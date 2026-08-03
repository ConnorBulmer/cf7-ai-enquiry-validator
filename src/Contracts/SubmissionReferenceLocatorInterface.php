<?php

declare(strict_types=1);

namespace CF7AIV\Contracts;

interface SubmissionReferenceLocatorInterface {
	public function findReferenceBySubmissionId(string $submissionId, int $formId, string $capturedAt): ?string;
}
