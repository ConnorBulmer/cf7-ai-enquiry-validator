import * as path from 'node:path';

import { defineConfig } from '@playwright/test';

const baseURL = process.env.CF7AIV_BASE_URL?.trim();

export default defineConfig({
	testDir: __dirname,
	testMatch: /.*\.(spec|test)\.(js|ts)/,
	outputDir: path.join(__dirname, 'test-results'),
	timeout: 45_000,
	expect: {
		timeout: 10_000,
	},
	fullyParallel: false,
	workers: 1,
	reporter: [
		['line'],
		['json', { outputFile: path.join(__dirname, 'test-results', 'results.json') }],
	],
	use: {
		...(baseURL ? { baseURL } : {}),
		trace: 'retain-on-failure',
		video: 'off',
		screenshot: 'off',
	},
});
