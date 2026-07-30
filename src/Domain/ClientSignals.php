<?php

declare(strict_types=1);

namespace CF7AIV\Domain;

final class ClientSignals {
	/** @param array<string, mixed> $signals */
	public function __construct(public readonly array $signals = []) {}
}
