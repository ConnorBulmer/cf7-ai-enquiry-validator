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
delete_option('cf7aiv_consent_override_audit');
