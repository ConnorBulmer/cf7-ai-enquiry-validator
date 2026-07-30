<?php

declare(strict_types=1);

namespace CF7AIV\Classification;

use CF7AIV\Domain\ClassificationResult;

interface ClassificationItemStoreInterface {
	/** @return array<string, mixed>|null */
	public function get(int $itemId): ?array;
	public function linkSource(int $itemId, string $sourceType, string $sourceReference): void;
	/**
	 * @param array<string, mixed> $policy
	 * @return bool True only when the intended result is confirmed stored.
	 */
	public function succeed(int $itemId, ClassificationResult $result, array $policy, EvidenceDecision $evidenceDecision, string $verificationState, string $reviewState, bool $isShadow, bool $replacePrediction = false): bool;
	/** @return int|false Persisted attempt number, or false when no failure write was confirmed. */
	public function fail(int $itemId, string $message, bool $transient, bool $replacePrediction = false): int|false;
}
