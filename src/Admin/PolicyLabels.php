<?php

declare(strict_types=1);

namespace CF7AIV\Admin;

use CF7AIV\Classification\PolicyValidator;

/**
 * Human labels for the tiers and outcomes of the policy an item was classified under.
 *
 * Review screens showed policy identifiers - ads_primary, legitimate_non_conversion
 * - and asked reviewers to type them back to correct a classification. The
 * policy already names its tiers and outcomes; this reads those names.
 */
final class PolicyLabels {
	/**
	 * Policy definitions by id, decoded but not re-validated: they were validated
	 * when saved, and a label lookup must not fail a page over a stale schema.
	 *
	 * @param list<int> $ids
	 * @return array<int,array<string,mixed>>
	 */
	public static function definitions(array $ids): array {
		global $wpdb;
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
		if ($ids === [] || ! isset($wpdb->prefix) || ! method_exists($wpdb, 'get_results')) { return []; }
		$rows = $wpdb->get_results("SELECT id,definition_json FROM {$wpdb->prefix}cf7aiv_policies WHERE id IN (" . implode(',', $ids) . ')', ARRAY_A);
		$definitions = [];
		foreach (is_array($rows) ? $rows : [] as $row) {
			$definition = json_decode((string) ($row['definition_json'] ?? ''), true);
			if (is_array($definition)) { $definitions[(int) ($row['id'] ?? 0)] = $definition; }
		}
		return $definitions;
	}

	/** @param array<string,mixed> $definition */
	public static function tier(array $definition, string $tierId): string {
		foreach (self::tiers($definition) as $tier) {
			if ($tier['id'] === $tierId) { return $tier['label']; }
		}
		return AdminUi::humanLabel($tierId);
	}

	/** @param array<string,mixed> $definition */
	public static function outcome(array $definition, string $outcomeId): string {
		return self::outcomes($definition)[$outcomeId] ?? AdminUi::humanLabel($outcomeId);
	}

	/**
	 * @param array<string,mixed> $definition
	 * @return list<array{id:string,label:string,outcome_id:string}>
	 */
	public static function tiers(array $definition): array {
		$tiers = [];
		foreach ((array) ($definition['tier_definitions'] ?? []) as $tier) {
			if (! is_array($tier) || ! is_string($tier['id'] ?? null) || $tier['id'] === '') { continue; }
			$label = is_string($tier['label'] ?? null) && trim($tier['label']) !== '' ? trim($tier['label']) : AdminUi::humanLabel($tier['id']);
			$tiers[] = ['id' => $tier['id'], 'label' => $label, 'outcome_id' => is_string($tier['outcome_id'] ?? null) ? $tier['outcome_id'] : ''];
		}
		return $tiers;
	}

	/**
	 * @param array<string,mixed> $definition
	 * @return array<string,string>
	 */
	public static function outcomes(array $definition): array {
		$outcomes = [];
		foreach (PolicyValidator::OUTCOMES as $outcome) {
			$label = $definition['outcome_labels'][$outcome] ?? null;
			$outcomes[$outcome] = is_string($label) && trim($label) !== '' ? trim($label) : AdminUi::humanLabel($outcome);
		}
		return $outcomes;
	}

	/**
	 * Saved draft and published policies, published first, newest first.
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function savedPolicies(): array {
		global $wpdb;
		if (! isset($wpdb->prefix) || ! method_exists($wpdb, 'get_results')) { return []; }
		$rows = $wpdb->get_results("SELECT id,policy_key,version,status FROM {$wpdb->prefix}cf7aiv_policies WHERE status IN ('draft','published') ORDER BY (status='published') DESC,id DESC LIMIT 30", ARRAY_A);
		return is_array($rows) ? array_values(array_filter($rows, static fn (mixed $row): bool => is_array($row) && (int) ($row['id'] ?? 0) > 0)) : [];
	}
}
