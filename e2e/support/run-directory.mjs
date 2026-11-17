import { randomBytes } from 'node:crypto';
import { lstatSync, mkdirSync, readFileSync, readdirSync, realpathSync, rmdirSync, unlinkSync, writeFileSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';

const files = ['e2e.sqlite', 'e2e.sqlite-wal', 'e2e.sqlite-shm', 'e2e.sqlite-journal'];
const marker = '.nexo-owner';

export function newRunIdentity() {
  return {
    directory: path.join(realpathSync(os.tmpdir()), `nexo-e2e-${randomBytes(16).toString('hex')}`),
    token: randomBytes(32).toString('hex'),
  };
}

export function createRunDirectory(run = newRunIdentity()) {
  if (path.dirname(run.directory) !== realpathSync(os.tmpdir()) ||
      !/^nexo-e2e-[a-f0-9]{32}$/.test(path.basename(run.directory)) ||
      !/^[a-f0-9]{64}$/.test(run.token)) throw new Error('Invalid E2E identity');
  mkdirSync(run.directory, { mode: 0o700 });
  writeFileSync(path.join(run.directory, marker), run.token, { flag: 'wx', mode: 0o600 });
  return run;
}

export function removeRunDirectory({ directory, token }) {
  const root = realpathSync(os.tmpdir());
  if (typeof directory !== 'string' || typeof token !== 'string' || !/^[a-f0-9]{64}$/.test(token) ||
      path.dirname(directory) !== root || !path.basename(directory).startsWith('nexo-e2e-') ||
      !lstatSync(directory).isDirectory() || realpathSync(directory) !== directory ||
      !lstatSync(path.join(directory, marker)).isFile() ||
      readFileSync(path.join(directory, marker), 'utf8') !== token) {
    throw new Error('Refusing to remove an unowned E2E directory');
  }
  const entries = readdirSync(directory);
  if (entries.some((entry) => entry !== marker && !files.includes(entry))) {
    throw new Error('Refusing to remove an E2E directory with unexpected files');
  }
  for (const file of entries.filter((entry) => entry !== marker)) {
    const target = path.join(directory, file);
    if (!lstatSync(target).isFile()) throw new Error('Refusing to remove a non-file E2E artifact');
  }
  for (const file of entries.filter((entry) => entry !== marker)) unlinkSync(path.join(directory, file));
  unlinkSync(path.join(directory, marker));
  rmdirSync(directory);
}
