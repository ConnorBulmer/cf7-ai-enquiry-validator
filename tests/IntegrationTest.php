<?php
/**
 * Standalone integration/pilot/CLI tests. Run: php tests/IntegrationTest.php
 */

declare(strict_types=1);

function cf7aiv_integration_assert(bool $condition, string $message): void {
	if (! $condition) { throw new RuntimeException($message); }
}

$GLOBALS['cf7aiv_integration_actions'] = [];
$GLOBALS['cf7aiv_integration_filters'] = [];

function add_action(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): void { $GLOBALS['cf7aiv_integration_actions'][$hook][] = [$callback, $priority, $acceptedArgs]; }
function add_filter(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): void { $GLOBALS['cf7aiv_integration_filters'][$hook][] = [$callback, $priority, $acceptedArgs]; }
function apply_filters(string $hook, mixed $value, mixed ...$arguments): mixed {
	foreach ($GLOBALS['cf7aiv_integration_filters'][$hook] ?? [] as [$callback]) { $value = $callback($value, ...$arguments); }
	return $value;
}
function plugin_dir_url(string $file): string { return 'https://example.test/wp-content/plugins/cf7-ai-enquiry-validator/'; }
function get_option(string $name, mixed $default = false): mixed { return $GLOBALS['cf7aiv_integration_options'][$name] ?? $default; }
function as_enqueue_async_action(string $hook = '', array $arguments = [], string $group = '', bool $unique = false): int {
	$GLOBALS['cf7aiv_integration_queued'][] = compact('hook', 'arguments', 'group', 'unique');
	return count($GLOBALS['cf7aiv_integration_queued']);
}

final class WP_CLI {
	/** @var array<string, mixed> */ public static array $commands = [];
	/** @var list<string> */ public static array $messages = [];
	public static function add_command(string $name, mixed $callable): void { self::$commands[$name] = $callable; }
	public static function log(string $message): void { self::$messages[] = $message; }
	public static function success(string $message): void { self::$messages[] = 'SUCCESS: ' . $message; }
	public static function warning(string $message): void { self::$messages[] = 'WARNING: ' . $message; }
	public static function error(string $message): never { throw new RuntimeException($message); }
}

define('WP_CLI', true);
define('ABSPATH', __DIR__ . '/');
define('AUTH_KEY', 'integration-test-auth-key');
define('SECURE_AUTH_KEY', 'integration-test-secure-auth-key');

require_once dirname(__DIR__) . '/cf7-ai-enquiry-validator.php';

cf7aiv_integration_assert(class_exists(CF7AIV\Integration\PilotDefaults::class), 'Care-home pilot defaults helper must exist.');

$policyDraft = CF7AIV\Integration\PilotDefaults::policyDraft();
cf7aiv_integration_assert($policyDraft['status'] === 'draft', 'Pilot policy helper must create a draft only.');
cf7aiv_integration_assert($policyDraft['definition']['schema'] === 'cf7aiv-policy/v2' && ($policyDraft['definition']['pack']['key'] ?? '') === 'care_supported_living', 'Pilot policy helper must use the care and supported-living v2 pack.');

$titleCases = [
	'Rose Cottage Care Enquiry Form' => 'residential_care',
	'Brochure Request – Main Site' => 'care_information',
	'Current Jobs Application' => 'excluded',
	'General Enquiry' => 'general_legitimate',
];
foreach ($titleCases as $title => $tier) {
	$profile = CF7AIV\Integration\PilotDefaults::profileForTitle($title);
	cf7aiv_integration_assert($profile !== null && $profile['recommended_tier'] === $tier && $profile['status'] === 'draft', "Pilot form profile must safely recognize {$title}.");
}
cf7aiv_integration_assert(CF7AIV\Integration\PilotDefaults::profileForTitle('Newsletter Signup') === null, 'Unrecognized forms must not receive an inferred pilot mapping.');

$profiles = CF7AIV\Integration\PilotDefaults::profilesForForms([
	['id' => 10, 'title' => 'Care Enquiry'], ['id' => 11, 'title' => 'Newsletter Signup'], ['id' => 12, 'title' => 'Brochure Request'],
]);
cf7aiv_integration_assert(array_column($profiles, 'form_id') === [10, 12], 'Pilot form helper must return suggestions only for recognized titles.');
foreach ($profiles as $profile) {
	cf7aiv_integration_assert($profile['status'] === 'draft' && $profile['publish'] === false, 'Pilot form suggestions must never auto-publish.');
}

$mappingDrafts = CF7AIV\Integration\PilotDefaults::deliveryMappingDrafts();
cf7aiv_integration_assert(array_column($mappingDrafts, 'destination_key') === ['ga4', 'microsoft_uet', 'webhook'], 'Pilot helper must provide draft mapping examples for every destination.');
foreach ($mappingDrafts as $mapping) {
	cf7aiv_integration_assert($mapping['status'] === 'draft' && $mapping['enabled'] === false, 'Pilot delivery mappings must remain disabled drafts.');
}
cf7aiv_integration_assert($mappingDrafts[0]['event_name'] === 'qualify_lead', 'The recommended high-value GA4 pilot event must be qualify_lead.');
cf7aiv_integration_assert($mappingDrafts[2]['event_name'] === 'qualified_lead', 'The webhook pilot event must clearly identify a qualified lead.');
$pilotSettings = CF7AIV\Integration\PilotDefaults::safeSettings();
cf7aiv_integration_assert($pilotSettings['automation']['mode'] === 'shadow', 'Pilot automation must remain in shadow mode.');
foreach ($pilotSettings['destinations'] as $destination) {
	cf7aiv_integration_assert($destination['dry_run'] === true && $destination['enabled'] === false, 'Pilot destinations must stay disabled and dry-run.');
}

cf7aiv_integration_assert(isset(WP_CLI::$commands['cf7aiv']), 'The wp cf7aiv command namespace must be registered when WP-CLI is available.');
$command = WP_CLI::$commands['cf7aiv'];
cf7aiv_integration_assert($command instanceof CF7AIV\Cli\Commands, 'WP-CLI registration must use the plugin command handler.');
foreach (['status', 'migrate', 'health', 'calibrate', 'backfill', 'process_queue', 'dry_run', 'pilot'] as $method) {
	cf7aiv_integration_assert(method_exists($command, $method), "wp cf7aiv must provide {$method}.");
}
$GLOBALS['cf7aiv_integration_queued'] = [];
$cliCalibrationSource = new class implements CF7AIV\Contracts\SubmissionSourceInterface {
	public function key(): string { return 'flamingo'; }
	public function get(string $reference): ?array { return null; }
	public function iterate(array $arguments = []): iterable { return []; }
	public function available(): bool { return true; }
};
add_filter('cf7aiv_submission_sources', static function (array $sources) use ($cliCalibrationSource): array { $sources['flamingo'] = $cliCalibrationSource; return $sources; }, 99);
$command->calibrate([], ['source' => 'flamingo', 'maximum' => 17, 'date-from' => '2026-07-22', 'date-to' => '2026-07-22', 'explicit-opt-in' => true]);
cf7aiv_integration_assert(count($GLOBALS['cf7aiv_integration_queued']) === 1, 'CLI calibration must enqueue one initial batch instead of iterating source records synchronously.');
cf7aiv_integration_assert($GLOBALS['cf7aiv_integration_queued'][0]['hook'] === 'cf7aiv_backfill_batch', 'CLI calibration must schedule the canonical backfill action.');
cf7aiv_integration_assert($GLOBALS['cf7aiv_integration_queued'][0]['arguments'][0] === 'flamingo' && $GLOBALS['cf7aiv_integration_queued'][0]['arguments'][2] === 17 && ($GLOBALS['cf7aiv_integration_queued'][0]['arguments'][1]['quota'] ?? 0) === 17 && ($GLOBALS['cf7aiv_integration_queued'][0]['arguments'][1]['date_from'] ?? '') === '2026-07-22', 'CLI calibration must preserve source, criteria, immutable quota, and bounded worker quota in the queued action.');
cf7aiv_integration_assert(str_contains(end(WP_CLI::$messages), 'scheduled'), 'CLI calibration must report scheduling rather than synchronous completion.');
cf7aiv_integration_assert(CF7AIV\Integration\OperationalSafety::isSafe($pilotSettings), 'Shadow plus destination dry-runs must pass the operational safety guard.');
$safetyReport = CF7AIV\Integration\OperationalSafety::report($pilotSettings);
cf7aiv_integration_assert(str_starts_with($safetyReport['test_identifiers']['event_id'], 'TEST-NOT-FOR-UPLOAD-'), 'Dry-run health must use an explicitly non-uploadable synthetic identifier.');
$unsafeSettings = $pilotSettings; $unsafeSettings['automation']['mode'] = 'live';
cf7aiv_integration_assert(! CF7AIV\Integration\OperationalSafety::isSafe($unsafeSettings), 'Live automation must fail the default operational safety guard.');
$unsafeServerGtmSettings = $pilotSettings; $unsafeServerGtmSettings['destinations']['server_gtm']['dry_run'] = false;
cf7aiv_integration_assert(! CF7AIV\Integration\OperationalSafety::isSafe($unsafeServerGtmSettings), 'A non-dry-run server-side GTM destination must fail the operational safety guard.');

$GLOBALS['cf7aiv_integration_options'][CF7AIV\Infrastructure\Settings::OPTION] = CF7AIV\Infrastructure\Settings::withDefaults([
	'providers' => [
		'deepseek_reasoning_effort' => 'deep',
		'openai_reasoning_effort' => 'standard',
	],
]);
$registeredProviders = CF7AIV\Classification\ClassificationHooks::registerProviders([]);
$deepReasoningProperty = new ReflectionProperty($registeredProviders['deepseek'], 'reasoningEffort');
$openAiReasoningProperty = new ReflectionProperty($registeredProviders['openai'], 'reasoningEffort');
$deepReasoningProperty->setAccessible(true);
$openAiReasoningProperty->setAccessible(true);
cf7aiv_integration_assert($deepReasoningProperty->getValue($registeredProviders['deepseek']) === 'deep' && $openAiReasoningProperty->getValue($registeredProviders['openai']) === 'standard', 'Provider registration must pass hydrated reasoning settings to both provider adapters.');
$capabilitySanitized = CF7AIV\Admin\SettingsSanitizer::sanitize([
	'providers' => [
		'deepseek_model' => 'deepseek-v4-flash',
		'deepseek_reasoning_effort' => 'invalid',
		'openai_model' => 'gpt-4o',
		'openai_reasoning_effort' => 'deep',
	],
]);
cf7aiv_integration_assert(
	$capabilitySanitized['providers']['deepseek_reasoning_effort'] === 'off'
	&& $capabilitySanitized['providers']['openai_reasoning_effort'] === 'unsupported',
	'Reasoning sanitization must fail closed to each provider and model capability default.'
);
$capableUnsupported = CF7AIV\Admin\SettingsSanitizer::sanitize([
	'providers' => [
		'openai_model' => 'gpt-5.4-mini',
		'openai_reasoning_effort' => 'unsupported',
	],
]);
$unsupportedModelValues = CF7AIV\Admin\SettingsSanitizer::sanitize([
	'providers' => [
		'openai_model' => 'gpt-4o',
		'openai_reasoning_effort' => 'off',
	],
]);
$invalidCapableReasoning = CF7AIV\Admin\SettingsSanitizer::sanitize([
	'providers' => [
		'openai_model' => 'gpt-5.4-mini',
		'openai_reasoning_effort' => 'invalid',
	],
]);
cf7aiv_integration_assert(
	$capableUnsupported['providers']['openai_reasoning_effort'] === 'off'
	&& $unsupportedModelValues['providers']['openai_reasoning_effort'] === 'unsupported'
	&& $invalidCapableReasoning['providers']['openai_reasoning_effort'] === 'unsupported',
	'OpenAI reasoning sanitization must normalize capable unsupported input to off and retain fail-closed unsupported values only for unsupported or invalid input.'
);

$GLOBALS['wpdb'] = new class {
	public string $prefix = 'wp_';
	public function prepare(string $sql, mixed ...$arguments): string { return str_replace('%s', "'wp_db7_forms'", $sql); }
	public function get_var(string $sql): string { return 'wp_db7_forms'; }
};
$GLOBALS['cf7aiv_integration_options']['active_plugins'] = [];
cf7aiv_integration_assert(! (new CF7AIV\Sources\CFDB7Source())->available(), 'An orphaned CFDB7 table must not make an inactive integration appear available.');
$GLOBALS['cf7aiv_integration_options']['active_plugins'] = ['contact-form-cfdb7/contact-form-cfdb-7.php'];
cf7aiv_integration_assert((new CF7AIV\Sources\CFDB7Source())->available(), 'An active CFDB7 plugin with its table must be reported available.');
cf7aiv_integration_assert(method_exists(CF7AIV\Sources\FlamingoSource::class, 'count') && method_exists(CF7AIV\Sources\CFDB7Source::class, 'count'), 'Built-in sources must expose efficient count helpers for health checks.');
$availableSource = new class implements CF7AIV\Contracts\SubmissionSourceInterface {
	public function key(): string { return 'available'; }
	public function get(string $reference): ?array { return null; }
	public function iterate(array $arguments = []): iterable { throw new RuntimeException('Health must not enumerate a countable source.'); }
	public function available(): bool { return true; }
	public function count(): int { return 6456; }
};
$unavailableSource = new class implements CF7AIV\Contracts\SubmissionSourceInterface {
	public function key(): string { return 'unavailable'; }
	public function get(string $reference): ?array { return null; }
	public function iterate(array $arguments = []): iterable { throw new RuntimeException('Health must not enumerate an unavailable source.'); }
	public function available(): bool { return false; }
	public function count(): ?int { return null; }
};
$sourceHealth = (new CF7AIV\Admin\HealthService(static fn (): array => ['flamingo' => $availableSource, 'cfdb7' => $unavailableSource]))->inspect();
cf7aiv_integration_assert($sourceHealth['sources']['flamingo'] === ['available' => true, 'count' => 6456], 'Health must use the source count helper without a 1000-record cap.');
cf7aiv_integration_assert($sourceHealth['sources']['cfdb7'] === ['available' => false, 'count' => null], 'Health must report an unavailable optional source without error or enumeration.');

$pluginRoot = dirname(__DIR__);
foreach (['readme.txt', 'README.md', 'CHANGELOG.md', 'LICENSE', 'docs/PRIVACY-AND-AI.md', 'docs/EXTENDING.md', 'docs/SETUP.md'] as $documentation) {
	cf7aiv_integration_assert(is_file($pluginRoot . '/' . $documentation), "Distribution documentation {$documentation} must exist.");
	cf7aiv_integration_assert(filesize($pluginRoot . '/' . $documentation) > 100, "Distribution documentation {$documentation} must not be a placeholder.");
}
$readme = file_get_contents($pluginRoot . '/README.md');
foreach (['DeepSeek', 'OpenAI', 'GA4', 'Microsoft', 'webhook', 'consent', 'retention', 'calibration', 'shadow', 'CF7AIV_', 'v1 boundaries'] as $topic) {
	cf7aiv_integration_assert(stripos((string) $readme, $topic) !== false, "README must document {$topic}.");
}
$extensionDocs = file_get_contents($pluginRoot . '/docs/EXTENDING.md');
foreach (['SubmissionSourceInterface', 'AIProviderInterface', 'DestinationInterface', 'ConsentResolverInterface', 'cf7aiv_after_classification'] as $contract) {
	cf7aiv_integration_assert(str_contains((string) $extensionDocs, $contract), "Extension guide must document {$contract}.");
}
$privacyDocs = file_get_contents($pluginRoot . '/docs/PRIVACY-AND-AI.md');
foreach (['name', 'email', 'phone', 'click ID', 'attribution', 'consent', 'files', 'whitelist'] as $privacyTopic) {
	cf7aiv_integration_assert(stripos((string) $privacyDocs, $privacyTopic) !== false, "Privacy guide must document {$privacyTopic} handling.");
}
$attributionJs = file_get_contents($pluginRoot . '/assets/js/attribution.js');
cf7aiv_integration_assert(str_contains((string) $attributionJs, "document.addEventListener('wp_listen_for_consent_change', populateAll)"), 'Attribution must re-evaluate through the standard WordPress consent-change event.');
cf7aiv_integration_assert(preg_match('/function\s+collect\s*\([^)]*\)\s*\{\s*var\s+consent\s*=\s*consentState\(\);\s*if\s*\(!consent\.statistics\s*&&\s*!consent\.marketing\)/s', (string) $attributionJs) === 1, 'Site-wide attribution collection must be consent-gated before storage is read or written.');

echo "PASS IntegrationTest\n";
