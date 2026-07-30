<?php
declare(strict_types=1);
namespace CF7AIV\Admin;
final class RetentionService {
	public function cleanup(int $days):int{global $wpdb;if(!isset($wpdb)){return 0;}$days=max(1,min(3650,$days));$cutoff=gmdate('Y-m-d H:i:s',time()-$days*86400);$items=$wpdb->prefix.'cf7aiv_items';$suggestions=$wpdb->prefix.'cf7aiv_review_suggestions';$deliveries=$wpdb->prefix.'cf7aiv_deliveries';$setupSuggestions=$wpdb->prefix.'cf7aiv_setup_suggestions';$runs=$wpdb->prefix.'cf7aiv_calibration_runs';$removed=(int)$wpdb->query($wpdb->prepare("DELETE FROM {$setupSuggestions} WHERE created_at < %s",$cutoff));$removed+=(int)$wpdb->query($wpdb->prepare("DELETE suggestion FROM {$suggestions} suggestion INNER JOIN {$items} item ON item.id=suggestion.item_id WHERE item.created_at < %s",$cutoff));$removed+=(int)$wpdb->query($wpdb->prepare("DELETE delivery FROM {$deliveries} delivery INNER JOIN {$items} item ON item.id=delivery.item_id WHERE item.created_at < %s",$cutoff));$removed+=(int)$wpdb->query($wpdb->prepare("DELETE FROM {$items} WHERE created_at < %s",$cutoff));$removed+=(int)$wpdb->query($wpdb->prepare("DELETE FROM {$runs} WHERE created_at < %s",$cutoff));return $removed;}
}
