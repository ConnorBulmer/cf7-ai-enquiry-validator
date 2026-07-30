<?php
/** Standalone authoritative calibration-run lifecycle regressions. */
declare(strict_types=1);

function calibration_run_assert(bool $condition,string $message):void{if(!$condition){throw new RuntimeException($message);}}
function add_action(string $hook,mixed $callback,int $priority=10,int $acceptedArgs=1):void{}
function add_filter(string $hook,mixed $callback,int $priority=10,int $acceptedArgs=1):void{}
function plugin_dir_url(string $file):string{return 'https://example.test/plugin/';}
function as_enqueue_async_action():int{return 1;}
function current_time(string $type,bool $gmt=false):string{return '2026-07-23 12:00:00';}
define('ABSPATH',__DIR__.'/');define('ARRAY_A','ARRAY_A');define('AUTH_KEY','run-auth');define('SECURE_AUTH_KEY','run-secure');
require_once dirname(__DIR__).'/cf7-ai-enquiry-validator.php';
calibration_run_assert(method_exists(CF7AIV\Admin\WpCalibrationRunStore::class,'whileActive'),'Calibration run storage must expose one atomic active-state guarded operation.');

final class MemoryCalibrationRuns implements CF7AIV\Admin\CalibrationRunStoreInterface {
	/** @var array<string,array<string,mixed>> */
	public array $runs=[];
	public bool $cancelAtGuard=false;
	public bool $cancelAfterGuard=false;
	public function create(string $runId,string $sourceKey,int $administratorId,array $criteria):bool{
		if(isset($this->runs[$runId])){return false;}
		$this->runs[$runId]=['state'=>'scheduling','source_key'=>$sourceKey,'administrator_id'=>$administratorId,'criteria'=>$criteria,'scheduled'=>0];
		return true;
	}
	public function activate(string $runId,int $scheduled):bool{
		if(($this->runs[$runId]['state']??null)!=='scheduling'){return false;}
		$this->runs[$runId]['state']='active';$this->runs[$runId]['scheduled']=$scheduled;return true;
	}
	public function cancel(string $runId):bool{
		if(!isset($this->runs[$runId])){return false;}
		$this->runs[$runId]['state']='cancelled';return true;
	}
	public function state(string $runId):?string{return isset($this->runs[$runId])?(string)$this->runs[$runId]['state']:null;}
	public function whileActive(string $runId,callable $operation):bool{
		if($this->cancelAtGuard){$this->cancel($runId);return false;}
		if($this->state($runId)!=='active'){return false;}
		$result=$operation()===true;
		if($this->cancelAfterGuard){$this->cancel($runId);}
		return $result;
	}
}

final class LifecycleSource implements CF7AIV\Contracts\SubmissionSourceInterface {
	public int $reads=0;
	public function __construct(private string $sourceKey='test'){}
	public function key():string{return $this->sourceKey;}
	public function get(string $reference):?array{return null;}
	public function iterate(array $arguments=[]):iterable{$this->reads++;yield ['reference'=>'1','form_id'=>10];}
	public function available():bool{return true;}
}

$runs=new MemoryCalibrationRuns();
$source=new LifecycleSource();
$persisted=[];$workerQueue=[];$deferred=[];
$backfill=new CF7AIV\Admin\BackfillService(
	static function(array $item)use(&$persisted):int{$persisted[]=$item;return count($persisted);},
	static function(string $hook,array $arguments)use(&$workerQueue):int{$workerQueue[]=compact('hook','arguments');return count($workerQueue);},
	static fn(string $runId):?string=>$runs->state($runId),
);
$worker=new CF7AIV\Admin\CalibrationBatchWorker(
	$runs,
	static fn():array=>['test'=>$source],
	$backfill,
	static function(string $hook,int $timestamp,array $arguments)use(&$deferred):int{$deferred[]=compact('hook','timestamp','arguments');return count($deferred);},
);

$queued=[];$results=[101,0];$unschedules=[];
$service=new CF7AIV\Admin\CalibrationService(
	static fn():array=>['test'=>$source],
	static fn():array=>[10],
	static function(string $hook,array $arguments)use(&$queued,&$results,$worker):int{
		$queued[]=compact('hook','arguments');
		if(count($queued)===1){$worker->process((string)$arguments[0],(array)$arguments[1],(int)$arguments[2]);}
		return (int)array_shift($results);
	},
	static fn():string=>'failed-run',
	static function(string $hook,array $arguments)use(&$unschedules):bool{$unschedules[]=compact('hook','arguments');return false;},
	$runs,
);
$threw=false;
try{$service->schedule('test',['explicit_opt_in'=>true,'form_ids'=>[10],'date_from'=>'2026-01-01','date_to'=>'2026-07-23','maximum'=>25],77);}catch(RuntimeException){$threw=true;}
calibration_run_assert($threw&&($runs->runs['failed-run']['state']??'')==='cancelled','Any partial enqueue failure must durably cancel the run before best-effort unscheduling.');
calibration_run_assert(count($deferred)===1&&$source->reads===0&&$persisted===[],'An action that runs while its run is scheduling must defer without reading its source or persisting an item.');
calibration_run_assert(count($unschedules)===1,'Already queued strata should still receive best-effort unscheduling after durable cancellation.');
$worker->process('test',(array)$queued[0]['arguments'][1],(int)$queued[0]['arguments'][2]);
calibration_run_assert($source->reads===0&&$persisted===[]&&$workerQueue===[],'A cancelled action must remain a no-op when already running, retried, or impossible to unschedule.');

$successfulSource=new LifecycleSource();
$successfulPersisted=[];$successfulQueue=[];$successfulQueued=[];
$successfulRuns=new MemoryCalibrationRuns();
$successfulBackfill=new CF7AIV\Admin\BackfillService(
	static function(array $item)use(&$successfulPersisted):int{$successfulPersisted[]=$item;return count($successfulPersisted);},
	static function(string $hook,array $arguments)use(&$successfulQueue):int{$successfulQueue[]=compact('hook','arguments');return count($successfulQueue);},
	static fn(string $runId):?string=>$successfulRuns->state($runId),
);
$successfulWorker=new CF7AIV\Admin\CalibrationBatchWorker($successfulRuns,static fn():array=>['test'=>$successfulSource],$successfulBackfill);
$successfulService=new CF7AIV\Admin\CalibrationService(
	static fn():array=>['test'=>$successfulSource],
	static fn():array=>[10],
	static function(string $hook,array $arguments)use(&$successfulQueued):int{$successfulQueued[]=compact('hook','arguments');return count($successfulQueued);},
	static fn():string=>'successful-run',
	null,
	$successfulRuns,
);
calibration_run_assert($successfulService->schedule('test',['explicit_opt_in'=>true,'form_ids'=>[10],'date_from'=>'2026-07-22','date_to'=>'2026-07-23','maximum'=>1],77)===1,'A fully queued run must activate successfully.');
calibration_run_assert($successfulRuns->state('successful-run')==='active','A run must become active only after every stratum is accepted by the queue.');
$successfulWorker->process('test',(array)$successfulQueued[0]['arguments'][1],(int)$successfulQueued[0]['arguments'][2]);
calibration_run_assert($successfulSource->reads===1&&count($successfulPersisted)===1&&($successfulPersisted[0]['calibration_run_id']??'')==='successful-run','An active run must read, persist its immutable run ID, and continue through the normal worker path.');
calibration_run_assert(($successfulQueue[0]['hook']??'')==='cf7aiv_classify_item','An active calibration item must still enqueue classification.');
calibration_run_assert(
	($successfulPersisted[0]['fingerprint']??'')===hash('sha256','history|successful-run|test|1'),
	'Each retry run must receive run-scoped item identity so an old cancelled action can never classify a later run item.',
);

$retryService=new CF7AIV\Admin\CalibrationService(
	static fn():array=>['test'=>$successfulSource],
	static fn():array=>[10],
	static fn(string $hook,array $arguments):int=>900,
	static fn():string=>'retry-run',
	null,
	$runs,
);
$retryService->schedule('test',['explicit_opt_in'=>true,'form_ids'=>[10],'date_from'=>'2026-07-22','date_to'=>'2026-07-23','maximum'=>1],77);
calibration_run_assert($runs->state('failed-run')==='cancelled'&&$runs->state('retry-run')==='active','An administrator retry must use a new run ID and can never reactivate the cancelled run.');

$raceRuns=new MemoryCalibrationRuns();
$raceRuns->create('race-run','test',77,[]);
$raceRuns->activate('race-run',1);
$raceRuns->cancelAtGuard=true;
$raceSource=new LifecycleSource();
$racePersisted=[];$raceQueue=[];
$raceWorker=new CF7AIV\Admin\BackfillService(
	static function(array $item)use(&$racePersisted):int{$racePersisted[]=$item;return count($racePersisted);},
	static function(string $hook,array $arguments)use(&$raceQueue):int{$raceQueue[]=compact('hook','arguments');return count($raceQueue);},
	$raceRuns,
);
$raceProcessed=$raceWorker->run($raceSource,['calibration_run_id'=>'race-run','quota'=>1],1);
calibration_run_assert($raceProcessed===0&&$racePersisted===[]&&$raceQueue===[]&&$raceRuns->state('race-run')==='cancelled','Cancellation that wins exactly at guarded item persistence must leave zero items and zero classification actions.');

$workerWinsRuns=new MemoryCalibrationRuns();$workerWinsRuns->create('worker-wins','test',77,[]);$workerWinsRuns->activate('worker-wins',1);$workerWinsRuns->cancelAfterGuard=true;
$workerWinsItems=[];$workerWinsQueue=[];
$workerWins=new CF7AIV\Admin\BackfillService(
	static function(array $item)use(&$workerWinsItems):int{$workerWinsItems[]=$item;return count($workerWinsItems);},
	static function(string $hook,array $arguments)use(&$workerWinsQueue):int{$workerWinsQueue[]=compact('hook','arguments');return 1;},
	$workerWinsRuns,
);
calibration_run_assert($workerWins->run(new LifecycleSource(),['calibration_run_id'=>'worker-wins','quota'=>1],1)===1&&count($workerWinsItems)===1&&$workerWinsQueue===[]&&$workerWinsRuns->state('worker-wins')==='cancelled','When the worker owns the guard first, cancellation linearizes after its completed item write and prevents later classification enqueue.');

final class GuardedRunWpdb {
	public string $prefix='wp_';
	public string $state='active';
	public array $records=[];
	public array $queries=[];
	public bool $failCommit=false;
	private ?array $snapshot=null;
	public function prepare(string $sql,mixed ...$arguments):string{foreach($arguments as $argument){$replacement=is_int($argument)?(string)$argument:"'".str_replace("'","''",(string)$argument)."'";$sql=preg_replace('/%[ds]/',$replacement,$sql,1)??$sql;}return $sql;}
	public function get_var(string $sql):mixed{$this->queries[]=$sql;return str_contains($sql,'SELECT state')?$this->state:null;}
	public function insert(string $table,array $data):int{return 1;}
	public function update(string $table,array $data,array $where):int{if(($where['state']??$this->state)!==$this->state){return 0;}$this->state=(string)($data['state']??$this->state);return 1;}
	public function query(string $sql):int|false{
		$this->queries[]=$sql;
		if($sql==='START TRANSACTION'){$this->snapshot=['state'=>$this->state,'records'=>$this->records];return 1;}
		if($sql==='COMMIT'){if($this->failCommit){return false;}$this->snapshot=null;return 1;}
		if($sql==='ROLLBACK'){if($this->snapshot!==null){$this->state=$this->snapshot['state'];$this->records=$this->snapshot['records'];}$this->snapshot=null;return 1;}
		if($sql==='INSERT LOCAL ITEM'){$this->records[]='item';return 1;}
		return 1;
	}
}

$guardDb=new GuardedRunWpdb();$GLOBALS['wpdb']=$guardDb;$guardStore=new CF7AIV\Admin\WpCalibrationRunStore();
calibration_run_assert($guardStore->whileActive('guard-run',static fn():bool=>$GLOBALS['wpdb']->query('INSERT LOCAL ITEM')===1)&&$guardDb->records===['item'],'An active guarded local write must commit while holding the run row lock.');
calibration_run_assert(str_contains(json_encode($guardDb->queries,JSON_THROW_ON_ERROR),'FOR UPDATE'),'Guarded operations must lock the authoritative run row.');
$rollbackDb=new GuardedRunWpdb();$rollbackDb->failCommit=true;$GLOBALS['wpdb']=$rollbackDb;$rollbackStore=new CF7AIV\Admin\WpCalibrationRunStore();
calibration_run_assert(!$rollbackStore->whileActive('rollback-run',static fn():bool=>$GLOBALS['wpdb']->query('INSERT LOCAL ITEM')===1)&&$rollbackDb->records===[],'A guarded-operation commit failure must roll back the local write.');
$cancelDb=new GuardedRunWpdb();$GLOBALS['wpdb']=$cancelDb;$cancelStore=new CF7AIV\Admin\WpCalibrationRunStore();
calibration_run_assert($cancelStore->cancel('cancel-run')&&$cancelDb->state==='cancelled'&&str_contains(json_encode($cancelDb->queries,JSON_THROW_ON_ERROR),'FOR UPDATE'),'Cancellation must lock the same authoritative run row before changing state.');
calibration_run_assert(!$cancelStore->whileActive('cancel-run',static fn():bool=>$GLOBALS['wpdb']->query('INSERT LOCAL ITEM')===1)&&$cancelDb->records===[],'A cancellation that owns the row first must prevent every later guarded write.');
$cancelRollbackDb=new GuardedRunWpdb();$cancelRollbackDb->failCommit=true;$GLOBALS['wpdb']=$cancelRollbackDb;$cancelRollbackStore=new CF7AIV\Admin\WpCalibrationRunStore();
calibration_run_assert(!$cancelRollbackStore->cancel('cancel-rollback')&&$cancelRollbackDb->state==='active','A cancellation commit failure must roll back to active instead of publishing an uncommitted cancelled state.');

echo "PASS CalibrationRunLifecycleTest\n";
