<?php

declare(strict_types=1);

namespace CF7AIV\Contracts;

use CF7AIV\Domain\ClassificationRequest;
use CF7AIV\Domain\ClassificationResult;

interface AIProviderInterface {
	public function key(): string;
	public function classify(ClassificationRequest $request): ClassificationResult;
}
