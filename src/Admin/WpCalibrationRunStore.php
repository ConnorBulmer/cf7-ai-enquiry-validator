<?php
declare(strict_types=1);
namespace CF7AIV\Admin;

final class WpCalibrationRunStore implements CalibrationRunStoreInterface {
	/** @var array<string,array<string,mixed>> */
	private static array $fallback=[];

	public function create(string $runId,string $sourceKey,int $administratorId,array $criteria):bool {
		if(!self::validId($runId)||$sourceKey===''||strlen($sourceKey)>32){return false;}
		global $wpdb;
		$now=function_exists('current_time')?current_time('mysql',true):gmdate('Y-m-d H:i:s');
		if(!$this->usable($wpdb)){
			if(isset(self::$fallback[$runId])){return false;}
			self::$fallback[$runId]=['state'=>'scheduling','source_key'=>$sourceKey,'scheduled_quota'=>0];
			return true;
		}
		$json=function_exists('wp_json_encode')?wp_json_encode($criteria):json_encode($criteria);
		return $wpdb->insert($wpdb->prefix.'cf7aiv_calibration_runs',[
			'run_id'=>$runId,'source_key'=>$sourceKey,'state'=>'scheduling',
			'administrator_id'=>max(0,$administratorId),'criteria_hash'=>hash('sha256',is_string($json)?$json:''),
			'scheduled_quota'=>0,'created_at'=>$now,'updated_at'=>$now,
		])===1;
	}

	public function activate(string $runId,int $scheduled):bool {
		if(!self::validId($runId)||$scheduled<1){return false;}
		global $wpdb;
		$now=function_exists('current_time')?current_time('mysql',true):gmdate('Y-m-d H:i:s');
		if(!$this->usable($wpdb)){
			if((self::$fallback[$runId]['state']??null)!=='scheduling'){return false;}
			self::$fallback[$runId]['state']='active';self::$fallback[$runId]['scheduled_quota']=$scheduled;return true;
		}
		return $wpdb->update(
			$wpdb->prefix.'cf7aiv_calibration_runs',
			['state'=>'active','scheduled_quota'=>$scheduled,'activated_at'=>$now,'updated_at'=>$now],
			['run_id'=>$runId,'state'=>'scheduling']
		)===1;
	}

	public function cancel(string $runId):bool {
		if(!self::validId($runId)){return false;}
		global $wpdb;
		$now=function_exists('current_time')?current_time('mysql',true):gmdate('Y-m-d H:i:s');
		if(!$this->usable($wpdb)){
			if(!isset(self::$fallback[$runId])){return false;}
			self::$fallback[$runId]['state']='cancelled';return true;
		}
		if($wpdb->query('START TRANSACTION')===false){return false;}
		$table=$wpdb->prefix.'cf7aiv_calibration_runs';
		$state=$wpdb->get_var($wpdb->prepare("SELECT state FROM {$table} WHERE run_id=%s FOR UPDATE",$runId));
		if(!in_array($state,['scheduling','active'],true)){$wpdb->query('ROLLBACK');return false;}
		$updated=$wpdb->update($table,['state'=>'cancelled','cancelled_at'=>$now,'updated_at'=>$now],['run_id'=>$runId,'state'=>$state]);
		if($updated!==1||$wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return false;}
		return true;
	}

	public function state(string $runId):?string {
		if(!self::validId($runId)){return null;}
		global $wpdb;
		if(!$this->usable($wpdb)){return isset(self::$fallback[$runId])?(string)self::$fallback[$runId]['state']:null;}
		$value=$wpdb->get_var($wpdb->prepare("SELECT state FROM {$wpdb->prefix}cf7aiv_calibration_runs WHERE run_id=%s LIMIT 1",$runId));
		return is_string($value)&&in_array($value,['scheduling','active','cancelled','complete'],true)?$value:null;
	}

	public function whileActive(string $runId,callable $operation):bool {
		if(!self::validId($runId)){return false;}
		global $wpdb;
		if(!$this->usable($wpdb)){return (self::$fallback[$runId]['state']??null)==='active'&&$operation()===true;}
		if($wpdb->query('START TRANSACTION')===false){return false;}
		$table=$wpdb->prefix.'cf7aiv_calibration_runs';
		$state=$wpdb->get_var($wpdb->prepare("SELECT state FROM {$table} WHERE run_id=%s FOR UPDATE",$runId));
		if($state!=='active'){$wpdb->query('ROLLBACK');return false;}
		try{
			if($operation()!==true){$wpdb->query('ROLLBACK');return false;}
			if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return false;}
			return true;
		}catch(\Throwable $error){$wpdb->query('ROLLBACK');throw $error;}
	}

	private function usable(mixed $wpdb):bool {
		return is_object($wpdb)&&isset($wpdb->prefix)&&method_exists($wpdb,'insert')&&method_exists($wpdb,'update')
			&&method_exists($wpdb,'query')&&method_exists($wpdb,'prepare')&&method_exists($wpdb,'get_var');
	}
	private static function validId(string $runId):bool{return preg_match('/\A[A-Za-z0-9-]{1,64}\z/',$runId)===1;}
}
