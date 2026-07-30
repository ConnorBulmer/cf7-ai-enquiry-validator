<?php
declare(strict_types=1);
namespace CF7AIV\Admin;
use CF7AIV\Contracts\SubmissionSourceInterface;
final class HealthService {
	private $sources;
	public function __construct(?callable $sources=null){$this->sources=$sources??static fn():array=>function_exists('apply_filters')?(array)apply_filters('cf7aiv_submission_sources',[]):[];}
	/** @return array<string,mixed> */
	public function inspect():array{
		$result=['sources'=>[],'paused_destinations'=>function_exists('get_option')?(array)get_option('cf7aiv_paused_destinations',[]):[],'checks'=>['landing_navigation'=>'read_only','form_presence'=>'read_only','saved_attribution'=>'read_only']];
		foreach(($this->sources)() as $key=>$source){
			if(!$source instanceof SubmissionSourceInterface){continue;}
			try{
				if(method_exists($source,'available')&&!$source->available()){$result['sources'][(string)$key]=['available'=>false,'count'=>null];continue;}
				if(method_exists($source,'count')){$count=$source->count();if($count!==null){$result['sources'][(string)$key]=['available'=>true,'count'=>(int)$count];continue;}}
				$count=0;foreach($source->iterate(['limit'=>1000]) as $_){$count++;if($count>=1000){break;}}
				$result['sources'][(string)$key]=['available'=>true,'count'=>$count];
			}catch(\Throwable){$result['sources'][(string)$key]=['available'=>false,'count'=>null];}
		}
		return $result;
	}
}
