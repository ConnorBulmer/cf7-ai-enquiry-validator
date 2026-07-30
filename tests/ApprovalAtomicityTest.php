<?php
/** Standalone atomic manual approval and replay regressions. */
declare(strict_types=1);

function approval_assert(bool $condition,string $message):void{if(!$condition){throw new RuntimeException($message);}}
function add_action(string $hook,mixed $callback,int $priority=10,int $acceptedArgs=1):void{}
function add_filter(string $hook,mixed $callback,int $priority=10,int $acceptedArgs=1):void{}
function plugin_dir_url(string $file):string{return 'https://example.test/plugin/';}
function as_enqueue_async_action():int{return 1;}
function current_time(string $type,bool $gmt=false):string{return '2026-07-22 12:00:00';}
define('ABSPATH',__DIR__.'/');define('ARRAY_A','ARRAY_A');define('AUTH_KEY','approval-auth');define('SECURE_AUTH_KEY','approval-secure');
require_once dirname(__DIR__).'/cf7-ai-enquiry-validator.php';

final class ApprovalAtomicWpdb {
	public string $prefix='wp_';
	public int $rows_affected=0;
	public int $insert_id=0;
	public array $queries=[];
	public array $audits=[];
	public bool $failStart=false;
	public bool $failUpdate=false;
	public bool $failInsert=false;
	public bool $failCommit=false;
	public array $lockedOverrides=[];
	private ?array $snapshot=null;

	/** @param array<int,array<string,mixed>> $items */
	public function __construct(public array $items){}

	public function prepare(string $sql,mixed ...$args):string{
		foreach($args as $arg){
			$replacement=is_int($arg)?(string)$arg:"'".str_replace("'","''",(string)$arg)."'";
			$sql=preg_replace('/%[ds]/',$replacement,$sql,1)??$sql;
		}
		return $sql;
	}

	public function query(string $sql):int|false{
		$this->queries[]=$sql;
		if($sql==='START TRANSACTION'){
			if($this->failStart){return false;}
			$this->snapshot=['items'=>$this->items,'audits'=>$this->audits];
			return 1;
		}
		if($sql==='COMMIT'){
			if($this->failCommit){return false;}
			$this->snapshot=null;
			return 1;
		}
		if($sql==='ROLLBACK'){
			if($this->snapshot!==null){$this->items=$this->snapshot['items'];$this->audits=$this->snapshot['audits'];}
			$this->snapshot=null;
			return 1;
		}
		return 1;
	}

	public function get_row(string $sql,mixed $format=null):?array{
		$this->queries[]=$sql;
		if(str_contains($sql,'cf7aiv_items')){
			preg_match('/WHERE id=([0-9]+)/',$sql,$match);
			$id=(int)($match[1]??0);
			if(str_contains($sql,'FOR UPDATE')&&isset($this->lockedOverrides[$id])){$this->items[$id]=$this->lockedOverrides[$id];if($this->snapshot!==null){$this->snapshot['items'][$id]=$this->lockedOverrides[$id];}}
			return $this->items[$id]??null;
		}
		return null;
	}

	public function get_var(string $sql):mixed{
		$this->queries[]=$sql;
		if(!str_contains($sql,'cf7aiv_review_suggestions')){return null;}
		preg_match("/dispatch_approval_id='([a-f0-9]{64})'/",$sql,$match);
		$id=$match[1]??'';
		foreach($this->audits as $audit){
			if(($audit['dispatch_approval_id']??'')!==$id){continue;}
			foreach([
				'item_id'=>'/item_id=([0-9]+)/',
				'administrator_id'=>'/administrator_id=([0-9]+)/',
				'corrected_tier'=>"/corrected_tier='([^']+)'/",
				'reviewed_outcome_id'=>"/reviewed_outcome_id='([^']+)'/",
			] as $field=>$pattern){
				preg_match($pattern,$sql,$expected);
				if((string)($audit[$field]??'')!==(string)($expected[1]??'')){continue 2;}
			}
			if(($audit['action']??'')==='approve_dispatch'&&($audit['status']??'')==='accepted'){return $id;}
		}
		return null;
	}

	public function update(string $table,array $data,array $where):int|false{
		$this->queries[]=['update',$table,$data,$where];
		if($this->failUpdate){return false;}
		$id=(int)($where['id']??0);
		if(!isset($this->items[$id])){return 0;}
		if(array_key_exists('dispatch_approval_id',$where)&&($this->items[$id]['dispatch_approval_id']??null)!==$where['dispatch_approval_id']){return 0;}
		$this->items[$id]=array_merge($this->items[$id],$data);
		$this->rows_affected=1;
		return 1;
	}

	public function insert(string $table,array $data):int|false{
		$this->queries[]=['insert',$table,$data];
		if($this->failInsert){return false;}
		foreach($this->audits as $audit){if(($audit['dispatch_approval_id']??null)===($data['dispatch_approval_id']??null)){return false;}}
		$this->insert_id=count($this->audits)+1;
		$this->audits[]=$data+['id'=>$this->insert_id];
		return 1;
	}

	public function delete(string $table,array $where,array $format=[]):int{
		$this->queries[]=['delete',$table,$where];
		$before=count($this->audits);
		$this->audits=array_values(array_filter($this->audits,static function(array $audit)use($where):bool{
			foreach($where as $key=>$value){if(($audit[$key]??null)!==$value){return true;}}
			return false;
		}));
		return $before-count($this->audits);
	}
}

function approval_item(int $id):array{
	return [
		'id'=>$id,'tier_id'=>'high_value_care','outcome_id'=>'primary_opportunity',
		'predicted_tier_id'=>'high_value_care','predicted_outcome_id'=>'primary_opportunity',
		'review_state'=>'review','reviewed_tier_id'=>null,'reviewed_outcome_id'=>null,
		'reviewed_by'=>null,'reviewed_at'=>null,'review_revision'=>0,'dispatch_approval_id'=>null,
		'is_shadow'=>0,'is_calibration'=>0,'is_historical'=>0,'created_at'=>'2026-07-22 10:00:00','updated_at'=>'2026-07-22 10:00:00',
	];
}

approval_assert(method_exists(CF7AIV\Admin\WpReviewStore::class,'approveDispatch'),'Review store must expose one atomic approve-and-dispatch operation.');

$GLOBALS['wpdb']=new ApprovalAtomicWpdb([1=>approval_item(1),2=>approval_item(2)]);
$store=new CF7AIV\Admin\WpReviewStore();
approval_assert($store->approveDispatch(1,77,'high_value_care','primary_opportunity',0),'First authorized approval must commit.');
$firstId=(string)($GLOBALS['wpdb']->items[1]['dispatch_approval_id']??'');
approval_assert(preg_match('/^[a-f0-9]{64}$/',$firstId)===1,'Approval ID must be a cryptographically strong 256-bit identifier.');
approval_assert(($GLOBALS['wpdb']->items[1]['review_state']??'')==='approved_dispatch'&&($GLOBALS['wpdb']->items[1]['review_revision']??-1)===1&&count($GLOBALS['wpdb']->audits)===1&&($GLOBALS['wpdb']->audits[0]['dispatch_approval_id']??'')===$firstId,'Item approval and matching audit must commit together while advancing the review revision.');
approval_assert($store->approveDispatch(1,77,'high_value_care','primary_opportunity',1),'An authorized replay must succeed idempotently.');
approval_assert(count($GLOBALS['wpdb']->audits)===1&&($GLOBALS['wpdb']->items[1]['dispatch_approval_id']??'')===$firstId,'Replay must retain the immutable approval ID and never create a duplicate audit.');
approval_assert(($GLOBALS['wpdb']->items[1]['review_revision']??-1)===1,'An idempotent replay must not create a new review mutation.');
approval_assert($store->approveDispatch(2,77,'high_value_care','primary_opportunity',0),'A second item approved in the same second must commit.');
$secondId=(string)($GLOBALS['wpdb']->items[2]['dispatch_approval_id']??'');
approval_assert($secondId!==$firstId&&preg_match('/^[a-f0-9]{64}$/',$secondId)===1,'Same-second approvals must receive distinct immutable IDs.');
$queryText=json_encode($GLOBALS['wpdb']->queries,JSON_THROW_ON_ERROR);
approval_assert(substr_count($queryText,'START TRANSACTION')===3&&substr_count($queryText,'FOR UPDATE')===3,'Every new or replayed approval must lock the item inside its own transaction.');

foreach(['failStart','failUpdate','failInsert','failCommit'] as $failure){
	$db=new ApprovalAtomicWpdb([9=>approval_item(9)]);
	$db->{$failure}=true;
	$GLOBALS['wpdb']=$db;
	$failedStore=new CF7AIV\Admin\WpReviewStore();
	$result=$failedStore->approveDispatch(9,77,'high_value_care','primary_opportunity',0);
	approval_assert(!$result,"{$failure} must fail closed.");
	approval_assert($db->items[9]===approval_item(9)&&$db->audits===[],"{$failure} must leave neither approved item state nor an audit.");
}

$orphaned=approval_item(10);
$orphaned=array_merge($orphaned,[
	'review_state'=>'approved_dispatch',
	'reviewed_by'=>77,
	'reviewed_at'=>'2026-07-22 12:00:00',
	'reviewed_tier_id'=>'high_value_care',
	'reviewed_outcome_id'=>'primary_opportunity',
	'review_revision'=>1,
	'dispatch_approval_id'=>str_repeat('d',64),
]);
$orphanDb=new ApprovalAtomicWpdb([10=>$orphaned]);
$GLOBALS['wpdb']=$orphanDb;
approval_assert(!(new CF7AIV\Admin\WpReviewStore())->approveDispatch(10,77,'high_value_care','primary_opportunity',1),'An approved item whose immutable audit is missing must fail closed.');
approval_assert(($orphanDb->items[10]['review_state']??'')!=='approved_dispatch'&&($orphanDb->items[10]['dispatch_approval_id']??null)===null,'A missing approval audit must not leave an item in dispatch-approved state.');

$staleDb=new ApprovalAtomicWpdb([11=>approval_item(11)]);
$concurrentTruth=array_merge(approval_item(11),[
	'tier_id'=>'concurrent_tier',
	'outcome_id'=>'secondary_opportunity',
	'reviewed_tier_id'=>'concurrent_tier',
	'reviewed_outcome_id'=>'secondary_opportunity',
	'reviewed_by'=>88,
	'reviewed_at'=>'2026-07-22 12:00:00',
	'review_state'=>'corrected',
]);
$staleDb->lockedOverrides[11]=$concurrentTruth;
$GLOBALS['wpdb']=$staleDb;$staleDispatches=[];
$staleDecision=(new CF7AIV\Admin\ReviewService(
	new CF7AIV\Admin\WpReviewStore(),
	static fn():array=>['automation'=>['mode'=>'live']],
	static function(int $itemId)use(&$staleDispatches):int{$staleDispatches[]=$itemId;return count($staleDispatches);},
))->apply(11,'approve_dispatch',[],77);
approval_assert(!$staleDecision->dispatched&&$staleDb->audits===[]&&$staleDispatches===[],'Approval must refuse when reviewer truth changes between the service read and locked transaction read.');
approval_assert(($staleDb->items[11]['tier_id']??'')==='concurrent_tier'&&($staleDb->items[11]['reviewed_tier_id']??'')==='concurrent_tier','A stale approval must never overwrite concurrently corrected reviewer truth.');
approval_assert($staleDecision->state==='corrected','A refused stale approval must report the current locked review state, not approved_dispatch.');

$sameTupleDb=new ApprovalAtomicWpdb([12=>approval_item(12)]);
$sameTupleDb->lockedOverrides[12]=array_merge(approval_item(12),[
	'review_state'=>'rejected',
	'reviewed_by'=>88,
	'reviewed_at'=>'2026-07-22 12:00:00',
	'review_revision'=>1,
]);
$GLOBALS['wpdb']=$sameTupleDb;$sameTupleDispatches=[];
$sameTupleDecision=(new CF7AIV\Admin\ReviewService(
	new CF7AIV\Admin\WpReviewStore(),
	static fn():array=>['automation'=>['mode'=>'live']],
	static function(int $itemId)use(&$sameTupleDispatches):int{$sameTupleDispatches[]=$itemId;return count($sameTupleDispatches);},
))->apply(12,'approve_dispatch',[],77);
approval_assert(!$sameTupleDecision->dispatched&&$sameTupleDb->audits===[]&&$sameTupleDispatches===[],'A stale approval must refuse a newer locked review revision even when tier and outcome are unchanged.');
approval_assert(($sameTupleDb->items[12]['review_state']??'')==='rejected'&&($sameTupleDb->items[12]['review_revision']??0)===1,'A stale same-tuple approval must preserve the concurrent review mutation.');

$GLOBALS['wpdb']=new ApprovalAtomicWpdb([20=>approval_item(20)]);
$dispatches=[];
$service=new CF7AIV\Admin\ReviewService(
	new CF7AIV\Admin\WpReviewStore(),
	static fn():array=>['automation'=>['mode'=>'live']],
	static function(int $itemId)use(&$dispatches):int{$dispatches[]=$itemId;return count($dispatches);},
);
$first=$service->apply(20,'approve_dispatch',[],77);
$replay=$service->apply(20,'approve_dispatch',[],77);
approval_assert($first->dispatched&&$replay->dispatched&&$dispatches===[20,20]&&count($GLOBALS['wpdb']->audits)===1,'Service replay must keep one audit while re-enqueueing the idempotent delivery worker so retries are not stranded.');

echo "PASS ApprovalAtomicityTest\n";
