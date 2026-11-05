// Real self-update test against GitHub (test matrix G1–G3, end to end):
// installs the FROM release asset in a clean Playground WordPress, stores marker data, lets core's
// wp_update_plugins() find the newer release, runs core's Plugin_Upgrader (our checksum filter included),
// then checks in a fresh request that the new version is active and the data survived.
// Usage: node scripts/check-update.mjs [from=0.1.0] [to=0.1.1]
import { spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { join } from 'node:path';
import { cleanPlaygroundTemp } from './playground-temp.mjs';

const [from = '0.1.0', to = '0.1.1'] = process.argv.slice(2);
const root = fileURLToPath(new URL('..', import.meta.url));
const dir = join(root, 'build', 'check-update');
rmSync(dir, { recursive: true, force: true });
mkdirSync(dir, { recursive: true });

const pre = `<?php require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/misc.php';
wp_set_current_user( 1 );
global $wpdb;
$f   = 'kuduy-mcp/kuduy-mcp.php';
$out = array( 'before_version' => get_plugin_data( WP_PLUGIN_DIR . '/' . $f, false, false )['Version'], 'before_active' => is_plugin_active( $f ) );
update_option( 'kuduy_mcp_settings', array( 'marker' => 'keep-me' ) );
$wpdb->insert( $wpdb->prefix . 'kuduy_oauth_clients', array( 'client_id' => 'marker-client', 'type' => 'dcr', 'name' => 'Marker', 'redirect_uris' => '[]', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );

delete_site_transient( 'update_plugins' );
delete_site_transient( 'kuduy_mcp_release' );
wp_update_plugins();
$t = get_site_transient( 'update_plugins' );
$offer = is_object( $t ) ? ( $t->response[ $f ] ?? null ) : null;
$out['offered_version'] = $offer->new_version ?? null;
$out['offered_package'] = $offer->package ?? null;

$out['pre_download'] = 'not called';
add_filter( 'upgrader_pre_download', function ( $reply ) use ( &$out ) { $out['pre_download'] = is_wp_error( $reply ) ? 'error: ' . $reply->get_error_message() : ( is_string( $reply ) ? 'verified file' : 'passed through' ); return $reply; }, 99 );
$result = ( new Plugin_Upgrader( new Automatic_Upgrader_Skin() ) )->upgrade( $f );
$out['upgrade_result'] = is_wp_error( $result ) ? 'error: ' . $result->get_error_message() : var_export( $result, true );
$out['after_version_file'] = get_plugin_data( WP_PLUGIN_DIR . '/' . $f, false, false )['Version'];
file_put_contents( '/check-out/pre.json', json_encode( $out ) );`;

// A new request: new code loaded. Reactivate like the "Activate" link after a manual update if needed.
const post = `<?php require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
global $wpdb;
$f   = 'kuduy-mcp/kuduy-mcp.php';
$out = array( 'active_after_upgrade' => is_plugin_active( $f ) );
if ( ! $out['active_after_upgrade'] ) { $r = activate_plugin( $f ); $out['reactivate'] = is_wp_error( $r ) ? $r->get_error_message() : 'ok'; }
$out['running_version'] = class_exists( 'Kuduy\\\\Mcp\\\\Plugin' ) ? Kuduy\\Mcp\\Plugin::VERSION : null;
$out['marker_option']   = get_option( 'kuduy_mcp_settings' )['marker'] ?? null;
$out['marker_row']      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}kuduy_oauth_clients WHERE client_id = 'marker-client'" );
$out['db_version']      = (int) get_option( 'kuduy_mcp_db_version' );
file_put_contents( '/check-out/post.json', json_encode( $out ) );`;

writeFileSync(join(dir, 'blueprint.json'), JSON.stringify({
  preferredVersions: { php: '8.3', wp: '7.1' },
  steps: [
    { step: 'installPlugin', pluginData: { resource: 'url', url: `https://github.com/kuduyDigital/KuDiagnostics/releases/download/v${from}/kuduy-mcp.zip` }, options: { activate: true } },
    { step: 'runPHP', code: pre },
    { step: 'runPHP', code: post },
  ],
}));

const cli = join(root, 'node_modules', '@wp-playground', 'cli', 'wp-playground.js');
const run = spawnSync(process.execPath, [cli, 'run-blueprint', `--blueprint=${join(dir, 'blueprint.json')}`, '--mount-dir', dir, '/check-out'], { cwd: root, stdio: 'inherit' });
cleanPlaygroundTemp(run.pid);

const read = (name) => (existsSync(join(dir, name)) ? JSON.parse(readFileSync(join(dir, name), 'utf8')) : null);
const a = read('pre.json');
const b = read('post.json');
console.log(JSON.stringify({ pre: a, post: b }, null, 2));
if (!a || !b) {
  console.error('A step did not finish; see the output above.');
  process.exit(1);
}
const checks = [
  [`installed v${from} from the GitHub release`, a.before_version === from && a.before_active === true],
  [`core offered v${to} with the release asset`, a.offered_version === to && String(a.offered_package).endsWith(`/v${to}/kuduy-mcp.zip`)],
  ['download went through the checksum check', a.pre_download === 'verified file'],
  ['core upgrader succeeded', a.upgrade_result === 'true' && a.after_version_file === to],
  [`v${to} running after upgrade`, b.running_version === to],
  ['settings and table rows kept', b.marker_option === 'keep-me' && b.marker_row === 1 && b.db_version >= 1],
];
for (const [name, ok] of checks) console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}`);
process.exit(checks.every(([, ok]) => ok) ? 0 : 1);
