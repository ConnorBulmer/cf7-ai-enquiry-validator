<?php

declare(strict_types=1);

namespace CF7AIV\Admin;

use CF7AIV\Contracts\SubmissionSourceInterface;
use CF7AIV\Infrastructure\Crypto;
use CF7AIV\Infrastructure\Settings;

final class ReviewQueuePage {
	public static function render(): void {
		self::capability();
		$settings = (array) get_option(Settings::OPTION, Settings::defaults());
		$advanced = ! empty($settings['ui']['advanced_mode']);
		$page = max(1, (int) ($_GET['paged'] ?? 1));
		$state = substr(preg_replace('/[^a-z_\-]/', '', (string) ($_GET['review_state'] ?? '')) ?? '', 0, 32);
		$rows = self::rows($page, $state);

		echo '<div class="wrap cf7aiv-admin"><div class="cf7aiv-page-header"><h1>Review Queue</h1><p>Review classifications, correct outcomes, and control approved delivery.</p></div>';
		if (($_GET['review_error'] ?? '') === '1') {
			$currentState = $state !== '' ? $state : 'review';
			echo '<div class="notice notice-error"><p>Approve-and-dispatch was not completed. Current review state: ' . self::e($currentState) . '.</p></div>';
		}
		echo '<ul class="cf7aiv-decision-legend" aria-label="Decision states"><li><span class="cf7aiv-status-badge is-strong">Strong match</span></li><li><span class="cf7aiv-status-badge is-review">Needs review</span></li><li><span class="cf7aiv-status-badge is-ineligible">Not eligible</span></li></ul>';
		echo '<form method="get" class="cf7aiv-toolbar cf7aiv-filter-toolbar"><input type="hidden" name="page" value="cf7aiv-review"><label for="cf7aiv-review-state">Review state</label><input id="cf7aiv-review-state" name="review_state" value="' . self::e($state) . '"><button type="submit" class="button">Filter</button></form>';
		echo '<form method="post" action="' . self::e(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="cf7aiv_bulk_review">';
		wp_nonce_field('cf7aiv_bulk_review');
		echo '<div class="tablenav top cf7aiv-toolbar cf7aiv-bulk-toolbar"><label for="cf7aiv-review-action">Bulk review action</label><select id="cf7aiv-review-action" name="review_action"><option value="confirm">Confirm prediction</option><option value="approve_no_send">Approve without send</option><option value="approve_dispatch">Approve and dispatch</option><option value="reject">Reject</option><option value="reanalyse">Reanalyse</option></select><button type="submit" class="button">Apply</button></div>';
		ob_start();
		echo '<table class="widefat striped"><thead><tr><th scope="col">Select</th><th scope="col">Item</th><th scope="col">Outcome</th><th scope="col">Tier</th><th scope="col">Decision state</th><th scope="col">Evidence codes</th><th scope="col">Missing evidence</th><th scope="col">Ambiguity</th><th scope="col">Policy</th><th scope="col">Source</th><th scope="col">Attribution</th><th scope="col">Consent</th><th scope="col">Review</th><th scope="col">Why it failed</th><th scope="col">Delivery status</th>';
		if ($advanced) {
			echo '<th scope="col">AI diagnostic, not dispatch score.</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ($rows as $row) {
			$id = (int) ($row['id'] ?? 0);
			$decision = self::decision((string) ($row['decision_state'] ?? ''));
			$revision=max(0,(int)($row['review_revision']??0));
			echo '<tr><td><label class="screen-reader-text" for="cf7aiv-item-' . $id . '">Select item ' . $id . '</label><input id="cf7aiv-item-' . $id . '" type="checkbox" name="item_ids[]" value="' . $id . '"><input type="hidden" name="review_revisions['.$id.']" value="'.$revision.'"></td>';
			echo '<td><a href="' . self::e(self::detailUrl($id)) . '">Review item ' . $id . '</a></td>';
			echo '<td>' . self::e((string) ($row['outcome_id'] ?? '')) . '</td><td>' . self::e((string) ($row['tier_id'] ?? '')) . '</td>';
			echo '<td><span class="cf7aiv-status-badge ' . self::e($decision['class']) . '">' . self::e($decision['label']) . '</span></td>';
			echo '<td>' . self::e(implode(', ', self::codes($row['evidence_json'] ?? null, true))) . '</td>';
			echo '<td>' . self::e(implode(', ', self::codes($row['missing_evidence_json'] ?? null))) . '</td>';
			echo '<td>' . self::e(implode(', ', self::codes($row['ambiguity_json'] ?? null))) . '</td>';
			echo '<td>' . self::e((string) ($row['policy_version'] ?? '')) . '</td><td>' . self::e((string) ($row['source_type'] ?? '')) . '</td>';
			echo '<td>' . (! empty($row['attribution_ready']) ? 'Ready' : 'Unavailable') . '</td><td>' . self::e(self::consent((string) ($row['consent_encrypted'] ?? ''))) . '</td>';
			echo '<td>' . self::e((string) ($row['review_state'] ?? '')) . '</td>';
			echo '<td>' . self::e(self::failureReason((string) ($row['review_state'] ?? ''), (string) ($row['last_error'] ?? ''))) . '</td>';
			echo '<td>' . self::e((string) ($row['destination_status'] ?? 'not sent')) . '</td>';
			if ($advanced) {
				echo '<td>' . self::e((string) ($row['confidence'] ?? 'Unavailable')) . '</td>';
			}
			echo '</tr>';
		}
		if ($rows === []) {
			echo '<tr><td colspan="' . ($advanced ? 15 : 14) . '">No review items match this filter.</td></tr>';
		}
		echo '</tbody></table>';
		$table = (string) ob_get_clean();
		echo AdminUi::tableRegion('Review queue results', $table) . '</form>';
		self::reviewDetail($advanced,$rows);
		echo '</div>';
	}

	/** @return list<array<string, mixed>> */
	private static function rows(int $page, string $state): array {
		global $wpdb;
		if (! isset($wpdb->prefix) || ! method_exists($wpdb, 'get_results')) {
			return [];
		}
		$where = $state !== '' && method_exists($wpdb, 'prepare') ? $wpdb->prepare(' WHERE item.review_state=%s', $state) : '';
		$offset = ($page - 1) * 50;
		$sql = "SELECT item.id,item.tier_id,item.outcome_id,item.reason_code,item.confidence,item.decision_state,item.evidence_json,item.missing_evidence_json,item.ambiguity_json,item.policy_version,item.source_type,item.source_reference,item.attribution_ready,item.consent_encrypted,item.review_state,item.review_revision,item.last_error,item.created_at,(SELECT delivery.state FROM {$wpdb->prefix}cf7aiv_deliveries delivery WHERE delivery.item_id=item.id ORDER BY delivery.id DESC LIMIT 1) AS destination_status FROM {$wpdb->prefix}cf7aiv_items item{$where} ORDER BY item.created_at ASC LIMIT 50 OFFSET {$offset}";
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
		echo '<section class="card"><h2>Review item ' . $id . '</h2>';
		if ($advanced) {
			self::sourceDetail($id);
		}
		$revision=self::detailRevision($id,$rows);
		echo '<form method="post" action="' . self::e(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="cf7aiv_review"><input type="hidden" name="item_id" value="' . $id . '"><input type="hidden" name="review_revision" value="' . $revision . '">';
		wp_nonce_field('cf7aiv_review');
		echo '<label for="cf7aiv-item-action">Action</label><select id="cf7aiv-item-action" name="review_action"><option value="confirm">Confirm prediction</option><option value="change_tier">Correct tier and outcome</option><option value="approve_no_send">Approve without send</option><option value="approve_dispatch">Approve and dispatch</option><option value="reject">Reject</option><option value="reanalyse">Reanalyse</option></select>';
		echo '<label for="cf7aiv-corrected-tier">Corrected tier</label><input id="cf7aiv-corrected-tier" name="tier_id"><label for="cf7aiv-corrected-outcome">Corrected outcome</label><input id="cf7aiv-corrected-outcome" name="outcome_id">';
		echo '<label for="cf7aiv-example-summary">Non-identifying example summary</label><textarea id="cf7aiv-example-summary" name="example_summary"></textarea><p class="cf7aiv-actions"><button type="submit" class="button button-primary">Apply review</button></p></form></section>';
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
		$sources = function_exists('apply_filters') ? (array) apply_filters('cf7aiv_submission_sources', []) : [];
		$source = $sources[(string) ($item['source_type'] ?? '')] ?? null;
		$record = $source instanceof SubmissionSourceInterface ? $source->get((string) ($item['source_reference'] ?? '')) : null;
		echo '<h3>Original source detail</h3>';
		if (! is_array($record)) {
			echo '<p>Source unavailable or deleted.</p>';
		} else {
			echo '<dl>';
			foreach ((array) ($record['fields'] ?? []) as $key => $value) {
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
