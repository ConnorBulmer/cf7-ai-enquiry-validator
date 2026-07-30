<?php
declare(strict_types=1);
namespace CF7AIV\Delivery;
use InvalidArgumentException;
final class ConsentPolicy {
	/** @param array<string,scalar> $actual @param array<string,mixed> $override @param list<string> $required */
	public static function evaluate(string $mode, array $actual, array $override = [], array $required = ['analytics_storage', 'ad_storage', 'ad_user_data']): ConsentDecision {
		if ($mode === 'respect') {
			foreach ($required as $key) { if (($actual[$key] ?? 'unknown') !== 'granted') { return new ConsentDecision(false, $actual); } }
			return new ConsentDecision(true, $actual);
		}
		if ($mode === 'always_actual') { return new ConsentDecision(true, $actual); }
		if ($mode !== 'legal_basis_override') { throw new InvalidArgumentException('Unknown consent delivery mode.'); }
		if ((int) ($override['administrator_id'] ?? 0) < 1 || strtotime((string) ($override['date'] ?? '')) === false || trim((string) ($override['reason'] ?? '')) === '') { throw new InvalidArgumentException('Legal-basis override requires administrator ID, date, and reason.'); }
		$audit = ['administrator_id' => (int) $override['administrator_id'], 'date' => gmdate(DATE_ATOM, (int) strtotime((string) $override['date'])), 'reason' => substr(trim((string) $override['reason']), 0, 500)];
		return new ConsentDecision(true, $actual, $audit);
	}
}
