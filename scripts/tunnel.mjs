// Public HTTPS test site: Cloudflare quick tunnel -> Playground (WP 7.1 + Elementor + WooCommerce + KUDUY MCP from this repo).
// shortcut: quick tunnel URL changes on every run (connectors must be re-added); use a named tunnel for long-lived testing.
import { spawn } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const PORT = Number(process.env.KUDUY_PORT ?? 9410);
const root = new URL('..', import.meta.url);
const children = [];
const stop = (code = 0) => { children.forEach((c) => c.kill()); process.exit(code); };
process.on('SIGINT', () => stop());

const password = randomBytes(18).toString('base64url');
writeFileSync(new URL('.tunnel-admin.txt', root), `user: admin\npassword: ${password}\n`);

const tunnel = spawn('cloudflared', ['tunnel', '--no-autoupdate', '--url', `http://127.0.0.1:${PORT}`]);
children.push(tunnel);
tunnel.on('exit', (code) => { console.error(`cloudflared exited (${code})`); stop(1); });

const siteUrl = await new Promise((resolve) => {
  tunnel.stderr.on('data', (chunk) => {
    const m = String(chunk).match(/https:\/\/[a-z0-9-]+\.trycloudflare\.com/);
    if (m) resolve(m[0]);
  });
});

// Never auto-login a public site; trust cloudflared's X-Forwarded-Proto so WP sees HTTPS.
const base = JSON.parse(readFileSync(new URL('tests/playground/blueprint.json', root)));
const blueprint = {
  ...base,
  login: false,
  steps: [
    ...(base.steps ?? []),
    {
      step: 'writeFile',
      path: '/wordpress/wp-content/mu-plugins/kuduy-proxy-https.php',
      data: "<?php\nif ( ( $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '' ) === 'https' ) { $_SERVER['HTTPS'] = 'on'; }\n",
    },
    { step: 'activatePlugin', pluginPath: 'kuduy-mcp/kuduy-mcp.php' },
    { step: 'runPHP', code: `<?php require '/wordpress/wp-load.php'; wp_set_password('${password}', 1); update_option( 'permalink_structure', '/%postname%/' ); flush_rewrite_rules();` },
  ],
};
const blueprintPath = join(tmpdir(), 'kuduy-tunnel-blueprint.json');
writeFileSync(blueprintPath, JSON.stringify(blueprint));

const cli = new URL('node_modules/@wp-playground/cli/wp-playground.js', root);
const wp = spawn(process.execPath, [cli.pathname.replace(/^\/(\w:)/, '$1'), 'server',
  '--wp=7.1', '--php=8.3', `--port=${PORT}`, `--site-url=${siteUrl}`, `--blueprint=${blueprintPath}`,
  '--mount-dir', fileURLToPath(root), '/wordpress/wp-content/plugins/kuduy-mcp'], { stdio: 'inherit' });
children.push(wp);
wp.on('exit', (code) => stop(code ?? 1));

console.log(`\nPublic URL: ${siteUrl}\nMCP connector URL: ${siteUrl}/wp-json/kuduy-mcp/v1/mcp\nAdmin login: see .tunnel-admin.txt\nCtrl+C stops both.\n`);
