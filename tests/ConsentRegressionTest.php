<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
require_once dirname(__DIR__) . '/cf7-ai-enquiry-validator.php';

function consent_assert(bool $condition, string $message): void {
	if (! $condition) { throw new RuntimeException($message); }
}

$resolver = new CF7AIV\Intake\ConsentResolver();
$snapshot = $resolver->resolve(new CF7AIV\Domain\ClientSignals([
	'wordpress' => ['ad_storage' => 'granted', 'analytics_storage' => 'granted'],
	'google' => ['analytics_storage' => 'unknown'],
]));
consent_assert($snapshot->states['analytics_storage'] === 'unknown', 'Explicit unknown must be more restrictive than granted.');
consent_assert($snapshot->states['ad_storage'] === 'granted', 'An absent key must remain unobserved and must not override an explicit grant.');
consent_assert($snapshot->source === 'wordpress+google', 'A source providing explicit unknown must be recorded as observed.');

$denied = $resolver->resolve(new CF7AIV\Domain\ClientSignals([
	'wordpress' => ['ad_storage' => 'unknown'],
	'complianz' => ['ad_storage' => 'denied'],
	'google' => ['ad_storage' => 'granted'],
]));
consent_assert($denied->states['ad_storage'] === 'denied', 'Denied must remain more restrictive than unknown and granted.');

echo "PASS ConsentRegressionTest\n";
