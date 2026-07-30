<?php
declare(strict_types=1);
namespace CF7AIV\Delivery;
use CF7AIV\Domain\ConversionEvent;
use CF7AIV\Domain\DeliveryResult;
interface DeliveryStoreInterface {
	/** @return array<string,mixed> */ public function claim(int $itemId, DeliveryMapping $mapping, ConversionEvent $event): array;
	public function record(int $deliveryId, DeliveryResult $result, string $leaseToken = ''): int;
	public function pause(string $destinationKey, string $sanitizedReason): void;
	public function permanentFailures(string $destinationKey): int;
	public function isPaused(string $destinationKey): bool;
}
