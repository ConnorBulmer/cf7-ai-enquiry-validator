<?php

declare(strict_types=1);

namespace CF7AIV\Classification;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class PolicyPackRegistry {
	/** @var array<string, array<string, mixed>>|null */
	private static ?array $cache = null;

	/** @return array<string, array<string, mixed>> */
	public function all(): array {
		if (self::$cache !== null) { return self::$cache; }
		$path = \CF7AIV\DIR . 'assets/policies/packs.json';
		$json = file_get_contents($path);
		if ($json === false) {
			throw new RuntimeException('Policy starter packs could not be read.');
		}
		try {
			$definitions = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
		} catch (JsonException $error) {
			throw new RuntimeException('Policy starter packs contain malformed JSON.', 0, $error);
		}
		if (! is_array($definitions) || ! array_is_list($definitions)) {
			throw new RuntimeException('Policy starter packs must be a JSON list.');
		}
		$packs = [];
		foreach ($definitions as $definition) {
			$pack = PolicyValidator::importV2(is_array($definition) ? $definition : []);
			$key = $pack['pack']['key'];
			if (isset($packs[$key])) {
				throw new InvalidArgumentException('Policy starter pack keys must be unique.');
			}
			$packs[$key] = $pack;
		}
		if (function_exists('apply_filters')) {
			$packs = apply_filters('cf7aiv_policy_packs', $packs);
		}
		if (! is_array($packs)) {
			throw new InvalidArgumentException('Filtered policy starter packs must be an array.');
		}
		$validated = [];
		foreach ($packs as $key => $definition) {
			if (! is_string($key) || ! is_array($definition)) {
				throw new InvalidArgumentException('Filtered policy starter packs must be keyed policy arrays.');
			}
			$pack = PolicyValidator::importV2($definition);
			if ($pack['pack']['key'] !== $key || isset($validated[$key])) {
				throw new InvalidArgumentException('Filtered policy starter pack keys must be stable and unique.');
			}
			$validated[$key] = $pack;
		}
		self::$cache = $validated;
		return self::$cache;
	}

	/** @return array<string, mixed>|null */
	public function get(string $key): ?array {
		return $this->all()[$key] ?? null;
	}
}
