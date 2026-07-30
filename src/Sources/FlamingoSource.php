<?php

declare(strict_types=1);

namespace CF7AIV\Sources;

use CF7AIV\Contracts\SubmissionSourceInterface;
use CF7AIV\Intake\SourceNormalizer;

final class FlamingoSource implements SubmissionSourceInterface {
	public function key(): string { return 'flamingo'; }

	public function available(): bool { return class_exists('Flamingo_Inbound_Message'); }

	public function count(): ?int {
		if (! $this->available()) { return null; }
		global $wpdb;
		if (isset($wpdb->posts) && method_exists($wpdb, 'get_var') && method_exists($wpdb, 'prepare')) {
			return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type=%s AND post_status=%s", 'flamingo_inbound', 'publish'));
		}
		return null;
	}

	public function get(string $reference): ?array {
		if (! $this->available()) { return null; }
		$message = new \Flamingo_Inbound_Message((int) $reference);
		$id = method_exists($message, 'id') ? (int) $message->id() : (int) ($message->id ?? 0);
		if ($id < 1) { return null; }
		$post = function_exists('get_post') ? get_post($id) : null;
		return SourceNormalizer::fromArray($this->key(), (string) $id, [
			'form_id' => (int) (($message->fields['_wpcf7'] ?? null) ?: ($message->meta['post_id'] ?? 0)),
			'fields' => (array) $message->fields,
			'submitted_at' => is_object($post) ? (string) ($post->post_date_gmt ?? $post->post_date ?? '') : '',
		], false);
	}

	public function iterate(array $arguments = []): iterable {
		if (! $this->available() || ! method_exists('Flamingo_Inbound_Message', 'find')) { return; }
		$limit = max(1, min(500, (int) ($arguments['limit'] ?? 100)));
		$offset = max(0, (int) ($arguments['offset'] ?? 0));
		$afterId = max(0, (int) ($arguments['after_id'] ?? 0));
		$formIds = array_values(array_unique(array_filter(array_map('intval', is_array($arguments['form_ids'] ?? null) ? $arguments['form_ids'] : []), static fn (int $id): bool => $id > 0)));
		$dateFrom = (string) ($arguments['date_from'] ?? '');
		$dateTo = (string) ($arguments['date_to'] ?? '');
		$candidateIds = $this->candidateIds($formIds, $dateFrom, $dateTo, $afterId, $offset, $limit);
		if ($candidateIds === []) { return; }
		$findArguments = ['posts_per_page' => $limit, 'offset' => $candidateIds === null ? $offset : 0, 'orderby' => 'ID', 'order' => 'ASC', 'post_status' => 'any'];
		if ($candidateIds !== null) { $findArguments['post__in'] = $candidateIds; }
		if ($formIds !== []) {
			$findArguments['meta_query'] = ['relation' => 'OR', ['key' => '_field__wpcf7', 'value' => $formIds, 'compare' => 'IN'], ['key' => '_meta', 'compare' => 'EXISTS']];
		}
		if ($dateFrom !== '' && $dateTo !== '') {
			$findArguments['date_query'] = [['column' => 'post_date_gmt', 'after' => $dateFrom . ' 00:00:00', 'before' => $dateTo . ' 23:59:59', 'inclusive' => true]];
		}
		$messages = \Flamingo_Inbound_Message::find($findArguments);
		if (! is_array($messages) || $messages === []) { return; }
		usort($messages, static function (object $left, object $right): int {
			$leftId = method_exists($left, 'id') ? (int) $left->id() : (int) ($left->id ?? 0);
			$rightId = method_exists($right, 'id') ? (int) $right->id() : (int) ($right->id ?? 0);
			return $leftId <=> $rightId;
		});
		$seen = [];
		foreach (array_slice($messages, 0, $limit) as $message) {
			$id = method_exists($message, 'id') ? (int) $message->id() : (int) ($message->id ?? 0);
			if ($id < 1 || $id <= $afterId || isset($seen[$id]) || ($candidateIds !== null && ! in_array($id, $candidateIds, true))) { continue; }
			$seen[$id] = true;
			$record = $this->get((string) $id);
			if ($record === null || ($formIds !== [] && ! in_array((int) ($record['form_id'] ?? 0), $formIds, true))) { continue; }
			$submittedDate = substr((string) ($record['submitted_at'] ?? ''), 0, 10);
			if (($dateFrom !== '' && ($submittedDate === '' || $submittedDate < $dateFrom)) || ($dateTo !== '' && ($submittedDate === '' || $submittedDate > $dateTo))) { continue; }
			$record['is_historical'] = true;
			$record['is_calibration'] = true;
			yield $record;
		}
	}

	/** @param list<int> $formIds @return list<int>|null */
	private function candidateIds(array $formIds, string $dateFrom, string $dateTo, int $afterId, int $offset, int $limit): ?array {
		global $wpdb;
		if (! isset($wpdb->posts, $wpdb->postmeta) || ! method_exists($wpdb, 'prepare') || ! method_exists($wpdb, 'get_col')) { return null; }
		$clauses = ['p.post_type = %s', 'p.ID > %d'];
		$parameters = ['flamingo_inbound', $afterId];
		$excludedStatuses = $this->excludedStatuses();
		if ($excludedStatuses !== []) {
			$clauses[] = 'p.post_status NOT IN (' . implode(',', array_fill(0, count($excludedStatuses), '%s')) . ')';
			array_push($parameters, ...$excludedStatuses);
		}
		if ($dateFrom !== '' && $dateTo !== '') {
			$clauses[] = 'p.post_date_gmt BETWEEN %s AND %s';
			$parameters[] = $dateFrom . ' 00:00:00';
			$parameters[] = $dateTo . ' 23:59:59';
		}
		if ($formIds !== []) {
			$exactPlaceholders = implode(',', array_fill(0, count($formIds), '%s'));
			$legacyClauses = [];
			$legacyPatterns = [];
			foreach ($formIds as $formId) {
				foreach (['s:7:"post_id";i:' . $formId . ';', 's:7:"post_id";s:' . strlen((string) $formId) . ':"' . $formId . '";'] as $needle) {
					$escaped = method_exists($wpdb, 'esc_like') ? $wpdb->esc_like($needle) : addcslashes($needle, '_%\\');
					$legacyClauses[] = 'lm.meta_value LIKE %s';
					$legacyPatterns[] = '%' . $escaped . '%';
				}
			}
			$clauses[] = "(EXISTS (SELECT 1 FROM {$wpdb->postmeta} fm WHERE fm.post_id=p.ID AND fm.meta_key=%s AND fm.meta_value IN ({$exactPlaceholders})) OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} lm WHERE lm.post_id=p.ID AND lm.meta_key=%s AND (" . implode(' OR ', $legacyClauses) . ')))';
			$parameters[] = '_field__wpcf7';
			array_push($parameters, ...array_map('strval', $formIds));
			$parameters[] = '_meta';
			array_push($parameters, ...$legacyPatterns);
		}
		$sql = "SELECT DISTINCT p.ID FROM {$wpdb->posts} p WHERE " . implode(' AND ', $clauses) . ' ORDER BY p.ID ASC LIMIT %d OFFSET %d';
		$parameters[] = $limit;
		$parameters[] = $offset;
		$ids = $wpdb->get_col($wpdb->prepare($sql, ...$parameters));
		return array_values(array_unique(array_filter(array_map('intval', is_array($ids) ? $ids : []), static fn (int $id): bool => $id > $afterId)));
	}

	/** @return list<string> */
	private function excludedStatuses(): array {
		$statuses = function_exists('get_post_stati') ? get_post_stati(['exclude_from_search' => true], 'names') : ['inherit', 'trash', 'auto-draft', 'flamingo-spam'];
		$statuses = is_array($statuses) ? $statuses : [];
		$statuses = array_values(array_unique(array_filter(array_map('strval', $statuses), static fn (string $status): bool => preg_match('/\A[a-z0-9_-]+\z/', $status) === 1)));
		sort($statuses, SORT_STRING);
		return $statuses;
	}
}
