// Runs tests/integration with PHPUnit inside Playground's WordPress and fails on the JUnit report,
// not on Playground's exit code (PHPUnit's exit() inside runPHP is not a reliable signal).
// Env: KUDUY_WP (default 7.1), KUDUY_PHP (default 8.3).
import { spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { join } from 'node:path';
import { cleanPlaygroundTemp } from './playground-temp.mjs';

const root = fileURLToPath(new URL('..', import.meta.url));
const out = join(root, 'build', 'it');
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
const steps = elementor === 'none' ? [] : [install('elementor', elementor)];
// KUDUY_PLUGINS=slug@version,… installs more wordpress.org plugins after Elementor (e.g. Elementor add-ons).
for (const entry of (process.env.KUDUY_PLUGINS ?? '').split(',').map((s) => s.trim()).filter(Boolean)) {
  const [slug, version] = entry.split('@');
  if (!/^[a-z0-9-]+$/.test(slug) || !/^[0-9][0-9.]*$/.test(version ?? '')) {
    console.error(`KUDUY_PLUGINS entry "${entry}" must be slug@version.`);
    process.exit(1);
  }
  steps.push(install(slug, version));
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
const attr = (name) => Number(xml.match(new RegExp(`<testsuite [^>]*${name}="(\\d+)"`))?.[1] ?? 0);
const [tests, failures, errors, skipped] = ['tests', 'failures', 'errors', 'skipped'].map(attr);
for (const m of xml.matchAll(/<testcase name="([^"]+)"[^>]*>\s*<(failure|error)[^>]*>([\s\S]*?)<\/\2>/g)) {
  console.error(`\n✗ ${m[1]} (${m[2]})\n${m[3].replace(/&quot;/g, '"').replace(/&#039;/g, "'").replace(/&gt;/g, '>').replace(/&lt;/g, '<').trim()}`);
}
const env = existsSync(join(out, 'env.txt')) ? readFileSync(join(out, 'env.txt'), 'utf8') : 'unknown versions';
console.log(`\nIntegration on ${env}: ${tests} tests, ${failures} failures, ${errors} errors, ${skipped} skipped`);
process.exit(tests > 0 && failures === 0 && errors === 0 ? 0 : 1);
