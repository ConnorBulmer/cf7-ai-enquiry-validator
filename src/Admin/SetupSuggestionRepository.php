<?php

declare(strict_types=1);

namespace CF7AIV\Admin;

class SetupSuggestionRepository {
	/** @param list<array{type:string,value:mixed,source_refs:list<string>}> $suggestions */
	public function storeBatch(array $suggestions, int $administratorId): int {
		global $wpdb;
		if ($suggestions === [] || ! isset($wpdb) || ! method_exists($wpdb, 'query') || ! method_exists($wpdb, 'prepare') || ! method_exists($wpdb, 'insert')) { return 0; }
		if ($wpdb->query('START TRANSACTION') === false) { return 0; }
		$insertedIds = [];
		try {
			foreach ($suggestions as $suggestion) {
				$id = $this->store($suggestion['type'], $suggestion['value'], $suggestion['source_refs'], $administratorId);
				if ($id <= 0) {
					$wpdb->query('ROLLBACK');
					$this->cleanup($insertedIds);
					return 0;
				}
				$insertedIds[] = $id;
			}
			if ($wpdb->query('COMMIT') === false) {
				$wpdb->query('ROLLBACK');
				$this->cleanup($insertedIds);
				return 0;
			}
			return count($suggestions);
		} catch (\Throwable $error) {
			$wpdb->query('ROLLBACK');
			$this->cleanup($insertedIds);
			throw $error;
		}
	}

	/** @param list<string> $sourceRefs */
	public function store(string $type, mixed $value, array $sourceRefs, int $administratorId): int {
		global $wpdb;
		if (! isset($wpdb) || ! isset($wpdb->prefix)) { return 0; }
		$now = function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s');
		$stored = $wpdb->insert($wpdb->prefix . 'cf7aiv_setup_suggestions', [
			'suggestion_type' => $type,
			'value_json' => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
			'provenance_json' => json_encode(array_values($sourceRefs), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
			'status' => 'pending',
			'created_by' => max(0, $administratorId),
			'created_at' => $now,
			'updated_at' => $now,
		]);
		return $stored === false ? 0 : (int) ($wpdb->insert_id ?? 0);
	}

	public function review(int $id, string $status, mixed $editedValue = null): bool {
		global $wpdb;
		if ($id <= 0 || ! in_array($status, ['accepted', 'rejected'], true) || ! isset($wpdb) || ! isset($wpdb->prefix)) { return false; }
		$data = [
			'status' => $status,
			'updated_at' => function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s'),
		];
		if ($editedValue !== null) {
			$data['value_json'] = json_encode($editedValue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		}
		return $wpdb->update($wpdb->prefix . 'cf7aiv_setup_suggestions', $data, ['id' => $id, 'status' => 'pending']) > 0;
	}

	/** @return array{id:int,type:string,value:mixed,source_refs:list<string>,status:string}|null */
	public function getPending(int $id): ?array {
		global $wpdb;
		if ($id <= 0 || ! isset($wpdb) || ! isset($wpdb->prefix) || ! method_exists($wpdb, 'get_row') || ! method_exists($wpdb, 'prepare')) { return null; }
		$row = $wpdb->get_row($wpdb->prepare(
			"SELECT id,suggestion_type,value_json,provenance_json,status FROM {$wpdb->prefix}cf7aiv_setup_suggestions WHERE id=%d AND status='pending' LIMIT 1",
			$id,
		), ARRAY_A);
		if (! is_array($row)) { return null; }
		try {
			$value = json_decode((string) ($row['value_json'] ?? ''), true, 16, JSON_THROW_ON_ERROR);
			$refs = json_decode((string) ($row['provenance_json'] ?? ''), true, 16, JSON_THROW_ON_ERROR);
		} catch (\JsonException) { return null; }
		if (! is_array($refs)) { return null; }
		return [
			'id' => (int) ($row['id'] ?? 0),
			'type' => (string) ($row['suggestion_type'] ?? ''),
			'value' => $value,
			'source_refs' => array_values($refs),
			'status' => (string) ($row['status'] ?? ''),
		];
	}

	/** @param list<int> $ids */
	private function cleanup(array $ids): void {
		global $wpdb;
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
		if ($ids === []) { return; }
		$placeholders = implode(',', array_fill(0, count($ids), '%d'));
		$sql = $wpdb->prepare("DELETE FROM {$wpdb->prefix}cf7aiv_setup_suggestions WHERE id IN ({$placeholders})", ...$ids);
		$wpdb->query($sql);
	}
}
