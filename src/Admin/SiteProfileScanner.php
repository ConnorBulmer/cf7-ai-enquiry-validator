<?php

declare(strict_types=1);

namespace CF7AIV\Admin;

use Closure;

final class SiteProfileScanner {
	private const PAGE_LIMIT = 50;
	private const PAGE_CHARACTER_LIMIT = 2000;
	private const PAGE_INPUT_CHARACTER_LIMIT = 100000;
	private const TOTAL_CHARACTER_LIMIT = 40000;
	private const FORM_REFERENCE_LIMIT = 100;
	private readonly ?Closure $pageLoader;
	private readonly ?Closure $formLoader;

	public function __construct(?callable $pageLoader = null, ?callable $formLoader = null) {
		$this->pageLoader = $pageLoader === null ? null : Closure::fromCallable($pageLoader);
		$this->formLoader = $formLoader === null ? null : Closure::fromCallable($formLoader);
	}

	/** @return array{pages:list<array{id:int,title:string,url:string,text:string}>,forms:list<array{id:int,title:string,fields:list<array{name:string,type:string,label:string,options:list<string>}>,page_ids:list<int>}>} */
	public function scan(): array {
		$postTypes = $this->postTypes();
		$rawPages = $this->pageLoader === null ? $this->loadPages($postTypes) : ($this->pageLoader)();
		$rawPages = $this->filterPosts($rawPages);
		$pages = [];
		$formPageIds = [];
		$total = 0;
		foreach (is_iterable($rawPages) ? $rawPages : [] as $page) {
			if (count($pages) >= self::PAGE_LIMIT) { break; }
			$pageData = $this->page($page, self::TOTAL_CHARACTER_LIMIT - $total, $postTypes);
			if ($pageData === null) { continue; }
			$normalized = $pageData['profile'];
			$total += $this->length($normalized['text']);
			$pages[] = $normalized;
			$content = $pageData['content'];
			$discoveredFormIds = [];
			if (preg_match_all('/\[contact-form-7\b[^\]]*\bid\s*=\s*["\']?(\d+)/i', $content, $matches)) {
				$discoveredFormIds = $matches[1];
			}
			if (function_exists('apply_filters')) {
				$filteredFormIds = apply_filters('cf7aiv_site_profile_post_form_ids', $discoveredFormIds, $page, $content);
				if (is_array($filteredFormIds)) { $discoveredFormIds = $filteredFormIds; }
			}
			foreach ($this->positiveIds($discoveredFormIds) as $formId) {
				$formPageIds[$formId][] = $normalized['id'];
			}
			if ($total >= self::TOTAL_CHARACTER_LIMIT) { break; }
		}

		$rawForms = $this->formLoader === null ? $this->loadForms() : ($this->formLoader)();
		$forms = [];
		foreach (is_iterable($rawForms) ? $rawForms : [] as $form) {
			$normalized = $this->form($form);
			if ($normalized !== null) {
				$normalized['page_ids'] = array_values(array_unique(array_merge($normalized['page_ids'], $formPageIds[$normalized['id']] ?? [])));
				$forms[] = $normalized;
			}
		}
		return ['pages' => $pages, 'forms' => $forms];
	}

	/** @param list<string> $postTypes @return list<mixed> */
	private function loadPages(array $postTypes): array {
		if (! function_exists('get_posts') || $postTypes === []) { return []; }
		$pages = get_posts([
			'post_type' => $postTypes,
			'post_status' => 'publish',
			'has_password' => false,
			'numberposts' => self::PAGE_LIMIT,
			'posts_per_page' => self::PAGE_LIMIT,
			'orderby' => 'ID',
			'order' => 'ASC',
		]);
		return is_array($pages) ? $pages : [];
	}

	/** @return list<string> */
	private function postTypes(): array {
		$defaults = ['page', 'post'];
		$types = $defaults;
		if (function_exists('apply_filters')) {
			$filtered = apply_filters('cf7aiv_site_profile_post_types', $types);
			if (is_array($filtered)) { $types = $filtered; }
		}
		$normalized = [];
		foreach ($types as $type) {
			if (! is_scalar($type)) { continue; }
			$value = strtolower((string) $type);
			$value = function_exists('sanitize_key') ? sanitize_key($value) : (preg_replace('/[^a-z0-9_\-]/', '', $value) ?? '');
			$value = substr($value, 0, 20);
			if ($value !== '' && ! in_array($value, $normalized, true)) { $normalized[] = $value; }
		}
		return $normalized;
	}

	/** @return list<mixed> */
	private function filterPosts(mixed $rawPages): array {
		$pages = is_array($rawPages) ? array_values($rawPages) : (is_iterable($rawPages) ? array_values(iterator_to_array($rawPages, false)) : []);
		if (! function_exists('apply_filters')) { return $pages; }
		$filtered = apply_filters('cf7aiv_site_profile_posts', $pages);
		return is_array($filtered) ? array_values($filtered) : $pages;
	}

	/** @return list<mixed> */
	private function loadForms(): array {
		if (! class_exists('WPCF7_ContactForm') || ! method_exists('WPCF7_ContactForm', 'find')) { return []; }
		$forms = \WPCF7_ContactForm::find();
		return is_array($forms) ? $forms : [];
	}

	/** @param list<string> $postTypes @return array{profile:array{id:int,title:string,url:string,text:string},content:string}|null */
	private function page(mixed $page, int $remaining, array $postTypes): ?array {
		$status = (string) $this->read($page, ['post_status', 'status'], 'publish');
		$password = (string) $this->read($page, ['post_password', 'password'], '');
		$postType = strtolower((string) $this->read($page, ['post_type', 'type'], 'page'));
		$postType = function_exists('sanitize_key') ? sanitize_key($postType) : (preg_replace('/[^a-z0-9_\-]/', '', $postType) ?? '');
		if ($status !== 'publish' || $password !== '' || $remaining <= 0 || ! in_array($postType, $postTypes, true)) { return null; }
		$id = (int) $this->read($page, ['ID', 'id'], 0);
		if ($id <= 0) { return null; }
		$url = (string) $this->read($page, ['permalink', 'url'], '');
		if ($url === '' && function_exists('get_permalink')) { $url = (string) get_permalink($id); }
		if (! $this->isLocalUrl($url)) { return null; }
		$content = $this->cut((string) $this->read($page, ['post_content', 'text', 'content'], ''), self::PAGE_INPUT_CHARACTER_LIMIT);
		if (function_exists('apply_filters')) {
			$filteredContent = apply_filters('cf7aiv_site_profile_post_content', $content, $page);
			if (is_string($filteredContent)) { $content = $this->cut($filteredContent, self::PAGE_INPUT_CHARACTER_LIMIT); }
		}
		$text = $this->normalize($content);
		$text = $this->cut($text, min(self::PAGE_CHARACTER_LIMIT, $remaining));
		return [
			'profile' => [
				'id' => $id,
				'title' => $this->cut($this->normalize((string) $this->read($page, ['post_title', 'title'], '')), 200),
				'url' => $url,
				'text' => $text,
			],
			'content' => $content,
		];
	}

	/** @return list<int> */
	private function positiveIds(mixed $values): array {
		if (! is_array($values)) { return []; }
		$ids = [];
		$inspected = 0;
		foreach ($values as $value) {
			$inspected++;
			if ($inspected > self::FORM_REFERENCE_LIMIT) { break; }
			if (! is_scalar($value)) { continue; }
			$id = (int) $value;
			if ($id <= 0 || in_array($id, $ids, true)) { continue; }
			$ids[] = $id;
			if (count($ids) >= self::FORM_REFERENCE_LIMIT) { break; }
		}
		return $ids;
	}

	/** @return array{id:int,title:string,fields:list<array{name:string,type:string,label:string,options:list<string>}>,page_ids:list<int>}|null */
	private function form(mixed $form): ?array {
		$id = (int) $this->read($form, ['id', 'ID'], 0);
		if ($id <= 0 && is_object($form) && method_exists($form, 'id')) { $id = (int) $form->id(); }
		if ($id <= 0) { return null; }
		$title = (string) $this->read($form, ['title', 'post_title'], '');
		if ($title === '' && is_object($form) && method_exists($form, 'title')) { $title = (string) $form->title(); }
		$rawFields = $this->read($form, ['fields', 'tags'], null);
		if ($rawFields === null && is_object($form) && method_exists($form, 'scan_form_tags')) { $rawFields = $form->scan_form_tags(); }
		$fields = [];
		foreach (is_iterable($rawFields) ? $rawFields : [] as $field) {
			$name = substr(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $this->read($field, ['name'], '')) ?? '', 0, 100);
			if ($name === '') { continue; }
			$type = substr(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $this->read($field, ['type', 'basetype'], 'text')) ?? '', 0, 40);
			$label = (string) $this->read($field, ['label'], '');
			if ($label === '') { $label = ucwords(str_replace(['-', '_'], ' ', preg_replace('/^your[-_]/', '', $name) ?? $name)); }
			$options = [];
			if (\CF7AIV\Domain\PolicySuggestionRequest::isChoiceType($type)
				&& ! \CF7AIV\Domain\PolicySuggestionRequest::isForbiddenField($name, $type, $label)) {
				foreach ((array) $this->read($field, ['options', 'values', 'raw_values'], []) as $option) {
					if (! is_scalar($option)) { continue; }
					$value = $this->cut($this->normalize((string) $option), 160);
					if ($value !== '') { $options[] = $value; }
				}
			}
			$fields[] = ['name' => $name, 'type' => $type, 'label' => $this->cut($this->normalize($label), 160), 'options' => array_slice(array_values(array_unique($options)), 0, 50)];
		}
		$pageIds = array_values(array_unique(array_filter(array_map('intval', (array) $this->read($form, ['page_ids'], [])), static fn (int $pageId): bool => $pageId > 0)));
		return ['id' => $id, 'title' => $this->cut($this->normalize($title), 200), 'fields' => $fields, 'page_ids' => $pageIds];
	}

	private function read(mixed $value, array $keys, mixed $default): mixed {
		foreach ($keys as $key) {
			if (is_array($value) && array_key_exists($key, $value)) { return $value[$key]; }
			if (is_object($value) && isset($value->{$key})) { return $value->{$key}; }
		}
		return $default;
	}

	private function normalize(string $value): string {
		$value = function_exists('wp_strip_all_tags') ? wp_strip_all_tags($value) : strip_tags($value);
		return trim(preg_replace('/\s+/u', ' ', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
	}

	private function isLocalUrl(string $url): bool {
		if ($url === '' || ! function_exists('home_url')) { return false; }
		$homeHost = strtolower((string) parse_url((string) home_url('/'), PHP_URL_HOST));
		$urlHost = strtolower((string) parse_url($url, PHP_URL_HOST));
		return $homeHost !== '' && $urlHost !== '' && hash_equals($homeHost, $urlHost);
	}

	private function cut(string $value, int $limit): string { return function_exists('mb_substr') ? mb_substr($value, 0, max(0, $limit)) : substr($value, 0, max(0, $limit)); }
	private function length(string $value): int { return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value); }
}
