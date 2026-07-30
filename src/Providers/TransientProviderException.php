<?php

declare(strict_types=1);

namespace CF7AIV\Providers;

final class TransientProviderException extends ProviderException {
	public function isTransient(): bool { return true; }
}
