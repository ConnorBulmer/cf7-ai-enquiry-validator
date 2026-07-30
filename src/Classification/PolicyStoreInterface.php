<?php

declare(strict_types=1);

namespace CF7AIV\Classification;

interface PolicyStoreInterface {
	/** @param array<string, mixed> $record */
	public function save(array $record): int;
	public function publish(string $policyKey, string $version): bool;
	/** @param list<string> $policyKeys @return array<string, mixed>|null */
	public function published(array $policyKeys): ?array;
}
