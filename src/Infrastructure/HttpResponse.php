<?php

declare(strict_types=1);

namespace CF7AIV\Infrastructure;

final class HttpResponse {
	public function __construct(public readonly int $status, public readonly string $body) {}
}
