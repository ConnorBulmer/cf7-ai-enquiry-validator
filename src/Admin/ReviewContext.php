<?php

declare(strict_types=1);

namespace CF7AIV\Admin;

use CF7AIV\Classification\ContextBuilder;
use CF7AIV\Contracts\SubmissionSourceInterface;

/**
 * The enquiry as the AI received it: whitelisted fields only, redacted.
 *
 * Reviewers were asked to confirm or correct a classification without seeing
 * the enquiry, unless advanced mode was on - and advanced mode shows the raw
 * source, names and addresses included. This is the middle ground: exactly what
 * the model judged, built by the same code that built its request, with nothing
 * the policy keeps from AI.
 */
final class ReviewContext {
	/**
	 * @param array<string,mixed> $item Needs source_type, source_reference and id.
	 * @param array<string,mixed> $definition The policy the item was classified under.
	 * @return array<string,mixed>|null Null when the source no longer holds the enquiry.
	 */
	public static function fields(array $item, array $definition): ?array {
		$fields = self::sourceFields($item);
		if ($fields === null) { return null; }
		$whitelist = ContextBuilder::safeWhitelist((array) ($definition['ai_whitelist'] ?? []));
		return ContextBuilder::request((string) ($item['id'] ?? ''), '', $fields, $whitelist, '')->context;
	}

	/**
	 * The enquiry's raw fields from its submission source, or null if gone.
	 *
	 * @param array<string,mixed> $item Needs source_type and source_reference.
	 * @return array<string,mixed>|null
	 */
	public static function sourceFields(array $item): ?array {
		$sources = function_exists('apply_filters') ? (array) apply_filters('cf7aiv_submission_sources', []) : [];
		$source = $sources[(string) ($item['source_type'] ?? '')] ?? null;
		if (! $source instanceof SubmissionSourceInterface) { return null; }
		try {
			$record = $source->get((string) ($item['source_reference'] ?? ''));
		} catch (\Throwable) {
			return null;
		}
		return is_array($record) && is_array($record['fields'] ?? null) ? $record['fields'] : null;
	}
}
