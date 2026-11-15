// End-to-end tests against the wp-env development site.
//
//   npm run env:start && npm run seed && npm run test:e2e
//
// Uses the system Chromium when PLAYWRIGHT_CHROMIUM is set (no browser
// download needed), otherwise Playwright's own Chromium
// (npx playwright install chromium).
import { defineConfig, devices } from '@playwright/test';

const executablePath = process.env.PLAYWRIGHT_CHROMIUM || undefined;

export default defineConfig( {
	testDir: 'tests/e2e',
	timeout: 60_000,
	retries: process.env.CI ? 1 : 0,
	// One worker: tests share the site's state (active theme, selections, uploads).
	workers: 1,
	reporter: process.env.CI ? 'github' : 'list',
	outputDir: 'tests/e2e/.results',
	use: {
		baseURL: process.env.WP_BASE_URL || 'http://localhost:8888',
		screenshot: 'only-on-failure',
		launchOptions: {
			executablePath,
			// Software WebGL so the 360° viewer renders on machines and CI runners without a GPU.
			args: [ '--use-angle=swiftshader', '--enable-unsafe-swiftshader', '--ignore-gpu-blocklist' ],
		},
	},
	projects: [
		{ name: 'desktop', use: { ...devices[ 'Desktop Chrome' ], viewport: { width: 1280, height: 800 } } },
		{ name: 'phone', use: { ...devices[ 'Pixel 7' ] } },
	],
} );
