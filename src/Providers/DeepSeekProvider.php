<?php

declare(strict_types=1);

namespace CF7AIV\Providers;

use CF7AIV\Classification\PolicyValidator;
use CF7AIV\Classification\ResultValidator;
use CF7AIV\Contracts\VerificationCapableProviderInterface;
use CF7AIV\Contracts\PolicySuggestionProviderInterface;
use CF7AIV\Domain\ClassificationRequest;
use CF7AIV\Domain\ClassificationResult;
use CF7AIV\Domain\PolicySuggestionRequest;
use CF7AIV\Domain\PolicySuggestionResult;
use CF7AIV\Infrastructure\HttpResponse;
use CF7AIV\Infrastructure\HttpTransportInterface;
use CF7AIV\Infrastructure\Settings;

final class DeepSeekProvider extends JsonProvider implements VerificationCapableProviderInterface, PolicySuggestionProviderInterface {
	/**
	 * DeepSeek-V4.1-Flash, released 10 September 2026.
	 *
	 * DeepSeek retired V4-Flash on the same day. Its model ID still works, but only
	 * as a temporary alias that DeepSeek routes to V4.1-Flash, and it can be
	 * withdrawn without notice.
	 */
	public const DEFAULT_MODEL = Settings::DEFAULT_DEEPSEEK_MODEL;

	/**
	 * Model IDs DeepSeek has retired, which a stored setting is moved off.
	 *
	 * deepseek-v4-pro is deliberately absent: it is still a documented model ID,
	 * and an administrator who chose it should decide when to leave it.
	 */
	private const RETIRED_MODELS = ['deepseek-v4-flash', 'deepseek-v4-flash-vision-exp', 'deepseek-chat', 'deepseek-reasoner'];

	/**
	 * How hard DeepSeek thinks, sent as reasoning_effort.
	 *
	 * Enabling thinking without an effort leaves DeepSeek on its default of
	 * "high", so standard and deep used to differ only in how many tokens they
	 * were allowed - deep was never asked to think harder. DeepSeek has no medium
	 * level: it maps medium to high, which makes high the counterpart of OpenAI's
	 * medium and max the counterpart of OpenAI's high.
	 */
	private const REASONING_EFFORT = ['standard' => 'high', 'deep' => 'max'];

	public function __construct(
		private readonly HttpTransportInterface $transport,
		private readonly string $apiKey,
		private readonly string $baseUrl = 'https://api.deepseek.com/chat/completions',
		private readonly string $model = self::DEFAULT_MODEL,
		private readonly int $timeout = Settings::DEFAULT_PROVIDER_TIMEOUT,
		private readonly string $reasoningEffort = 'off',
	) {}

	public static function isRetiredModel(string $model): bool {
		return in_array(strtolower(trim($model)), self::RETIRED_MODELS, true);
	}

	public static function reasoningOptions(string $model): array {
		return ['off', 'standard', 'deep'];
	}

	public static function reasoningDescription(string $model, string $effort): string {
		return match ($effort) {
			'standard' => 'Standard reasoning enables DeepSeek thinking at high effort, with its own bounded allowance on top of the JSON answer.',
			'deep' => 'Deep reasoning enables DeepSeek thinking at maximum effort. It is the most accurate setting and the slowest, and uses the most tokens.',
			default => 'Reasoning is off; DeepSeek is limited to the bounded structured JSON output budget.',
		};
	}

	public function key(): string { return 'deepseek'; }

	protected function profile(bool $reasoning): array {
		return ['provider' => 'deepseek', 'model' => $this->model, 'reasoning' => $reasoning ? 'deep' : $this->reasoningEffort];
	}

	public function suggestPolicy(PolicySuggestionRequest $request): PolicySuggestionResult {
		$schema = json_encode(PolicySuggestionResult::jsonSchema($request->allowedSourceRefs()), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		$reasoningEffort = $this->reasoningEffort;
		$payload = [
			'model' => $this->model,
			'messages' => [
				['role' => 'system', 'content' => 'Suggest no more than ' . PolicySuggestionResult::MAX_SUGGESTIONS . ' compact policy setup facts from only the supplied public local-page and non-identifying form structure. Never infer identities, attribution, secrets, or a publishable policy.' . "\n" . self::UNTRUSTED_PROFILE_RULE . "\nRequired JSON Schema: " . $schema . "\nReturn one valid JSON object matching this schema and no other text."],
				['role' => 'user', 'content' => $request->providerProfileJson()],
			],
			'response_format' => ['type' => 'json_object'],
			'max_tokens' => $this->tokenBudget($reasoningEffort, self::SUGGESTION_ANSWER_TOKENS),
		] + $this->reasoningPayload($reasoningEffort);
		$response = $this->transport->post($this->baseUrl, [
			'headers' => $this->headers($this->apiKey),
			'timeout' => $this->requestTimeout($this->timeout, $reasoningEffort),
			'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
		]);
		$this->assertSuggestionStatus($response);
		$body = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);
		if (! is_array($body)) { throw new PermanentProviderException('DeepSeek returned a malformed suggestion response.'); }
		$this->assertComplete($body);
		return PolicySuggestionResult::fromJson(trim($this->extractText($body)));
	}

	protected function transportForTest(): HttpResponse {
		return $this->transport->post($this->baseUrl, [
			'headers' => $this->headers($this->apiKey),
			'timeout' => 20,
			'body' => json_encode([
				'model' => $this->model,
				'messages' => [['role' => 'user', 'content' => 'Reply with the single word ok.']],
				'max_tokens' => 8,
				'thinking' => ['type' => 'disabled'],
				'temperature' => 0,
			], JSON_THROW_ON_ERROR),
		]);
	}

	public function classifyWithReasoning(ClassificationRequest $request): ClassificationResult {
		return $this->classifyMode($request, true);
	}

	protected function send(ClassificationRequest $request, bool $reasoning): HttpResponse {
		$schema = json_encode(ResultValidator::jsonSchema($request), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		$guidance = $this->v2JsonGuidance($request);
		$reasoningEffort = $reasoning ? 'deep' : $this->reasoningEffort;
		$payload = [
			'model' => $this->model,
			'messages' => [
				['role' => 'system', 'content' => $request->systemPrompt . "\n" . self::UNTRUSTED_INPUT_RULE . "\nRequired JSON Schema: " . $schema . $guidance . "\nReturn one valid JSON object matching this schema and no other text."],
				['role' => 'user', 'content' => json_encode($this->userInput($request), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
			],
			'response_format' => ['type' => 'json_object'],
			'max_tokens' => $this->tokenBudget($reasoningEffort, self::CLASSIFICATION_ANSWER_TOKENS),
		] + $this->reasoningPayload($reasoningEffort);
		return $this->transport->post($this->baseUrl, [
			'headers' => $this->headers($this->apiKey),
			'timeout' => $this->requestTimeout($this->timeout, $reasoningEffort),
			'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
		]);
	}

	private function v2JsonGuidance(ClassificationRequest $request): string {
		if ($request->policySchema !== PolicyValidator::SCHEMA_V2) { return ''; }
		$mapping = json_encode($request->allowedEvidence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		$pairs = [];
		foreach ($request->allowedEvidence as $code => $dimension) {
			$pairs[] = ['code' => $code, 'dimension' => $dimension];
		}
		return "\nEvidence code-to-dimension mapping: " . $mapping
			. "\nEach evidence dimension must equal the value mapped from its code exactly."
			. "\nEvidence pair examples: " . json_encode($pairs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
			. "\nDo not stop after required evidence. For the chosen tier, include every grounded supporting evidence assertion present in the supplied context, using distinct supporting evidence codes until minimum_supporting_evidence is satisfied. If it cannot be satisfied, choose a tier whose evidence rules are satisfied or return insufficient information.";
	}

	protected function extractText(array $body): string {
		if (isset($body['error'])) { throw new PermanentProviderException('DeepSeek returned an application error.'); }
		$content = $body['choices'][0]['message']['content'] ?? '';
		return is_string($content) ? $content : '';
	}

	protected function extractUsage(array $body): array {
		$usage = is_array($body['usage'] ?? null) ? $body['usage'] : [];
		$details = is_array($usage['completion_tokens_details'] ?? null) ? $usage['completion_tokens_details'] : [];
		return [
			'input_tokens' => $usage['prompt_tokens'] ?? 0,
			'output_tokens' => $usage['completion_tokens'] ?? 0,
			'reasoning_tokens' => $details['reasoning_tokens'] ?? 0,
		];
	}

	/**
	 * Thinking, effort and sampling for one request.
	 *
	 * Thinking is on by default for DeepSeek, so "off" has to be sent rather
	 * than implied. Temperature is ignored while thinking, so it is only sent
	 * when it does something.
	 *
	 * @return array<string,mixed>
	 */
	private function reasoningPayload(string $reasoningEffort): array {
		$effort = self::REASONING_EFFORT[$reasoningEffort] ?? null;
		if ($effort === null) {
			return ['thinking' => ['type' => 'disabled'], 'temperature' => 0];
		}
		return ['thinking' => ['type' => 'enabled'], 'reasoning_effort' => $effort];
	}

	/**
	 * Refuse a reply the model did not finish writing.
	 *
	 * A truncated answer is not malformed input, it is an exhausted budget, and
	 * the two need opposite fixes. Without this the JSON decode fails several
	 * layers away and reports the model as having returned nonsense, then pays
	 * for an identical retry that fails the same way.
	 *
	 * @param array<string,mixed> $body
	 */
	protected function assertComplete(array $body): void {
		$reason = $body['choices'][0]['finish_reason'] ?? '';
		if ($reason === 'length') {
			throw new PermanentProviderException('DeepSeek stopped at the token budget before finishing the response (finish_reason: length). Choose a lower reasoning setting or send fewer fields.');
		}
		if ($reason === 'content_filter') {
			throw new PermanentProviderException('DeepSeek withheld the response under its content filter (finish_reason: content_filter).');
		}
		if ($reason === 'insufficient_system_resource') {
			throw new TransientProviderException('DeepSeek ended the response early because it was short of capacity (finish_reason: insufficient_system_resource).');
		}
	}

	/**
	 * The status code is the whole diagnosis here, so it belongs in the message.
	 *
	 * Without it every rejection reads the same, and an administrator cannot tell
	 * a credential that was never stored (401) from one the account cannot use
	 * (403) from a wrong base URL (404). The status is not sensitive: it carries
	 * no key material and no enquiry content.
	 */
	private function assertSuggestionStatus(HttpResponse $response): void {
		if ($response->status >= 200 && $response->status < 300) { return; }
		if ($response->status === 0 || $response->status === 408 || $response->status === 429 || $response->status >= 500) {
			throw new TransientProviderException('DeepSeek suggestion request failed temporarily (HTTP ' . $response->status . ').');
		}
		throw new PermanentProviderException('DeepSeek suggestion request failed (HTTP ' . $response->status . ').');
	}
}
