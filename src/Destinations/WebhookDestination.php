<?php
declare(strict_types=1);
namespace CF7AIV\Destinations;

use CF7AIV\Contracts\DestinationInterface;
use CF7AIV\Delivery\ResponseClassifier;
use CF7AIV\Domain\ConversionEvent;
use CF7AIV\Domain\DeliveryResult;
use CF7AIV\Infrastructure\HttpTransportInterface;
use InvalidArgumentException;
use Throwable;

/** Receivers should reject stale timestamps and retain event IDs to prevent replay. */
final class WebhookDestination implements DestinationInterface {
	public function __construct(private readonly HttpTransportInterface $transport, private readonly string $url, private readonly string $secret, private readonly bool $dryRun = false, private readonly ?int $now = null, private readonly bool $allowInsecure = false) {}
	public function key(): string { return 'webhook'; }
	public function send(ConversionEvent $event): DeliveryResult {
		if ($this->secret === '' || $this->url === '') { throw new InvalidArgumentException('Webhook URL and secret are required.'); }
		if (! $this->allowInsecure && strtolower((string) parse_url($this->url, PHP_URL_SCHEME)) !== 'https') { throw new InvalidArgumentException('Webhook URL must use HTTPS.'); }
		$timestamp = (string) ($this->now ?? time()); $body = json_encode($event->toArray(), JSON_THROW_ON_ERROR);
		$arguments = ['headers' => ['Content-Type' => 'application/json', 'X-CF7AIV-Event-ID' => $event->eventId, 'X-CF7AIV-Timestamp' => $timestamp, 'X-CF7AIV-Signature' => 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $this->secret)], 'body' => $body, 'timeout' => 10];
		if ($this->dryRun) { $safe = $arguments; $safe['headers']['X-CF7AIV-Signature'] = 'sha256=[redacted]'; return new DeliveryResult(false, false, null, null, ['url' => $this->url, 'arguments' => $safe], [], true); }
		try { return ResponseClassifier::classify($this->transport->post($this->url, $arguments)); } catch (Throwable $error) { return ResponseClassifier::transportFailure($error); }
	}
}
