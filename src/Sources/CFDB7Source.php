<?php

declare(strict_types=1);

namespace CF7AIV\Sources;

use CF7AIV\Contracts\SubmissionReferenceLocatorInterface;
use CF7AIV\Contracts\SubmissionSourceInterface;
use CF7AIV\Intake\SourceNormalizer;

final class CFDB7Source implements SubmissionSourceInterface, SubmissionReferenceLocatorInterface {
	public function key(): string { return 'cfdb7'; }

	public function get(string $reference): ?array {
		global $wpdb;
		if (! isset($wpdb->prefix) || ! $this->available()) { return null; }
		$table = $wpdb->prefix . 'db7_forms';
		$row = $wpdb->get_row($wpdb->prepare("SELECT form_id, form_post_id, form_value, form_date FROM {$table} WHERE form_id = %d", (int) $reference), ARRAY_A);
		return is_array($row) ? $this->normalize($row, false) : null;
	}

	public function findReferenceBySubmissionId(string $submissionId, int $formId, string $capturedAt): ?string {
		global $wpdb;
		$submissionId = trim($submissionId);
		if (preg_match('/\A[A-Za-z0-9._~-]{8,128}\z/', $submissionId) !== 1
			|| $formId < 1 || ! isset($wpdb->prefix) || ! method_exists($wpdb, 'get_results')
			|| ! method_exists($wpdb, 'esc_like') || ! $this->available()) {
			return null;
		}
		$table = $wpdb->prefix . 'db7_forms';
		$clauses = ['form_post_id = %d', 'form_value LIKE %s'];
		$parameters = [$formId, '%' . $wpdb->esc_like($submissionId) . '%'];
		$window = $this->captureWindow($capturedAt);
		if ($window !== null) {
			$clauses[] = 'form_date BETWEEN %s AND %s';
			array_push($parameters, ...$window);
		}
		$sql = "SELECT form_id, form_value FROM {$table} WHERE " . implode(' AND ', $clauses) . ' ORDER BY form_id DESC LIMIT 20';
		$rows = $wpdb->get_results($wpdb->prepare($sql, ...$parameters), ARRAY_A);
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

	/** @return array{string,string}|null */
	private function captureWindow(string $capturedAt): ?array {
		if (preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $capturedAt) !== 1) { return null; }
		$date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $capturedAt, new \DateTimeZone('UTC'));
		$errors = \DateTimeImmutable::getLastErrors();
		if ($date === false || ($errors !== false && ((int) $errors['warning_count'] > 0 || (int) $errors['error_count'] > 0))
			|| $date->format('Y-m-d H:i:s') !== $capturedAt) {
			return null;
		}
		$from = $date->modify('-10 minutes')->format('Y-m-d H:i:s');
		$to = $date->modify('+10 minutes')->format('Y-m-d H:i:s');
		if (function_exists('get_date_from_gmt')) {
			$from = (string) get_date_from_gmt($from, 'Y-m-d H:i:s');
			$to = (string) get_date_from_gmt($to, 'Y-m-d H:i:s');
		}
		return [$from, $to];
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

	/**
	 * A source found once is still there for the rest of the request.
	 *
	 * Every get(), count() and reference lookup asked again, which cost a
	 * SHOW TABLES query per item while classifying a backlog - and a new
	 * instance is built for each item, so the answer is kept per table for the
	 * request rather than per instance. Only a positive answer is kept, so a
	 * table created mid-request is still noticed.
	 *
	 * @var array<string,true>
	 */
	private static array $confirmedTables = [];

	public function available(): bool {
		global $wpdb;
		if (! isset($wpdb->prefix) || ! $this->pluginActive()) { return false; }
		$table = $wpdb->prefix . 'db7_forms';
		if (isset(self::$confirmedTables[$table])) { return true; }
		if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) { return false; }
		self::$confirmedTables[$table] = true;
		return true;
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
