<?php

declare(strict_types=1);

namespace CF7AIV\Destinations;

use CF7AIV\Delivery\ResponseClassifier;
use CF7AIV\Domain\DeliveryResult;
use CF7AIV\Infrastructure\HttpResponse;
use Throwable;

final class MicrosoftUetResponseClassifier {
	public static function classify(HttpResponse $response, string $eventId): DeliveryResult {
		$providerError = self::providerError($response->body);
		if ($response->status >= 200 && $response->status < 300) {
			if ($providerError !== null) {
				return new DeliveryResult(false, false, null, self::errorMessage('Microsoft UET rejected event', $providerError));
			}
			return new DeliveryResult(true, false, substr($eventId, 0, 191));
		}

		$retryable = in_array($response->status, [408, 425, 429], true) || $response->status >= 500;
		$prefix = 'Microsoft UET returned HTTP ' . $response->status;
		$error = $providerError === null ? $prefix . '.' : self::errorMessage($prefix, $providerError);
		return new DeliveryResult(false, $retryable, null, $error);
	}

	public static function transportFailure(Throwable $error): DeliveryResult {
		return ResponseClassifier::transportFailure($error);
	}

	/** @return array{code:string,field:string}|null */
	private static function providerError(string $body): ?array {
		if (trim($body) === '') {
			return null;
		}
		$decoded = json_decode($body, true);
		if (! is_array($decoded)) {
			return null;
		}
		foreach ($decoded as $key => $candidate) {
			if (! is_string($key) || strtolower($key) !== 'error') {
				continue;
			}
			// An OAuth-style rejection names the problem as a bare code, such as
			// {"error":"invalid_token"}, and was otherwise reported as only a
			// status. Only a code-shaped value is kept: free text could carry a
			// token or an address.
			if (is_string($candidate) && preg_match('/^[A-Za-z][A-Za-z_]{0,63}$/', trim($candidate)) === 1) {
				return ['code' => trim($candidate), 'field' => ''];
			}
			if (! is_array($candidate)) {
				continue;
			}
			return [
				'code' => self::safePart(self::scalarForKeys($candidate, ['code', 'errorcode']) ?? 'ValidationError', 64),
				'field' => self::safePart(self::scalarForKeys($candidate, ['field', 'fieldpath', 'propertyname', 'parameter']) ?? '', 128),
			];
		}
		$entry = self::findErrorEntry($decoded);
		if ($entry === null) {
			return null;
		}
		return [
			'code' => self::safePart(self::scalarForKeys($entry, ['code', 'errorcode']) ?? 'ValidationError', 64),
			'field' => self::safePart(self::scalarForKeys($entry, ['field', 'fieldpath', 'propertyname', 'parameter']) ?? '', 128),
		];
	}

	/** @param array<mixed> $value @return array<mixed>|null */
	private static function findErrorEntry(array $value): ?array {
		foreach ($value as $key => $candidate) {
			$normalized = is_string($key) ? strtolower($key) : '';
			if (in_array($normalized, ['errors', 'partialerrors', 'operationerrors'], true) && is_array($candidate) && $candidate !== []) {
				$entry = array_is_list($candidate) ? ($candidate[0] ?? null) : $candidate;
				return is_array($entry) ? $entry : ['code' => 'ValidationError'];
			}
			if (is_array($candidate)) {
				$nested = self::findErrorEntry($candidate);
				if ($nested !== null) {
					return $nested;
				}
			}
		}
		return null;
	}

	/** @param array<mixed> $value @param list<string> $keys */
	private static function scalarForKeys(array $value, array $keys): string|int|float|null {
		foreach ($value as $key => $candidate) {
			if (is_string($key) && in_array(strtolower($key), $keys, true) && is_scalar($candidate)) {
				return is_bool($candidate) ? null : $candidate;
			}
		}
		foreach ($value as $candidate) {
			if (is_array($candidate)) {
				$nested = self::scalarForKeys($candidate, $keys);
				if ($nested !== null) {
					return $nested;
				}
			}
		}
		return null;
	}

	private static function safePart(string|int|float $value, int $maximum): string {
		$safe = preg_replace('/[^A-Za-z0-9_.\[\]-]/', '', (string) $value) ?? '';
		return substr($safe, 0, $maximum);
	}

	/** @param array{code:string,field:string} $providerError */
	private static function errorMessage(string $prefix, array $providerError): string {
		$details = $providerError['code'] !== '' ? ' code ' . $providerError['code'] : '';
		$details .= $providerError['field'] !== '' ? ' field ' . $providerError['field'] : '';
		return $prefix . ($details === '' ? '.' : ' (' . trim($details) . ').');
	}
}
