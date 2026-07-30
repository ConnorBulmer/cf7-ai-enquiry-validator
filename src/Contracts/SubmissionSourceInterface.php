<?php

declare(strict_types=1);

namespace CF7AIV\Contracts;

interface SubmissionSourceInterface {
	public function key(): string;

	/** @return array<string, mixed>|null */
	public function get(string $reference): ?array;

	/** @return iterable<array<string, mixed>> */
	public function iterate(array $arguments = []): iterable;
}
