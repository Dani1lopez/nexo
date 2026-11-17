import { test } from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, mkdtempSync, writeFileSync, mkdirSync, symlinkSync, rmSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { createRunDirectory, removeRunDirectory } from './run-directory.mjs';

test('removes only known files from its own run directory', () => {
  const run = createRunDirectory();
  writeFileSync(path.join(run.directory, 'e2e.sqlite'), 'test');
  writeFileSync(path.join(run.directory, 'e2e.sqlite-wal'), 'test');
  removeRunDirectory(run);
  assert.equal(existsSync(run.directory), false);
});

test('refuses a foreign directory, a forged identity, and a symlink', () => {
  const root = mkdtempSync(path.join(os.tmpdir(), 'nexo-cleanup-test-'));
  const foreign = path.join(root, 'foreign');
  mkdirSync(foreign);
  writeFileSync(path.join(foreign, 'keep'), 'safe');
  const link = path.join(root, 'link');
  symlinkSync(foreign, link);
  const run = createRunDirectory();
  try {
    assert.throws(() => removeRunDirectory({ directory: foreign, token: run.token }));
    assert.throws(() => removeRunDirectory({ directory: link, token: run.token }));
    assert.throws(() => removeRunDirectory({ directory: run.directory, token: 'wrong' }));
    assert.equal(existsSync(path.join(foreign, 'keep')), true);
    assert.equal(existsSync(run.directory), true);
  } finally {
    removeRunDirectory(run);
    rmSync(root, { recursive: true, force: true });
  }
});

test('refuses symlink artifacts and creation over an existing directory', () => {
  const run = createRunDirectory();
  const victim = path.join(run.directory, 'victim');
  writeFileSync(victim, 'safe');
  symlinkSync(victim, path.join(run.directory, 'e2e.sqlite'));
  try {
    assert.throws(() => createRunDirectory(run), { code: 'EEXIST' });
    assert.throws(() => removeRunDirectory(run));
    assert.equal(existsSync(victim), true);
  } finally {
    rmSync(path.join(run.directory, 'e2e.sqlite'));
    rmSync(victim);
    removeRunDirectory(run);
  }
});

test('preserves a directory with unexpected contents', () => {
  const run = createRunDirectory();
  const extra = path.join(run.directory, 'unexpected');
  writeFileSync(extra, 'safe');
  try {
    assert.throws(() => removeRunDirectory(run));
    assert.equal(existsSync(extra), true);
  } finally {
    rmSync(extra);
    removeRunDirectory(run);
  }
});
