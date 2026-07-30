<?php

declare(strict_types=1);

namespace CF7AIV\Providers;

final class PermanentProviderException extends ProviderException {
	public function isTransient(): bool { return false; }
}
