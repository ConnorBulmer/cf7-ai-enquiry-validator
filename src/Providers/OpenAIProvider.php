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

final class OpenAIProvider extends JsonProvider implements PolicySuggestionProviderInterface {
	public function __construct(
		private readonly HttpTransportInterface $transport,
		private readonly string $apiKey,
		private readonly string $baseUrl = 'https://api.openai.com/v1/responses',
		private readonly string $model = 'gpt-5.4-mini',
		private readonly int $timeout = 30,
		private readonly string $reasoningEffort = 'unsupported',
	) {}

	public static function reasoningOptions(string $model): array {
		return self::supportsReasoning($model) ? ['off', 'standard', 'deep'] : ['unsupported'];
	}

	public static function reasoningDescription(string $model, string $effort): string {
		if (! self::supportsReasoning($model) || $effort === 'unsupported') {
			return 'Reasoning is unavailable for this OpenAI model or selected configuration.';
		}
		return match ($effort) {
			'standard' => 'Standard OpenAI reasoning uses a bounded medium reasoning effort and may use more tokens.',
			'deep' => 'Deep OpenAI reasoning uses a bounded high reasoning effort and may use more tokens.',
			default => 'Reasoning is off for this OpenAI model.'
		};
	}

	public function key(): string { return 'openai'; }

	public function suggestPolicy(PolicySuggestionRequest $request): PolicySuggestionResult {
		$payload = [
			'model' => $this->model,
			'store' => false,
			'instructions' => 'Suggest compact policy setup facts from only the supplied public local-page and non-identifying form structure. Never infer identities, attribution, secrets, or a publishable policy.',
			'input' => [['role' => 'user', 'content' => [['type' => 'input_text', 'text' => $request->providerProfileJson()]]]],
			'text' => ['format' => ['type' => 'json_schema', 'name' => 'cf7aiv_policy_suggestions', 'strict' => true, 'schema' => PolicySuggestionResult::jsonSchema($request->allowedSourceRefs())]],
			'max_output_tokens' => 1500,
		];
		$response = $this->transport->post($this->baseUrl, [
			'headers' => $this->headers($this->apiKey),
			'timeout' => max(5, min(120, $this->timeout)),
			'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
		]);
		$this->assertSuggestionStatus($response);
		$body = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);
		if (! is_array($body)) { throw new PermanentProviderException('OpenAI returned a malformed suggestion response.'); }
		return PolicySuggestionResult::fromJson(trim($this->extractText($body)));
	}

	protected function send(ClassificationRequest $request, bool $reasoning): HttpResponse {
		$payload = [
			'model' => $this->model,
			'store' => false,
			'instructions' => $request->systemPrompt,
			'input' => [['role' => 'user', 'content' => [['type' => 'input_text', 'text' => json_encode($this->userInput($request), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]]]],
			'text' => ['format' => [
				'type' => 'json_schema',
				'name' => 'cf7aiv_classification',
				'strict' => true,
				'schema' => ResultValidator::jsonSchema($request),
			]],
			'max_output_tokens' => $request->policySchema === \CF7AIV\Classification\PolicyValidator::SCHEMA_V2 ? 900 : 500,
		];
		if (self::supportsReasoning($this->model) && in_array($this->reasoningEffort, ['standard', 'deep'], true)) {
			$payload['reasoning'] = ['effort' => $this->reasoningEffort === 'deep' ? 'high' : 'medium'];
		}
		return $this->transport->post($this->baseUrl, [
			'headers' => $this->headers($this->apiKey),
			'timeout' => max(5, min(120, $this->timeout)),
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

	private function assertSuggestionStatus(HttpResponse $response): void {
		if ($response->status >= 200 && $response->status < 300) { return; }
		if ($response->status === 0 || $response->status === 408 || $response->status === 429 || $response->status >= 500) { throw new TransientProviderException('OpenAI suggestion request failed temporarily.'); }
		throw new PermanentProviderException('OpenAI suggestion request failed.');
	}

	private static function supportsReasoning(string $model): bool {
		return preg_match('/^(?:o[1-4](?:$|[-.])|gpt-5(?:$|[-.]))/i', trim($model)) === 1;
	}
}
