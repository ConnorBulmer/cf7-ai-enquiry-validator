<?php
/**
 * Standalone grounded-evidence tests. Run: php tests/GroundedEvidenceTest.php
 */

declare(strict_types=1);

function grounded_assert(bool $condition, string $message): void {
	if (! $condition) { throw new RuntimeException($message); }
}

function grounded_reject(array $value, CF7AIV\Domain\ClassificationRequest $request, string $message): void {
	$rejected = false;
	try {
		CF7AIV\Classification\ResultValidator::fromJson(json_encode($value, JSON_THROW_ON_ERROR), $request);
	} catch (InvalidArgumentException) {
		$rejected = true;
	}
	grounded_assert($rejected, $message);
}

function plugin_dir_url(string $file): string {
	return 'https://example.test/wp-content/plugins/cf7-ai-enquiry-validator/';
}

define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');

require_once dirname(__DIR__) . '/cf7-ai-enquiry-validator.php';

final class CF7AIVGroundedTransport implements CF7AIV\Infrastructure\HttpTransportInterface {
	/** @var list<CF7AIV\Infrastructure\HttpResponse> */ public array $responses;
	/** @var list<array{url:string,arguments:array<string,mixed>}> */ public array $requests = [];
	public function __construct(array $responses) { $this->responses = $responses; }
	public function post(string $url, array $arguments): CF7AIV\Infrastructure\HttpResponse {
		$this->requests[] = compact('url', 'arguments');
		$response = array_shift($this->responses);
		if (! $response instanceof CF7AIV\Infrastructure\HttpResponse) { throw new RuntimeException('Missing fake provider response.'); }
		return $response;
	}
}

$request = new CF7AIV\Domain\ClassificationRequest(
	'grounded-item',
	'agency-v2',
	'message: We need a website rebuild before October.',
	['message' => 'We need a website rebuild before October.'],
	'Classify the redacted enquiry.',
	['website_rebuild', 'insufficient_information'],
	['primary_opportunity', 'insufficient_information'],
	['service_fit' => 'service_fit', 'readiness_signal' => 'readiness'],
	CF7AIV\Classification\PolicyValidator::SCHEMA_V2,
	['competitor_or_comparison'],
	['conflicting_intent'],
);

$valid = [
	'outcome_id' => 'primary_opportunity',
	'tier_id' => 'website_rebuild',
	'reason_code' => 'qualified',
	'model_confidence' => 0.61,
	'evidence' => [
		['dimension' => 'service_fit', 'code' => 'service_fit', 'field_key' => 'message', 'excerpt' => 'website rebuild'],
		['dimension' => 'readiness', 'code' => 'readiness_signal', 'field_key' => 'message', 'excerpt' => 'before October'],
	],
	'missing_evidence' => [],
	'exclusion_codes' => [],
	'ambiguity_flags' => [],
	'summary' => 'Specific prospective project enquiry.',
];

$result = CF7AIV\Classification\ResultValidator::fromJson(json_encode($valid, JSON_THROW_ON_ERROR), $request);
grounded_assert(count($result->evidence) === 2 && $result->evidence[0]->grounded, 'Exact request spans must ground evidence.');
grounded_assert($result->confidence === 0.61, 'Model confidence remains diagnostic.');
grounded_assert($result->outcomeId === 'primary_opportunity', 'The validated result must preserve its universal outcome.');
grounded_assert($result->summary === 'Potential primary business opportunity.', 'Stored summaries must be deterministic and source-free.');

$hallucinated = $valid;
$hallucinated['evidence'][0]['excerpt'] = '£50,000 approved budget';
grounded_reject($hallucinated, $request, 'An excerpt absent from the request must be rejected.');

$wrongField = $valid;
$wrongField['evidence'][0]['field_key'] = 'email';
grounded_reject($wrongField, $request, 'Evidence must reference a whitelisted field present in the request.');

$wrongDimension = $valid;
$wrongDimension['evidence'][0]['dimension'] = 'readiness';
grounded_reject($wrongDimension, $request, 'Evidence dimensions must match the published evidence catalog.');

$unknownOutcome = $valid;
$unknownOutcome['outcome_id'] = 'invented_outcome';
grounded_reject($unknownOutcome, $request, 'Outcomes must be restricted by the published policy.');

$unknownExclusion = $valid;
$unknownExclusion['exclusion_codes'] = ['invented_exclusion'];
grounded_reject($unknownExclusion, $request, 'V2 exclusion codes must be restricted by the published policy.');

$unknownAmbiguity = $valid;
$unknownAmbiguity['ambiguity_flags'] = ['invented_ambiguity'];
grounded_reject($unknownAmbiguity, $request, 'V2 ambiguity flags must be restricted by the published policy.');

$catalogued = $valid;
$catalogued['exclusion_codes'] = ['competitor_or_comparison'];
$catalogued['ambiguity_flags'] = ['conflicting_intent'];
$cataloguedResult = CF7AIV\Classification\ResultValidator::fromJson(json_encode($catalogued, JSON_THROW_ON_ERROR), $request);
grounded_assert($cataloguedResult->exclusionCodes === ['competitor_or_comparison'] && $cataloguedResult->ambiguityFlags === ['conflicting_intent'], 'Published exclusion and ambiguity catalog values must remain valid.');

$extraProperty = $valid;
$extraProperty['reasoning'] = 'Private chain of thought.';
grounded_reject($extraProperty, $request, 'V2 results must reject properties outside the exact contract.');

grounded_assert(CF7AIV\Classification\EvidenceGrounder::ground('WEBSITE&nbsp; rebuild', 'We need a website rebuild before October.'), 'Grounding must normalize case, entities, and whitespace.');
grounded_assert(CF7AIV\Classification\EvidenceGrounder::ground('before October', ['later', 'Before October']), 'Grounding must inspect individual scalar array elements.');
grounded_assert(! CF7AIV\Classification\EvidenceGrounder::ground('x', 'x'), 'Grounding must reject excerpts shorter than two characters.');
grounded_assert(! CF7AIV\Classification\EvidenceGrounder::ground(str_repeat('x', 161), str_repeat('x', 161)), 'Grounding must reject excerpts longer than 160 characters.');

$usageResult = $result->withProviderUsage([
	'input_tokens' => '120',
	'output_tokens' => 45.8,
	'reasoning_tokens' => -9,
	'calls' => 0,
	'ignored' => 999,
]);
grounded_assert($usageResult->providerUsage === ['input_tokens' => 120, 'output_tokens' => 45, 'reasoning_tokens' => 0, 'calls' => 0], 'Provider usage must contain only normalized non-negative integer counters.');
grounded_assert($usageResult->evidence[0]->excerpt === 'website rebuild', 'Attaching provider usage must retain transient grounded assertions.');

final class CF7AIVGroundedWpdb {
	public string $prefix = 'wp_';
	/** @var list<array<string,mixed>> */ public array $updates = [];
	/** @var list<string> */ public array $queries = [];
	/** @var array<string,mixed> */ public array $row = [];
	public function prepare(string $sql, mixed ...$arguments): string {
		foreach ($arguments as $argument) {
			$replacement = is_int($argument) ? (string) $argument : "'" . str_replace("'", "''", (string) $argument) . "'";
			$sql = preg_replace('/%[ds]/', $replacement, $sql, 1) ?? $sql;
		}
		return $sql;
	}
	public function query(string $sql): bool {
		$this->queries[] = $sql;
		if (str_contains($sql, 'classification_attempts=classification_attempts+1')) {
			if (preg_match("/SET state='([^']+)'/", $sql, $state) === 1) { $this->row['state'] = $state[1]; }
			if (str_contains($sql, "review_state='error'")) { $this->row['review_state'] = 'error'; }
			if (preg_match("/last_error='((?:''|[^'])*)'/", $sql, $error) === 1) { $this->row['last_error'] = str_replace("''", "'", $error[1]); }
			$this->row['classification_attempts'] = (int) ($this->row['classification_attempts'] ?? 0) + 1;
		}
		return true;
	}
	public function get_row(string $sql, mixed $format = null): array { return $this->row; }
	public function get_var(string $sql): mixed { return str_contains($sql, 'classification_attempts') ? (int) ($this->row['classification_attempts'] ?? 0) : null; }
	public function update(string $table, array $data, array $where): bool {
		$this->updates[] = compact('table', 'data', 'where');
		$this->row = array_merge($this->row, $data);
		return true;
	}
}

$GLOBALS['wpdb'] = new CF7AIVGroundedWpdb();
(new CF7AIV\Classification\WpClassificationItemStore())->succeed(7, $usageResult, [
	'id' => 3,
	'version' => 'agency-v2',
	'compiled_prompt_hash' => str_repeat('a', 64),
], new CF7AIV\Classification\EvidenceDecision('strong_match', []), 'off', 'review', false);
$stored = $GLOBALS['wpdb']->updates[0]['data'] ?? [];
grounded_assert(isset($stored['evidence_json'], $stored['provider_usage_json']), 'Grounded result metadata and usage must be serialized by the item store.');
grounded_assert(($stored['outcome_id'] ?? null) === 'primary_opportunity'
	&& ($stored['predicted_tier_id'] ?? null) === 'website_rebuild'
	&& ($stored['predicted_outcome_id'] ?? null) === 'primary_opportunity'
	&& ($stored['decision_state'] ?? null) === 'strong_match'
	&& ($stored['verification_state'] ?? null) === 'off', 'The store must persist prediction, evidence-decision, and verification metadata.');
grounded_assert(($stored['missing_evidence_json'] ?? null) === '[]'
	&& ($stored['exclusion_json'] ?? null) === '[]'
	&& ($stored['confidence'] ?? null) === 0.61, 'The store must persist safe missing/exclusion metadata and retain confidence only as diagnostic output.');
$persisted = json_encode([
	$stored['evidence_json'] ?? null,
	$stored['provider_usage_json'] ?? null,
	$stored['summary'] ?? null,
], JSON_THROW_ON_ERROR);
foreach (['website rebuild', 'before October', 'prospect@example.test'] as $privateText) {
	grounded_assert(! str_contains($persisted, $privateText), "Stored result fields must not retain {$privateText}.");
}
grounded_assert(str_contains((string) $stored['evidence_json'], 'service_fit') && ! str_contains((string) $stored['evidence_json'], 'excerpt'), 'Stored evidence may retain codes and grounding status, but never excerpt properties.');

$GLOBALS['wpdb']->row = array_merge($GLOBALS['wpdb']->row, [
	'predicted_tier_id' => 'website_rebuild',
	'predicted_outcome_id' => 'primary_opportunity',
	'tier_id' => 'administrator_corrected',
	'outcome_id' => 'secondary_opportunity',
	'reviewed_tier_id' => 'administrator_corrected',
	'reviewed_outcome_id' => 'secondary_opportunity',
	'reviewed_at' => '2026-07-22 12:00:00',
	'reviewed_by' => 12,
	'review_state' => 'corrected',
]);
$humanAudit = static fn (array $row): array => array_intersect_key($row, array_flip([
	'tier_id', 'outcome_id', 'reviewed_tier_id', 'reviewed_outcome_id', 'reviewed_at', 'reviewed_by', 'review_state',
]));
$expectedHumanAudit = $humanAudit($GLOBALS['wpdb']->row);
$reanalysisQueue = [];
(new CF7AIV\Admin\ReviewService(
	new CF7AIV\Admin\WpReviewStore(),
	null,
	null,
	null,
	static function (int $itemId) use (&$reanalysisQueue): void { $reanalysisQueue[] = $itemId; },
))->apply(7, 'reanalyse', [], 77);
grounded_assert($humanAudit($GLOBALS['wpdb']->row) === $expectedHumanAudit && $reanalysisQueue === [7], 'The real review service/store must preserve corrected human audit when administrator 77 requests reanalysis of administrator 12 review.');
$replacement = new CF7AIV\Domain\ClassificationResult(
	'retained_marketing',
	'qualified',
	0.82,
	'Safe replacement.',
	[],
	'primary_opportunity',
	$usageResult->evidence,
);
$classificationStore = new CF7AIV\Classification\WpClassificationItemStore();
$classificationStore->succeed(7, $replacement, [
	'id' => 3,
	'version' => 'agency-v2',
	'compiled_prompt_hash' => str_repeat('b', 64),
], new CF7AIV\Classification\EvidenceDecision('strong_match', []), 'off', 'review', false);
grounded_assert($GLOBALS['wpdb']->row['predicted_tier_id'] === 'website_rebuild'
	&& $GLOBALS['wpdb']->row['tier_id'] === 'administrator_corrected'
	&& $GLOBALS['wpdb']->row['reviewed_tier_id'] === 'administrator_corrected', 'A direct ordinary store replay must preserve prediction, reviewer truth, and effective delivery fields.');
$classificationStore->succeed(7, $replacement, [
	'id' => 3,
	'version' => 'agency-v2',
	'compiled_prompt_hash' => str_repeat('b', 64),
], new CF7AIV\Classification\EvidenceDecision('strong_match', []), 'off', 'review', false, true);
grounded_assert($GLOBALS['wpdb']->row['predicted_tier_id'] === 'retained_marketing'
	&& $GLOBALS['wpdb']->row['predicted_outcome_id'] === 'primary_opportunity'
	&& $GLOBALS['wpdb']->row['tier_id'] === 'administrator_corrected'
	&& $GLOBALS['wpdb']->row['reviewed_tier_id'] === 'administrator_corrected'
	&& $humanAudit($GLOBALS['wpdb']->row) === $expectedHumanAudit
	&& ! str_contains(json_encode($GLOBALS['wpdb']->updates, JSON_THROW_ON_ERROR), 'cf7aiv_review_suggestions'), 'Explicit reanalysis may replace prediction diagnostics but must preserve reviewer/effective audit history and never erase review suggestions.');
$classificationStore->fail(7, 'Permanent reanalysis failure.', false, true);
grounded_assert(
	$humanAudit($GLOBALS['wpdb']->row) === $expectedHumanAudit
	&& $GLOBALS['wpdb']->row['last_error'] === 'Permanent reanalysis failure.'
	&& $GLOBALS['wpdb']->row['state'] === 'error',
	'A permanent explicit reanalysis failure may update classification error diagnostics but must preserve corrected human audit and effective fields.',
);

$schema = CF7AIV\Classification\ResultValidator::jsonSchema($request);
grounded_assert($schema['required'] === array_keys($valid), 'V2 provider schema must require exactly the grounded result contract.');
grounded_assert(($schema['properties']['outcome_id']['enum'] ?? []) === $request->allowedOutcomeIds, 'V2 schema must restrict outcomes to the published policy.');
grounded_assert(($schema['properties']['evidence']['items']['properties']['code']['enum'] ?? []) === array_keys($request->allowedEvidence), 'V2 schema must restrict evidence codes to the published catalog.');
grounded_assert(($schema['properties']['exclusion_codes']['items']['enum'] ?? []) === ['competitor_or_comparison'], 'V2 schema must restrict exclusion codes to the published catalog.');
grounded_assert(($schema['properties']['ambiguity_flags']['items']['enum'] ?? []) === ['conflicting_intent'], 'V2 schema must restrict ambiguity flags to the published catalog.');

$validJson = json_encode($valid, JSON_THROW_ON_ERROR);
$deepTransport = new CF7AIVGroundedTransport([new CF7AIV\Infrastructure\HttpResponse(200, json_encode([
	'choices' => [['message' => ['content' => $validJson]]],
	'usage' => ['prompt_tokens' => 101, 'completion_tokens' => 52, 'completion_tokens_details' => ['reasoning_tokens' => 7]],
], JSON_THROW_ON_ERROR))]);
$deepResult = (new CF7AIV\Providers\DeepSeekProvider($deepTransport, 'deep-secret'))->classify($request);
$deepPayload = json_decode((string) $deepTransport->requests[0]['arguments']['body'], true, 64, JSON_THROW_ON_ERROR);
$deepSystemPrompt = (string) ($deepPayload['messages'][0]['content'] ?? '');
grounded_assert(
	str_contains($deepSystemPrompt, 'Evidence code-to-dimension mapping: {"service_fit":"service_fit","readiness_signal":"readiness"}'),
	'DeepSeek must receive the exact published evidence code-to-dimension mapping so independently valid enum values cannot be paired incorrectly.',
);
grounded_assert(
	str_contains($deepSystemPrompt, 'Evidence pair examples: [{"code":"service_fit","dimension":"service_fit"},{"code":"readiness_signal","dimension":"readiness"}]')
		&& ! str_contains($deepSystemPrompt, 'Example JSON output:')
		&& ! str_contains($deepSystemPrompt, 'We need a website rebuild before October.'),
	'DeepSeek JSON mode must receive only concrete code-to-dimension pair examples without inventing tier/outcome semantics or promoting request text.',
);
grounded_assert(($deepPayload['max_tokens'] ?? null) === 900, 'DeepSeek v2 output must allow 900 tokens for grounded evidence.');
grounded_assert($deepResult->providerUsage === ['input_tokens' => 101, 'output_tokens' => 52, 'reasoning_tokens' => 7, 'calls' => 1], 'DeepSeek usage must be normalized onto the result.');

$multibyteRequest = new CF7AIV\Domain\ClassificationRequest(
	'multibyte-item',
	'agency-v2',
	'message: safe',
	['message' => str_repeat('a', 159) . 'é'],
	'Classify the redacted enquiry.',
	['website_rebuild', 'insufficient_information'],
	['primary_opportunity', 'insufficient_information'],
	['service_fit' => 'service_fit'],
	CF7AIV\Classification\PolicyValidator::SCHEMA_V2,
	[],
	[],
);
$multibyteValid = $valid;
$multibyteValid['evidence'] = [];
$multibyteTransport = new CF7AIVGroundedTransport([new CF7AIV\Infrastructure\HttpResponse(200, json_encode([
	'choices' => [['message' => ['content' => json_encode($multibyteValid, JSON_THROW_ON_ERROR)]]],
], JSON_THROW_ON_ERROR))]);
(new CF7AIV\Providers\DeepSeekProvider($multibyteTransport, 'deep-secret'))->classify($multibyteRequest);
$multibytePayload = json_decode((string) $multibyteTransport->requests[0]['arguments']['body'], true, 64, JSON_THROW_ON_ERROR);
$multibyteSystemPrompt = (string) ($multibytePayload['messages'][0]['content'] ?? '');
grounded_assert(
	is_array($multibytePayload) && ! str_contains($multibyteSystemPrompt, str_repeat('a', 159) . 'é'),
	'DeepSeek system guidance must remain valid JSON and must not elevate multibyte request-controlled text into its example.',
);

$deepRetryTransport = new CF7AIVGroundedTransport([
	new CF7AIV\Infrastructure\HttpResponse(200, json_encode([
		'choices' => [['message' => ['content' => '']]],
		'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 4, 'completion_tokens_details' => ['reasoning_tokens' => 3]],
	], JSON_THROW_ON_ERROR)),
	new CF7AIV\Infrastructure\HttpResponse(200, json_encode([
		'choices' => [['message' => ['content' => $validJson]]],
		'usage' => ['prompt_tokens' => 40, 'completion_tokens' => 20, 'completion_tokens_details' => ['reasoning_tokens' => 2]],
	], JSON_THROW_ON_ERROR)),
]);
$deepRetryResult = (new CF7AIV\Providers\DeepSeekProvider($deepRetryTransport, 'deep-secret'))->classify($request);
grounded_assert($deepRetryResult->providerUsage === ['input_tokens' => 70, 'output_tokens' => 24, 'reasoning_tokens' => 5, 'calls' => 2], 'Retry telemetry must sum usage from every returned response and count both HTTP calls.');

$openTransport = new CF7AIVGroundedTransport([new CF7AIV\Infrastructure\HttpResponse(200, json_encode([
	'output_text' => $validJson,
	'usage' => ['input_tokens' => 87, 'output_tokens' => 44, 'output_tokens_details' => ['reasoning_tokens' => 5]],
], JSON_THROW_ON_ERROR))]);
$openResult = (new CF7AIV\Providers\OpenAIProvider($openTransport, 'openai-secret'))->classify($request);
$openPayload = json_decode((string) $openTransport->requests[0]['arguments']['body'], true, 64, JSON_THROW_ON_ERROR);
grounded_assert(($openPayload['max_output_tokens'] ?? null) === 900, 'OpenAI v2 output must allow 900 tokens for grounded evidence.');
grounded_assert($openResult->providerUsage === ['input_tokens' => 87, 'output_tokens' => 44, 'reasoning_tokens' => 5, 'calls' => 1], 'OpenAI usage must be normalized onto the result.');
foreach ([json_encode($deepPayload, JSON_THROW_ON_ERROR), json_encode($openPayload, JSON_THROW_ON_ERROR)] as $providerPayload) {
	foreach (['email', 'phone', 'gclid', 'consent', 'attribution', 'deep-secret', 'openai-secret'] as $forbidden) {
		grounded_assert(! str_contains(strtolower($providerPayload), $forbidden), "V2 provider payloads must omit {$forbidden} data.");
	}
}

echo "PASS GroundedEvidenceTest\n";
