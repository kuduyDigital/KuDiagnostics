// Runs tests/integration with PHPUnit inside Playground's WordPress and fails on the JUnit report,
// not on Playground's exit code (PHPUnit's exit() inside runPHP is not a reliable signal).
// Env: KUDUY_WP (default 7.1), KUDUY_PHP (default 8.3), KUDUY_HPOS (on|off, default on; only with WooCommerce),
// KUDUY_FILTER (run only matching tests).
import { spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { join } from 'node:path';
import { cleanPlaygroundTemp } from './playground-temp.mjs';

const root = fileURLToPath(new URL('..', import.meta.url));
// KUDUY_RUN=<name> gives a run its own output folder (build/it-<name>), so several runs can go side by side.
const runName = process.env.KUDUY_RUN ?? '';
if (runName && !/^[a-z0-9-]{1,40}$/.test(runName)) {
  console.error('KUDUY_RUN may only contain a-z, 0-9 and -.');
  process.exit(1);
}
const out = join(root, 'build', runName ? `it-${runName}` : 'it');
const report = join(out, 'junit.xml');
rmSync(out, { recursive: true, force: true });
mkdirSync(out, { recursive: true });

if (!existsSync(join(root, 'vendor', 'phpunit'))) {
  console.error('vendor/ missing: run `composer install` first.');
  process.exit(1);
}

// The blueprint's preferredVersions (or its defaults: latest PHP/WP) override --wp/--php, so set them here.
const wp = process.env.KUDUY_WP ?? '7.1';
const php = process.env.KUDUY_PHP ?? '8.3';
// Elementor is installed and active before WordPress boots the tests (it cannot start mid-request).
// KUDUY_ELEMENTOR=<version> picks a release from wordpress.org; KUDUY_ELEMENTOR=none runs without it.
const elementor = process.env.KUDUY_ELEMENTOR ?? '4.3.6';
const blueprint = JSON.parse(readFileSync(join(root, 'tests', 'integration', 'blueprint.json'), 'utf8'));
const install = (slug, version) => ({
  step: 'installPlugin',
  pluginData: { resource: 'url', url: `https://downloads.wordpress.org/plugin/${slug}.${version}.zip` },
  options: { activate: true },
});
// Test sites never update themselves: with an older pinned plugin (WooCommerce 11.0.1 on WordPress 6.9) WordPress
// started a background update during the blueprint and left `.maintenance` behind, so every later request got the
// maintenance page and PHPUnit never ran (found 2026-11-09).
// KUDUY_CODE_EXEC=1 defines D4's KUDUY_MCP_ALLOW_CODE_EXEC so the code-tool tests run (a separate CI job).
const steps = [{ step: 'defineWpConfigConsts', consts: {
  AUTOMATIC_UPDATER_DISABLED: true,
  ...(process.env.KUDUY_CODE_EXEC === '1' ? { KUDUY_MCP_ALLOW_CODE_EXEC: true } : {}),
} }];
if (elementor !== 'none') steps.push(install('elementor', elementor));
// KUDUY_PLUGINS=slug@version,… installs more wordpress.org plugins after Elementor (e.g. Elementor add-ons).
for (const entry of (process.env.KUDUY_PLUGINS ?? '').split(',').map((s) => s.trim()).filter(Boolean)) {
  const [slug, version] = entry.split('@');
  if (!/^[a-z0-9-]+$/.test(slug) || !/^[0-9][0-9.]*$/.test(version ?? '')) {
    console.error(`KUDUY_PLUGINS entry "${entry}" must be slug@version.`);
    process.exit(1);
  }
  steps.push(install(slug, version));
  if (slug === 'seo-by-rank-math') {
    // Rank Math stays inert until its account is connected or its setup wizard is skipped; do what "Skip" does.
    steps.push({ step: 'setSiteOptions', options: { rank_math_registration_skip: '1' } });
  }
  if (slug === 'polylang') {
    // Polylang loads its pll_* API only in a context (admin, REST, or front end with languages). The test request is
    // none of these until a language exists, so add English and German through Polylang's model first (found
    // 2026-11-10: without this MultilingualTest skipped silently).
    steps.push({
      step: 'runPHP',
      code: "<?php require '/wordpress/wp-load.php'; $m = new PLL_Model( new WP_Syntex\\Polylang\\Options\\Options() ); foreach ( array( 'en_US', 'de_DE' ) as $l ) { $m->languages->add( array( 'locale' => $l ) ); }",
    });
  }
  if (slug === 'woocommerce') {
    // KUDUY_HPOS=on|off picks the order storage (fresh shop, no orders yet, so WooCommerce accepts the switch).
    // kuduy_test_hpos tells the tests which mode was asked for, so a silent fallback fails them.
    const hpos = process.env.KUDUY_HPOS ?? 'on';
    if (!['on', 'off'].includes(hpos)) {
      console.error('KUDUY_HPOS must be on or off.');
      process.exit(1);
    }
    steps.push({ step: 'setSiteOptions', options: {
      woocommerce_coming_soon: 'no',
      woocommerce_custom_orders_table_enabled: hpos === 'on' ? 'yes' : 'no',
      kuduy_test_hpos: hpos,
    } });
  }
}
// KUDUY_LOCAL_PLUGINS=file.zip,… installs licensed ZIPs kept in the repo folder but git-ignored (e.g. Elementor Pro).
// Local runs only: CI has no such files. The repo is mounted into the site, so the ZIP is read from there.
for (const file of (process.env.KUDUY_LOCAL_PLUGINS ?? '').split(',').map((s) => s.trim()).filter(Boolean)) {
  if (!/^[A-Za-z0-9._-]+\.zip$/.test(file) || !existsSync(join(root, file))) {
    console.error(`KUDUY_LOCAL_PLUGINS entry "${file}" must be a .zip file name in the repo folder.`);
    process.exit(1);
  }
  steps.push({
    step: 'installPlugin',
    pluginData: { resource: 'vfs', path: `/wordpress/wp-content/plugins/kuduy-mcp/${file}` },
    options: { activate: true },
  });
}
// KUDUY_FILTER=<PHPUnit filter> runs only matching tests (local debugging; CI always runs everything).
const filter = process.env.KUDUY_FILTER ?? '';
if (filter) {
  if (!/^[A-Za-z0-9_:|]+$/.test(filter)) {
    console.error('KUDUY_FILTER may only contain letters, digits, _, : and | (e.g. SiteEditorTest or test_widgets).');
    process.exit(1);
  }
  for (const s of blueprint.steps) {
    if (s.step === 'runPHP') s.code = s.code.replace("'--log-junit'", `'--filter', '${filter}', '--log-junit'`);
  }
}
writeFileSync(join(out, 'blueprint.json'), JSON.stringify({ ...blueprint, preferredVersions: { php, wp }, steps: [...steps, ...blueprint.steps] }));

const cli = join(root, 'node_modules', '@wp-playground', 'cli', 'wp-playground.js');
const run = spawnSync(process.execPath, [cli, 'run-blueprint', `--wp=${wp}`, `--php=${php}`,
  `--blueprint=${join(out, 'blueprint.json')}`, ...(process.env.KUDUY_DEBUG ? ['--debug'] : []),
  '--mount-dir', root, '/wordpress/wp-content/plugins/kuduy-mcp',
  '--mount-dir', out, '/kuduy-out',
], { cwd: root, stdio: 'inherit' });
cleanPlaygroundTemp(run.pid);

if (!existsSync(report)) {
  console.error(`No JUnit report (Playground exit ${run.status}). PHPUnit did not run or crashed before writing it.`);
  process.exit(1);
}

const xml = readFileSync(report, 'utf8');
// Count the elements themselves: suite totals are missing or misattributed in PHPUnit 10's report when a class
// skips in setUpBeforeClass(), so the first <testsuite> attributes can hide failures elsewhere.
const count = (tag) => (xml.match(new RegExp(`<${tag}[\\s>/]`, 'g')) ?? []).length;
const [tests, failures, errors, skipped] = ['testcase', 'failure', 'error', 'skipped'].map(count);
for (const m of xml.matchAll(/<testcase name="([^"]+)"[^>]*>\s*<(failure|error)[^>]*>([\s\S]*?)<\/\2>/g)) {
  console.error(`\n✗ ${m[1]} (${m[2]})\n${m[3].replace(/&quot;/g, '"').replace(/&#039;/g, "'").replace(/&gt;/g, '>').replace(/&lt;/g, '<').trim()}`);
}
const env = existsSync(join(out, 'env.txt')) ? readFileSync(join(out, 'env.txt'), 'utf8') : 'unknown versions';
console.log(`\nIntegration on ${env}: ${tests} tests, ${failures} failures, ${errors} errors, ${skipped} skipped`);
process.exit(tests > 0 && failures === 0 && errors === 0 ? 0 : 1);
