<?php

declare(strict_types=1);

namespace CF7AIV\Providers;

use CF7AIV\Classification\ResultValidator;
use CF7AIV\Contracts\AIProviderInterface;
use CF7AIV\Domain\ClassificationRequest;
use CF7AIV\Domain\ClassificationResult;
use CF7AIV\Infrastructure\HttpResponse;
use InvalidArgumentException;
use JsonException;

abstract class JsonProvider implements AIProviderInterface {
	public function classify(ClassificationRequest $request): ClassificationResult {
		return $this->classifyMode($request, false);
	}

	protected function classifyMode(ClassificationRequest $request, bool $reasoning): ClassificationResult {
		$lastError = null;
		$usage = ['input_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0, 'calls' => 0];
		for ($attempt = 0; $attempt < 2; $attempt++) {
			$response = $this->send($request, $reasoning);
			$usage['calls']++;
			$this->validateStatus($response);
			try {
				$body = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);
				if (! is_array($body)) { throw new InvalidArgumentException('Provider response body is not an object.'); }
				$responseUsage = $this->normalizeUsage($this->extractUsage($body));
				foreach (['input_tokens', 'output_tokens', 'reasoning_tokens'] as $key) {
					$usage[$key] += $responseUsage[$key];
				}
				$text = trim($this->extractText($body));
				if ($text === '') { throw new InvalidArgumentException('Provider returned empty content.'); }
				return ResultValidator::fromJson($text, $request)->withProviderUsage($usage);
			} catch (JsonException|InvalidArgumentException $error) {
				$lastError = $error;
			}
		}
		throw new PermanentProviderException('Provider returned malformed or schema-invalid content after one retry.', 0, $lastError);
	}

	abstract protected function send(ClassificationRequest $request, bool $reasoning): HttpResponse;
	/** @param array<string, mixed> $body */
	abstract protected function extractText(array $body): string;
	/** @param array<string, mixed> $body @return array<string,mixed> */
	abstract protected function extractUsage(array $body): array;

	private function validateStatus(HttpResponse $response): void {
		if ($response->status >= 200 && $response->status < 300) { return; }
		if ($response->status === 408 || $response->status === 429 || $response->status >= 500 || $response->status === 0) {
			throw new TransientProviderException('Provider returned a transient HTTP error (' . $response->status . ').');
		}
		throw new PermanentProviderException('Provider returned a permanent HTTP error (' . $response->status . ').');
	}

	/** @return array<string, string> */
	protected function headers(string $apiKey): array {
		if (trim($apiKey) === '') { throw new PermanentProviderException('Provider API key is not configured.'); }
		return ['Authorization' => 'Bearer ' . $apiKey, 'Content-Type' => 'application/json'];
	}

	/** @return array<string, mixed> */
	protected function userInput(ClassificationRequest $request): array {
		return ['item_id' => $request->itemId, 'policy_version' => $request->policyVersion, 'context' => $request->context];
	}

	/** @param array<string,mixed> $usage @return array{input_tokens:int,output_tokens:int,reasoning_tokens:int,calls:int} */
	private function normalizeUsage(array $usage): array {
		$normalized = ['input_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0, 'calls' => 1];
		foreach (['input_tokens', 'output_tokens', 'reasoning_tokens'] as $key) {
			$value = $usage[$key] ?? 0;
			$normalized[$key] = is_numeric($value) ? max(0, (int) $value) : 0;
		}
		return $normalized;
	}
}
