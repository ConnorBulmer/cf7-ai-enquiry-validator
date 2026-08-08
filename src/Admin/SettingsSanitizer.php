<?php
declare(strict_types=1);
namespace CF7AIV\Admin;
use CF7AIV\Infrastructure\Settings;
final class SettingsSanitizer {
	/** @param array<string,mixed> $input @return array<string,mixed> */
	public static function sanitize(array $input):array {
		$out=Settings::defaults();
		$out['automation']['mode']='shadow';
		$verificationMode = (string) ($input['automation']['verification_mode'] ?? 'off');
		$out['automation']['verification_mode'] = in_array($verificationMode, ['off', 'automatic_candidates'], true) ? $verificationMode : 'off';
		$out['calibration']['default_sample_size'] = max(1, min(500, (int) ($input['calibration']['default_sample_size'] ?? 25)));
		$setupStage = (string) ($input['setup']['stage'] ?? 'setup');
		$out['setup']['stage'] = in_array($setupStage, ['setup', 'testing', 'live'], true) ? $setupStage : 'setup';
		$out['ui']['advanced_mode'] = self::bool($input['ui']['advanced_mode'] ?? false);
		$out['privacy']['enhanced_matching']=self::bool($input['privacy']['enhanced_matching']??false);$out['privacy']['delete_on_uninstall']=self::bool($input['privacy']['delete_on_uninstall']??false);$out['privacy']['retention_days']=max(1,min(3650,(int)($input['privacy']['retention_days']??90)));
		$out['attribution']['retention_days']=max(1,min(365,(int)($input['attribution']['retention_days']??90)));
		$consent=(string)($input['consent']['mode']??'respect');$out['consent']['mode']=in_array($consent,['respect','always_actual','legal_basis_override'],true)?$consent:'respect';
		if($out['consent']['mode']==='legal_basis_override'){$reason=trim(strip_tags((string)($input['consent']['override']['reason']??'')));$date=(string)($input['consent']['override']['date']??'');$admin=(int)($input['consent']['override']['administrator_id']??0);if($admin>0&&$reason!==''&&strtotime($date)!==false){$out['consent']['override']=['administrator_id'=>$admin,'date'=>gmdate(DATE_ATOM,(int)strtotime($date)),'reason'=>substr($reason,0,500)];}else{$out['consent']['mode']='respect';}}
		$out['delivery']['pause_threshold']=max(1,min(20,(int)($input['delivery']['pause_threshold']??3)));
		$selected=(string)($input['providers']['selected']??'deepseek');$out['providers']['selected']=in_array($selected,['deepseek','openai'],true)?$selected:'deepseek';
		foreach(['deepseek_model','openai_model'] as $key){$out['providers'][$key]=substr(preg_replace('/[^a-zA-Z0-9._:\-]/','',(string)($input['providers'][$key]??$out['providers'][$key]))??'',0,100);}
		$deepseekReasoning=(string)($input['providers']['deepseek_reasoning_effort']??'off');$out['providers']['deepseek_reasoning_effort']=in_array($deepseekReasoning,['off','standard','deep'],true)?$deepseekReasoning:'off';
		$openaiReasoning=(string)($input['providers']['openai_reasoning_effort']??'unsupported');$openaiOptions=\CF7AIV\Providers\OpenAIProvider::reasoningOptions((string)$out['providers']['openai_model']);if(!in_array($openaiReasoning,$openaiOptions,true)&&in_array($openaiReasoning,['off','unsupported'],true)&&!in_array('unsupported',$openaiOptions,true)){$openaiReasoning=$openaiOptions[0]??'unsupported';}$out['providers']['openai_reasoning_effort']=in_array($openaiReasoning,$openaiOptions,true)?$openaiReasoning:'unsupported';
		foreach(['deepseek_base_url','openai_base_url'] as $key){$url=filter_var((string)($input['providers'][$key]??$out['providers'][$key]),FILTER_VALIDATE_URL);if(is_string($url)&&str_starts_with(strtolower($url),'https://')){$out['providers'][$key]=$url;}}
		$out['providers']['timeout']=max(3,min(300,(int)($input['providers']['timeout']??15)));
		foreach(['ga4','server_gtm','microsoft_uet','webhook'] as $key){
			$given=(array)($input['destinations'][$key]??[]);
			foreach(['enabled','dry_run','allow_insecure'] as $flag){if(array_key_exists($flag,$given)){$out['destinations'][$key][$flag]=self::bool($given[$flag]);}}
			foreach(['measurement_id','tag_id','url'] as $field){if(isset($given[$field])){$out['destinations'][$key][$field]=substr(trim(strip_tags((string)$given[$field])),0,500);}}
			if($key==='server_gtm'){
				$out['destinations'][$key]['measurement_id']=substr(preg_replace('/[^A-Za-z0-9_\-]/','',trim(strip_tags((string)($given['measurement_id']??''))))??'',0,100);
				$endpoint=filter_var(trim((string)($given['endpoint_url']??'')),FILTER_VALIDATE_URL);
				$out['destinations'][$key]['endpoint_url']=is_string($endpoint)&&strtolower((string)parse_url($endpoint,PHP_URL_SCHEME))==='https'&&parse_url($endpoint,PHP_URL_USER)===null&&parse_url($endpoint,PHP_URL_PASS)===null&&parse_url($endpoint,PHP_URL_QUERY)===null&&parse_url($endpoint,PHP_URL_FRAGMENT)===null?substr(rtrim($endpoint,'/'),0,500):'';
			}
		}
		$out['digest']=['enabled'=>self::bool($input['digest']['enabled']??false),'email'=>self::email((string)($input['digest']['email']??''))];
		return $out;
	}
	/** @param array<string,mixed> $input @return array<string,string> */
	public static function secrets(array $input):array { $out=[];foreach(['deepseek_api_key','openai_api_key','ga4_api_secret','server_gtm_api_secret','microsoft_uet_token','webhook_secret'] as $key){$candidate=$input['providers'][$key]??$input['secrets'][$key]??'';$value=is_scalar($candidate)?trim((string)$candidate):'';if($value!==''){$out[$key]=substr($value,0,4096);}}return $out; }
	private static function bool(mixed $value):bool{return $value===true||$value===1||$value==='1'||$value==='yes'||$value==='on';}
	private static function email(string $value):string{$value=strtolower(trim($value));return filter_var($value,FILTER_VALIDATE_EMAIL)?$value:'';}
}
