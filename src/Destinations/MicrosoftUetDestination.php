<?php
declare(strict_types=1);
namespace CF7AIV\Destinations;

use CF7AIV\Contracts\DestinationInterface;
use CF7AIV\Domain\ConversionEvent;
use CF7AIV\Domain\DeliveryResult;
use CF7AIV\Infrastructure\HttpTransportInterface;
use InvalidArgumentException;
use Throwable;

final class MicrosoftUetDestination implements DestinationInterface {
	public function __construct(private readonly HttpTransportInterface $transport, private readonly string $tagId, private readonly string $token, private readonly bool $enhancedMatching = false, private readonly bool $dryRun = false) {}
	public function key(): string { return 'microsoft_uet'; }
	public function send(ConversionEvent $event): DeliveryResult {
		if ($this->tagId === '' || $this->token === '') { throw new InvalidArgumentException('Microsoft UET tag ID and token are required.'); }
		$userData = [];
		if (($event->attribution['msclkid'] ?? '') !== '') { $userData['msclkid'] = (string) $event->attribution['msclkid']; }
		if ($this->enhancedMatching && ($event->consent['ad_user_data'] ?? 'unknown') === 'granted') {
			if (isset($event->userData['email_sha256'])) { $userData['em'] = $event->userData['email_sha256']; }
			if (isset($event->userData['phone_sha256'])) { $userData['ph'] = $event->userData['phone_sha256']; }
		}
		$providerEvent = ['eventType' => 'custom', 'eventId' => $event->eventId, 'eventName' => $event->eventName, 'eventTime' => (int) strtotime($event->occurredAt), 'userData' => $userData, 'customData' => ['value' => $event->value, 'currency' => $event->currency, 'transactionId' => $event->eventId], 'adStorageConsent' => ($event->consent['ad_storage'] ?? 'unknown') === 'granted' ? 'G' : 'D'];
		$body = ['data' => [$providerEvent], 'continueOnValidationError' => false];
		$url = 'https://capi.uet.microsoft.com/v1/' . rawurlencode($this->tagId) . '/events';
		$arguments = ['headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $this->token], 'body' => json_encode($body, JSON_THROW_ON_ERROR), 'timeout' => 10];
		if ($this->dryRun) { $safe = $arguments; $safe['headers']['Authorization'] = 'Bearer [redacted]'; return new DeliveryResult(false, false, null, null, ['url' => $url, 'arguments' => $safe], [], true); }
		try { $response = $this->transport->post($url, $arguments); } catch (Throwable $error) { return MicrosoftUetResponseClassifier::transportFailure($error); }
		return MicrosoftUetResponseClassifier::classify($response, $event->eventId);
	}
}
