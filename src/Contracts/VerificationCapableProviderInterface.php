<?php

declare(strict_types=1);

namespace CF7AIV\Contracts;

use CF7AIV\Domain\ClassificationRequest;
use CF7AIV\Domain\ClassificationResult;

interface VerificationCapableProviderInterface extends AIProviderInterface {
	public function classifyWithReasoning(ClassificationRequest $request): ClassificationResult;
}
