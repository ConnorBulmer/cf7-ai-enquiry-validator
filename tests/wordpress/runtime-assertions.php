<?php

$assert = static function (bool $condition, string $message): void {
	if (! $condition) { throw new RuntimeException($message); }
};

$phase = (string) ($args[0] ?? 'before');
$mode = (string) getenv('CF7AIV_SOURCE_MODE');
$expectedWordPress = (string) getenv('CF7AIV_WORDPRESS_VERSION');
$wordPressVersion = (string) get_bloginfo('version');
$assert($expectedWordPress !== '' && $wordPressVersion === $expectedWordPress, 'WordPress version must match the pinned matrix cell.');
$assert(defined('CF7AIV\VERSION'), 'Plugin version is unavailable.');
$assert(CF7AIV\Infrastructure\Migration::VERSION === '10', 'Schema version must remain 10.');
$assert((string) get_option('cf7aiv_schema_version') === '10', 'Installed schema version must be 10.');

if ($mode === 'flamingo') {
	$assert((new CF7AIV\Sources\FlamingoSource())->available(), 'Flamingo source must be available.');
} elseif ($mode === 'cfdb7') {
	$source = new CF7AIV\Sources\CFDB7Source();
	$assert($source->available(), 'CFDB7 source must be available.');
	global $wpdb;
	$fixture = (array) get_option('cf7aiv_portability_fixture', []);
	$formId = (int) (($fixture['form_ids'][0] ?? 0));
	$token = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
	$captured = current_time('mysql', true);
	$localDate = get_date_from_gmt($captured);
	$table = $wpdb->prefix . 'db7_forms';
	$wpdb->insert($table, ['form_post_id' => $formId, 'form_value' => serialize(['cf7aiv_submission_id' => $token]), 'form_date' => $localDate]);
	$expected = (string) $wpdb->insert_id;
	for ($index = 0; $index < 101; $index++) {
		$wpdb->insert($table, ['form_post_id' => $formId, 'form_value' => serialize(['message' => 'unrelated-' . $index]), 'form_date' => $localDate]);
	}
	$assert($source->findReferenceBySubmissionId($token, $formId, $captured) === $expected, 'CFDB7 exact lookup must survive 101 newer rows.');
} else {
	throw new RuntimeException('Unknown persistent source mode.');
}

if ($phase === 'after') {
	global $wpdb;
	$count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cf7aiv_items");
	$assert($count >= 2, 'AJAX success and mail failure must both create classification items.');
}

echo 'PASS runtime ' . $phase . ' wp=' . $wordPressVersion . ' php=' . PHP_VERSION . ' source=' . $mode . "\n";
