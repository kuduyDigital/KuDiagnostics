// WordPress Playground CLI unpacks a full WordPress into the system temp folder for every run
// ("<exe>-playground-cli-site-<pid>-…") and leaves it there when the run ends or is killed.
// Each run is ~300 MB; dozens of runs filled the dev machine's C: drive. Call this after a run exits.
import { readdirSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

export function cleanPlaygroundTemp(pid) {
  if (!pid) return;
  const marker = `playground-cli-site-${pid}-`;
  for (const name of readdirSync(tmpdir())) {
    if (name.includes(marker)) {
      rmSync(join(tmpdir(), name), { recursive: true, force: true, maxRetries: 3 });
    }
  }
}
