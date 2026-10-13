<?php
declare(strict_types=1);

define('CF7AIV\\DIR', dirname(__DIR__) . '/');

spl_autoload_register(static function (string $class): void {
	$prefix = 'CF7AIV\\';
	if (! str_starts_with($class, $prefix)) { return; }
	$path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
	if (is_file($path)) { require_once $path; }
});

function budget_assert(bool $condition, string $message): void {
	if (! $condition) { throw new RuntimeException($message); }
}

use CF7AIV\Providers\DeepSeekProvider;

$provider = new ReflectionClass(DeepSeekProvider::class);

$budget = $provider->getMethod('tokenBudget');
$budget->setAccessible(true);
$instance = $provider->newInstanceWithoutConstructor();
$call = static fn (string $effort, int $answer): int => $budget->invoke($instance, $effort, $answer);

$suggestion = $provider->getConstant('SUGGESTION_ANSWER_TOKENS');
$classification = $provider->getConstant('CLASSIFICATION_ANSWER_TOKENS');

// Reasoning must never eat into the answer. This is the defect: at a flat 900
// the thinking tokens consumed the allowance and the JSON came back cut in half.
foreach (['standard', 'deep'] as $effort) {
	budget_assert(
		$call($effort, $suggestion) > $suggestion,
		"Enabling {$effort} reasoning must add budget, not share the answer's."
	);
	budget_assert(
		$call($effort, $classification) > $classification,
		"Classification with {$effort} reasoning must add budget, not share the answer's."
	);
}

// With thinking off there is nothing to reserve, so the answer keeps it all.
budget_assert($call('off', $suggestion) === $suggestion, 'With reasoning off the answer keeps the whole budget.');
budget_assert($call('off', $classification) === $classification, 'Classification with reasoning off keeps its budget.');

// Deeper reasoning must not be given less room than lighter reasoning.
budget_assert(
	$call('deep', $suggestion) > $call('standard', $suggestion),
	'Deep reasoning must receive at least as much room as standard.'
);

// The old flat budget was the bug; nothing may regress to it.
budget_assert($call('deep', $suggestion) > 1600, 'The deep budget must exceed the old flat 1600 that caused truncation.');
budget_assert($call('standard', $suggestion) > 900, 'The standard budget must exceed the old flat 900 that caused truncation.');

// Twenty suggestions each carry a type, a value and source refs; the answer
// allowance must be at least what OpenAI is given for the identical call.
budget_assert($suggestion >= 1500, 'The suggestion answer allowance must match the OpenAI provider for the same task.');

// A reply the model did not finish must be reported as a budget problem, not as
// malformed input, because the two need opposite fixes.
$source = file_get_contents(__DIR__ . '/../src/Providers/DeepSeekProvider.php');
budget_assert(is_string($source), 'Provider source must be readable.');
budget_assert(str_contains($source, "finish_reason"), 'Truncation must be detected via finish_reason.');
budget_assert(str_contains($source, 'assertComplete'), 'A completeness check must exist.');
budget_assert(
	substr_count($source, '$this->assertComplete(') >= 1,
	'The completeness check must actually be called.'
);
budget_assert(
	! str_contains($source, "'max_tokens' => \$reasoningEffort === 'deep' ? 1600 : 900"),
	'The flat shared budget must be gone from every call.'
);
budget_assert(
	substr_count($source, "'max_tokens' => \$this->tokenBudget(") === 2,
	'Both the suggestion and classification calls must size their budget the same way.'
);

// ─── Reasoning needs both room and time ──────────────────────────────────────
// Reasoning is the point of the plugin: an inaccurate classification feeding ad
// bidding is worse than a slow one. The ceilings were sized for a synchronous
// web request, which starved the thinking that makes classification accurate.
// Classification, GA4 validation and the site scan all run queued now, so the
// limits are sized for the work instead of for the browser.

budget_assert($call('deep', $classification) >= 9000, 'Deep reasoning must have genuine room to think.');
budget_assert($call('standard', $classification) >= 4000, 'Standard reasoning must have room beyond the answer.');
budget_assert($call('deep', $classification) > $call('standard', $classification), 'Deep must exceed standard.');

use CF7AIV\Admin\SettingsSanitizer;
use CF7AIV\Infrastructure\Settings;

// One ceiling, shared by the sanitizer, the settings field and both providers.
// It used to be a literal repeated in six places, and the copies drifted apart.
budget_assert(SettingsSanitizer::sanitize(['providers' => ['timeout' => '9999']])['providers']['timeout'] === Settings::MAX_PROVIDER_TIMEOUT, 'The stored timeout must be clamped to the shared ceiling.');
budget_assert(Settings::MAX_PROVIDER_TIMEOUT >= 240, 'The ceiling must allow a long reasoning call.');
budget_assert(SettingsSanitizer::sanitize(['providers' => []])['providers']['timeout'] === Settings::DEFAULT_PROVIDER_TIMEOUT, 'A missing timeout must fall back to the shared default.');

$page = file_get_contents(__DIR__ . '/../src/Admin/IntegrationsPage.php');
budget_assert(is_string($page), 'Integrations source must be readable.');
budget_assert(str_contains($page, 'max="\' . Settings::MAX_PROVIDER_TIMEOUT'), 'The timeout field must accept the same ceiling the sanitizer allows.');
budget_assert(! str_contains($page, 'max="60" name="cf7aiv[providers][timeout]"'), 'The old browser-sized ceiling must be gone.');

foreach (['DeepSeekProvider', 'OpenAIProvider', 'JsonProvider'] as $providerFile) {
	$src = file_get_contents(__DIR__ . '/../src/Providers/' . $providerFile . '.php');
	budget_assert(is_string($src), "{$providerFile} source must be readable.");
	budget_assert(preg_match('/min\(\d+,\s*\$this->timeout\)/', $src) !== 1, "{$providerFile} must not clamp the timeout with its own literal.");
}

// The site scan runs as a job. With reasoning it can take minutes, so a refused
// job is reported rather than run inside the administrator's request. Action
// Scheduler reports that refusal as 0 rather than false, so the check must not
// test for false alone.
$hooks = file_get_contents(__DIR__ . '/../src/Admin/AdminHooks.php');
budget_assert(is_string($hooks), 'AdminHooks source must be readable.');
budget_assert(str_contains($hooks, "Queue::accepted(Queue::enqueue('cf7aiv_setup_scan_job'"), 'The site scan must be queued, and a refused enqueue recognised.');
budget_assert(str_contains($hooks, "Queue::accepted(Queue::enqueue('cf7aiv_validate_ga4_job'"), 'GA4 validation must recognise a refused enqueue too.');
budget_assert(! str_contains($hooks, ')===false){self::run'), 'No queued handler may treat only false as a refused enqueue.');
budget_assert(str_contains($hooks, "add_action('cf7aiv_setup_scan_job'"), 'The site scan job must be registered.');
budget_assert(! str_contains($hooks, 'self::runSetupScan('), 'A refused scan must be reported, not run inside the administrator request.');

echo "PASS ProviderTokenBudgetTest\n";
