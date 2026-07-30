<?php
declare(strict_types=1);
namespace CF7AIV\Delivery;
final class WpMappingRepository {
	/** @return list<DeliveryMapping> */
	public function publishedForTier(string $tierId): array {
		global $wpdb; if (! isset($wpdb)) { return []; } $table = $wpdb->prefix . 'cf7aiv_mappings';
		$rows = $wpdb->get_results($wpdb->prepare("SELECT mapping.mapping_key AS `key`,mapping.version,mapping.tier_id,mapping.destination_key,mapping.event_name,mapping.event_value AS value,mapping.currency,mapping.status,mapping.enabled FROM {$table} mapping WHERE mapping.tier_id=%s AND mapping.status='published' AND mapping.enabled=1 AND NOT EXISTS (SELECT 1 FROM {$table} newer WHERE newer.mapping_key=mapping.mapping_key AND newer.status='published' AND newer.enabled=1 AND newer.id>mapping.id) ORDER BY mapping.id ASC", $tierId), ARRAY_A);
		$result = []; foreach (is_array($rows) ? $rows : [] as $row) { try { $result[] = DeliveryMapping::fromArray($row); } catch (\InvalidArgumentException) {} } return $result;
	}
}
