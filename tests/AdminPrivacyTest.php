<?php
/** Standalone Task 5 privacy exporter/eraser regression. */
declare(strict_types=1);
function privacy_admin_assert(bool $condition,string $message):void{if(!$condition){throw new RuntimeException($message);}}
$GLOBALS['privacy_actions']=[];$GLOBALS['privacy_filters']=[];
function add_action(string $hook,mixed $callback,int $priority=10,int $acceptedArgs=1):void{$GLOBALS['privacy_actions'][$hook][]=[$callback,$priority,$acceptedArgs];}
function add_filter(string $hook,mixed $callback,int $priority=10,int $acceptedArgs=1):void{$GLOBALS['privacy_filters'][$hook][]=[$callback,$priority,$acceptedArgs];}
function plugin_dir_url(string $file):string{return 'https://example.test/plugin/';}
function as_enqueue_async_action():int{return 1;}
function __(string $text,string $domain=''):string{return $text;}
define('ABSPATH',__DIR__.'/');define('ARRAY_A','ARRAY_A');define('AUTH_KEY','privacy-admin');define('SECURE_AUTH_KEY','privacy-secure');
final class AdminPrivacyWpdb{
	public string $prefix='wp_';public array $queries=[];public array $deleted=[];
	public function prepare(string $sql,mixed ...$args):string{$this->queries[]=['prepare',$sql,$args];return $sql;}
	public function get_results(string $sql,mixed $format=null):array{$this->queries[]=['results',$sql];return [
		['id'=>5,'tier_id'=>'high_value_care','outcome_id'=>'primary_opportunity','decision_state'=>'strong_match','predicted_tier_id'=>'private-prediction','predicted_outcome_id'=>'private-predicted-outcome','reviewed_tier_id'=>'private-review-truth','reviewed_outcome_id'=>'private-reviewed-outcome','reason_code'=>'qualified','confidence'=>'0.9600','evidence_json'=>'[{"code":"service_fit","field_key":"message","excerpt":"private@example.test source corpus"},{"code":"specific_need","field_key":"project_type","provenance":"private-page"}]','policy_version'=>'care-v1','state'=>'classified','review_state'=>'approved_dispatch','created_at'=>'2026-07-22 10:00:00','email_hash'=>'must-not-export','summary'=>'must-not-export','model_response'=>'must-not-export','raw_identity'=>'must-not-export','attribution_encrypted'=>'must-not-export','source_text'=>'must-not-export','suggestion_provenance'=>'must-not-export'],
		['id'=>6,'tier_id'=>'care_information','outcome_id'=>'secondary_opportunity','decision_state'=>'needs_review','reason_code'=>'ambiguous','confidence'=>null,'evidence_json'=>'[]','policy_version'=>'care-v1','state'=>'classified','review_state'=>'review','created_at'=>'2026-07-22 10:01:00'],
	];}
	public function get_col(string $sql):array{$this->queries[]=['col',$sql];return [5,8];}
	public function query(string $sql):int{$this->queries[]=['query',$sql];return 2;}
	public function delete(string $table,array $where,array $format=[]):int{$this->deleted[]=compact('table','where');return 2;}
}
$GLOBALS['wpdb']=new AdminPrivacyWpdb();
require_once dirname(__DIR__).'/cf7-ai-enquiry-validator.php';
$export=CF7AIV\Infrastructure\Privacy::export('Person@Example.test');
privacy_admin_assert(count($export['data'])===2&&$export['done'],'Exporter must return matched plugin metadata when a consented email hash exists.');
$serialized=json_encode($export,JSON_THROW_ON_ERROR);
foreach(['must-not-export','private-prediction','private-predicted-outcome','private-review-truth','private-reviewed-outcome','private@example.test','source corpus','private-page','email_hash','summary','model_response','raw_identity','attribution_encrypted','source_text','suggestion_provenance','source_reference','excerpt','provenance'] as $forbidden){privacy_admin_assert(!str_contains($serialized,$forbidden),"Exporter must omit sensitive/internal field {$forbidden}.");}
foreach(['Outcome','Tier','Decision','Reason','AI diagnostic confidence','Evidence codes','Policy version','Processing state','Review state','Captured at','primary_opportunity','strong_match','service_fit','message','specific_need','project_type'] as $required){privacy_admin_assert(str_contains($serialized,$required),"Exporter must include safe diagnostic metadata {$required}.");}
privacy_admin_assert(substr_count($serialized,'AI diagnostic confidence')===1,'AI diagnostic confidence must be exported only for records where it is present.');
$erased=CF7AIV\Infrastructure\Privacy::erase('Person@Example.test');
$queries=json_encode($GLOBALS['wpdb']->queries,JSON_THROW_ON_ERROR);
privacy_admin_assert($erased['items_removed']&&!$erased['items_retained'],'Eraser must report matched plugin records removed.');
privacy_admin_assert(str_contains($queries,'cf7aiv_review_suggestions')&&str_contains($queries,'cf7aiv_deliveries')&&strpos($queries,'cf7aiv_review_suggestions')<strpos($queries,'cf7aiv_deliveries'),'Eraser must remove associated review suggestions before deliveries and plugin items.');
privacy_admin_assert(!str_contains($queries,'flamingo_inbound_messages')&&!str_contains($queries,'db7_forms'),'Eraser must never touch source enquiries.');
$GLOBALS['wpdb']->queries=[];
$removed=(new CF7AIV\Admin\RetentionService())->cleanup(90);
$retentionQueries=json_encode($GLOBALS['wpdb']->queries,JSON_THROW_ON_ERROR);
privacy_admin_assert($removed>=0&&str_contains($retentionQueries,'DELETE FROM wp_cf7aiv_setup_suggestions WHERE created_at < %s'),'Retention must remove setup suggestions according to their own created_at age.');
privacy_admin_assert(strpos($retentionQueries,'cf7aiv_review_suggestions')<strpos($retentionQueries,'cf7aiv_deliveries')&&strpos($retentionQueries,'cf7aiv_deliveries')<strpos($retentionQueries,'cf7aiv_items WHERE created_at'),'Retention must remove dependent review and delivery rows before their items.');
echo "PASS AdminPrivacyTest\n";
