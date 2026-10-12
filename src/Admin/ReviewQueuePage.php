<?php

declare(strict_types=1);

namespace CF7AIV\Admin;

use CF7AIV\Infrastructure\Crypto;
use CF7AIV\Infrastructure\Settings;

final class ReviewQueuePage {
	/** Review states, in the order a reviewer meets them. */
	private const REVIEW_STATES = [
		'review' => 'Needs review', 'pending' => 'Pending', 'eligible' => 'Eligible', 'error' => 'Error',
		'confirmed' => 'Confirmed', 'corrected' => 'Corrected', 'approved' => 'Approved',
		'approved_dispatch' => 'Approved and dispatched', 'rejected' => 'Rejected',
	];

	public static function render(): void {
		self::capability();
		$settings = (array) get_option(Settings::OPTION, Settings::defaults());
		$advanced = ! empty($settings['ui']['advanced_mode']);
		$page = max(1, (int) ($_GET['paged'] ?? 1));
		$state = substr(preg_replace('/[^a-z_\-]/', '', (string) ($_GET['review_state'] ?? '')) ?? '', 0, 32);
		$rows = self::rows($page, $state);
		$policies = PolicyLabels::definitions(array_map(static fn (array $row): int => (int) ($row['policy_id'] ?? 0), $rows));

		echo '<div class="wrap cf7aiv-admin"><div class="cf7aiv-page-header"><h1>Review Queue</h1><p>Review classifications, correct outcomes, and control approved delivery.</p></div>';
		if (($_GET['review_error'] ?? '') === '1') {
			$currentState = $state !== '' ? $state : 'review';
			echo '<div class="notice notice-error"><p>Approve-and-dispatch was not completed. Current review state: ' . self::e($currentState) . '.</p></div>';
		}
		echo '<ul class="cf7aiv-decision-legend" aria-label="Decision states"><li><span class="cf7aiv-status-badge is-strong">Strong match</span></li><li><span class="cf7aiv-status-badge is-review">Needs review</span></li><li><span class="cf7aiv-status-badge is-ineligible">Not eligible</span></li></ul>';
		// A list of the states that exist, rather than a box that had to be typed
		// into with the exact internal name.
		echo '<form method="get" class="cf7aiv-toolbar cf7aiv-filter-toolbar"><input type="hidden" name="page" value="cf7aiv-review"><label for="cf7aiv-review-state">Review state</label><select id="cf7aiv-review-state" name="review_state"><option value="">All states</option>';
		foreach (self::REVIEW_STATES as $value => $label) {
			echo '<option value="' . self::e($value) . '"' . ($state === $value ? ' selected' : '') . '>' . self::e($label) . '</option>';
		}
		echo '</select><button type="submit" class="button">Filter</button></form>';
		echo '<form method="post" action="' . self::e(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="cf7aiv_bulk_review">';
		wp_nonce_field('cf7aiv_bulk_review');
		echo '<div class="tablenav top cf7aiv-toolbar cf7aiv-bulk-toolbar"><label for="cf7aiv-review-action">Bulk review action</label><select id="cf7aiv-review-action" name="review_action"><option value="confirm">Confirm prediction</option><option value="approve_no_send">Approve without send</option><option value="approve_dispatch">Approve and dispatch</option><option value="reject">Reject</option><option value="reanalyse">Reanalyse</option></select><button type="submit" class="button">Apply</button></div>';
		/*
		 * The default view keeps what a reviewer decides on: what the AI said and
		 * why, and whether it can be sent. Evidence codes, policy, source and
		 * consent detail are diagnostics, and fifteen columns of them pushed the
		 * summary off the screen. They remain in advanced mode.
		 */
		$headers = ['Select', 'Item', 'Classification', 'Decision state', 'Reason', 'Attribution', 'Review', 'Why it failed', 'Delivery status'];
		if ($advanced) {
			array_push($headers, 'Evidence codes', 'Missing evidence', 'Ambiguity', 'Policy', 'Source', 'Consent', 'AI diagnostic, not dispatch score.');
		}
		ob_start();
		echo '<table class="widefat striped cf7aiv-review-table"><thead><tr>';
		foreach ($headers as $header) { echo '<th scope="col">' . self::e($header) . '</th>'; }
		echo '</tr></thead><tbody>';
		foreach ($rows as $row) {
			$id = (int) ($row['id'] ?? 0);
			$definition = $policies[(int) ($row['policy_id'] ?? 0)] ?? [];
			$decision = self::decision((string) ($row['decision_state'] ?? ''));
			$revision=max(0,(int)($row['review_revision']??0));
			$reviewState = (string) ($row['review_state'] ?? '');
			echo '<tr><td><label class="screen-reader-text" for="cf7aiv-item-' . $id . '">Select item ' . $id . '</label><input id="cf7aiv-item-' . $id . '" type="checkbox" name="item_ids[]" value="' . $id . '"><input type="hidden" name="review_revisions['.$id.']" value="'.$revision.'"></td>';
			echo '<td><a href="' . self::e(self::detailUrl($id)) . '">Review item ' . $id . '</a>' . (($row['created_at'] ?? '') !== '' ? '<br><span class="description">' . self::e((string) $row['created_at']) . '</span>' : '') . '</td>';
			echo '<td>' . self::classification($definition, (string) ($row['tier_id'] ?? ''), (string) ($row['outcome_id'] ?? '')) . '</td>';
			echo '<td><span class="cf7aiv-status-badge ' . self::e($decision['class']) . '">' . self::e($decision['label']) . '</span></td>';
			echo '<td>' . self::e(AdminUi::humanLabel((string) ($row['reason_code'] ?? ''))) . '</td>';
			echo '<td>' . (! empty($row['attribution_ready']) ? 'Ready' : 'Unavailable') . '</td>';
			echo '<td>' . self::e(self::REVIEW_STATES[$reviewState] ?? AdminUi::humanLabel($reviewState)) . '</td>';
			echo '<td>' . self::e(self::failureReason($reviewState, (string) ($row['last_error'] ?? ''))) . '</td>';
			echo '<td>' . self::e(AdminUi::humanLabel((string) ($row['destination_status'] ?? 'not sent'))) . '</td>';
			if ($advanced) {
				echo '<td>' . self::e(implode(', ', self::codes($row['evidence_json'] ?? null, true))) . '</td>';
				echo '<td>' . self::e(implode(', ', self::codes($row['missing_evidence_json'] ?? null))) . '</td>';
				echo '<td>' . self::e(implode(', ', self::codes($row['ambiguity_json'] ?? null))) . '</td>';
				echo '<td>' . self::e((string) ($row['policy_version'] ?? '')) . '</td><td>' . self::e((string) ($row['source_type'] ?? '')) . '</td>';
				echo '<td>' . self::e(self::consent((string) ($row['consent_encrypted'] ?? ''))) . '</td>';
				echo '<td>' . self::e((string) ($row['confidence'] ?? 'Unavailable')) . '</td>';
			}
			echo '</tr>';
		}
		if ($rows === []) {
			echo '<tr><td colspan="' . count($headers) . '">No review items match this filter.</td></tr>';
		}
		echo '</tbody></table>';
		$table = (string) ob_get_clean();
		echo AdminUi::tableRegion('Review queue results', $table) . '</form>';
		self::reviewDetail($advanced,$rows);
		echo '</div>';
	}

	/** @param array<string,mixed> $definition */
	private static function classification(array $definition, string $tierId, string $outcomeId): string {
		if ($tierId === '' && $outcomeId === '') { return 'Not classified'; }
		return self::e(PolicyLabels::tier($definition, $tierId)) . ($outcomeId !== '' ? '<br><span class="description">' . self::e(PolicyLabels::outcome($definition, $outcomeId)) . '</span>' : '');
	}

	/** @return list<array<string, mixed>> */
	private static function rows(int $page, string $state): array {
		global $wpdb;
		if (! isset($wpdb->prefix) || ! method_exists($wpdb, 'get_results')) {
			return [];
		}
		$where = $state !== '' && method_exists($wpdb, 'prepare') ? $wpdb->prepare(' WHERE item.review_state=%s', $state) : '';
		$offset = ($page - 1) * 50;
		$sql = "SELECT item.id,item.policy_id,item.tier_id,item.outcome_id,item.reason_code,item.confidence,item.decision_state,item.evidence_json,item.missing_evidence_json,item.ambiguity_json,item.policy_version,item.source_type,item.source_reference,item.attribution_ready,item.consent_encrypted,item.review_state,item.review_revision,item.last_error,item.created_at,(SELECT delivery.state FROM {$wpdb->prefix}cf7aiv_deliveries delivery WHERE delivery.item_id=item.id ORDER BY delivery.id DESC LIMIT 1) AS destination_status FROM {$wpdb->prefix}cf7aiv_items item{$where} ORDER BY item.created_at ASC LIMIT 50 OFFSET {$offset}";
		$rows = $wpdb->get_results($sql, ARRAY_A);
		return is_array($rows) ? $rows : [];
	}

	/** @return array{label:string,class:string} */
	private static function decision(string $state): array {
		return match ($state) {
			'strong_match' => ['label' => 'Strong match', 'class' => 'is-strong'],
			'needs_review' => ['label' => 'Needs review', 'class' => 'is-review'],
			default => ['label' => 'Not eligible', 'class' => 'is-ineligible'],
		};
	}

	/** @return list<string> */
	private static function codes(mixed $json, bool $objectCodes = false): array {
		$value = is_string($json) ? json_decode($json, true) : $json;
		if (! is_array($value)) {
			return [];
		}
		$codes = [];
		foreach ($value as $entry) {
			$code = $objectCodes && is_array($entry) ? ($entry['code'] ?? '') : $entry;
			if (is_scalar($code) && (string) $code !== '') {
				$codes[] = (string) $code;
			}
		}
		return array_values(array_unique($codes));
	}

	private static function detailUrl(int $id): string {
		$url = 'admin.php?page=cf7aiv-review&detail_item=' . $id;
		return function_exists('wp_nonce_url') ? wp_nonce_url($url, 'cf7aiv_source_detail_' . $id) : $url;
	}

	private static function reviewDetail(bool $advanced,array $rows): void {
		$id = (int) ($_GET['detail_item'] ?? 0);
		if ($id < 1) {
			return;
		}
		if (function_exists('wp_verify_nonce') && wp_verify_nonce((string) ($_GET['_wpnonce'] ?? ''), 'cf7aiv_source_detail_' . $id) === false) {
			return;
		}
		$item = self::detailItem($id);
		$definition = $item === null ? [] : (PolicyLabels::definitions([(int) ($item['policy_id'] ?? 0)])[(int) ($item['policy_id'] ?? 0)] ?? []);
		echo '<section class="card cf7aiv-review-detail"><h2>Review item ' . $id . '</h2>';
		if ($item !== null) {
			self::classificationDetail($item, $definition);
			self::aiInput($item, $definition);
		}
		if ($advanced) {
			self::sourceDetail($id);
		}
		$revision=self::detailRevision($id,$rows);
		echo '<form method="post" action="' . self::e(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="cf7aiv_review"><input type="hidden" name="item_id" value="' . $id . '"><input type="hidden" name="review_revision" value="' . $revision . '">';
		wp_nonce_field('cf7aiv_review');
		echo '<label for="cf7aiv-item-action">Action</label><select id="cf7aiv-item-action" name="review_action"><option value="confirm">Confirm prediction</option><option value="change_tier">Correct tier and outcome</option><option value="approve_no_send">Approve without send</option><option value="approve_dispatch">Approve and dispatch</option><option value="reject">Reject</option><option value="reanalyse">Reanalyse</option></select>';
		self::correctionFields($definition, (string) ($item['tier_id'] ?? ''), (string) ($item['outcome_id'] ?? ''));
		echo '<label for="cf7aiv-example-summary">Non-identifying example summary</label><textarea id="cf7aiv-example-summary" name="example_summary"></textarea><p class="description">Required when correcting. Describe the enquiry and why the corrected tier fits, without names or contact details. Policy Lab can turn these into examples the AI learns from once you approve them.</p><p class="cf7aiv-actions"><button type="submit" class="button button-primary">Apply review</button></p></form></section>';
	}

	/** @return array<string,mixed>|null */
	private static function detailItem(int $id): ?array {
		global $wpdb;
		if (! isset($wpdb->prefix) || ! method_exists($wpdb, 'get_row') || ! method_exists($wpdb, 'prepare')) { return null; }
		$item = $wpdb->get_row($wpdb->prepare("SELECT id,policy_id,tier_id,outcome_id,predicted_tier_id,predicted_outcome_id,reason_code,decision_state,evidence_json,missing_evidence_json,ambiguity_json,source_type,source_reference FROM {$wpdb->prefix}cf7aiv_items WHERE id=%d", $id), ARRAY_A);
		return is_array($item) ? $item : null;
	}

	/** @param array<string,mixed> $item @param array<string,mixed> $definition */
	private static function classificationDetail(array $item, array $definition): void {
		$decision = self::decision((string) ($item['decision_state'] ?? ''));
		// tier_id holds the reviewer's correction once there is one; the AI's
		// own answer is kept in predicted_tier_id.
		$aiTier = (string) (($item['predicted_tier_id'] ?? '') !== '' ? $item['predicted_tier_id'] : ($item['tier_id'] ?? ''));
		$aiOutcome = (string) (($item['predicted_outcome_id'] ?? '') !== '' ? $item['predicted_outcome_id'] : ($item['outcome_id'] ?? ''));
		$rows = ['AI classification' => self::classification($definition, $aiTier, $aiOutcome)];
		if ((string) ($item['tier_id'] ?? '') !== $aiTier || (string) ($item['outcome_id'] ?? '') !== $aiOutcome) {
			$rows['Current classification'] = self::classification($definition, (string) ($item['tier_id'] ?? ''), (string) ($item['outcome_id'] ?? '')) . ' <span class="description">(after review)</span>';
		}
		$rows += [
			'Decision' => '<span class="cf7aiv-status-badge ' . self::e($decision['class']) . '">' . self::e($decision['label']) . '</span>',
			'Reason' => self::e(AdminUi::humanLabel((string) ($item['reason_code'] ?? ''))),
			'Evidence found' => self::e(implode(', ', array_map([AdminUi::class, 'humanLabel'], self::codes($item['evidence_json'] ?? null, true))) ?: 'None'),
			'Evidence missing' => self::e(implode(', ', array_map([AdminUi::class, 'humanLabel'], self::codes($item['missing_evidence_json'] ?? null))) ?: 'None'),
			'Ambiguity' => self::e(implode(', ', array_map([AdminUi::class, 'humanLabel'], self::codes($item['ambiguity_json'] ?? null))) ?: 'None'),
		];
		echo '<dl class="cf7aiv-review-facts">';
		foreach ($rows as $label => $html) { echo '<dt>' . self::e($label) . '</dt><dd>' . $html . '</dd>'; }
		echo '</dl>';
	}

	/** @param array<string,mixed> $item @param array<string,mixed> $definition */
	private static function aiInput(array $item, array $definition): void {
		echo '<h3>What the AI was given</h3><p class="description">The fields this policy sends to AI, with personal details redacted. This is exactly what the classification was based on.</p>';
		$fields = $definition === [] ? null : ReviewContext::fields($item, $definition);
		if ($fields === null) {
			echo '<p>The enquiry is no longer available from its source, or its policy could not be read.</p>';
			return;
		}
		if ($fields === []) {
			echo '<p>None of the fields this policy sends to AI had a value.</p>';
			return;
		}
		echo '<dl class="cf7aiv-ai-input">';
		foreach ($fields as $key => $value) {
			$text = is_array($value) ? implode('; ', array_map('strval', $value)) : (string) $value;
			echo '<dt>' . self::e((string) $key) . '</dt><dd>' . nl2br(self::e($text)) . '</dd>';
		}
		echo '</dl>';
	}

	/**
	 * Choose a correction from the policy's own tiers rather than typing its id.
	 *
	 * Each tier names its outcome, so choosing a tier selects the outcome too;
	 * the outcome list stays editable for the rare correction that needs both.
	 * Without a readable policy the free-text fields remain, so correction still
	 * works.
	 *
	 * @param array<string,mixed> $definition
	 */
	private static function correctionFields(array $definition, string $currentTier, string $currentOutcome): void {
		$tiers = PolicyLabels::tiers($definition);
		if ($tiers === []) {
			echo '<label for="cf7aiv-corrected-tier">Corrected tier</label><input id="cf7aiv-corrected-tier" name="tier_id"><label for="cf7aiv-corrected-outcome">Corrected outcome</label><input id="cf7aiv-corrected-outcome" name="outcome_id">';
			return;
		}
		echo '<label for="cf7aiv-corrected-tier">Corrected tier</label><select id="cf7aiv-corrected-tier" name="tier_id" data-cf7aiv-tier-select>';
		foreach ($tiers as $tier) {
			echo '<option value="' . self::e($tier['id']) . '" data-outcome="' . self::e($tier['outcome_id']) . '"' . ($tier['id'] === $currentTier ? ' selected' : '') . '>' . self::e($tier['label']) . '</option>';
		}
		echo '</select><label for="cf7aiv-corrected-outcome">Corrected outcome</label><select id="cf7aiv-corrected-outcome" name="outcome_id" data-cf7aiv-outcome-select>';
		foreach (PolicyLabels::outcomes($definition) as $value => $label) {
			echo '<option value="' . self::e($value) . '"' . ($value === $currentOutcome ? ' selected' : '') . '>' . self::e($label) . '</option>';
		}
		echo '</select>';
	}

	private static function sourceDetail(int $id): void {
		global $wpdb;
		if (! isset($wpdb->prefix) || ! method_exists($wpdb, 'get_row') || ! method_exists($wpdb, 'prepare')) {
			return;
		}
		$item = $wpdb->get_row($wpdb->prepare("SELECT source_type,source_reference,created_at FROM {$wpdb->prefix}cf7aiv_items WHERE id=%d", $id), ARRAY_A);
		if (! is_array($item)) {
			return;
		}
		$fields = ReviewContext::sourceFields($item);
		echo '<h3>Original source detail</h3>';
		if ($fields === null) {
			echo '<p>Source unavailable or deleted.</p>';
		} else {
			echo '<dl>';
			foreach ($fields as $key => $value) {
				if (is_scalar($value)) {
					echo '<dt>' . self::e((string) $key) . '</dt><dd>' . self::e((string) $value) . '</dd>';
				}
			}
			echo '</dl>';
		}
		foreach ((new ReviewService(new WpReviewStore()))->timingWarnings($id) as $warning) {
			echo '<div class="notice notice-warning inline"><p>' . self::e($warning) . '</p></div>';
		}
	}

	/**
	 * Why an item failed, for the states where that is the only useful question.
	 *
	 * The reason has always been recorded in last_error, and was never shown
	 * anywhere in the administration screens. An operator looking at a queue of
	 * items in "error" could see only that they had failed, which left
	 * re-running them as the only available move - and re-running something that
	 * fails deterministically just fails again. Observed on a live site where
	 * every item failed for one fixed reason that took a code read to find.
	 *
	 * Shown only for the failure states: on a successful item the column would
	 * be noise, and a stale reason next to a healthy item reads as a new problem.
	 * Sanitized through the same path as delivery errors, because it carries
	 * whatever a provider or the database put in it.
	 */
	private static function failureReason(string $reviewState, string $lastError): string {
		if (! in_array($reviewState, ['error', 'retrying'], true)) { return ''; }
		$reason = trim(AdminSafe::log($lastError));
		return $reason === '' ? 'No reason recorded' : $reason;
	}

	private static function consent(string $encrypted): string {
		try {
			$value = json_decode(Crypto::decrypt($encrypted), true, 16, JSON_THROW_ON_ERROR);
			$states = is_array($value) ? ($value['states'] ?? $value) : [];
			return is_array($states) ? implode(', ', array_map(static fn ($key, $state): string => $key . ':' . $state, array_keys($states), $states)) : 'Unavailable';
		} catch (\Throwable) {
			return 'Unavailable';
		}
	}

	/** @param list<array<string,mixed>> $rows */
	private static function detailRevision(int $id,array $rows):int {
		foreach($rows as $row){if((int)($row['id']??0)===$id){return max(0,(int)($row['review_revision']??0));}}
		global $wpdb;
		if(isset($wpdb->prefix)&&method_exists($wpdb,'get_var')&&method_exists($wpdb,'prepare')){
			return max(0,(int)$wpdb->get_var($wpdb->prepare("SELECT review_revision FROM {$wpdb->prefix}cf7aiv_items WHERE id=%d",$id)));
		}
		return 0;
	}

	private static function capability(): void {
		if (! current_user_can('manage_options')) {
			throw new \RuntimeException('Administrator capability is required.');
		}
	}

	private static function e(string $value): string {
		return function_exists('esc_html') ? esc_html($value) : htmlspecialchars($value, ENT_QUOTES);
	}
}
