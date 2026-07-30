<?php

declare(strict_types=1);

namespace CF7AIV\Sources;

use CF7AIV\Contracts\SubmissionSourceInterface;
use CF7AIV\Intake\SourceNormalizer;

final class CFDB7Source implements SubmissionSourceInterface {
	public function key(): string { return 'cfdb7'; }

	public function get(string $reference): ?array {
		global $wpdb;
		if (! isset($wpdb->prefix) || ! $this->available()) { return null; }
		$table = $wpdb->prefix . 'db7_forms';
		$row = $wpdb->get_row($wpdb->prepare("SELECT form_id, form_post_id, form_value, form_date FROM {$table} WHERE form_id = %d", (int) $reference), ARRAY_A);
		return is_array($row) ? $this->normalize($row, false) : null;
	}

	public function findReferenceBySubmissionId(string $submissionId, int $limit = 50): ?string {
		global $wpdb;
		$submissionId = trim($submissionId);
		if (preg_match('/\A[A-Za-z0-9._~-]{8,128}\z/', $submissionId) !== 1
			|| ! isset($wpdb->prefix) || ! method_exists($wpdb, 'get_results') || ! $this->available()) {
			return null;
		}
		$table = $wpdb->prefix . 'db7_forms';
		$limit = max(1, min(100, $limit));
		$rows = $wpdb->get_results($wpdb->prepare("SELECT form_id, form_value FROM {$table} ORDER BY form_id DESC LIMIT %d", $limit), ARRAY_A);
		foreach (is_array($rows) ? $rows : [] as $row) {
			if (! is_array($row) || (int) ($row['form_id'] ?? 0) < 1) { continue; }
			$fields = $this->decodeFields($row['form_value'] ?? null);
			$candidate = $fields['cf7aiv_submission_id'] ?? null;
			if (is_scalar($candidate) && hash_equals($submissionId, trim((string) $candidate))) {
				return (string) (int) $row['form_id'];
			}
		}
		return null;
	}

	public function iterate(array $arguments = []): iterable {
		global $wpdb;
		if (! isset($wpdb->prefix) || ! $this->available()) { return; }
		$table = $wpdb->prefix . 'db7_forms';
		$limit = max(1, min(500, (int) ($arguments['limit'] ?? 100)));
		$afterId = max(0, (int) ($arguments['after_id'] ?? 0));
		$offset = max(0, (int) ($arguments['offset'] ?? 0));
		$formIds = array_values(array_unique(array_filter(array_map('intval', is_array($arguments['form_ids'] ?? null) ? $arguments['form_ids'] : []), static fn (int $id): bool => $id > 0)));
		$clauses = ['form_id > %d'];
		$parameters = [$afterId];
		if ($formIds !== []) {
			$clauses[] = 'form_post_id IN (' . implode(',', array_fill(0, count($formIds), '%d')) . ')';
			array_push($parameters, ...$formIds);
		}
		$dateFrom = (string) ($arguments['date_from'] ?? '');
		$dateTo = (string) ($arguments['date_to'] ?? '');
		if ($dateFrom !== '' && $dateTo !== '') {
			$clauses[] = 'form_date BETWEEN %s AND %s';
			$parameters[] = $dateFrom . ' 00:00:00';
			$parameters[] = $dateTo . ' 23:59:59';
		}
		$sql = "SELECT form_id, form_post_id, form_value, form_date FROM {$table} WHERE " . implode(' AND ', $clauses) . ' ORDER BY form_id ASC LIMIT %d OFFSET %d';
		$parameters[] = $limit;
		$parameters[] = $offset;
		$rows = $wpdb->get_results($wpdb->prepare($sql, ...$parameters), ARRAY_A);
		foreach (is_array($rows) ? $rows : [] as $row) {
			if (is_array($row)) { yield $this->normalize($row, true); }
		}
	}

	public function available(): bool {
		global $wpdb;
		if (! isset($wpdb->prefix) || ! $this->pluginActive()) { return false; }
		$table = $wpdb->prefix . 'db7_forms';
		return (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
	}

	public function count(): ?int {
		global $wpdb;
		if (! $this->available() || ! method_exists($wpdb, 'get_var')) { return null; }
		return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}db7_forms");
	}

	private function pluginActive(): bool {
		if (! function_exists('get_option')) { return true; }
		$plugins = array_values((array) get_option('active_plugins', []));
		if (function_exists('get_site_option')) { $plugins = array_merge($plugins, array_keys((array) get_site_option('active_sitewide_plugins', []))); }
		foreach ($plugins as $plugin) {
			$plugin = strtolower(str_replace('\\', '/', (string) $plugin));
			if (str_contains($plugin, 'contact-form-cfdb7/') || str_contains($plugin, 'contact-form-cfdb-7')) { return true; }
		}
		return false;
	}

	/** @param array<string, mixed> $row @return array<string, mixed> */
	private function normalize(array $row, bool $historical): array {
		$value = $this->decodeFields($row['form_value'] ?? []);
		return SourceNormalizer::fromArray($this->key(), (string) ($row['form_id'] ?? ''), [
			'form_post_id' => $row['form_post_id'] ?? 0,
			'form_value' => is_array($value) ? $value : [],
			'form_date' => $row['form_date'] ?? '',
		], $historical);
	}

	/** @return array<string, mixed> */
	private function decodeFields(mixed $value): array {
		if (is_array($value)) { return $value; }
		if (! is_string($value) || $value === '') { return []; }
		$decoded = @unserialize($value, ['allowed_classes' => false]);
		return is_array($decoded) ? $decoded : [];
	}
}
