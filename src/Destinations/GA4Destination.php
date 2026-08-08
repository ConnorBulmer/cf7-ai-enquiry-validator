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

final class GA4Destination implements DestinationInterface {
	public function __construct(private readonly HttpTransportInterface $transport, private readonly string $measurementId, private readonly string $apiSecret, private readonly bool $debug = false, private readonly bool $enhancedMatching = false, private readonly bool $dryRun = false, private readonly ?int $now = null) {}
	public function key(): string { return 'ga4'; }
	public function send(ConversionEvent $event): DeliveryResult {
		if ($this->measurementId === '' || $this->apiSecret === '') { throw new InvalidArgumentException('GA4 measurement ID and API secret are required.'); }
		$warnings = []; $now = $this->now ?? time(); $occurred = strtotime($event->occurredAt);
		$params = ['event_id' => $event->eventId, 'value' => $event->value, 'currency' => $event->currency, 'engagement_time_msec' => 1];
		if (($event->attribution['session_id'] ?? '') !== '') { $params['session_id'] = (string) $event->attribution['session_id']; }
		$sessionStarted = strtotime((string) ($event->attribution['session_started_at'] ?? ''));
		if (($event->attribution['session_id'] ?? '') === '' || $sessionStarted === false || $now - $sessionStarted > 86400) { $warnings[] = 'GA4 session attribution requires session_id and dispatch within 24 hours of session start.'; }
		$body = ['client_id' => (string) ($event->attribution['client_id'] ?? $event->eventId), 'events' => [['name' => $event->eventName, 'params' => $params]], 'consent' => ['ad_user_data' => self::consent($event->consent['ad_user_data'] ?? 'unknown'), 'ad_personalization' => self::consent($event->consent['ad_personalization'] ?? 'unknown')]];
		if ($this->debug) { $body['validation_behavior'] = 'ENFORCE_RECOMMENDATIONS'; }
		if ($occurred !== false && $occurred <= $now && $now - $occurred <= 72 * 3600) { $body['timestamp_micros'] = $occurred * 1000000; }
		else { $warnings[] = 'GA4 Ads-export event timestamps must be within the last 72 hours.'; }
		if ($this->enhancedMatching && ($event->consent['ad_user_data'] ?? 'unknown') === 'granted') {
			if (isset($event->userData['email_sha256'])) { $body['user_data']['sha256_email_address'] = $event->userData['email_sha256']; }
			if (isset($event->userData['phone_sha256'])) { $body['user_data']['sha256_phone_number'] = $event->userData['phone_sha256']; }
		}
		$path = $this->debug ? '/debug/mp/collect' : '/mp/collect';
		$url = 'https://www.google-analytics.com' . $path . '?measurement_id=' . rawurlencode($this->measurementId) . '&api_secret=' . rawurlencode($this->apiSecret);
		$arguments = ['headers' => ['Content-Type' => 'application/json'], 'body' => json_encode($body, JSON_THROW_ON_ERROR), 'timeout' => 10];
		if ($this->dryRun) { return new DeliveryResult(false, false, null, null, ['url' => preg_replace('/api_secret=[^&]+/', 'api_secret=[redacted]', $url), 'arguments' => $arguments], $warnings, true); }
		try { $response = $this->transport->post($url, $arguments); } catch (Throwable $error) { $result = ResponseClassifier::transportFailure($error); return new DeliveryResult(false, $result->retryable, null, $result->error, [], $warnings); }
		$result = ResponseClassifier::classify($response);
		if ($result->successful && $this->debug) {
			$decoded = json_decode($response->body, true);
			if (! is_array($decoded)) { return new DeliveryResult(false, false, null, 'GA4 debug validation returned an unreadable response.', [], $warnings); }
			if (! empty($decoded['validationMessages'])) {
				return new DeliveryResult(false, false, null, self::validationFailure($decoded['validationMessages']), [], $warnings);
			}
		}
		return new DeliveryResult($result->successful, $result->retryable, $result->remoteId, $result->error, [], $warnings);
	}
	/**
	 * Report what GA4 objected to, rather than only that it objected.
	 *
	 * The debug endpoint exists precisely to say what is wrong - a mismatched
	 * API secret, an unknown event name, a malformed client id all come back
	 * named. Collapsing them into "validation failed" threw away the entire
	 * value of calling it, and left a destination that refuses to leave dry-run
	 * for reasons the operator could not see.
	 *
	 * Google's own text is used, bounded and stripped of anything that is not
	 * plain description, because it is displayed to an administrator.
	 *
	 * @param mixed $messages
	 */
	private static function validationFailure(mixed $messages): string {
		$parts = [];
		foreach (is_array($messages) ? array_slice($messages, 0, 3) : [] as $message) {
			if (! is_array($message)) { continue; }
			$field = isset($message['fieldPath']) && is_scalar($message['fieldPath']) ? trim((string) $message['fieldPath']) : '';
			$code = isset($message['validationCode']) && is_scalar($message['validationCode']) ? trim((string) $message['validationCode']) : '';
			$description = isset($message['description']) && is_scalar($message['description']) ? trim((string) $message['description']) : '';
			$detail = trim(($field !== '' ? $field . ': ' : '') . $description . ($code !== '' ? ' [' . $code . ']' : ''));
			$detail = (string) preg_replace('/[^\P{C}\n]+/u', '', $detail);
			$detail = trim((string) preg_replace('/\s+/', ' ', $detail));
			if ($detail !== '') { $parts[] = $detail; }
		}
		return $parts === []
			? 'GA4 debug validation failed and returned no explanation.'
			: substr('GA4 rejected the event. ' . implode(' | ', $parts), 0, 400);
	}

	private static function consent(mixed $state): string { return $state === 'granted' ? 'GRANTED' : 'DENIED'; }
}
