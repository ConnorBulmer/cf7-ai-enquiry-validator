<?php

declare(strict_types=1);

use CF7AIVRelease\ReleasePackager;

require_once __DIR__ . '/release-lib.php';

$arguments = [];
foreach (array_slice($argv, 1) as $argument) {
	if (preg_match('/^--([a-z-]+)=(.*)$/D', $argument, $matches) === 1) {
		$arguments[$matches[1]] = $matches[2];
	}
}

try {
	$tag = trim((string) ($arguments['tag'] ?? ''));
	$output = trim((string) ($arguments['output-dir'] ?? ''));
	if ($tag === '' || $output === '') {
		throw new InvalidArgumentException('Usage: php tools/build-release.php --tag=vMAJOR.MINOR.PATCH --output-dir=PATH');
	}
	$result = ReleasePackager::build(dirname(__DIR__), $tag, $output);
	echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
	fwrite(STDERR, 'Release build failed: ' . $error->getMessage() . PHP_EOL);
	exit(1);
}
