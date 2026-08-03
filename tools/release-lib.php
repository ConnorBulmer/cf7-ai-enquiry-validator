<?php

declare(strict_types=1);

namespace CF7AIVRelease;

use FilesystemIterator;
use InvalidArgumentException;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

final class ReleasePackager {
	public const SLUG = 'cf7-ai-enquiry-validator';
	public const MAIN_FILE = 'cf7-ai-enquiry-validator.php';

	public static function versionFromTag(string $tag): string {
		if (preg_match('/^v([0-9]+\.[0-9]+\.[0-9]+)$/D', trim($tag), $matches) !== 1) {
			throw new InvalidArgumentException('Release tag must use exact vMAJOR.MINOR.PATCH format.');
		}
		return $matches[1];
	}

	/** @return array{plugin_header:string,version_constant:string,stable_tag:string} */
	public static function validateMetadata(string $root, string $version): array {
		$main = self::read($root . DIRECTORY_SEPARATOR . self::MAIN_FILE);
		$readme = self::read($root . DIRECTORY_SEPARATOR . 'readme.txt');
		$metadata = [
			'plugin_header' => self::match($main, '/^[ \t*#@\/]*Version:\s*([0-9]+\.[0-9]+\.[0-9]+)\s*$/mi', 'plugin header Version'),
			'version_constant' => self::match($main, '/\bconst\s+VERSION\s*=\s*[\'"]([0-9]+\.[0-9]+\.[0-9]+)[\'"]\s*;/', 'CF7AIV VERSION constant'),
			'stable_tag' => self::match($readme, '/^Stable tag:\s*([0-9]+\.[0-9]+\.[0-9]+)\s*$/mi', 'readme.txt Stable tag'),
		];
		foreach ($metadata as $source => $actual) {
			if (! hash_equals($version, $actual)) {
				throw new RuntimeException("Release version {$version} does not match {$source} value {$actual}.");
			}
		}
		return $metadata;
	}

	/** @return list<string> */
	public static function distributableFiles(string $root): array {
		$resolvedRoot = realpath($root);
		if ($resolvedRoot === false || ! is_dir($resolvedRoot)) {
			throw new InvalidArgumentException('Plugin root does not exist.');
		}
		$directory = new RecursiveDirectoryIterator(
			$resolvedRoot,
			FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO,
		);
		$filter = new RecursiveCallbackFilterIterator(
			$directory,
			static function (\SplFileInfo $item, string $key, \RecursiveIterator $iterator) use ($resolvedRoot): bool {
				$relative = self::relative($resolvedRoot, $item->getPathname());
				if ($item->isLink()) {
					throw new RuntimeException("Release source contains a symbolic link: {$relative}");
				}
				return ! self::excluded($relative, $item->isDir());
			},
		);
		$iterator = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::LEAVES_ONLY);
		$files = [];
		foreach ($iterator as $item) {
			if ($item instanceof \SplFileInfo && $item->isFile()) {
				$relative = self::relative($resolvedRoot, $item->getPathname());
				if (! self::excluded($relative, false)) {
					self::assertSafeArchivePath($relative);
					$files[] = $relative;
				}
			}
		}
		sort($files, SORT_STRING);
		return $files;
	}

	/** @return array{version:string,zip:string,checksum:string,sha256:string,entries:int,metadata:array<string,string>} */
	public static function build(string $root, string $tag, string $outputDirectory): array {
		if (! class_exists(ZipArchive::class)) {
			throw new RuntimeException('PHP ZipArchive is required to build a release.');
		}
		$resolvedRoot = realpath($root);
		if ($resolvedRoot === false) {
			throw new InvalidArgumentException('Plugin root does not exist.');
		}
		$version = self::versionFromTag($tag);
		$metadata = self::validateMetadata($resolvedRoot, $version);
		$files = self::distributableFiles($resolvedRoot);
		if ($files === []) {
			throw new RuntimeException('No distributable plugin files were found.');
		}
		self::assertNoCredentials($resolvedRoot, $files);

		if (! is_dir($outputDirectory) && ! mkdir($outputDirectory, 0777, true) && ! is_dir($outputDirectory)) {
			throw new RuntimeException('Could not create the release output directory.');
		}
		$resolvedOutput = realpath($outputDirectory);
		if ($resolvedOutput === false) {
			throw new RuntimeException('Could not resolve the release output directory.');
		}
		$zipPath = $resolvedOutput . DIRECTORY_SEPARATOR . self::SLUG . '-' . $version . '.zip';
		$checksumPath = $zipPath . '.sha256';
		foreach ([$zipPath, $checksumPath] as $target) {
			if (is_file($target) && ! unlink($target)) {
				throw new RuntimeException('Could not replace an existing release asset.');
			}
		}

		$archive = new ZipArchive();
		$opened = $archive->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL);
		if ($opened !== true) {
			throw new RuntimeException("Could not create release ZIP (ZipArchive code {$opened}).");
		}
		try {
			foreach ($files as $relative) {
				$source = $resolvedRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
				$entry = self::SLUG . '/' . $relative;
				if (! $archive->addFile($source, $entry)) {
					throw new RuntimeException("Could not add {$relative} to the release ZIP.");
				}
			}
		} finally {
			$archive->close();
		}

		self::verifyArchive($zipPath, count($files));
		$hash = hash_file('sha256', $zipPath);
		if (! is_string($hash) || $hash === '') {
			throw new RuntimeException('Could not hash the release ZIP.');
		}
		$checksum = $hash . '  ' . basename($zipPath) . "\n";
		if (file_put_contents($checksumPath, $checksum) === false) {
			throw new RuntimeException('Could not write the release checksum.');
		}

		return [
			'version' => $version,
			'zip' => $zipPath,
			'checksum' => $checksumPath,
			'sha256' => $hash,
			'entries' => count($files),
			'metadata' => $metadata,
		];
	}

	/** @param list<string> $files */
	private static function assertNoCredentials(string $root, array $files): void {
		$textExtensions = ['css', 'html', 'js', 'json', 'md', 'php', 'txt', 'xml', 'yaml', 'yml'];
		$patterns = [
			'/sk-[A-Za-z0-9_-]{24,}/',
			'/Authorization\s*:\s*Bearer\s+[A-Za-z0-9._-]{16,}/i',
			'/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/',
		];
		foreach ($files as $relative) {
			$extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
			if (! in_array($extension, $textExtensions, true)) {
				continue;
			}
			$content = self::read($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
			foreach ($patterns as $pattern) {
				if (preg_match($pattern, $content) === 1) {
					throw new RuntimeException("Possible committed credential found in distributable file {$relative}.");
				}
			}
		}
	}

	public static function verifyArchive(string $zipPath, int $expectedEntries): void {
		$archive = new ZipArchive();
		if ($archive->open($zipPath) !== true) {
			throw new RuntimeException('Generated release ZIP could not be reopened.');
		}
		try {
			if ($archive->numFiles !== $expectedEntries) {
				throw new RuntimeException('Generated release ZIP entry count does not match its source file list.');
			}
			$hasMain = false;
			for ($index = 0; $index < $archive->numFiles; $index++) {
				$name = $archive->getNameIndex($index);
				if (! is_string($name) || ! str_starts_with($name, self::SLUG . '/') || str_contains($name, '\\')) {
					throw new RuntimeException('Generated release ZIP has an invalid entry path.');
				}
				self::assertSafeArchivePath($name);
				if ($name === self::SLUG . '/' . self::MAIN_FILE) {
					$hasMain = true;
				}
			}
			if (! $hasMain) {
				throw new RuntimeException('Generated release ZIP is missing the plugin main file.');
			}
		} finally {
			$archive->close();
		}
	}

	private static function assertSafeArchivePath(string $path): void {
		$components = explode('/', str_replace('\\', '/', $path));
		foreach ($components as $component) {
			if (
				$component === ''
				|| $component === '.'
				|| $component === '..'
				|| preg_match('/[. ]$/D', $component) === 1
				|| preg_match('/[<>:"|?*\x00-\x1F]/', $component) === 1
			) {
				throw new RuntimeException("Release archive path contains a Windows-unsafe component: {$path}");
			}
			$deviceBase = strtoupper(rtrim(explode('.', $component, 2)[0], '. '));
			if (preg_match('/^(?:CON|PRN|AUX|NUL|(?:COM|LPT)(?:[1-9]|\x{00B9}|\x{00B2}|\x{00B3}))$/uD', $deviceBase) === 1) {
				throw new RuntimeException("Release archive path contains a Windows-unsafe component: {$path}");
			}
		}
	}

	private static function excluded(string $relative, bool $directory): bool {
		$relative = str_replace('\\', '/', $relative);
		$segments = explode('/', $relative);
		$top = $segments[0] ?? '';
		if (in_array($top, ['.git', '.github', '.idea', '.superpowers', '.vscode', '.worktrees', 'coverage', 'dist', 'node_modules', 'tests', 'tools'], true)) {
			return true;
		}
		if ($relative === 'docs/superpowers' || str_starts_with($relative, 'docs/superpowers/')) {
			return true;
		}
		$base = basename($relative);
		if (in_array($base, ['.DS_Store', '.gitattributes', '.gitignore', 'Thumbs.db', 'VERIFICATION.md'], true)) {
			return true;
		}
		if ($base === '.env' || str_starts_with($base, '.env.') || str_ends_with($base, '.log') || str_ends_with($base, '.cache')) {
			return true;
		}
		return false;
	}

	private static function relative(string $root, string $path): string {
		return str_replace('\\', '/', ltrim(substr($path, strlen($root)), '\\/'));
	}

	private static function read(string $path): string {
		$content = @file_get_contents($path);
		if (! is_string($content)) {
			throw new RuntimeException("Could not read required release file {$path}.");
		}
		return $content;
	}

	private static function match(string $content, string $pattern, string $label): string {
		if (preg_match($pattern, $content, $matches) !== 1) {
			throw new RuntimeException("Could not read {$label}.");
		}
		return $matches[1];
	}
}
