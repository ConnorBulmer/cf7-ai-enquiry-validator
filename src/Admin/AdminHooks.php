<?php
declare(strict_types=1);
namespace CF7AIV\Admin;

use CF7AIV\Classification\PolicyRepository;
use CF7AIV\Contracts\VerificationCapableProviderInterface;
use CF7AIV\Delivery\DeliveryMapping;
use CF7AIV\Delivery\WpMappingLifecycle;
use CF7AIV\Destinations\GA4Destination;
use CF7AIV\Domain\ConversionEvent;
use CF7AIV\Infrastructure\Crypto;
use CF7AIV\Infrastructure\Settings;
use CF7AIV\Infrastructure\Queue;
use CF7AIV\Infrastructure\WordPressHttpTransport;
use CF7AIV\Integration\MicrosoftUetTestService;
use CF7AIV\Providers\DeepSeekProvider;
use CF7AIV\Providers\OpenAIProvider;
use InvalidArgumentException;

final class AdminHooks {
	private const SCAN_OUTCOME_PREFIX = 'cf7aiv_scan_outcome_';
	/**
	 * Every admin_post action this plugin answers, and the method that answers it.
	 *
	 * Kept as a map rather than twenty add_action calls so that guarded() is
	 * applied to all of them by construction. A handler added here cannot be
	 * registered unguarded by accident, which was how the exposure below arose in
	 * the first place.
	 *
	 * @var array<string,string>
	 */
	private const HANDLERS = [
		'cf7aiv_review' => 'review',
		'cf7aiv_bulk_review' => 'bulkReview',
		'cf7aiv_save_settings' => 'saveSettings',
		'cf7aiv_save_guided_policy' => 'saveGuidedPolicy',
		'cf7aiv_lifecycle_transition' => 'lifecycleTransition',
		'cf7aiv_automation_transition' => 'automationTransition',
		'cf7aiv_ga_transition' => 'gaTransition',
		'cf7aiv_save_consent' => 'saveConsent',
		'cf7aiv_policy_import' => 'policyImport',
		'cf7aiv_policy_publish' => 'policyPublish',
		'cf7aiv_save_mapping' => 'saveMapping',
		'cf7aiv_mapping_publish' => 'mappingPublish',
		'cf7aiv_mapping_enable' => 'mappingEnable',
		'cf7aiv_mapping_disable' => 'mappingDisable',
		'cf7aiv_validate_ga4' => 'validateGa4',
		'cf7aiv_test_microsoft' => 'testMicrosoft',
		'cf7aiv_setup' => 'completeSetup',
		'cf7aiv_setup_scan' => 'setupScan',
		'cf7aiv_setup_suggestion' => 'setupSuggestion',
		'cf7aiv_backfill' => 'backfill',
	];

	private static bool $settingsSave = false;
	private static bool $settingsSaveValidationFailed = false;
	/** @var array<string,mixed> */
	private static array $settingsSaveInput = [];
	/** @var array<string,mixed> */
	private static array $settingsSaveSanitized = [];
	private static string $settingsSaveReturnTab = '';

	public static function register():void{
		EventLog::register();add_action('admin_menu',[self::class,'menu']);add_action('admin_init',[self::class,'settings']);add_action('admin_enqueue_scripts',[self::class,'enqueueAssets']);add_action('init',[self::class,'schedule']);
		foreach(self::HANDLERS as $action=>$method){add_action('admin_post_'.$action,self::guarded([self::class,$method]));}
		add_action('cf7aiv_validate_ga4_job',[self::class,'runGa4Validation']);add_action('cf7aiv_setup_scan_job',[self::class,'runSetupScan'],10,2);add_action('cf7aiv_backfill_batch',[self::class,'backfillBatch'],10,3);add_action('cf7aiv_daily_maintenance',[self::class,'daily']);
	}
	public static function schedule():void{if(function_exists('wp_next_scheduled')&&function_exists('wp_schedule_event')&&!wp_next_scheduled('cf7aiv_daily_maintenance')){wp_schedule_event(time()+3600,'daily','cf7aiv_daily_maintenance');}}
	public static function menu():void{
		add_menu_page('AI Enquiry Validator','AI Enquiry Validator','manage_options','cf7aiv',[AdminPages::class,'overview'],'dashicons-filter',58);
		add_submenu_page('cf7aiv','Overview','Overview','manage_options','cf7aiv',[AdminPages::class,'overview']);add_submenu_page('cf7aiv','Review Queue','Review Queue','manage_options','cf7aiv-review',[AdminPages::class,'reviewQueue']);add_submenu_page('cf7aiv','Policy Lab','Policy Lab','manage_options','cf7aiv-policy',[AdminPages::class,'policyLab']);add_submenu_page('cf7aiv','Integrations','Integrations','manage_options','cf7aiv-integrations',[IntegrationsPage::class,'render']);add_submenu_page('cf7aiv','Tracking Health & Logs','Tracking Health & Logs','manage_options','cf7aiv-health',[AdminPages::class,'health']);add_submenu_page('cf7aiv','Setup','Setup','manage_options','cf7aiv-setup',[AdminPages::class,'setup']);
	}
	// Custom admin-post handlers sanitize form input; do not re-sanitize internal state updates here.
	public static function settings():void{if(function_exists('register_setting')){register_setting('cf7aiv',Settings::OPTION);}}
	public static function enqueueAssets(string $hookSuffix):void{
		$page=substr(preg_replace('/[^a-zA-Z0-9_\-]/','',(string)($_GET['page']??''))??'',0,100);
		$pluginScreen=str_starts_with($page,'cf7aiv')||str_starts_with($hookSuffix,'toplevel_page_cf7aiv')||str_contains($hookSuffix,'_page_cf7aiv');
		if($pluginScreen&&function_exists('wp_enqueue_style')){wp_enqueue_style('cf7aiv-admin',\CF7AIV\URL.'assets/css/admin.css',[],\CF7AIV\VERSION);}
		$setupScreen=$page==='cf7aiv-setup'||str_contains($hookSuffix,'_page_cf7aiv-setup');
		$integrationScreen=$page==='cf7aiv-integrations'||str_contains($hookSuffix,'_page_cf7aiv-integrations');
		if(($setupScreen||$integrationScreen)&&function_exists('wp_enqueue_script')){wp_enqueue_script('cf7aiv-admin',\CF7AIV\URL.'assets/js/admin.js',[],\CF7AIV\VERSION,true);}
	}
	public static function review():void{$post=self::post();AdminRequestGuard::authorize((string)($post['_wpnonce']??''),'cf7aiv_review');$post['review_revision']=self::reviewRevision($post['review_revision']??null);$action=(string)($post['review_action']??'');$decision=(new ReviewService(new WpReviewStore()))->apply((int)($post['item_id']??0),$action,$post,get_current_user_id());$path='admin.php?page=cf7aiv-review';if($action==='approve_dispatch'&&!$decision->dispatched){$state=self::identifier($decision->state,'review');$path.='&review_error=1&review_state='.rawurlencode($state);}self::redirect($path);}
	public static function bulkReview():void{$post=self::post();AdminRequestGuard::authorize((string)($post['_wpnonce']??''),'cf7aiv_bulk_review');$ids=is_array($post['item_ids']??null)?array_values(array_unique(array_filter(array_map('intval',$post['item_ids']),static fn(int $id):bool=>$id>0))):[];$raw=is_array($post['review_revisions']??null)?$post['review_revisions']:[];$revisions=[];foreach($ids as $id){$revisions[$id]=self::reviewRevision($raw[$id]??null);}$post['review_revisions']=$revisions;(new ReviewService(new WpReviewStore()))->applyMany($ids,(string)($post['review_action']??''),$post,get_current_user_id());self::redirect('admin.php?page=cf7aiv-review');}
	public static function saveSettings():void{$post=self::post();AdminRequestGuard::authorize((string)($post['_wpnonce']??''),'cf7aiv_settings');$input=(array)($post['cf7aiv']??[]);$current=(array)get_option(Settings::OPTION,Settings::defaults());$sanitized=SettingsSanitizer::sanitize($input);$secrets=SettingsSanitizer::secrets($input);$next=$current;if(isset($input['ui'])){$next['ui']['advanced_mode']=$sanitized['ui']['advanced_mode'];}if(isset($input['providers'])){$next['providers']=$sanitized['providers'];}if(isset($input['privacy'])){$next['privacy']=$sanitized['privacy'];}if(isset($input['automation'])){$next['automation']['verification_mode']=$sanitized['automation']['verification_mode'];}if(!self::verificationSupported((string)($next['providers']['selected']??'deepseek'))){$next['automation']['verification_mode']=Settings::VERIFICATION_OFF;}foreach(array_keys((array)($input['destinations']??[])) as $key){if(!in_array($key,['ga4','server_gtm','microsoft_uet','webhook'],true)){continue;}$next['destinations'][$key]=$sanitized['destinations'][$key];if($key==='ga4'){$oldGa=(array)($current['destinations']['ga4']??[]);$newGa=(array)$next['destinations']['ga4'];$changed=trim((string)($oldGa['measurement_id']??''))!==trim((string)($newGa['measurement_id']??''))||!empty($oldGa['enabled'])!==!empty($newGa['enabled'])||isset($secrets['ga4_api_secret'])||empty($newGa['enabled']);if($changed){Ga4Validation::invalidate();$next['destinations']['ga4']['debug_validated']=false;$next['destinations']['ga4']['live_enabled']=false;$next['destinations']['ga4']['dry_run']=true;}else{$next['destinations']['ga4']['debug_validated']=!empty($oldGa['debug_validated']);$next['destinations']['ga4']['live_enabled']=!empty($oldGa['live_enabled']);if(!empty($next['destinations']['ga4']['live_enabled'])){$next['destinations']['ga4']['dry_run']=false;}}}}update_option(Settings::OPTION,$next,false);$cleared=self::secretsToClear($input);if($secrets!==[]||$cleared!==[]){$stored=(array)get_option('cf7aiv_secrets',[]);foreach($cleared as $key){unset($stored[$key]);}foreach($secrets as $key=>$value){$stored[$key]=Crypto::encrypt($value);}update_option('cf7aiv_secrets',$stored,false);}self::redirect('admin.php?page=cf7aiv-integrations');}
	/**
	 * Credentials the administrator asked to remove.
	 *
	 * A blank credential field means "keep what is stored", which is what makes
	 * re-saving safe - but it also left no way to take a credential out again
	 * without editing the database. Removal is therefore an explicit checkbox
	 * rather than an empty field.
	 *
	 * A typed replacement wins over a removal request, because someone who has
	 * both ticked the box and entered a new value clearly wants the new value.
	 * Only known credential names are honoured.
	 *
	 * @param array<string,mixed> $input
	 * @return list<string>
	 */
	private static function secretsToClear(array $input):array{
		$requested=$input['secrets_clear']??null;
		if(!is_array($requested)){return [];}
		$known=['deepseek_api_key','openai_api_key','ga4_api_secret','server_gtm_api_secret','microsoft_uet_token','webhook_secret'];
		$clear=[];
		foreach($known as $key){
			if(in_array($requested[$key]??null,[true,1,'1','true','on','yes'],true)){$clear[]=$key;}
		}
		return $clear;
	}
	public static function saveGuidedPolicy():void{
		$post=self::post();AdminRequestGuard::authorize((string)($post['_wpnonce']??''),'cf7aiv_guided_policy');
		$input=(array)($post['guided']??[]);$definition=GuidedPolicyDraft::build($input,self::acceptedSetupSuggestions());
		$key=self::identifier((string)($input['policy_key']??'global'),'global');$version=self::identifier((string)($input['version']??gmdate('Ymd-His')),gmdate('Ymd-His'));
		(new PolicyRepository())->saveDraft($key,$version,$definition,get_current_user_id());
		$settings=(array)get_option(Settings::OPTION,Settings::defaults());$settings['setup']['selected_pack']=(string)$definition['pack']['key'];$settings['setup']['draft_summary']=['policy_key'=>$key,'version'=>$version,'pack_key'=>(string)$definition['pack']['key'],'pack_label'=>(string)$definition['pack']['label'],'status'=>'draft','saved_at'=>gmdate(DATE_ATOM)];update_option(Settings::OPTION,$settings,false);
		self::redirect(($post['return_page']??'setup')==='policy'?'admin.php?page=cf7aiv-policy':'admin.php?page=cf7aiv-setup');
	}
	public static function lifecycleTransition():void{
		$post=self::post();AdminRequestGuard::authorize((string)($post['_wpnonce']??''),'cf7aiv_lifecycle_transition');
		$settings=(array)get_option(Settings::OPTION,Settings::defaults());$current=(string)($settings['setup']['stage']??'setup');$target=(string)($post['transition']??'');
		if($target==='testing'&&in_array($current,['setup','testing','live'],true)){$settings['setup']['stage']='testing';$settings['setup_complete']=true;$settings['automation']['mode']='shadow';}
		elseif($target==='live'&&$current==='testing'&&self::hasPublishedV2Policy()){$settings['setup']['stage']='live';$settings['setup_complete']=true;$settings['automation']['mode']='shadow';}
		if(!self::verificationSupported((string)($settings['providers']['selected']??'deepseek'))){$settings['automation']['verification_mode']=Settings::VERIFICATION_OFF;}
		update_option(Settings::OPTION,$settings,false);self::redirect('admin.php?page=cf7aiv-setup');
	}
	public static function automationTransition():void{$post=self::post();AdminRequestGuard::authorize((string)($post['_wpnonce']??''),'cf7aiv_automation_transition');$settings=(array)get_option(Settings::OPTION,Settings::defaults());$live=($post['transition']??'shadow')==='live'&&($settings['setup']['stage']??'setup')==='live';$settings['automation']['mode']=$live?'live':'shadow';if(!self::verificationSupported((string)($settings['providers']['selected']??'deepseek'))){$settings['automation']['verification_mode']=Settings::VERIFICATION_OFF;}update_option(Settings::OPTION,$settings,false);self::redirect('admin.php?page=cf7aiv-integrations');}
	public static function gaTransition():void{$post=self::post();AdminRequestGuard::authorize((string)($post['_wpnonce']??''),'cf7aiv_ga_transition');$settings=(array)get_option(Settings::OPTION,Settings::defaults());$ga=(array)($settings['destinations']['ga4']??[]);$live=($post['transition']??'shadow')==='live';$ready=!empty($ga['enabled'])&&Ga4Validation::isPassed()&&trim((string)($ga['measurement_id']??''))!==''&&Crypto::secret('ga4_api_secret')!=='';$settings['destinations']['ga4']['live_enabled']=$live&&$ready;$settings['destinations']['ga4']['dry_run']=!($live&&$ready);update_option(Settings::OPTION,$settings,false);self::redirect('admin.php?page=cf7aiv-integrations');}
	public static function saveConsent():void{$post=self::post();AdminRequestGuard::authorize((string)($post['_wpnonce']??''),'cf7aiv_save_consent');$settings=(array)get_option(Settings::OPTION,Settings::defaults());$mode=(string)($post['mode']??'respect');$input=['consent'=>['mode'=>$mode]];if($mode==='legal_basis_override'){$input['consent']['override']=['administrator_id'=>get_current_user_id(),'date'=>gmdate(DATE_ATOM),'reason'=>(string)($post['reason']??'')];}$sanitized=SettingsSanitizer::sanitize($input);$settings['consent']=$sanitized['consent'];update_option(Settings::OPTION,$settings,false);if(($sanitized['consent']['mode']??'')==='legal_basis_override'&&isset($sanitized['consent']['override'])){$audit=(array)get_option('cf7aiv_consent_override_audit',[]);$audit[]=$sanitized['consent']['override'];if(count($audit)>500){$audit=array_slice($audit,-500);}update_option('cf7aiv_consent_override_audit',$audit,false);}self::redirect('admin.php?page=cf7aiv-integrations');}
	public static function policyImport():void{$post=self::post();AdminRequestGuard::authorize((string)($post['_wpnonce']??''),'cf7aiv_policy');$definition=PolicyLab::import((string)($post['policy_json']??''));$key=self::identifier((string)($post['policy_key']??'global'),'global');$version=self::identifier((string)($post['version']??gmdate('Ymd-His')),gmdate('Ymd-His'));(new PolicyRepository())->saveDraft($key,$version,$definition,get_current_user_id());self::redirect('admin.php?page=cf7aiv-policy');}
	public static function policyPublish():void{$post=self::post();AdminRequestGuard::authorize((string)($post['_wpnonce']??''),'cf7aiv_policy_publish');$key=trim((string)($post['policy_key']??'global'));$version=trim((string)($post['version']??''));$published=(new PolicyRepository())->publish($key,$version);if($published){$settings=(array)get_option(Settings::OPTION,Settings::defaults());$summary=(array)($settings['setup']['draft_summary']??[]);if(($summary['policy_key']??'')===$key&&($summary['version']??'')===$version){$settings['setup']['draft_summary']['status']='published';update_option(Settings::OPTION,$settings,false);}}self::redirect('admin.php?page=cf7aiv-policy');}
	public static function saveMapping():void{$post=self::post();AdminRequestGuard::authorize((string)($post['_wpnonce']??''),'cf7aiv_mapping');$data=(array)($post['mapping']??[]);$data['status']='draft';$data['enabled']=false;$mapping=DeliveryMapping::fromArray($data);global $wpdb;$wpdb->insert($wpdb->prefix.'cf7aiv_mappings',['mapping_key'=>$mapping->key,'version'=>$mapping->version,'tier_id'=>$mapping->tierId,'destination_key'=>$mapping->destinationKey,'event_name'=>$mapping->eventName,'event_value'=>$mapping->value,'currency'=>$mapping->currency,'status'=>'draft','enabled'=>0,'created_at'=>current_time('mysql',true),'updated_at'=>current_time('mysql',true),'published_at'=>null]);self::redirect('admin.php?page=cf7aiv-integrations');}
	public static function mappingPublish():void{$post=self::post();AdminRequestGuard::authorize((string)($post['_wpnonce']??''),'cf7aiv_mapping_publish');$key=self::identifier((string)($post['mapping_key']??''),'');$version=self::identifier((string)($post['version']??''),'');if($key!==''&&$version!==''){(new WpMappingLifecycle())->publish($key,$version);}self::redirect('admin.php?page=cf7aiv-integrations');}
	public static function mappingEnable():void{$post=self::post();AdminRequestGuard::authorize((string)($post['_wpnonce']??''),'cf7aiv_mapping_enable');$key=self::identifier((string)($post['mapping_key']??''),'');$version=self::identifier((string)($post['version']??''),'');if($key!==''&&$version!==''){(new WpMappingLifecycle())->enable($key,$version);}self::redirect('admin.php?page=cf7aiv-integrations');}
	public static function mappingDisable():void{$post=self::post();AdminRequestGuard::authorize((string)($post['_wpnonce']??''),'cf7aiv_mapping_disable');$key=self::identifier((string)($post['mapping_key']??''),'');$version=self::identifier((string)($post['version']??''),'');if($key!==''&&$version!==''){(new WpMappingLifecycle())->disable($key,$version);}self::redirect('admin.php?page=cf7aiv-integrations');}
	public static function validateGa4():void{$post=self::post();AdminRequestGuard::authorize((string)($post['_wpnonce']??''),'cf7aiv_validate_ga4');$settings=(array)get_option(Settings::OPTION,Settings::defaults());$ga=(array)($settings['destinations']['ga4']??[]);Ga4Validation::queued();EventLog::record('ga4_validation','info','Validation queued.',['measurement_id'=>(string)($ga['measurement_id']??'')]);if(!Queue::accepted(Queue::enqueue('cf7aiv_validate_ga4_job'))){self::runGa4Validation();}self::redirect('admin.php?page=cf7aiv-integrations');}
	/**
	 * Run the GA4 check away from the administrator's request.
	 *
	 * It used to run inline. A destination check that contacts Google can
	 * outlive PHP's execution limit on a slow or filtered network, and when it
	 * did the request died before anything was recorded - so the screen showed
	 * no result, no reason, and no sign it had even run. Observed on a live
	 * site: repeated attempts, each ending with the page simply reloading.
	 *
	 * Queued work cannot take the page down with it, and the outcome is written
	 * whenever it finishes. If the queue is unavailable the check still runs
	 * inline, because refusing to check at all would be worse.
	 */
	public static function runGa4Validation():void{
		$settings=(array)get_option(Settings::OPTION,Settings::defaults());
		$ga=(array)($settings['destinations']['ga4']??[]);
		try{
			$event=new ConversionEvent('cf7aiv-validation','validation','cf7aiv_configuration_check',0.0,'GBP',gmdate(DATE_ATOM),[],['client_id'=>'0.0'],['analytics_storage'=>'denied','ad_storage'=>'denied','ad_user_data'=>'denied','ad_personalization'=>'denied']);
			$result=(new GA4Destination(new WordPressHttpTransport(),(string)($ga['measurement_id']??''),Crypto::secret('ga4_api_secret'),true,false,false))->send($event);
			$reason=$result->successful?'':substr((string)($result->error??'GA4 validation failed without an explanation.'),0,400);
		}catch(\Throwable $error){
			$result=null;$reason=DiagnosticsService::describeFailure($error);
			EventLog::recordFailure('ga4_validation',$error);
		}
		$passed=$result!==null&&$result->successful;
		if($passed){Ga4Validation::passed();}else{Ga4Validation::failed($reason);}
		EventLog::record('ga4_validation',$passed?'info':'error',$passed?'Validation passed.':('Validation failed. '.$reason));
	}
	public static function testMicrosoft():void{$post=self::post();AdminRequestGuard::authorize((string)($post['_wpnonce']??''),'cf7aiv_test_microsoft');$status='failed';try{$receipt=(new MicrosoftUetTestService())->transport((string)($post['confirmation']??''));$status=!empty($receipt['successful'])?'success':(!empty($receipt['retryable'])?'retryable':'failed');}catch(InvalidArgumentException){$status='refused';}self::redirect('admin.php?page=cf7aiv-integrations&microsoft_test='.$status);}
	public static function completeSetup():void{$post=self::post();AdminRequestGuard::authorize((string)($post['_wpnonce']??''),'cf7aiv_setup');$settings=(array)get_option(Settings::OPTION,Settings::defaults());$settings['setup_complete']=true;$settings['setup']['stage']='testing';$settings['automation']['mode']='shadow';update_option(Settings::OPTION,$settings,false);self::redirect('admin.php?page=cf7aiv-setup');}
	public static function setupScan():void{$post=self::post();AdminRequestGuard::authorize((string)($post['_wpnonce']??''),'cf7aiv_setup_scan');$pack=self::identifier((string)($post['pack_key']??'general'),'general');$administratorId=function_exists('get_current_user_id')?get_current_user_id():0;self::rememberScanOutcome($administratorId,['state'=>'queued','queued_at'=>time()]);
		// A refused job is reported, not run inline. With reasoning the call can
		// take minutes, and inside this request it would hang the page until a
		// proxy gave up - the failure the background job exists to prevent.
		if(Queue::accepted(Queue::enqueue('cf7aiv_setup_scan_job',[$pack,$administratorId]))){EventLog::record('setup_scan','info','Site scan queued.',['pack'=>$pack]);}
		else{$reason='The background queue refused the job, so the scan did not run. Check Tools > Scheduled Actions for errors, then try again.';EventLog::record('setup_scan','error','Site scan could not be queued. '.$reason,['pack'=>$pack]);self::rememberScanOutcome($administratorId,['state'=>'failed','diagnostic'=>$reason]);}
		self::redirect('admin.php?page=cf7aiv-setup');}
	/**
	 * Run the site scan away from the administrator's request.
	 *
	 * This was the last provider call still made inside an admin request, and
	 * it is the slowest of them: a whole site profile with reasoning enabled.
	 * Keeping it synchronous is what forced the provider timeout to stay small
	 * enough for a web request to survive, which in turn starved classification
	 * of the thinking time that makes it accurate. Queued, the timeout can be
	 * sized for the work rather than for the browser.
	 */
	public static function runSetupScan(string $pack='general',int $administratorId=0):void{
		try{
			$service=self::setupService();
			$count=$service->generate($pack,$administratorId);
			if($service->lastError()!==null){
				EventLog::record('setup_scan','error','Site scan failed. '.(string)$service->lastDiagnostic());
				self::rememberScanOutcome($administratorId,['state'=>'failed','diagnostic'=>(string)$service->lastDiagnostic()]);
				return;
			}
			EventLog::record('setup_scan','info','Site scan complete.',['suggestions'=>(string)$count]);
			self::rememberScanOutcome($administratorId,['state'=>'complete','suggestions'=>max(0,$count)]);
		}catch(\Throwable $error){
			EventLog::recordFailure('setup_scan',$error);
			self::rememberScanOutcome($administratorId,['state'=>'failed','diagnostic'=>DiagnosticsService::describeFailure($error)]);
		}
	}
	/**
	 * Leave the scan's state for the administrator who started it.
	 *
	 * The scan runs in a background job, so the request that started it has
	 * already redirected by the time there is a result. The state - queued, then
	 * complete or failed - waits here for that administrator's next visit to
	 * Setup, which is how a failure reason still reaches the page.
	 *
	 * It travels in a per-administrator transient rather than the query string: a
	 * URL is shoulder-surfed, logged by the server, and kept in history, and the
	 * reason names the provider and the status it returned. The reason is
	 * redacted and length-capped before it gets here.
	 *
	 * @param array{state:string,diagnostic?:string,suggestions?:int,queued_at?:int} $outcome
	 */
	private static function rememberScanOutcome(int $administratorId,array $outcome):void{
		if($administratorId<=0||!function_exists('set_transient')){return;}
		set_transient(self::SCAN_OUTCOME_PREFIX.$administratorId,$outcome,3600);
	}
	/**
	 * Read the current administrator's scan state.
	 *
	 * A finished result is cleared once shown, so a stale reason cannot be
	 * mistaken for a fresh one. A queued scan stays until the job replaces it.
	 *
	 * @return array{state?:string,diagnostic?:string,suggestions?:int,queued_at?:int}
	 */
	public static function takeScanOutcome():array{
		if(!function_exists('get_transient')||!function_exists('get_current_user_id')){return [];}
		$key=self::SCAN_OUTCOME_PREFIX.get_current_user_id();
		$value=get_transient($key);
		if(!is_array($value)||!in_array($value['state']??null,['queued','complete','failed'],true)){return [];}
		if($value['state']!=='queued'&&function_exists('delete_transient')){delete_transient($key);}
		return $value;
	}
	public static function setupSuggestion():void{$post=self::post();AdminRequestGuard::authorize((string)($post['_wpnonce']??''),'cf7aiv_setup_suggestion');$action=(string)($post['suggestion_action']??'');$edited=$post['edited_value']??null;if(is_string($edited)&&str_starts_with(trim($edited),'{')){$decoded=json_decode($edited,true);if(is_array($decoded)){$edited=$decoded;}}self::setupService()->review((int)($post['suggestion_id']??0),$action,$edited);self::redirect('admin.php?page=cf7aiv-setup');}
	public static function backfill():void{$post=self::post();AdminRequestGuard::authorize((string)($post['_wpnonce']??''),'cf7aiv_backfill');$source=substr(preg_replace('/[^a-zA-Z0-9_\-]/','',(string)($post['source']??''))??'',0,32);$rawForms=$post['form_ids']??[];if(!is_array($rawForms)){$rawForms=preg_split('/\s*,\s*/',(string)$rawForms,-1,PREG_SPLIT_NO_EMPTY)?:[];}$explicit=in_array($post['explicit_opt_in']??null,[true,1,'1','true','on','yes'],true);$snapshot=CalibrationPolicySnapshot::active();$submitted=(string)($post['whitelist_fingerprint']??'');if($submitted===''||!hash_equals($snapshot['fingerprint'],$submitted)){self::refuse('Published policy fields changed after preview. Review the current whitelist and opt in again.');}try{(new CalibrationService())->schedule($source,['explicit_opt_in'=>$explicit,'form_ids'=>$rawForms,'date_from'=>(string)($post['date_from']??gmdate('Y-m-d',strtotime('-1 year'))),'date_to'=>(string)($post['date_to']??gmdate('Y-m-d')),'maximum'=>(int)($post['maximum']??25),'ai_whitelist_snapshot'=>$snapshot['fields'],'whitelist_fingerprint'=>$snapshot['fingerprint']],function_exists('get_current_user_id')?get_current_user_id():0);}catch(\InvalidArgumentException $error){self::refuse($error->getMessage());}self::redirect('admin.php?page=cf7aiv-health');}
	/** @param array<string,mixed> $arguments */ public static function backfillBatch(string $sourceKey,array $arguments=[],int $batchSize=25):void{(new CalibrationBatchWorker(new WpCalibrationRunStore()))->process($sourceKey,$arguments,$batchSize);}
	public static function daily():void{$settings=(array)get_option(Settings::OPTION,Settings::defaults());(new RetentionService())->cleanup((int)($settings['privacy']['retention_days']??90));(new DigestService())->send();}
	/**
	 * Wrap an admin_post handler so a throw becomes an HTTP response.
	 *
	 * Every handler here validates input, and most of them hand that input to a
	 * service that throws InvalidArgumentException when it is wrong. Registered
	 * bare, that exception left the handler uncaught: WordPress rendered its
	 * "critical error" page, mailed the site administrator and logged a fatal, for
	 * what was in fact a typed-in value the plugin was right to reject. The
	 * administrator saw a broken plugin and lost whatever they had entered.
	 *
	 * The split below follows how this codebase already uses exceptions.
	 * InvalidArgumentException means "the caller passed something wrong", and
	 * every such message in src/ is written for a person to read - so it is shown,
	 * with a 400. Anything else is a fault the administrator cannot act on and
	 * whose message may describe internals, so it is logged in full and reported
	 * generically with a 500.
	 *
	 * @param callable():void $handler
	 */
	public static function guarded(callable $handler):\Closure{
		return static function()use($handler):void{
			AdminResponse::reset();
			try{$handler();}catch(\Throwable $error){self::fail($error);}
		};
	}

	/**
	 * Turn a thrown handler into a response.
	 *
	 * A handler that already answered - denied for capability or nonce, refused
	 * for bad input - is left alone. wp_die is not guaranteed to end the request,
	 * so a deliberate 403 can arrive here as a throwable, and reporting it as an
	 * unexpected fault would turn "you may not do that" into "the plugin broke".
	 *
	 * Outside WordPress there is nothing to render and no fatal page to prevent,
	 * so the original is re-thrown with its type and stack intact - that is what
	 * keeps the handlers assertable in tests.
	 *
	 * The error_log call is deliberately not behind CF7AIV_DEBUG, unlike
	 * DiagnosticsService::recordGate. Catching here removes the fatal that
	 * WordPress would have logged unconditionally, and silently swallowing a real
	 * fault would be a worse bug than the one being fixed.
	 */
	private static function fail(\Throwable $error):void{
		if(AdminResponse::sent()){throw $error;}
		if($error instanceof \InvalidArgumentException){self::refuse($error->getMessage());return;}
		$action=self::currentAction();
		if(function_exists('do_action')){do_action('cf7aiv_admin_action_failed',$action,$error);}
		if(function_exists('error_log')){error_log('[CF7AIV admin_action_failed] '.($action===''?'unknown':$action).': '.$error->getMessage().' in '.$error->getFile().':'.$error->getLine());}
		if(function_exists('wp_die')){wp_die('This action could not be completed. The details were written to the site error log; no settings were changed by the failed step.','Error',['response'=>500]);return;}
		throw $error;
	}

	/**
	 * Refuse a request the administrator is allowed to make but which cannot be
	 * carried out - a stale policy fingerprint, an unknown form, a source with no
	 * records.
	 *
	 * These used to throw out of the admin_post handler uncaught, so WordPress
	 * rendered its "critical error" page, mailed the site administrator and logged
	 * a fatal. A routine validation refusal is not a crash, and presenting it as
	 * one sends people looking for a broken plugin. Mirrors
	 * AdminRequestGuard::deny(), including throwing when wp_die is unavailable so
	 * the behaviour stays assertable in tests.
	 */
	private static function refuse(string $message):void{
		$action=self::currentAction();
		// A refusal is shown on screen, so the administrator who caused it always
		// knows why. Nobody else does: a refusal that only happens sometimes, or
		// to someone else, otherwise leaves no trace at all. Broadcast it so it can
		// be observed, and log it only under CF7AIV_DEBUG - the same balance
		// DiagnosticsService::recordGate strikes, and for the same reason: routine
		// refusals are not errors and must not fill the site error log.
		if(function_exists('do_action')){do_action('cf7aiv_admin_action_refused',$action,$message);}
		if(defined('CF7AIV_DEBUG')&&CF7AIV_DEBUG&&function_exists('error_log')){error_log('[CF7AIV admin_action_refused] '.($action===''?'unknown':$action).': '.$message);}
		AdminResponse::halt($message,'Bad Request',400);
		throw new \InvalidArgumentException($message);
	}

	/** The admin_post action being served, reduced to identifier characters before it reaches a log or a hook. */
	private static function currentAction():string{
		return substr(preg_replace('/[^a-zA-Z0-9_\-]/','',(string)($_POST['action']??''))??'',0,64);
	}

	/** @return array<string,mixed> */
	private static function post():array{
		self::$settingsSave=false;self::$settingsSaveValidationFailed=false;self::$settingsSaveInput=[];self::$settingsSaveSanitized=[];self::$settingsSaveReturnTab='';
		$value=$_POST??[];
		if(!is_array($value)){return [];}
		$value=function_exists('wp_unslash')?wp_unslash($value):$value;
		if(!is_array($value)){return [];}
		if(isset($value['cf7aiv'])&&is_array($value['cf7aiv'])){
			self::$settingsSave=true;self::$settingsSaveReturnTab=is_scalar($value['return_tab']??null)?(string)$value['return_tab']:'';$settings=(array)get_option(Settings::OPTION,Settings::defaults());$input=(array)$value['cf7aiv'];
			if(array_key_exists('providers',$input)&&is_array($input['providers'])){foreach((array)($settings['providers']??[]) as $key=>$stored){if(!array_key_exists($key,$input['providers'])){$value['cf7aiv']['providers'][$key]=$stored;}}}
			self::$settingsSaveInput=(array)$value['cf7aiv'];
			try{self::$settingsSaveSanitized=SettingsSanitizer::sanitize(self::$settingsSaveInput);}catch(\Throwable){self::$settingsSaveValidationFailed=true;$value['cf7aiv']=[];self::$settingsSaveInput=[];self::$settingsSaveSanitized=[];}
		}
		return $value;
	}
	private static function settingsSaveSucceeded():bool{
		if(self::$settingsSaveValidationFailed){return false;}$input=self::$settingsSaveInput;$sanitized=self::$settingsSaveSanitized;$settings=Settings::withDefaults((array)get_option(Settings::OPTION,Settings::defaults()));
		if(isset($input['ui'])&&($settings['ui']['advanced_mode']??null)!==($sanitized['ui']['advanced_mode']??null)){return false;}
		if(isset($input['providers'])&&(array)($settings['providers']??[])!==(array)($sanitized['providers']??[])){return false;}
		if(isset($input['privacy'])&&(array)($settings['privacy']??[])!==($sanitized['privacy']??[])){return false;}
		if(isset($input['automation'])){$expected=(string)($sanitized['automation']['verification_mode']??Settings::VERIFICATION_OFF);if(!self::verificationSupported((string)($settings['providers']['selected']??'deepseek'))){$expected=Settings::VERIFICATION_OFF;}if((string)($settings['automation']['verification_mode']??'')!==$expected){return false;}}
		foreach((array)($input['destinations']??[]) as $key=>$given){if(!in_array((string)$key,['ga4','server_gtm','microsoft_uet','webhook'],true)||!is_array($given)){continue;}foreach(['enabled','dry_run','allow_insecure','measurement_id','tag_id','url','endpoint_url'] as $field){if(array_key_exists($field,$given)&&(($settings['destinations'][$key][$field]??null)!==($sanitized['destinations'][$key][$field]??null))){return false;}}}
		foreach(SettingsSanitizer::secrets($input) as $key=>$value){try{$stored=Crypto::secret($key);}catch(\Throwable){return false;}if($stored!==$value){return false;}}
		// A removal is verified against the stored option rather than Crypto::secret(),
		// because a constant outranks the database and cannot be removed from here.
		// Checking the resolved value would report a failure for a removal that did
		// exactly what was asked.
		$remaining=(array)get_option('cf7aiv_secrets',[]);
		foreach(self::secretsToClear($input) as $key){if(isset($remaining[$key])){return false;}}
		return true;
	}
	private static function setupService():SetupSuggestionService{$settings=Settings::withDefaults((array)get_option(Settings::OPTION,Settings::defaults()));$providerSettings=(array)$settings['providers'];$transport=new WordPressHttpTransport();$selected=(string)$providerSettings['selected'];$timeout=(int)$providerSettings['timeout'];$reasoningKey=$selected==='openai'?'openai_reasoning_effort':'deepseek_reasoning_effort';$reasoningEffort=(string)$providerSettings[$reasoningKey];$provider=$selected==='openai'?new OpenAIProvider($transport,Crypto::secret('openai_api_key'),(string)$providerSettings['openai_base_url'],(string)$providerSettings['openai_model'],$timeout,$reasoningEffort):new DeepSeekProvider($transport,Crypto::secret('deepseek_api_key'),(string)$providerSettings['deepseek_base_url'],(string)$providerSettings['deepseek_model'],$timeout,$reasoningEffort);return new SetupSuggestionService(new SiteProfileScanner(),$provider,new SetupSuggestionRepository());}
	/** @return list<array<string,mixed>> */ private static function acceptedSetupSuggestions():array{global $wpdb;if(!isset($wpdb->prefix)||!method_exists($wpdb,'get_results')){return [];}$rows=$wpdb->get_results("SELECT suggestion_type,value_json,provenance_json FROM {$wpdb->prefix}cf7aiv_setup_suggestions WHERE status='accepted' ORDER BY id ASC LIMIT 200",ARRAY_A);return is_array($rows)?$rows:[];}
	private static function hasPublishedV2Policy():bool{global $wpdb;return isset($wpdb->prefix)&&method_exists($wpdb,'get_var')&&(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cf7aiv_policies WHERE status='published' AND policy_format='cf7aiv-policy/v2'")>0;}
	private static function verificationSupported(string $selected):bool{try{$providers=function_exists('apply_filters')?(array)apply_filters('cf7aiv_ai_providers',[]):[];if(isset($providers[$selected])){return $providers[$selected] instanceof VerificationCapableProviderInterface;}return $selected==='deepseek'&&is_a(DeepSeekProvider::class,VerificationCapableProviderInterface::class,true);}catch(\Throwable){return false;}}
	private static function redirect(string $path):void{if(str_starts_with($path,'admin.php?page=cf7aiv-integrations')&&is_array($_POST??null)){ $tab=self::$settingsSaveReturnTab!==''?self::$settingsSaveReturnTab:($_POST['return_tab']??'');if(is_scalar($tab)&&in_array((string)$tab,['provider','google','microsoft','webhook','consent'],true)){$path.=(str_contains($path,'?')?'&':'?').'tab='.rawurlencode((string)$tab);}if(self::$settingsSave){$path.='&'.(self::settingsSaveSucceeded()?'saved=1':'failed=1');}}if(function_exists('wp_safe_redirect')){wp_safe_redirect(admin_url($path));}}
	private static function identifier(string $value,string $fallback):string{$value=substr(preg_replace('/[^a-zA-Z0-9:_\-]/','',trim($value))??'',0,100);return $value===''?$fallback:$value;}
	private static function reviewRevision(mixed $value):int{if(is_int($value)&&$value>=0){return $value;}if(is_string($value)&&preg_match('/\A(?:0|[1-9][0-9]*)\z/',$value)===1){$parsed=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);if(is_int($parsed)){return $parsed;}}throw new \InvalidArgumentException('Review revision is required.');}
}
