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
function get_option(string $name, mixed $default = false): mixed { return $default; }
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
	['effort' => 'off', 'thinking' => 'disabled', 'max_tokens' => 1500],
	['effort' => 'standard', 'thinking' => 'enabled', 'max_tokens' => 3000],
	['effort' => 'deep', 'thinking' => 'enabled', 'max_tokens' => 4500],
] as $case) {
	$transport = new ProviderSuggestionCaptureTransport('deepseek');
	$provider = new CF7AIV\Providers\DeepSeekProvider($transport, 'key', 'https://api.deepseek.com/chat/completions', 'deepseek-v4-flash', 30, $case['effort']);
	$provider->suggestPolicy($suggestionRequest);
	$payload = $transport->lastPayload ?? [];
	$wire = json_encode($payload, JSON_THROW_ON_ERROR);
	provider_assert(($payload['thinking']['type'] ?? '') === $case['thinking'], "DeepSeek {$case['effort']} suggestions must map to the matching thinking capability.");
	provider_assert(($payload['max_tokens'] ?? 0) === $case['max_tokens'], "DeepSeek {$case['effort']} suggestions must use the classification-equivalent bounded output budget.");
	provider_assert(($payload['response_format']['type'] ?? '') === 'json_object' && str_contains((string) ($payload['messages'][0]['content'] ?? ''), 'Required JSON Schema'), 'DeepSeek suggestions must retain strict JSON mode and the fixed schema prompt.');
	provider_assert(! str_contains($wire, 'private@example.test') && ! str_contains($wire, 'TOP-SECRET'), 'DeepSeek suggestion payloads must retain privacy redaction.');
	if ($case['effort'] === 'off') {
		provider_assert(($payload['temperature'] ?? null) === 0, 'DeepSeek off suggestions must retain deterministic temperature handling.');
	} else {
		provider_assert(! array_key_exists('temperature', $payload), "DeepSeek {$case['effort']} suggestions must use the reasoning capability instead of forced temperature zero.");
	}
}

foreach ([
	['effort' => 'off', 'mapped' => 'none', 'max_output_tokens' => 1500],
	['effort' => 'standard', 'mapped' => 'medium', 'max_output_tokens' => 3000],
	['effort' => 'deep', 'mapped' => 'high', 'max_output_tokens' => 6000],
] as $case) {
	$transport = new ProviderSuggestionCaptureTransport('openai');
	$provider = new CF7AIV\Providers\OpenAIProvider($transport, 'key', 'https://api.openai.com/v1/responses', 'gpt-5.4-mini', 30, $case['effort']);
	$provider->suggestPolicy($suggestionRequest);
	$payload = $transport->lastPayload ?? [];
	$wire = json_encode($payload, JSON_THROW_ON_ERROR);
	provider_assert(($payload['reasoning']['effort'] ?? '') === $case['mapped'], "OpenAI {$case['effort']} suggestions must map to the documented reasoning capability.");
	provider_assert(($payload['max_output_tokens'] ?? 0) === $case['max_output_tokens'], "OpenAI {$case['effort']} suggestions must use a bounded output budget.");
	provider_assert(($payload['store'] ?? true) === false && ($payload['text']['format']['type'] ?? '') === 'json_schema' && ($payload['text']['format']['strict'] ?? false) === true, 'OpenAI suggestions must retain store=false and strict JSON schema output.');
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
provider_assert(($v1Transport->lastPayload['max_tokens'] ?? 0) === 900, 'DeepSeek off must send max_tokens=900.');

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
provider_assert(($v1TransportStd->lastPayload['max_tokens'] ?? 0) === 2400, 'DeepSeek standard must send the 900-token answer allowance plus its reasoning allowance.');

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
provider_assert(($v1TransportDeep->lastPayload['max_tokens'] ?? 0) === 3900, 'DeepSeek deep must send the 900-token answer allowance plus its deep reasoning allowance.');

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
provider_assert(($oaiSupportedTransport->lastPayload['max_output_tokens'] ?? 0) === 3000, 'OpenAI standard reasoning must reserve a bounded reasoning and structured-output budget.');
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
provider_assert(($oaiDeepTransport->lastPayload['max_output_tokens'] ?? 0) === 6000, 'OpenAI deep reasoning must reserve a larger bounded reasoning and structured-output budget.');

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

echo "PASS ProviderTest\n";
