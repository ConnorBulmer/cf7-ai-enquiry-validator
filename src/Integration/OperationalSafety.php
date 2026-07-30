<?php

declare(strict_types=1);

namespace CF7AIV\Integration;

final class OperationalSafety {
	/** @param array<string, mixed> $settings */
	public static function isSafe(array $settings): bool {
		if (($settings['automation']['mode'] ?? 'shadow') !== 'shadow') { return false; }
		foreach ((array) ($settings['destinations'] ?? []) as $destination) {
			if (! is_array($destination) || empty($destination['dry_run'])) { return false; }
		}
		return true;
	}

	/** @param array<string, mixed> $settings @return array<string, mixed> */
	public static function report(array $settings): array {
		return [
			'safe' => self::isSafe($settings),
			'automation_mode' => (string) ($settings['automation']['mode'] ?? 'shadow'),
			'test_identifiers' => ['event_id' => 'TEST-NOT-FOR-UPLOAD-CF7AIV', 'client_id' => 'TEST-NOT-FOR-UPLOAD-0.0'],
			'destinations' => array_map(
				static fn (array $destination): array => ['enabled' => ! empty($destination['enabled']), 'dry_run' => ! empty($destination['dry_run'])],
				(array) ($settings['destinations'] ?? [])
			),
			'note' => 'No network request was sent by this check.',
		];
	}
}
