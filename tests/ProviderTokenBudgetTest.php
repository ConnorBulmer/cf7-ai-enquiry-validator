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

echo "PASS ProviderTokenBudgetTest\n";
