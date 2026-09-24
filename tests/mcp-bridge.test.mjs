import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, writeFile, readFile, rm, symlink } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createHash } from 'node:crypto';
import { Bridge, CHUNK } from '../bin/webtrees-mcp.mjs';

test('large local image travels exclusively in bounded MCP chunks, with original bytes and metadata', async () => {
  const dir = await mkdtemp(join(tmpdir(), 'webtrees-bridge-'));
  try {
    const bytes = Buffer.alloc(CHUNK * 3 + 57, 147);
    const path = join(dir, 'image.jpg'); await writeFile(path, bytes);
    const calls = [];
    const bridge = new Bridge({ endpoint: 'https://example.test/mcp', token: 'test-token', roots: [dir], fetchImpl: async (url, init) => {
      assert.equal(url, 'https://example.test/mcp'); assert.equal(init.redirect, 'error');
      const rpc = JSON.parse(init.body); calls.push(rpc);
      const a = rpc.params.arguments;
      const receipt = a.final ? { xref: 'M1', pending: true, 'target-xref': a['target-xref'] } : { 'upload-id': a['upload-id'], 'next-offset': a.offset + CHUNK, complete: false };
      return { ok: true, json: async () => ({ jsonrpc: '2.0', id: rpc.id, result: { content: [{ type: 'text', text: JSON.stringify(receipt) }], isError: false } }) };
    } });
    const result = await bridge.upload({ 'local-path': path, tree: 'test', 'target-xref': 'I1', 'target-type': 'INDI', title: 'Test' });
    assert.equal(calls.length, 4);
    assert.match(result.content[0].text, /M1/);
    assert.deepEqual(Buffer.concat(calls.map(c => Buffer.from(c.params.arguments['content-base64'], 'base64'))), bytes);
    for (const [i, call] of calls.entries()) {
      assert.equal(call.method, 'tools/call'); assert.equal(call.params.name, 'upload-media-chunk');
      const a = call.params.arguments;
      assert.equal(a.offset, i * CHUNK); assert.equal(a.final, i === 3);
      assert.equal(a.sha256, createHash('sha256').update(bytes).digest('hex'));
      assert.equal(a.title, 'Test'); assert.equal(a['total-bytes'], bytes.length);
      assert.ok(Buffer.from(a['content-base64'], 'base64').length <= CHUNK);
      assert.equal(a['local-path'], undefined);
      assert.equal(a['upload-id'], calls[0].params.arguments['upload-id']);
    }
  } finally { await rm(dir, { recursive: true }); }
});

test('discovery replaces base64 with local-path and hides internal chunk tool', async () => {
  const bridge = new Bridge({ endpoint: 'https://example.test/mcp', token: 'test', fetchImpl: async (url, init) => ({ ok: true, json: async () => ({ jsonrpc: '2.0', id: JSON.parse(init.body).id, result: { tools: [
    { name: 'upload-media', inputSchema: { properties: { filename: {}, 'content-base64': {}, tree: {} } } },
    { name: 'upload-media-chunk' }, { name: 'get-record' }
  ] } }) }) });
  const { tools } = await bridge.list(); assert.equal(tools.length, 2);
  assert.ok(tools[0].inputSchema.properties['local-path']);
  assert.equal(tools[0].inputSchema.properties['content-base64'], undefined);
  assert.equal(tools[1].name, 'get-record');
});

test('download-media writes verified bytes to a private local temporary file', async () => {
  const bytes = Buffer.from('real image bytes');
  const bridge = new Bridge({ endpoint: 'https://example.test/mcp', token: 'test', fetchImpl: async (url, init) => {
    const rpc = JSON.parse(init.body);
    assert.equal(rpc.params.name, 'download-media');
    const payload = { filename: 'scan.png', bytes: bytes.length, sha256: createHash('sha256').update(bytes).digest('hex'), 'content-base64': bytes.toString('base64') };
    return { ok: true, json: async () => ({ jsonrpc: '2.0', id: rpc.id, result: { structuredContent: payload, content: [{ type: 'text', text: JSON.stringify(payload) }] } }) };
  } });
  const result = await bridge.handle({ method: 'tools/call', params: { name: 'download-media', arguments: { tree: 'test', xref: 'M1', filename: 'api-media/hash/scan.png' } } });
  assert.equal(result.structuredContent['content-base64'], undefined);
  assert.equal((await readFile(result.structuredContent['local-path'])).toString(), bytes.toString());
  await rm(result.structuredContent['local-path'], { force: true });
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
