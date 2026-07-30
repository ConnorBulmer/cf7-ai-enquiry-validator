<?php

declare(strict_types=1);

namespace CF7AIV\Integration;

use CF7AIV\Classification\ContextBuilder;
use CF7AIV\Classification\EvidenceEvaluator;
use CF7AIV\Classification\Eligibility;
use CF7AIV\Classification\PolicyCompiler;
use CF7AIV\Classification\PolicyPackRegistry;
use CF7AIV\Classification\PolicyValidator;
use CF7AIV\Classification\ResultValidator;
use CF7AIV\Contracts\AIProviderInterface;
use CF7AIV\Contracts\DestinationInterface;
use CF7AIV\Delivery\DeliveryMapping;
use CF7AIV\Delivery\DeliveryStoreInterface;
use CF7AIV\Delivery\DeliveryWorker;
use CF7AIV\Domain\ClassificationRequest;
use CF7AIV\Domain\ClassificationResult;
use CF7AIV\Domain\ConversionEvent;
use CF7AIV\Domain\DeliveryResult;
use RuntimeException;

final class PipelineSelfTest {
	/** @var callable():int */
	private $clock;

	public function __construct(
		private readonly ?AIProviderInterface $provider = null,
		?callable $clock = null,
	) {
		$this->clock = $clock ?? static fn (): int => time();
	}

	/** @return array<string,mixed> */
	public function run(): array {
		$policy = (new PolicyPackRegistry())->get('custom_business');
		if ($policy === null) { throw new RuntimeException('The generic custom-business policy pack is unavailable.'); }
		$policy['business'] = [
			'description' => 'A business providing commercial growth and advisory services.',
			'services' => ['Growth strategy', 'Business advisory'],
			'audiences' => ['Organisations seeking commercial support'],
		];
		$compiled = PolicyCompiler::compile($policy);
		$definition = $compiled['definition'];
		$fields = [
			'message' => 'We need help with a customer acquisition strategy and would like to book a discovery call next week.',
			'subject' => 'Growth strategy project',
			'your-name' => 'Ada Private',
			'your-email' => 'ada@example.test',
			'cf7aiv_gclid' => 'test-click-id',
		];
		$base = ContextBuilder::request(
			'pipeline-self-test',
			(string) $definition['pack']['version'],
			$fields,
			ContextBuilder::safeWhitelist((array) $definition['ai_whitelist']),
			$compiled['prompt'],
		);
		$allowedEvidence = [];
		foreach ($definition['evidence_catalog'] as $evidence) {
			$allowedEvidence[(string) $evidence['code']] = (string) $evidence['dimension'];
		}
		$request = new ClassificationRequest(
			$base->itemId,
			$base->policyVersion,
			$base->redactedText,
			$base->context,
			$base->systemPrompt,
			array_values(array_map(static fn (array $tier): string => (string) $tier['id'], $definition['tier_definitions'])),
			array_values(array_unique(array_map(static fn (array $tier): string => (string) $tier['outcome_id'], $definition['tier_definitions']))),
			$allowedEvidence,
			PolicyValidator::SCHEMA_V2,
			array_values($definition['exclusion_catalog']),
			array_values($definition['ambiguity_catalog']),
		);
		$result = $this->provider instanceof AIProviderInterface
			? $this->provider->classify($request)
			: $this->deterministicResult($request);
		$evidence = EvidenceEvaluator::evaluate($result, $definition);
		$tier = null;
		foreach ($definition['tier_definitions'] as $candidate) {
			if (($candidate['id'] ?? '') === $result->tierId) { $tier = $candidate; break; }
		}
		$eligibility = Eligibility::evaluate($result, $evidence, [
			'policy_status' => 'published',
			'automation_mode' => 'live',
			'calibration' => false,
			'historical' => false,
			'tier_ads_eligible' => is_array($tier) && ! empty($tier['ads_eligible']),
			'mapping_published' => true,
		]);

		$now = ($this->clock)();
		$createdAt = gmdate(DATE_ATOM, $now);
		$item = [
			'id' => 900000001,
			'tier_id' => $result->tierId,
			'outcome_id' => $result->outcomeId,
			'decision_state' => $evidence->state,
			'verification_state' => 'off',
			'is_shadow' => 0,
			'is_calibration' => 0,
			'is_historical' => 0,
			'created_at' => $createdAt,
			'classified_at' => $createdAt,
			'form_id' => 1,
			'source_type' => 'self_test',
			'enquiry_text' => $fields['message'],
			'name' => $fields['your-name'],
			'email' => $fields['your-email'],
		];
		$mapping = new DeliveryMapping(
			'pipeline-self-test',
			'1',
			$result->tierId,
			'webhook',
			'cf7aiv_pipeline_test',
			0.0,
			'GBP',
			'published',
			true,
		);
		$store = new PipelineSelfTestStore();
		$destination = new PipelineSelfTestDestination();
		$settings = [
			'automation' => ['mode' => 'live', 'verification_mode' => 'off'],
			'consent' => ['mode' => 'always_actual'],
			'privacy' => ['enhanced_matching' => false],
			'delivery' => ['pause_threshold' => 3],
			'destinations' => [
				'webhook' => ['enabled' => true, 'url' => 'https://self-test.invalid/conversion'],
			],
		];
		$signals = [
			['client_id' => '555.' . $now, 'session_id' => (string) $now, 'session_started_at' => $createdAt],
			['analytics_storage' => 'denied', 'ad_storage' => 'denied', 'ad_user_data' => 'denied', 'ad_personalization' => 'denied'],
		];
		$worker = new DeliveryWorker(
			$store,
			static fn (int $itemId): array => $item,
			static fn (string $tierId): array => $eligibility->eligible ? [$mapping] : [],
			static fn (): array => ['webhook' => $destination],
			static fn (): array => $settings,
			static fn (array $resolvedItem): array => $signals,
			static function (string $hook, array $arguments, int $delay): void {},
			static fn (string $name): string => $name === 'webhook_secret' ? 'isolated-self-test-secret' : '',
		);
		$worker->process((int) $item['id']);
		$worker->process((int) $item['id']);

		$aiSerialized = json_encode([$request->redactedText, $request->context], JSON_THROW_ON_ERROR);
		$envelope = $destination->lastEvent?->toArray() ?? [];
		$envelopeSerialized = json_encode($envelope, JSON_THROW_ON_ERROR);
		$forbidden = ['Ada Private', 'ada@example.test', 'test-click-id'];
		$aiSafe = $this->containsNone($aiSerialized, $forbidden);
		$envelopeSafe = $this->containsNone($envelopeSerialized, array_merge($forbidden, [$fields['message']]));
		$idempotent = $destination->calls <= 1 && count($store->records) <= 1;

		return [
			'successful' => $eligibility->eligible && $destination->calls === 1 && $aiSafe && $envelopeSafe && $idempotent,
			'isolated' => true,
			'production_state_touched' => false,
			'provider' => $this->provider?->key() ?? 'deterministic-local',
			'ai_context_fields' => array_keys($request->context),
			'ai_payload_pii_free' => $aiSafe,
			'classification' => [
				'tier_id' => $result->tierId,
				'outcome_id' => $result->outcomeId,
				'reason_code' => $result->reasonCode,
				'confidence' => $result->confidence,
				'ambiguity_flags' => $result->ambiguityFlags,
				'missing_evidence' => $result->missingEvidence,
				'exclusion_codes' => $result->exclusionCodes,
			],
			'evidence_state' => $evidence->state,
			'evidence_reasons' => $evidence->reasons,
			'eligible' => $eligibility->eligible,
			'eligibility_reasons' => $eligibility->reasons,
			'destination_calls' => $destination->calls,
			'delivery_records' => count($store->records),
			'idempotent' => $idempotent,
			'envelope_pii_free' => $envelopeSafe,
			'envelope' => $envelope,
		];
	}

	private function deterministicResult(ClassificationRequest $request): ClassificationResult {
		return ResultValidator::fromArray([
			'outcome_id' => 'primary_opportunity',
			'tier_id' => 'primary_opportunity',
			'reason_code' => 'qualified',
			'model_confidence' => 0.96,
			'evidence' => [
				['dimension' => 'service_fit', 'code' => 'service_fit', 'field_key' => 'message', 'excerpt' => 'customer acquisition strategy'],
				['dimension' => 'commercial_intent', 'code' => 'commercial_intent', 'field_key' => 'message', 'excerpt' => 'would like to book'],
				['dimension' => 'specificity', 'code' => 'specific_need', 'field_key' => 'message', 'excerpt' => 'customer acquisition strategy'],
				['dimension' => 'readiness', 'code' => 'readiness_signal', 'field_key' => 'message', 'excerpt' => 'next week'],
				['dimension' => 'requested_action', 'code' => 'requested_action', 'field_key' => 'message', 'excerpt' => 'book a discovery call'],
			],
			'missing_evidence' => [],
			'exclusion_codes' => [],
			'ambiguity_flags' => [],
			'summary' => 'Prospect requests a discovery call for a defined growth project.',
		], $request);
	}

	/** @param list<string> $forbidden */
	private function containsNone(string $serialized, array $forbidden): bool {
		foreach ($forbidden as $value) {
			if ($value !== '' && str_contains($serialized, $value)) { return false; }
		}
		return true;
	}
}

final class PipelineSelfTestStore implements DeliveryStoreInterface {
	/** @var array<string,array<string,mixed>> */
	public array $records = [];

	public function claim(int $itemId, DeliveryMapping $mapping, ConversionEvent $event): array {
		$key = $event->idempotencyKey;
		if (! isset($this->records[$key])) {
			$this->records[$key] = ['id' => 1, 'state' => 'pending', 'attempts' => 0, 'lease_token' => 'self-test-lease'];
		}
		$acquired = in_array($this->records[$key]['state'], ['pending', 'retrying'], true);
		if ($acquired) { $this->records[$key]['state'] = 'sending'; }
		return $this->records[$key] + ['acquired' => $acquired];
	}

	public function record(int $deliveryId, DeliveryResult $result, string $leaseToken = ''): int {
		foreach ($this->records as &$record) {
			if ((int) $record['id'] !== $deliveryId || $leaseToken !== (string) $record['lease_token']) { continue; }
			$record['attempts']++;
			$record['state'] = $result->successful ? 'delivered' : ($result->retryable ? 'retrying' : 'failed');
			return (int) $record['attempts'];
		}
		return 0;
	}

	public function pause(string $destinationKey, string $sanitizedReason): void {}
	public function permanentFailures(string $destinationKey): int { return 0; }
	public function isPaused(string $destinationKey): bool { return false; }
}

final class PipelineSelfTestDestination implements DestinationInterface {
	public int $calls = 0;
	public ?ConversionEvent $lastEvent = null;
	public function key(): string { return 'webhook'; }
	public function send(ConversionEvent $event): DeliveryResult {
		$this->calls++;
		$this->lastEvent = $event;
		return new DeliveryResult(true, false, 'isolated-self-test');
	}
}
