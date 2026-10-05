import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, writeFile, readFile, rm, symlink } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createHash } from 'node:crypto';
import { Bridge, CHUNK } from '../bin/webtrees-mcp.mjs';

test('large local image uploads through a signed URL, never as base64', async () => {
  const dir = await mkdtemp(join(tmpdir(), 'webtrees-bridge-'));
  try {
    const bytes = Buffer.alloc(CHUNK * 3 + 57, 147);
    const path = join(dir, 'image.jpg'); await writeFile(path, bytes);
    const uploadUrl = 'https://example.test/api/media/upload?token=signed';
    const calls = [];
    const bridge = new Bridge({ endpoint: 'https://example.test/mcp', token: 'test-token', roots: [dir], fetchImpl: async (url, init) => {
      if (url === uploadUrl) {
        calls.push({ put: true, body: Buffer.from(init.body), contentType: init.headers['Content-Type'] });
        assert.equal(init.method, 'PUT'); assert.equal(init.redirect, 'error');
        return { ok: true, status: 201, json: async () => ({ xref: 'M1', pending: true, 'target-xref': 'I1' }) };
      }
      assert.equal(url, 'https://example.test/mcp');
      const rpc = JSON.parse(init.body); calls.push(rpc);
      assert.equal(rpc.params.name, 'create-media-upload');
      const a = rpc.params.arguments;
      assert.equal(a.tree, 'test'); assert.equal(a['target-xref'], 'I1'); assert.equal(a['target-type'], 'INDI');
      assert.equal(a.filename, 'image.jpg'); assert.equal(a.title, 'Test');
      assert.equal(a['content-base64'], undefined); assert.equal(a['local-path'], undefined);
      return { ok: true, json: async () => ({ jsonrpc: '2.0', id: rpc.id, result: { structuredContent: { 'upload-id': 'abc', 'upload-url': uploadUrl, 'max-bytes': 20 * 1024 * 1024, 'expires-at': 123 } } }) };
    } });
    const result = await bridge.upload({ 'local-path': path, tree: 'test', 'target-xref': 'I1', 'target-type': 'INDI', title: 'Test' });
    assert.equal(calls.length, 2);
    assert.equal(result.structuredContent.xref, 'M1');
    const put = calls.find(c => c.put);
    assert.deepEqual(put.body, bytes);
    assert.equal(put.contentType, 'image/jpeg');
  } finally { await rm(dir, { recursive: true }); }
});

test('discovery replaces base64 with local-path and hides internal tools', async () => {
  const bridge = new Bridge({ endpoint: 'https://example.test/mcp', token: 'test', fetchImpl: async (url, init) => ({ ok: true, json: async () => ({ jsonrpc: '2.0', id: JSON.parse(init.body).id, result: { tools: [
    { name: 'upload-media', inputSchema: { properties: { filename: {}, 'content-base64': {}, tree: {} } } },
    { name: 'upload-media-chunk' }, { name: 'create-media-upload' }, { name: 'get-record' }
  ] } }) }) });
  const { tools } = await bridge.list(); assert.equal(tools.length, 2);
  assert.ok(tools[0].inputSchema.properties['local-path']);
  assert.equal(tools[0].inputSchema.properties['content-base64'], undefined);
  assert.equal(tools[1].name, 'get-record');
});

test('download-media fetches the signed URL and writes verified bytes to a private temp file', async () => {
  const bytes = Buffer.from('real image bytes');
  const sha256 = createHash('sha256').update(bytes).digest('hex');
  const contentUrl = 'https://example.test/api/media/content?token=signed';
  const bridge = new Bridge({ endpoint: 'https://example.test/mcp', token: 'test', fetchImpl: async (url, init) => {
    if (url === contentUrl) {
      assert.equal(init.headers.Authorization, 'Bearer test');
      assert.equal(init.redirect, 'error');
      return { ok: true, status: 200, arrayBuffer: async () => bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.length) };
    }
    const rpc = JSON.parse(init.body);
    assert.equal(rpc.params.name, 'download-media');
    const payload = { filename: 'scan.png', bytes: bytes.length, sha256, 'content-url': contentUrl, 'expires-at': 12345 };
    return { ok: true, json: async () => ({ jsonrpc: '2.0', id: rpc.id, result: { structuredContent: payload, content: [{ type: 'text', text: JSON.stringify(payload) }] } }) };
  } });
  const result = await bridge.handle({ method: 'tools/call', params: { name: 'download-media', arguments: { tree: 'test', xref: 'M1', filename: 'api-media/hash/scan.png' } } });
  assert.equal(result.structuredContent['content-base64'], undefined);
  assert.equal(result.structuredContent['content-url'], undefined);
  assert.equal((await readFile(result.structuredContent['local-path'])).toString(), bytes.toString());
  await rm(result.structuredContent['local-path'], { force: true });
});

test('download-media still accepts the legacy base64 fallback', async () => {
  const bytes = Buffer.from('legacy bytes');
  const bridge = new Bridge({ endpoint: 'https://example.test/mcp', token: 'test', fetchImpl: async (url, init) => {
    const rpc = JSON.parse(init.body);
    const payload = { filename: 'scan.png', bytes: bytes.length, sha256: createHash('sha256').update(bytes).digest('hex'), 'content-base64': bytes.toString('base64') };
    return { ok: true, json: async () => ({ jsonrpc: '2.0', id: rpc.id, result: { structuredContent: payload } }) };
  } });
  const result = await bridge.handle({ method: 'tools/call', params: { name: 'download-media', arguments: { tree: 'test', xref: 'M1', filename: 'api-media/hash/scan.png' } } });
  assert.equal((await readFile(result.structuredContent['local-path'])).toString(), bytes.toString());
  await rm(result.structuredContent['local-path'], { force: true });
});

test('upload-media-batch transfers multiple local images in one MCP call', async () => {
  const dir = await mkdtemp(join(tmpdir(), 'webtrees-bridge-'));
  try {
    const first = join(dir, 'one.png'); const second = join(dir, 'two.jpg');
    await writeFile(first, Buffer.from('first')); await writeFile(second, Buffer.from('second'));
    let calls = 0;
    const bridge = new Bridge({ endpoint: 'https://example.test/mcp', token: 'test', roots: [dir], fetchImpl: async (url, init) => {
      calls++;
      const rpc = JSON.parse(init.body); assert.equal(rpc.params.name, 'upload-media-batch');
      const files = rpc.params.arguments.files; assert.equal(files.length, 2);
      assert.equal(files[0]['local-path'], undefined); assert.equal(Buffer.from(files[0]['content-base64'], 'base64').toString(), 'first');
      assert.equal(Buffer.from(files[1]['content-base64'], 'base64').toString(), 'second');
      return { ok: true, json: async () => ({ jsonrpc: '2.0', id: rpc.id, result: { content: [{ type: 'text', text: '{"pending":true}' }] } }) };
    } });
    const result = await bridge.handle({ method: 'tools/call', params: { name: 'upload-media-batch', arguments: { tree: 'test', 'target-xref': 'I1', 'target-type': 'INDI', files: [{ 'local-path': first }, { 'local-path': second, title: 'Two' }] } } });
    assert.equal(calls, 1); assert.equal(result.content[0].text, '{"pending":true}');
  } finally { await rm(dir, { recursive: true }); }
});

test('refuses files outside upload roots, including symlink escape', async () => {
  const dir = await mkdtemp(join(tmpdir(), 'webtrees-bridge-'));
  const outside = await mkdtemp(join(tmpdir(), 'webtrees-outside-'));
  try {
    const path = join(outside, 'secret.jpg'); await writeFile(path, 'secret');
    await symlink(path, join(dir, 'link.jpg'));
    const bridge = new Bridge({ endpoint: 'https://example.test/mcp', token: 'test', roots: [dir], fetchImpl: () => { throw new Error('Must not send'); } });
    await assert.rejects(bridge.upload({ 'local-path': join(dir, 'link.jpg') }), /outside permitted/);
  } finally { await rm(dir, { recursive: true }); await rm(outside, { recursive: true }); }
});

test('stops at server rejection; never retries or duplicates an upload', async () => {
  const dir = await mkdtemp(join(tmpdir(), 'webtrees-bridge-'));
  try {
    const path = join(dir, 'image.png'); await writeFile(path, Buffer.alloc(CHUNK * 2)); let calls = 0;
    const bridge = new Bridge({ endpoint: 'https://example.test/mcp', token: 'test', roots: [dir], fetchImpl: async (url, init) => {
      calls++; return { ok: true, json: async () => ({ jsonrpc: '2.0', id: JSON.parse(init.body).id, result: { isError: true, content: [{ type: 'text', text: '403' }] } }) };
    } });
    assert.equal((await bridge.upload({ 'local-path': path })).isError, true); assert.equal(calls, 1);
  } finally { await rm(dir, { recursive: true }); }
});

test('direct chunk invocation is refused and RPC response IDs are checked', async () => {
  const b = new Bridge({ endpoint: 'https://example.test/mcp', token: 'test', fetchImpl: async () => ({ ok: true, json: async () => ({ jsonrpc: '2.0', id: 999, result: {} }) }) });
  await assert.rejects(b.handle({ method: 'tools/call', params: { name: 'upload-media-chunk', arguments: {} } }), /internal/);
  await assert.rejects(b.rpc('tools/list'), /Mismatched/);
});

test('preserves structured error codes from non-success HTTP responses', async () => {
  const b = new Bridge({ endpoint: 'https://example.test/mcp', token: 'test', fetchImpl: async () => ({
    ok: false, status: 409, json: async () => ({ error: { code: 'pending_conflict', message: 'Pending change exists.' } }),
  }) });
  await assert.rejects(b.rpc('tools/list'), /pending_conflict: Pending change exists/);
});
