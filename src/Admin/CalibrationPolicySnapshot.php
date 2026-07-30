<?php

declare(strict_types=1);

namespace CF7AIV\Admin;

use CF7AIV\Classification\ContextBuilder;
use CF7AIV\Classification\PolicyValidator;

final class CalibrationPolicySnapshot {
	/** @return array{fields:list<string>,fingerprint:string} */
	public static function active(): array {
		global $wpdb;
		$definitions = [];
		if (isset($wpdb->prefix) && method_exists($wpdb, 'get_results')) {
			$rows = $wpdb->get_results(
				"SELECT definition_json FROM {$wpdb->prefix}cf7aiv_policies WHERE status='published' ORDER BY policy_key ASC,version ASC",
				ARRAY_A,
			);
			foreach (is_array($rows) ? $rows : [] as $row) {
				if (is_array($row) && isset($row['definition_json'])) { $definitions[] = $row['definition_json']; }
			}
		}
		return self::fromDefinitions($definitions);
	}

	/** @param array<mixed> $definitions @return array{fields:list<string>,fingerprint:string} */
	public static function fromDefinitions(array $definitions): array {
		$fields = [];
		foreach ($definitions as $definition) {
			try {
				$validated = PolicyValidator::import(is_array($definition) || is_string($definition) ? $definition : []);
				$fields = array_merge($fields, (array) ($validated['ai_whitelist'] ?? []));
			} catch (\Throwable) {
				// Invalid records cannot widen the disclosed or dispatched whitelist.
			}
		}
		$fields = ContextBuilder::safeWhitelist($fields);
		return ['fields' => $fields, 'fingerprint' => self::fingerprint($fields)];
	}

	/** @param array<mixed> $fields */
	public static function fingerprint(array $fields): string {
		$json = json_encode(ContextBuilder::safeWhitelist($fields), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		return hash('sha256', is_string($json) ? $json : '[]');
	}
}
