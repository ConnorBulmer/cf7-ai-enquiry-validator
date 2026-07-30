<?php
declare(strict_types=1);
namespace CF7AIV\Delivery;
final class ConsentDecision {
	/** @param array<string,scalar> $actual @param array<string,mixed> $audit */
	public function __construct(public readonly bool $permitted, public readonly array $actual, public readonly array $audit = []) {}
}
