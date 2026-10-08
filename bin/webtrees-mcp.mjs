#!/usr/bin/env node
// Node 22+. Local file transport; image bytes never enter the model's tool arguments.
import { mkdtemp, open, realpath, stat, writeFile } from 'node:fs/promises';
import { constants } from 'node:fs';
import { delimiter, resolve, relative, isAbsolute, basename, join } from 'node:path';
import { createHash } from 'node:crypto';
import { createInterface } from 'node:readline';
import { pathToFileURL } from 'node:url';
import { tmpdir } from 'node:os';

export const LIMIT = 20 * 1024 * 1024;
export const CHUNK = 256 * 1024;
export class Bridge {
  constructor({ endpoint, token, roots = [process.cwd()], fetchImpl = fetch }) {
    const url = new URL(endpoint);
    if (url.protocol !== 'https:' || url.username || url.password) throw new Error('A trusted HTTPS MCP endpoint is required.');
    if (!token) throw new Error('Set WEBTREES_TOKEN or TOKEN before starting the MCP bridge.');
    this.endpoint = url.href; this.token = token; this.roots = roots; this.fetch = fetchImpl; this.id = 0;
  }
  async rpc(method, params = {}) {
    const id = ++this.id;
    let response;
    try {
      response = await this.fetch(this.endpoint, { method: 'POST', redirect: 'error',
        signal: AbortSignal.timeout(60000), headers: { Authorization: `Bearer ${this.token}`,
          'Content-Type': 'application/json', Accept: 'application/json, text/event-stream' },
        body: JSON.stringify({ jsonrpc: '2.0', id, method, params }) });
    } catch { throw new Error('MCP transport failed. Upload outcome may be uncertain; inspect webtrees before uploading again.'); }
    if (!response.ok) {
      let detail;
      try { detail = await response.json(); } catch { detail = undefined; }
      const remote = detail?.error;
      if (remote && typeof remote === 'object' && typeof remote.code === 'string') {
        throw new Error(`MCP error ${remote.code}: ${remote.message || 'request failed'}`);
      }
      throw new Error(`MCP endpoint returned HTTP ${response.status} (http_${response.status}). Check token scopes and server settings.`);
    }
    let payload;
    try { payload = await response.json(); } catch { throw new Error('Invalid MCP response. Inspect webtrees before retrying an upload.'); }
    if (payload.jsonrpc !== '2.0' || payload.id !== id) throw new Error('Mismatched MCP response. Inspect webtrees before retrying an upload.');
    if (payload.error) {
      const error = new Error(`Remote MCP error ${payload.error.code}: ${payload.error.message ?? 'request failed'}`);
      error.code = payload.error.code;
      error.data = payload.error.data;
      throw error;
    }
    return payload.result;
  }
  async list() {
    const result = await this.rpc('tools/list');
    if (!result.tools.some(t => t.name === 'create-media-upload')) throw new Error('Server upgrade required: create-media-upload is missing.');
    return { tools: result.tools.filter(t => t.name !== 'upload-media-chunk' && t.name !== 'create-media-upload').map(t => {
      if (t.name === 'download-media') {
        return { ...t, description: 'Download a visible media file and write it to a local temporary file. Supply the exact filename returned by get-media; the response contains local-path, bytes, and sha256, never base64.',
          outputSchema: { type: 'object', properties: { 'local-path': { type: 'string' }, bytes: { type: 'integer' }, sha256: { type: 'string' } }, required: ['local-path', 'bytes', 'sha256'] } };
      }
      if (t.name === 'upload-media-batch') {
        const properties = { ...t.inputSchema.properties };
        delete properties['legacy-inline'];
        properties.files = { type: 'array', minItems: 1, maxItems: 50, items: { type: 'object', properties: { 'local-path': { type: 'string' }, 'target-xref': { type: 'string' }, 'target-type': { type: 'string', enum: ['INDI', 'FAM', 'SOUR'] }, title: { type: 'string' }, note: { type: 'string' }, date: { type: 'string' } }, required: ['local-path'], additionalProperties: false }, description: 'Local files with optional per-file target, title, note and date.' };
        return { ...t, description: 'Upload multiple local images/PDFs using signed URLs, with optional per-file targets. PUTs commit independently and edits queue against pending versions. Supply local-path for each file; never base64.',
          inputSchema: { type: 'object', properties, required: ['tree', 'files'], additionalProperties: false } };
      }
      if (t.name !== 'upload-media') return t;
      const properties = { ...t.inputSchema.properties };
      delete properties['content-base64']; delete properties['legacy-inline']; delete properties.filename;
      properties['local-path'] = { type: 'string', description: 'Absolute path to a local JPEG/PNG/GIF/WebP/PDF file within WEBTREES_UPLOAD_ROOTS. Up to 20 MiB. The bridge reads and transfers bytes automatically over MCP.' };
      return { ...t, description: 'Upload a local image through MCP and link to a verified INDI/FAM/SOUR. Supply local-path, never base64. Requires mcp_write, no api_write. Verify tree and target first. BOTH media and link await approval. Do not retry uncertain uploads.',
        inputSchema: { type: 'object', properties, required: ['tree', 'target-xref', 'target-type', 'local-path'], additionalProperties: false } };
    }) };
  }
  async upload(args) {
    if (typeof args['local-path'] !== 'string' || !isAbsolute(args['local-path'])) throw new Error('local-path must be an absolute file path.');
    const path = await realpath(args['local-path']);
    const roots = await Promise.all(this.roots.map(r => realpath(resolve(r))));
    if (!roots.some(root => { const rel = relative(root, path); return rel && !rel.startsWith('..') && !isAbsolute(rel) && !rel.split(/[\\/]/).some(p => p.startsWith('.')); })) {
      throw new Error('File is outside permitted upload roots, or in a hidden directory. Configure WEBTREES_UPLOAD_ROOTS.');
    }
    if (!/\.(jpe?g|png|gif|webp|pdf)$/i.test(path)) throw new Error('Only JPEG, PNG, GIF, WebP and PDF files can be uploaded.');
    const expected = await stat(path);
    const handle = await open(path, constants.O_RDONLY | constants.O_NOFOLLOW | constants.O_NONBLOCK);
    let bytes;
    try {
      const stat = await handle.stat();
      if (stat.dev !== expected.dev || stat.ino !== expected.ino || await realpath(path) !== path) throw new Error('File path changed while opening.');
      if (!stat.isFile() || stat.size <= 0 || stat.size > LIMIT) throw new Error('Image must be a regular file between 1 byte and 20 MiB.');
      bytes = Buffer.alloc(stat.size);
      let read = 0;
      while (read < bytes.length) { const r = await handle.read(bytes, read, bytes.length - read, read); if (!r.bytesRead) throw new Error('Image changed while reading.'); read += r.bytesRead; }
      const after = await handle.stat();
      if (after.size !== stat.size || after.mtimeMs !== stat.mtimeMs) throw new Error('Image changed while reading.');
    } finally { await handle.close(); }
    const metadata = Object.fromEntries(['tree','target-xref','target-type','title','note','date'].filter(k => args[k] !== undefined).map(k => [k,args[k]]));
    // Staged raw upload: mint a signed URL, then PUT the bytes directly so no
    // base64 ever travels through the model or the JSON-RPC request.
    const staged = await this.rpc('tools/call', { name: 'create-media-upload', arguments: { ...metadata, filename: basename(path) } });
    if (staged.isError) return staged;
    let receipt = staged.structuredContent;
    if (!receipt) {
      try { receipt = JSON.parse(staged.content.find(c => c.type === 'text').text); }
      catch { throw new Error('Invalid staged-upload response.'); }
    }
    return this.putUpload(receipt, bytes, args, path);
  }
  async putUpload(receipt, bytes, args, path) {
    if (typeof receipt['upload-url'] !== 'string' || !receipt['upload-url'] || !Number.isInteger(receipt['max-bytes']) || bytes.length > receipt['max-bytes']) {
      throw new Error('Server refused a staged upload URL for this file.');
    }
    const contentType = { jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', gif: 'image/gif', webp: 'image/webp' }[basename(path).split('.').pop().toLowerCase()] ?? 'application/octet-stream';
    let response;
    try {
      response = await this.fetch(receipt['upload-url'], { method: 'PUT', redirect: 'error',
        signal: AbortSignal.timeout(120000), headers: { Authorization: `Bearer ${this.token}`, 'Content-Type': contentType }, body: bytes });
    } catch { throw new Error('Staged upload transport failed. Inspect webtrees before uploading again.'); }
    let payload;
    try { payload = await response.json(); } catch { payload = undefined; }
    if (!response.ok) {
      const remote = payload?.error;
      if (remote && typeof remote.code === 'string') throw new Error(`MCP error ${remote.code}: ${remote.message || 'upload failed'}`);
      throw new Error(`Staged upload returned HTTP ${response.status}. Inspect webtrees before retrying.`);
    }
    if (typeof payload?.xref !== 'string' || !payload.xref || payload.pending !== true || payload['target-xref'] !== args['target-xref']) {
      throw new Error('Missing final media receipt. Inspect webtrees before retrying.');
    }
    return { isError: false, content: [{ type: 'text', text: JSON.stringify(payload) }], structuredContent: payload };
  }
  async readLocal(inputPath) {
    if (typeof inputPath !== 'string' || !isAbsolute(inputPath)) throw new Error('local-path must be an absolute file path.');
    const path = await realpath(inputPath);
    const roots = await Promise.all(this.roots.map(r => realpath(resolve(r))));
    if (!roots.some(root => { const rel = relative(root, path); return rel && !rel.startsWith('..') && !isAbsolute(rel) && !rel.split(/[\\/]/).some(p => p.startsWith('.')); })) {
      throw new Error('File is outside permitted upload roots, or in a hidden directory. Configure WEBTREES_UPLOAD_ROOTS.');
    }
    if (!/\.(jpe?g|png|gif|webp|pdf)$/i.test(path)) throw new Error('Only JPEG, PNG, GIF, WebP and PDF files can be uploaded.');
    const expected = await stat(path);
    const handle = await open(path, constants.O_RDONLY | constants.O_NOFOLLOW | constants.O_NONBLOCK);
    try {
      const current = await handle.stat();
      if (current.dev !== expected.dev || current.ino !== expected.ino || await realpath(path) !== path) throw new Error('File path changed while opening.');
      if (!current.isFile() || current.size <= 0 || current.size > LIMIT) throw new Error('Image must be a regular file between 1 byte and 20 MiB.');
      const bytes = Buffer.alloc(current.size);
      let read = 0;
      while (read < bytes.length) { const part = await handle.read(bytes, read, bytes.length - read, read); if (!part.bytesRead) throw new Error('Image changed while reading.'); read += part.bytesRead; }
      const after = await handle.stat();
      if (after.size !== current.size || after.mtimeMs !== current.mtimeMs) throw new Error('Image changed while reading.');
      return bytes;
    } finally { await handle.close(); }
  }
  async download(args) {
    const result = await this.rpc('tools/call', { name: 'download-media', arguments: args });
    if (result.isError) return result;
    let payload = result.structuredContent;
    if (!payload) {
      try { payload = JSON.parse(result.content.find(c => c.type === 'text').text); }
      catch { throw new Error('Invalid download response.'); }
    }
    if (typeof payload.filename !== 'string' || typeof payload.sha256 !== 'string' || !Number.isInteger(payload.bytes)) {
      throw new Error('Download response is missing a filename, size or checksum.');
    }
    if (!/^[^/\\.][^/\\]*\.[A-Za-z0-9]+$/.test(payload.filename)) throw new Error('Download response contains an unsafe filename.');
    let bytes;
    if (typeof payload['content-url'] === 'string') {
      // Preferred path: fetch the signed URL directly so no base64 enters the model.
      let response;
      try {
        response = await this.fetch(payload['content-url'], { method: 'GET', redirect: 'error',
          signal: AbortSignal.timeout(60000), headers: { Authorization: `Bearer ${this.token}` } });
      } catch { throw new Error('Media download transport failed; inspect webtrees before retrying.'); }
      if (!response.ok) throw new Error(`Media download returned HTTP ${response.status}.`);
      bytes = Buffer.from(await response.arrayBuffer());
    } else if (typeof payload['content-base64'] === 'string') {
      // Legacy fallback for installations without signed URLs.
      bytes = Buffer.from(payload['content-base64'], 'base64');
      if (bytes.toString('base64') !== payload['content-base64']) throw new Error('Download response contained invalid base64.');
    } else {
      throw new Error('Download response is missing content-url.');
    }
    if (bytes.length !== payload.bytes || createHash('sha256').update(bytes).digest('hex') !== payload.sha256) {
      throw new Error('Downloaded bytes failed checksum verification.');
    }
    const directory = await mkdtemp(join(tmpdir(), 'webtrees-download-'));
    const path = join(directory, basename(payload.filename));
    await writeFile(path, bytes, { mode: 0o600 });
    delete payload['content-base64'];
    delete payload['content-url'];
    delete payload['expires-at'];
    const local = { ...payload, 'local-path': path };
    return { ...result, structuredContent: local, content: [{ type: 'text', text: JSON.stringify(local) }] };
  }
  async uploadBatch(args) {
    if (!Array.isArray(args.files) || args.files.length < 1 || args.files.length > 50) throw new Error('files must contain 1-50 local files.');
    const prepared = [];
    let total = 0;
    for (const item of args.files) {
      const bytes = await this.readLocal(item['local-path']);
      total += bytes.length;
      if (total > LIMIT) throw new Error('Batch exceeds the 20 MiB total limit.');
      const path = await realpath(item['local-path']);
      const metadata = { ...Object.fromEntries(['target-xref', 'target-type', 'title', 'note', 'date'].filter(k => item[k] !== undefined).map(k => [k, item[k]])), filename: basename(path) };
      prepared.push({ bytes, path, metadata });
    }
    const staged = await this.rpc('tools/call', { name: 'upload-media-batch', arguments: {
      ...Object.fromEntries(['tree', 'target-xref', 'target-type'].filter(k => args[k] !== undefined).map(k => [k, args[k]])),
      files: prepared.map(item => item.metadata),
    } });
    if (staged.isError) return staged;
    const receipt = staged.structuredContent ?? JSON.parse(staged.content.find(c => c.type === 'text').text);
    if (!Array.isArray(receipt.files) || receipt.files.length !== prepared.length) throw new Error('Invalid staged batch receipt; no file bytes were sent.');
    const files = [];
    for (let index = 0; index < prepared.length; index++) {
      const item = prepared[index];
      try {
        const result = await this.putUpload(receipt.files[index], item.bytes, { ...args, ...item.metadata }, item.path);
        files.push(result.structuredContent);
      } catch (error) {
        throw new Error(`Batch stopped at file ${index + 1}. Previously completed media xrefs: ${files.map(file => file.xref).join(', ') || 'none'}. Inspect webtrees before retrying. ${error.message}`);
      }
    }
    const records = [...new Map(files.flatMap(file => file.records ?? []).map(record => [record.xref, record])).values()];
    const payload = { xref: files[0].xref, hash: files[0].hash, version: files[0].version, files, records, pending: true };
    return { isError: false, content: [{ type: 'text', text: JSON.stringify(payload) }], structuredContent: payload };
  }

  async handle(request) {
    switch (request.method) {
      case 'initialize': return { protocolVersion: request.params.protocolVersion, capabilities: { tools: {} }, serverInfo: { name: 'webtrees-local-file-bridge', version: '1.0.0' } };
      case 'ping': return {};
      case 'tools/list': return this.list();
      case 'tools/call':
        if (request.params.name === 'upload-media-chunk') throw new Error('Use upload-media with local-path; chunk transport is internal.');
        if (request.params.name === 'upload-media') return this.upload(request.params.arguments ?? {});
        if (request.params.name === 'download-media') return this.download(request.params.arguments ?? {});
        if (request.params.name === 'upload-media-batch') return this.uploadBatch(request.params.arguments ?? {});
        return this.rpc('tools/call', request.params);
      default: throw new Error('Unsupported MCP method.');
    }
  }
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  try {
    const configuredRoots = process.env.WEBTREES_UPLOAD_ROOTS?.split(delimiter).filter(Boolean);
    const bridge = new Bridge({ endpoint: process.env.WEBTREES_MCP_URL, token: process.env.WEBTREES_TOKEN || process.env.TOKEN,
      roots: configuredRoots?.length ? configuredRoots : [process.cwd()] });
    const input = createInterface({ input: process.stdin, crlfDelay: Infinity });
    for await (const line of input) {
      let request;
      try {
        request = JSON.parse(line);
        if (request.id === undefined) continue;
        const result = await bridge.handle(request);
        process.stdout.write(JSON.stringify({ jsonrpc: '2.0', id: request.id, result }) + '\n');
      } catch (error) {
        const result = { isError: true, content: [{ type: 'text', text: error.message }] };
        process.stdout.write(JSON.stringify(request?.method === 'tools/call' ? { jsonrpc: '2.0', id: request.id, result } :
          { jsonrpc: '2.0', id: request?.id ?? null, error: { code: -32603, message: error.message } }) + '\n');
      }
    }
  } catch (error) { process.stderr.write(error.message + '\n'); process.exitCode = 1; }
}
