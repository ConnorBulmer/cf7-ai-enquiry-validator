<?php

declare(strict_types=1);

namespace CF7AIV\Contracts;

use CF7AIV\Domain\ClientSignals;
use CF7AIV\Domain\ConsentSnapshot;

interface ConsentResolverInterface {
	public function key(): string;
	public function resolve(ClientSignals $signals): ConsentSnapshot;
}
