<?php

declare(strict_types=1);

namespace CF7AIV\Classification;

use CF7AIV\Contracts\AIProviderInterface;
use CF7AIV\Contracts\SubmissionReferenceLocatorInterface;
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
	/** @var callable(int,array<string,mixed>):bool */ private $duplicateResolver;

	public function __construct(
		private readonly ClassificationItemStoreInterface $items,
		private readonly PolicyRepository $policies,
		?callable $sourceResolver = null,
		?callable $providerResolver = null,
		?callable $settingsResolver = null,
		?callable $enqueue = null,
		?callable $mappingResolver = null,
		CalibrationRunStoreInterface|callable|null $calibrationAuthority = null,
		?callable $duplicateResolver = null,
	) {
		$this->duplicateResolver = $duplicateResolver ?? static fn (int $itemId, array $item): bool => self::recentEnquiryFromSamePerson($itemId, $item);
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
			$whitelist = ContextBuilder::safeWhitelist((array) $definition['ai_whitelist']);
			if (! empty($item['is_historical']) || ! empty($item['is_calibration']) || ! empty($record['is_historical']) || ! empty($record['is_calibration'])) {
				$whitelist = array_values(array_intersect($whitelist, ContextBuilder::safeWhitelist($historicalWhitelistSnapshot)));
			}
			$request = RequestFactory::build((string) $itemId, (string) $policy['version'], $definition, (string) $policy['compiled_prompt'], $record['fields'], $whitelist);
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
			/*
			 * A second enquiry from the same person within 30 days would otherwise
			 * be sent as a second conversion automatically. The model cannot know
			 * this - it only ever sees one enquiry - so it is checked here and the
			 * enquiry goes to a person instead. Only hashes already stored are
			 * used; they exist when enhanced matching is on and consent allows it.
			 */
			if ($potentialAutoCandidate && ($this->duplicateResolver)($itemId, $item)) {
				$potentialAutoCandidate = false;
				$evidenceDecision = new EvidenceDecision('needs_review', ['possible_duplicate']);
				$result = $result->withAmbiguityFlag('possible_duplicate');
			}
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
			$submissionId = $this->submissionId($item);
			if ($submissionId === '') {
				throw new PermanentProviderException('A generic CF7 item has no source-link token.');
			}
			$locators = [];
			foreach ($sources as $registeredKey => $source) {
				if (is_string($registeredKey) && $registeredKey !== ''
					&& $source instanceof SubmissionSourceInterface
					&& $source instanceof SubmissionReferenceLocatorInterface) {
					$locators[$registeredKey] = $source;
				}
			}
			if ($locators === []) {
				throw new PermanentProviderException('A generic CF7 item has no registered persistent source.');
			}
			foreach ($locators as $registeredKey => $locator) {
				$reference = $locator->findReferenceBySubmissionId(
					$submissionId,
					(int) ($item['form_id'] ?? 0),
					(string) ($item['created_at'] ?? ''),
				);
				if ($reference === null || trim($reference) === '') { continue; }
				$reference = trim($reference);
				$this->items->linkSource($itemId, $registeredKey, $reference);
				$item['source_type'] = $registeredKey;
				$item['source_reference'] = $reference;
				return [$locator, $reference];
			}
			throw new TransientProviderException('Persistent source link is not visible yet.');
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

	/**
	 * Was an earlier enquiry from the same email or phone hash, in the 30 days
	 * before this one, sent or due to be sent as a conversion?
	 *
	 * Only those count: an earlier support question or spam message from the
	 * same person was never a conversion, so it cannot make this one a second.
	 * "Earlier" is strict - by capture time, then id - so two enquiries captured
	 * in the same second cannot each hold the other back.
	 *
	 * @param array<string,mixed> $item
	 */
	private static function recentEnquiryFromSamePerson(int $itemId, array $item): bool {
		global $wpdb;
		$email = (string) ($item['email_hash'] ?? '');
		$phone = (string) ($item['phone_hash'] ?? '');
		if (($email === '' && $phone === '') || ! isset($wpdb->prefix) || ! method_exists($wpdb, 'get_var')) { return false; }
		$created = (string) ($item['created_at'] ?? '');
		$created = strtotime($created) === false ? gmdate('Y-m-d H:i:s') : $created;
		return (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}cf7aiv_items item WHERE (item.created_at<%s OR (item.created_at=%s AND item.id<%d)) AND item.created_at>=DATE_SUB(%s, INTERVAL 30 DAY) AND item.is_calibration=0 AND item.is_historical=0 AND ((item.email_hash<>'' AND item.email_hash=%s) OR (item.phone_hash<>'' AND item.phone_hash=%s)) AND (item.review_state IN ('eligible','approved_dispatch') OR EXISTS (SELECT 1 FROM {$wpdb->prefix}cf7aiv_deliveries delivery WHERE delivery.item_id=item.id AND delivery.state IN ('delivered','sending','retrying','pending')))",
			$created, $created, $itemId, $created, $email, $phone
		)) > 0;
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
