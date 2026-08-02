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

final class DeepSeekProvider extends JsonProvider implements VerificationCapableProviderInterface, PolicySuggestionProviderInterface {
	public function __construct(
		private readonly HttpTransportInterface $transport,
		private readonly string $apiKey,
		private readonly string $baseUrl = 'https://api.deepseek.com/chat/completions',
		private readonly string $model = 'deepseek-v4-flash',
		private readonly int $timeout = 30,
	) {}

	public function key(): string { return 'deepseek'; }

	public function suggestPolicy(PolicySuggestionRequest $request): PolicySuggestionResult {
		$schema = json_encode(PolicySuggestionResult::jsonSchema($request->allowedSourceRefs()), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		$payload = [
			'model' => $this->model,
			'messages' => [
				['role' => 'system', 'content' => 'Suggest no more than ' . PolicySuggestionResult::MAX_SUGGESTIONS . ' compact policy setup facts from only the supplied public local-page and non-identifying form structure. Never infer identities, attribution, secrets, or a publishable policy.' . "\nRequired JSON Schema: " . $schema . "\nReturn one valid JSON object matching this schema and no other text."],
				['role' => 'user', 'content' => $request->providerProfileJson()],
			],
			'response_format' => ['type' => 'json_object'],
			'thinking' => ['type' => 'disabled'],
			'temperature' => 0,
			'max_tokens' => 4000,
		];
		$response = $this->transport->post($this->baseUrl, [
			'headers' => $this->headers($this->apiKey),
			'timeout' => max(5, min(120, $this->timeout)),
			'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
		]);
		$this->assertSuggestionStatus($response);
		$body = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);
		if (! is_array($body)) { throw new PermanentProviderException('DeepSeek returned a malformed suggestion response.'); }
		return PolicySuggestionResult::fromJson(trim($this->extractText($body)));
	}

	public function classifyWithReasoning(ClassificationRequest $request): ClassificationResult {
		return $this->classifyMode($request, true);
	}

	protected function send(ClassificationRequest $request, bool $reasoning): HttpResponse {
		$schema = json_encode(ResultValidator::jsonSchema($request), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		$guidance = $this->v2JsonGuidance($request);
		$payload = [
			'model' => $this->model,
			'messages' => [
				['role' => 'system', 'content' => $request->systemPrompt . "\nRequired JSON Schema: " . $schema . $guidance . "\nReturn one valid JSON object matching this schema and no other text."],
				['role' => 'user', 'content' => json_encode($this->userInput($request), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
			],
			'response_format' => ['type' => 'json_object'],
			'thinking' => ['type' => $reasoning ? 'enabled' : 'disabled'],
			'max_tokens' => $reasoning ? 1600 : 900,
		];
		if (! $reasoning) { $payload['temperature'] = 0; }
		return $this->transport->post($this->baseUrl, [
			'headers' => $this->headers($this->apiKey),
			'timeout' => max(5, min(120, $this->timeout)),
			'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
		]);
	}

	private function v2JsonGuidance(ClassificationRequest $request): string {
		if ($request->policySchema !== PolicyValidator::SCHEMA_V2) { return ''; }
		$mapping = json_encode($request->allowedEvidence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		$outcome = $request->allowedOutcomeIds[0] ?? '';
		$tier = $request->allowedTierIds[0] ?? '';
		$fieldKey = array_key_first($request->context);
		$evidence = [];
		$code = array_key_first($request->allowedEvidence);
		if (is_string($fieldKey) && is_string($code)) {
			$value = $request->context[$fieldKey] ?? '';
			if (is_array($value)) {
				$value = current(array_filter($value, static fn (mixed $item): bool => is_scalar($item)));
			}
			$excerpt = trim((string) (is_scalar($value) ? $value : ''));
			$excerpt = preg_replace('/\s+/u', ' ', $excerpt) ?? $excerpt;
			if (strlen($excerpt) > 160) {
				$excerpt = substr($excerpt, 0, 160);
				while ($excerpt !== '' && preg_match('//u', $excerpt) !== 1) { $excerpt = substr($excerpt, 0, -1); }
			}
			if (strlen($excerpt) >= 2) {
				$evidence[] = [
					'dimension' => $request->allowedEvidence[$code],
					'code' => $code,
					'field_key' => $fieldKey,
					'excerpt' => $excerpt,
				];
			}
		}
		$example = [
			'outcome_id' => $outcome,
			'tier_id' => $tier,
			'reason_code' => 'qualified',
			'model_confidence' => 0.5,
			'evidence' => $evidence,
			'missing_evidence' => [],
			'exclusion_codes' => [],
			'ambiguity_flags' => [],
			'summary' => 'Classify using only grounded request evidence.',
		];
		return "\nEvidence code-to-dimension mapping: " . $mapping
			. "\nEach evidence dimension must equal the value mapped from its code exactly."
			. "\nExample JSON output: " . json_encode($example, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
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

	private function assertSuggestionStatus(HttpResponse $response): void {
		if ($response->status >= 200 && $response->status < 300) { return; }
		if ($response->status === 0 || $response->status === 408 || $response->status === 429 || $response->status >= 500) { throw new TransientProviderException('DeepSeek suggestion request failed temporarily.'); }
		throw new PermanentProviderException('DeepSeek suggestion request failed.');
	}
}
