<?php
declare(strict_types=1);
namespace CF7AIV\Delivery;
use CF7AIV\Domain\ConversionEvent;
use CF7AIV\Domain\DeliveryResult;
use CF7AIV\Infrastructure\Crypto;
final class WpDeliveryStore implements DeliveryStoreInterface {
	public static function item(int $id): ?array { global $wpdb; if (! isset($wpdb)) { return null; } $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}cf7aiv_items WHERE id=%d", $id), ARRAY_A); return is_array($row) ? $row : null; }
	public function claim(int $itemId, DeliveryMapping $mapping, ConversionEvent $event): array {
		global $wpdb; $table = $wpdb->prefix . 'cf7aiv_deliveries';
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
	public function permanentFailures(string $destinationKey): int { global $wpdb; return isset($wpdb) ? (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}cf7aiv_deliveries WHERE destination_key=%s AND state='failed'", $destinationKey)) : 0; }
	public function isPaused(string $destinationKey): bool { $paused = function_exists('get_option') ? (array) get_option('cf7aiv_paused_destinations', []) : []; return isset($paused[$destinationKey]); }
}
