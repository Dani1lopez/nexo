import { defineConfig, devices } from '@playwright/test';
import path from 'node:path';
import { newRunIdentity } from './support/run-directory.mjs';
import { accounts } from './support/accounts';

const port = Number(process.env.E2E_PORT ?? 8010);
// Never accept a caller-provided cleanup target or ownership token.
const run = newRunIdentity();
process.env.E2E_TMP = run.directory;
process.env.E2E_RUN_TOKEN = run.token;

export default defineConfig({
  testDir: './tests',
  outputDir: path.resolve(__dirname, '../test-results'),
  // One shared database and no automatic retries: a flaky test must be fixed, not repeated.
  workers: 1,
  retries: 0,
  forbidOnly: Boolean(process.env.CI),
  timeout: 30_000,
  expect: { timeout: 5_000 },
  reporter: process.env.CI ? [['github'], ['list']] : 'list',
  globalTeardown: './global-teardown.ts',
  use: {
    baseURL: `http://127.0.0.1:${port}`,
    trace: 'retain-on-failure',
    locale: 'es-ES',
    timezoneId: 'Europe/Madrid',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
  webServer: {
    command: 'bash e2e/support/start.sh',
    cwd: path.resolve(__dirname, '..'),
    url: `http://127.0.0.1:${port}/up`,
    reuseExistingServer: false,
    timeout: 60_000,
    env: {
      E2E_PORT: String(port),
      E2E_TMP: run.directory,
      E2E_RUN_TOKEN: run.token,
      E2E_TREASURY_EMAIL: accounts.treasury.email,
      E2E_TREASURY_PASSWORD: accounts.treasury.password,
      E2E_BOARD_EMAIL: accounts.board.email,
      E2E_BOARD_PASSWORD: accounts.board.password,
    },
  },
});
