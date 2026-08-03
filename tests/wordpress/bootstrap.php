<?php

if (! class_exists('WPCF7_ContactForm')) { throw new RuntimeException('Contact Form 7 is not active.'); }

$previous = get_option('cf7aiv_portability_fixture', []);
foreach ((array) ($previous['form_ids'] ?? []) as $formId) { wp_delete_post((int) $formId, true); }
if ((int) ($previous['page_id'] ?? 0) > 0) { wp_delete_post((int) $previous['page_id'], true); }

$createForm = static function (string $title, string $mode): int {
	$form = WPCF7_ContactForm::get_template(['title' => $title, 'locale' => 'en_GB']);
	$properties = $form->get_properties();
	$properties['form'] = implode("\n", [
		'[text* your-name autocomplete:name]',
		'[email* your-email autocomplete:email]',
		'[textarea* your-message]',
		'[hidden test-mail-mode "' . $mode . '"]',
		'[submit "Send"]',
	]);
	$properties['mail']['recipient'] = 'admin@example.test';
	$properties['mail']['sender'] = 'WordPress <wordpress@example.test>';
	$properties['mail']['subject'] = 'Portability fixture';
	$properties['mail']['body'] = '[your-message]';
	$properties['additional_settings'] = '';
	$form->set_properties($properties);
	$result = $form->save();
	if (! $result || $form->id() < 1) { throw new RuntimeException('Could not create CF7 fixture form.'); }
	return (int) $form->id();
};

$successId = $createForm('Portability success', 'success');
$failureId = $createForm('Portability failure', 'fail');
$pageId = wp_insert_post([
	'post_type' => 'page',
	'post_status' => 'publish',
	'post_title' => 'Portability fixture',
	'post_name' => 'portability-fixture',
	'post_content' => '[contact-form-7 id="' . $successId . '" title="Portability success"]' . "\n" . '[contact-form-7 id="' . $failureId . '" title="Portability failure"]',
], true);
if (is_wp_error($pageId)) { throw new RuntimeException('Could not create fixture page.'); }

$settings = CF7AIV\Infrastructure\Settings::defaults();
$settings['destinations']['ga4']['measurement_id'] = 'G-TEST123';
update_option(CF7AIV\Infrastructure\Settings::OPTION, $settings, false);
update_option('cf7aiv_portability_fixture', ['page_id' => (int) $pageId, 'form_ids' => [$successId, $failureId]], false);
flush_rewrite_rules(false);
echo wp_json_encode(['page_id' => (int) $pageId, 'form_ids' => [$successId, $failureId]], JSON_THROW_ON_ERROR) . "\n";
