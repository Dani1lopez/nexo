import { existsSync } from 'node:fs';
import { removeRunDirectory } from './support/run-directory.mjs';

export default function globalTeardown(): void {
  const directory = process.env.E2E_TMP;
  const token = process.env.E2E_RUN_TOKEN;
  if (directory && token && existsSync(directory)) {
    removeRunDirectory({ directory, token });
  }
}
