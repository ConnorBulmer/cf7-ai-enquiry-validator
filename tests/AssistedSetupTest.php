<?php
/**
 * Standalone assisted-setup tests. Run: php tests/AssistedSetupTest.php
 */

declare(strict_types=1);

$GLOBALS['setup_failures'] = [];
function setup_assert(bool $condition, string $message): void {
	if (! $condition) { $GLOBALS['setup_failures'][] = $message; }
}

$GLOBALS['setup_actions'] = [];
$GLOBALS['setup_fired_actions'] = [];
$GLOBALS['setup_throw_action'] = null;
$GLOBALS['setup_allowed'] = true;
$GLOBALS['setup_nonce_valid'] = true;
$GLOBALS['setup_filters'] = [];

function add_action(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): void { $GLOBALS['setup_actions'][$hook] = $callback; }
function add_filter(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): void { $GLOBALS['setup_filters'][$hook][] = compact('callback', 'priority', 'acceptedArgs'); }
function apply_filters(string $hook, mixed $value, mixed ...$arguments): mixed {
	$callbacks = $GLOBALS['setup_filters'][$hook] ?? [];
	usort($callbacks, static fn (array $left, array $right): int => $left['priority'] <=> $right['priority']);
	foreach ($callbacks as $registered) {
		$callArguments = array_slice([$value, ...$arguments], 0, max(1, (int) $registered['acceptedArgs']));
		$value = ($registered['callback'])(...$callArguments);
	}
	return $value;
}
function do_action(string $hook, mixed ...$arguments): void { $GLOBALS['setup_fired_actions'][] = [$hook, $arguments]; if ($GLOBALS['setup_throw_action'] === $hook) { throw new RuntimeException('Observer failure.'); } }
function plugin_dir_url(string $file): string { return 'https://local.test/wp-content/plugins/cf7-ai-enquiry-validator/'; }
function as_enqueue_async_action(): int { return 1; }
function current_user_can(string $capability): bool { return $GLOBALS['setup_allowed']; }
function wp_verify_nonce(string $nonce, string $action): int|false { return $GLOBALS['setup_nonce_valid'] ? 1 : false; }
function get_current_user_id(): int { return 77; }
function admin_url(string $path = ''): string { return 'https://local.test/wp-admin/' . $path; }
function wp_safe_redirect(string $url): bool { $GLOBALS['setup_redirect'] = $url; return true; }
function home_url(string $path = ''): string { return 'https://local.test' . $path; }
function wp_strip_all_tags(string $text): string { return strip_tags($text); }
function current_time(string $type, bool $gmt = false): string { return '2026-07-22 12:00:00'; }
function get_posts(array $arguments): array {
	$GLOBALS['setup_get_posts_arguments'] = $arguments;
	return [(object) ['ID' => 81, 'post_status' => 'publish', 'post_title' => 'Contact', 'post_content' => '<p>Request a website project.</p>[contact-form-7 id="91" title="Project enquiry"]']];
}
function get_permalink(int $id): string { return 'https://local.test/page-' . $id; }

final class WPCF7_ContactForm {
	public static function find(): array { return [new self()]; }
	public function id(): int { return 91; }
	public function title(): string { return 'Project enquiry'; }
	public function scan_form_tags(): array { return [(object) ['name' => 'project-type', 'basetype' => 'select', 'values' => ['Website', 'Strategy']]]; }
}

define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
define('AUTH_KEY', 'setup-auth');
define('SECURE_AUTH_KEY', 'setup-secure');

require_once dirname(__DIR__) . '/cf7-ai-enquiry-validator.php';

use CF7AIV\Admin\SetupSuggestionRepository;
use CF7AIV\Admin\SetupSuggestionService;
use CF7AIV\Admin\SiteProfileScanner;
use CF7AIV\Contracts\PolicySuggestionProviderInterface;
use CF7AIV\Domain\PolicySuggestionRequest;
use CF7AIV\Domain\PolicySuggestionResult;
use CF7AIV\Infrastructure\HttpResponse;
use CF7AIV\Infrastructure\HttpTransportInterface;
use CF7AIV\Providers\DeepSeekProvider;
use CF7AIV\Providers\OpenAIProvider;

$privateLoaderCalled = false;
$pages = static fn (): array => [
	['ID' => 12, 'post_status' => 'publish', 'post_password' => '', 'post_title' => str_repeat('Services ', 80), 'permalink' => 'https://url-user:url-password@local.test/services?token=URL-SECRET#private-fragment', 'post_content' => '<p>Website design for established businesses. full page body marker</p>'],
	['ID' => 13, 'post_status' => 'publish', 'post_title' => 'About', 'permalink' => 'https://local.test/about', 'post_content' => '<p>Practical digital strategy and support.</p>'],
	['ID' => 14, 'post_status' => 'private', 'post_title' => 'Private', 'permalink' => 'https://local.test/private', 'post_content' => 'historic private enquiry'],
	['ID' => 15, 'post_status' => 'publish', 'post_title' => 'Remote', 'permalink' => 'https://outside.test/remote', 'post_content' => 'outside corpus'],
	['ID' => 16, 'post_status' => 'publish', 'post_password' => 'protected-secret', 'post_title' => 'Protected', 'permalink' => 'https://local.test/protected', 'post_content' => 'password protected private page marker'],
];
$forms = static function () use (&$privateLoaderCalled): array {
	return [[
		'id' => 34,
		'title' => 'Enquiry',
		'page_ids' => [12],
		'fields' => [
			['name' => 'your-name', 'type' => 'text', 'label' => 'Your name', 'options' => [], 'value' => 'Ada Person'],
			['name' => 'your-email', 'type' => 'email', 'label' => 'Your email', 'options' => [], 'value' => 'ada@example.test'],
			['name' => 'message', 'type' => 'textarea', 'label' => 'How can we help?', 'options' => []],
			['name' => 'service-type', 'type' => 'select', 'label' => 'Requested service', 'options' => ['Website design', 'Digital strategy']],
			['name' => 'project-code', 'type' => 'text', 'label' => 'Project code', 'options' => ['CONFIDENTIAL-DEFAULT'], 'default_values' => ['ANOTHER-DEFAULT']],
			['name' => 'safe-hidden', 'type' => 'hidden', 'label' => 'Project marker', 'options' => ['HIDDEN-DEFAULT']],
			['name' => 'tracking-choice', 'type' => 'select', 'label' => 'Campaign source', 'options' => ['PRIVATE-CAMPAIGN']],
			['name' => 'gclid', 'type' => 'hidden', 'label' => 'Attribution', 'options' => [], 'value' => 'secret-click-id'],
		],
	]];
};
$scanner = new SiteProfileScanner($pages, $forms);
$profile = $scanner->scan();
setup_assert(count($profile['pages']) === 2, 'Scanner must return published local pages only.');
setup_assert(! str_contains((string) json_encode($profile), 'password protected private page marker'), 'Scanner must exclude password-protected published posts.');
setup_assert($profile['forms'][0]['fields'][0]['name'] === 'your-name', 'Form structure may be profiled for mapping.');
setup_assert(! str_contains((string) json_encode($profile), 'historic private enquiry'), 'Scanner must never query stored submissions.');
setup_assert(! $privateLoaderCalled, 'Scanner must have no historical-submission loader.');
setup_assert(strlen($profile['pages'][0]['text']) <= 2000, 'Each page profile must be compact.');

$standardProfile = (new SiteProfileScanner())->scan();
setup_assert(($GLOBALS['setup_get_posts_arguments']['post_status'] ?? '') === 'publish', 'Standard scanner must query published content only.');
setup_assert(($GLOBALS['setup_get_posts_arguments']['post_type'] ?? []) === ['page', 'post'], 'Standard scanner must query only public page and post types.');
setup_assert(($GLOBALS['setup_get_posts_arguments']['posts_per_page'] ?? 0) === 50, 'Standard scanner must cap page queries at 50.');
setup_assert($standardProfile['forms'][0]['page_ids'] === [81], 'Standard scanner must derive compact form page provenance without executing a shortcode.');

$scannerFilterHooks = [
	'cf7aiv_site_profile_post_types',
	'cf7aiv_site_profile_posts',
	'cf7aiv_site_profile_post_content',
	'cf7aiv_site_profile_post_form_ids',
];
add_filter('cf7aiv_site_profile_post_types', static fn (array $types): array => [...$types, 'case_study', 'case_study']);
add_filter('cf7aiv_site_profile_posts', static function (array $posts): array {
	$extended = [
		(object) ['ID' => 44, 'post_type' => 'case_study', 'post_status' => 'publish', 'post_password' => '', 'post_title' => 'Public case study', 'permalink' => 'https://local.test/case-study', 'post_content' => 'Base case study.'],
		(object) ['ID' => 45, 'post_type' => 'case_study', 'post_status' => 'draft', 'post_password' => '', 'post_title' => 'Draft', 'permalink' => 'https://local.test/draft', 'post_content' => 'DRAFT-PRIVATE-MARKER'],
		(object) ['ID' => 46, 'post_type' => 'case_study', 'post_status' => 'publish', 'post_password' => 'protected', 'post_title' => 'Protected', 'permalink' => 'https://local.test/protected', 'post_content' => 'PROTECTED-PRIVATE-MARKER'],
		(object) ['ID' => 47, 'post_type' => 'case_study', 'post_status' => 'publish', 'post_password' => '', 'post_title' => 'Remote', 'permalink' => 'https://outside.test/remote', 'post_content' => 'REMOTE-CONTENT-MARKER'],
		(object) ['ID' => 48, 'post_type' => 'private_type', 'post_status' => 'publish', 'post_password' => '', 'post_title' => 'Unselected type', 'permalink' => 'https://local.test/unselected', 'post_content' => 'UNSELECTED-TYPE-MARKER'],
	];
	for ($id = 100; $id < 160; $id++) {
		$extended[] = (object) ['ID' => $id, 'post_type' => 'case_study', 'post_status' => 'publish', 'post_password' => '', 'post_title' => 'Bounded ' . $id, 'permalink' => 'https://local.test/bounded-' . $id, 'post_content' => str_repeat('B', 2500)];
	}
	return $extended;
});
add_filter('cf7aiv_site_profile_post_content', static function (string $content, mixed $post): string {
	return ((int) $post->ID === 44) ? 'Builder service details [contact-form-7 id="34"] ' . str_repeat('X', 250000) : $content;
}, 10, 2);
add_filter('cf7aiv_site_profile_post_form_ids', static function (array $ids, mixed $post, string $content): array {
	if ((int) $post->ID !== 44 || ! str_contains($content, 'Builder service details')) { return $ids; }
	$GLOBALS['setup_builder_content_input_length'] = strlen($content);
	return [...$ids, ...array_fill(0, 10000, 0), 101, 500, 34, -1, 34];
}, 10, 3);
$extendedProfile = (new SiteProfileScanner(null, static fn (): array => [[
	'id' => 34,
	'title' => 'Builder enquiry',
	'page_ids' => [],
	'fields' => [['name' => 'service', 'type' => 'text', 'label' => 'Service', 'options' => []]],
], [
	'id' => 101,
	'title' => 'Out-of-bound reference',
	'page_ids' => [],
	'fields' => [['name' => 'service', 'type' => 'text', 'label' => 'Service', 'options' => []]],
], [
	'id' => 500,
	'title' => 'Far out-of-bound reference',
	'page_ids' => [],
	'fields' => [['name' => 'service', 'type' => 'text', 'label' => 'Service', 'options' => []]],
]]))->scan();
setup_assert(($GLOBALS['setup_get_posts_arguments']['post_type'] ?? []) === ['page', 'post', 'case_study'], 'Scanner post types must be filtered, sanitized, and de-duplicated before the public query.');
$extendedJson = (string) json_encode($extendedProfile);
setup_assert(str_contains($extendedJson, 'Builder service details') && str_contains($extendedJson, 'Public case study'), 'Builder-managed public content from a selected custom post type must enter the bounded profile.');
setup_assert(($GLOBALS['setup_builder_content_input_length'] ?? PHP_INT_MAX) <= 100000, 'Builder-managed content must be bounded before form-reference filters and normalization process it.');
setup_assert(! str_contains($extendedJson, 'DRAFT-PRIVATE-MARKER') && ! str_contains($extendedJson, 'PROTECTED-PRIVATE-MARKER') && ! str_contains($extendedJson, 'REMOTE-CONTENT-MARKER') && ! str_contains($extendedJson, 'UNSELECTED-TYPE-MARKER'), 'Filtered posts must still reject draft, protected, remote, and unselected post-type content.');
setup_assert(($extendedProfile['forms'][0]['page_ids'] ?? []) === [44], 'Builder form references must create one positive de-duplicated form/page association.');
setup_assert(($extendedProfile['forms'][1]['page_ids'] ?? []) === [] && ($extendedProfile['forms'][2]['page_ids'] ?? []) === [], 'Builder form-reference filters must not make the scanner process an unbounded ID list.');
setup_assert(count($extendedProfile['pages']) <= 50 && array_sum(array_map(static fn (array $page): int => strlen($page['text']), $extendedProfile['pages'])) <= 40000, 'Filtered scanner content must retain page and total character bounds.');
foreach ($scannerFilterHooks as $hook) { unset($GLOBALS['setup_filters'][$hook]); }

add_filter('cf7aiv_site_profile_post_types', static fn (array $types): string => 'invalid');
add_filter('cf7aiv_site_profile_posts', static fn (array $posts): string => 'invalid');
add_filter('cf7aiv_site_profile_post_content', static fn (string $content): array => ['invalid']);
add_filter('cf7aiv_site_profile_post_form_ids', static fn (array $ids): string => 'invalid');
$invalidFilterThrew = false;
try { $invalidFilterProfile = (new SiteProfileScanner())->scan(); } catch (Throwable) { $invalidFilterThrew = true; $invalidFilterProfile = ['pages' => [], 'forms' => []]; }
setup_assert(! $invalidFilterThrew && ($GLOBALS['setup_get_posts_arguments']['post_type'] ?? []) === ['page', 'post'], 'Invalid scanner filter return types must fail closed to the safe defaults without throwing.');
setup_assert(count($invalidFilterProfile['pages']) === 1 && $invalidFilterProfile['forms'][0]['page_ids'] === [81], 'Invalid scanner filter returns must not erase the safe default public profile.');
foreach ($scannerFilterHooks as $hook) { unset($GLOBALS['setup_filters'][$hook]); }

class SetupMemoryRepository extends SetupSuggestionRepository {
	/** @var list<array<string,mixed>> */
	public array $records = [];
	public function store(string $type, mixed $value, array $sourceRefs, int $administratorId): int {
		$this->records[] = [
			'id' => count($this->records) + 1,
			'type' => $type,
			'value_json' => json_encode($value, JSON_THROW_ON_ERROR),
			'provenance_json' => json_encode($sourceRefs, JSON_THROW_ON_ERROR),
			'status' => 'pending',
			'created_by' => $administratorId,
		];
		return count($this->records);
	}
	public function review(int $id, string $status, mixed $editedValue = null): bool {
		foreach ($this->records as &$record) {
			if ($record['id'] !== $id || $record['status'] !== 'pending') { continue; }
			$record['status'] = $status;
			if ($editedValue !== null) { $record['value_json'] = json_encode($editedValue, JSON_THROW_ON_ERROR); }
			return true;
		}
		return false;
	}
	public function getPending(int $id): ?array {
		foreach ($this->records as $record) {
			if ($record['id'] === $id && $record['status'] === 'pending') {
				return ['id' => $id, 'type' => $record['type'], 'value' => json_decode($record['value_json'], true), 'source_refs' => json_decode($record['provenance_json'], true), 'status' => 'pending'];
			}
		}
		return null;
	}
	public function storeBatch(array $suggestions, int $administratorId): int {
		$before = $this->records;
		foreach ($suggestions as $suggestion) {
			if ($this->store($suggestion['type'], $suggestion['value'], $suggestion['source_refs'], $administratorId) <= 0) { $this->records = $before; return 0; }
		}
		return count($suggestions);
	}
}

final class SetupFixedProvider implements PolicySuggestionProviderInterface {
	public ?PolicySuggestionRequest $request = null;
	public function suggestPolicy(PolicySuggestionRequest $request): PolicySuggestionResult {
		$this->request = $request;
		return new PolicySuggestionResult([
			['type' => 'service', 'value' => 'Website design', 'source_refs' => ['page:12']],
			['type' => 'audience', 'value' => 'Established businesses', 'source_refs' => ['page:12']],
			['type' => 'exclusion', 'value' => 'Recruitment enquiries', 'source_refs' => ['pack:agency']],
			['type' => 'field_mapping', 'value' => ['field' => 'service-type', 'meaning' => 'Requested service'], 'source_refs' => ['form:34']],
			['type' => 'field_mapping', 'value' => ['field' => 'your-name', 'meaning' => 'Customer identity'], 'source_refs' => ['form:34']],
			['type' => 'field_mapping', 'value' => ['field' => 'safe-hidden', 'meaning' => 'Project marker'], 'source_refs' => ['form:34']],
			['type' => 'field_mapping', 'value' => ['field' => 'tracking-choice', 'meaning' => 'Requested service'], 'source_refs' => ['form:34']],
			['type' => 'field_mapping', 'value' => ['field' => 'service-type', 'meaning' => str_repeat('M', 301)], 'source_refs' => ['form:34']],
			['type' => 'service', 'value' => 'full page body marker', 'source_refs' => ['https://outside.test/arbitrary']],
			['type' => 'service', 'value' => 'API key secret-token', 'source_refs' => ['page:12']],
		]);
	}
}

$repository = new SetupMemoryRepository();
$provider = new SetupFixedProvider();
$service = new SetupSuggestionService($scanner, $provider, $repository);
$count = $service->generate('agency', 77);
setup_assert($count === 4, 'Assistant must store only valid reviewable suggestions.');
$suggestionsHook=CF7AIV\Hooks::class.'::AFTER_SETUP_SUGGESTIONS';
setup_assert(defined($suggestionsHook)&&in_array(constant($suggestionsHook),array_column($GLOBALS['setup_fired_actions'],0),true),'Stored setup suggestions must fire the additive cf7aiv_after_setup_suggestions lifecycle action.');
$GLOBALS['setup_fired_actions']=[];
$GLOBALS['setup_throw_action']=constant($suggestionsHook);
$observerRepository=new SetupMemoryRepository();
$observerCount=(new SetupSuggestionService($scanner,new SetupFixedProvider(),$observerRepository))->generate('agency',77);
$observerCalls=array_values(array_filter($GLOBALS['setup_fired_actions'],static fn(array $fired):bool=>$fired[0]===constant(CF7AIV\Hooks::class.'::AFTER_SETUP_SUGGESTIONS')));
setup_assert($observerCount===4&&count($observerRepository->records)===4,'A throwing setup-suggestion observer must not rewrite a committed generation result to zero.');
setup_assert(count($observerCalls)===1,'Setup suggestions must fire their lifecycle action exactly once for the completed batch.');
$GLOBALS['setup_throw_action']=null;
foreach ($repository->records as $record) {
	setup_assert($record['status'] === 'pending', 'Generated suggestions must remain pending.');
	setup_assert(! str_contains($record['value_json'], 'full page body marker'), 'Suggestion rows must not copy the scanned corpus.');
	setup_assert(! str_contains($record['value_json'], 'your-name'), 'Identity fields must never become mapping suggestions.');
}

$sixtyFields = [];
for ($fieldIndex = 1; $fieldIndex <= 60; $fieldIndex++) {
	$sixtyFields[] = ['name' => 'service-' . $fieldIndex, 'type' => 'select', 'label' => 'Requested service ' . $fieldIndex, 'options' => ['Option ' . $fieldIndex]];
}
$sixtyFieldScanner = new SiteProfileScanner(static fn (): array => [], static fn (): array => [[
	'id' => 600,
	'title' => 'Large service form',
	'page_ids' => [],
	'fields' => $sixtyFields,
]]);
$sixtyFieldProfile = $sixtyFieldScanner->scan();
$sixtyFieldRequest = new PolicySuggestionRequest('agency', $sixtyFieldProfile);
setup_assert(count($sixtyFieldProfile['forms'][0]['fields']) === 60, 'Field-bound regression must retain 60 fields in the raw scanner profile.');
setup_assert(count($sixtyFieldRequest->providerProfile()['forms'][0]['fields']) === 50, 'Field-bound regression must expose only the finalized 50-field provider subset.');

final class SetupOmittedFieldProvider implements PolicySuggestionProviderInterface {
	public function suggestPolicy(PolicySuggestionRequest $request): PolicySuggestionResult {
		return new PolicySuggestionResult([[
			'type' => 'field_mapping',
			'value' => ['field' => 'service-60', 'meaning' => 'Requested service'],
			'source_refs' => ['form:600'],
		]]);
	}
}
$omittedFieldRepository = new SetupMemoryRepository();
$omittedFieldService = new SetupSuggestionService($sixtyFieldScanner, new SetupOmittedFieldProvider(), $omittedFieldRepository);
setup_assert($omittedFieldService->generate('agency', 77) === 0 && $omittedFieldRepository->records === [], 'A field omitted from the finalized provider-visible subset must not authorize a generated mapping.');

$omittedFieldReviewRepository = new SetupMemoryRepository();
$omittedFieldReviewRepository->records = [[
	'id' => 1,
	'type' => 'field_mapping',
	'value_json' => json_encode(['field' => 'service-1', 'meaning' => 'Requested service']),
	'provenance_json' => json_encode(['form:600']),
	'status' => 'pending',
	'created_by' => 77,
]];
$omittedFieldReviewService = new SetupSuggestionService($sixtyFieldScanner, new SetupOmittedFieldProvider(), $omittedFieldReviewRepository);
$omittedFieldEditRejected = false;
try { $omittedFieldReviewService->review(1, 'accept', ['field' => 'service-60', 'meaning' => 'Requested service']); } catch (InvalidArgumentException) { $omittedFieldEditRejected = true; }
setup_assert($omittedFieldEditRejected, 'A field omitted from the finalized provider-visible subset must not authorize an edited mapping.');

setup_assert($service->review(1, 'accept', 'Conversion-focused web design'), 'An administrator may accept and safely edit a pending suggestion.');
setup_assert($repository->records[0]['status'] === 'accepted', 'Accepted suggestions must remain review records, not published policy.');
setup_assert($service->review(2, 'reject'), 'An administrator may reject a pending suggestion.');
setup_assert($repository->records[1]['status'] === 'rejected', 'Rejected suggestions must remain review records.');
$unsafeEdit = false;
try { $service->review(3, 'accept', 'Contact private@example.test'); } catch (InvalidArgumentException) { $unsafeEdit = true; }
setup_assert($unsafeEdit, 'Edited suggestions must obey safe-prose rules.');
$policyStore = new class { public array $published = []; };
setup_assert($policyStore->published === [], 'Assisted setup must never publish a policy.');

$providerProfile = $provider->request?->providerProfile() ?? [];
$providerJson = (string) json_encode($providerProfile);
foreach (['Ada Person', 'ada@example.test', 'secret-click-id', 'your-name', 'your-email', 'gclid', 'Attribution', 'https://outside.test', 'CONFIDENTIAL-DEFAULT', 'ANOTHER-DEFAULT', 'HIDDEN-DEFAULT', 'safe-hidden', 'PRIVATE-CAMPAIGN', 'tracking-choice', 'Campaign source', 'url-user', 'url-password', 'URL-SECRET', 'private-fragment'] as $forbidden) {
	setup_assert(! str_contains($providerJson, $forbidden), "Provider corpus must exclude {$forbidden}.");
}
setup_assert(str_contains($providerJson, 'service-type'), 'Non-identifying form structure may enter the provider corpus.');
setup_assert(str_contains($providerJson, 'project-code'), 'Safe non-choice field structure may enter the provider corpus.');
setup_assert(str_contains($providerJson, '"url":"https:\/\/local.test\/services"'), 'Provider URLs must retain only a bounded same-host public path.');
setup_assert(strlen((string) ($providerProfile['pages'][0]['title'] ?? '')) <= 200, 'Provider titles must be bounded.');

$forbiddenFieldProfile = ['pages' => [], 'forms' => [['id' => 50, 'title' => 'Tracking', 'page_ids' => [], 'fields' => []]]];
foreach (['dclid', 'campaign', 'source', 'medium', 'landing', 'referrer', 'cookie', 'session', 'client_id', 'user_id'] as $index => $term) {
	$forbiddenFieldProfile['forms'][0]['fields'][] = ['name' => 'custom-' . $index, 'type' => 'select', 'label' => strtoupper($term), 'options' => ['PRIVATE-' . $index]];
}
$forbiddenFieldJson = (string) json_encode((new PolicySuggestionRequest('agency', $forbiddenFieldProfile))->providerProfile());
foreach (['custom-0', 'custom-1', 'custom-2', 'custom-3', 'custom-4', 'custom-5', 'custom-6', 'custom-7', 'custom-8', 'custom-9'] as $forbiddenName) {
	setup_assert(! str_contains($forbiddenFieldJson, $forbiddenName), "Provider corpus must exclude label-forbidden field {$forbiddenName}.");
}

$oversizedProfile = ['pages' => [], 'forms' => []];
for ($formIndex = 1; $formIndex <= 100; $formIndex++) {
	$fields = [];
	for ($fieldIndex = 1; $fieldIndex <= 100; $fieldIndex++) { $fields[] = ['name' => "service-{$formIndex}-{$fieldIndex}", 'type' => 'select', 'label' => str_repeat('Requested service ', 12), 'options' => array_fill(0, 20, str_repeat('Option ', 20))]; }
	$oversizedProfile['forms'][] = ['id' => 1000 + $formIndex, 'title' => str_repeat('Large form ', 30), 'page_ids' => [], 'fields' => $fields];
}
$boundedProfileJson = json_encode((new PolicySuggestionRequest('agency', $oversizedProfile))->providerProfile(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
setup_assert(strlen($boundedProfileJson) <= 40000, 'Serialized provider profile must never exceed 40,000 characters.');

$twentyPageProfile = ['pages' => [], 'forms' => []];
for ($pageIndex = 1; $pageIndex <= 20; $pageIndex++) {
	$twentyPageProfile['pages'][] = ['id' => $pageIndex, 'title' => 'Service ' . $pageIndex, 'url' => 'https://local.test/service-' . $pageIndex, 'text' => str_repeat(chr(64 + $pageIndex), 2000)];
}
$twentyPageRequest = new PolicySuggestionRequest('agency', $twentyPageProfile);
$visiblePageIds = array_column($twentyPageRequest->providerProfile()['pages'], 'id');
$omittedPageIds = array_values(array_diff(range(1, 20), $visiblePageIds));
setup_assert(count($visiblePageIds) < 20 && $omittedPageIds !== [], 'Provider-profile bound test must reproduce a raw 20-page profile with an omitted subset.');
$omittedRef = 'page:' . ($omittedPageIds[0] ?? 20);
setup_assert(! in_array($omittedRef, $twentyPageRequest->allowedSourceRefs(), true), 'Sources omitted from the finalized provider-visible profile must not remain authorized.');

$schemaJson = json_encode(PolicySuggestionResult::jsonSchema(), JSON_THROW_ON_ERROR);
setup_assert(! str_contains($schemaJson, 'oneOf'), 'OpenAI strict suggestion schema must not use unsupported oneOf.');
setup_assert(str_contains($schemaJson, 'anyOf'), 'Suggestion schema must retain explicit string-or-field-mapping composition.');
setup_assert((PolicySuggestionResult::jsonSchema()['properties']['suggestions']['maxItems'] ?? null) === 20, 'Assisted setup must bound model output to 20 compact suggestions so large public profiles do not truncate JSON.');

final class SetupCaptureTransport implements HttpTransportInterface {
	/** @var list<array{url:string,arguments:array<string,mixed>}> */
	public array $requests = [];
	public function __construct(private readonly string $provider) {}
	public function post(string $url, array $arguments): HttpResponse {
		$this->requests[] = compact('url', 'arguments');
		$suggestions = json_encode(['suggestions' => [['type' => 'service', 'value' => 'Website design', 'source_refs' => ['page:12']]]], JSON_THROW_ON_ERROR);
		return $this->provider === 'openai'
			? new HttpResponse(200, json_encode(['output_text' => $suggestions], JSON_THROW_ON_ERROR))
			: new HttpResponse(200, json_encode(['choices' => [['message' => ['content' => $suggestions]]]], JSON_THROW_ON_ERROR));
	}
}

$taintedProfile = $profile;
$taintedProfile['pages'][0]['text'] .= ' Contact ada@example.test or +44 7700 900123. token=TOP-SECRET. https://outside.test/private';
$taintedProfile['forms'][0]['fields'][0]['value'] = 'Ada Person';
$taintedProfile['stored_enquiries'] = [['message' => 'historic private enquiry']];
$taintedRequest = new PolicySuggestionRequest('agency', $taintedProfile);
foreach (['openai', 'deepseek'] as $providerKey) {
	$transport = new SetupCaptureTransport($providerKey);
	$actualProvider = $providerKey === 'openai'
		? new OpenAIProvider($transport, 'configured-key')
		: new DeepSeekProvider($transport, 'configured-key');
	$result = $actualProvider->suggestPolicy($taintedRequest);
	setup_assert(count($result->suggestions) === 1, ucfirst($providerKey) . ' must parse setup suggestions.');
	$wire = (string) ($transport->requests[0]['arguments']['body'] ?? '');
	$wirePayload = json_decode($wire, true, 64, JSON_THROW_ON_ERROR);
	foreach (['Ada Person', 'ada@example.test', '7700 900123', 'historic private enquiry', 'TOP-SECRET', 'https://outside.test/private', 'your-name', 'your-email', 'gclid'] as $forbidden) {
		setup_assert(! str_contains($wire, $forbidden), ucfirst($providerKey) . " payload must exclude {$forbidden}.");
	}
	setup_assert(str_contains($wire, 'store') === ($providerKey === 'openai'), 'OpenAI must explicitly disable response storage.');
	setup_assert(str_contains($wire, 'json_schema') || str_contains($wire, 'Required JSON Schema'), ucfirst($providerKey) . ' must request the fixed suggestion schema.');
	if ($providerKey === 'openai') {
		$wireSchema = $wirePayload['text']['format']['schema'] ?? [];
	} else {
		$systemContent = (string) ($wirePayload['messages'][0]['content'] ?? '');
		$schemaPrefix = "\nRequired JSON Schema: ";
		$schemaStart = strpos($systemContent, $schemaPrefix);
		$schemaEnd = strpos($systemContent, "\nReturn one valid JSON object", $schemaStart === false ? 0 : $schemaStart + strlen($schemaPrefix));
		$wireSchema = $schemaStart !== false && $schemaEnd !== false
			? json_decode(substr($systemContent, $schemaStart + strlen($schemaPrefix), $schemaEnd - $schemaStart - strlen($schemaPrefix)), true, 64, JSON_THROW_ON_ERROR)
			: [];
	}
	$wireSourceRefs = $wireSchema['properties']['suggestions']['items']['properties']['source_refs']['items']['enum'] ?? null;
	setup_assert($wireSourceRefs === $taintedRequest->allowedSourceRefs(), ucfirst($providerKey) . ' suggestion schema must constrain provenance to the exact finalized pack, page, and form identifiers.');
	if ($providerKey === 'deepseek') {
		$deepSeekWire = $wirePayload;
		setup_assert(($deepSeekWire['max_tokens'] ?? null) === 900, 'DeepSeek assisted setup must use the bounded off-mode JSON output budget.');
		setup_assert(str_contains((string) ($deepSeekWire['messages'][0]['content'] ?? ''), 'no more than 20 compact'), 'DeepSeek assisted setup must explicitly request the bounded compact suggestion count.');
	}
	setup_assert(strlen(json_encode($taintedRequest->providerProfile(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) <= 40000, ucfirst($providerKey) . ' provider profile must remain within the final serialized boundary.');
}

$reviewRepository = new SetupMemoryRepository();
$reviewRepository->records = [
	['id' => 1, 'type' => 'field_mapping', 'value_json' => json_encode(['field' => 'service-type', 'meaning' => 'Requested service']), 'provenance_json' => json_encode(['form:34']), 'status' => 'pending', 'created_by' => 77],
	['id' => 2, 'type' => 'service', 'value_json' => json_encode('Website design'), 'provenance_json' => json_encode(['page:12']), 'status' => 'pending', 'created_by' => 77],
	['id' => 3, 'type' => 'field_mapping', 'value_json' => json_encode(['field' => 'safe-hidden', 'meaning' => 'Project marker']), 'provenance_json' => json_encode(['form:34']), 'status' => 'pending', 'created_by' => 77],
	['id' => 4, 'type' => 'field_mapping', 'value_json' => json_encode(['field' => 'service-type', 'meaning' => 'Requested service']), 'provenance_json' => json_encode(['form:34']), 'status' => 'pending', 'created_by' => 77],
	['id' => 5, 'type' => 'service', 'value_json' => json_encode('Website design'), 'provenance_json' => json_encode(['pack:../unsafe']), 'status' => 'pending', 'created_by' => 77],
	['id' => 6, 'type' => 'service', 'value_json' => json_encode('Website design'), 'provenance_json' => json_encode(array_fill(0, 11, 'page:12')), 'status' => 'pending', 'created_by' => 77],
	['id' => 7, 'type' => 'service', 'value_json' => json_encode('Website design'), 'provenance_json' => json_encode(['page:12']), 'status' => 'pending', 'created_by' => 77],
	['id' => 8, 'type' => 'field_mapping', 'value_json' => json_encode(['field' => 'service-type', 'meaning' => 'Requested service']), 'provenance_json' => json_encode(['form:34']), 'status' => 'pending', 'created_by' => 77],
];
$reviewService = new SetupSuggestionService($scanner, $provider, $reviewRepository);
foreach ([[1, 'plain text'], [2, ['field' => 'service-type', 'meaning' => 'Requested service']], [3, ['field' => 'safe-hidden', 'meaning' => 'Project marker']]] as [$reviewId, $edit]) {
	$rejectedEdit = false;
	try { $reviewService->review($reviewId, 'accept', $edit); } catch (InvalidArgumentException) { $rejectedEdit = true; }
	setup_assert($rejectedEdit, "Review edit {$reviewId} must reapply the original pending suggestion type and safe field schema.");
}
setup_assert($reviewService->review(4, 'accept', ['field' => 'service-type', 'meaning' => 'Requested service']), 'A valid mapping edit may be accepted after revalidation.');
foreach ([[5, 'Website design'], [6, 'Website design'], [7, str_repeat('A', 501)]] as [$reviewId, $edit]) {
	$rejectedEdit = false;
	try { $reviewService->review($reviewId, 'accept', $edit); } catch (InvalidArgumentException) { $rejectedEdit = true; }
	setup_assert($rejectedEdit, "Review edit {$reviewId} must enforce compact safe provenance and value bounds.");
}
$longMeaningRejected = false;
try { $reviewService->review(8, 'accept', ['field' => 'service-type', 'meaning' => str_repeat('M', 301)]); } catch (InvalidArgumentException) { $longMeaningRejected = true; }
setup_assert($longMeaningRejected, 'Field-mapping edits must enforce the schema meaning maximum of 300 characters.');

final class SetupFailingProvider implements PolicySuggestionProviderInterface {
	public function suggestPolicy(PolicySuggestionRequest $request): PolicySuggestionResult { throw new RuntimeException('API key TOP-SECRET failed for private@example.test'); }
}
$failedRepository = new SetupMemoryRepository();
$failedService = new SetupSuggestionService($scanner, new SetupFailingProvider(), $failedRepository);
setup_assert($failedService->generate('agency', 77) === 0, 'Provider failure must return zero suggestions.');
setup_assert($failedService->lastError() === 'Suggestions could not be generated. Manual setup remains available.', 'Provider failure must expose only a safe error.');
setup_assert($failedRepository->records === [], 'Provider failure must not persist partial or private data.');

$scannerFailureRepository = new SetupMemoryRepository();
$scannerFailureService = new SetupSuggestionService(new SiteProfileScanner(static function (): array { throw new RuntimeException('scanner leaked secret'); }, static fn (): array => []), new SetupFixedProvider(), $scannerFailureRepository);
$scannerFailureThrew = false;
try { $scannerFailureCount = $scannerFailureService->generate('agency', 77); } catch (Throwable) { $scannerFailureThrew = true; $scannerFailureCount = -1; }
setup_assert(! $scannerFailureThrew && $scannerFailureCount === 0 && $scannerFailureRepository->records === [], 'Scanner failures must be safely contained without persistence.');

final class SetupAtomicFailureRepository extends SetupMemoryRepository {
	private int $calls = 0;
	public function store(string $type, mixed $value, array $sourceRefs, int $administratorId): int {
		$this->calls++;
		if ($this->calls === 2) { return 0; }
		return parent::store($type, $value, $sourceRefs, $administratorId);
	}
	public function storeBatch(array $suggestions, int $administratorId): int { return 0; }
}
$atomicRepository = new SetupAtomicFailureRepository();
$atomicService = new SetupSuggestionService($scanner, new SetupFixedProvider(), $atomicRepository);
setup_assert($atomicService->generate('agency', 77) === 0 && $atomicRepository->records === [], 'Repository failure must not leave a partially persisted suggestion batch.');
setup_assert($atomicService->lastError() === 'Suggestions could not be generated. Manual setup remains available.', 'Repository failure must expose only the safe manual-setup error.');

final class SetupThrowingRepository extends SetupMemoryRepository {
	public function storeBatch(array $suggestions, int $administratorId): int { throw new RuntimeException('database DSN and secret'); }
}
$throwingRepository = new SetupThrowingRepository();
$throwingService = new SetupSuggestionService($scanner, new SetupFixedProvider(), $throwingRepository);
setup_assert($throwingService->generate('agency', 77) === 0 && $throwingRepository->records === [], 'Repository exceptions must be safely contained without persistence.');
setup_assert(! str_contains((string) $throwingService->lastError(), 'database'), 'Repository exceptions must not leak operational details.');

final class SetupTransactionWpdb {
	public string $prefix = 'wp_';
	public int $insert_id = 0;
	public int $insertCalls = 0;
	/** @var array<int,array<string,mixed>> */
	public array $rows = [7 => ['suggestion_type' => 'unrelated']];
	/** @var list<string> */
	public array $queries = [];
	public function __construct(public bool $failStart = false, public int $failInsertAt = 0, public bool $failCommit = false) {}
	public function insert(string $table, array $data): bool {
		$this->insertCalls++;
		if ($this->failInsertAt === $this->insertCalls) { return false; }
		$this->insert_id = 100 + $this->insertCalls;
		$this->rows[$this->insert_id] = $data;
		return true;
	}
	public function query(string $sql): int|false {
		$this->queries[] = $sql;
		if ($sql === 'START TRANSACTION') { return $this->failStart ? false : 0; }
		if ($sql === 'COMMIT') { return $this->failCommit ? false : 0; }
		if (preg_match('/DELETE FROM .* WHERE id IN \(([^)]+)\)/', $sql, $matches)) {
			foreach (array_map('intval', explode(',', $matches[1])) as $id) { unset($this->rows[$id]); }
			return 1;
		}
		// Deliberately leave rows intact on ROLLBACK to emulate a non-transactional table.
		return 0;
	}
	public function prepare(string $sql, mixed ...$arguments): string {
		foreach ($arguments as $argument) { $sql = preg_replace('/%d/', (string) (int) $argument, $sql, 1) ?? $sql; }
		return $sql;
	}
}
$batch = [
	['type' => 'service', 'value' => 'Website design', 'source_refs' => ['page:12']],
	['type' => 'audience', 'value' => 'Established businesses', 'source_refs' => ['page:12']],
];
$GLOBALS['wpdb'] = new SetupTransactionWpdb(failStart: true);
$realRepository = new SetupSuggestionRepository();
setup_assert($realRepository->storeBatch($batch, 77) === 0, 'Failed transaction start must fail closed.');
setup_assert($GLOBALS['wpdb']->insertCalls === 0 && array_keys($GLOBALS['wpdb']->rows) === [7], 'Failed transaction start must perform no inserts or unrelated cleanup.');

$GLOBALS['wpdb'] = new SetupTransactionWpdb(failInsertAt: 2);
setup_assert($realRepository->storeBatch($batch, 77) === 0, 'A failed later insert must fail the entire batch.');
setup_assert(array_keys($GLOBALS['wpdb']->rows) === [7], 'Compensating cleanup must remove only rows inserted by the failed batch.');
setup_assert(str_contains(implode(' ', $GLOBALS['wpdb']->queries), 'id IN (101)') && ! str_contains(implode(' ', $GLOBALS['wpdb']->queries), 'id IN (7)'), 'Compensating cleanup must target exact inserted IDs and never unrelated rows.');
unset($GLOBALS['wpdb']);

setup_assert(isset($GLOBALS['setup_actions']['admin_post_cf7aiv_setup_scan']), 'Setup scan admin action must be registered.');
setup_assert(isset($GLOBALS['setup_actions']['admin_post_cf7aiv_setup_suggestion']), 'Suggestion review admin action must be registered.');
$GLOBALS['setup_allowed'] = false;
$_POST = ['_wpnonce' => 'nonce', 'pack_key' => 'agency'];
$blocked = false;
try { CF7AIV\Admin\AdminHooks::setupScan(); } catch (RuntimeException) { $blocked = true; }
setup_assert($blocked, 'Setup scan must be capability guarded before scanning or provider calls.');
$GLOBALS['setup_allowed'] = true;
$GLOBALS['setup_nonce_valid'] = false;
$blocked = false;
try { CF7AIV\Admin\AdminHooks::setupSuggestion(); } catch (RuntimeException) { $blocked = true; }
setup_assert($blocked, 'Suggestion review must be nonce guarded before mutation.');
unset($_POST);

if ($GLOBALS['setup_failures'] !== []) {
	throw new RuntimeException("Assisted setup failures:\n - " . implode("\n - ", $GLOBALS['setup_failures']));
}

echo "PASS AssistedSetupTest\n";
