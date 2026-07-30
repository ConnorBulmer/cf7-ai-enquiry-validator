<?php
declare(strict_types=1);
namespace CF7AIV\Admin;
use CF7AIV\Contracts\SubmissionSourceInterface;
use CF7AIV\Infrastructure\Queue;
use CF7AIV\Intake\ItemRepository;
final class BackfillService {
	private const MAX_PER_ACTION = 100;
	private $persist;private $enqueue;private $runState;private $whileActive;
	public function __construct(?callable $persist=null,?callable $enqueue=null,CalibrationRunStoreInterface|callable|null $runAuthority=null){$repo=new ItemRepository();$this->persist=$persist??static fn(array $item):int=>$repo->persist($item);$this->enqueue=$enqueue??static fn(string $hook,array $args):mixed=>Queue::enqueue($hook,$args);if($runAuthority instanceof CalibrationRunStoreInterface){$this->runState=fn(string $runId):?string=>$runAuthority->state($runId);$this->whileActive=fn(string $runId,callable $operation):bool=>$runAuthority->whileActive($runId,$operation);}else{$this->runState=$runAuthority??static fn(string $runId):string=>'active';$this->whileActive=fn(string $runId,callable $operation):bool=>($this->runState)($runId)==='active'&&$operation()===true;}}
	/** @param array<string,mixed> $arguments */
	public function run(SubmissionSourceInterface $source,array $arguments=[],int $batchSize=25):int{
		$runId=(string)($arguments['calibration_run_id']??'');$stratumQuota=$arguments['quota']??null;$completed=$arguments['completed']??0;
		if($runId===''||strlen($runId)>128||preg_match('/\A[A-Za-z0-9-]+\z/',$runId)!==1||!is_int($stratumQuota)||$stratumQuota<1||$stratumQuota>500||!is_int($completed)||$completed<0||$completed>=$stratumQuota){return 0;}
		if(($this->runState)($runId)!=='active'){return 0;}
		$remainingLimit=min(max(0,$batchSize),$stratumQuota-$completed);if($remainingLimit===0){return 0;}
		$successful=0;$scanned=0;$lastReference=max(0,(int)($arguments['after_id']??0));$hasMore=false;
		$now=function_exists('current_time')?current_time('mysql',true):gmdate('Y-m-d H:i:s');$query=$arguments;$query['limit']=self::MAX_PER_ACTION+1;
		foreach($source->iterate($query) as $record){
			if(($this->runState)($runId)!=='active'){break;}
			if($scanned>=self::MAX_PER_ACTION){$hasMore=true;break;}$scanned++;
			if(!is_array($record)){continue;}$reference=substr((string)($record['reference']??$record['id']??''),0,191);if($reference===''){continue;}
			$numericReference=filter_var($reference,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($numericReference!==false){$lastReference=max($lastReference,(int)$numericReference);}
			$item=['fingerprint'=>hash('sha256','history|'.$runId.'|'.$source->key().'|'.$reference),'source_type'=>$source->key(),'source_reference'=>$reference,'form_id'=>(int)($record['form_id']??0),'calibration_run_id'=>$runId,'attribution_encrypted'=>null,'consent_encrypted'=>null,'attribution_ready'=>false,'email_hash'=>null,'phone_hash'=>null,'is_calibration'=>true,'is_historical'=>true,'created_at'=>$now,'updated_at'=>$now];
			if(($this->runState)($runId)!=='active'){break;}
			$itemId=0;$stored=($this->whileActive)($runId,function()use(&$itemId,$item):bool{$itemId=(int)($this->persist)($item);return $itemId>0;});
			if(!$stored){if(($this->runState)($runId)!=='active'){break;}continue;}
			$whitelist=\CF7AIV\Classification\ContextBuilder::safeWhitelist(is_array($arguments['ai_whitelist_snapshot']??null)?$arguments['ai_whitelist_snapshot']:[]);
			($this->whileActive)($runId,function()use($itemId,$whitelist):bool{($this->enqueue)('cf7aiv_classify_item',[$itemId,false,$whitelist]);return true;});
			$successful++;if($successful>=$remainingLimit){break;}
		}
		$remaining=$remainingLimit-$successful;if($remaining>0&&$hasMore&&($this->runState)($runId)==='active'){$next=$arguments;$next['completed']=$completed+$successful;$next['after_id']=$lastReference;if($lastReference>0){$next['offset']=0;}else{$next['offset']=max(0,(int)($arguments['offset']??0))+$scanned;}($this->whileActive)($runId,function()use($source,$next,$remaining):bool{($this->enqueue)('cf7aiv_backfill_batch',[$source->key(),$next,$remaining]);return true;});}
		return $successful;
	}
}
