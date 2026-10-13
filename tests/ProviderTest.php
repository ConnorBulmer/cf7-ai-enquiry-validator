<?php
/**
 * Provider reasoning settings and capability tests. Run: php tests/ProviderTest.php
 */

declare(strict_types=1);

function provider_assert(bool $condition, string $message): void {
	if (! $condition) { throw new RuntimeException("FAIL: {$message}"); }
}

define('ABSPATH', __DIR__ . '/');
define('AUTH_KEY', 'provider-test-auth-key');
define('SECURE_AUTH_KEY', 'provider-test-secure-auth-key');

function add_action(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): void {}
function add_filter(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): void {}
function apply_filters(string $hook, mixed $value, mixed ...$arguments): mixed { return $value; }
function plugin_dir_url(string $file): string { return 'https://example.test/wp-content/plugins/cf7-ai-enquiry-validator/'; }
function get_option(string $name, mixed $default = false): mixed { return $GLOBALS['provider_test_options'][$name] ?? $default; }
// Stub Action Scheduler so the main plugin file does not attempt to load it.
function as_enqueue_async_action(string $hook = '', array $arguments = [], string $group = '', bool $unique = false): int { return 1; }

// Minimal WP_CLI stub so the autoloader can load without it.
if (! defined('WP_CLI')) { define('WP_CLI', true); }

final class WP_CLI {
	/** @var array<string,mixed> */ public static array $commands = [];
	/** @var list<string> */ public static array $messages = [];
	public static function add_command(string $name, mixed $callable): void { self::$commands[$name] = $callable; }
	public static function log(string $message): void { self::$messages[] = $message; }
	public static function success(string $message): void { self::$messages[] = 'SUCCESS: ' . $message; }
	public static function warning(string $message): void { self::$messages[] = 'WARNING: ' . $message; }
	public static function error(string $message): never { throw new RuntimeException($message); }
}

require_once dirname(__DIR__) . '/cf7-ai-enquiry-validator.php';

// ─── Defaults ────────────────────────────────────────────────────────────────

$defaults = CF7AIV\Infrastructure\Settings::defaults();
provider_assert(isset($defaults['providers']['deepseek_reasoning_effort']), 'Settings::defaults() must include deepseek_reasoning_effort.');
provider_assert($defaults['providers']['deepseek_reasoning_effort'] === 'off', 'DeepSeek reasoning defaults off.');
provider_assert(isset($defaults['providers']['openai_reasoning_effort']), 'Settings::defaults() must include openai_reasoning_effort.');
provider_assert($defaults['providers']['openai_reasoning_effort'] === 'unsupported', 'OpenAI unsupported reasoning defaults honestly.');

// ─── Sanitizer ───────────────────────────────────────────────────────────────

$sanitized = CF7AIV\Admin\SettingsSanitizer::sanitize([
	'providers' => [
		'deepseek_reasoning_effort' => 'deep',
		'openai_reasoning_effort' => 'invalid',
	],
]);
provider_assert($sanitized['providers']['deepseek_reasoning_effort'] === 'deep', 'DeepSeek reasoning effort is retained.');
provider_assert($sanitized['providers']['openai_reasoning_effort'] === 'unsupported', 'Invalid OpenAI reasoning effort fails closed.');

$sanitizedStandard = CF7AIV\Admin\SettingsSanitizer::sanitize([
	'providers' => ['deepseek_reasoning_effort' => 'standard', 'openai_reasoning_effort' => 'standard'],
]);
provider_assert($sanitizedStandard['providers']['deepseek_reasoning_effort'] === 'standard', 'DeepSeek standard reasoning effort is accepted.');
provider_assert($sanitizedStandard['providers']['openai_reasoning_effort'] === 'standard', 'OpenAI standard reasoning effort is accepted.');

$sanitizedOff = CF7AIV\Admin\SettingsSanitizer::sanitize([
	'providers' => ['deepseek_reasoning_effort' => 'off', 'openai_reasoning_effort' => 'off'],
]);
provider_assert($sanitizedOff['providers']['deepseek_reasoning_effort'] === 'off', 'DeepSeek off reasoning effort is accepted.');
provider_assert($sanitizedOff['providers']['openai_reasoning_effort'] === 'off', 'OpenAI off reasoning effort is accepted.');

$sanitizedDeep = CF7AIV\Admin\SettingsSanitizer::sanitize([
	'providers' => ['deepseek_reasoning_effort' => 'deep', 'openai_reasoning_effort' => 'deep'],
]);
provider_assert($sanitizedDeep['providers']['deepseek_reasoning_effort'] === 'deep', 'DeepSeek deep reasoning effort is accepted.');
provider_assert($sanitizedDeep['providers']['openai_reasoning_effort'] === 'deep', 'OpenAI deep reasoning effort is accepted.');

$sanitizedInvalidDs = CF7AIV\Admin\SettingsSanitizer::sanitize([
	'providers' => ['deepseek_reasoning_effort' => 'turbo'],
]);
provider_assert($sanitizedInvalidDs['providers']['deepseek_reasoning_effort'] === 'off', 'Invalid DeepSeek reasoning effort fails closed to off.');

// ─── DeepSeekProvider capability helpers ─────────────────────────────────────

$dsOptions = CF7AIV\Providers\DeepSeekProvider::reasoningOptions('deepseek-v4-flash');
provider_assert(is_array($dsOptions), 'DeepSeekProvider::reasoningOptions() must return an array.');
provider_assert(in_array('off', $dsOptions, true), 'DeepSeek reasoning options must include off.');
provider_assert(in_array('standard', $dsOptions, true), 'DeepSeek reasoning options must include standard.');
provider_assert(in_array('deep', $dsOptions, true), 'DeepSeek reasoning options must include deep.');

$dsDesc = CF7AIV\Providers\DeepSeekProvider::reasoningDescription('deepseek-v4-flash', 'off');
provider_assert(is_string($dsDesc) && $dsDesc !== '', 'DeepSeek off reasoning description must be a non-empty string.');
$dsDescDeep = CF7AIV\Providers\DeepSeekProvider::reasoningDescription('deepseek-v4-flash', 'deep');
provider_assert(is_string($dsDescDeep) && $dsDescDeep !== '', 'DeepSeek deep reasoning description must be a non-empty string.');

// ─── OpenAIProvider capability helpers ───────────────────────────────────────

$oaiOptionsSupported = CF7AIV\Providers\OpenAIProvider::reasoningOptions('o4-mini');
provider_assert(is_array($oaiOptionsSupported), 'OpenAIProvider::reasoningOptions() must return an array for o4-mini.');
provider_assert(in_array('standard', $oaiOptionsSupported, true), 'OpenAI reasoning options must include standard for o4-mini.');
provider_assert(in_array('deep', $oaiOptionsSupported, true), 'OpenAI reasoning options must include deep for o4-mini.');
provider_assert(! in_array('off', $oaiOptionsSupported, true), 'OpenAI o4-mini must not claim documented reasoning.effort=none support.');
foreach (['gpt-5.4', 'gpt-5.4-mini', 'gpt-5.4-nano'] as $noneModel) {
	$noneOptions = CF7AIV\Providers\OpenAIProvider::reasoningOptions($noneModel);
	provider_assert(in_array('off', $noneOptions, true), "OpenAI {$noneModel} must expose the documented off reasoning control.");
}
foreach (['o4-mini-2025-04-16', 'gpt-5.4-2026-03-05', 'gpt-5.4-mini-2026-03-17', 'gpt-5.4-nano-2026-03-17'] as $snapshotModel) {
	$snapshotOptions = CF7AIV\Providers\OpenAIProvider::reasoningOptions($snapshotModel);
	provider_assert(in_array('standard', $snapshotOptions, true), "OpenAI reasoning options must support documented snapshot {$snapshotModel}.");
}

$oaiOptionsUnsupported = CF7AIV\Providers\OpenAIProvider::reasoningOptions('gpt-4o');
provider_assert(is_array($oaiOptionsUnsupported), 'OpenAIProvider::reasoningOptions() must return an array for gpt-4o.');
provider_assert(in_array('unsupported', $oaiOptionsUnsupported, true), 'OpenAI reasoning options must include unsupported for gpt-4o.');
provider_assert(! in_array('standard', $oaiOptionsUnsupported, true), 'OpenAI gpt-4o must not include standard in reasoning options.');
$oaiAliasOptions = CF7AIV\Providers\OpenAIProvider::reasoningOptions('gpt-5-chat-latest');
provider_assert(in_array('unsupported', $oaiAliasOptions, true), 'OpenAI gpt-5-chat-latest must render reasoning as unsupported.');
provider_assert(! in_array('standard', $oaiAliasOptions, true), 'OpenAI gpt-5-chat-latest must not expose standard reasoning options.');
$oaiUnknownOptions = CF7AIV\Providers\OpenAIProvider::reasoningOptions('model-that-is-not-allow-listed');
provider_assert($oaiUnknownOptions === ['unsupported'], 'OpenAI unknown models must fail closed without reasoning controls.');

$oaiDesc = CF7AIV\Providers\OpenAIProvider::reasoningDescription('gpt-5.4-mini', 'unsupported');
provider_assert(is_string($oaiDesc) && $oaiDesc !== '', 'OpenAI unsupported reasoning description must be a non-empty string.');

// ─── DeepSeekProvider payload behavior ───────────────────────────────────────

// Verify DeepSeekProvider constructor accepts optional reasoning_effort parameter.
$mockTransport = new class implements CF7AIV\Infrastructure\HttpTransportInterface {
	public ?array $lastPayload = null;
	public function post(string $url, array $arguments): CF7AIV\Infrastructure\HttpResponse {
		$this->lastPayload = json_decode($arguments['body'], true);
		$resultJson = json_encode([
			'choices' => [['message' => ['content' => '{"tier_id":"spam","outcome_id":"spam","ads_eligible":false,"flags":[],"confidence":0.9,"evidence":[],"exclusion_codes":[],"ambiguity_flags":[],"reasoning_summary":""}']]],
			'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20, 'completion_tokens_details' => ['reasoning_tokens' => 0]],
		]);
		return new CF7AIV\Infrastructure\HttpResponse(200, $resultJson);
	}
};

$dsOff = new CF7AIV\Providers\DeepSeekProvider($mockTransport, 'key', 'https://api.deepseek.com/chat/completions', 'deepseek-v4-flash', 30, 'off');
provider_assert($dsOff instanceof CF7AIV\Providers\DeepSeekProvider, 'DeepSeekProvider must accept reasoning_effort=off in constructor.');

$dsStandard = new CF7AIV\Providers\DeepSeekProvider($mockTransport, 'key', 'https://api.deepseek.com/chat/completions', 'deepseek-v4-flash', 30, 'standard');
provider_assert($dsStandard instanceof CF7AIV\Providers\DeepSeekProvider, 'DeepSeekProvider must accept reasoning_effort=standard in constructor.');

$dsDeepInstance = new CF7AIV\Providers\DeepSeekProvider($mockTransport, 'key', 'https://api.deepseek.com/chat/completions', 'deepseek-v4-flash', 30, 'deep');
provider_assert($dsDeepInstance instanceof CF7AIV\Providers\DeepSeekProvider, 'DeepSeekProvider must accept reasoning_effort=deep in constructor.');

// Verify backward compat: no reasoning_effort param defaults to 'off'.
$dsDefault = new CF7AIV\Providers\DeepSeekProvider($mockTransport, 'key');
provider_assert($dsDefault instanceof CF7AIV\Providers\DeepSeekProvider, 'DeepSeekProvider must be constructible without reasoning_effort param (backward compat).');

// ─── OpenAIProvider constructor backward compat ───────────────────────────────

$oaiDefault = new CF7AIV\Providers\OpenAIProvider($mockTransport, 'key');
provider_assert($oaiDefault instanceof CF7AIV\Providers\OpenAIProvider, 'OpenAIProvider must be constructible without reasoning_effort param (backward compat).');

$oaiWithEffort = new CF7AIV\Providers\OpenAIProvider($mockTransport, 'key', 'https://api.openai.com/v1/responses', 'gpt-5.4-mini', 30, 'unsupported');
provider_assert($oaiWithEffort instanceof CF7AIV\Providers\OpenAIProvider, 'OpenAIProvider must accept reasoning_effort=unsupported in constructor.');

// ─── DeepSeekProvider send() payload assertions ───────────────────────────────

// We need a real ClassificationRequest to invoke classify(). Build a minimal one.
$mockRequest = new CF7AIV\Domain\ClassificationRequest(
	itemId: '1',
	policyVersion: '1',
	redactedText: 'Test',
	context: ['message' => 'hello'],
	systemPrompt: 'You are a classifier.',
	allowedTierIds: ['spam'],
	allowedOutcomeIds: ['spam'],
	allowedEvidence: [],
	policySchema: CF7AIV\Classification\PolicyValidator::SCHEMA_V1,
	allowedExclusionCodes: [],
	allowedAmbiguityFlags: [],
);

final class ProviderSuggestionCaptureTransport implements CF7AIV\Infrastructure\HttpTransportInterface {
	public ?array $lastPayload = null;

	public function __construct(private readonly string $provider) {}

	public function post(string $url, array $arguments): CF7AIV\Infrastructure\HttpResponse {
		$this->lastPayload = json_decode((string) ($arguments['body'] ?? ''), true, 64, JSON_THROW_ON_ERROR);
		$suggestions = json_encode(['suggestions' => [[
			'type' => 'service',
			'value' => 'Website design',
			'source_refs' => ['pack:general'],
		]]], JSON_THROW_ON_ERROR);
		$body = $this->provider === 'openai'
			? json_encode(['output_text' => $suggestions], JSON_THROW_ON_ERROR)
			: json_encode(['choices' => [['message' => ['content' => $suggestions]]],], JSON_THROW_ON_ERROR);
		return new CF7AIV\Infrastructure\HttpResponse(200, $body);
	}
}

$suggestionRequest = new CF7AIV\Domain\PolicySuggestionRequest('general', [
	'pages' => [['id' => 1, 'title' => 'Services', 'url' => 'https://example.test/services', 'text' => 'Public service information. private@example.test token=TOP-SECRET']],
	'forms' => [],
]);

// Budgets stay bounded, but reasoning is given its own allowance instead of
// competing with the answer. Sharing one flat budget let thinking consume it
// and returned a JSON object cut in half, which surfaced as a malformed
// response several layers away.
foreach ([
	['effort' => 'off', 'thinking' => 'disabled', 'reasoning_effort' => null, 'max_tokens' => 1500],
	['effort' => 'standard', 'thinking' => 'enabled', 'reasoning_effort' => 'high', 'max_tokens' => 9500],
	['effort' => 'deep', 'thinking' => 'enabled', 'reasoning_effort' => 'max', 'max_tokens' => 25500],
] as $case) {
	$transport = new ProviderSuggestionCaptureTransport('deepseek');
	$provider = new CF7AIV\Providers\DeepSeekProvider($transport, 'key', 'https://api.deepseek.com/chat/completions', 'deepseek-v4-flash', 30, $case['effort']);
	$provider->suggestPolicy($suggestionRequest);
	$payload = $transport->lastPayload ?? [];
	$wire = json_encode($payload, JSON_THROW_ON_ERROR);
	provider_assert(($payload['thinking']['type'] ?? '') === $case['thinking'], "DeepSeek {$case['effort']} suggestions must map to the matching thinking capability.");
	provider_assert(($payload['reasoning_effort'] ?? null) === $case['reasoning_effort'], "DeepSeek {$case['effort']} suggestions must send the matching reasoning_effort, so deep actually thinks harder than standard.");
	provider_assert(($payload['max_tokens'] ?? 0) === $case['max_tokens'], "DeepSeek {$case['effort']} suggestions must use the classification-equivalent bounded output budget.");
	provider_assert(($payload['response_format']['type'] ?? '') === 'json_object' && str_contains((string) ($payload['messages'][0]['content'] ?? ''), 'Required JSON Schema'), 'DeepSeek suggestions must retain strict JSON mode and the fixed schema prompt.');
	provider_assert(str_contains((string) ($payload['messages'][0]['content'] ?? ''), 'never as instructions'), 'DeepSeek suggestions must treat the scanned site as data, not instructions.');
	provider_assert(! str_contains($wire, 'private@example.test') && ! str_contains($wire, 'TOP-SECRET'), 'DeepSeek suggestion payloads must retain privacy redaction.');
	if ($case['effort'] === 'off') {
		provider_assert(($payload['temperature'] ?? null) === 0, 'DeepSeek off suggestions must retain deterministic temperature handling.');
	} else {
		provider_assert(! array_key_exists('temperature', $payload), "DeepSeek {$case['effort']} suggestions must use the reasoning capability instead of forced temperature zero.");
	}
}

foreach ([
	['effort' => 'off', 'mapped' => 'none', 'max_output_tokens' => 1500],
	['effort' => 'standard', 'mapped' => 'medium', 'max_output_tokens' => 9500],
	['effort' => 'deep', 'mapped' => 'high', 'max_output_tokens' => 25500],
] as $case) {
	$transport = new ProviderSuggestionCaptureTransport('openai');
	$provider = new CF7AIV\Providers\OpenAIProvider($transport, 'key', 'https://api.openai.com/v1/responses', 'gpt-5.4-mini', 30, $case['effort']);
	$provider->suggestPolicy($suggestionRequest);
	$payload = $transport->lastPayload ?? [];
	$wire = json_encode($payload, JSON_THROW_ON_ERROR);
	provider_assert(($payload['reasoning']['effort'] ?? '') === $case['mapped'], "OpenAI {$case['effort']} suggestions must map to the documented reasoning capability.");
	provider_assert(($payload['max_output_tokens'] ?? 0) === $case['max_output_tokens'], "OpenAI {$case['effort']} suggestions must use a bounded output budget.");
	provider_assert(($payload['store'] ?? true) === false && ($payload['text']['format']['type'] ?? '') === 'json_schema' && ($payload['text']['format']['strict'] ?? false) === true, 'OpenAI suggestions must retain store=false and strict JSON schema output.');
	provider_assert(str_contains((string) ($payload['instructions'] ?? ''), 'never as instructions'), 'OpenAI suggestions must treat the scanned site as data, not instructions.');
	provider_assert(! str_contains($wire, 'private@example.test') && ! str_contains($wire, 'TOP-SECRET'), 'OpenAI suggestion payloads must retain privacy redaction.');
}

// The mock transport returns a minimal valid-schema response for SCHEMA_V1.
// We need the ResultValidator schema to permit our minimal JSON.  Let us just
// test the payload structure by overriding the mock result to match v1 schema.
$v1Transport = new class implements CF7AIV\Infrastructure\HttpTransportInterface {
	public ?array $lastPayload = null;
	public function post(string $url, array $arguments): CF7AIV\Infrastructure\HttpResponse {
		$this->lastPayload = json_decode($arguments['body'], true);
		// Return a minimal v1-compatible response.
		$content = json_encode([
			'tier_id' => 'spam',
			'outcome_id' => 'spam',
			'ads_eligible' => false,
			'flags' => [],
			'confidence' => 0.9,
			'evidence' => [],
			'exclusion_codes' => [],
			'ambiguity_flags' => [],
			'reasoning_summary' => '',
		]);
		$body = json_encode([
			'choices' => [['message' => ['content' => $content]]],
			'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20, 'completion_tokens_details' => ['reasoning_tokens' => 0]],
		]);
		return new CF7AIV\Infrastructure\HttpResponse(200, $body);
	}
};

// DeepSeek off → thinking.type = disabled
$dsOffInstance = new CF7AIV\Providers\DeepSeekProvider($v1Transport, 'key', 'https://api.deepseek.com/chat/completions', 'deepseek-v4-flash', 30, 'off');
try {
	$dsOffInstance->classify($mockRequest);
} catch (Throwable) {}
provider_assert(($v1Transport->lastPayload['thinking']['type'] ?? '') === 'disabled', 'DeepSeek off must send thinking.type=disabled.');
provider_assert(($v1Transport->lastPayload['max_tokens'] ?? 0) === 1800, 'DeepSeek off must send the classification answer allowance with no reasoning added.');
provider_assert(! array_key_exists('reasoning_effort', $v1Transport->lastPayload ?? []) && ($v1Transport->lastPayload['temperature'] ?? null) === 0, 'DeepSeek off must send no effort and keep temperature zero.');

// DeepSeek standard → thinking.type = enabled
$v1TransportStd = new class implements CF7AIV\Infrastructure\HttpTransportInterface {
	public ?array $lastPayload = null;
	public function post(string $url, array $arguments): CF7AIV\Infrastructure\HttpResponse {
		$this->lastPayload = json_decode($arguments['body'], true);
		$content = json_encode(['tier_id' => 'spam', 'outcome_id' => 'spam', 'ads_eligible' => false, 'flags' => [], 'confidence' => 0.9, 'evidence' => [], 'exclusion_codes' => [], 'ambiguity_flags' => [], 'reasoning_summary' => '']);
		$body = json_encode(['choices' => [['message' => ['content' => $content]]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20, 'completion_tokens_details' => ['reasoning_tokens' => 5]]]);
		return new CF7AIV\Infrastructure\HttpResponse(200, $body);
	}
};
$dsStdInstance = new CF7AIV\Providers\DeepSeekProvider($v1TransportStd, 'key', 'https://api.deepseek.com/chat/completions', 'deepseek-v4-flash', 30, 'standard');
try { $dsStdInstance->classify($mockRequest); } catch (Throwable) {}
provider_assert(($v1TransportStd->lastPayload['thinking']['type'] ?? '') === 'enabled', 'DeepSeek standard must send thinking.type=enabled.');
provider_assert(($v1TransportStd->lastPayload['max_tokens'] ?? 0) === 9800, 'DeepSeek standard must send the classification answer allowance plus its reasoning allowance.');
provider_assert(($v1TransportStd->lastPayload['reasoning_effort'] ?? '') === 'high', 'DeepSeek standard must ask for high effort explicitly.');
provider_assert(! array_key_exists('temperature', $v1TransportStd->lastPayload ?? []), 'DeepSeek ignores temperature while thinking, so it must not be sent.');

// DeepSeek deep → thinking.type = enabled, answer allowance plus deep reasoning
$v1TransportDeep = new class implements CF7AIV\Infrastructure\HttpTransportInterface {
	public ?array $lastPayload = null;
	public function post(string $url, array $arguments): CF7AIV\Infrastructure\HttpResponse {
		$this->lastPayload = json_decode($arguments['body'], true);
		$content = json_encode(['tier_id' => 'spam', 'outcome_id' => 'spam', 'ads_eligible' => false, 'flags' => [], 'confidence' => 0.9, 'evidence' => [], 'exclusion_codes' => [], 'ambiguity_flags' => [], 'reasoning_summary' => '']);
		$body = json_encode(['choices' => [['message' => ['content' => $content]]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20, 'completion_tokens_details' => ['reasoning_tokens' => 10]]]);
		return new CF7AIV\Infrastructure\HttpResponse(200, $body);
	}
};
$dsDeepInstanceP = new CF7AIV\Providers\DeepSeekProvider($v1TransportDeep, 'key', 'https://api.deepseek.com/chat/completions', 'deepseek-v4-flash', 30, 'deep');
try { $dsDeepInstanceP->classify($mockRequest); } catch (Throwable) {}
provider_assert(($v1TransportDeep->lastPayload['thinking']['type'] ?? '') === 'enabled', 'DeepSeek deep must send thinking.type=enabled.');
provider_assert(($v1TransportDeep->lastPayload['max_tokens'] ?? 0) === 25800, 'DeepSeek deep must send the classification answer allowance plus its deep reasoning allowance.');
provider_assert(($v1TransportDeep->lastPayload['reasoning_effort'] ?? '') === 'max', 'DeepSeek deep must ask for maximum effort, not the same default effort as standard.');

// ─── OpenAI send() payload assertions ────────────────────────────────────────

// OpenAI unsupported model alias → no reasoning parameter added
$oaiTransport = new class implements CF7AIV\Infrastructure\HttpTransportInterface {
	public ?array $lastPayload = null;
	public function post(string $url, array $arguments): CF7AIV\Infrastructure\HttpResponse {
		$this->lastPayload = json_decode($arguments['body'], true);
		$content = json_encode(['tier_id' => 'spam', 'outcome_id' => 'spam', 'ads_eligible' => false, 'flags' => [], 'confidence' => 0.9, 'evidence' => [], 'exclusion_codes' => [], 'ambiguity_flags' => [], 'reasoning_summary' => '']);
		$body = json_encode(['output_text' => $content, 'usage' => ['input_tokens' => 10, 'output_tokens' => 20, 'output_tokens_details' => ['reasoning_tokens' => 0]]]);
		return new CF7AIV\Infrastructure\HttpResponse(200, $body);
	}
};
$oaiUnsupportedInstance = new CF7AIV\Providers\OpenAIProvider($oaiTransport, 'key', 'https://api.openai.com/v1/responses', 'gpt-5-chat-latest', 30, 'standard');
try { $oaiUnsupportedInstance->classify($mockRequest); } catch (Throwable) {}
provider_assert(! isset($oaiTransport->lastPayload['reasoning']), 'OpenAI unsupported model alias must not send reasoning parameter.');
provider_assert(($oaiTransport->lastPayload['store'] ?? true) === false, 'OpenAI must retain store=false regardless of reasoning.');

$oaiSupportedTransport = new class implements CF7AIV\Infrastructure\HttpTransportInterface {
	public ?array $lastPayload = null;
	public function post(string $url, array $arguments): CF7AIV\Infrastructure\HttpResponse {
		$this->lastPayload = json_decode($arguments['body'], true);
		$content = json_encode(['tier_id' => 'spam', 'outcome_id' => 'spam', 'ads_eligible' => false, 'flags' => [], 'confidence' => 0.9, 'evidence' => [], 'exclusion_codes' => [], 'ambiguity_flags' => [], 'reasoning_summary' => '']);
		return new CF7AIV\Infrastructure\HttpResponse(200, json_encode(['output_text' => $content, 'usage' => ['input_tokens' => 10, 'output_tokens' => 20, 'output_tokens_details' => ['reasoning_tokens' => 5]]]) ?: '');
	}
};
$oaiSupportedInstance = new CF7AIV\Providers\OpenAIProvider($oaiSupportedTransport, 'key', 'https://api.openai.com/v1/responses', 'o4-mini', 30, 'standard');
try { $oaiSupportedInstance->classify($mockRequest); } catch (Throwable) {}
provider_assert(($oaiSupportedTransport->lastPayload['reasoning']['effort'] ?? '') === 'medium', 'OpenAI standard reasoning must send the documented medium reasoning effort for supported models.');
provider_assert(($oaiSupportedTransport->lastPayload['max_output_tokens'] ?? 0) === 8500, 'OpenAI standard reasoning must add its reasoning allowance on top of the v1 answer, not share it.');
provider_assert(($oaiSupportedTransport->lastPayload['store'] ?? true) === false, 'OpenAI supported reasoning must retain store=false.');

$oaiDeepTransport = new class implements CF7AIV\Infrastructure\HttpTransportInterface {
	public ?array $lastPayload = null;
	public function post(string $url, array $arguments): CF7AIV\Infrastructure\HttpResponse {
		$this->lastPayload = json_decode($arguments['body'], true);
		$content = json_encode(['tier_id' => 'spam', 'outcome_id' => 'spam', 'ads_eligible' => false, 'flags' => [], 'confidence' => 0.9, 'evidence' => [], 'exclusion_codes' => [], 'ambiguity_flags' => [], 'reasoning_summary' => '']);
		return new CF7AIV\Infrastructure\HttpResponse(200, json_encode(['output_text' => $content, 'usage' => ['input_tokens' => 10, 'output_tokens' => 20, 'output_tokens_details' => ['reasoning_tokens' => 10]]]) ?: '');
	}
};
$oaiDeepInstance = new CF7AIV\Providers\OpenAIProvider($oaiDeepTransport, 'key', 'https://api.openai.com/v1/responses', 'o4-mini', 30, 'deep');
try { $oaiDeepInstance->classify($mockRequest); } catch (Throwable) {}
provider_assert(($oaiDeepTransport->lastPayload['reasoning']['effort'] ?? '') === 'high', 'OpenAI deep reasoning must send the documented high reasoning effort for supported models.');
provider_assert(($oaiDeepTransport->lastPayload['max_output_tokens'] ?? 0) === 24500, 'OpenAI deep reasoning must add its larger reasoning allowance on top of the v1 answer.');

$oaiNoneTransport = new class implements CF7AIV\Infrastructure\HttpTransportInterface {
	public ?array $lastPayload = null;
	public function post(string $url, array $arguments): CF7AIV\Infrastructure\HttpResponse {
		$this->lastPayload = json_decode($arguments['body'], true);
		$content = json_encode(['tier_id' => 'spam', 'outcome_id' => 'spam', 'ads_eligible' => false, 'flags' => [], 'confidence' => 0.9, 'evidence' => [], 'exclusion_codes' => [], 'ambiguity_flags' => [], 'reasoning_summary' => '']);
		return new CF7AIV\Infrastructure\HttpResponse(200, json_encode(['output_text' => $content, 'usage' => ['input_tokens' => 10, 'output_tokens' => 20, 'output_tokens_details' => ['reasoning_tokens' => 0]]]) ?: '');
	}
};
$oaiNoneInstance = new CF7AIV\Providers\OpenAIProvider($oaiNoneTransport, 'key', 'https://api.openai.com/v1/responses', 'gpt-5.4', 30, 'off');
try { $oaiNoneInstance->classify($mockRequest); } catch (Throwable) {}
provider_assert(($oaiNoneTransport->lastPayload['reasoning']['effort'] ?? '') === 'none', 'GPT-5.4 off must send the documented reasoning.effort=none value.');

// ─── ClassificationHooks uses settings for reasoning effort ──────────────────

// Verify Settings::withDefaults() correctly merges reasoning effort keys.
$existing = ['providers' => ['selected' => 'openai']];
$merged = CF7AIV\Infrastructure\Settings::withDefaults($existing);
provider_assert($merged['providers']['deepseek_reasoning_effort'] === 'off', 'withDefaults must add deepseek_reasoning_effort=off to existing settings.');
provider_assert($merged['providers']['openai_reasoning_effort'] === 'unsupported', 'withDefaults must add openai_reasoning_effort=unsupported to existing settings.');

// ─── Model IDs and one-time settings upgrade ─────────────────────────────────

// DeepSeek retired V4-Flash on 10 September 2026. Its ID is only a temporary
// alias for V4.1-Flash, so a fresh install must use the current ID.
provider_assert(CF7AIV\Infrastructure\Settings::defaults()['providers']['deepseek_model'] === 'deepseek-flash', 'Fresh installs must default to the current DeepSeek model ID.');
provider_assert(CF7AIV\Infrastructure\Settings::defaults()['providers']['timeout'] === 60, 'Fresh installs must default to a 60-second provider timeout.');

$legacy = ['providers' => ['deepseek_model' => 'deepseek-v4-flash', 'timeout' => 15]];
$upgraded = CF7AIV\Infrastructure\Settings::upgrade($legacy);
provider_assert($upgraded['providers']['deepseek_model'] === 'deepseek-flash', 'A stored retired DeepSeek model ID must move to the current one.');
provider_assert($upgraded['providers']['timeout'] === 60, 'A timeout still on the old 15-second default must move to the new default.');
provider_assert($upgraded['settings_revision'] === CF7AIV\Infrastructure\Settings::REVISION, 'An upgrade must record the revision it applied.');
foreach (['deepseek-chat', 'deepseek-reasoner', 'DeepSeek-V4-Flash-Vision-Exp'] as $retired) {
	provider_assert(CF7AIV\Infrastructure\Settings::upgrade(['providers' => ['deepseek_model' => $retired]])['providers']['deepseek_model'] === 'deepseek-flash', "Retired model {$retired} must move to the current one.");
}
// deepseek-reasoner always thought, so moving it to a model that reasons only
// when asked must not quietly switch reasoning off.
$reasoner = CF7AIV\Infrastructure\Settings::upgrade(['providers' => ['deepseek_model' => 'deepseek-reasoner', 'deepseek_reasoning_effort' => 'off']]);
provider_assert($reasoner['providers']['deepseek_reasoning_effort'] === 'standard', 'A site on deepseek-reasoner must keep reasoning after the model moves.');
$reasonerDeep = CF7AIV\Infrastructure\Settings::upgrade(['providers' => ['deepseek_model' => 'deepseek-reasoner', 'deepseek_reasoning_effort' => 'deep']]);
provider_assert($reasonerDeep['providers']['deepseek_reasoning_effort'] === 'deep', 'A chosen reasoning level must be kept.');
$chatOff = CF7AIV\Infrastructure\Settings::upgrade(['providers' => ['deepseek_model' => 'deepseek-v4-flash', 'deepseek_reasoning_effort' => 'off']]);
provider_assert($chatOff['providers']['deepseek_reasoning_effort'] === 'off', 'Only the reasoner model implies reasoning.');
$chosen = CF7AIV\Infrastructure\Settings::upgrade(['providers' => ['deepseek_model' => 'deepseek-v4-pro', 'timeout' => 20]]);
provider_assert($chosen['providers']['deepseek_model'] === 'deepseek-v4-pro' && $chosen['providers']['timeout'] === 20, 'A model or timeout the administrator chose must be left alone.');
// Once applied, an administrator who deliberately picks 15 seconds keeps it.
$alreadyCurrent = CF7AIV\Infrastructure\Settings::upgrade(['settings_revision' => CF7AIV\Infrastructure\Settings::REVISION, 'providers' => ['deepseek_model' => 'deepseek-v4-flash', 'timeout' => 15]]);
provider_assert($alreadyCurrent['providers']['timeout'] === 15 && $alreadyCurrent['providers']['deepseek_model'] === 'deepseek-v4-flash', 'The upgrade must run once, not on every request.');
provider_assert(CF7AIV\Infrastructure\Settings::upgrade([])['settings_revision'] === CF7AIV\Infrastructure\Settings::REVISION, 'Settings without providers must still be marked upgraded.');

// ─── Timeouts follow the setting, the effort floor and the ceiling ───────────

final class ProviderTimeoutCaptureTransport implements CF7AIV\Infrastructure\HttpTransportInterface {
	/** @var list<int> */ public array $timeouts = [];
	/** @var list<array<string,mixed>> */ public array $payloads = [];
	public function __construct(private readonly string $provider, private readonly array $envelope = []) {}
	public function post(string $url, array $arguments): CF7AIV\Infrastructure\HttpResponse {
		$this->timeouts[] = (int) ($arguments['timeout'] ?? 0);
		$this->payloads[] = json_decode((string) $arguments['body'], true);
		// A valid v1 result, so a completed reply is accepted rather than retried.
		$content = json_encode(['tier_id' => 'spam', 'reason_code' => 'spam', 'confidence' => 0.9, 'summary' => 'Unsolicited sales message.', 'ambiguity_flags' => []]);
		$body = $this->provider === 'openai'
			? ['output_text' => $content]
			: ['choices' => [['message' => ['content' => $content], 'finish_reason' => 'stop']]];
		return new CF7AIV\Infrastructure\HttpResponse(200, (string) json_encode(array_replace_recursive($body, $this->envelope)));
	}
}

foreach ([
	['effort' => 'off', 'configured' => 30, 'expected' => 30],
	['effort' => 'off', 'configured' => 1, 'expected' => 5],
	['effort' => 'standard', 'configured' => 30, 'expected' => 90],
	['effort' => 'standard', 'configured' => 200, 'expected' => 200],
	['effort' => 'deep', 'configured' => 30, 'expected' => 180],
	['effort' => 'deep', 'configured' => 900, 'expected' => 300],
] as $case) {
	$transport = new ProviderTimeoutCaptureTransport('deepseek');
	(new CF7AIV\Providers\DeepSeekProvider($transport, 'key', 'https://api.deepseek.com/chat/completions', 'deepseek-flash', $case['configured'], $case['effort']))->classify($mockRequest);
	provider_assert($transport->timeouts === [$case['expected']], "DeepSeek {$case['effort']} with a {$case['configured']}s setting must wait {$case['expected']}s.");
}

// Verification always reasons deeply, so it needs the deep floor even when
// classification itself runs with reasoning off.
$verifyTransport = new ProviderTimeoutCaptureTransport('deepseek');
(new CF7AIV\Providers\DeepSeekProvider($verifyTransport, 'key', 'https://api.deepseek.com/chat/completions', 'deepseek-flash', 30, 'off'))->classifyWithReasoning($mockRequest);
provider_assert($verifyTransport->timeouts === [180] && ($verifyTransport->payloads[0]['reasoning_effort'] ?? '') === 'max', 'The verification pass must be given deep reasoning time and effort.');
// A default classification plus a deep verification share one queued job, and
// Action Scheduler marks a job failed at 300 seconds.
provider_assert(CF7AIV\Infrastructure\Settings::DEFAULT_PROVIDER_TIMEOUT + CF7AIV\Providers\DeepSeekProvider::REASONING_TIMEOUT_FLOOR['deep'] <= 300, 'A default classification and its deep verification must fit inside the Action Scheduler failure period.');

$openTimeout = new ProviderTimeoutCaptureTransport('openai');
(new CF7AIV\Providers\OpenAIProvider($openTimeout, 'key', 'https://api.openai.com/v1/responses', 'gpt-5.4-mini', 30, 'standard'))->classify($mockRequest);
provider_assert($openTimeout->timeouts === [90], 'OpenAI standard reasoning must be given the standard floor.');

// The providers classification uses must read the configured timeout. They
// were built with a fixed 30 seconds, so the setting only reached the scan.
$GLOBALS['provider_test_options'][CF7AIV\Infrastructure\Settings::OPTION] = CF7AIV\Infrastructure\Settings::withDefaults(['providers' => ['timeout' => 200]]);
$registered = CF7AIV\Classification\ClassificationHooks::registerProviders([]);
foreach (['deepseek', 'openai'] as $key) {
	$timeoutProperty = new ReflectionProperty($registered[$key], 'timeout');
	provider_assert($timeoutProperty->getValue($registered[$key]) === 200, "Classification's {$key} provider must use the configured timeout.");
}
unset($GLOBALS['provider_test_options']);

// ─── Each result records the setup that produced it ──────────────────────────

$profileTransport = new ProviderTimeoutCaptureTransport('deepseek');
$profiled = (new CF7AIV\Providers\DeepSeekProvider($profileTransport, 'key', 'https://api.deepseek.com/chat/completions', 'deepseek-flash', 30, 'standard'))->classify($mockRequest);
provider_assert($profiled->providerProfile === ['provider' => 'deepseek', 'model' => 'deepseek-flash', 'reasoning' => 'standard'], 'A DeepSeek result must record its provider, model and reasoning level.');
$verified = (new CF7AIV\Providers\DeepSeekProvider(new ProviderTimeoutCaptureTransport('deepseek'), 'key', 'https://api.deepseek.com/chat/completions', 'deepseek-flash', 30, 'off'))->classifyWithReasoning($mockRequest);
provider_assert(($verified->providerProfile['reasoning'] ?? '') === 'deep', 'A verification result must record the deep reasoning it used.');
$openProfiled = (new CF7AIV\Providers\OpenAIProvider(new ProviderTimeoutCaptureTransport('openai'), 'key', 'https://api.openai.com/v1/responses', 'gpt-5.4-mini', 30, 'deep'))->classify($mockRequest);
provider_assert($openProfiled->providerProfile === ['provider' => 'openai', 'model' => 'gpt-5.4-mini', 'reasoning' => 'deep'], 'An OpenAI result must record its provider, model and reasoning level.');
provider_assert($profiled->withProviderUsage(['calls' => 3])->providerProfile === $profiled->providerProfile, 'Merging verification usage must keep the profile.');

// ─── Connection test ─────────────────────────────────────────────────────────

final class ProviderConnectionTransport implements CF7AIV\Infrastructure\HttpTransportInterface {
	/** @var list<array<string,mixed>> */ public array $requests = [];
	public function __construct(private readonly int $status, private readonly string $body = '{}') {}
	public function post(string $url, array $arguments): CF7AIV\Infrastructure\HttpResponse {
		$this->requests[] = ['url' => $url, 'timeout' => $arguments['timeout'] ?? null, 'payload' => json_decode((string) $arguments['body'], true)];
		return new CF7AIV\Infrastructure\HttpResponse($this->status, $this->body);
	}
}
$okTransport = new ProviderConnectionTransport(200);
(new CF7AIV\Providers\DeepSeekProvider($okTransport, 'key', 'https://api.deepseek.com/chat/completions', 'deepseek-flash', 300, 'deep'))->testConnection();
$testPayload = $okTransport->requests[0]['payload'] ?? [];
provider_assert($okTransport->requests[0]['timeout'] === 20 && ($testPayload['thinking']['type'] ?? '') === 'disabled' && ($testPayload['max_tokens'] ?? 0) <= 8 && ($testPayload['model'] ?? '') === 'deepseek-flash', 'The connection test must be one tiny, non-reasoning call with a short limit, whatever the configured effort and timeout.');
$rejected = null;
try { (new CF7AIV\Providers\DeepSeekProvider(new ProviderConnectionTransport(401, '{"error":{"message":"Authentication Fails, Your api key is invalid","type":"authentication_error"}}'), 'key'))->testConnection(); } catch (Throwable $error) { $rejected = $error; }
provider_assert($rejected instanceof CF7AIV\Providers\PermanentProviderException && str_contains($rejected->getMessage(), 'HTTP 401') && str_contains($rejected->getMessage(), 'api key is invalid'), 'A rejected connection test must name the status and the provider\'s reason.');
$busy = null;
try { (new CF7AIV\Providers\OpenAIProvider(new ProviderConnectionTransport(503), 'key'))->testConnection(); } catch (Throwable $error) { $busy = $error; }
provider_assert($busy instanceof CF7AIV\Providers\TransientProviderException && str_contains($busy->getMessage(), 'HTTP 503'), 'A provider outage must read as temporary.');
$openTest = new ProviderConnectionTransport(200);
(new CF7AIV\Providers\OpenAIProvider($openTest, 'key', 'https://api.openai.com/v1/responses', 'gpt-5.4-mini', 300, 'deep'))->testConnection();
provider_assert(($openTest->requests[0]['payload']['reasoning']['effort'] ?? '') === 'none' && ($openTest->requests[0]['payload']['store'] ?? true) === false, 'The OpenAI connection test must switch reasoning off where the model allows and keep store=false.');

// ─── An unfinished reply is not malformed output ─────────────────────────────

foreach ([
	['finish' => 'length', 'exception' => CF7AIV\Providers\PermanentProviderException::class],
	['finish' => 'content_filter', 'exception' => CF7AIV\Providers\PermanentProviderException::class],
	['finish' => 'insufficient_system_resource', 'exception' => CF7AIV\Providers\TransientProviderException::class],
] as $case) {
	$transport = new ProviderTimeoutCaptureTransport('deepseek', ['choices' => [['finish_reason' => $case['finish']]]]);
	$thrown = null;
	try { (new CF7AIV\Providers\DeepSeekProvider($transport, 'key', 'https://api.deepseek.com/chat/completions', 'deepseek-flash', 30, 'deep'))->classify($mockRequest); } catch (Throwable $error) { $thrown = $error; }
	provider_assert($thrown instanceof $case['exception'] && str_contains($thrown->getMessage(), $case['finish']), "DeepSeek finish_reason {$case['finish']} must be reported as itself.");
	provider_assert(count($transport->timeouts) === 1, "DeepSeek finish_reason {$case['finish']} must not pay for an identical retry.");
}

$incomplete = new ProviderTimeoutCaptureTransport('openai', ['status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens']]);
$thrown = null;
try { (new CF7AIV\Providers\OpenAIProvider($incomplete, 'key', 'https://api.openai.com/v1/responses', 'gpt-5.4-mini', 30, 'deep'))->classify($mockRequest); } catch (Throwable $error) { $thrown = $error; }
provider_assert($thrown instanceof CF7AIV\Providers\PermanentProviderException && str_contains($thrown->getMessage(), 'max_output_tokens'), 'An incomplete OpenAI reply must be reported as incomplete, not malformed.');
provider_assert(count($incomplete->timeouts) === 1, 'An incomplete OpenAI reply must not pay for an identical retry.');

// A provider built on JsonProvider before the completeness check existed must
// still load: the check has a no-op default rather than being abstract.
$legacyProvider = new class extends CF7AIV\Providers\JsonProvider {
	public function key(): string { return 'legacy'; }
	protected function send(CF7AIV\Domain\ClassificationRequest $request, bool $reasoning): CF7AIV\Infrastructure\HttpResponse {
		return new CF7AIV\Infrastructure\HttpResponse(200, (string) json_encode(['text' => json_encode(['tier_id' => 'spam', 'reason_code' => 'spam', 'confidence' => 0.9, 'summary' => 'Unsolicited sales message.', 'ambiguity_flags' => []])]));
	}
	protected function extractText(array $body): string { return (string) ($body['text'] ?? ''); }
	protected function extractUsage(array $body): array { return []; }
};
provider_assert($legacyProvider->classify($mockRequest)->tierId === 'spam', 'A JsonProvider subclass without assertComplete must keep working.');

$complete = new ProviderTimeoutCaptureTransport('openai', ['status' => 'completed']);
(new CF7AIV\Providers\OpenAIProvider($complete, 'key', 'https://api.openai.com/v1/responses', 'gpt-5.4-mini', 30, 'deep'))->classify($mockRequest);
provider_assert(count($complete->timeouts) === 1, 'A completed OpenAI reply must still be accepted.');

echo "PASS ProviderTest\n";
