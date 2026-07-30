<?php

declare(strict_types=1);

namespace CF7AIV\Domain;

use CF7AIV\Classification\Redactor;

final class PolicySuggestionRequest {
	private const MAX_SERIALIZED_CHARACTERS = 36000;
	private const PAGE_SECTION_CHARACTERS = 26000;
	private const PAGE_LIMIT = 50;
	private const FORM_LIMIT = 25;
	private const FIELD_LIMIT = 50;
	private const OPTION_LIMIT = 20;
	private const FORBIDDEN_PARTS = [
		'name', 'email', 'e-mail', 'phone', 'telephone', 'mobile', 'tel', 'address', 'postcode', 'postal',
		'ip', 'file', 'attachment', 'upload', 'gclid', 'dclid', 'wbraid', 'gbraid', 'msclkid', 'fbclid',
		'ttclid', 'li_fat_id', 'utm_', 'campaign', 'source', 'medium', 'landing', 'referrer', 'consent',
		'cookie', 'session', 'client_id', 'user_id', 'attribution', 'hash', 'secret', 'token', 'password',
	];
	/** @var array{pack_key:string,pages:list<array{id:int,title:string,url:string,text:string}>,forms:list<array{id:int,title:string,fields:list<array{name:string,type:string,label:string,options:list<string>}>,page_ids:list<int>}>}|null */
	private ?array $providerProfileCache = null;
	/** @param array<string,mixed> $profile */
	public function __construct(
		public readonly string $packKey,
		public readonly array $profile,
	) {}

	/** @return array{pack_key:string,pages:list<array{id:int,title:string,url:string,text:string}>,forms:list<array{id:int,title:string,fields:list<array{name:string,type:string,label:string,options:list<string>}>,page_ids:list<int>}>} */
	public function providerProfile(): array {
		if ($this->providerProfileCache !== null) { return $this->providerProfileCache; }
		$result = ['pack_key' => $this->normalizedPackKey(), 'pages' => [], 'forms' => []];
		foreach (array_slice((array) ($this->profile['pages'] ?? []), 0, self::PAGE_LIMIT) as $page) {
			if (! is_array($page)) { continue; }
			$candidate = $result;
			$candidate['pages'][] = [
				'id' => (int) ($page['id'] ?? 0),
				'title' => $this->clean((string) ($page['title'] ?? ''), 200),
				'url' => $this->localUrl((string) ($page['url'] ?? '')),
				'text' => $this->clean((string) ($page['text'] ?? ''), 2000),
			];
			if ($this->serializedLength($candidate) > self::PAGE_SECTION_CHARACTERS) { break; }
			$result = $candidate;
		}
		foreach (array_slice((array) ($this->profile['forms'] ?? []), 0, self::FORM_LIMIT) as $form) {
			if (! is_array($form)) { continue; }
			$formValue = [
				'id' => (int) ($form['id'] ?? 0),
				'title' => $this->clean((string) ($form['title'] ?? ''), 200),
				'fields' => [],
				'page_ids' => array_slice(array_values(array_unique(array_filter(array_map('intval', (array) ($form['page_ids'] ?? [])), static fn (int $id): bool => $id > 0))), 0, 50),
			];
			foreach (array_slice((array) ($form['fields'] ?? []), 0, self::FIELD_LIMIT) as $field) {
				if (! is_array($field)) { continue; }
				$name = (string) ($field['name'] ?? '');
				$type = (string) ($field['type'] ?? '');
				$label = (string) ($field['label'] ?? '');
				if (! self::isProviderVisibleField($field)) { continue; }
				$options = [];
				if (self::isChoiceType($type)) {
					foreach (array_slice((array) ($field['options'] ?? []), 0, self::OPTION_LIMIT) as $option) {
						if (is_scalar($option)) { $options[] = $this->clean((string) $option, 160); }
					}
				}
				$nextForm = $formValue;
				$nextForm['fields'][] = [
					'name' => substr(preg_replace('/[^a-zA-Z0-9_\-]/', '', $name) ?? '', 0, 100),
					'type' => substr(preg_replace('/[^a-zA-Z0-9_\-]/', '', $type) ?? '', 0, 40),
					'label' => $this->clean($label, 160),
					'options' => array_values(array_unique(array_filter($options, static fn (string $value): bool => $value !== ''))),
				];
				$candidate = $result;
				$candidate['forms'][] = $nextForm;
				if ($this->serializedLength($candidate) > self::MAX_SERIALIZED_CHARACTERS) { break; }
				$formValue = $nextForm;
			}
			$candidate = $result;
			$candidate['forms'][] = $formValue;
			if ($this->serializedLength($candidate) > self::MAX_SERIALIZED_CHARACTERS) { break; }
			$result = $candidate;
		}
		return $this->providerProfileCache = $result;
	}

	public function providerProfileJson(): string {
		$json = json_encode($this->providerProfile(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		if (strlen($json) > self::MAX_SERIALIZED_CHARACTERS) { throw new \InvalidArgumentException('Provider profile exceeds its safe serialized boundary.'); }
		return $json;
	}

	/** @return list<string> */
	public function allowedSourceRefs(): array {
		$visible = $this->providerProfile();
		$refs = ['pack:' . (string) ($visible['pack_key'] ?? 'general')];
		foreach ($visible['pages'] as $page) {
			if (is_array($page) && (int) ($page['id'] ?? 0) > 0) { $refs[] = 'page:' . (int) $page['id']; }
		}
		foreach ($visible['forms'] as $form) {
			if (is_array($form) && (int) ($form['id'] ?? 0) > 0) { $refs[] = 'form:' . (int) $form['id']; }
		}
		return array_values(array_unique($refs));
	}

	public static function isForbiddenField(string $name, string $type = '', string $label = ''): bool {
		$type = strtolower(trim($type));
		if (in_array($type, ['email', 'tel', 'file', 'hidden', 'password'], true)) { return true; }
		$normalized = strtolower(trim($name . ' ' . $label . ' ' . $type));
		if (str_contains($normalized, 'cf7aiv_')) { return true; }
		foreach (self::FORBIDDEN_PARTS as $part) {
			if (str_contains($normalized, $part)) { return true; }
		}
		return false;
	}

	/** @param array<string,mixed> $field */
	public static function isProviderVisibleField(array $field): bool {
		$name = trim((string) ($field['name'] ?? ''));
		$type = trim((string) ($field['type'] ?? ''));
		$label = trim((string) ($field['label'] ?? ''));
		return $name !== '' && $type !== '' && ! self::isForbiddenField($name, $type, $label);
	}

	public static function isChoiceType(string $type): bool {
		return in_array(strtolower(trim($type)), ['select', 'checkbox', 'radio', 'exclusive_checkbox'], true);
	}

	private function clean(string $value, int $limit): string {
		$value = Redactor::redact(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		$value = preg_replace('/\b(?:api[ _-]?key|access[ _-]?token|token|password|passphrase|secret)\s*[:=]\s*\S+/i', '[REDACTED]', $value) ?? '';
		$value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
		return function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);
	}

	private function localUrl(string $url): string {
		if ($url === '') { return ''; }
		$home = function_exists('home_url') ? (string) home_url('/') : '';
		$parts = parse_url($url);
		if (! is_array($parts)) { return ''; }
		$host = strtolower((string) ($parts['host'] ?? ''));
		$homeHost = strtolower((string) parse_url($home, PHP_URL_HOST));
		$scheme = strtolower((string) ($parts['scheme'] ?? ''));
		if ($host === '' || $homeHost === '' || ! hash_equals($homeHost, $host) || ! in_array($scheme, ['http', 'https'], true)) { return ''; }
		$path = (string) ($parts['path'] ?? '/');
		$path = '/' . ltrim(str_replace(["\r", "\n"], '', $path), '/');
		$url = $scheme . '://' . $homeHost . $path;
		return function_exists('mb_substr') ? mb_substr($url, 0, 500) : substr($url, 0, 500);
	}

	private function normalizedPackKey(): string {
		$value = substr(preg_replace('/[^a-zA-Z0-9_\-]/', '', trim($this->packKey)) ?? '', 0, 100);
		return $value === '' ? 'general' : $value;
	}

	/** @param array<string,mixed> $value */
	private function serializedLength(array $value): int {
		return strlen(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
	}
}
