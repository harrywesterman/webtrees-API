#!/usr/bin/env node
// Node 22+. Local file transport; image bytes never enter the model's tool arguments.
import { mkdtemp, open, realpath, stat, writeFile } from 'node:fs/promises';
import { constants } from 'node:fs';
import { delimiter, resolve, relative, isAbsolute, basename, join } from 'node:path';
import { createHash, randomBytes } from 'node:crypto';
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
    if (!response.ok) throw new Error(`MCP endpoint returned HTTP ${response.status}. Check token scopes and server settings.`);
    let payload;
    try { payload = await response.json(); } catch { throw new Error('Invalid MCP response. Inspect webtrees before retrying an upload.'); }
    if (payload.jsonrpc !== '2.0' || payload.id !== id) throw new Error('Mismatched MCP response. Inspect webtrees before retrying an upload.');
    if (payload.error) throw new Error(`Remote MCP error ${payload.error.code}. Inspect server configuration.`);
    return payload.result;
  }
  async list() {
    const result = await this.rpc('tools/list');
    if (!result.tools.some(t => t.name === 'upload-media-chunk')) throw new Error('Server upgrade required: upload-media-chunk is missing.');
    return { tools: result.tools.filter(t => t.name !== 'upload-media-chunk').map(t => {
      if (t.name === 'download-media') {
        return { ...t, description: 'Download a visible media file and write it to a local temporary file. Supply the exact filename returned by get-media; the response contains local-path, bytes, and sha256, never base64.',
          outputSchema: { type: 'object', properties: { 'local-path': { type: 'string' }, bytes: { type: 'integer' }, sha256: { type: 'string' } }, required: ['local-path', 'bytes', 'sha256'] } };
      }
      if (t.name !== 'upload-media') return t;
      const properties = { ...t.inputSchema.properties };
      delete properties['content-base64']; delete properties.filename;
      properties['local-path'] = { type: 'string', description: 'Absolute path to a local JPEG/PNG/GIF/WebP file within WEBTREES_UPLOAD_ROOTS. Up to 20 MiB. The bridge reads and transfers bytes automatically over MCP.' };
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
    if (!/\.(jpe?g|png|gif|webp)$/i.test(path)) throw new Error('Only JPEG, PNG, GIF and WebP files can be uploaded.');
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
    const uploadId = Math.floor(Date.now() / 1000).toString(16).padStart(8, '0') + randomBytes(12).toString('hex');
    const common = { ...metadata, 'upload-id': uploadId, filename: basename(path), 'total-bytes': bytes.length, sha256: createHash('sha256').update(bytes).digest('hex') };
    let result;
    for (let offset = 0; offset < bytes.length; offset += CHUNK) {
      result = await this.rpc('tools/call', { name: 'upload-media-chunk', arguments: { ...common, offset,
        'content-base64': bytes.subarray(offset, offset + CHUNK).toString('base64'), final: offset + CHUNK >= bytes.length } });
      if (result.isError) return result;
      let receipt;
      try { receipt = JSON.parse(result.content.find(c => c.type === 'text').text); }
      catch { throw new Error('Invalid upload acknowledgement. Inspect webtrees before retrying.'); }
      if (offset + CHUNK < bytes.length) {
        if (receipt['upload-id'] !== uploadId || receipt['next-offset'] !== offset + CHUNK || receipt.complete !== false) throw new Error('Incorrect chunk acknowledgement. Upload stopped.');
      } else if (typeof receipt.xref !== 'string' || !receipt.xref || receipt.pending !== true || receipt['target-xref'] !== args['target-xref']) {
        throw new Error('Missing final media receipt. Inspect webtrees before retrying.');
      }
    }
    return result;
  }
  async download(args) {
    const result = await this.rpc('tools/call', { name: 'download-media', arguments: args });
    if (result.isError) return result;
    let payload = result.structuredContent;
    if (!payload) {
      try { payload = JSON.parse(result.content.find(c => c.type === 'text').text); }
      catch { throw new Error('Invalid download response.'); }
    }
    if (typeof payload['content-base64'] !== 'string' || typeof payload.filename !== 'string' || typeof payload.sha256 !== 'string' || !Number.isInteger(payload.bytes)) {
      throw new Error('Download response is missing file bytes or checksum.');
    }
    if (!/^[^/\\.][^/\\]*\.[A-Za-z0-9]+$/.test(payload.filename)) throw new Error('Download response contains an unsafe filename.');
    const bytes = Buffer.from(payload['content-base64'], 'base64');
    if (bytes.toString('base64') !== payload['content-base64'] || bytes.length !== payload.bytes || createHash('sha256').update(bytes).digest('hex') !== payload.sha256) {
      throw new Error('Downloaded bytes failed checksum verification.');
    }
    const directory = await mkdtemp(join(tmpdir(), 'webtrees-download-'));
    const path = join(directory, basename(payload.filename));
    await writeFile(path, bytes, { mode: 0o600 });
    delete payload['content-base64'];
    const local = { ...payload, 'local-path': path };
    return { ...result, structuredContent: local, content: [{ type: 'text', text: JSON.stringify(local) }] };
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
