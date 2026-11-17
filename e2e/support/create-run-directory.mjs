import { createRunDirectory } from './run-directory.mjs';

createRunDirectory({ directory: process.env.E2E_TMP, token: process.env.E2E_RUN_TOKEN });
