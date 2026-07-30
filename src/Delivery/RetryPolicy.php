<?php
declare(strict_types=1);
namespace CF7AIV\Delivery;
final class RetryPolicy { public static function delay(int $attempt): int { return min(3600, 60 * (2 ** max(0, min(10, $attempt - 1)))); } }
