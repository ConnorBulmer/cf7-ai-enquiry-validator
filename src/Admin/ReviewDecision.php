<?php
declare(strict_types=1);
namespace CF7AIV\Admin;
final class ReviewDecision { /** @param list<string> $warnings */ public function __construct(public readonly string $state, public readonly bool $dispatched, public readonly array $warnings = []) {} }
