<?php
declare(strict_types=1);
namespace CF7AIV\Delivery;

use CF7AIV\Admin\DiagnosticsService;
use CF7AIV\Contracts\DestinationInterface;
use CF7AIV\Domain\DeliveryResult;
use CF7AIV\Hooks;
use CF7AIV\Infrastructure\Crypto;
use CF7AIV\Infrastructure\Queue;
use CF7AIV\Infrastructure\Settings;
use Throwable;

final class DeliveryWorker {
	private $itemResolver; private $mappingResolver; private $destinationResolver; private $settingsResolver; private $signalsResolver; private $enqueue; private $credentialResolver; private $manualApprovalResolver;
	public function __construct(private readonly DeliveryStoreInterface $store, ?callable $itemResolver = null, ?callable $mappingResolver = null, ?callable $destinationResolver = null, ?callable $settingsResolver = null, ?callable $signalsResolver = null, ?callable $enqueue = null, ?callable $credentialResolver = null, ?callable $manualApprovalResolver = null) {
		$this->itemResolver = $itemResolver ?? static fn (int $id): ?array => WpDeliveryStore::item($id);
		$this->mappingResolver = $mappingResolver ?? static fn (string $tier): array => (new WpMappingRepository())->publishedForTier($tier);
		$this->destinationResolver = $destinationResolver ?? static fn (): array => function_exists('apply_filters') ? (array) apply_filters('cf7aiv_destinations', []) : [];
		$this->settingsResolver = $settingsResolver ?? static fn (): array => function_exists('get_option') ? (array) get_option(Settings::OPTION, Settings::defaults()) : Settings::defaults();
		$this->signalsResolver = $signalsResolver ?? static fn (array $item): array => self::signals($item);
		$this->enqueue = $enqueue ?? static function (string $hook, array $args, int $delay): void { if ($delay > 0) { Queue::schedule($hook, time() + $delay, $args); } else { Queue::enqueue($hook, $args); } };
		$this->credentialResolver = $credentialResolver ?? static fn (string $name): string => Crypto::secret($name);
		$this->manualApprovalResolver = $manualApprovalResolver ?? static fn (array $item): bool => self::auditedManualApproval($item);
	}
	public static function standard(): self { return new self(new WpDeliveryStore()); }
	public function process(int $itemId): void {
		$item = ($this->itemResolver)($itemId);
		if (! is_array($item)) { $this->gate($itemId, '', 'item_not_found'); return; }
		if (($item['tier_id'] ?? '') === '') { $this->gate($itemId, '', 'tier_not_classified'); return; }
		if (! array_key_exists('is_shadow', $item) || (int) $item['is_shadow'] !== 0) { $this->gate($itemId, '', 'shadow_item'); return; }
		if (! empty($item['is_calibration']) || ! empty($item['is_historical'])) { $this->gate($itemId, '', 'calibration_or_historical_item'); return; }
		$settings = ($this->settingsResolver)();
		$automatic = ($item['decision_state'] ?? '') === 'strong_match';
		if ($automatic && ($settings['automation']['verification_mode'] ?? 'off') === 'automatic_candidates') {
			$automatic = ($item['verification_state'] ?? '') === 'passed';
		}
		$reviewedTier = trim((string) ($item['reviewed_tier_id'] ?? ''));
		$reviewedOutcome = trim((string) ($item['reviewed_outcome_id'] ?? ''));
		$approvalId = trim((string) ($item['dispatch_approval_id'] ?? ''));
		$manual = ($item['review_state'] ?? '') === 'approved_dispatch'
			&& $reviewedTier !== ''
			&& hash_equals((string) $item['tier_id'], $reviewedTier)
			&& $reviewedOutcome !== ''
			&& hash_equals((string) ($item['outcome_id'] ?? ''), $reviewedOutcome)
			&& (int) ($item['reviewed_by'] ?? 0) > 0
			&& trim((string) ($item['reviewed_at'] ?? '')) !== ''
			&& preg_match('/^[a-f0-9]{64}$/', $approvalId) === 1;
		if ($manual) {
			try { $manual = (bool) ($this->manualApprovalResolver)($item); }
			catch (Throwable) { $manual = false; }
		}
		if (! $automatic && ! $manual) { $this->gate($itemId, '', 'not_approved'); return; }
		[$attribution, $consent] = ($this->signalsResolver)($item); $destinations = ($this->destinationResolver)();
		$mappings = ($this->mappingResolver)((string) $item['tier_id']);
		if ($mappings === []) { $this->gate($itemId, '', 'no_published_mapping'); return; }
		foreach ($mappings as $mapping) {
			$destinationKey = $mapping instanceof DeliveryMapping ? $mapping->destinationKey : '';
			if (! $mapping instanceof DeliveryMapping || ! $mapping->isPublished()) { $this->gate($itemId, $destinationKey, 'mapping_not_published'); continue; }
			if ($this->store->isPaused($destinationKey)) { $this->gate($itemId, $destinationKey, 'destination_paused'); continue; }
			$destination = $destinations[$destinationKey] ?? null;
			if (! $destination instanceof DestinationInterface || ! DestinationReadiness::configured($destinationKey, $settings, $this->credentialResolver, $destination)) { $this->gate($itemId, $destinationKey, 'destination_not_configured'); continue; }
			$required = DestinationReadiness::requiredConsent($destinationKey, $destination);
			try { $decision = ConsentPolicy::evaluate((string) ($settings['consent']['mode'] ?? 'respect'), is_array($consent) ? $consent : [], (array) ($settings['consent']['override'] ?? []), $required); } catch (Throwable) { $this->gate($itemId, $destinationKey, 'consent_evaluation_failed'); continue; }
			if (! $decision->permitted) { $this->gate($itemId, $destinationKey, 'consent_not_permitted'); continue; }
			if (! DestinationReadiness::signalsReady($destinationKey, is_array($attribution) ? $attribution : [], $item, $settings, $decision->actual, $destination)) { $this->gate($itemId, $destinationKey, 'signals_not_ready'); continue; }
			$enhanced = ! empty($settings['privacy']['enhanced_matching']) && ($decision->actual['ad_user_data'] ?? 'unknown') === 'granted';
			$event = EnvelopeBuilder::build($item, $mapping, is_array($attribution) ? $attribution : [], $decision->actual, $enhanced, $decision->audit);
			$delivery = $this->store->claim($itemId, $mapping, $event);
			if (empty($delivery['acquired'])) { $this->gate($itemId, $destinationKey, 'claim_not_acquired'); continue; }
			try { $result = $destination->send($event); } catch (Throwable) { $result = new DeliveryResult(false, false, null, 'Destination configuration is invalid.'); }
			$attempt = $this->store->record((int) ($delivery['id'] ?? 0), $result, (string) ($delivery['lease_token'] ?? ''));
			if (function_exists('do_action')) { do_action(Hooks::AFTER_DELIVERY, (int) ($delivery['id'] ?? 0), $itemId, $mapping->destinationKey, $result); }
			if ($result->retryable && $attempt <= 5) { ($this->enqueue)('cf7aiv_deliver_item', [$itemId], RetryPolicy::delay($attempt)); }
			if (! $result->successful && ! $result->retryable && ! $result->dryRun && $this->store->permanentFailures($mapping->destinationKey) >= max(1, (int) ($settings['delivery']['pause_threshold'] ?? 3))) { $this->store->pause($mapping->destinationKey, $result->error ?? 'Repeated permanent delivery failure.'); }
		}
	}
	private function gate(int $itemId, string $destination, string $reason): void { DiagnosticsService::recordGate($itemId, $destination, $reason); }
	/** @param array<string,mixed> $item */
	private static function auditedManualApproval(array $item): bool {
		global $wpdb;
		if (! isset($wpdb) || ! isset($wpdb->prefix) || ! method_exists($wpdb, 'prepare') || ! method_exists($wpdb, 'get_var')) { return false; }
		$itemId = (int) ($item['id'] ?? 0);
		$administratorId = (int) ($item['reviewed_by'] ?? 0);
		$reviewedTier = trim((string) ($item['reviewed_tier_id'] ?? ''));
		$reviewedOutcome = trim((string) ($item['reviewed_outcome_id'] ?? ''));
		$approvalId = trim((string) ($item['dispatch_approval_id'] ?? ''));
		if ($itemId <= 0 || $administratorId <= 0 || $reviewedTier === '' || $reviewedOutcome === '' || preg_match('/^[a-f0-9]{64}$/', $approvalId) !== 1) { return false; }
		$sql = $wpdb->prepare(
			"SELECT dispatch_approval_id FROM {$wpdb->prefix}cf7aiv_review_suggestions WHERE dispatch_approval_id=%s AND item_id=%d AND administrator_id=%d AND action='approve_dispatch' AND corrected_tier=%s AND reviewed_outcome_id=%s AND status='accepted' LIMIT 1",
			$approvalId,
			$itemId,
			$administratorId,
			$reviewedTier,
			$reviewedOutcome,
		);
		$stored = (string) $wpdb->get_var($sql);
		return $stored !== '' && hash_equals($approvalId, $stored);
	}
	/** @param array<string,mixed> $item @return array{array<string,scalar>,array<string,scalar>} */
	private static function signals(array $item): array {
		$result = [];
		foreach (['attribution_encrypted', 'consent_encrypted'] as $field) { try { $value = json_decode(Crypto::decrypt((string) ($item[$field] ?? '')), true, 32, JSON_THROW_ON_ERROR); $result[] = is_array($value) ? $value : []; } catch (Throwable) { $result[] = []; } }
		return [$result[0], $result[1]['states'] ?? $result[1]];
	}
}
