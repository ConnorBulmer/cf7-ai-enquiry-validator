<?php
/**
 * Reviewer corrections as policy examples. Run: php tests/CorrectionExamplesTest.php
 */
declare(strict_types=1);

function examples_assert(bool $condition, string $message): void { if (! $condition) { throw new RuntimeException('FAIL: ' . $message); } }

define('CF7AIV\\DIR', dirname(__DIR__) . '/');
spl_autoload_register(static function (string $class): void {
	if (! str_starts_with($class, 'CF7AIV\\')) { return; }
	$path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, 7)) . '.php';
	if (is_file($path)) { require_once $path; }
});

use CF7AIV\Admin\CorrectionExamples;
use CF7AIV\Classification\PolicyCompiler;
use CF7AIV\Classification\PolicyValidator;
use CF7AIV\Classification\StarterPolicy;

$base = ['id' => 1, 'policy_key' => 'global', 'version' => 'live', 'definition' => PolicyValidator::import(StarterPolicy::definition())];
$merged = CorrectionExamples::merge($base, [
	['id' => 1, 'previous_tier' => 'care_information', 'corrected_tier' => 'respite_care', 'example_summary' => 'Asked about a two-week respite stay for a parent next month.'],
	['id' => 2, 'previous_tier' => 'respite_care', 'corrected_tier' => 'not_a_tier', 'example_summary' => 'Something about a tier this policy does not have.'],
	['id' => 3, 'previous_tier' => 'residential_care', 'corrected_tier' => 'spam', 'example_summary' => 'Wants us to email a quote to their address.'],
	['id' => 4, 'previous_tier' => 'care_information', 'corrected_tier' => 'respite_care', 'example_summary' => 'Asked about a two-week respite stay for a parent next month.'],
]);
examples_assert($merged['added'] === [1], 'Only a valid, new, non-identifying correction may become an example.');
examples_assert($merged['definition']['examples'] === [['summary' => 'Asked about a two-week respite stay for a parent next month.', 'tier_id' => 'respite_care', 'not_tier_id' => 'care_information']], 'An example must name the right tier and the wrong one it replaces.');
examples_assert(count($merged['skipped']) === 3, 'Each skipped correction must say why.');
examples_assert(str_contains(implode(' ', $merged['skipped']), 'not in this policy') && str_contains(implode(' ', $merged['skipped']), 'personal or tracking data') && str_contains(implode(' ', $merged['skipped']), 'already has this example'), 'Skips must give the reason: unknown tier, personal data, duplicate.');
PolicyValidator::import($merged['definition']);

// A policy keeps a bounded number of examples.
$full = $base; $full['definition']['examples'] = array_fill(0, CorrectionExamples::MAX_EXAMPLES, ['summary' => 'Earlier example.', 'tier_id' => 'spam']);
$capped = CorrectionExamples::merge($full, [['id' => 9, 'previous_tier' => 'spam', 'corrected_tier' => 'respite_care', 'example_summary' => 'A new respite enquiry.']]);
examples_assert($capped['added'] === [] && str_contains($capped['skipped'][0], (string) CorrectionExamples::MAX_EXAMPLES), 'Examples beyond the limit must be refused with a reason.');

// The compiled prompt explains examples only when there are some, so existing
// policies compile to exactly the prompt they always did.
$withExamples = PolicyCompiler::compile($merged['definition'])['prompt'];
$without = PolicyCompiler::compile(StarterPolicy::definition())['prompt'];
examples_assert(str_contains($withExamples, 'Policy examples are enquiries a reviewer classified') && str_contains($withExamples, 'two-week respite stay'), 'A policy with examples must tell the model how to use them, and include them.');
examples_assert(! str_contains($without, 'Policy examples are enquiries'), 'A policy without examples must compile as before.');

// ─── Creating the draft ──────────────────────────────────────────────────────
define('ARRAY_A', 'ARRAY_A');
$GLOBALS['examples_options'] = [];
function get_option(string $name, mixed $default = false): mixed { return $GLOBALS['examples_options'][$name] ?? $default; }
function update_option(string $name, mixed $value, bool $autoload = false): bool { $GLOBALS['examples_options'][$name] = $value; return true; }
final class ExamplesWpdb {
	public string $prefix = 'wp_'; public int $insert_id = 5; public array $queries = [];
	public function __construct(private readonly array $policyRow) {}
	public function prepare(string $sql, mixed ...$args): string { $this->queries[] = ['prepare', $sql, $args]; return $sql; }
	public function get_row(string $sql, mixed $format = null): ?array { return str_contains($sql, 'cf7aiv_policies') ? $this->policyRow : null; }
	public function get_results(string $sql, mixed $format = null): array {
		$this->queries[] = ['results', $sql];
		return str_contains($sql, 'cf7aiv_review_suggestions') ? [['id' => 1, 'previous_tier' => 'care_information', 'corrected_tier' => 'respite_care', 'example_summary' => 'Asked about a two-week respite stay for a parent next month.']] : [];
	}
	public function query(string $sql): int { $this->queries[] = ['query', $sql]; return 1; }
	public bool $versionTaken = false;
	public function get_var(string $sql): mixed { return str_contains($sql, 'SELECT COUNT(*) FROM wp_cf7aiv_policies') ? ($this->versionTaken ? 1 : 0) : 5; }
}
$GLOBALS['wpdb'] = new ExamplesWpdb(['id' => 1, 'policy_key' => 'global', 'version' => 'live', 'status' => 'published', 'definition_json' => json_encode(StarterPolicy::definition()), 'compiled_prompt' => 'p']);
$created = CorrectionExamples::createDraft(1, [1, 0, -3], '20261012-examples', 7);
examples_assert($created['policy_key'] === 'global' && $created['version'] === '20261012-examples' && $created['added'] === [1], 'A draft must be created from the chosen corrections.');
$saved = array_values(array_filter($GLOBALS['wpdb']->queries, static fn (array $query): bool => $query[0] === 'prepare' && str_contains($query[1], 'INSERT INTO wp_cf7aiv_policies')));
examples_assert($saved !== [] && str_contains((string) $saved[0][2][5], 'two-week respite stay') && $saved[0][2][1] === '20261012-examples', 'The draft policy must be saved with the example in its definition.');
examples_assert((bool) array_filter($GLOBALS['wpdb']->queries, static fn (array $query): bool => $query[0] === 'query' && str_contains($query[1], "SET status='applied'") && str_contains($query[1], 'id IN (1)')), 'A used correction must not be offered again.');
examples_assert(($GLOBALS['examples_options']['cf7aiv_settings']['setup']['draft_summary']['version'] ?? '') === '20261012-examples', 'The new draft must be offered for publication.');
$refused = false;
try { CorrectionExamples::createDraft(1, [], 'x', 7); } catch (InvalidArgumentException) { $refused = true; }
examples_assert($refused, 'Creating a draft with no corrections chosen must be refused.');
$GLOBALS['wpdb']->versionTaken = true;
$GLOBALS['wpdb']->queries = [];
$refused = false;
try { CorrectionExamples::createDraft(1, [1], 'live', 7); } catch (InvalidArgumentException $error) { $refused = str_contains($error->getMessage(), 'already used'); }
examples_assert($refused && ! array_filter($GLOBALS['wpdb']->queries, static fn (array $query): bool => $query[0] === 'query' && str_contains($query[1], "SET status='applied'")), 'Reusing an existing version must be refused without using up the corrections.');

echo "PASS CorrectionExamplesTest\n";
