<?php

declare(strict_types=1);

namespace CF7AIV\Admin;

use CF7AIV\Classification\PolicyRepository;
use CF7AIV\Classification\PolicyValidator;
use CF7AIV\Infrastructure\Settings;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Turn reviewer corrections into examples in a new draft policy.
 *
 * A correction already requires a non-identifying summary of the enquiry and
 * why the corrected tier fits. Those summaries were stored and never used, while
 * every policy's examples list stayed empty. Examples drawn from real mistakes
 * are among the most effective ways to improve a classifier.
 *
 * Nothing here learns on its own. An administrator chooses which corrections
 * to use; the result is a draft, which can be tested against reviewed enquiries
 * and must be published explicitly like any other.
 */
final class CorrectionExamples {
	/** Examples kept in one policy. Each one lengthens every classification prompt. */
	public const MAX_EXAMPLES = 40;

	/** @return list<array<string,mixed>> Unused corrections, newest first. */
	public static function pending(): array {
		global $wpdb;
		if (! isset($wpdb->prefix) || ! method_exists($wpdb, 'get_results')) { return []; }
		$rows = $wpdb->get_results("SELECT id,previous_tier,corrected_tier,example_summary,created_at FROM {$wpdb->prefix}cf7aiv_review_suggestions WHERE action='change_tier' AND status='draft' ORDER BY id DESC LIMIT 50", ARRAY_A);
		return is_array($rows) ? array_values(array_filter($rows, static fn (mixed $row): bool => is_array($row) && (int) ($row['id'] ?? 0) > 0)) : [];
	}

	/**
	 * Add the chosen corrections to a copy of a policy, saved as a new draft.
	 *
	 * @param array<string,mixed> $base A policy row with its validated definition.
	 * @param list<array<string,mixed>> $corrections Rows with id, previous_tier, corrected_tier, example_summary.
	 * @return array{definition:array<string,mixed>,added:list<int>,skipped:list<string>}
	 */
	public static function merge(array $base, array $corrections): array {
		$definition = (array) $base['definition'];
		$tiers = [];
		foreach ((array) ($definition['tier_definitions'] ?? []) as $tier) {
			if (is_array($tier) && is_string($tier['id'] ?? null)) { $tiers[$tier['id']] = true; }
		}
		$examples = array_values((array) ($definition['examples'] ?? []));
		$added = [];
		$skipped = [];
		foreach ($corrections as $correction) {
			$id = (int) ($correction['id'] ?? 0);
			$tier = (string) ($correction['corrected_tier'] ?? '');
			$summary = trim((string) ($correction['example_summary'] ?? ''));
			if ($summary === '' || ! isset($tiers[$tier])) {
				$skipped[] = 'Correction ' . $id . ': its tier is not in this policy.';
				continue;
			}
			$example = ['summary' => $summary, 'tier_id' => $tier];
			$previous = (string) ($correction['previous_tier'] ?? '');
			if ($previous !== '' && $previous !== $tier && isset($tiers[$previous])) { $example['not_tier_id'] = $previous; }
			if (in_array($example, $examples, true)) {
				$skipped[] = 'Correction ' . $id . ': the policy already has this example.';
				continue;
			}
			if (count($examples) >= self::MAX_EXAMPLES) {
				$skipped[] = 'Correction ' . $id . ': the policy already has ' . self::MAX_EXAMPLES . ' examples.';
				continue;
			}
			// Policies refuse prose that names personal data, so a summary that
			// does is skipped with a reason rather than failing the whole draft.
			try {
				PolicyValidator::import(array_merge($definition, ['examples' => array_merge($examples, [$example])]));
			} catch (Throwable) {
				$skipped[] = 'Correction ' . $id . ': its summary mentions personal or tracking data, which policies may not contain.';
				continue;
			}
			$examples[] = $example;
			$added[] = $id;
		}
		$definition['examples'] = $examples;
		return ['definition' => $definition, 'added' => $added, 'skipped' => $skipped];
	}

	/**
	 * @param list<int> $correctionIds
	 * @return array{policy_key:string,version:string,added:list<int>,skipped:list<string>}
	 */
	public static function createDraft(int $basePolicyId, array $correctionIds, string $version, int $administratorId): array {
		global $wpdb;
		$base = AccuracyTest::loadPolicy($basePolicyId);
		if ($base === null) { throw new InvalidArgumentException('Choose the policy to add the examples to.'); }
		$ids = array_values(array_unique(array_filter(array_map('intval', $correctionIds), static fn (int $id): bool => $id > 0)));
		if ($ids === []) { throw new InvalidArgumentException('Choose at least one correction.'); }
		$rows = $wpdb->get_results("SELECT id,previous_tier,corrected_tier,example_summary FROM {$wpdb->prefix}cf7aiv_review_suggestions WHERE action='change_tier' AND status='draft' AND id IN (" . implode(',', $ids) . ')', ARRAY_A);
		$merged = self::merge($base, is_array($rows) ? $rows : []);
		if ($merged['added'] === []) {
			throw new InvalidArgumentException('None of the chosen corrections could be added. ' . implode(' ', array_slice($merged['skipped'], 0, 3)));
		}
		$key = (string) $base['policy_key'];
		// Saving over an existing version would either change nothing (a
		// published policy is never overwritten) or silently replace another
		// draft - and the corrections would be marked used either way.
		$version = substr($version, 0, 64);
		if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}cf7aiv_policies WHERE policy_key=%s AND version=%s", $key, $version)) > 0) {
			throw new InvalidArgumentException('The draft reference "' . $version . '" is already used for this policy. Choose a new one.');
		}
		if ((new PolicyRepository())->saveDraft($key, $version, $merged['definition'], $administratorId) <= 0) {
			throw new RuntimeException('The draft could not be saved, so no corrections were used.');
		}
		$wpdb->query("UPDATE {$wpdb->prefix}cf7aiv_review_suggestions SET status='applied',updated_at=UTC_TIMESTAMP() WHERE id IN (" . implode(',', $merged['added']) . ") AND status='draft'");
		// Offer it for publication in the same place a guided draft is offered.
		$settings = (array) get_option(Settings::OPTION, Settings::defaults());
		$settings['setup']['draft_summary'] = [
			'policy_key' => $key, 'version' => $version,
			'pack_key' => (string) ($merged['definition']['pack']['key'] ?? ''), 'pack_label' => (string) ($merged['definition']['pack']['label'] ?? ''),
			'status' => 'draft', 'saved_at' => gmdate(DATE_ATOM),
		];
		update_option(Settings::OPTION, $settings, false);
		return ['policy_key' => $key, 'version' => $version, 'added' => $merged['added'], 'skipped' => $merged['skipped']];
	}
}
