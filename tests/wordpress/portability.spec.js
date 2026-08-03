'use strict';

const { test, expect } = require('@playwright/test');

const baseURL = process.env.CF7AIV_BASE_URL;
const fixtureURL = baseURL + '/portability-fixture/';

async function waitForCf7(page, eventName, action) {
	const event = page.evaluate((name) => new Promise((resolve) => {
		document.addEventListener(name, (value) => resolve(value.detail && value.detail.status || name), { once: true });
	}), eventName);
	await action();
	return event;
}

async function complete(form, suffix) {
	await form.locator('[name="your-name"]').fill('Test Person ' + suffix);
	await form.locator('[name="your-email"]').fill('robot@example.test');
	await form.locator('[name="your-message"]').fill('Controlled portability enquiry ' + suffix);
}

test('real CF7 lifecycle remains form and attempt local', async ({ browser }) => {
	const context = await browser.newContext();
	const page = await context.newPage();
	await page.goto(fixtureURL);
	const forms = page.locator('.wpcf7 form');
	await expect(forms).toHaveCount(2);
	const firstToken = await forms.nth(0).locator('[name="cf7aiv_submission_id"]').inputValue();
	const secondToken = await forms.nth(1).locator('[name="cf7aiv_submission_id"]').inputValue();
	expect(firstToken).not.toBe('');
	expect(secondToken).not.toBe(firstToken);

	await complete(forms.nth(0), 'success');
	await waitForCf7(page, 'wpcf7mailsent', () => forms.nth(0).locator('[type="submit"]').click());
	expect(await forms.nth(0).locator('[name="cf7aiv_submission_id"]').inputValue()).not.toBe(firstToken);
	expect(await forms.nth(1).locator('[name="cf7aiv_submission_id"]').inputValue()).toBe(secondToken);

	await complete(forms.nth(1), 'failure');
	await waitForCf7(page, 'wpcf7mailfailed', () => forms.nth(1).locator('[type="submit"]').click());
	expect(await forms.nth(1).locator('[name="cf7aiv_submission_id"]').inputValue()).not.toBe(secondToken);

	const invalidToken = await forms.nth(0).locator('[name="cf7aiv_submission_id"]').inputValue();
	await forms.nth(0).locator('[name="your-email"]').fill('');
	await waitForCf7(page, 'wpcf7invalid', () => forms.nth(0).locator('[type="submit"]').click());
	expect(await forms.nth(0).locator('[name="cf7aiv_submission_id"]').inputValue()).toBe(invalidToken);

	const beforeNavigation = await forms.nth(0).locator('[name="cf7aiv_submission_id"]').inputValue();
	await page.reload();
	expect(await page.locator('.wpcf7 form').nth(0).locator('[name="cf7aiv_submission_id"]').inputValue()).not.toBe(beforeNavigation);
	await context.close();
});

test('configured GA property selects one exact cookie pair', async ({ browser }) => {
	const context = await browser.newContext();
	await context.addInitScript(() => {
		window.dataLayer = [['consent', 'update', { analytics_storage: 'granted', ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied' }]];
	});
	await context.addCookies([
		{ name: '_ga', value: 'GA1.1.111111111.1111111111', url: baseURL },
		{ name: '_ga_OTHER', value: 'GS1.1.s2222222222.o1', url: baseURL },
		{ name: '_ga_TEST123', value: 'GS1.1.s3333333333.o1', url: baseURL },
	]);
	const page = await context.newPage();
	await page.goto(fixtureURL);
	const form = page.locator('.wpcf7 form').first();
	await expect(form.locator('[name="cf7aiv_ga_client_id"]')).toHaveValue('111111111.1111111111');
	await expect(form.locator('[name="cf7aiv_ga_session_id"]')).toHaveValue('3333333333');
	await context.close();
});
