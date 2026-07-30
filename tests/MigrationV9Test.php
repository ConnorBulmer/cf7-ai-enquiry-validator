<?php
/** Standalone schema-v9 post-dbDelta verification regressions. */
declare(strict_types=1);

function migration_v9_assert(bool $condition,string $message):void{if(!$condition){throw new RuntimeException($message);}}
ini_set('error_log','NUL');
define('ABSPATH',__DIR__.'/');define('ARRAY_A','ARRAY_A');
$GLOBALS['migration_v9_options']=[];$GLOBALS['migration_v9_dbdelta']=[];
function dbDelta(string $sql):void{$GLOBALS['migration_v9_dbdelta'][]=$sql;}
function update_option(string $name,mixed $value,bool $autoload=false):void{$GLOBALS['migration_v9_options'][$name]=$value;}

final class MigrationV9Wpdb {
	public string $prefix='wp_';
	public array $queries=[];
	public string $missingRunColumn='';
	/** @param list<string> $itemColumns @param list<string> $auditColumns @param list<array<string,mixed>> $itemIndexes @param list<array<string,mixed>> $auditIndexes */
	public function __construct(public array $itemColumns,public array $auditColumns,public array $itemIndexes,public array $auditIndexes,public bool $runSchema=true){}
	public function query(string $sql):int{$this->queries[]=$sql;return 1;}
	public function get_results(string $sql,mixed $format=null):array{
		$this->queries[]=$sql;
		if(str_contains($sql,'SHOW COLUMNS FROM wp_cf7aiv_calibration_runs')){
			$columns=['run_id','source_key','state','administrator_id','criteria_hash','scheduled_quota','created_at','updated_at','activated_at','cancelled_at'];
			if($this->missingRunColumn!==''){$columns=array_values(array_filter($columns,fn(string $column):bool=>$column!==$this->missingRunColumn));}
			return $this->runSchema?array_map(static fn(string $field):array=>['Field'=>$field],$columns):[];
		}
		if(str_contains($sql,'SHOW INDEX FROM wp_cf7aiv_calibration_runs')){return $this->runSchema?[migration_v9_index('PRIMARY','run_id')]:[];}
		if(str_contains($sql,'SHOW COLUMNS FROM wp_cf7aiv_items')){return array_map(static fn(string $field):array=>['Field'=>$field],$this->itemColumns);}
		if(str_contains($sql,'SHOW COLUMNS FROM wp_cf7aiv_review_suggestions')){return array_map(static fn(string $field):array=>['Field'=>$field],$this->auditColumns);}
		if(str_contains($sql,'SHOW INDEX FROM wp_cf7aiv_items')){return $this->itemIndexes;}
		if(str_contains($sql,'SHOW INDEX FROM wp_cf7aiv_review_suggestions')){return $this->auditIndexes;}
		return [];
	}
}

function migration_v9_index(string $name,string $column,int $nonUnique=0,int $sequence=1):array{
	return ['Key_name'=>$name,'Non_unique'=>$nonUnique,'Column_name'=>$column,'Seq_in_index'=>$sequence];
}
function migration_v9_db(array $itemColumns,array $auditColumns,array $itemDispatchIndex=[],array $auditDispatchIndex=[]):MigrationV9Wpdb{
	return new MigrationV9Wpdb(
		$itemColumns,
		$auditColumns,
		array_merge([migration_v9_index('fingerprint','fingerprint')],$itemDispatchIndex),
		$auditDispatchIndex,
	);
}

require_once dirname(__DIR__).'/cf7-ai-enquiry-validator.php';

$GLOBALS['wpdb']=migration_v9_db([],[],[],[]);
CF7AIV\Infrastructure\Migration::migrate();
migration_v9_assert(!isset($GLOBALS['migration_v9_options']['cf7aiv_schema_version']),'A no-op dbDelta must not record schema version 9 when approval columns and indexes are absent.');

$GLOBALS['migration_v9_options']=[];
$GLOBALS['wpdb']=migration_v9_db(
	['dispatch_approval_id','review_revision','calibration_run_id'],
	['dispatch_approval_id','reviewed_outcome_id'],
	[migration_v9_index('dispatch_approval_id','dispatch_approval_id')],
	[migration_v9_index('dispatch_approval_id','dispatch_approval_id')],
);
$GLOBALS['wpdb']->runSchema=false;
CF7AIV\Infrastructure\Migration::migrate();
migration_v9_assert(!isset($GLOBALS['migration_v9_options']['cf7aiv_schema_version']),'Migration must not record version 10 when the calibration run table is absent after dbDelta.');

foreach(['activated_at','cancelled_at'] as $missingRunColumn){
	$GLOBALS['migration_v9_options']=[];
	$GLOBALS['wpdb']=migration_v9_db(
		['dispatch_approval_id','review_revision','calibration_run_id'],
		['dispatch_approval_id','reviewed_outcome_id'],
		[migration_v9_index('dispatch_approval_id','dispatch_approval_id')],
		[migration_v9_index('dispatch_approval_id','dispatch_approval_id')],
	);
	$GLOBALS['wpdb']->missingRunColumn=$missingRunColumn;
	CF7AIV\Infrastructure\Migration::migrate();
	migration_v9_assert(!isset($GLOBALS['migration_v9_options']['cf7aiv_schema_version']),"Migration must not record version 10 when {$missingRunColumn} is absent after dbDelta.");
}

$GLOBALS['migration_v9_options']=[];
$GLOBALS['wpdb']=migration_v9_db(
	['dispatch_approval_id'],
	['dispatch_approval_id','reviewed_outcome_id'],
	[migration_v9_index('dispatch_approval_id','dispatch_approval_id')],
	[
		migration_v9_index('dispatch_approval_id','dispatch_approval_id',0,1),
		migration_v9_index('dispatch_approval_id','item_id',0,2),
	],
);
CF7AIV\Infrastructure\Migration::migrate();
migration_v9_assert(!isset($GLOBALS['migration_v9_options']['cf7aiv_schema_version']),'A partial dbDelta with a composite audit approval index must not record schema version 9.');

$GLOBALS['migration_v9_options']=[];
$GLOBALS['wpdb']=migration_v9_db(
	['dispatch_approval_id','review_revision','calibration_run_id'],
	['dispatch_approval_id','reviewed_outcome_id'],
	[migration_v9_index('dispatch_approval_id','dispatch_approval_id')],
	[migration_v9_index('dispatch_approval_id','dispatch_approval_id')],
);
CF7AIV\Infrastructure\Migration::migrate();
migration_v9_assert(($GLOBALS['migration_v9_options']['cf7aiv_schema_version']??null)==='10','Migration must record version 10 only after every approval column, review revision, and both single-column unique indexes are verified.');

echo "PASS MigrationV9Test\n";
