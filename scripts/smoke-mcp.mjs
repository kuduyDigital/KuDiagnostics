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
      // Not on wp-login.php: stray output there breaks core's own login (headers already sent), unrelated to MCP.
      data: "<?php add_action( 'init', function () { if ( str_contains( $_SERVER['REQUEST_URI'] ?? '', 'wp-login.php' ) ) { return; } echo 'NOISE-FROM-ANOTHER-PLUGIN'; trigger_error( 'smoke notice', E_USER_NOTICE ); } );\n",
    },
    {
      step: 'runPHP',
      code: "<?php require '/wordpress/wp-load.php'; [ $pass ] = WP_Application_Passwords::create_new_application_password( 1, array( 'name' => 'smoke' ) ); file_put_contents( '/smoke-out/auth.txt', 'admin:' . $pass ); $pw = wp_generate_password( 24, false ); wp_set_password( $pw, 1 ); file_put_contents( '/smoke-out/login.txt', $pw ); update_option( 'permalink_structure', '/%postname%/' );",
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

  // ---- OAuth: the sign-in Claude performs, over real HTTP ----
  res = await modern('tools/list');
  const challenge = res.headers.get('www-authenticate') ?? '';
  const prmUrl = challenge.match(/resource_metadata="([^"]+)"/)?.[1];
  check('401 carries WWW-Authenticate resource_metadata + scope', res.status === 401 && !!prmUrl && /scope="kuduy:read kuduy:write"/.test(challenge), challenge);

  const prm = await (await fetch(prmUrl)).json();
  const issuer = prm.authorization_servers?.[0];
  check('PRM names this resource and its issuer', prm.resource === url && typeof issuer === 'string', JSON.stringify(prm));

  const issuerPath = new URL(issuer).pathname;
  const wk = await fetch(`${BASE}/.well-known/oauth-authorization-server${issuerPath}`);
  const asm = wk.status === 200 ? await wk.json() : {};
  check('AS metadata at /.well-known (RFC 8414 path insertion)', asm.issuer === issuer, `${wk.status}`);
  const oidc = await (await fetch(`${issuer}/.well-known/openid-configuration`)).json();
  check('AS metadata at REST fallback {issuer}/.well-known/openid-configuration', oidc.issuer === issuer && oidc.code_challenge_methods_supported?.[0] === 'S256', JSON.stringify(oidc).slice(0, 200));
  const wkPrm = await fetch(`${BASE}/.well-known/oauth-protected-resource${new URL(url).pathname}`);
  check('PRM at /.well-known/oauth-protected-resource{path}', wkPrm.status === 200 && (await wkPrm.json()).resource === url, String(wkPrm.status));

  const redirectUri = 'http://127.0.0.1:53682/callback';
  res = await fetch(oidc.registration_endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ client_name: 'Smoke Client', redirect_uris: ['http://127.0.0.1/callback'] }) });
  const client = await res.json();
  check('DCR registers a public client', res.status === 201 && client.token_endpoint_auth_method === 'none', JSON.stringify(client));

  const b64url = (buf) => Buffer.from(buf).toString('base64url');
  const { createHash, randomBytes } = await import('node:crypto');
  const verifier = b64url(randomBytes(32));
  const authUrl = new URL(oidc.authorization_endpoint);
  Object.entries({ response_type: 'code', client_id: client.client_id, redirect_uri: redirectUri, code_challenge: b64url(createHash('sha256').update(verifier).digest()), code_challenge_method: 'S256', state: 'smoke-state', scope: 'kuduy:read kuduy:write offline_access', resource: url })
    .forEach(([k, v]) => authUrl.searchParams.set(k, v));

  const jar = new Map([['wordpress_test_cookie', 'WP%20Cookie%20check']]);
  const cookies = () => [...jar].map(([k, v]) => `${k}=${v}`).join('; ');
  const keep = (r) => r.headers.getSetCookie().forEach((c) => { const [kv] = c.split(';'); const i = kv.indexOf('='); jar.set(kv.slice(0, i), kv.slice(i + 1)); });

  const excerpt = (t) => (t.match(/<div id="login_error"[\s\S]*?<\/div>/)?.[0] ?? t.match(/<title>[\s\S]*?<\/title>/)?.[0] ?? t.slice(0, 300)).replace(/\s+/g, ' ');
  res = await fetch(authUrl, { redirect: 'manual' });
  check('authorize while logged out redirects to wp-login.php', res.status === 302 && (res.headers.get('location') ?? '').includes('wp-login.php'), `${res.status} ${res.headers.get('location')} ${excerpt(await res.text())} url=${authUrl.href.slice(0, 120)}`);

  res = await fetch(`${BASE}/wp-login.php`, { method: 'POST', redirect: 'manual', headers: { 'Content-Type': 'application/x-www-form-urlencoded', Cookie: cookies() }, body: new URLSearchParams({ log: 'admin', pwd: readFileSync(join(out, 'login.txt'), 'utf8'), testcookie: '1', redirect_to: authUrl.href }) });
  keep(res);
  check('real wp-login.php login', res.status === 302 && [...jar.keys()].some((k) => k.startsWith('wordpress_logged_in_')), `${res.status} ${excerpt(await res.text())}`);

  res = await fetch(authUrl, { headers: { Cookie: cookies() } });
  const html = await res.text();
  const nonce = html.match(/name="_kuduy_nonce" value="([^"]+)"/)?.[1];
  check('consent page: names client + return host, anti-framing headers', res.status === 200 && html.includes('Smoke Client') && html.includes('127.0.0.1') && res.headers.get('x-frame-options') === 'DENY' && /frame-ancestors 'none'/.test(res.headers.get('content-security-policy') ?? '') && !!nonce, `${res.status} xfo=${res.headers.get('x-frame-options')}`);

  const form = new URLSearchParams();
  for (const [, name, value] of html.matchAll(/<input type="hidden" name="([^"]+)" value="([^"]*)"/g)) form.set(name, value.replace(/&amp;/g, '&').replace(/&quot;/g, '"').replace(/&#039;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>'));
  form.append('scopes[]', 'kuduy:read');
  form.append('scopes[]', 'kuduy:write');
  form.set('_kuduy_nonce', nonce ?? '');
  form.set('decision', 'approve');
  res = await fetch(oidc.authorization_endpoint, { method: 'POST', redirect: 'manual', headers: { 'Content-Type': 'application/x-www-form-urlencoded', Cookie: cookies() }, body: form });
  const back = new URL(res.headers.get('location') ?? 'http://invalid/');
  check('approve redirects to client with code, state, iss', res.status === 302 && back.origin + back.pathname === redirectUri && !!back.searchParams.get('code') && back.searchParams.get('state') === 'smoke-state' && back.searchParams.get('iss') === issuer, `${res.status} ${back.href}`);

  const tokenReq = (params) => fetch(oidc.token_endpoint, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(params) });
  res = await tokenReq({ grant_type: 'authorization_code', code: back.searchParams.get('code') ?? '', redirect_uri: redirectUri, client_id: client.client_id, code_verifier: verifier, resource: url });
  const t1 = await res.json();
  check('token endpoint issues rotating tokens, no-store', res.status === 200 && t1.access_token?.startsWith('kmcp_at_') && t1.refresh_token?.startsWith('kmcp_rt_') && res.headers.get('cache-control') === 'no-store', JSON.stringify(t1).slice(0, 200));

  res = await modern('tools/call', { name: 'kuduy-site-info' }, { Authorization: `Bearer ${t1.access_token}` });
  [body] = await json(res);
  check('MCP tools/call with OAuth bearer', res.status === 200 && body?.result?.isError === false && body.result.structuredContent?.user?.id === 1, `${res.status}`);

  res = await tokenReq({ grant_type: 'refresh_token', refresh_token: t1.refresh_token, client_id: client.client_id, resource: url });
  const t2 = await res.json();
  check('refresh rotates', res.status === 200 && t2.refresh_token && t2.refresh_token !== t1.refresh_token, JSON.stringify(t2).slice(0, 200));
  res = await tokenReq({ grant_type: 'refresh_token', refresh_token: t1.refresh_token, client_id: client.client_id });
  const reuse = await res.json();
  res = await tokenReq({ grant_type: 'refresh_token', refresh_token: t2.refresh_token, client_id: client.client_id });
  const after = await res.json();
  check('refresh reuse -> invalid_grant and whole connection revoked', reuse.error === 'invalid_grant' && after.error === 'invalid_grant', `${reuse.error} / ${after.error}`);
  res = await modern('tools/list', {}, { Authorization: `Bearer ${t2.access_token}` });
  check('revoked access token -> 401 invalid_token', res.status === 401 && /error="invalid_token"/.test(res.headers.get('www-authenticate') ?? ''), `${res.status} ${res.headers.get('www-authenticate')}`);
} catch (e) {
  check('smoke run', false, e.message);
} finally {
  stop();
}
const failed = results.filter((ok) => !ok).length;
console.log(`\nSmoke: ${results.length - failed}/${results.length} passed`);
process.exit(failed ? 1 : 0);
