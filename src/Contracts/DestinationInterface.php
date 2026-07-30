<?php

declare(strict_types=1);

namespace CF7AIV\Contracts;

use CF7AIV\Domain\ConversionEvent;
use CF7AIV\Domain\DeliveryResult;

interface DestinationInterface {
	public function key(): string;
	public function send(ConversionEvent $event): DeliveryResult;
}
