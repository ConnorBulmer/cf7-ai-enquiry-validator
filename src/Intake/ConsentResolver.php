<?php

declare(strict_types=1);

namespace CF7AIV\Intake;

use CF7AIV\Contracts\ConsentResolverInterface;
use CF7AIV\Domain\ClientSignals;
use CF7AIV\Domain\ConsentSnapshot;

final class ConsentResolver implements ConsentResolverInterface {
	public const KEYS = ['ad_storage', 'analytics_storage', 'ad_user_data', 'ad_personalization'];

	public function key(): string { return 'most_restrictive'; }

	public function resolve(ClientSignals $signals): ConsentSnapshot {
		$states = [];
		$sources = [];
		foreach (self::KEYS as $key) {
			$observed = [];
			foreach ($signals->signals as $source => $values) {
				if (! is_array($values) || ! array_key_exists($key, $values)) {
					continue;
				}
				$state = self::normalize($values[$key]);
				$observed[] = $state;
				$sources[(string) $source] = true;
			}
			$states[$key] = in_array('denied', $observed, true)
				? 'denied'
				: (in_array('unknown', $observed, true) ? 'unknown' : (in_array('granted', $observed, true) ? 'granted' : 'unknown'));
		}
		return new ConsentSnapshot($states, $sources === [] ? 'none' : implode('+', array_keys($sources)), gmdate('c'));
	}

	/**
	 * Resolve with the resolvers registered on cf7aiv_consent_resolvers as well.
	 *
	 * The filter was registered but never read, so a resolver another plugin
	 * added for its consent platform was silently ignored. Each one now counts
	 * as one more consent source, combined most-restrictively with the posted
	 * Consent Mode values, the WordPress Consent API and Complianz, exactly as
	 * those are combined with each other.
	 *
	 * Only what an extension decided counts. Its "unknown" means it has no
	 * answer for that key, not that the visitor refused, so a resolver that
	 * only knows marketing consent does not block analytics. A resolver that
	 * throws contributes "unknown" for every key instead: a broken consent
	 * integration must hold conversions back, not wave them through.
	 *
	 * @param array<mixed> $resolvers Keyed by resolver name, as the filter returns them.
	 */
	public function resolveWith(ClientSignals $signals, array $resolvers): ConsentSnapshot {
		$combined = $signals->signals;
		foreach ($resolvers as $name => $resolver) {
			if (! $resolver instanceof ConsentResolverInterface || $resolver instanceof self) { continue; }
			$source = 'resolver:' . self::sourceName(is_string($name) && $name !== '' ? $name : self::resolverKey($resolver));
			// Two names that sanitise alike must not let one overwrite the other's answer.
			for ($suffix = 2, $base = $source; array_key_exists($source, $combined); $suffix++) { $source = $base . '-' . $suffix; }
			try {
				$states = $resolver->resolve($signals)->states;
			} catch (\Throwable $error) {
				if (function_exists('error_log')) { error_log('[CF7AIV intake] Consent resolver ' . $source . ' failed; its consent is recorded as unknown: ' . $error->getMessage()); }
				$combined[$source] = array_fill_keys(self::KEYS, 'unknown');
				continue;
			}
			$decided = [];
			foreach (self::KEYS as $key) {
				$state = self::normalize($states[$key] ?? null);
				if ($state !== 'unknown') { $decided[$key] = $state; }
			}
			if ($decided !== []) { $combined[$source] = $decided; }
		}
		return $this->resolve(new ClientSignals($combined));
	}

	private static function resolverKey(ConsentResolverInterface $resolver): string {
		try { return $resolver->key(); } catch (\Throwable) { return 'extension'; }
	}

	private static function sourceName(string $name): string {
		$name = substr((string) preg_replace('/[^a-z0-9_\-]/', '', strtolower($name)), 0, 40);
		return $name === '' ? 'extension' : $name;
	}

	private static function normalize(mixed $value): string {
		if (is_bool($value)) { return $value ? 'granted' : 'denied'; }
		$value = strtolower(trim(is_scalar($value) ? (string) $value : ''));
		if (in_array($value, ['granted', 'allow', 'allowed', 'yes', '1', 'true'], true)) { return 'granted'; }
		if (in_array($value, ['denied', 'deny', 'denied', 'no', '0', 'false'], true)) { return 'denied'; }
		return 'unknown';
	}
}
