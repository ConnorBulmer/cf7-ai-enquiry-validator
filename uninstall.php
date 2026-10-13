<?php

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

$settings = (array) get_option('cf7aiv_settings', []);
if (empty($settings['privacy']['delete_on_uninstall'])) {
	return;
}

global $wpdb;
foreach (['cf7aiv_review_suggestions', 'cf7aiv_deliveries', 'cf7aiv_items', 'cf7aiv_calibration_runs', 'cf7aiv_mappings', 'cf7aiv_setup_suggestions', 'cf7aiv_policies'] as $table) {
	$wpdb->query('DROP TABLE IF EXISTS `' . esc_sql($wpdb->prefix . $table) . '`');
}
delete_option('cf7aiv_settings');
delete_option('cf7aiv_secrets');
delete_option('cf7aiv_schema_version');
delete_option('cf7aiv_paused_destinations');
delete_option('cf7aiv_resumed_destinations');
delete_option('cf7aiv_accuracy_test');
delete_option('cf7aiv_consent_override_audit');
delete_option('cf7aiv_event_log');
delete_option('cf7aiv_ga4_validation');

// Transients: the cached usage totals, and the per-administrator scan,
// connection-test and examples notices (cf7aiv_*_{user id}), which would
// otherwise stay in the options table until something read them after expiry.
delete_transient('cf7aiv_ai_usage_totals');
$options = isset($wpdb->options) ? (string) $wpdb->options : $wpdb->prefix . 'options';
$wpdb->query("DELETE FROM `" . esc_sql($options) . "` WHERE option_name LIKE '\\_transient\\_cf7aiv\\_%' OR option_name LIKE '\\_transient\\_timeout\\_cf7aiv\\_%'");
