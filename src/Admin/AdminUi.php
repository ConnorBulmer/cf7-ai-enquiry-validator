<?php
declare(strict_types=1);

namespace CF7AIV\Admin;

final class AdminUi {
	public static function badge(string $label, string $tone = 'neutral'): string {
		$tone = in_array($tone, ['neutral', 'positive', 'warning', 'negative'], true) ? $tone : 'neutral';
		return '<span class="cf7aiv-status-badge is-' . $tone . '">' . self::e($label) . '</span>';
	}

	public static function metric(string $label, string $value, string $description = ''): string {
		$html = '<div class="cf7aiv-metric"><dl><dt>' . self::e($label) . '</dt><dd>' . self::e($value) . '</dd></dl>';
		if ($description !== '') {
			$html .= '<p>' . self::e($description) . '</p>';
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
		$html = '<table class="widefat striped cf7aiv-compact-table cf7aiv-count-grid"><caption>' . self::e($caption) . '</caption><thead><tr><th scope="col">Label</th><th scope="col">Count</th></tr></thead><tbody>';
		foreach ($rows as $row) {
			$html .= '<tr><th scope="row">' . self::e((string) ($row['label'] ?? '')) . '</th><td>' . self::e((string) ($row['total'] ?? '0')) . '</td></tr>';
		}
		return $html . '</tbody></table>';
	}

	/** @param array<int,array{label:mixed,value:mixed}> $rows */
	public static function keyValueTable(string $caption, array $rows): string {
		$html = '<table class="widefat striped cf7aiv-key-value-table"><caption>' . self::e($caption) . '</caption><tbody>';
		foreach ($rows as $row) {
			$html .= '<tr><th scope="row">' . self::e((string) ($row['label'] ?? '')) . '</th><td>' . self::e((string) ($row['value'] ?? '')) . '</td></tr>';
		}
		return $html . '</tbody></table>';
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
