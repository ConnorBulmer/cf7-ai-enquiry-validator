<?php
declare(strict_types=1);
namespace CF7AIV\Admin;
interface ReviewStoreInterface {
	/** @return array<string,mixed>|null */ public function get(int $itemId): ?array;
	public function persist(int $itemId, string $reviewState, ?string $reviewedTierId, ?string $reviewedOutcomeId, int $administratorId, int $expectedRevision): bool;
	public function suggest(int $itemId, int $administratorId, string $previousTier, string $correctedTier, string $exampleSummary): void;
	public function approveDispatch(int $itemId, int $administratorId, string $reviewedTierId, string $reviewedOutcomeId, int $expectedRevision): bool;
}
