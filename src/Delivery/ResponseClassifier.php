<?php
declare(strict_types=1);
namespace CF7AIV\Delivery;
use CF7AIV\Domain\DeliveryResult;
use CF7AIV\Infrastructure\HttpResponse;
use Throwable;
final class ResponseClassifier {
	public static function classify(HttpResponse $response): DeliveryResult {
		if ($response->status >= 200 && $response->status < 300) { return new DeliveryResult(true); }
		$retry = in_array($response->status, [408, 425, 429], true) || $response->status >= 500;
		return new DeliveryResult(false, $retry, null, 'Destination returned HTTP ' . $response->status . '.');
	}
	public static function transportFailure(Throwable $error): DeliveryResult {
		$message = strtolower($error->getMessage());
		if (str_contains($message, 'timeout') || str_contains($message, 'timed out')) { return new DeliveryResult(false, true, null, 'Destination request timed out.'); }
		return new DeliveryResult(false, true, null, 'Destination request failed in transport.');
	}
}
