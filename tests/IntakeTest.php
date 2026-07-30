<?php
/**
 * Standalone intake tests. Run: php tests/IntakeTest.php
 */

declare(strict_types=1);

function cf7aiv_intake_assert(bool $condition, string $message): void {
	if (! $condition) {
		throw new RuntimeException($message);
	}
}

$GLOBALS['cf7aiv_test_actions'] = [];
$GLOBALS['cf7aiv_test_filters'] = [];

function add_action(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): void {
	$GLOBALS['cf7aiv_test_actions'][$hook][] = [$callback, $priority, $acceptedArgs];
}
function add_filter(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): void {
	$GLOBALS['cf7aiv_test_filters'][$hook][] = [$callback, $priority, $acceptedArgs];
}
function plugin_dir_url(string $file): string { return 'https://example.test/wp-content/plugins/cf7-ai-enquiry-validator/'; }
function as_enqueue_async_action(): int { return 1; }

define('ABSPATH', __DIR__ . '/');
define('AUTH_KEY', 'intake-test-auth-key');
define('SECURE_AUTH_KEY', 'intake-test-secure-auth-key');

require_once dirname(__DIR__) . '/cf7-ai-enquiry-validator.php';

cf7aiv_intake_assert(class_exists(CF7AIV\Intake\IdentifierValidator::class), 'Intake identifier validator must exist.');

use CF7AIV\Domain\ClientSignals;
use CF7AIV\Intake\Attribution;
use CF7AIV\Intake\AttributionDiagnostics;
use CF7AIV\Intake\ConsentResolver;
use CF7AIV\Intake\FieldNames;
use CF7AIV\Intake\IdentifierValidator;
use CF7AIV\Intake\SourceNormalizer;
use CF7AIV\Intake\SubmissionFingerprint;

cf7aiv_intake_assert(IdentifierValidator::clickId('gclid', 'abc_DEF-123.456') === 'abc_DEF-123.456', 'Valid Google click IDs must be accepted.');
cf7aiv_intake_assert(IdentifierValidator::clickId('gclid', '<script>') === null, 'Unsafe Google click IDs must be rejected.');
cf7aiv_intake_assert(IdentifierValidator::clickId('msclkid', '0123456789abcdef0123456789ABCDEF') !== null, 'Valid Microsoft click IDs must be accepted.');
cf7aiv_intake_assert(IdentifierValidator::clickId('msclkid', 'not-a-msclkid') === null, 'Malformed Microsoft click IDs must be rejected.');

$resolved = Attribution::merge(
	['gclid' => 'fresh_GOOGLE-123', 'zc_gad' => 'alias_SHOULD-NOT-WIN', 'msclkid' => 'bad'],
	['gclid' => 'persisted_GOOGLE-456', 'msclkid' => '0123456789abcdef0123456789abcdef']
);
cf7aiv_intake_assert($resolved['gclid'] === 'fresh_GOOGLE-123', 'A fresh canonical URL value must beat aliases and persisted values.');
cf7aiv_intake_assert($resolved['msclkid'] === '0123456789abcdef0123456789abcdef', 'An invalid fresh value must not erase a valid persisted value.');
$alias = Attribution::merge(['zc_gad' => 'alias_GOOGLE-123'], ['gclid' => 'persisted_GOOGLE-456']);
cf7aiv_intake_assert($alias['gclid'] === 'alias_GOOGLE-123', 'A fresh zc_gad alias must beat a persisted gclid.');

$fingerprintA = SubmissionFingerprint::make(42, ['b' => [' two ', 'one'], 'a' => ' Value '], 'submission-123');
$fingerprintB = SubmissionFingerprint::make(42, ['a' => ' Value ', 'b' => [' two ', 'one']], 'submission-123');
cf7aiv_intake_assert($fingerprintA === $fingerprintB, 'Fingerprint must be stable when posted field order changes.');
cf7aiv_intake_assert(strlen($fingerprintA) === 64, 'Fingerprint must be a SHA-256 value.');
cf7aiv_intake_assert($fingerprintA !== SubmissionFingerprint::make(43, ['a' => ' Value ', 'b' => [' two ', 'one']], 'submission-123'), 'Form identity must participate in the fingerprint.');

$consent = (new ConsentResolver())->resolve(new ClientSignals([
	'wordpress' => ['ad_storage' => 'granted', 'analytics_storage' => 'granted'],
	'complianz' => ['ad_storage' => 'denied'],
	'google' => ['ad_storage' => 'granted', 'analytics_storage' => 'granted', 'ad_user_data' => 'granted', 'ad_personalization' => 'granted'],
]));
cf7aiv_intake_assert($consent->states['ad_storage'] === 'denied', 'Consent conflicts must resolve to the most restrictive state.');
cf7aiv_intake_assert($consent->states['analytics_storage'] === 'granted', 'Unopposed grants must remain granted.');
cf7aiv_intake_assert(! $consent->permitsAdvertising(), 'A denied advertising state must block advertising permission.');

$names = FieldNames::all();
cf7aiv_intake_assert(in_array('cf7aiv_gclid', $names, true), 'Canonical hidden fields must include gclid.');
cf7aiv_intake_assert(in_array('cf7aiv_ad_user_data', $names, true), 'Canonical hidden fields must include Google Consent Mode v2 values.');
cf7aiv_intake_assert(count($names) === count(array_unique($names)), 'Canonical hidden field names must be unique.');
$html = '<input type="hidden" name="cf7aiv_gclid" value="existing"><p>Form</p>';
$injected = FieldNames::injectMissing($html);
cf7aiv_intake_assert(substr_count($injected, 'name="cf7aiv_gclid"') === 1, 'Existing attribution fields must not be duplicated.');
cf7aiv_intake_assert(str_contains($injected, 'name="cf7aiv_msclkid"'), 'Missing canonical attribution fields must be injected.');

$diagnostics = AttributionDiagnostics::inspect([
	'gclid' => 'bad!',
	'ga_client_id' => '',
], ['ad_storage' => 'unknown', 'ad_user_data' => 'unknown']);
cf7aiv_intake_assert(! $diagnostics['ready'], 'Incomplete attribution must not be marked ready.');
cf7aiv_intake_assert(in_array('missing_valid_click_identifier', $diagnostics['issues'], true), 'Diagnostics must explain missing valid click identifiers.');
cf7aiv_intake_assert(in_array('missing_ga_identity', $diagnostics['issues'], true), 'Diagnostics must explain missing GA identity.');
cf7aiv_intake_assert(in_array('missing_consent', $diagnostics['issues'], true), 'Diagnostics must explain missing consent.');
$ready = AttributionDiagnostics::inspect([
	'gclid' => 'valid_CLICK-123',
	'ga_client_id' => '123456789.987654321',
	'ga_session_id' => '1234567890',
], ['ad_storage' => 'granted', 'ad_user_data' => 'granted']);
cf7aiv_intake_assert($ready['ready'] && $ready['issues'] === [], 'Complete valid attribution must be ready.');

$normalized = SourceNormalizer::fromArray('cfdb7', '91', [
	'form_id' => '12',
	'form_value' => ['your-name' => 'Person', 'cf7aiv_gclid' => 'valid_CLICK-123'],
	'form_date' => '2026-07-22 12:00:00',
], true);
cf7aiv_intake_assert($normalized['source'] === 'cfdb7' && $normalized['reference'] === '91', 'Source records must expose a canonical source and reference.');
cf7aiv_intake_assert($normalized['form_id'] === 12, 'Source records must normalize form IDs to integers.');
cf7aiv_intake_assert($normalized['fields']['your-name'] === 'Person', 'On-demand source retrieval must preserve source-owned content.');
cf7aiv_intake_assert($normalized['is_historical'] && $normalized['is_calibration'], 'Historical source records must always be calibration-only.');

foreach (['wp_enqueue_scripts', 'template_redirect', 'wpcf7_submit', 'wpcf7_after_flamingo'] as $hook) {
	cf7aiv_intake_assert(isset($GLOBALS['cf7aiv_test_actions'][$hook]), "Production intake hook {$hook} must be registered.");
}
$submitBoundary = $GLOBALS['cf7aiv_test_actions']['wpcf7_submit'][0][0];
$didThrow = false;
try { $submitBoundary(null, ['status' => 'mail_sent']); } catch (Throwable) { $didThrow = true; }
cf7aiv_intake_assert(! $didThrow, 'CF7 capture callbacks must never throw through the CF7 hook boundary.');
foreach (['wpcf7_form_elements', 'cf7aiv_submission_sources', 'cf7aiv_consent_resolvers'] as $hook) {
	cf7aiv_intake_assert(isset($GLOBALS['cf7aiv_test_filters'][$hook]), "Production intake filter {$hook} must be registered.");
}

$sourceCallbacks = $GLOBALS['cf7aiv_test_filters']['cf7aiv_submission_sources'];
$sourceRegistration = $sourceCallbacks[array_key_last($sourceCallbacks)][0];
$sources = $sourceRegistration([]);
cf7aiv_intake_assert(isset($sources['flamingo'], $sources['cfdb7']), 'Built-in Flamingo and CFDB7 sources must be registered by key.');
$resolverCallbacks = $GLOBALS['cf7aiv_test_filters']['cf7aiv_consent_resolvers'];
$resolverRegistration = $resolverCallbacks[array_key_last($resolverCallbacks)][0];
$resolvers = $resolverRegistration([]);
cf7aiv_intake_assert(isset($resolvers['most_restrictive']), 'The built-in consent resolver must be registered by key.');

echo "PASS IntakeTest\n";
