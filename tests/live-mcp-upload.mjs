// Explicit opt-in acceptance test. Creates ONE pending image/link on the chosen target.
import { Bridge } from '../bin/webtrees-mcp.mjs';
import { resolve, dirname } from 'node:path';
const [tree, target, path] = process.argv.slice(2);
if (!tree || !target || !path || process.env.WEBTREES_LIVE_UPLOAD !== 'yes') {
  throw new Error('Explicitly opt in with WEBTREES_LIVE_UPLOAD=yes and arguments TREE TARGET IMAGE.');
}
const bridge = new Bridge({ endpoint: process.env.WEBTREES_MCP_URL, token: process.env.TOKEN || process.env.WEBTREES_TOKEN, roots: [dirname(resolve(path))] });
const listed = await bridge.list();
if (!listed.tools.find(t => t.name === 'upload-media')?.inputSchema.properties['local-path']) throw new Error('Local upload tool missing');
const result = await bridge.upload({ tree, 'target-xref': target, 'target-type': 'INDI', 'local-path': resolve(path), title: 'MCP uploadtest — mag na controle worden afgewezen', note: 'Test van volledige lokale MCP-upload. Media en koppeling wachten op beoordeling.' });
if (result.isError) { console.log(JSON.stringify(result)); process.exitCode = 1; }
else { console.log(JSON.stringify(result)); }
