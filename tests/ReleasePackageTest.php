<?php
/**
 * Standalone release-package tests.
 * Run: php tests/ReleasePackageTest.php
 */

declare(strict_types=1);

use CF7AIVRelease\ReleasePackager;

function cf7aiv_release_assert(bool $condition, string $message): void {
	if (! $condition) {
		throw new RuntimeException($message);
	}
}

function cf7aiv_release_write(string $root, string $relative, string $content): void {
	$path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
	$directory = dirname($path);
	if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
		throw new RuntimeException('Could not create release-test directory.');
	}
	file_put_contents($path, $content);
}

function cf7aiv_release_remove(string $path): void {
	if (! file_exists($path)) {
		return;
	}
	if (is_file($path) || is_link($path)) {
		unlink($path);
		return;
	}
	$entries = scandir($path);
	if (! is_array($entries)) {
		throw new RuntimeException('Could not read release-test directory.');
	}
	foreach ($entries as $entry) {
		if ($entry !== '.' && $entry !== '..') {
			cf7aiv_release_remove($path . DIRECTORY_SEPARATOR . $entry);
		}
	}
	rmdir($path);
}

/** @param list<string> $entries */
function cf7aiv_release_write_zip(string $path, array $entries): void {
	$zip = new ZipArchive();
	cf7aiv_release_assert($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) === true, 'A release-test ZIP must be creatable.');
	try {
		foreach ($entries as $entry) {
			cf7aiv_release_assert($zip->addFromString($entry, "fixture\n"), "The release-test ZIP entry {$entry} must be writable.");
		}
	} finally {
		$zip->close();
	}
}

$library = dirname(__DIR__) . '/tools/release-lib.php';
require_once $library;

cf7aiv_release_assert(ReleasePackager::versionFromTag('v1.2.3') === '1.2.3', 'A semantic release tag must produce its version.');
$invalidTagRejected = false;
try {
	ReleasePackager::versionFromTag('1.2.3');
} catch (InvalidArgumentException) {
	$invalidTagRejected = true;
}
cf7aiv_release_assert($invalidTagRejected, 'A release tag without the required v prefix must be rejected.');

$pluginRoot = dirname(__DIR__);
$metadata = ReleasePackager::validateMetadata($pluginRoot, '0.2.6');
cf7aiv_release_assert(
	$metadata === [
		'plugin_header' => '0.2.6',
		'version_constant' => '0.2.6',
		'stable_tag' => '0.2.6',
	],
	'The real plugin header, constant, and stable tag must agree with the release version.',
);

$fixtureRoot = sys_get_temp_dir() . '/cf7aiv-release-fixture-' . bin2hex(random_bytes(6));
$fixtureOutput = sys_get_temp_dir() . '/cf7aiv-release-output-' . bin2hex(random_bytes(6));
mkdir($fixtureRoot, 0777, true);
mkdir($fixtureOutput, 0777, true);

try {
	cf7aiv_release_write($fixtureRoot, 'cf7-ai-enquiry-validator.php', "<?php\n/*\n * Version: 9.8.7\n */\nnamespace CF7AIV;\nconst VERSION = '9.8.7';\n");
	cf7aiv_release_write($fixtureRoot, 'readme.txt', "Stable tag: 9.8.7\n");
	cf7aiv_release_write($fixtureRoot, 'README.md', "# Runtime documentation\n");
	cf7aiv_release_write($fixtureRoot, 'src/runtime.php', "<?php\n");
	cf7aiv_release_write($fixtureRoot, 'vendor/action-scheduler/action-scheduler.php', "<?php\n");
	cf7aiv_release_write($fixtureRoot, 'docs/SETUP.md', "# Setup\n");
	cf7aiv_release_write($fixtureRoot, '.github/workflows/release.yml', "name: release\n");
	cf7aiv_release_write($fixtureRoot, 'tests/example.php', "<?php\n");
	cf7aiv_release_write($fixtureRoot, 'tools/build.php', "<?php\n");
	cf7aiv_release_write($fixtureRoot, 'docs/superpowers/specs/design.md', "# Design\n");
	cf7aiv_release_write($fixtureRoot, 'VERIFICATION.md', "# Evidence\n");
	cf7aiv_release_write($fixtureRoot, '.env', "SECRET=not-for-release\n");
	cf7aiv_release_write($fixtureRoot, 'dist/old.zip', "old\n");

	$files = ReleasePackager::distributableFiles($fixtureRoot);
	cf7aiv_release_assert(
		$files === [
			'README.md',
			'cf7-ai-enquiry-validator.php',
			'docs/SETUP.md',
			'readme.txt',
			'src/runtime.php',
			'vendor/action-scheduler/action-scheduler.php',
		],
		'The distributable file list must include runtime/docs/vendor content and exclude repository, test, tool, evidence, environment, and output files.',
	);

	$mismatchRejected = false;
	try {
		ReleasePackager::validateMetadata($fixtureRoot, '9.8.6');
	} catch (RuntimeException $error) {
		$mismatchRejected = str_contains($error->getMessage(), '9.8.6');
	}
	cf7aiv_release_assert($mismatchRejected, 'Version disagreement must stop packaging with the expected version in the error.');

	$result = ReleasePackager::build($fixtureRoot, 'v9.8.7', $fixtureOutput);
	cf7aiv_release_assert(
		$result['version'] === '9.8.7'
		&& $result['entries'] === 6
		&& is_file($result['zip'])
		&& is_file($result['checksum']),
		'The packager must return a complete release receipt and create both assets.',
	);

	$zip = new ZipArchive();
	cf7aiv_release_assert($zip->open($result['zip']) === true, 'The generated release must be a readable ZIP.');
	$entries = [];
	for ($index = 0; $index < $zip->numFiles; $index++) {
		$entries[] = $zip->getNameIndex($index);
	}
	$zip->close();
	sort($entries, SORT_STRING);
	cf7aiv_release_assert(
		$entries === [
			'cf7-ai-enquiry-validator/README.md',
			'cf7-ai-enquiry-validator/cf7-ai-enquiry-validator.php',
			'cf7-ai-enquiry-validator/docs/SETUP.md',
			'cf7-ai-enquiry-validator/readme.txt',
			'cf7-ai-enquiry-validator/src/runtime.php',
			'cf7-ai-enquiry-validator/vendor/action-scheduler/action-scheduler.php',
		],
		'Every ZIP entry must use the canonical plugin root and forward slashes.',
	);

	$expectedHash = hash_file('sha256', $result['zip']);
	$checksum = trim((string) file_get_contents($result['checksum']));
	cf7aiv_release_assert(
		$checksum === $expectedHash . '  ' . basename($result['zip']),
		'The checksum asset must identify the exact release ZIP bytes.',
	);

	$safeArchive = $fixtureOutput . '/safe-paths.zip';
	cf7aiv_release_write_zip(
		$safeArchive,
		[
			'cf7-ai-enquiry-validator/cf7-ai-enquiry-validator.php',
			'cf7-ai-enquiry-validator/docs/console.md',
			'cf7-ai-enquiry-validator/src/com10.php',
		],
	);
	ReleasePackager::verifyArchive($safeArchive, 3);

	$unsafePaths = [
		'cf7-ai-enquiry-validator/NUL',
		'cf7-ai-enquiry-validator/docs/con.txt',
		'cf7-ai-enquiry-validator/PRN.md',
		'cf7-ai-enquiry-validator/AUX',
		'cf7-ai-enquiry-validator/bin/COM1.exe',
		'cf7-ai-enquiry-validator/bin/com9',
		'cf7-ai-enquiry-validator/bin/COM¹',
		'cf7-ai-enquiry-validator/bin/com².txt',
		'cf7-ai-enquiry-validator/bin/COM³.log',
		'cf7-ai-enquiry-validator/log/LPT1.txt',
		'cf7-ai-enquiry-validator/log/lpt9',
		'cf7-ai-enquiry-validator/log/LPT¹',
		'cf7-ai-enquiry-validator/log/lpt².txt',
		'cf7-ai-enquiry-validator/log/LPT³.log',
		'cf7-ai-enquiry-validator/docs/trailing.',
		'cf7-ai-enquiry-validator/docs/trailing ',
		'cf7-ai-enquiry-validator/docs/readme.txt:stream',
	];
	foreach ($unsafePaths as $index => $unsafePath) {
		$unsafeArchive = $fixtureOutput . "/unsafe-path-{$index}.zip";
		cf7aiv_release_write_zip(
			$unsafeArchive,
			[
				'cf7-ai-enquiry-validator/cf7-ai-enquiry-validator.php',
				$unsafePath,
			],
		);
		$unsafePathRejected = false;
		try {
			ReleasePackager::verifyArchive($unsafeArchive, 2);
		} catch (RuntimeException $error) {
			$unsafePathRejected = str_contains($error->getMessage(), 'unsafe');
		}
		cf7aiv_release_assert($unsafePathRejected, "The release archive must reject Windows-unsafe path {$unsafePath}.");
	}

	cf7aiv_release_write($fixtureRoot, 'src/secret.txt', 'sk-' . str_repeat('A', 32));
	$secretRejected = false;
	try {
		ReleasePackager::build($fixtureRoot, 'v9.8.7', $fixtureOutput);
	} catch (RuntimeException $error) {
		$secretRejected = str_contains($error->getMessage(), 'credential');
	}
	cf7aiv_release_assert($secretRejected, 'A credential-like value in distributable source must stop packaging.');
} finally {
	cf7aiv_release_remove($fixtureRoot);
	cf7aiv_release_remove($fixtureOutput);
}

echo "PASS ReleasePackageTest\n";
