// Real-HTTP smoke test of the MCP endpoint on a Playground server (WP 7.1 + Elementor + WooCommerce),
// with a mu-plugin that prints junk and raises a notice on every request (test matrix F2).
// Auth uses a fresh Application Password (WP_ENVIRONMENT_TYPE=local allows it over http).
import { spawn, spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { join } from 'node:path';

const PORT = 9401;
const BASE = `http://127.0.0.1:${PORT}`;
const root = fileURLToPath(new URL('..', import.meta.url));
const out = join(root, 'build', 'smoke');
rmSync(out, { recursive: true, force: true });
mkdirSync(out, { recursive: true });

const base = JSON.parse(readFileSync(join(root, 'tests', 'playground', 'blueprint.json'), 'utf8'));
const blueprint = {
  ...base,
  login: false,
  steps: [
    { step: 'defineWpConfigConsts', consts: { WP_ENVIRONMENT_TYPE: 'local', WP_DEBUG: true, WP_DEBUG_DISPLAY: true } },
    { step: 'activatePlugin', pluginPath: 'kuduy-mcp/kuduy-mcp.php' },
    {
      step: 'writeFile',
      path: '/wordpress/wp-content/mu-plugins/noise.php',
      data: "<?php add_action( 'init', function () { echo 'NOISE-FROM-ANOTHER-PLUGIN'; trigger_error( 'smoke notice', E_USER_NOTICE ); } );\n",
    },
    {
      step: 'runPHP',
      code: "<?php require '/wordpress/wp-load.php'; [ $pass ] = WP_Application_Passwords::create_new_application_password( 1, array( 'name' => 'smoke' ) ); file_put_contents( '/smoke-out/auth.txt', 'admin:' . $pass );",
    },
  ],
};
const bpPath = join(out, 'blueprint.json');
writeFileSync(bpPath, JSON.stringify(blueprint));

const cli = join(root, 'node_modules', '@wp-playground', 'cli', 'wp-playground.js');
const server = spawn(process.execPath, [cli, 'server', '--wp=7.1', '--php=8.3', `--port=${PORT}`, `--blueprint=${bpPath}`,
  '--mount-dir', root, '/wordpress/wp-content/plugins/kuduy-mcp', '--mount-dir', out, '/smoke-out'], { cwd: root });
let log = '';
server.stdout.on('data', (d) => { log += d; });
server.stderr.on('data', (d) => { log += d; });
const stop = () => (process.platform === 'win32'
  ? spawnSync('taskkill', ['/pid', String(server.pid), '/T', '/F'])
  : server.kill());

const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${ok ? '' : `  -> ${detail}`}`); };

try {
  const deadline = Date.now() + 8 * 60_000;
  while (!/Ready!/.test(log)) {
    if (Date.now() > deadline || server.exitCode !== null) throw new Error(`Playground did not start:\n${log.slice(-2000)}`);
    await new Promise((r) => setTimeout(r, 1000));
  }
  const auth = `Basic ${Buffer.from(readFileSync(join(out, 'auth.txt'), 'utf8')).toString('base64')}`;
  const url = `${BASE}/wp-json/kuduy-mcp/v1/mcp`;
  const meta = { 'io.modelcontextprotocol/protocolVersion': '2026-07-28', 'io.modelcontextprotocol/clientInfo': { name: 'smoke', version: '1' }, 'io.modelcontextprotocol/clientCapabilities': {} };
  const modern = (method, params = {}, extra = {}) => fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json, text/event-stream', 'MCP-Protocol-Version': '2026-07-28', 'Mcp-Method': method, ...(params.name ? { 'Mcp-Name': params.name } : {}), ...extra },
    body: JSON.stringify({ jsonrpc: '2.0', id: 1, method, params: { ...params, _meta: meta } }),
  });
  const json = async (res) => { const text = await res.text(); try { return [JSON.parse(text), text]; } catch { return [null, text]; } };

  let res = await modern('server/discover');
  let [body, text] = await json(res);
  check('discover: 200 + clean JSON despite noisy plugin', res.status === 200 && body?.result?.supportedVersions?.[0] === '2026-07-28' && !text.includes('NOISE') && !text.includes('smoke notice'), `${res.status} ${text.slice(0, 200)}`);
  check('discover: Content-Type application/json', (res.headers.get('content-type') ?? '').startsWith('application/json'), res.headers.get('content-type'));
  check('noise really printed on normal pages', (await (await fetch(`${BASE}/`)).text()).includes('NOISE-FROM-ANOTHER-PLUGIN'), 'mu-plugin not active: F2 check would be meaningless');

  res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'MCP-Protocol-Version': '2025-11-25' }, body: JSON.stringify({ jsonrpc: '2.0', method: 'notifications/initialized' }) });
  text = await res.text();
  check('notification: 202 with empty body', res.status === 202 && text === '', `${res.status} "${text.slice(0, 100)}"`);

  res = await fetch(url);
  text = await res.text();
  check('GET: 405 + Allow: POST, empty body', res.status === 405 && res.headers.get('allow') === 'POST' && text === '', `${res.status} allow=${res.headers.get('allow')} "${text.slice(0, 100)}"`);

  res = await modern('tools/list');
  check('tools/list without auth: 401', res.status === 401, String(res.status));

  res = await modern('tools/call', { name: 'kuduy-site-info' }, { Authorization: auth });
  [body, text] = await json(res);
  check('tools/call site-info with Application Password', res.status === 200 && body?.result?.isError === false && body.result.structuredContent?.wordpress_version === '7.1.4', `${res.status} ${text.slice(0, 300)}`);

  res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'initialize', params: { protocolVersion: '2025-11-25', capabilities: {}, clientInfo: { name: 'smoke', version: '1' } } }) });
  [body] = await json(res);
  check('legacy initialize: 200, no Mcp-Session-Id', res.status === 200 && body?.result?.protocolVersion === '2025-11-25' && !res.headers.has('mcp-session-id'), `${res.status} session=${res.headers.get('mcp-session-id')}`);

  res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{"jsonrpc":' });
  [body, text] = await json(res);
  check('invalid JSON: 400 -32700', res.status === 400 && body?.error?.code === -32700, `${res.status} ${text.slice(0, 200)}`);
} catch (e) {
  check('smoke run', false, e.message);
} finally {
  stop();
}
const failed = results.filter((ok) => !ok).length;
console.log(`\nSmoke: ${results.length - failed}/${results.length} passed`);
process.exit(failed ? 1 : 0);
