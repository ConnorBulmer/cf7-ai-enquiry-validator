<?php

declare(strict_types=1);

namespace CF7AIV\Providers;

use CF7AIV\Classification\ResultValidator;
use CF7AIV\Contracts\PolicySuggestionProviderInterface;
use CF7AIV\Domain\ClassificationRequest;
use CF7AIV\Domain\PolicySuggestionRequest;
use CF7AIV\Domain\PolicySuggestionResult;
use CF7AIV\Infrastructure\HttpResponse;
use CF7AIV\Infrastructure\HttpTransportInterface;
use CF7AIV\Infrastructure\Settings;

final class OpenAIProvider extends JsonProvider implements PolicySuggestionProviderInterface {
	private const REASONING_MODELS = [
		'o1' => true,
		'o1-mini' => true,
		'o1-preview' => true,
		'o1-pro' => true,
		'o3' => true,
		'o3-mini' => true,
		'o3-pro' => true,
		'o4-mini' => true,
		'o4-mini-2025-04-16' => true,
		'gpt-5' => true,
		'gpt-5-mini' => true,
		'gpt-5-nano' => true,
		'gpt-5.1' => true,
		'gpt-5.1-mini' => true,
		'gpt-5.2' => true,
		'gpt-5.2-mini' => true,
		'gpt-5.4' => true,
		'gpt-5.4-2026-03-05' => true,
		'gpt-5.4-mini' => true,
		'gpt-5.4-nano' => true,
		'gpt-5.4-mini-2026-03-17' => true,
		'gpt-5.4-nano-2026-03-17' => true,
	];
	private const NONE_REASONING_MODELS = [
		'gpt-5.4' => true,
		'gpt-5.4-2026-03-05' => true,
		'gpt-5.4-mini' => true,
		'gpt-5.4-mini-2026-03-17' => true,
		'gpt-5.4-nano' => true,
		'gpt-5.4-nano-2026-03-17' => true,
	];
	/** A v1 answer has no quoted evidence, so it needs far less room than a v2 one. */
	private const V1_CLASSIFICATION_ANSWER_TOKENS = 500;

	public function __construct(
		private readonly HttpTransportInterface $transport,
		private readonly string $apiKey,
		private readonly string $baseUrl = 'https://api.openai.com/v1/responses',
		private readonly string $model = 'gpt-5.4-mini',
		private readonly int $timeout = Settings::DEFAULT_PROVIDER_TIMEOUT,
		private readonly string $reasoningEffort = 'unsupported',
	) {}

	public static function reasoningOptions(string $model): array {
		if (! self::supportsReasoning($model)) {
			return ['unsupported'];
		}
		return self::supportsNone($model) ? ['off', 'standard', 'deep'] : ['standard', 'deep'];
	}

	public static function reasoningDescription(string $model, string $effort): string {
		if (! self::supportsReasoning($model) || $effort === 'unsupported') {
			return 'Reasoning is unavailable for this OpenAI model or selected configuration.';
		}
		return match ($effort) {
			'off' => self::supportsNone($model)
				? 'Reasoning is disabled with the documented reasoning.effort=none setting for this model.'
				: 'Reasoning cannot be disabled with a documented none setting for this model; standard or deep is required.',
			'standard' => 'Standard OpenAI reasoning uses a bounded medium reasoning effort and may use more tokens.',
			'deep' => 'Deep OpenAI reasoning uses a bounded high reasoning effort and may use more tokens.',
			default => 'Reasoning is unavailable for this OpenAI model or selected configuration.'
		};
	}

	public function key(): string { return 'openai'; }

	protected function profile(bool $reasoning): array {
		return ['provider' => 'openai', 'model' => $this->model, 'reasoning' => $this->effectiveReasoningEffort() ?? 'unsupported'];
	}

	public function suggestPolicy(PolicySuggestionRequest $request): PolicySuggestionResult {
		$effort = $this->effectiveReasoningEffort();
		$payload = [
			'model' => $this->model,
			'store' => false,
			'instructions' => 'Suggest compact policy setup facts from only the supplied public local-page and non-identifying form structure. Never infer identities, attribution, secrets, or a publishable policy.' . "\n" . self::UNTRUSTED_PROFILE_RULE,
			'input' => [['role' => 'user', 'content' => [['type' => 'input_text', 'text' => $request->providerProfileJson()]]]],
			'text' => ['format' => ['type' => 'json_schema', 'name' => 'cf7aiv_policy_suggestions', 'strict' => true, 'schema' => PolicySuggestionResult::jsonSchema($request->allowedSourceRefs())]],
			'max_output_tokens' => $this->tokenBudget((string) $effort, self::SUGGESTION_ANSWER_TOKENS),
		];
		$reasoning = $this->reasoningPayload($effort);
		if ($reasoning !== null) {
			$payload['reasoning'] = $reasoning;
		}
		$response = $this->transport->post($this->baseUrl, [
			'headers' => $this->headers($this->apiKey),
			'timeout' => $this->requestTimeout($this->timeout, $effort),
			'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
		]);
		$this->assertSuggestionStatus($response);
		$body = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);
		if (! is_array($body)) { throw new PermanentProviderException('OpenAI returned a malformed suggestion response.'); }
		$this->assertComplete($body);
		return PolicySuggestionResult::fromJson(trim($this->extractText($body)));
	}

	protected function transportForTest(): HttpResponse {
		$payload = ['model' => $this->model, 'store' => false, 'input' => 'Reply with the single word ok.', 'max_output_tokens' => 16];
		if (self::supportsNone($this->model)) { $payload['reasoning'] = ['effort' => 'none']; }
		return $this->transport->post($this->baseUrl, [
			'headers' => $this->headers($this->apiKey),
			'timeout' => 20,
			'body' => json_encode($payload, JSON_THROW_ON_ERROR),
		]);
	}

	protected function send(ClassificationRequest $request, bool $reasoning): HttpResponse {
		$effort = $this->effectiveReasoningEffort();
		$payload = [
			'model' => $this->model,
			'store' => false,
			'instructions' => $request->systemPrompt . "\n" . self::UNTRUSTED_INPUT_RULE,
			'input' => [['role' => 'user', 'content' => [['type' => 'input_text', 'text' => json_encode($this->userInput($request), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]]]],
			'text' => ['format' => [
				'type' => 'json_schema',
				'name' => 'cf7aiv_classification',
				'strict' => true,
				'schema' => ResultValidator::jsonSchema($request),
			]],
			'max_output_tokens' => $this->tokenBudget(
				(string) $effort,
				$request->policySchema === \CF7AIV\Classification\PolicyValidator::SCHEMA_V2 ? self::CLASSIFICATION_ANSWER_TOKENS : self::V1_CLASSIFICATION_ANSWER_TOKENS,
			),
		];
		$reasoningPayload = $this->reasoningPayload($effort);
		if ($reasoningPayload !== null) {
			$payload['reasoning'] = $reasoningPayload;
		}
		return $this->transport->post($this->baseUrl, [
			'headers' => $this->headers($this->apiKey),
			'timeout' => $this->requestTimeout($this->timeout, $effort),
			'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
		]);
	}

	protected function extractText(array $body): string {
		if (isset($body['error'])) { throw new PermanentProviderException('OpenAI returned an application error.'); }
		if (isset($body['output_text']) && is_string($body['output_text'])) { return $body['output_text']; }
		$text = [];
		foreach (($body['output'] ?? []) as $item) {
			if (! is_array($item)) { continue; }
			if (($item['type'] ?? '') === 'refusal') { throw new PermanentProviderException('OpenAI refused the classification request.'); }
			foreach (($item['content'] ?? []) as $content) {
				if (! is_array($content)) { continue; }
				if (($content['type'] ?? '') === 'refusal' || isset($content['refusal'])) { throw new PermanentProviderException('OpenAI refused the classification request.'); }
				if (($content['type'] ?? '') === 'output_text') {
					$value = $content['text'] ?? '';
					if (is_string($value)) { $text[] = $value; }
					elseif (is_array($value) && is_string($value['value'] ?? null)) { $text[] = $value['value']; }
				}
			}
		}
		return implode("\n", $text);
	}

	protected function extractUsage(array $body): array {
		$usage = is_array($body['usage'] ?? null) ? $body['usage'] : [];
		$details = is_array($usage['output_tokens_details'] ?? null) ? $usage['output_tokens_details'] : [];
		return [
			'input_tokens' => $usage['input_tokens'] ?? 0,
			'output_tokens' => $usage['output_tokens'] ?? 0,
			'reasoning_tokens' => $details['reasoning_tokens'] ?? 0,
		];
	}

	/**
	 * Refuse a reply the model did not finish writing.
	 *
	 * The Responses API marks a reply cut short by max_output_tokens, or by a
	 * content filter, as incomplete and may still return partial text. Parsed as
	 * an answer it fails as malformed JSON, and is retried for the same result.
	 *
	 * @param array<string,mixed> $body
	 */
	protected function assertComplete(array $body): void {
		if (($body['status'] ?? '') !== 'incomplete') { return; }
		$reason = preg_replace('/[^a-z_]/', '', strtolower((string) ($body['incomplete_details']['reason'] ?? ''))) ?: 'unknown';
		throw new PermanentProviderException('OpenAI stopped before finishing the response (incomplete: ' . $reason . ').');
	}

	private function assertSuggestionStatus(HttpResponse $response): void {
		if ($response->status >= 200 && $response->status < 300) { return; }
		if ($response->status === 0 || $response->status === 408 || $response->status === 429 || $response->status >= 500) { throw new TransientProviderException('OpenAI suggestion request failed temporarily.'); }
		throw new PermanentProviderException('OpenAI suggestion request failed.');
	}

	private static function supportsReasoning(string $model): bool {
		return isset(self::REASONING_MODELS[strtolower(trim($model))]);
	}

	private static function supportsNone(string $model): bool {
		return isset(self::NONE_REASONING_MODELS[strtolower(trim($model))]);
	}

	/** @return array{effort:string}|null */
	private function reasoningPayload(?string $effort): ?array {
		return match ($effort) {
			'off' => ['effort' => 'none'],
			'standard' => ['effort' => 'medium'],
			'deep' => ['effort' => 'high'],
			default => null,
		};
	}

	private function effectiveReasoningEffort(): ?string {
		$options = self::reasoningOptions($this->model);
		if ($options === ['unsupported']) {
			return null;
		}
		if (in_array($this->reasoningEffort, $options, true)) {
			return $this->reasoningEffort;
		}
		return $options[0] ?? null;
	}
}
