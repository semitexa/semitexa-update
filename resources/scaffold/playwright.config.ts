import { defineConfig, devices } from '@playwright/test';

/**
 * E2E tests of this project, run by `bin/semitexa test:run` in the e2e-runner
 * container (docker-compose.test.yml); nothing is installed on the host.
 *
 * Specs live beside the module they test, in `src/modules/<Module>/tests/E2E/`,
 * and are found by glob: a new module with a spec needs no change here.
 * bin/semitexa passes the directories it discovered, so the two agree.
 *
 * baseURL: the runner shares the app container's network, so localhost is the
 * app; Chromium never upgrades localhost to HTTPS. PLAYWRIGHT_BASE_URL points
 * a run elsewhere.
 *
 * This file belongs to the project: edit it freely. `bin/semitexa update`
 * creates it when missing and leaves an edited one alone.
 */

const baseURL = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:9502';

export default defineConfig({
    testDir: '.',
    testMatch: [
        'src/modules/*/tests/E2E/**/*.spec.ts',
        'packages/*/tests/E2E/**/*.spec.ts',
    ],
    timeout: 30_000,
    expect: { timeout: 5_000 },
    // One worker: specs share the app and its database. A suite that can run
    // in parallel says so with test.describe.configure in its own file.
    fullyParallel: false,
    workers: 1,
    reporter: process.env.CI
        ? [['list'], ['junit', { outputFile: 'var/test-results/e2e-junit.xml' }]]
        : [['list']],
    forbidOnly: !!process.env.CI,
    outputDir: 'var/test-results/e2e',
    use: {
        baseURL,
        actionTimeout: 7_000,
        navigationTimeout: 15_000,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'off',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
