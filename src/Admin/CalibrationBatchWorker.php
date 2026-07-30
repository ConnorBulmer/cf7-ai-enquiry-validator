<?php
declare(strict_types=1);
namespace CF7AIV\Admin;

use CF7AIV\Contracts\SubmissionSourceInterface;
use CF7AIV\Infrastructure\Queue;

final class CalibrationBatchWorker {
	private const MAX_ACTIVATION_WAITS=5;
	private $sources;
	private $defer;
	private BackfillService $backfill;

	public function __construct(
		private readonly CalibrationRunStoreInterface $runs,
		?callable $sources=null,
		?BackfillService $backfill=null,
		?callable $defer=null,
	){
		$this->sources=$sources??static fn():array=>function_exists('apply_filters')?(array)apply_filters('cf7aiv_submission_sources',[]):[];
		$this->defer=$defer??static fn(string $hook,int $timestamp,array $arguments):int|bool=>Queue::schedule($hook,$timestamp,$arguments);
		$this->backfill=$backfill??new BackfillService(null,null,$this->runs);
	}

	/** @param array<string,mixed> $arguments */
	public function process(string $sourceKey,array $arguments=[],int $batchSize=25):int {
		$runId=(string)($arguments['calibration_run_id']??'');
		$state=$this->runs->state($runId);
		if($state==='scheduling'){
			$waits=max(0,(int)($arguments['_calibration_activation_waits']??0));
			if($waits>=self::MAX_ACTIVATION_WAITS){$this->runs->cancel($runId);return 0;}
			$arguments['_calibration_activation_waits']=$waits+1;
			$result=($this->defer)('cf7aiv_backfill_batch',time()+15,[$sourceKey,$arguments,$batchSize]);
			if(!self::queued($result)){$this->runs->cancel($runId);}
			return 0;
		}
		if($state!=='active'){return 0;}
		$sources=($this->sources)();
		$source=is_array($sources)?($sources[$sourceKey]??null):null;
		if(!$source instanceof SubmissionSourceInterface){return 0;}
		return $this->backfill->run($source,$arguments,$batchSize);
	}

	private static function queued(mixed $result):bool{return $result===true||(is_int($result)&&$result>0);}
}
