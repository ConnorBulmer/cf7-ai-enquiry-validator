<?php
declare(strict_types=1);
namespace CF7AIV\Delivery;
use CF7AIV\Domain\ConversionEvent;
use CF7AIV\Domain\DeliveryResult;
use CF7AIV\Infrastructure\Crypto;
final class WpDeliveryStore implements DeliveryStoreInterface {
	public const RESUMED_OPTION = 'cf7aiv_resumed_destinations';
	public static function item(int $id): ?array { global $wpdb; if (! isset($wpdb)) { return null; } $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}cf7aiv_items WHERE id=%d", $id), ARRAY_A); return is_array($row) ? $row : null; }
	public function claim(int $itemId, DeliveryMapping $mapping, ConversionEvent $event): array {
		global $wpdb; $table = $wpdb->prefix . 'cf7aiv_deliveries';
		if ($this->sentUnderAnotherVersion($itemId, $mapping)) { return ['acquired' => false]; }
		$wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$table} (item_id,destination_key,mapping_key,idempotency_key,state,attempts,request_encrypted,created_at,updated_at) VALUES (%d,%s,%s,%s,'pending',0,%s,UTC_TIMESTAMP(),UTC_TIMESTAMP())", $itemId, $mapping->destinationKey, $mapping->key . ':' . $mapping->version, $event->idempotencyKey, Crypto::encrypt(json_encode($event->toArray(), JSON_THROW_ON_ERROR))));
		$leaseToken = bin2hex(random_bytes(32));
		$wpdb->query($wpdb->prepare("UPDATE {$table} SET state='sending',lease_token=%s,lease_expires_at=DATE_ADD(UTC_TIMESTAMP(), INTERVAL 2 MINUTE),updated_at=UTC_TIMESTAMP() WHERE idempotency_key=%s AND (state IN ('pending','retrying','dry_run') OR (state='sending' AND lease_expires_at < UTC_TIMESTAMP()))", $leaseToken, $event->idempotencyKey));
		$acquired = (int) ($wpdb->rows_affected ?? 0) === 1;
		$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE idempotency_key=%s", $event->idempotencyKey), ARRAY_A);
		if (! is_array($row)) { return ['acquired' => false]; }
		$row['acquired'] = $acquired;
		if ($acquired) { $row['lease_token'] = $leaseToken; } else { unset($row['lease_token']); }
		return $row;
	}
	public function record(int $deliveryId, DeliveryResult $result, string $leaseToken = ''): int {
		global $wpdb; $table = $wpdb->prefix . 'cf7aiv_deliveries'; $state = $result->dryRun ? 'dry_run' : ($result->successful ? 'delivered' : ($result->retryable ? 'retrying' : 'failed')); $error = $result->error === null ? null : substr($result->error, 0, 500);
		$wpdb->query($wpdb->prepare("UPDATE {$table} SET state=%s,attempts=attempts+1,response_id=%s,last_error=%s,lease_token=NULL,lease_expires_at=NULL,updated_at=UTC_TIMESTAMP() WHERE id=%d AND state='sending' AND lease_token=%s", $state, $result->remoteId, $error, $deliveryId, $leaseToken)); return (int) $wpdb->get_var($wpdb->prepare("SELECT attempts FROM {$table} WHERE id=%d", $deliveryId));
	}
	public function pause(string $destinationKey, string $sanitizedReason): void { $paused = function_exists('get_option') ? (array) get_option('cf7aiv_paused_destinations', []) : []; $paused[$destinationKey] = ['reason' => substr($sanitizedReason, 0, 500), 'paused_at' => gmdate(DATE_ATOM)]; if (function_exists('update_option')) { update_option('cf7aiv_paused_destinations', $paused, false); } }
	/**
	 * One conversion per item and mapping, whichever version of the mapping sent it.
	 *
	 * The idempotency key includes the mapping version, so publishing a new
	 * version gave an already-delivered item a fresh key, and the next retry or
	 * re-dispatch for that item sent the conversion a second time.
	 *
	 * The other versions are read from the mappings table rather than matched by
	 * pattern, because a mapping key may itself contain the ':' that separates
	 * key from version. This is a check before the claim, not part of it: two
	 * workers straddling a publish can still both pass, which the per-key unique
	 * index cannot stop because their keys differ.
	 */
	private function sentUnderAnotherVersion(int $itemId, DeliveryMapping $mapping): bool {
		global $wpdb;
		if (! method_exists($wpdb, 'get_col')) { return false; }
		$versions = (array) $wpdb->get_col($wpdb->prepare("SELECT version FROM {$wpdb->prefix}cf7aiv_mappings WHERE mapping_key=%s", $mapping->key));
		$others = [];
		foreach ($versions as $version) {
			if (is_scalar($version) && (string) $version !== $mapping->version) { $others[] = $mapping->key . ':' . $version; }
		}
		if ($others === []) { return false; }
		$placeholders = implode(',', array_fill(0, count($others), '%s'));
		return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}cf7aiv_deliveries WHERE item_id=%d AND destination_key=%s AND mapping_key IN ({$placeholders}) AND (state='delivered' OR (state='sending' AND lease_expires_at >= UTC_TIMESTAMP()))", $itemId, $mapping->destinationKey, ...$others)) > 0;
	}
	/**
	 * Permanent failures in the current run of bad luck, not in the table's history.
	 *
	 * This counted every failure the destination had ever had. Unrelated
	 * rejections months apart, with successes between them, paused it, and
	 * because the count never fell every later rejection paused it again. Only
	 * failures since the destination last delivered or was last resumed count.
	 * There is deliberately no time window: a quiet site whose credential was
	 * revoked fails every send, and must still pause and say why.
	 */
	public function permanentFailures(string $destinationKey): int {
		global $wpdb;
		if (! isset($wpdb)) { return 0; }
		$table = $wpdb->prefix . 'cf7aiv_deliveries';
		$since = '0000-00-00 00:00:00';
		$resumed = function_exists('get_option') ? (string) (((array) get_option(self::RESUMED_OPTION, []))[$destinationKey] ?? '') : '';
		$delivered = (string) $wpdb->get_var($wpdb->prepare("SELECT MAX(updated_at) FROM {$table} WHERE destination_key=%s AND state='delivered'", $destinationKey));
		foreach ([$resumed, $delivered] as $candidate) { if ($candidate > $since) { $since = $candidate; } }
		return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE destination_key=%s AND state='failed' AND updated_at > %s", $destinationKey, $since));
	}
	/** Lift an automatic pause. Failures before this point no longer count toward the next one. */
	public function resume(string $destinationKey): bool {
		if (! function_exists('get_option') || ! function_exists('update_option')) { return false; }
		$paused = (array) get_option('cf7aiv_paused_destinations', []);
		if (! array_key_exists($destinationKey, $paused)) { return false; }
		unset($paused[$destinationKey]);
		update_option('cf7aiv_paused_destinations', $paused, false);
		$resumed = (array) get_option(self::RESUMED_OPTION, []);
		$resumed[$destinationKey] = gmdate('Y-m-d H:i:s');
		update_option(self::RESUMED_OPTION, $resumed, false);
		return true;
	}
	public function isPaused(string $destinationKey): bool { $paused = function_exists('get_option') ? (array) get_option('cf7aiv_paused_destinations', []) : []; return isset($paused[$destinationKey]); }
}
