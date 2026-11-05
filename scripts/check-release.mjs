// Installs build/kuduy-mcp.zip into a clean Playground WordPress the way an upload does (installPlugin from the ZIP),
// activates it and checks tables, REST routes and that no dev files were shipped. Run build-release.php first.
// Env: KUDUY_WP (default 7.1), KUDUY_PHP (default 8.1 = minimum).
import { spawnSync } from 'node:child_process';
import { copyFileSync, existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { join } from 'node:path';
import { cleanPlaygroundTemp } from './playground-temp.mjs';

const root = fileURLToPath(new URL('..', import.meta.url));
const zip = join(root, 'build', 'kuduy-mcp.zip');
const dir = join(root, 'build', 'check-release');
if (!existsSync(zip)) {
  console.error('build/kuduy-mcp.zip missing: run `php scripts/build-release.php` first.');
  process.exit(1);
}
rmSync(dir, { recursive: true, force: true });
mkdirSync(dir, { recursive: true });
copyFileSync(zip, join(dir, 'kuduy-mcp.zip'));

const php = `<?php require '/wordpress/wp-load.php'; require_once ABSPATH . 'wp-admin/includes/plugin.php';
global $wpdb;
$f = 'kuduy-mcp/kuduy-mcp.php';
$out = array(
  'active'   => is_plugin_active( $f ),
  'version'  => get_plugin_data( WP_PLUGIN_DIR . '/' . $f, false, false )['Version'] ?? null,
  'tables'   => count( array_filter( array( 'kuduy_oauth_clients', 'kuduy_oauth_codes', 'kuduy_oauth_tokens', 'kuduy_audit_log', 'kuduy_snapshots' ), fn( $t ) => false !== $wpdb->query( "SELECT 1 FROM {$wpdb->prefix}{$t} LIMIT 1" ) && '' === $wpdb->last_error ) ),
  'routes'   => array_values( array_filter( array_keys( rest_get_server()->get_routes() ), fn( $r ) => str_starts_with( $r, '/kuduy-mcp/' ) ) ),
  'devfiles' => array_values( array_filter( array( 'tests', 'node_modules', 'docs', 'scripts', 'vendor/phpunit', 'vendor/phpstan', 'composer.lock', 'package.json' ), fn( $p ) => file_exists( WP_PLUGIN_DIR . '/kuduy-mcp/' . $p ) ) ),
);
file_put_contents( '/check-out/result.json', json_encode( $out ) );`;

writeFileSync(join(dir, 'blueprint.json'), JSON.stringify({
  preferredVersions: { php: process.env.KUDUY_PHP ?? '8.1', wp: process.env.KUDUY_WP ?? '7.1' },
  steps: [
    { step: 'installPlugin', pluginData: { resource: 'bundled', path: 'kuduy-mcp.zip' }, options: { activate: true } },
    { step: 'runPHP', code: php },
  ],
}));

const cli = join(root, 'node_modules', '@wp-playground', 'cli', 'wp-playground.js');
const run = spawnSync(process.execPath, [cli, 'run-blueprint', `--blueprint=${join(dir, 'blueprint.json')}`, '--blueprint-may-read-adjacent-files',
  '--mount-dir', dir, '/check-out'], { cwd: root, stdio: 'inherit' });
cleanPlaygroundTemp(run.pid);

const resultFile = join(dir, 'result.json');
if (!existsSync(resultFile)) {
  console.error(`No result (Playground exit ${run.status}): the ZIP did not install or activate.`);
  process.exit(1);
}
const r = JSON.parse(readFileSync(resultFile, 'utf8'));
const checks = [
  ['installed from ZIP and active', r.active === true],
  [`version ${r.version}`, typeof r.version === 'string'],
  ['5 tables created', r.tables === 5],
  ['MCP + OAuth routes registered', r.routes.includes('/kuduy-mcp/v1/mcp') && r.routes.includes('/kuduy-mcp/v1/oauth/token')],
  [`no dev files shipped${r.devfiles.length ? `: ${r.devfiles.join(', ')}` : ''}`, r.devfiles.length === 0],
];
for (const [name, ok] of checks) console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}`);
process.exit(checks.every(([, ok]) => ok) ? 0 : 1);
