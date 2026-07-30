<?php

declare(strict_types=1);

namespace CF7AIV\Intake;

use CF7AIV\Contracts\ConsentResolverInterface;
use CF7AIV\Domain\ClientSignals;
use CF7AIV\Domain\ConsentSnapshot;

final class ConsentResolver implements ConsentResolverInterface {
	private const KEYS = ['ad_storage', 'analytics_storage', 'ad_user_data', 'ad_personalization'];

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

	private static function normalize(mixed $value): string {
		if (is_bool($value)) { return $value ? 'granted' : 'denied'; }
		$value = strtolower(trim(is_scalar($value) ? (string) $value : ''));
		if (in_array($value, ['granted', 'allow', 'allowed', 'yes', '1', 'true'], true)) { return 'granted'; }
		if (in_array($value, ['denied', 'deny', 'denied', 'no', '0', 'false'], true)) { return 'denied'; }
		return 'unknown';
	}
}
