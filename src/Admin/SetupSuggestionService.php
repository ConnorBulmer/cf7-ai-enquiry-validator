<?php

declare(strict_types=1);

namespace CF7AIV\Admin;

use CF7AIV\Classification\Redactor;
use CF7AIV\Contracts\PolicySuggestionProviderInterface;
use CF7AIV\Domain\PolicySuggestionRequest;
use CF7AIV\Domain\PolicySuggestionResult;
use CF7AIV\Hooks;
use InvalidArgumentException;
use Throwable;

final class SetupSuggestionService {
	private const TYPES = ['service', 'audience', 'value_signal', 'seriousness_signal', 'legitimate_non_conversion', 'exclusion', 'field_mapping'];
	private const SUGGESTION_LIMIT = PolicySuggestionResult::MAX_SUGGESTIONS;
	private const BATCH_CHARACTER_LIMIT = 40000;
	private ?string $error = null;

	public function __construct(
		private readonly SiteProfileScanner $scanner,
		private readonly PolicySuggestionProviderInterface $provider,
		private readonly SetupSuggestionRepository $repository,
	) {}

	public function generate(string $packKey, int $administratorId): int {
		$this->error = null;
		$packKey = substr(preg_replace('/[^a-zA-Z0-9_\-]/', '', trim($packKey)) ?? '', 0, 100);
		if ($packKey === '') { $packKey = 'general'; }
		try {
			$request = new PolicySuggestionRequest($packKey, $this->scanner->scan());
			$request->providerProfileJson();
			$result = $this->provider->suggestPolicy($request);
			$valid = [];
			foreach (array_slice($result->suggestions, 0, self::SUGGESTION_LIMIT) as $suggestion) {
				if (! is_array($suggestion)) { continue; }
				$normalized = $this->validate($suggestion, $request);
				if ($normalized === null) { continue; }
				$candidate = [...$valid, $normalized];
				if ($this->encodedLength($candidate) > self::BATCH_CHARACTER_LIMIT) { break; }
				$valid = $candidate;
			}
			if ($valid === []) { return 0; }
			$count = $this->repository->storeBatch($valid, $administratorId);
			if ($count !== count($valid)) { throw new \RuntimeException('Suggestion batch could not be stored atomically.'); }
		} catch (Throwable) {
			$this->error = 'Suggestions could not be generated. Manual setup remains available.';
			return 0;
		}
		if (function_exists('do_action')) { try { do_action(Hooks::AFTER_SETUP_SUGGESTIONS, $count, $packKey, $administratorId); } catch (Throwable) {} }
		return $count;
	}

	public function review(int $id, string $action, mixed $editedValue = null): bool {
		if (! in_array($action, ['accept', 'reject'], true)) { throw new InvalidArgumentException('Suggestion action must be accept or reject.'); }
		$original = $this->repository->getPending($id);
		if ($original === null) { return false; }
		if ($action === 'reject') { return $this->repository->review($id, 'rejected'); }
		$value = $editedValue !== null && $editedValue !== '' ? $editedValue : $original['value'];
		$packKey = 'general';
		foreach ($original['source_refs'] as $ref) {
			if (is_string($ref) && str_starts_with($ref, 'pack:')) { $packKey = substr($ref, 5); break; }
		}
		try {
			$request = new PolicySuggestionRequest($packKey, $this->scanner->scan());
			$validated = $this->validate(['type' => $original['type'], 'value' => $value, 'source_refs' => $original['source_refs']], $request);
		} catch (InvalidArgumentException $error) { throw $error; }
		catch (Throwable) {
			$this->error = 'Suggestions could not be generated. Manual setup remains available.';
			return false;
		}
		if ($validated === null) { throw new InvalidArgumentException('Edited suggestion does not match its pending type, provenance, or safe field schema.'); }
		return $this->repository->review($id, 'accepted', $editedValue !== null && $editedValue !== '' ? $validated['value'] : null);
	}

	public function lastError(): ?string { return $this->error; }

	/** @param array<string,mixed> $suggestion @return array{type:string,value:mixed,source_refs:list<string>}|null */
	private function validate(array $suggestion, PolicySuggestionRequest $request): ?array {
		if (array_diff(array_keys($suggestion), ['type', 'value', 'source_refs']) !== []) { return null; }
		$type = is_string($suggestion['type'] ?? null) ? $suggestion['type'] : '';
		if (! in_array($type, self::TYPES, true)) { return null; }
		$sourceRefs = $suggestion['source_refs'] ?? null;
		if (! is_array($sourceRefs) || $sourceRefs === [] || count($sourceRefs) > 10) { return null; }
		$allowed = array_flip($request->allowedSourceRefs());
		$normalizedRefs = [];
		foreach ($sourceRefs as $ref) {
			if (! is_string($ref) || ! isset($allowed[$ref])) { return null; }
			$normalizedRefs[] = $ref;
		}
		$value = $suggestion['value'] ?? null;
		try { $this->assertSafeValue($value); } catch (InvalidArgumentException) { return null; }
		if ($type === 'field_mapping') {
			if (! is_array($value) || array_diff(array_keys($value), ['field', 'meaning']) !== [] || ! isset($value['field'], $value['meaning']) || ! is_string($value['field']) || ! is_string($value['meaning'])) { return null; }
			if ($this->textLength($value['field']) > 100 || $this->textLength($value['meaning']) > 300) { return null; }
			if (PolicySuggestionRequest::isForbiddenField($value['field']) || ! $this->fieldExists($value['field'], $request, $normalizedRefs)) { return null; }
		} elseif (! is_string($value)) { return null; }
		return ['type' => $type, 'value' => $value, 'source_refs' => array_values(array_unique($normalizedRefs))];
	}

	/** @param list<string> $sourceRefs */
	private function fieldExists(string $fieldName, PolicySuggestionRequest $request, array $sourceRefs): bool {
		foreach ($request->providerProfile()['forms'] as $form) {
			if (! is_array($form) || ! in_array('form:' . (int) ($form['id'] ?? 0), $sourceRefs, true)) { continue; }
			foreach ((array) ($form['fields'] ?? []) as $field) {
				if (is_array($field) && (string) ($field['name'] ?? '') === $fieldName && PolicySuggestionRequest::isProviderVisibleField($field)) { return true; }
			}
		}
		return false;
	}

	private function assertSafeValue(mixed $value): void {
		if (is_array($value)) {
			foreach ($value as $key => $child) {
				if (! is_string($key) || ! is_scalar($child)) { throw new InvalidArgumentException('Suggestion values must be compact prose.'); }
				$this->assertSafeString($key, 100);
				$this->assertSafeString((string) $child, 500);
			}
			return;
		}
		if (! is_string($value)) { throw new InvalidArgumentException('Suggestion value must be text or a field mapping.'); }
		$this->assertSafeString($value, 500);
	}

	private function assertSafeString(string $value, int $maximum): void {
		$value = trim($value);
		$length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
		if ($value === '' || $length > $maximum || Redactor::redact($value) !== $value
			|| preg_match('/\b(?:identity|name|full[ _-]?name|email|e-mail|phone|telephone|mobile|ip[ _-]?address|street[ _-]?address|postcode|postal[ _-]?code|gclid|gbraid|wbraid|msclkid|fbclid|ttclid|li_fat_id|utm(?:_[a-z]+)?|attribution|consent|hash(?:ed)?|secret|api[ _-]?key|access[ _-]?token|token|password|passphrase|attachment|upload(?:ed)?|file(?:name)?)\b/i', $value)) {
			throw new InvalidArgumentException('Suggestion contains privacy-sensitive or forbidden content.');
		}
	}

	/** @param array<mixed> $value */
	private function encodedLength(array $value): int {
		return strlen(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
	}

	private function textLength(string $value): int { return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value); }
}
