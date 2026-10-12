<?php
declare(strict_types=1);
namespace CF7AIV\Admin;

use CF7AIV\Classification\Redactor;
use CF7AIV\Hooks;
use CF7AIV\Infrastructure\Queue;
use CF7AIV\Infrastructure\Settings;
use InvalidArgumentException;

final class ReviewService {
	private $settings; private $dispatch; private $clock; private $reanalyse;
	public function __construct(private readonly ReviewStoreInterface $store, ?callable $settings = null, ?callable $dispatch = null, ?callable $clock = null, ?callable $reanalyse = null) {
		$this->settings = $settings ?? static fn():array => function_exists('get_option') ? (array) get_option(Settings::OPTION, Settings::defaults()) : Settings::defaults();
		$this->dispatch = $dispatch ?? static fn(int $id):mixed => Queue::enqueue('cf7aiv_evaluate_delivery', [$id]);
		$this->clock = $clock ?? static fn():int => time();
		$this->reanalyse = $reanalyse ?? static fn(int $id):mixed => Queue::enqueue('cf7aiv_classify_item', [$id, true]);
	}
	/** @param array<string,mixed> $input */
	public function apply(int $itemId, string $action, array $input, int $administratorId): ReviewDecision {
		$item = $this->store->get($itemId); if ($item === null) { throw new InvalidArgumentException('Review item is unavailable.'); }
		$expectedRevision = array_key_exists('review_revision',$input)
			? self::revision($input['review_revision'])
			: max(0, (int) ($item['review_revision'] ?? 0));
		$states = ['confirm'=>'confirmed','change_tier'=>'corrected','approve_no_send'=>'approved','approve_dispatch'=>'approved_dispatch','reject'=>'rejected','reanalyse'=>'pending'];
		if (! isset($states[$action])) { throw new InvalidArgumentException('Unknown review action.'); }
		$predictedTier = (string) ($item['predicted_tier_id'] ?? $item['tier_id'] ?? '');
		$predictedOutcome = (string) ($item['predicted_outcome_id'] ?? $item['outcome_id'] ?? '');
		$reviewedTier = $action === 'confirm' ? $predictedTier : null;
		$reviewedOutcome = $action === 'confirm' ? $predictedOutcome : null;
		if (in_array($action, ['approve_no_send', 'approve_dispatch'], true)) {
			$reviewedTier = (string) ($item['reviewed_tier_id'] ?? $predictedTier);
			$reviewedOutcome = (string) ($item['reviewed_outcome_id'] ?? $predictedOutcome);
		}
		if ($action === 'change_tier') {
			$reviewedTier = strtolower(trim((string)($input['tier_id']??''))); if (!preg_match('/^[a-z0-9_\-]{1,64}$/',$reviewedTier)) { throw new InvalidArgumentException('A valid corrected tier is required.'); }
			$reviewedOutcome = strtolower(trim((string)($input['outcome_id']??''))); if (!preg_match('/^[a-z0-9_\-]{1,64}$/',$reviewedOutcome)) { throw new InvalidArgumentException('A valid corrected outcome is required.'); }
			$example = trim((string)($input['example_summary']??'')); if ($example==='' || Redactor::redact($example)!==$example) { throw new InvalidArgumentException('Correction examples must be non-identifying administrator-authored text.'); }
		}
		if ($reviewedTier === '' || $reviewedOutcome === '') {
			$reviewedTier = null;
			$reviewedOutcome = null;
		}
		$mutationSucceeded=true;
		if ($action !== 'reanalyse' && $action !== 'approve_dispatch') {
			$mutationSucceeded=$this->store->persist($itemId,$states[$action],$reviewedTier,$reviewedOutcome,$administratorId,$expectedRevision);
			if($mutationSucceeded&&$action==='change_tier'){$this->store->suggest($itemId,$administratorId,$predictedTier,(string)$reviewedTier,substr($example,0,1000));}
		}
		$dispatched = false; $approvalSucceeded = false;
		if ($action==='approve_dispatch' && $this->canDispatch($item) && $administratorId > 0 && $reviewedTier !== null && $reviewedOutcome !== null
			&& $this->store->approveDispatch($itemId,$administratorId,$reviewedTier,$reviewedOutcome,$expectedRevision)) {
			$approvalSucceeded=true;
			$queueResult=($this->dispatch)($itemId);
			$dispatched=Queue::accepted($queueResult);
		}
		if ($action==='reanalyse') { ($this->reanalyse)($itemId); }
		$decisionState=$states[$action];$warnings=$this->timingWarningsFor($item);
		if($action==='approve_dispatch'){
			$current=$this->store->get($itemId);
			$decisionState=(string)($current['review_state']??($approvalSucceeded?'approved_dispatch':($item['review_state']??'review')));
			if(!$approvalSucceeded){$warnings[]='Approve-and-dispatch was not completed.';}
			elseif(!$dispatched){$warnings[]='Approval was recorded, but delivery could not be queued.';}
		}
		elseif(!$mutationSucceeded){
			$current=$this->store->get($itemId);
			$decisionState=(string)($current['review_state']??($item['review_state']??'review'));
			$warnings[]='Review was not completed because the item changed.';
		}
		$decision = new ReviewDecision($decisionState,$dispatched,$warnings);
		if (function_exists('do_action')) { do_action(Hooks::AFTER_REVIEW,$itemId,$action,$administratorId,$decision); }
		return $decision;
	}
	/** @param list<int> $itemIds @param array<string,mixed> $input @return array<int,ReviewDecision> */
	public function applyMany(array $itemIds,string $action,array $input,int $administratorId):array { $out=[]; foreach(array_unique(array_map('intval',$itemIds)) as $id){if($id>0){$itemInput=$input;$revisions=$input['review_revisions']??null;if(is_array($revisions)&&array_key_exists($id,$revisions)){$itemInput['review_revision']=$revisions[$id];}$out[$id]=$this->apply($id,$action,$itemInput,$administratorId);}} return $out; }
	/** @return list<string> */
	public function timingWarnings(int $itemId):array { $item=$this->store->get($itemId); return $item===null?[]:$this->timingWarningsFor($item); }
	/** @param array<string,mixed> $item */
	private function canDispatch(array $item):bool { $settings=($this->settings)(); return array_key_exists('is_shadow',$item) && (int)$item['is_shadow']===0 && empty($item['is_calibration']) && empty($item['is_historical']) && (($settings['automation']['mode']??'shadow')==='live'); }
	/** @param array<string,mixed> $item @return list<string> */
	private function timingWarningsFor(array $item):array { $created=strtotime((string)($item['created_at']??'')); if($created===false){return [];} $age=($this->clock)()-$created;$warnings=[];if($age>86400){$warnings[]='Session attribution is weakened after 24 hours.';}if($age>259200){$warnings[]='Event backdating is unavailable after 72 hours.';}return $warnings; }
	private static function revision(mixed $value):int {if(is_int($value)&&$value>=0){return $value;}if(is_string($value)&&preg_match('/\A(?:0|[1-9][0-9]*)\z/',$value)===1){$parsed=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);if(is_int($parsed)){return $parsed;}}throw new InvalidArgumentException('Review revision is invalid.');}
}
