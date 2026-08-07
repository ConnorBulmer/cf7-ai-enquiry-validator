<?php
declare(strict_types=1);

namespace CF7AIV\Admin;

final class AdminUi {
	public static function badge(string $label, string $tone = 'neutral'): string {
		$tone = in_array($tone, ['neutral', 'positive', 'warning', 'negative'], true) ? $tone : 'neutral';
		return '<span class="cf7aiv-status-badge is-' . $tone . '">' . self::e($label) . '</span>';
	}

	/**
	 * @param array{href:string,text:string}|null $action Optional follow-up link,
	 *        for metrics where the obvious next question is "which ones, and why?"
	 */
	public static function metric(string $label, string $value, string $description = '', ?array $action = null): string {
		$html = '<div class="cf7aiv-metric"><dl><dt>' . self::e($label) . '</dt><dd>' . self::e($value) . '</dd></dl>';
		if ($description !== '') {
			$html .= '<p>' . self::e($description) . '</p>';
		}
		if ($action !== null && ($action['href'] ?? '') !== '' && ($action['text'] ?? '') !== '') {
			$html .= '<p class="cf7aiv-metric-action"><a href="' . self::e((string) $action['href']) . '">' . self::e((string) $action['text']) . '</a></p>';
		}
		return $html . '</div>';
	}

	public static function emptyState(string $message): string {
		return '<p class="cf7aiv-empty-state" role="status">' . self::e($message) . '</p>';
	}

	public static function panel(string $title, string $body, string $classes = ''): string {
		$tokens = preg_split('/\s+/', trim($classes), -1, PREG_SPLIT_NO_EMPTY) ?: [];
		$tokens = array_values(array_filter($tokens, static fn(string $token): bool => preg_match('/^[a-z0-9_-]+$/', $token) === 1));
		$class = 'card cf7aiv-panel' . ($tokens === [] ? '' : ' ' . implode(' ', $tokens));
		return '<section class="' . self::e($class) . '"><h2>' . self::e($title) . '</h2>' . $body . '</section>';
	}

	/** @param array<int,array{label:mixed,total:mixed}> $rows */
	public static function labelCountTable(string $caption, array $rows): string {
		$html = '<table class="widefat striped cf7aiv-compact-table cf7aiv-count-grid">' . self::caption($caption) . '<thead><tr><th scope="col">Label</th><th scope="col">Count</th></tr></thead><tbody>';
		foreach ($rows as $row) {
			$html .= '<tr><th scope="row">' . self::e((string) ($row['label'] ?? '')) . '</th><td>' . self::e((string) ($row['total'] ?? '0')) . '</td></tr>';
		}
		return $html . '</tbody></table>';
	}

	/** @param array<int,array{label:mixed,value:mixed}> $rows */
	public static function keyValueTable(string $caption, array $rows): string {
		$html = '<table class="widefat striped cf7aiv-key-value-table">' . self::caption($caption) . '<tbody>';
		foreach ($rows as $row) {
			$html .= '<tr><th scope="row">' . self::e((string) ($row['label'] ?? '')) . '</th><td>' . self::e((string) ($row['value'] ?? '')) . '</td></tr>';
		}
		return $html . '</tbody></table>';
	}

	/**
	 * A caption that assistive technology reads but sight users do not see twice.
	 *
	 * Every table on these screens sits inside panel(), which already prints the
	 * same string as an <h2>. Printing it again as a visible caption gave each
	 * panel a duplicated heading. The caption still belongs in the markup - a
	 * table without one is harder to place when tabbing through a long screen -
	 * so it is hidden visually rather than removed.
	 */
	public static function caption(string $caption): string {
		return '<caption class="screen-reader-text">' . self::e($caption) . '</caption>';
	}

	/**
	 * Turn an internal identifier into something a person can read.
	 *
	 * Tier, outcome and reason identifiers are policy-defined keys such as
	 * retained_marketing or legitimate_non_conversion, and they were being shown
	 * to administrators verbatim. Policies are user-authored, so a fixed
	 * translation table cannot cover them; deriving the label from the key does,
	 * and it stays correct when someone adds a tier tomorrow.
	 *
	 * Anything that does not look like an identifier - a sentence, a value that
	 * already contains spaces or capitals - is returned untouched.
	 *
	 * Call it where the value is known to be a policy identifier. It is
	 * deliberately not applied inside the table helpers: destination keys,
	 * submission source keys and health check names travel through those too, and
	 * an operator needs those verbatim to match them against configuration.
	 * Prettifying "list-paused-destination" into "List paused destination" would
	 * make it unmatchable.
	 */
	public static function humanLabel(string $value): string {
		$trimmed = trim($value);
		// Single lowercase words are identifiers too - "excluded", "qualified" -
		// and leaving them alone put them next to "General legitimate" looking
		// like a different class of thing.
		if ($trimmed === '' || preg_match('/^[a-z][a-z0-9]*(?:[_-][a-z0-9]+)*$/', $trimmed) !== 1) {
			return $value;
		}
		$words = str_replace(['_', '-'], ' ', $trimmed);
		return ucfirst($words);
	}

	public static function tableRegion(string $label, string $table): string {
		return '<div class="cf7aiv-table-region" tabindex="0" aria-label="' . self::e($label) . '">' . $table . '</div>';
	}

	public static function duration(?int $seconds): string {
		if ($seconds === null) { return 'No pending queue'; }
		$remaining = max(0, $seconds);
		$parts = [];
		foreach ([86400 => 'day', 3600 => 'hour', 60 => 'minute', 1 => 'second'] as $size => $unit) {
			$value = intdiv($remaining, $size);
			if ($value === 0) { continue; }
			$parts[] = $value . ' ' . $unit . ($value === 1 ? '' : 's');
			$remaining %= $size;
			if (count($parts) === 2) { break; }
		}
		return $parts === [] ? '0 seconds' : implode(' ', $parts);
	}

	private static function e(string $value): string {
		return function_exists('esc_html') ? esc_html($value) : htmlspecialchars($value, ENT_QUOTES);
	}
}
