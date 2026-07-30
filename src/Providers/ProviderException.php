<?php

declare(strict_types=1);

namespace CF7AIV\Providers;

use RuntimeException;

abstract class ProviderException extends RuntimeException {
	abstract public function isTransient(): bool;
}
