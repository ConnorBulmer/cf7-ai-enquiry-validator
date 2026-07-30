<?php

declare(strict_types=1);

namespace CF7AIV\Classification;

use CF7AIV\Contracts\AIProviderInterface;
use CF7AIV\Contracts\SubmissionSourceInterface;
use CF7AIV\Contracts\VerificationCapableProviderInterface;
use CF7AIV\Domain\ClassificationRequest;
use CF7AIV\Domain\ClassificationResult;
use CF7AIV\Delivery\DeliveryMapping;
use CF7AIV\Delivery\WpMappingRepository;
use CF7AIV\Hooks;
use CF7AIV\Infrastructure\Crypto;
use CF7AIV\Infrastructure\Queue;
use CF7AIV\Infrastructure\Settings;
use CF7AIV\Providers\PermanentProviderException;
use CF7AIV\Providers\ProviderException;
use CF7AIV\Providers\TransientProviderException;
use CF7AIV\Sources\CFDB7Source;
use CF7AIV\Admin\WpCalibrationRunStore;
use CF7AIV\Admin\CalibrationRunStoreInterface;
use Throwable;

final class ClassificationWorker {
	/** @var callable():array<string,SubmissionSourceInterface> */ private $sourceResolver;
	/** @var callable():array<string,AIProviderInterface> */ private $providerResolver;
	/** @var callable():array<string,mixed> */ private $settingsResolver;
	/** @var callable(string,array<int,mixed>,int):void */ private $enqueue;
	/** @var callable(string):array<int,mixed> */ private $mappingResolver;
	/** @var callable(string):?string */ private $calibrationState;
	/** @var callable(string,callable):bool */ private $calibrationGuard;

	public function __construct(
		private readonly ClassificationItemStoreInterface $items,
		private readonly PolicyRepository $policies,
		?callable $sourceResolver = null,
		?callable $providerResolver = null,
		?callable $settingsResolver = null,
		?callable $enqueue = null,
		?callable $mappingResolver = null,
		CalibrationRunStoreInterface|callable|null $calibrationAuthority = null,
	) {
		$this->sourceResolver = $sourceResolver ?? static fn (): array => function_exists('apply_filters') ? (array) apply_filters('cf7aiv_submission_sources', []) : [];
		$this->providerResolver = $providerResolver ?? static fn (): array => function_exists('apply_filters') ? (array) apply_filters('cf7aiv_ai_providers', []) : [];
		$this->settingsResolver = $settingsResolver ?? static fn (): array => function_exists('get_option') ? (array) get_option(Settings::OPTION, Settings::defaults()) : Settings::defaults();
		$this->enqueue = $enqueue ?? static function (string $hook, array $arguments, int $delay): void {
			if ($delay > 0) { Queue::schedule($hook, time() + $delay, $arguments); }
			else { Queue::enqueue($hook, $arguments); }
		};
		$this->mappingResolver = $mappingResolver ?? static fn (string $tierId): array => (new WpMappingRepository())->publishedForTier($tierId);
		$authority=$calibrationAuthority??new WpCalibrationRunStore();
		if($authority instanceof CalibrationRunStoreInterface){
			$this->calibrationState=fn(string $runId):?string=>$authority->state($runId);
			$this->calibrationGuard=fn(string $runId,callable $operation):bool=>$authority->whileActive($runId,$operation);
		}else{
			$this->calibrationState=$authority;
			$this->calibrationGuard=fn(string $runId,callable $operation):bool=>($this->calibrationState)($runId)==='active'&&$operation()===true;
		}
	}

	public static function standard(): self {
		return new self(new WpClassificationItemStore(), new PolicyRepository());
	}

	/** @param list<string> $historicalWhitelistSnapshot */
	public function process(int $itemId, bool $replacePrediction = false, array $historicalWhitelistSnapshot = []): void {
		try {
			$item = $this->items->get($itemId);
			if ($item === null) { return; }
			if (! $this->calibrationActive($item)) { return; }
			if (! $replacePrediction
				&& (string) ($item['predicted_tier_id'] ?? '') !== ''
				&& (string) ($item['predicted_outcome_id'] ?? '') !== '') {
				return;
			}
			[$source, $reference] = $this->resolveSource($itemId, $item);
			if (! $this->calibrationActive($item)) { return; }
			$record = $source->get($reference);
			if ($record === null || ! is_array($record['fields'] ?? null)) { throw new PermanentProviderException('Original source content is unavailable.'); }
			$policy = $this->policies->publishedForForm((int) ($item['form_id'] ?? $record['form_id'] ?? 0));
			if ($policy === null) { throw new PermanentProviderException('No published classification policy is available.'); }
			$definition = $policy['definition'];
			$tierIds = array_values(array_map(static fn (array $tier): string => (string) $tier['id'], $definition['tier_definitions']));
			$whitelist = ContextBuilder::safeWhitelist((array) $definition['ai_whitelist']);
			if (! empty($item['is_historical']) || ! empty($item['is_calibration']) || ! empty($record['is_historical']) || ! empty($record['is_calibration'])) {
				$whitelist = array_values(array_intersect($whitelist, ContextBuilder::safeWhitelist($historicalWhitelistSnapshot)));
			}
			$baseRequest = ContextBuilder::request((string) $itemId, (string) $policy['version'], $record['fields'], $whitelist, (string) $policy['compiled_prompt']);
			$outcomeIds = [];
			$allowedEvidence = [];
			$allowedExclusionCodes = [];
			$allowedAmbiguityFlags = [];
			if (($definition['schema'] ?? PolicyValidator::SCHEMA_V1) === PolicyValidator::SCHEMA_V2) {
				$outcomeIds = array_values(array_unique(array_map(static fn (array $tier): string => (string) $tier['outcome_id'], $definition['tier_definitions'])));
				foreach ($definition['evidence_catalog'] as $evidence) {
					$allowedEvidence[(string) $evidence['code']] = (string) $evidence['dimension'];
				}
				$allowedExclusionCodes = array_values($definition['exclusion_catalog']);
				$allowedAmbiguityFlags = array_values($definition['ambiguity_catalog']);
			}
			$request = new ClassificationRequest(
				$baseRequest->itemId,
				$baseRequest->policyVersion,
				$baseRequest->redactedText,
				$baseRequest->context,
				$baseRequest->systemPrompt,
				$tierIds,
				$outcomeIds,
				$allowedEvidence,
				(string) ($definition['schema'] ?? PolicyValidator::SCHEMA_V1),
				$allowedExclusionCodes,
				$allowedAmbiguityFlags,
			);
			$settings = ($this->settingsResolver)();
			$isHistorical = ! empty($item['is_historical']) || ! empty($item['is_calibration']) || ! empty($record['is_historical']) || ! empty($record['is_calibration']);
			$isShadow = $isHistorical || ($settings['automation']['mode'] ?? 'shadow') !== 'live';
			$isShadowOrigin = array_key_exists('is_shadow', $item) ? (int) $item['is_shadow'] !== 0 : $isShadow;
			$provider = $this->provider($settings);
			if (! $this->calibrationActive($item)) { return; }
			$result = $provider->classify($request);
			$tier = null;
			foreach ($definition['tier_definitions'] as $candidate) { if (($candidate['id'] ?? '') === $result->tierId) { $tier = $candidate; break; } }
			$publishedMappings = array_filter(
				($this->mappingResolver)($result->tierId),
				static fn (mixed $mapping): bool => $mapping instanceof DeliveryMapping && $mapping->isPublished(),
			);
			$evidenceDecision = EvidenceEvaluator::evaluate($result, $definition);
			$verificationState = Settings::verificationMode($settings) === Settings::VERIFICATION_AUTOMATIC_CANDIDATES ? 'not_required' : 'off';
			$potentialAutoCandidate = ($definition['schema'] ?? '') === PolicyValidator::SCHEMA_V2
				&& $evidenceDecision->state === 'strong_match'
				&& $result->outcomeId === 'primary_opportunity'
				&& is_array($tier) && ! empty($tier['ads_eligible'])
				&& ($settings['automation']['mode'] ?? 'shadow') === 'live'
				&& ! $isShadowOrigin
				&& empty($item['is_calibration']) && empty($record['is_calibration'])
				&& empty($item['is_historical']) && empty($record['is_historical'])
				&& $publishedMappings !== [];
			if (Settings::verificationMode($settings) === Settings::VERIFICATION_AUTOMATIC_CANDIDATES && $potentialAutoCandidate) {
				if (! $provider instanceof VerificationCapableProviderInterface) {
					$verificationState = 'unsupported';
					$evidenceDecision = new EvidenceDecision('needs_review', ['verification_unsupported']);
				} else {
					try {
						if (! $this->calibrationActive($item)) { return; }
						$verificationResult = $provider->classifyWithReasoning($request);
						$result = $result->withProviderUsage($this->mergeUsage($result, $verificationResult));
						$verificationEvidence = EvidenceEvaluator::evaluate($verificationResult, $definition);
						if ($verificationResult->outcomeId === $result->outcomeId
							&& $verificationResult->tierId === $result->tierId
							&& $verificationEvidence->state === 'strong_match') {
							$verificationState = 'passed';
						} else {
							$verificationState = 'disagreed';
							$evidenceDecision = new EvidenceDecision('needs_review', ['verification_disagreed']);
						}
					} catch (Throwable) {
						$verificationState = 'failed';
						$evidenceDecision = new EvidenceDecision('needs_review', ['verification_failed']);
					}
				}
			}
			$decision = Eligibility::evaluate($result, $evidenceDecision, [
				'policy_status' => $policy['status'] ?? '',
				'automation_mode' => $settings['automation']['mode'] ?? 'shadow',
				'calibration' => ! empty($item['is_calibration']) || ! empty($record['is_calibration']),
				'historical' => ! empty($item['is_historical']) || ! empty($record['is_historical']),
				'tier_ads_eligible' => is_array($tier) && ! empty($tier['ads_eligible']),
				'mapping_published' => $publishedMappings !== [],
			]);
			if (! $this->calibrationActive($item)) { return; }
			$stored=$this->guardMutation($item,function()use($itemId,$result,$policy,$evidenceDecision,$verificationState,$decision,$isShadow,$replacePrediction):bool{
				return $this->items->succeed($itemId,$result,$policy,$evidenceDecision,$verificationState,$decision->eligible?'eligible':'review',$isShadow,$replacePrediction);
			});
			if(!$stored){return;}
			$this->guardMutation($item,function()use($itemId,$result,$decision):bool{
				if(function_exists('do_action')){do_action(Hooks::AFTER_CLASSIFICATION,$itemId,$result,$decision);}
				if($decision->eligible){($this->enqueue)('cf7aiv_evaluate_delivery',[$itemId],0);}
				return true;
			});
		} catch (ProviderException $error) {
			if (isset($item) && is_array($item) && ! $this->calibrationActive($item)) { return; }
			$attempt=0;
			$stored=$this->guardMutation($item,function()use(&$attempt,$itemId,$error,$replacePrediction):bool{
				$result=$this->items->fail($itemId,$error->getMessage(),$error->isTransient(),$replacePrediction);
				if($result===false){return false;}
				$attempt=$result;
				return true;
			});
			if(!$stored){return;}
			if ($error->isTransient() && $attempt <= 3) {
				$this->guardMutation($item,function()use($replacePrediction,$itemId,$attempt,$historicalWhitelistSnapshot,$item):bool{
					$historical = ! empty($item['is_historical']) || ! empty($item['is_calibration']);
					$arguments = $historical
						? [$itemId, $replacePrediction, ContextBuilder::safeWhitelist($historicalWhitelistSnapshot)]
						: ($replacePrediction ? [$itemId, true] : [$itemId]);
					($this->enqueue)(
						'cf7aiv_classify_item',
						$arguments,
						min(3600,60*(2**max(0,$attempt-1))),
					);
					return true;
				});
			}
		} catch (Throwable $error) {
			if (isset($item) && is_array($item) && ! $this->calibrationActive($item)) { return; }
			$this->guardMutation($item,function()use($itemId,$replacePrediction):bool{
				return $this->items->fail($itemId,'Classification could not be completed.',false,$replacePrediction)!==false;
			});
		}
	}

	/** @param array<string,mixed> $item */
	private function calibrationActive(array $item):bool {
		if(empty($item['is_calibration'])){return true;}
		$runId=(string)($item['calibration_run_id']??'');
		return $runId!==''&&($this->calibrationState)($runId)==='active';
	}

	/** @param array<string,mixed> $item */
	private function guardMutation(array $item,callable $operation):bool {
		if(empty($item['is_calibration'])){return $operation()===true;}
		$runId=(string)($item['calibration_run_id']??'');
		return $runId!==''&&($this->calibrationGuard)($runId,$operation);
	}

	/** @param array<string, mixed> $item @return array{SubmissionSourceInterface,string} */
	private function resolveSource(int $itemId, array &$item): array {
		$sources = ($this->sourceResolver)();
		$key = (string) ($item['source_type'] ?? '');
		if ($key === 'cf7') {
			$cfdb7 = $sources['cfdb7'] ?? null;
			if (! $cfdb7 instanceof CFDB7Source) {
				throw new PermanentProviderException('A generic CF7 item has no registered persistent source.');
			}
			$submissionId = $this->submissionId($item);
			if ($submissionId === '') {
				throw new PermanentProviderException('A generic CF7 item has no source-link token.');
			}
			$reference = $cfdb7->findReferenceBySubmissionId($submissionId);
			if ($reference === null) {
				throw new TransientProviderException('Persistent source link is not visible yet.');
			}
			$this->items->linkSource($itemId, 'cfdb7', $reference);
			$item['source_type'] = 'cfdb7';
			$item['source_reference'] = $reference;
			return [$cfdb7, $reference];
		}
		if (! isset($sources[$key]) || ! $sources[$key] instanceof SubmissionSourceInterface) {
			throw new PermanentProviderException('Registered submission source is unavailable.');
		}
		return [$sources[$key], (string) ($item['source_reference'] ?? '')];
	}

	/** @param array<string, mixed> $item */
	private function submissionId(array $item): string {
		try {
			$attribution = json_decode(Crypto::decrypt((string) ($item['attribution_encrypted'] ?? '')), true, 16, JSON_THROW_ON_ERROR);
			$value = is_array($attribution) ? ($attribution['submission_id'] ?? '') : '';
			return is_scalar($value) ? trim((string) $value) : '';
		} catch (Throwable) { return ''; }
	}

	/** @param array<string, mixed> $settings */
	private function provider(array $settings): AIProviderInterface {
		$providers = ($this->providerResolver)();
		$key = (string) ($settings['providers']['selected'] ?? 'deepseek');
		if (! isset($providers[$key]) || ! $providers[$key] instanceof AIProviderInterface) {
			throw new PermanentProviderException('Selected AI provider is unavailable.');
		}
		return $providers[$key];
	}

	/** @return array{input_tokens:int,output_tokens:int,reasoning_tokens:int,calls:int} */
	private function mergeUsage(ClassificationResult $first, ClassificationResult $second): array {
		$usage = [];
		foreach (['input_tokens', 'output_tokens', 'reasoning_tokens', 'calls'] as $key) {
			$usage[$key] = max(0, (int) ($first->providerUsage[$key] ?? 0)) + max(0, (int) ($second->providerUsage[$key] ?? 0));
		}
		return $usage;
	}

}
