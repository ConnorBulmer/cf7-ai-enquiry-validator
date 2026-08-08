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
	/**
	 * Answer-only allowances, before any reasoning allowance is added.
	 *
	 * A v2 classification is the larger of the two answers, not the smaller. It
	 * must emit nine required fields, and every evidence entry carries an excerpt
	 * quoted from the enquiry, so the reply grows with how much the enquiry
	 * actually says. At 900 it was being cut off mid-object and reported as
	 * "malformed or schema-invalid content", which reads as the model
	 * misbehaving rather than as a budget that was never large enough.
	 *
	 * Observed on a live site: the same enquiry timed out with reasoning enabled
	 * and returned invalid content with reasoning off, because the two settings
	 * were failing for two different reasons at once.
	 */
	private const SUGGESTION_ANSWER_TOKENS = 1500;
	private const CLASSIFICATION_ANSWER_TOKENS = 1800;

	public function __construct(
		private readonly HttpTransportInterface $transport,
		private readonly string $apiKey,
		private readonly string $baseUrl = 'https://api.deepseek.com/chat/completions',
		private readonly string $model = 'deepseek-v4-flash',
		private readonly int $timeout = 30,
		private readonly string $reasoningEffort = 'off',
	) {}

	public static function reasoningOptions(string $model): array {
		return ['off', 'standard', 'deep'];
	}

	public static function reasoningDescription(string $model, string $effort): string {
		return match ($effort) {
			'standard' => 'Standard reasoning enables DeepSeek thinking with a bounded JSON output budget.',
			'deep' => 'Deep reasoning enables a larger bounded DeepSeek thinking budget and may use more tokens.',
			default => 'Reasoning is off; DeepSeek is limited to the bounded structured JSON output budget.',
		};
	}

	public function key(): string { return 'deepseek'; }

	public function suggestPolicy(PolicySuggestionRequest $request): PolicySuggestionResult {
		$schema = json_encode(PolicySuggestionResult::jsonSchema($request->allowedSourceRefs()), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		$reasoningEffort = $this->reasoningEffort;
		$thinking = match ($reasoningEffort) {
			'standard', 'deep' => ['type' => 'enabled'],
			default => ['type' => 'disabled'],
		};
		$payload = [
			'model' => $this->model,
			'messages' => [
				['role' => 'system', 'content' => 'Suggest no more than ' . PolicySuggestionResult::MAX_SUGGESTIONS . ' compact policy setup facts from only the supplied public local-page and non-identifying form structure. Never infer identities, attribution, secrets, or a publishable policy.' . "\nRequired JSON Schema: " . $schema . "\nReturn one valid JSON object matching this schema and no other text."],
				['role' => 'user', 'content' => $request->providerProfileJson()],
			],
			'response_format' => ['type' => 'json_object'],
			'thinking' => $thinking,
			'max_tokens' => $this->tokenBudget($reasoningEffort, self::SUGGESTION_ANSWER_TOKENS),
		];
		if ($reasoningEffort === 'off') { $payload['temperature'] = 0; }
		$response = $this->transport->post($this->baseUrl, [
			'headers' => $this->headers($this->apiKey),
			'timeout' => max(5, min(300, $this->timeout)),
			'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
		]);
		$this->assertSuggestionStatus($response);
		$body = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);
		if (! is_array($body)) { throw new PermanentProviderException('DeepSeek returned a malformed suggestion response.'); }
		$this->assertComplete($body);
		return PolicySuggestionResult::fromJson(trim($this->extractText($body)));
	}

	public function classifyWithReasoning(ClassificationRequest $request): ClassificationResult {
		return $this->classifyMode($request, true);
	}

	protected function send(ClassificationRequest $request, bool $reasoning): HttpResponse {
		$schema = json_encode(ResultValidator::jsonSchema($request), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		$guidance = $this->v2JsonGuidance($request);
		$reasoningEffort = $reasoning ? 'deep' : $this->reasoningEffort;
		$thinking = match ($reasoningEffort) {
			'standard', 'deep' => ['type' => 'enabled'],
			default => ['type' => 'disabled'],
		};
		$payload = [
			'model' => $this->model,
			'messages' => [
				['role' => 'system', 'content' => $request->systemPrompt . "\nRequired JSON Schema: " . $schema . $guidance . "\nReturn one valid JSON object matching this schema and no other text."],
				['role' => 'user', 'content' => json_encode($this->userInput($request), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
			],
			'response_format' => ['type' => 'json_object'],
			'thinking' => $thinking,
			'max_tokens' => $this->tokenBudget($reasoningEffort, self::CLASSIFICATION_ANSWER_TOKENS),
		];
		if ($reasoningEffort === 'off') { $payload['temperature'] = 0; }
		return $this->transport->post($this->baseUrl, [
			'headers' => $this->headers($this->apiKey),
			'timeout' => max(5, min(300, $this->timeout)),
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
	 * Size max_tokens for the answer and the reasoning separately.
	 *
	 * DeepSeek counts reasoning tokens against max_tokens, so one flat budget is
	 * shared between thinking and the JSON answer. At 900 tokens with thinking
	 * enabled the reasoning could consume the entire allowance and the answer
	 * came back truncated - or empty - which surfaced as "response is malformed"
	 * because a JSON object cut in half does not parse. Twenty suggestions carry
	 * a type, a value and source references each, so the answer alone needs
	 * roughly what OpenAI is already given for the identical call.
	 *
	 * The answer allowance therefore matches the OpenAI provider for the same
	 * task, and thinking is granted its own allowance on top rather than
	 * competing with it.
	 */
	private function tokenBudget(string $reasoningEffort, int $answerTokens): int {
		$reasoningAllowance = match ($reasoningEffort) {
			'deep' => 8000,
			'standard' => 3000,
			default => 0,
		};
		return $answerTokens + $reasoningAllowance;
	}

	/**
	 * Refuse a reply the model did not finish writing.
	 *
	 * A truncated answer is not malformed input, it is an exhausted budget, and
	 * the two need opposite fixes. Without this the JSON decode fails several
	 * layers away and reports the model as having returned nonsense.
	 *
	 * @param array<string,mixed> $body
	 */
	private function assertComplete(array $body): void {
		$reason = $body['choices'][0]['finish_reason'] ?? '';
		if ($reason === 'length') {
			throw new PermanentProviderException('DeepSeek stopped at the token budget before finishing the response (finish_reason: length). Raise the reasoning budget or reduce the request.');
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
