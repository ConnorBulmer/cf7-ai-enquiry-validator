<?php
/**
 * Review screen: what reviewers see and how they correct. Run: php tests/ReviewScreenTest.php
 */
declare(strict_types=1);

function review_screen_assert(bool $condition, string $message): void { if (! $condition) { throw new RuntimeException('FAIL: ' . $message); } }

define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
define('AUTH_KEY', 'review-screen-auth-key');
define('SECURE_AUTH_KEY', 'review-screen-secure-auth-key');
$GLOBALS['rs_filters'] = []; $GLOBALS['rs_options'] = [];
function add_action(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): void {}
function add_filter(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): void { $GLOBALS['rs_filters'][$hook][] = $callback; }
function apply_filters(string $hook, mixed $value, mixed ...$arguments): mixed { foreach ($GLOBALS['rs_filters'][$hook] ?? [] as $callback) { $value = $callback($value, ...$arguments); } return $value; }
function plugin_dir_url(string $file): string { return 'https://example.test/plugin/'; }
function get_option(string $name, mixed $default = false): mixed { return $GLOBALS['rs_options'][$name] ?? $default; }
function current_user_can(string $capability): bool { return true; }
function wp_verify_nonce(string $nonce, string $action): int|false { return 1; }
function wp_nonce_field(string $action, string $name = '_wpnonce'): void { echo '<input type="hidden" name="' . $name . '" value="nonce">'; }
function wp_nonce_url(string $url, string $action): string { return $url . '&_wpnonce=nonce'; }
function admin_url(string $path = ''): string { return 'https://example.test/wp-admin/' . $path; }
function esc_html(string $value): string { return htmlspecialchars($value, ENT_QUOTES); }
function as_enqueue_async_action(): int { return 1; }

require_once dirname(__DIR__) . '/cf7-ai-enquiry-validator.php';

$definition = CF7AIV\Classification\StarterPolicy::definition();
final class ReviewScreenSource implements CF7AIV\Contracts\SubmissionSourceInterface {
	public function key(): string { return 'testsrc'; }
	public function get(string $reference): ?array {
		return $reference === 'ref-91' ? ['reference' => 'ref-91', 'form_id' => 5, 'fields' => [
			'message' => 'Looking for respite care for my father next month. Call Ada on 07700 900123.',
			'your-name' => 'Ada Lovelace', 'your-email' => 'ada@example.test',
		]] : null;
	}
	public function iterate(array $arguments = []): iterable { return []; }
}
add_filter('cf7aiv_submission_sources', static fn (array $sources): array => $sources + ['testsrc' => new ReviewScreenSource()]);

final class ReviewScreenWpdb {
	public string $prefix = 'wp_';
	public ?array $item = null;
	public array $rows = [];
	public function __construct(private readonly array $definition) {}
	public function prepare(string $sql, mixed ...$args): string { foreach ($args as $arg) { $sql = preg_replace('/%[ds]/', is_int($arg) ? (string) $arg : "'" . $arg . "'", $sql, 1) ?? $sql; } return $sql; }
	public function get_results(string $sql, mixed $format = null): array {
		if (str_contains($sql, 'cf7aiv_policies') && str_contains($sql, 'WHERE id IN')) { return [['id' => 3, 'definition_json' => json_encode($this->definition)]]; }
		if (str_contains($sql, 'FROM wp_cf7aiv_items item')) { return $this->rows; }
		return [];
	}
	public function get_row(string $sql, mixed $format = null): ?array { return str_contains($sql, 'FROM wp_cf7aiv_items WHERE id=') ? $this->item : null; }
	public function get_var(string $sql): mixed { return 7; }
}
$tierId = (string) $definition['tier_definitions'][0]['id'];
$outcomeId = (string) $definition['tier_definitions'][0]['outcome_id'];
$tierLabel = (string) $definition['tier_definitions'][0]['label'];
$GLOBALS['wpdb'] = new ReviewScreenWpdb($definition);
$GLOBALS['wpdb']->rows = [['id' => 91, 'policy_id' => 3, 'tier_id' => $tierId, 'outcome_id' => $outcomeId, 'decision_state' => 'needs_review', 'reason_code' => 'qualified', 'review_state' => 'review', 'review_revision' => 7, 'attribution_ready' => 1, 'created_at' => '2026-10-12 09:00:00', 'confidence' => '0.91', 'evidence_json' => '[{"code":"service_fit"}]', 'missing_evidence_json' => '[]', 'ambiguity_json' => '[]', 'source_reference' => 'ref-91', 'source_type' => 'testsrc']];
$GLOBALS['wpdb']->item = ['id' => 91, 'policy_id' => 3, 'tier_id' => $tierId, 'outcome_id' => $outcomeId, 'reason_code' => 'qualified', 'decision_state' => 'needs_review', 'summary' => 'Respite care enquiry for a parent.', 'evidence_json' => '[{"code":"service_fit"}]', 'missing_evidence_json' => '["timeframe"]', 'ambiguity_json' => '[]', 'source_type' => 'testsrc', 'source_reference' => 'ref-91'];

// ─── The list reads like the policy, not like its identifiers ───────────────
ob_start(); CF7AIV\Admin\ReviewQueuePage::render(); $list = ob_get_clean();
review_screen_assert(str_contains($list, $tierLabel), 'The list must show the tier label from the policy.');
review_screen_assert(str_contains($list, '<td>Qualified</td>'), 'The list must show the reason in words.');
review_screen_assert(str_contains($list, '<select id="cf7aiv-review-state" name="review_state">') && str_contains($list, '<option value="corrected">Corrected</option>'), 'The review state filter must be a list of real states.');
review_screen_assert(! str_contains($list, 'Evidence codes') && ! str_contains(strtolower($list), 'confidence'), 'Diagnostic columns and model confidence must stay out of the default view.');
review_screen_assert(! str_contains($list, 'ref-91'), 'The list must never render a source reference.');

// ─── The detail shows what the AI judged, without identities ────────────────
$_GET = ['detail_item' => 91, '_wpnonce' => 'nonce'];
ob_start(); CF7AIV\Admin\ReviewQueuePage::render(); $detail = ob_get_clean(); $_GET = [];
review_screen_assert(str_contains($detail, 'What the AI was given') && str_contains($detail, 'Looking for respite care for my father next month.'), 'The detail must show the enquiry text the AI classified.');
review_screen_assert(! str_contains($detail, 'Ada Lovelace') && ! str_contains($detail, 'ada@example.test') && ! str_contains($detail, '07700 900123'), 'Fields kept from AI, and personal details inside the message, must not appear.');
review_screen_assert(! str_contains($detail, 'Original source detail') && ! str_contains($detail, 'ref-91'), 'The raw source stays in advanced mode only.');
review_screen_assert(str_contains($detail, 'Timeframe') && str_contains($detail, 'Qualified'), 'The detail must show the reason and the evidence the AI could not find.');
review_screen_assert(! str_contains($detail, 'Current classification'), 'An uncorrected item has only the AI classification.');

// ─── Corrections choose from the policy ─────────────────────────────────────
review_screen_assert(str_contains($detail, '<select id="cf7aiv-corrected-tier" name="tier_id" data-cf7aiv-tier-select>'), 'The corrected tier must be chosen from a list.');
review_screen_assert(str_contains($detail, 'value="' . $tierId . '" data-outcome="' . $outcomeId . '" selected'), 'The current tier must be preselected and carry its outcome.');
review_screen_assert(str_contains($detail, '<select id="cf7aiv-corrected-outcome" name="outcome_id" data-cf7aiv-outcome-select>'), 'The corrected outcome must be chosen from a list.');

// After a correction the AI's own answer stays visible beside the reviewer's.
$GLOBALS['wpdb']->item['predicted_tier_id'] = 'care_information';
$GLOBALS['wpdb']->item['predicted_outcome_id'] = 'secondary_opportunity';
$_GET = ['detail_item' => 91, '_wpnonce' => 'nonce'];
ob_start(); CF7AIV\Admin\ReviewQueuePage::render(); $corrected = ob_get_clean(); $_GET = [];
$aiLabel = CF7AIV\Admin\PolicyLabels::tier($definition, 'care_information');
review_screen_assert(preg_match('/<dt>AI classification<\/dt><dd>' . preg_quote($aiLabel, '/') . '/', $corrected) === 1 && str_contains($corrected, 'Current classification') && str_contains($corrected, '(after review)'), 'After a correction the page must show what the AI said, not the correction, as the AI classification.');
review_screen_assert(! str_contains($list, '<option value="retrying">'), 'The filter must only offer states an item can have.');

// Without a readable policy the free-text fields remain, so correction still works.
$GLOBALS['wpdb']->item['policy_id'] = 0;
$_GET = ['detail_item' => 91, '_wpnonce' => 'nonce'];
ob_start(); CF7AIV\Admin\ReviewQueuePage::render(); $fallback = ob_get_clean(); $_GET = [];
review_screen_assert(str_contains($fallback, '<input id="cf7aiv-corrected-tier" name="tier_id">'), 'Without a policy the correction must fall back to text fields.');

echo "PASS ReviewScreenTest\n";
