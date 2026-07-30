<?php

declare(strict_types=1);

namespace CF7AIV\Contracts;

use CF7AIV\Domain\PolicySuggestionRequest;
use CF7AIV\Domain\PolicySuggestionResult;

interface PolicySuggestionProviderInterface {
	public function suggestPolicy(PolicySuggestionRequest $request): PolicySuggestionResult;
}
