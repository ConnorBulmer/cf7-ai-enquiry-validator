<?php

declare(strict_types=1);

namespace CF7AIV\Infrastructure;

interface HttpTransportInterface {
	/** @param array<string, mixed> $arguments */
	public function post(string $url, array $arguments): HttpResponse;
}
