import * as fs from 'node:fs';
import * as path from 'node:path';

import { test as base, expect, type Browser, type Page } from '@playwright/test';

const baseURL = process.env.CF7AIV_BASE_URL?.trim() || '';
const configuredContactURL = process.env.CF7AIV_CONTACT_URL?.trim() || '';
const configuredAuthState = process.env.CF7AIV_AUTH_STATE?.trim()
	|| process.env.CF7AIV_STORAGE_STATE?.trim()
	|| '';
const authStatePath = configuredAuthState && fs.existsSync(path.resolve(configuredAuthState))
	&& fs.statSync(path.resolve(configuredAuthState)).isFile()
	? path.resolve(configuredAuthState)
	: null;
const authUnavailableReason = configuredAuthState === ''
	? 'Authenticated UX tests require CF7AIV_AUTH_STATE or CF7AIV_STORAGE_STATE pointing to an existing administrator storage-state file.'
	: 'Authenticated UX tests require the configured administrator storage-state file to exist.';
const defaultContactPath = '/portability-fixture/';
const viewports = [
	{ width: 1440, height: 1000 },
	{ width: 1024, height: 900 },
	{ width: 390, height: 844 },
] as const;

type PublicConsentFixture = {
	google: Record<string, string>;
	wordpress: {
		statistics: boolean;
		marketing: boolean;
	};
};

type DiagnosticLog = {
	console: string[];
	pageErrors: string[];
	requestFailures: string[];
	responses: string[];
};

type TrackedPage = {
	page: Page;
	log: DiagnosticLog;
};

type Fixtures = {
	trackedPages: TrackedPage[];
};

const test = base.extend<Fixtures>({
	trackedPages: async ({}, use, testInfo) => {
		const trackedPages: TrackedPage[] = [];
		await use(trackedPages);

		const lines: string[] = [];
		trackedPages.forEach(({ page, log }, index) => {
			lines.push(`Page ${index + 1}: ${safePageURL(page.url())}`);
			log.console.forEach((entry) => lines.push(`console ${entry}`));
			log.pageErrors.forEach((entry) => lines.push(`pageerror ${entry}`));
			log.requestFailures.forEach((entry) => lines.push(`requestfailed ${entry}`));
			log.responses.forEach((entry) => lines.push(`response ${entry}`));
		});
		if (lines.length === 0) {
			lines.push('No browser diagnostics were captured.');
		}
		await testInfo.attach('browser-diagnostics', {
			body: lines.join('\n'),
			contentType: 'text/plain',
		});
	},
});

const protectedPages = new WeakSet<Page>();

function redact(value: string): string {
	return value
		.replace(/(api[_-]?key|secret|token|password|authorization|cookie)\s*[:=]\s*[^\s,;]+/gi, '$1=[redacted]')
		.replace(/Bearer\s+[^\s]+/gi, 'Bearer [redacted]')
		.slice(0, 1000);
}

function safePageURL(value: string): string {
	try {
		const url = new URL(value);
		return `${url.protocol}//${url.host}${url.pathname}`;
	} catch {
		return '[no page URL]';
	}
}

function safeRequestURL(value: string): string {
	return safePageURL(value);
}

function trackPage(page: Page, trackedPages: TrackedPage[]): void {
	if (trackedPages.some((entry) => entry.page === page)) {
		return;
	}

	const log: DiagnosticLog = {
		console: [],
		pageErrors: [],
		requestFailures: [],
		responses: [],
	};
	page.on('console', (message) => {
		if (log.console.length < 100) {
			log.console.push(`${message.type()}: ${redact(message.text())}`);
		}
	});
	page.on('pageerror', (error) => {
		if (log.pageErrors.length < 100) {
			log.pageErrors.push(redact(error.message));
		}
	});
	page.on('requestfailed', (request) => {
		if (log.requestFailures.length < 100) {
			log.requestFailures.push(`${request.method()} ${safeRequestURL(request.url())}: ${redact(request.failure()?.errorText || 'unknown failure')}`);
		}
	});
	page.on('response', (response) => {
		if (response.status() >= 400 && log.responses.length < 100) {
			log.responses.push(`${response.status()} ${response.request().method()} ${safeRequestURL(response.url())}`);
		}
	});
	trackedPages.push({ page, log });
}

function isTransportRequest(url: string): boolean {
	return /(?:api\.openai\.com|api\.deepseek\.com|capi\.uet\.microsoft\.com|(?:www\.)?google-analytics\.com\/.*collect|analytics\.google\.com\/.*collect|bat\.bing\.com\/action)/i.test(url);
}

async function protectTransport(page: Page): Promise<void> {
	if (protectedPages.has(page)) {
		return;
	}
	protectedPages.add(page);
	await page.route('**/*', async (route) => {
		if (isTransportRequest(route.request().url())) {
			await route.abort('blockedbyclient');
			return;
		}
		await route.continue();
	});
}

function requireBaseURL(): string {
	test.skip(baseURL === '', 'CF7AIV_BASE_URL is required for browser UX tests.');
	return baseURL;
}

function requireAuthState(): string {
	test.skip(authStatePath === null, authUnavailableReason);
	return authStatePath as string;
}

function siteURL(target: string): string {
	return new URL(target, requireBaseURL()).toString();
}

function contactURL(): string {
	return new URL(configuredContactURL || defaultContactPath, requireBaseURL()).toString();
}

async function expectNoOverflow(page: Page, label: string): Promise<void> {
	await expect.poll(
		() => page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth),
		{ message: `Horizontal overflow at ${label}` },
	).toBe(true);
}

async function expectLabeledControlsAndNonces(page: Page): Promise<void> {
	const unlabeledControls = await page.locator(
		'.cf7aiv-admin input:not([type="hidden"]):not([type="submit"]):not([type="button"]), .cf7aiv-admin select, .cf7aiv-admin textarea',
	).evaluateAll((controls) => controls
		.filter((control) => !control.labels || control.labels.length === 0)
		.map((control) => `${control.tagName.toLowerCase()}#${control.id || '[no-id]'}`));
	expect(unlabeledControls, 'Every visible admin form control must have an associated label.').toEqual([]);

	const formsWithoutNonces = await page.locator('.cf7aiv-admin form').evaluateAll((forms) => forms
		.filter((form) => !form.querySelector('input[name="_wpnonce"]'))
		.map((form) => form.id || form.getAttribute('action') || '[anonymous-form]'));
	expect(formsWithoutNonces, 'Every admin action form must contain a nonce field.').toEqual([]);
}

async function openAdminPage(browser: Browser, trackedPages: TrackedPage[], pageSlug: string): Promise<{ context: Awaited<ReturnType<Browser['newContext']>>; page: Page }> {
	const statePath = requireAuthState();
	const context = await browser.newContext({
		baseURL: requireBaseURL(),
		storageState: statePath,
	});
	const page = await context.newPage();
	trackPage(page, trackedPages);
	await protectTransport(page);
	await page.goto(siteURL(`/wp-admin/admin.php?page=${pageSlug}`), { waitUntil: 'domcontentloaded' });
	if (await page.locator('#loginform').isVisible().catch(() => false)) {
		throw new Error('Configured administrator storage state opened the login form; refusing to treat login as administrator success.');
	}
	if (await page.getByText(/Sorry, you are not allowed to access this page/i).isVisible().catch(() => false)) {
		throw new Error('Configured storage state is authenticated but does not have the administrator capability required by the plugin screens.');
	}
	await expect(page.locator('#wpadminbar')).toBeVisible();
	await expect(page.locator('.cf7aiv-admin')).toBeVisible();
	return { context, page };
}

async function openPublicPage(browser: Browser, trackedPages: TrackedPage[], consent: PublicConsentFixture): Promise<{ context: Awaited<ReturnType<Browser['newContext']>>; page: Page }> {
	const context = await browser.newContext({ baseURL: requireBaseURL() });
	await context.addInitScript(({ google, wordpress }) => {
		const windowWithConsent = window as Window & {
			dataLayer?: unknown[];
			wp_get_consent_type?: () => string;
			wp_has_consent?: (category: string) => boolean;
		};
		windowWithConsent.dataLayer = [['consent', 'update', google]];
		const getConsentType = () => 'optin';
		const hasConsent = (category: string) => {
			if (category === 'statistics') return wordpress.statistics;
			if (category === 'marketing') return wordpress.marketing;
			return false;
		};
		// Keep the authoritative fixture stable if a consent manager loads later.
		Object.defineProperty(windowWithConsent, 'wp_get_consent_type', {
			configurable: false,
			enumerable: true,
			get: () => getConsentType,
			set: () => {},
		});
		Object.defineProperty(windowWithConsent, 'wp_has_consent', {
			configurable: false,
			enumerable: true,
			get: () => hasConsent,
			set: () => {},
		});
	}, consent);
	const page = await context.newPage();
	trackPage(page, trackedPages);
	await protectTransport(page);
	return { context, page };
}

async function gotoContact(page: Page): Promise<void> {
	const response = await page.goto(contactURL(), { waitUntil: 'domcontentloaded' });
	if (!configuredContactURL && response?.status() === 404) {
		test.skip(true, 'The default portability contact fixture is unavailable; set CF7AIV_CONTACT_URL to a public Contact Form 7 page.');
	}
	await expect(page.locator('.wpcf7 form').first()).toBeVisible();
}

for (const viewport of viewports) {
	test(`Setup has grouped steps at ${viewport.width}px`, async ({ browser, trackedPages }, testInfo) => {
		const { context, page } = await openAdminPage(browser, trackedPages, 'cf7aiv-setup');
		const nonGetRequests: string[] = [];
		page.on('request', (request) => {
			if (request.method() !== 'GET') {
				nonGetRequests.push(`${request.method()} ${safeRequestURL(request.url())}`);
			}
		});
		try {
			await page.setViewportSize(viewport);
			await expect(page.locator('.cf7aiv-setup-page')).toBeVisible();
			await expect(page.getByRole('list', { name: 'Setup progress' })).toBeVisible();
			await expect(page.locator('.cf7aiv-setup-step')).toHaveCount(5);
			await expect(page.getByLabel('Starter pack')).toBeVisible();
			await expectLabeledControlsAndNonces(page);
			await page.getByRole('button', { name: 'Continue to qualification' }).click();
			const businessDescription = page.locator('#cf7aiv-business-description');
			await expect(businessDescription).toBeVisible();
			await businessDescription.fill('Synthetic responsive QA business context.');
			const setupSteps = page.locator('.cf7aiv-setup-progress [data-setup-step]');
			const setupStepNames = await setupSteps.evaluateAll((items) => items.map((item) => item.getAttribute('data-setup-step') || ''));
			for (const [index, stepName] of setupStepNames.entries()) {
				await setupSteps.nth(index).click();
				await expect(page.locator(`.cf7aiv-setup-step[data-step="${stepName}"]`)).toBeVisible();
				await expect(page.locator('.cf7aiv-setup-progress [aria-current="step"]')).toHaveAttribute('data-setup-step', stepName);
				await expectNoOverflow(page, `Setup ${viewport.width}x${viewport.height} ${stepName}`);
			}
			expect(nonGetRequests).toEqual([]);
			await page.screenshot({ path: testInfo.outputPath(`setup-${viewport.width}.png`), fullPage: true });
		} finally {
			await context.close();
		}
	});

	test(`Integrations remains grouped at ${viewport.width}px`, async ({ browser, trackedPages }, testInfo) => {
		const { context, page } = await openAdminPage(browser, trackedPages, 'cf7aiv-integrations');
		const nonGetRequests: string[] = [];
		page.on('request', (request) => {
			if (request.method() !== 'GET') {
				nonGetRequests.push(`${request.method()} ${safeRequestURL(request.url())}`);
			}
		});
		try {
			await page.setViewportSize(viewport);
			await expect(page.locator('.cf7aiv-integrations-page')).toBeVisible();
			await expect(page.getByRole('navigation', { name: 'Integration settings' })).toBeVisible();
			await expect(page.getByRole('tab', { name: 'AI provider' })).toBeVisible();
			await expect(page.getByRole('button', { name: 'Save changes' }).first()).toBeVisible();
			await expect(page.locator('.cf7aiv-save-bar')).toHaveCount(2);
			await expect(page.locator('[data-provider-panel="true"]')).toHaveCount(2);
			await expectLabeledControlsAndNonces(page);
			const tabs = page.getByRole('tab');
			const tabNames = await tabs.evaluateAll((items) => items.map((item) => item.getAttribute('data-tab') || ''));
			for (const [index, tabName] of tabNames.entries()) {
				await tabs.nth(index).click();
				await expect(tabs.nth(index)).toHaveAttribute('aria-selected', 'true');
				await expect(page.locator(`.cf7aiv-integration-tab-panel[data-tab="${tabName}"]`)).toBeVisible();
				await expectNoOverflow(page, `Integrations ${viewport.width}x${viewport.height} ${tabName}`);
			}
			expect(nonGetRequests).toEqual([]);
			await page.screenshot({ path: testInfo.outputPath(`integrations-${viewport.width}.png`), fullPage: true });
		} finally {
			await context.close();
		}
	});
}

test('Setup progress changes steps without submitting a form', async ({ browser, trackedPages }) => {
	const { context, page } = await openAdminPage(browser, trackedPages, 'cf7aiv-setup');
	const nonGetRequests: string[] = [];
	page.on('request', (request) => {
		if (request.method() !== 'GET') {
			nonGetRequests.push(`${request.method()} ${safeRequestURL(request.url())}`);
		}
	});
	try {
		await page.getByRole('button', { name: 'Continue to qualification' }).click();
		await expect(page.getByRole('heading', { name: /2\. Define qualification/ })).toBeVisible();
		await expect(page.locator('.cf7aiv-setup-progress [aria-current="step"]')).toHaveAttribute('data-setup-step', 'qualification');
		await expect(page.locator('.cf7aiv-setup-progress [aria-current="step"]')).toContainText('Define qualification');
		await page.getByRole('button', { name: 'Back to context' }).click();
		await expect(page.getByRole('heading', { name: /1\. Choose context/ })).toBeVisible();
		expect(nonGetRequests).toEqual([]);
	} finally {
		await context.close();
	}
});

test('provider switch hides irrelevant fields and exposes reasoning controls', async ({ browser, trackedPages }) => {
	const { context, page } = await openAdminPage(browser, trackedPages, 'cf7aiv-integrations');
	try {
		const provider = page.getByRole('combobox', { name: 'Provider' });
		await provider.selectOption('openai');
		await expect(page.locator('[data-provider-panel="true"][data-provider="deepseek"]')).toBeHidden();
		await expect(page.locator('[data-provider-panel="true"][data-provider="openai"]')).toBeVisible();
		await expect(page.locator('.cf7aiv-reasoning-control[data-provider="openai"]')).toBeVisible();
		await expect(page.getByRole('combobox', { name: /OpenAI reasoning/i })).toBeVisible();

		await provider.selectOption('deepseek');
		await expect(page.locator('[data-provider-panel="true"][data-provider="openai"]')).toBeHidden();
		await expect(page.locator('[data-provider-panel="true"][data-provider="deepseek"]')).toBeVisible();
		await expect(page.locator('.cf7aiv-reasoning-control[data-provider="deepseek"]')).toBeVisible();
		await expect(page.getByRole('combobox', { name: /DeepSeek reasoning/i })).toBeVisible();
	} finally {
		await context.close();
	}
});

test('ordinary save is distinct from high-risk actions', async ({ browser, trackedPages }) => {
	const { context, page } = await openAdminPage(browser, trackedPages, 'cf7aiv-integrations');
	try {
		await expect(page.getByRole('button', { name: 'Save changes' }).first()).toBeVisible();
		await page.getByRole('tab', { name: 'Google' }).click();
		await expect(page.getByRole('heading', { name: 'High-risk actions' })).toBeVisible();
		await expect(page.getByRole('button', { name: 'Enable live automation' })).toBeVisible();
		await expect(page.getByRole('button', { name: 'Publish mapping' })).toBeVisible();
		await expect(page.getByRole('button', { name: 'Save changes' }).first()).toBeVisible();
		const saveFormIsHighRisk = await page.getByRole('button', { name: 'Save changes' }).first().evaluate((button) => button.closest('form')?.classList.contains('cf7aiv-high-risk') || false);
		expect(saveFormIsHighRisk).toBe(false);
	} finally {
		await context.close();
	}
});

test('dirty integration changes can be discarded without an admin POST', async ({ browser, trackedPages }, testInfo) => {
	const { context, page } = await openAdminPage(browser, trackedPages, 'cf7aiv-integrations');
	const adminPostRequests: string[] = [];
	page.on('request', (request) => {
		if (request.method() !== 'GET' && /\/wp-admin\/admin-post\.php/i.test(request.url())) {
			adminPostRequests.push(`${request.method()} ${safeRequestURL(request.url())}`);
		}
	});
	try {
		const provider = page.getByRole('combobox', { name: 'Provider' });
		const originalProvider = await provider.inputValue();
		const alternateProvider = originalProvider === 'openai' ? 'deepseek' : 'openai';
		await expect(page.getByRole('button', { name: 'Save changes' }).first()).toBeDisabled();
		await provider.selectOption(alternateProvider);
		await expect(page.getByRole('button', { name: 'Save changes' }).first()).toBeEnabled();
		await expect(page.locator('.cf7aiv-save-bar').first()).toHaveAttribute('data-dirty', 'true');
		await expect(page.locator('[data-save-status]').first()).toHaveText('Unsaved changes');

		page.once('dialog', (dialog) => dialog.accept());
		await page.getByRole('button', { name: 'Discard changes' }).first().click();
		await expect(provider).toHaveValue(originalProvider);
		await expect(page.getByRole('button', { name: 'Save changes' }).first()).toBeDisabled();
		await expect(page.locator('.cf7aiv-save-bar').first()).toHaveAttribute('data-dirty', 'false');
		await expect(page.locator('[data-save-status]').first()).toHaveText('Saved');
		expect(adminPostRequests).toEqual([]);
		await page.screenshot({ path: testInfo.outputPath('integrations-discarded.png'), fullPage: true });
	} finally {
		await context.close();
	}
});

test('logged-out admin requests redirect to WordPress login', async ({ page, trackedPages }) => {
	trackPage(page, trackedPages);
	await protectTransport(page);
	const response = await page.goto(siteURL('/wp-admin/admin.php?page=cf7aiv-setup'), { waitUntil: 'domcontentloaded' });
	expect(response).not.toBeNull();
	await expect(page).toHaveURL(/\/wp-login\.php(?:\?|$)/);
	await expect(page.getByLabel('Username or Email Address')).toBeVisible();
});

test('public contact form renders attribution fields without submission', async ({ page, trackedPages }, testInfo) => {
	trackPage(page, trackedPages);
	await protectTransport(page);
	await gotoContact(page);
	const form = page.locator('.wpcf7 form').first();
	await expect(form.locator('input[type="submit"], button[type="submit"]').first()).toBeVisible();
	await expect(form.locator('[name="cf7aiv_submission_id"]')).toHaveCount(1);
	await expect(form.locator('[name="cf7aiv_submission_id"]')).toHaveValue(/\S+/);
	await page.screenshot({ path: testInfo.outputPath('public-contact.png'), fullPage: true });
});

test('authoritative consent denied keeps public attribution read-only and empty', async ({ browser, trackedPages }) => {
	const { context, page } = await openPublicPage(browser, trackedPages, {
		google: {
			analytics_storage: 'granted',
			ad_storage: 'granted',
			ad_user_data: 'granted',
			ad_personalization: 'granted',
		},
		wordpress: { statistics: false, marketing: false },
	});
	try {
		const target = new URL(contactURL());
		target.searchParams.set('gclid', 'UXDENIEDCLICK123');
		target.searchParams.set('utm_source', 'ux-denied');
		const response = await page.goto(target.toString(), { waitUntil: 'domcontentloaded' });
		if (!configuredContactURL && response?.status() === 404) {
			test.skip(true, 'The default portability contact fixture is unavailable; set CF7AIV_CONTACT_URL to a public Contact Form 7 page.');
		}
		const form = page.locator('.wpcf7 form').first();
		await expect(form).toBeVisible();
		await expect.poll(() => form.locator('[name="cf7aiv_gclid"]').inputValue()).toBe('');
		await expect.poll(() => form.locator('[name="cf7aiv_utm_source"]').inputValue()).toBe('');
		await expect(form.locator('[name="cf7aiv_analytics_storage"]')).toHaveValue('');
		await expect(form.locator('[name="cf7aiv_ad_storage"]')).toHaveValue('');
		await expect(form.locator('[name="cf7aiv_ad_user_data"]')).toHaveValue('');
		await expect(form.locator('[name="cf7aiv_ad_personalization"]')).toHaveValue('');
		const mosaicConsent = form.locator('[name="mosaic_consent"]');
		if (await mosaicConsent.count()) {
			await expect(mosaicConsent).toHaveValue('not_granted');
		}
		const storedAttribution = await page.evaluate(() => ({
			localStorage: window.localStorage.getItem('cf7aiv_attribution'),
			cookie: document.cookie,
		}));
		expect(storedAttribution.localStorage).toBeNull();
		expect(storedAttribution.cookie).not.toContain('cf7aiv_attribution=');
	} finally {
		await context.close();
	}
});

test('authoritative consent granted exposes public attribution without submission', async ({ browser, trackedPages }) => {
	const { context, page } = await openPublicPage(browser, trackedPages, {
		google: {
			analytics_storage: 'denied',
			ad_storage: 'denied',
			ad_user_data: 'denied',
			ad_personalization: 'denied',
		},
		wordpress: { statistics: true, marketing: true },
	});
	try {
		const target = new URL(contactURL());
		target.searchParams.set('gclid', 'UXGRANTEDCLICK123');
		target.searchParams.set('utm_source', 'ux-granted');
		const response = await page.goto(target.toString(), { waitUntil: 'domcontentloaded' });
		if (!configuredContactURL && response?.status() === 404) {
			test.skip(true, 'The default portability contact fixture is unavailable; set CF7AIV_CONTACT_URL to a public Contact Form 7 page.');
		}
		const form = page.locator('.wpcf7 form').first();
		await expect(form).toBeVisible();
		await expect.poll(() => form.locator('[name="cf7aiv_gclid"]').inputValue()).toBe('UXGRANTEDCLICK123');
		await expect.poll(() => form.locator('[name="cf7aiv_utm_source"]').inputValue()).toBe('ux-granted');
		await expect(form.locator('[name="cf7aiv_analytics_storage"]')).toHaveValue('granted');
		await expect(form.locator('[name="cf7aiv_ad_storage"]')).toHaveValue('granted');
		await expect(form.locator('[name="cf7aiv_ad_user_data"]')).toHaveValue('granted');
		await expect(form.locator('[name="cf7aiv_ad_personalization"]')).toHaveValue('granted');
		const storedAttribution = await page.evaluate(() => {
			const raw = window.localStorage.getItem('cf7aiv_attribution');
			return raw ? JSON.parse(raw) : null;
		});
		expect(storedAttribution?.gclid).toBe('UXGRANTEDCLICK123');
		expect(storedAttribution?.utm_source).toBe('ux-granted');
	} finally {
		await context.close();
	}
});
