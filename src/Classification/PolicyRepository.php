<?php

declare(strict_types=1);

namespace CF7AIV\Classification;

final class PolicyRepository {
	private PolicyStoreInterface $store;

	public function __construct(?PolicyStoreInterface $store = null) {
		$this->store = $store ?? new WpPolicyStore();
	}

	/** @param array<string, mixed> $definition */
	public function saveDraft(string $policyKey, string $version, array $definition, int $createdBy = 0): int {
		$compiled = PolicyCompiler::compile($definition);
		$now = function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s');
		return $this->store->save([
			'policy_key' => $policyKey,
			'version' => $version,
			'status' => 'draft',
			'policy_format' => $compiled['definition']['schema'],
			'pack_key' => $compiled['definition']['pack']['key'] ?? null,
			'pack_version' => $compiled['definition']['pack']['version'] ?? null,
			'definition_json' => json_encode($compiled['definition'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
			'compiled_prompt' => $compiled['prompt'],
			'compiled_prompt_hash' => $compiled['hash'],
			'thresholds_json' => json_encode($compiled['definition']['thresholds'] ?? [], JSON_THROW_ON_ERROR),
			'examples_json' => json_encode($compiled['definition']['examples'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
			'created_by' => $createdBy,
			'created_at' => $now,
			'updated_at' => $now,
			'published_at' => null,
		]);
	}

	public function publish(string $policyKey, string $version): bool {
		return $this->store->publish($policyKey, $version);
	}

	/** @return array<string, mixed>|null */
	public function publishedForForm(int $formId): ?array {
		$record = $this->store->published(['form:' . $formId, 'global']);
		if ($record === null) { return null; }
		$definition = json_decode((string) ($record['definition_json'] ?? ''), true);
		if (! is_array($definition)) { return null; }
		$record['definition'] = PolicyValidator::import($definition);
		return $record;
	}
}
