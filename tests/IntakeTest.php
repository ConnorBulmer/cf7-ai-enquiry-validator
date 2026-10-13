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
$GLOBALS['cf7aiv_test_options'] = [];
$GLOBALS['cf7aiv_inline_script'] = '';

function add_action(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): void {
	$GLOBALS['cf7aiv_test_actions'][$hook][] = [$callback, $priority, $acceptedArgs];
}
function add_filter(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): void {
	$GLOBALS['cf7aiv_test_filters'][$hook][] = [$callback, $priority, $acceptedArgs];
}
function plugin_dir_url(string $file): string { return 'https://example.test/wp-content/plugins/cf7-ai-enquiry-validator/'; }
function as_enqueue_async_action(): int { return 1; }
function get_option(string $key, mixed $default = false): mixed { return $GLOBALS['cf7aiv_test_options'][$key] ?? $default; }
function wp_enqueue_script(string $handle, string $source, array $dependencies = [], string|bool|null $version = false, array|bool $arguments = []): void {}
function wp_add_inline_script(string $handle, string $data, string $position = 'after'): bool { $GLOBALS['cf7aiv_inline_script'] = $data; return true; }
function wp_json_encode(mixed $value, int $flags = 0, int $depth = 512): string|false { return json_encode($value, $flags, $depth); }

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

// Resolvers registered on cf7aiv_consent_resolvers count as more consent sources.
$fixedResolver = static fn (array $states, string $key = 'fixed'): CF7AIV\Contracts\ConsentResolverInterface => new class ($states, $key) implements CF7AIV\Contracts\ConsentResolverInterface {
	public array $seen = [];
	public function __construct(private array $states, private string $name) {}
	public function key(): string { return $this->name; }
	public function resolve(ClientSignals $signals): CF7AIV\Domain\ConsentSnapshot { $this->seen = $signals->signals; return new CF7AIV\Domain\ConsentSnapshot($this->states, $this->name, gmdate('c')); }
};
$throwingResolver = new class implements CF7AIV\Contracts\ConsentResolverInterface {
	public function key(): string { return 'broken'; }
	public function resolve(ClientSignals $signals): CF7AIV\Domain\ConsentSnapshot { throw new RuntimeException('consent platform unreachable'); }
};
$noSignals = new ClientSignals([]);
$googleGranted = new ClientSignals(['google' => ['ad_storage' => 'granted', 'analytics_storage' => 'granted', 'ad_user_data' => 'granted', 'ad_personalization' => 'granted']]);
$cmp = $fixedResolver(['ad_storage' => 'granted', 'analytics_storage' => 'granted', 'ad_user_data' => 'granted', 'ad_personalization' => 'unknown'], 'cookie-platform');
$withCmp = (new ConsentResolver())->resolveWith($noSignals, ['cookie_platform' => $cmp]);
cf7aiv_intake_assert($withCmp->states['ad_storage'] === 'granted' && $withCmp->states['analytics_storage'] === 'granted', 'A grant from a registered resolver must count where no built-in source had an answer.');
cf7aiv_intake_assert($withCmp->states['ad_personalization'] === 'unknown', 'A key the resolver did not decide must stay unknown.');
cf7aiv_intake_assert($withCmp->source === 'resolver:cookie_platform', 'The resolver must be named in the consent source for diagnostics.');
$marketingDenied = (new ConsentResolver())->resolveWith($googleGranted, ['cmp' => $fixedResolver(['ad_storage' => 'denied', 'analytics_storage' => 'unknown', 'ad_user_data' => 'unknown', 'ad_personalization' => 'unknown'])]);
cf7aiv_intake_assert($marketingDenied->states['ad_storage'] === 'denied', 'A denial from a registered resolver must override a built-in grant.');
cf7aiv_intake_assert($marketingDenied->states['analytics_storage'] === 'granted', 'A resolver that only knows marketing must not block analytics.');
$cannotOverride = (new ConsentResolver())->resolveWith(new ClientSignals(['wordpress' => ['ad_storage' => 'denied']]), ['cmp' => $fixedResolver(['ad_storage' => 'granted'])]);
cf7aiv_intake_assert($cannotOverride->states['ad_storage'] === 'denied', 'A resolver grant must never override a denial from another source.');
$broken = (new ConsentResolver())->resolveWith($googleGranted, ['broken' => $throwingResolver]);
cf7aiv_intake_assert($broken->states['ad_storage'] === 'unknown' && $broken->states['analytics_storage'] === 'unknown', 'A resolver that throws must hold consent at unknown, not let built-in grants through.');
$ignored = (new ConsentResolver())->resolveWith($googleGranted, ['most_restrictive' => new ConsentResolver(), 'junk' => 'not a resolver', 7 => null]);
cf7aiv_intake_assert($ignored->states === (new ConsentResolver())->resolve($googleGranted)->states && $ignored->source === 'google', 'The built-in resolver and non-resolvers in the filter must not change the result.');
$seenBy = $fixedResolver(['ad_storage' => 'granted']);
(new ConsentResolver())->resolveWith($googleGranted, ['seen' => $seenBy]);
cf7aiv_intake_assert($seenBy->seen === $googleGranted->signals, 'Registered resolvers must receive the captured consent signals.');
$twins = (new ConsentResolver())->resolveWith($noSignals, ['Same' => $fixedResolver(['ad_storage' => 'granted']), 'same' => $fixedResolver(['ad_storage' => 'denied'])]);
cf7aiv_intake_assert($twins->states['ad_storage'] === 'denied', 'Two resolvers whose names sanitise alike must both be counted.');

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
$microsoftOnly = AttributionDiagnostics::inspect(['msclkid' => '0123456789abcdef0123456789abcdef'], ['ad_storage' => 'granted', 'ad_user_data' => 'granted'], false);
cf7aiv_intake_assert($microsoftOnly['ready'] && $microsoftOnly['issues'] === [], 'A site with no GA destination must not need GA identity to be attribution-ready.');
cf7aiv_intake_assert(! AttributionDiagnostics::gaIdentityNeeded(['destinations' => ['microsoft_uet' => ['enabled' => true]]]), 'A Microsoft-only site does not need GA identity.');
cf7aiv_intake_assert(AttributionDiagnostics::gaIdentityNeeded(['destinations' => ['microsoft_uet' => ['enabled' => true], 'server_gtm' => ['enabled' => true]]]), 'Any enabled GA route needs GA identity.');
cf7aiv_intake_assert(AttributionDiagnostics::gaIdentityNeeded([]), 'Before anything is enabled, GA identity is still reported.');
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

$GLOBALS['cf7aiv_test_options']['cf7aiv_settings'] = [
	'attribution' => ['retention_days' => 90],
	'destinations' => ['ga4' => ['measurement_id' => 'g-abc123', 'api_secret' => 'PRIVATE-GA-SECRET']],
];
CF7AIV\Intake\IntakeHooks::enqueue();
cf7aiv_intake_assert(str_contains($GLOBALS['cf7aiv_inline_script'], '"measurementId":"G-ABC123"'), 'The configured GA4 Measurement ID must be public attribution configuration.');
cf7aiv_intake_assert(! str_contains($GLOBALS['cf7aiv_inline_script'], 'PRIVATE-GA-SECRET'), 'No GA4 API secret may be localized.');

$GLOBALS['cf7aiv_test_options']['cf7aiv_settings']['destinations'] = [
	'ga4' => ['measurement_id' => ''],
	'server_gtm' => ['measurement_id' => 'g-sgtm123'],
];
CF7AIV\Intake\IntakeHooks::enqueue();
cf7aiv_intake_assert(str_contains($GLOBALS['cf7aiv_inline_script'], '"measurementId":"G-SGTM123"'), 'A server-GTM-only route must expose its configured Measurement ID for browser identity capture.');

$GLOBALS['cf7aiv_test_options']['cf7aiv_settings']['destinations'] = [
	'ga4' => ['measurement_id' => 'G-DIRECT123'],
	'server_gtm' => ['measurement_id' => 'G-SGTM123'],
];
CF7AIV\Intake\IntakeHooks::enqueue();
cf7aiv_intake_assert(str_contains($GLOBALS['cf7aiv_inline_script'], '"measurementId":""'), 'Different configured Google route properties must fail closed instead of capturing identity for the wrong destination.');
$GLOBALS['cf7aiv_test_options']['cf7aiv_settings']['destinations'] = [
	'ga4' => ['enabled' => false, 'measurement_id' => 'G-OLD123'],
	'server_gtm' => ['enabled' => true, 'measurement_id' => 'G-SGTM123'],
];
CF7AIV\Intake\IntakeHooks::enqueue();
cf7aiv_intake_assert(str_contains($GLOBALS['cf7aiv_inline_script'], '"measurementId":"G-SGTM123"'), 'A disabled destination\'s leftover Measurement ID must not blank the enabled one.');

$GLOBALS['cf7aiv_test_options']['cf7aiv_settings']['destinations'] = ['ga4' => ['measurement_id' => 'UA-invalid']];
CF7AIV\Intake\IntakeHooks::enqueue();
cf7aiv_intake_assert(str_contains($GLOBALS['cf7aiv_inline_script'], '"measurementId":""'), 'An invalid GA4 Measurement ID must disable browser identity capture.');

echo "PASS IntakeTest\n";
