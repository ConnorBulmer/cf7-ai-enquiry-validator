<?php
/** Standalone administration, review, privacy, and operations tests. */
declare(strict_types=1);
function admin_assert(bool $condition, string $message): void { if (! $condition) { throw new RuntimeException($message); } }
$GLOBALS['admin_actions'] = []; $GLOBALS['admin_filters'] = []; $GLOBALS['admin_menus'] = []; $GLOBALS['admin_submenus'] = []; $GLOBALS['admin_fired'] = []; $GLOBALS['admin_options'] = []; $GLOBALS['admin_enqueued_styles'] = []; $GLOBALS['admin_enqueued_scripts'] = []; $GLOBALS['admin_allowed'] = true; $GLOBALS['admin_nonce_valid'] = true; $GLOBALS['admin_throw_action'] = null;
function add_action(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): void { $GLOBALS['admin_actions'][$hook][] = [$callback,$priority,$acceptedArgs]; }
function add_filter(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): void { $GLOBALS['admin_filters'][$hook][] = [$callback,$priority,$acceptedArgs]; }
function do_action(string $hook, mixed ...$args): void { $GLOBALS['admin_fired'][] = [$hook,$args]; if ($GLOBALS['admin_throw_action'] === $hook) { throw new RuntimeException('Observer failure.'); } }
function apply_filters(string $hook, mixed $value, mixed ...$arguments): mixed { foreach($GLOBALS['admin_filters'][$hook]??[] as [$callback]){$value=$callback($value,...$arguments);}return $value; }
function plugin_dir_url(string $file): string { return 'https://example.test/plugin/'; }
function as_enqueue_async_action(string $hook='',array $args=[],string $group='',bool $unique=false): int { $GLOBALS['admin_async'][]=compact('hook','args','group','unique'); return 1; }
function get_option(string $key, mixed $default = false): mixed { return $GLOBALS['admin_options'][$key] ?? $default; }
function update_option(string $key, mixed $value, bool $autoload = false): bool { $GLOBALS['admin_options'][$key] = $value; return true; }
function add_option(string $key, mixed $value, string $deprecated = '', bool $autoload = true): bool { $GLOBALS['admin_options'][$key] = $value; return true; }
function current_user_can(string $capability): bool { return $GLOBALS['admin_allowed']; }
function wp_verify_nonce(string $nonce, string $action): int|false { return $GLOBALS['admin_nonce_valid'] ? 1 : false; }
function get_current_user_id(): int { return 77; }
function current_time(string $type, bool $gmt = false): string { return '2026-07-22 12:00:00'; }
function wp_json_encode(mixed $value, int $flags = 0): string|false { return json_encode($value, $flags); }
function wp_unslash(mixed $value): mixed { return is_array($value) ? array_map('wp_unslash', $value) : (is_string($value) ? stripslashes($value) : $value); }
function admin_wp_slash(mixed $value): mixed { return is_array($value) ? array_map('admin_wp_slash', $value) : (is_string($value) ? addslashes($value) : $value); }
function __(string $value, string $domain = ''): string { return $value; }
function esc_html(string $value): string { return htmlspecialchars($value, ENT_QUOTES); }
function esc_attr(string $value): string { return htmlspecialchars($value, ENT_QUOTES); }
function esc_url(string $value): string { return $value; }
function admin_url(string $path = ''): string { return 'https://example.test/wp-admin/' . $path; }
function wp_safe_redirect(string $url): bool { $GLOBALS['admin_redirect'] = $url; return true; }
function wp_nonce_field(string $action, string $name = '_wpnonce'): void { echo '<input type="hidden" name="' . $name . '" value="nonce">'; }
function add_menu_page(mixed ...$args): string { $GLOBALS['admin_menus'][] = $args; return 'toplevel'; }
function add_submenu_page(mixed ...$args): string { $GLOBALS['admin_submenus'][] = $args; return 'submenu'; }
function register_setting(mixed ...$args): bool { return true; }
function wp_enqueue_style(string $handle, string $src, array $dependencies = [], string|bool|null $version = false): void { $GLOBALS['admin_enqueued_styles'][] = compact('handle', 'src', 'dependencies', 'version'); }
function wp_enqueue_script(string $handle, string $src, array $dependencies = [], string|bool|null $version = false, array|bool $args = []): void { $GLOBALS['admin_enqueued_scripts'][] = compact('handle', 'src', 'dependencies', 'version', 'args'); }
function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
function sanitize_email(string $value): string { return filter_var($value, FILTER_VALIDATE_EMAIL) ? strtolower($value) : ''; }
define('ABSPATH', __DIR__ . '/'); define('ARRAY_A', 'ARRAY_A'); define('AUTH_KEY', 'admin-auth'); define('SECURE_AUTH_KEY', 'admin-secure');
require_once dirname(__DIR__) . '/cf7-ai-enquiry-validator.php';

admin_assert(class_exists(CF7AIV\Admin\ReviewService::class), 'Review service must exist.');
$adsPolicy = [
	'tier_definitions' => [
		['id' => 'ads_primary', 'outcome_id' => 'primary_opportunity', 'ads_eligible' => true],
		['id' => 'secondary', 'outcome_id' => 'secondary_opportunity', 'ads_eligible' => false],
		['id' => 'excluded', 'outcome_id' => 'excluded', 'ads_eligible' => false],
		['id' => 'spam', 'outcome_id' => 'spam', 'ads_eligible' => false],
	],
];
$reviewedRows = [
	['predicted_tier_id' => 'ads_primary', 'predicted_outcome_id' => 'primary_opportunity', 'reviewed_tier_id' => 'ads_primary', 'reviewed_outcome_id' => 'primary_opportunity', 'reviewed_at' => '2026-07-22 10:00:00', 'policy_snapshot' => $adsPolicy],
	['predicted_tier_id' => 'ads_primary', 'predicted_outcome_id' => 'primary_opportunity', 'reviewed_tier_id' => 'excluded', 'reviewed_outcome_id' => 'excluded', 'reviewed_at' => '2026-07-22 10:01:00', 'policy_snapshot' => $adsPolicy],
	['predicted_tier_id' => 'secondary', 'predicted_outcome_id' => 'secondary_opportunity', 'reviewed_tier_id' => 'ads_primary', 'reviewed_outcome_id' => 'primary_opportunity', 'reviewed_at' => '2026-07-22 10:02:00', 'policy_snapshot' => $adsPolicy],
	['predicted_tier_id' => 'spam', 'predicted_outcome_id' => 'spam', 'reviewed_tier_id' => 'spam', 'reviewed_outcome_id' => 'spam', 'reviewed_at' => '2026-07-22 10:03:00', 'policy_snapshot' => $adsPolicy],
	['predicted_tier_id' => 'ads_primary', 'predicted_outcome_id' => 'primary_opportunity', 'reviewed_tier_id' => null, 'reviewed_outcome_id' => null, 'reviewed_at' => null, 'policy_snapshot' => $adsPolicy],
];
$metrics = CF7AIV\Admin\CalibrationMetrics::fromRows($reviewedRows);
admin_assert($metrics['reviewed'] === 4, 'Every human-labelled row must count once.');
admin_assert($metrics['false_positives'] === 1, 'A predicted Ads opportunity corrected away is a false positive.');
admin_assert(abs($metrics['ads_precision'] - 0.5) < 0.0001, 'Ads precision must be true Ads positives divided by predicted Ads positives.');
admin_assert(($metrics['confusion_matrix']['primary_opportunity']['excluded'] ?? 0) === 1, 'Confusion matrix must retain prediction and reviewer truth.');
admin_assert(abs($metrics['exact_agreement'] - 0.5) < 0.0001 && ($metrics['per_tier']['ads_primary']['reviewed'] ?? 0) === 2, 'Agreement and per-tier counts must use only human-reviewed rows.');
$adminCalibrationSource=new class implements CF7AIV\Contracts\SubmissionSourceInterface{public function key():string{return 'test';}public function get(string $reference):?array{return null;}public function iterate(array $arguments=[]):iterable{return []; }public function available():bool{return true;}};
add_filter('cf7aiv_submission_sources',static function(array $sources)use($adminCalibrationSource):array{$sources['test']=$adminCalibrationSource;return $sources;},99);
$GLOBALS['admin_async']=[];$adminWhitelistFingerprint=CF7AIV\Admin\CalibrationPolicySnapshot::active()['fingerprint'];$_POST=['_wpnonce'=>'nonce','source'=>'test','explicit_opt_in'=>'1','date_from'=>'2026-07-01','date_to'=>'2026-07-22','whitelist_fingerprint'=>$adminWhitelistFingerprint];CF7AIV\Admin\AdminHooks::backfill();unset($_POST);
admin_assert(count($GLOBALS['admin_async'])===3&&array_sum(array_map(static fn(array $action):int=>(int)($action['args'][2]??0),$GLOBALS['admin_async']))===25&&count(array_unique(array_map(static fn(array $action):string=>(string)($action['args'][1]['calibration_run_id']??''),$GLOBALS['admin_async'])))===1,'Admin calibration must explicitly enqueue an exact 25-record stratified run instead of broad source iteration.');
$calibrationHook=CF7AIV\Hooks::class.'::AFTER_CALIBRATION_SCHEDULED';
admin_assert(defined($calibrationHook)&&in_array(constant($calibrationHook),array_column($GLOBALS['admin_fired'],0),true),'Successful calibration scheduling must fire the additive cf7aiv_after_calibration_scheduled lifecycle action.');
$GLOBALS['admin_fired']=[];$GLOBALS['admin_throw_action']=constant($calibrationHook);$observerEnqueues=[];
$observerCalibration=new CF7AIV\Admin\CalibrationService(
	static fn():array=>['test'=>$adminCalibrationSource],
	static fn():array=>[],
	static function(string $hook,array $arguments)use(&$observerEnqueues):int{$observerEnqueues[]=compact('hook','arguments');return count($observerEnqueues);},
	static fn():string=>'observer-run',
);
$observerThrew=false;try{$observerScheduled=$observerCalibration->schedule('test',['explicit_opt_in'=>true,'form_ids'=>[],'date_from'=>'2026-07-01','date_to'=>'2026-07-22','maximum'=>25],77);}catch(Throwable){$observerThrew=true;$observerScheduled=0;}
$observerCalibrationCalls=array_values(array_filter($GLOBALS['admin_fired'],static fn(array $fired):bool=>$fired[0]===constant(CF7AIV\Hooks::class.'::AFTER_CALIBRATION_SCHEDULED')));
admin_assert(!$observerThrew&&$observerScheduled===25&&count($observerEnqueues)===3,'A throwing calibration observer must not fail or re-enqueue an already scheduled three-stratum run.');
admin_assert(count($observerCalibrationCalls)===1,'Calibration must fire its lifecycle action exactly once after every stratum is scheduled.');
$GLOBALS['admin_throw_action']=null;
$GLOBALS['admin_fired']=[];$zeroEnqueues=0;$zeroThrew=false;
$zeroCalibration=new CF7AIV\Admin\CalibrationService(
	static fn():array=>['test'=>$adminCalibrationSource],
	static fn():array=>[],
	static function(string $hook,array $arguments)use(&$zeroEnqueues):int{$zeroEnqueues++;return 0;},
	static fn():string=>'zero-run',
);
try{$zeroCalibration->schedule('test',['explicit_opt_in'=>true,'form_ids'=>[],'date_from'=>'2026-07-01','date_to'=>'2026-07-22','maximum'=>25],77);}catch(RuntimeException){$zeroThrew=true;}
admin_assert($zeroThrew&&$zeroEnqueues===1&&!in_array(CF7AIV\Hooks::AFTER_CALIBRATION_SCHEDULED,array_column($GLOBALS['admin_fired'],0),true),'A zero Action Scheduler ID must fail immediately without counting quota or firing calibration completion.');
$GLOBALS['admin_fired']=[];$partialResults=[101,0];$partialEnqueues=[];$partialCancels=[];$partialThrew=false;
$partialCalibration=new CF7AIV\Admin\CalibrationService(
	static fn():array=>['test'=>$adminCalibrationSource],
	static fn():array=>[],
	static function(string $hook,array $arguments)use(&$partialResults,&$partialEnqueues):int{$partialEnqueues[]=compact('hook','arguments');return (int)array_shift($partialResults);},
	static fn():string=>'partial-run',
	static function(string $hook,array $arguments)use(&$partialCancels):void{$partialCancels[]=compact('hook','arguments');},
);
try{$partialCalibration->schedule('test',['explicit_opt_in'=>true,'form_ids'=>[],'date_from'=>'2026-07-01','date_to'=>'2026-07-22','maximum'=>25],77);}catch(RuntimeException){$partialThrew=true;}
admin_assert($partialThrew&&count($partialEnqueues)===2&&count($partialCancels)===1,'A partial calibration enqueue failure must cancel already queued strata and report failure.');
admin_assert(!in_array(CF7AIV\Hooks::AFTER_CALIBRATION_SCHEDULED,array_column($GLOBALS['admin_fired'],0),true),'A partially rolled-back calibration run must never fire its completion action.');

final class AdminMemoryReviewStore implements CF7AIV\Admin\ReviewStoreInterface {
	public array $updates = []; public array $suggestions = []; public array $dispatchAudits = []; public bool $failApproval = false;
	public function __construct(public array $items) {}
	public function get(int $itemId): ?array { return $this->items[$itemId] ?? null; }
	public function persist(int $itemId, string $reviewState, ?string $reviewedTierId, ?string $reviewedOutcomeId, int $administratorId,int $expectedRevision): bool {
		if($expectedRevision!==(int)($this->items[$itemId]['review_revision']??0)){return false;}
		$this->updates[] = compact('itemId','reviewState','reviewedTierId','reviewedOutcomeId','administratorId');
		$this->items[$itemId]['review_state'] = $reviewState;
		$this->items[$itemId]['review_revision'] = (int) ($this->items[$itemId]['review_revision'] ?? 0) + 1;
		if ($reviewedTierId !== null && $reviewedOutcomeId !== null) {
			$this->items[$itemId]['tier_id'] = $reviewedTierId;
			$this->items[$itemId]['outcome_id'] = $reviewedOutcomeId;
			$this->items[$itemId]['reviewed_tier_id'] = $reviewedTierId;
			$this->items[$itemId]['reviewed_outcome_id'] = $reviewedOutcomeId;
			$this->items[$itemId]['reviewed_at'] = '2026-07-22 12:00:00';
		}
		return true;
	}
	public function suggest(int $itemId, int $administratorId, string $previousTier, string $correctedTier, string $exampleSummary): void { $this->suggestions[] = compact('itemId','administratorId','previousTier','correctedTier','exampleSummary'); }
	public function approveDispatch(int $itemId,int $administratorId,string $reviewedTierId,string $reviewedOutcomeId,int $expectedRevision):bool{if($this->failApproval||$expectedRevision!==(int)($this->items[$itemId]['review_revision']??0)){return false;}$this->dispatchAudits[]=compact('itemId','administratorId','reviewedTierId','reviewedOutcomeId','expectedRevision');$this->items[$itemId]=array_merge($this->items[$itemId],['review_state'=>'approved_dispatch','reviewed_by'=>$administratorId,'reviewed_at'=>'2026-07-22 12:00:00','reviewed_tier_id'=>$reviewedTierId,'reviewed_outcome_id'=>$reviewedOutcomeId,'tier_id'=>$reviewedTierId,'outcome_id'=>$reviewedOutcomeId,'review_revision'=>$expectedRevision+1,'dispatch_approval_id'=>str_repeat('a',64)]);return true;}
}
$baseItem = ['id'=>1,'tier_id'=>'care_information','outcome_id'=>'secondary_opportunity','predicted_tier_id'=>'care_information','predicted_outcome_id'=>'secondary_opportunity','created_at'=>'2026-07-22 10:00:00','is_shadow'=>0,'is_calibration'=>0,'is_historical'=>0,'review_state'=>'review'];
$store = new AdminMemoryReviewStore([1=>$baseItem,2=>array_merge($baseItem,['id'=>2,'is_calibration'=>1]),3=>array_merge($baseItem,['id'=>3,'is_historical'=>1]),4=>array_merge($baseItem,['id'=>4,'is_shadow'=>1])]); $dispatches=[];
$live = new CF7AIV\Admin\ReviewService($store, static fn():array=>['automation'=>['mode'=>'live']], static function(int $id) use (&$dispatches):int{$dispatches[]=$id;return count($dispatches);}, static fn():int=>strtotime('2026-07-22 12:00:00Z'));
$changed = $live->apply(1,'change_tier',['tier_id'=>'high_value_care','outcome_id'=>'primary_opportunity','example_summary'=>'Specific current residential-care need.'],77);
admin_assert(!$changed->dispatched && $store->suggestions[0]['exampleSummary']==='Specific current residential-care need.', 'Tier corrections must create administrator-authored labelled draft suggestions without dispatch.');
admin_assert($store->items[1]['predicted_tier_id']==='care_information'&&$store->items[1]['predicted_outcome_id']==='secondary_opportunity'&&$store->items[1]['tier_id']==='high_value_care'&&$store->items[1]['outcome_id']==='primary_opportunity'&&$store->items[1]['reviewed_tier_id']==='high_value_care'&&$store->items[1]['reviewed_outcome_id']==='primary_opportunity','A correction must preserve immutable prediction while writing reviewer truth and the effective delivery result.');
$confirmedStore = new AdminMemoryReviewStore([1=>$baseItem]);
(new CF7AIV\Admin\ReviewService($confirmedStore))->apply(1,'confirm',[],77);
admin_assert($confirmedStore->items[1]['reviewed_tier_id']==='care_information'&&$confirmedStore->items[1]['reviewed_outcome_id']==='secondary_opportunity','Confirmation must copy the immutable prediction into reviewer truth.');
$live->apply(1,'approve_no_send',[],77); admin_assert($dispatches===[], 'Approve without send must never dispatch.');
$approved = $live->apply(1,'approve_dispatch',[],77); admin_assert($approved->dispatched && $dispatches===[1], 'Eligible direct approval may dispatch in live mode.');
admin_assert($approved->state==='approved_dispatch'&&$store->items[1]['review_state']==='approved_dispatch'&&count($store->dispatchAudits)===1,'Approve-and-dispatch must persist a distinct review state and a separate auditable administrator dispatch record.');
admin_assert($store->items[1]['tier_id']==='high_value_care'&&$store->items[1]['outcome_id']==='primary_opportunity'&&$store->items[1]['reviewed_tier_id']==='high_value_care'&&$store->items[1]['reviewed_outcome_id']==='primary_opportunity','Approval after a correction must preserve reviewer truth and the corrected effective delivery result.');
$failedApprovalStore=new AdminMemoryReviewStore([1=>$baseItem]);$failedApprovalStore->failApproval=true;$GLOBALS['admin_fired']=[];
$failedApproval=(new CF7AIV\Admin\ReviewService($failedApprovalStore,static fn():array=>['automation'=>['mode'=>'live']],static fn(int $id):int=>1))->apply(1,'approve_dispatch',[],77);
$failedApprovalHook=end($GLOBALS['admin_fired']);
admin_assert(!$failedApproval->dispatched&&$failedApproval->state==='review'&&$failedApprovalStore->items[1]['review_state']==='review','A failed atomic approval must return the actual current review state.');
admin_assert(($failedApprovalHook[0]??'')===CF7AIV\Hooks::AFTER_REVIEW&&(($failedApprovalHook[1][3]??null)?->state??'')==='review','cf7aiv_after_review must never publish approved_dispatch when the atomic approval failed.');
$twoTabStore=new AdminMemoryReviewStore([1=>array_merge($baseItem,['review_state'=>'rejected','review_revision'=>1])]);$twoTabDispatches=[];
$twoTabDecision=(new CF7AIV\Admin\ReviewService($twoTabStore,static fn():array=>['automation'=>['mode'=>'live']],static function(int $id)use(&$twoTabDispatches):int{$twoTabDispatches[]=$id;return 1;}))->apply(1,'approve_dispatch',['review_revision'=>'0'],77);
admin_assert(!$twoTabDecision->dispatched&&$twoTabDispatches===[]&&$twoTabStore->items[1]['review_state']==='rejected','A stale rev0 form must not approve the same-tuple row after another tab records revision 1.');
foreach([false,0,-1] as $queueFailure){
	$queueFailureStore=new AdminMemoryReviewStore([1=>$baseItem]);
	$queueDecision=(new CF7AIV\Admin\ReviewService($queueFailureStore,static fn():array=>['automation'=>['mode'=>'live']],static fn(int $id):bool|int=>$queueFailure))->apply(1,'approve_dispatch',[],77);
	admin_assert(!$queueDecision->dispatched,"Review dispatch result {$queueFailure} must be treated as queue failure.");
}
$shadowOrigin=$live->apply(4,'approve_dispatch',[],77);$live->applyMany([2,3,4],'approve_dispatch',[],77); admin_assert(!$shadowOrigin->dispatched&&$dispatches===[1], 'Direct and bulk approval must never dispatch an item originally classified in shadow mode after settings switch live.');
$shadowStore = new AdminMemoryReviewStore([1=>$baseItem]); $shadowDispatch=[];
$shadow = new CF7AIV\Admin\ReviewService($shadowStore, static fn():array=>['automation'=>['mode'=>'shadow']], static function(int $id) use (&$shadowDispatch):void{$shadowDispatch[]=$id;});
admin_assert(!$shadow->apply(1,'approve_dispatch',[],77)->dispatched && $shadowDispatch===[], 'Shadow mode must block direct approve-and-dispatch.');
$GLOBALS['admin_async']=[];
$shadow->apply(1,'reanalyse',[],77);
admin_assert(($GLOBALS['admin_async'][0]['hook']??'')==='cf7aiv_classify_item'&&($GLOBALS['admin_async'][0]['args']??[])===[1,true],'Administrator reanalysis must enqueue the explicit prediction-replacement marker.');
final class AdminReviewWpdb {
	public string $prefix='wp_'; public array $updates=[];
	public function __construct(public array $row) {}
	public function prepare(string $sql,mixed ...$args):string{return $sql;}
	public function get_row(string $sql,mixed $format=null):array{return $this->row;}
	public function update(string $table,array $data,array $where):int{$this->updates[]=compact('table','data','where');$this->row=array_merge($this->row,$data);return 1;}
}
$failedUiRow=array_merge($baseItem,['id'=>70]);
$GLOBALS['wpdb']=new AdminReviewWpdb($failedUiRow);$_POST=['_wpnonce'=>'nonce','item_id'=>70,'review_action'=>'approve_dispatch','review_revision'=>'0'];
CF7AIV\Admin\AdminHooks::review();unset($_POST);
admin_assert(str_contains((string)($GLOBALS['admin_redirect']??''),'review_error=1')&&str_contains((string)$GLOBALS['admin_redirect'],'review_state=review'),'Failed approve-and-dispatch administration must redirect with an honest current-state error.');
$missingRevisionRejected=false;try{$_POST=['_wpnonce'=>'nonce','item_id'=>70,'review_action'=>'reject'];CF7AIV\Admin\AdminHooks::review();}catch(InvalidArgumentException){$missingRevisionRejected=true;}unset($_POST);
admin_assert($missingRevisionRejected,'Single review administration must reject a request without the revision that was rendered.');
$missingBulkRevisionRejected=false;try{$_POST=['_wpnonce'=>'nonce','item_ids'=>[70],'review_action'=>'reject','review_revisions'=>[]];CF7AIV\Admin\AdminHooks::bulkReview();}catch(InvalidArgumentException){$missingBulkRevisionRejected=true;}unset($_POST);
admin_assert($missingBulkRevisionRejected,'Bulk review administration must require one rendered revision per selected item.');
$correctedAudit=['id'=>71,'predicted_tier_id'=>'care_information','predicted_outcome_id'=>'secondary_opportunity','tier_id'=>'high_value_care','outcome_id'=>'primary_opportunity','reviewed_tier_id'=>'high_value_care','reviewed_outcome_id'=>'primary_opportunity','review_state'=>'corrected','reviewed_by'=>12,'reviewed_at'=>'2026-07-21 09:00:00','created_at'=>'2026-07-20 09:00:00'];
$GLOBALS['wpdb']=new AdminReviewWpdb($correctedAudit);$realReanalysisQueue=[];
$realReviewService=new CF7AIV\Admin\ReviewService(new CF7AIV\Admin\WpReviewStore(),null,null,null,static function(int $id)use(&$realReanalysisQueue):void{$realReanalysisQueue[]=$id;});
$realReviewService->apply(71,'reanalyse',[],77);
admin_assert($GLOBALS['wpdb']->row===$correctedAudit&&$GLOBALS['wpdb']->updates===[]&&$realReanalysisQueue===[71],'Requesting reanalysis through the real review store must not rewrite corrected human audit or effective fields.');
$unreviewedAudit=['id'=>72,'predicted_tier_id'=>'care_information','predicted_outcome_id'=>'secondary_opportunity','tier_id'=>'care_information','outcome_id'=>'secondary_opportunity','reviewed_tier_id'=>null,'reviewed_outcome_id'=>null,'review_state'=>'review','reviewed_by'=>null,'reviewed_at'=>null,'created_at'=>'2026-07-22 09:00:00'];
$GLOBALS['wpdb']=new AdminReviewWpdb($unreviewedAudit);$realReviewService=new CF7AIV\Admin\ReviewService(new CF7AIV\Admin\WpReviewStore(),null,null,null,static function(int $id):void{});
$realReviewService->apply(72,'reanalyse',[],77);
admin_assert($GLOBALS['wpdb']->row===$unreviewedAudit&&$GLOBALS['wpdb']->updates===[],'Requesting reanalysis must leave an unreviewed record unreviewed and must not attribute a human review to the requesting administrator.');
$unsafe=false; try{$live->apply(1,'change_tier',['tier_id'=>'high_value_care','outcome_id'=>'primary_opportunity','example_summary'=>'Email me at private@example.test'],77);}catch(InvalidArgumentException){$unsafe=true;} admin_assert($unsafe,'Corrections must reject identifying example text rather than copying source-like content.');
$oldStore = new AdminMemoryReviewStore([4=>array_merge($baseItem,['id'=>4,'created_at'=>'2026-07-18 10:00:00'])]); $old = new CF7AIV\Admin\ReviewService($oldStore, static fn():array=>['automation'=>['mode'=>'live']], null, static fn():int=>strtotime('2026-07-22 12:00:00Z'));
admin_assert(count($old->timingWarnings(4))===2, 'Review detail must warn after GA 24-hour and 72-hour timing limits.');
admin_assert(in_array(CF7AIV\Hooks::AFTER_REVIEW,array_column($GLOBALS['admin_fired'],0),true), 'Persisted review actions must fire cf7aiv_after_review.');

$sanitized=CF7AIV\Admin\SettingsSanitizer::sanitize(['automation'=>['mode'=>'invalid','verification_mode'=>'invalid','minimum_confidence'=>9],'calibration'=>['default_sample_size'=>9999],'setup'=>['stage'=>'invalid'],'ui'=>['advanced_mode'=>'1'],'privacy'=>['enhanced_matching'=>'1','retention_days'=>99999],'consent'=>['mode'=>'invalid'],'digest'=>['enabled'=>'1','email'=>'Admin@Example.test'],'providers'=>['deepseek_api_key'=>'SECRET']]);
admin_assert($sanitized['automation']['mode']==='shadow' && $sanitized['automation']['verification_mode']==='off' && !array_key_exists('minimum_confidence',$sanitized['automation']) && $sanitized['calibration']['default_sample_size']===500 && $sanitized['setup']['stage']==='setup' && $sanitized['ui']['advanced_mode']===true, 'Settings must omit model-confidence automation and sanitize schema-v8 defaults.');
admin_assert($sanitized['privacy']['retention_days']===3650 && $sanitized['consent']['mode']==='respect', 'Settings must bound retention and reject invalid consent mode.');
admin_assert(!str_contains(json_encode($sanitized),'SECRET') && !isset($sanitized['providers']['deepseek_api_key']), 'Sanitized/renderable settings must never contain secrets.');
$craftedLive=CF7AIV\Admin\SettingsSanitizer::sanitize(['automation'=>['mode'=>'live'],'destinations'=>['ga4'=>['live_enabled'=>'1','debug_validated'=>'1']]]);
admin_assert($craftedLive['automation']['mode']==='shadow'&&empty($craftedLive['destinations']['ga4']['live_enabled'])&&empty($craftedLive['destinations']['ga4']['debug_validated']),'Ordinary settings sanitization must never authorize automation-live or GA server-controlled transition state.');
$serverGtmDefaults=CF7AIV\Infrastructure\Settings::defaults()['destinations']['server_gtm']??[];
admin_assert(($serverGtmDefaults['enabled']??null)===false&&($serverGtmDefaults['dry_run']??null)===true,'Existing-sGTM must be disabled and local dry-run by default.');
$serverGtmSanitized=CF7AIV\Admin\SettingsSanitizer::sanitize(['destinations'=>['server_gtm'=>['enabled'=>'1','dry_run'=>'1','endpoint_url'=>'https://metrics.example.test/collect','measurement_id'=>' G-TEST<script> ']]]);
admin_assert(
	($serverGtmSanitized['destinations']['server_gtm']['enabled']??false)===true
	&&($serverGtmSanitized['destinations']['server_gtm']['dry_run']??false)===true
	&&($serverGtmSanitized['destinations']['server_gtm']['endpoint_url']??'')==='https://metrics.example.test/collect'
	&&($serverGtmSanitized['destinations']['server_gtm']['measurement_id']??'')==='G-TEST',
	'Existing-sGTM settings must retain only bounded HTTPS endpoint and measurement configuration.'
);
$serverGtmInvalid=CF7AIV\Admin\SettingsSanitizer::sanitize(['destinations'=>['server_gtm'=>['endpoint_url'=>'http://metrics.example.test?secret=bad','measurement_id'=>str_repeat('G',200)]]]);
admin_assert(($serverGtmInvalid['destinations']['server_gtm']['endpoint_url']??'')===''&&strlen((string)($serverGtmInvalid['destinations']['server_gtm']['measurement_id']??''))<=100,'Existing-sGTM settings must reject non-HTTPS endpoints and bound identifiers.');
$serverGtmSecrets=CF7AIV\Admin\SettingsSanitizer::secrets(['secrets'=>['server_gtm_api_secret'=>'write-only-sgtm-secret']]);
admin_assert(($serverGtmSecrets['server_gtm_api_secret']??'')==='write-only-sgtm-secret','Existing-sGTM API secret must use the write-only secret store.');
admin_assert(CF7AIV\Admin\AdminSafe::log('token=secret Person@example.test')==='token=[redacted] [REDACTED_EMAIL]','Admin logs must redact credential-like values and identity before rendering.');
$invalidPolicy=false; try{CF7AIV\Admin\PolicyLab::import('{"schema":"wrong"}');}catch(InvalidArgumentException){$invalidPolicy=true;} admin_assert($invalidPolicy,'Policy Lab must surface policy import errors.');

final class AdminTestSource implements CF7AIV\Contracts\SubmissionSourceInterface { public function key():string{return 'test';} public function get(string $reference):?array{return null;} public function iterate(array $arguments=[]):iterable{yield ['reference'=>'a','form_id'=>9,'fields'=>['message'=>'PRIVATE']];yield ['reference'=>'b','form_id'=>9,'fields'=>['email'=>'private@example.test']];} }
$backfilled=[]; $backfill=new CF7AIV\Admin\BackfillService(static function(array $item)use(&$backfilled):int{$backfilled[]=$item;return count($backfilled);}); $backfill->run(new AdminTestSource(),['calibration_run_id'=>'admin-test-run','quota'=>50],50);
admin_assert(count($backfilled)===2 && $backfilled[0]['is_calibration'] && $backfilled[0]['is_historical'], 'Historical backfill must always set calibration and historical flags.');
admin_assert(!str_contains(json_encode($backfilled),'PRIVATE')&&!str_contains(json_encode($backfilled),'private@example.test'), 'Backfill must not copy raw source fields into plugin records.');

final class AdminOpsWpdb {
	public string $prefix='wp_'; public array $queries=[]; public array $renderRows=[]; public array $qualityRows=[]; public array $metricRows=[]; public array $deliveryRows=[]; public array $acceptedSuggestions=[]; public array $pendingSuggestions=[]; public bool $draftPolicy=true; public bool $publishedV2=false; public array $mappingRow=['id'=>1,'status'=>'draft','enabled'=>0];
	public function prepare(string $sql,mixed ...$args):string{$this->queries[]=['prepare',$sql,$args];return $sql;}
	public function query(string $sql):int{$this->queries[]=['query',$sql];if(str_contains($sql,"SET status='published'")){$this->mappingRow['status']='published';$this->mappingRow['enabled']=0;return 1;}if(str_contains($sql,'SET enabled=CASE')){$this->mappingRow['enabled']=1;}if(str_contains($sql,'SET enabled=0')&&str_contains($sql,'WHERE id=')){$this->mappingRow['enabled']=0;}return 2;}
	public function get_row(string $sql,mixed $format=null):?array{$this->queries[]=['get_row',$sql];return str_contains($sql,'cf7aiv_mappings')?$this->mappingRow:null;}
	public function get_results(string $sql,mixed $format=null):array{if(str_contains($sql,'cf7aiv_policies')&&str_contains($sql,"status='published'")){$definition=CF7AIV\Classification\StarterPolicy::definition();$definition['ai_whitelist']=['actual-message','actual-subject'];return [['definition_json'=>json_encode($definition)]];}if(str_contains($sql,"cf7aiv_setup_suggestions")&&str_contains($sql,"status='accepted'")){return $this->acceptedSuggestions;}if(str_contains($sql,"cf7aiv_setup_suggestions")&&str_contains($sql,"status='pending'")){return $this->pendingSuggestions;}if(str_contains($sql,'SELECT id,destination_key,state,last_error,updated_at FROM wp_cf7aiv_deliveries')){return $this->deliveryRows;}if(str_contains($sql,'SELECT state AS label')){return $this->metricRows['state']??[];}if(str_contains($sql,'SELECT tier_id AS label')){return $this->metricRows['tier_id']??[];}if(str_contains($sql,'SELECT reason_code AS label')){return $this->metricRows['reason_code']??[];}if(str_contains($sql,'reviewed_at IS NOT NULL')){return $this->qualityRows;}if(str_contains($sql,'FROM wp_cf7aiv_items item')){return $this->renderRows;}if(str_contains($sql,'provider_usage_json')){return array_map(static fn(array $row):array=>['provider_usage_json'=>$row['provider_usage_json']??null],$this->renderRows);}return [];}
	public function get_var(string $sql):mixed{if(str_contains($sql,"status='draft'")&&str_contains($sql,'cf7aiv_policies')){return $this->draftPolicy?1:0;}if(str_contains($sql,"status='published'")&&str_contains($sql,"cf7aiv-policy/v2")){return $this->publishedV2?1:0;}return 0;}
	public function insert(string $table,array $data):bool{$this->queries[]=['insert',$table,$data];return true;}
	public function update(string $table,array $data,array $where):int{$this->queries[]=['update',$table,$data,$where];return 1;}
}
$GLOBALS['wpdb']=new AdminOpsWpdb(); $removed=(new CF7AIV\Admin\RetentionService())->cleanup(90); $sql=json_encode($GLOBALS['wpdb']->queries);
admin_assert($removed>=0 && str_contains($sql,'cf7aiv_review_suggestions') && str_contains($sql,'cf7aiv_deliveries') && str_contains($sql,'cf7aiv_items') && str_contains($sql,'cf7aiv_calibration_runs') && strpos($sql,'cf7aiv_review_suggestions')<strpos($sql,'cf7aiv_items') && !str_contains($sql,'flamingo_inbound_messages') && !str_contains($sql,'db7_forms'), 'Retention must remove review suggestions and deliveries before plugin items, remove expired calibration runs, and never source rows.');
$GLOBALS['admin_fired']=[]; $health=(new CF7AIV\Admin\HealthService(static fn():array=>['test'=>new AdminTestSource()]))->inspect();
admin_assert(isset($health['sources']['test']) && !in_array('cf7aiv_evaluate_delivery',array_column($GLOBALS['admin_fired'],0),true), 'Health checks must be read-only and never enqueue/create a conversion.');
$mail=[]; $disabledDigest=new CF7AIV\Admin\DigestService(static function(...$args)use(&$mail):void{$mail[]=$args;},static fn():array=>['digest'=>['enabled'=>false]]); admin_assert(!$disabledDigest->send()&&$mail===[],'Daily digest must send no email unless enabled.');
$enabledDigest=new CF7AIV\Admin\DigestService(static function(...$args)use(&$mail):void{$mail[]=$args;},static fn():array=>['digest'=>['enabled'=>true,'email'=>'admin@example.test']]); admin_assert($enabledDigest->send()&&count($mail)===1,'Enabled daily digest must send a pending/paused/permanent-failure summary.');

$GLOBALS['admin_allowed']=false; $blocked=false; try{CF7AIV\Admin\AdminRequestGuard::authorize('nonce','cf7aiv_review');}catch(RuntimeException){$blocked=true;} admin_assert($blocked,'Admin actions must reject users without manage_options.');
$GLOBALS['admin_allowed']=true;$GLOBALS['admin_nonce_valid']=false;$blocked=false;try{CF7AIV\Admin\AdminRequestGuard::authorize('bad','cf7aiv_review');}catch(RuntimeException){$blocked=true;}admin_assert($blocked,'Admin actions must reject invalid nonces.');
$GLOBALS['admin_nonce_valid']=true; CF7AIV\Admin\AdminHooks::menu();
admin_assert(count($GLOBALS['admin_menus'])===1&&count($GLOBALS['admin_submenus'])>=6,'Admin must register one top-level menu and functional subpages.');
$GLOBALS['wpdb']->renderRows=[['id'=>91,'tier_id'=>'ads_primary','outcome_id'=>'primary_opportunity','reason_code'=>'qualified','confidence'=>'0.9876','policy_version'=>'v2','source_type'=>'flamingo','source_reference'=>'private-source-reference','attribution_ready'=>1,'consent_encrypted'=>'','review_state'=>'review','review_revision'=>7,'decision_state'=>'strong_match','evidence_json'=>'[{"code":"service_fit"}]','missing_evidence_json'=>'[]','ambiguity_json'=>'[]','provider_usage_json'=>'{"input_tokens":12,"output_tokens":8,"reasoning_tokens":3,"calls":2}','created_at'=>'2026-07-22 10:00:00','destination_status'=>'not sent']];
$GLOBALS['wpdb']->qualityRows=[];
$GLOBALS['wpdb']->metricRows=['state'=>[['label'=>'review','total'=>1]],'tier_id'=>[['label'=>'ads_primary','total'=>1]],'reason_code'=>[['label'=>'qualified','total'=>1]]];
$GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]=array_replace_recursive(CF7AIV\Infrastructure\Settings::defaults(),['providers'=>['selected'=>'deepseek']]);
ob_start();CF7AIV\Admin\AdminPages::overview();$overview=ob_get_clean();admin_assert(str_contains($overview,'AI Enquiry Validator')&&str_contains($overview,'Overview')&&str_contains($overview,'Attribution readiness')&&str_contains($overview,'Queue age')&&str_contains($overview,'Paused destinations'),'Overview screen must render setup/shadow, attribution, queue, and destination metrics.');
admin_assert(! str_contains($overview, '<pre>'), 'Overview must not expose raw JSON presentation.');
admin_assert(str_contains($overview, 'cf7aiv-page-header') && str_contains($overview, 'cf7aiv-metric-grid'), 'Overview must use the shared header and metric grid.');
admin_assert(str_contains($overview, '<caption>Counts by state</caption>') && str_contains($overview, '<caption>Counts by tier</caption>') && str_contains($overview, '<caption>Counts by reason</caption>'), 'Overview distributions must render as separate semantic tables.');
admin_assert(str_contains($overview, 'No human-reviewed enquiries yet.'), 'Zero reviewed data must render an explanatory empty state.');
admin_assert(str_contains($overview, 'No destinations are paused.'), 'No paused destinations must render an explicit empty state.');
admin_assert(str_contains($overview, '14 hours 34 minutes') || str_contains($overview, 'No pending queue'), 'Queue age must be human-readable rather than raw seconds.');

$GLOBALS['wpdb']->metricRows=[
	'state'=>[['label'=>'<script>alert(1)</script>','total'=>2]],
	'tier_id'=>[['label'=>'ads_primary','total'=>3]],
	'reason_code'=>[['label'=>'<reason>','total'=>1]],
];
$populatedQualityRows=$reviewedRows;
$populatedQualityRows[]=['predicted_tier_id'=>'spam','predicted_outcome_id'=>'<matrix-predicted>','reviewed_tier_id'=>'spam','reviewed_outcome_id'=>'<matrix-reviewed>','reviewed_at'=>'2026-07-22 10:04:00','policy_snapshot'=>$adsPolicy];
$GLOBALS['wpdb']->qualityRows=$populatedQualityRows;
$GLOBALS['admin_options']['cf7aiv_paused_destinations']=['<paused-destination>'=>'private implementation value'];
ob_start();CF7AIV\Admin\AdminPages::overview();$populatedOverview=ob_get_clean();
admin_assert(str_contains($populatedOverview,'&lt;script&gt;alert(1)&lt;/script&gt;')&&!str_contains($populatedOverview,'<script>alert(1)</script>')&&str_contains($populatedOverview,'&lt;reason&gt;'),'Overview distribution labels must remain escaped in semantic tables.');
admin_assert(str_contains($populatedOverview,'<caption>Reviewed confusion matrix</caption>')&&str_contains($populatedOverview,'<caption>Reviewed per-tier counts</caption>')&&str_contains($populatedOverview,'ads_primary (predicted)</th><td>2</td>')&&str_contains($populatedOverview,'<th scope="col">excluded</th>')&&str_contains($populatedOverview,'<th scope="row">primary_opportunity</th><td>0</td><td>0</td><td>1</td><td>1</td><td>0</td><td>0</td>')&&str_contains($populatedOverview,'&lt;matrix-predicted&gt;')&&str_contains($populatedOverview,'&lt;matrix-reviewed&gt;')&&!str_contains($populatedOverview,'<matrix-predicted>'),'Overview quality must render escaped matrix union headers, integer counts, and per-tier counts.');
admin_assert(str_contains($populatedOverview,'&lt;paused-destination&gt;')&&str_contains($populatedOverview,'>Paused<')&&!str_contains($populatedOverview,'private implementation value'),'Paused destinations must render only escaped names and the Paused status label.');
admin_assert(str_contains($populatedOverview,'Human-reviewed quality')&&str_contains($populatedOverview,'2 of 5')&&str_contains($populatedOverview,'<th scope="row">False positives</th><td>1</td>')&&!str_contains($populatedOverview,'Review precision')&&!str_contains(strtolower($populatedOverview),'unreviewed accuracy'),'Overview percentages must pair counts with human-reviewed quality labels and Ads-policy false positives.');
$GLOBALS['admin_options']['cf7aiv_paused_destinations']=['list-paused-destination'];
ob_start();CF7AIV\Admin\AdminPages::overview();$listPausedOverview=ob_get_clean();
admin_assert(str_contains($listPausedOverview,'<th scope="row">list-paused-destination</th><td>Paused</td>'),'Paused destinations must accept list-shaped options without exposing their indexes.');

final class AdminHealthSource implements CF7AIV\Contracts\SubmissionSourceInterface {
	public function __construct(private string $sourceKey, private bool $isAvailable, private ?int $recordCount = null) {}
	public function key(): string { return $this->sourceKey; }
	public function get(string $reference): ?array { return null; }
	public function iterate(array $arguments = []): iterable { return []; }
	public function available(): bool { return $this->isAvailable; }
	public function count(): ?int { return $this->recordCount; }
}
$GLOBALS['admin_health_sources']=[
	'available-source'=>new AdminHealthSource('available-source',true,4),
	'unavailable-source'=>new AdminHealthSource('unavailable-source',false),
];
add_filter('cf7aiv_submission_sources',static fn(array $sources):array=>$GLOBALS['admin_health_sources'],100);
$GLOBALS['admin_options']['cf7aiv_paused_destinations']=['paused-destination'=>'private implementation value'];
$GLOBALS['wpdb']->deliveryRows=[
	['id'=>21,'destination_key'=>'ga4','state'=>'failed','last_error'=>'<script>alert(1)</script>','updated_at'=>'2026-07-22 10:05:00'],
	['id'=>22,'destination_key'=>'webhook','state'=>'pending','last_error'=>'Temporary connection issue','updated_at'=>'2026-07-22 10:04:00'],
];
ob_start();CF7AIV\Admin\AdminPages::health();$healthPage=ob_get_clean();
admin_assert(! str_contains($healthPage, '<pre>'), 'Tracking Health must not expose raw JSON presentation.');
admin_assert(str_contains($healthPage, '<caption>Submission source health</caption>'), 'Source health must use a semantic table.');
admin_assert(str_contains($healthPage, 'Available') && str_contains($healthPage, 'Unavailable'), 'Availability must be stated in text.');
admin_assert(str_contains($healthPage, '<caption>Read-only checks</caption>'), 'Read-only checks must be structured.');
admin_assert(str_contains($healthPage, 'cf7aiv-table-region') && str_contains($healthPage, 'aria-label="Sanitized delivery errors"'), 'Delivery logs must scroll locally in a labelled region.');
admin_assert(str_contains($healthPage, '&lt;script&gt;') && ! str_contains($healthPage, '<script>'), 'Sanitised delivery error text must remain escaped.');
admin_assert(! str_contains($healthPage, 'private implementation value'), 'Tracking Health must render only paused destination names and never associative private values.');
admin_assert(str_contains($healthPage, '<p class="cf7aiv-actions"><button type="submit" class="button">Schedule calibration</button></p></form>'), 'Tracking Health calibration submit must use the shared action target-size wrapper inside its existing form.');
$GLOBALS['admin_health_sources']=[];
$GLOBALS['admin_options']['cf7aiv_paused_destinations']=[];
$GLOBALS['wpdb']->deliveryRows=[];
ob_start();CF7AIV\Admin\AdminPages::health();$emptyHealthPage=ob_get_clean();
admin_assert(str_contains($emptyHealthPage, 'No submission sources were detected.') && str_contains($emptyHealthPage, 'No destinations are paused.') && str_contains($emptyHealthPage, 'No delivery errors were recorded.'), 'Tracking Health must render explicit empty states.');
ob_start();CF7AIV\Admin\AdminPages::policyLab();$policyPage=ob_get_clean();admin_assert(str_contains($policyPage,'Tiers')&&str_contains($policyPage,'Audiences')&&str_contains($policyPage,'Exclusions')&&str_contains($policyPage,'Field whitelist')&&str_contains($policyPage,'Calibration summary'),'Policy Lab must expose guided policy dimensions and calibration summary.');
$GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]['setup']['draft_summary']=['status'=>'draft','policy_key'=>'global','version'=>'presentation-v1'];
$GLOBALS['wpdb']->pendingSuggestions=[['id'=>1,'suggestion_type'=>'service','value_json'=>'"Presentation test"','provenance_json'=>'["starter-pack"]']];
ob_start();CF7AIV\Admin\AdminPages::policyLab();$policy=ob_get_clean();
ob_start();CF7AIV\Admin\AdminPages::setup();$setup=ob_get_clean();
admin_assert(str_contains($setup, 'cf7aiv-setup-page'), 'Setup needs a dedicated root.');
admin_assert(str_contains($setup, 'cf7aiv-setup-progress'), 'Setup needs progress navigation.');
admin_assert(substr_count($setup, 'cf7aiv-setup-step') >= 5, 'Setup needs five guided steps.');
admin_assert(str_contains($setup, 'Save guided draft'), 'Setup needs a clear draft-save action.');
$scanForm = [];
preg_match('/(<form[^>]*id="cf7aiv-setup-scan-form"[^>]*>.*?<\/form>)/s', $setup, $scanForm);
admin_assert(
	preg_match('/<select[^>]*name="pack_key"[^>]*form="cf7aiv-setup-scan-form"/', $setup) === 1
	&& isset($scanForm[1])
	&& str_contains($scanForm[1], 'value="cf7aiv_setup_scan"')
	&& ! str_contains($scanForm[1], 'data-setup-scan-pack'),
	'No-JavaScript scan submission must use the selected pack control owned by the scan form.'
);
$guidedForm = [];
preg_match('/(<form[^>]*id="cf7aiv-guided-policy-form"[^>]*>.*?<\/form>)/s', $setup, $guidedForm);
admin_assert(
	isset($guidedForm[1])
	&& preg_match('/<select[^>]*name="guided\[pack_key\]"[^>]*data-setup-guided-pack/', $guidedForm[1]) === 1
	&& ! preg_match('/<input[^>]*type="hidden"[^>]*name="guided\[pack_key\]"/', $guidedForm[1]),
	'No-JavaScript guided saving must use a current pack select owned by the guided form.'
);
foreach (['pack', 'business_description', 'services', 'audiences', 'primary_definition', 'exclusion_codes', 'ai_whitelist'] as $summaryBinding) {
	admin_assert(str_contains($setup, 'data-setup-summary="' . $summaryBinding . '"'), "Setup review must bind the {$summaryBinding} summary.");
}
foreach (['exclusion_codes', 'ai_whitelist'] as $optionGroup) {
	admin_assert(str_contains($setup, 'data-setup-option-group="' . $optionGroup . '"'), "Setup must expose the {$optionGroup} option group for review binding.");
}
admin_assert(str_contains($policy, 'cf7aiv-page-header') && str_contains($policy, 'cf7aiv-actions'), 'Policy Lab must use shared native structure.');
admin_assert(str_contains($setup, 'cf7aiv-page-header') && str_contains($setup, 'cf7aiv-actions'), 'Setup must use shared native structure.');
admin_assert(str_contains($policy, 'class="card'), 'Policy Lab must retain native card panels.');
admin_assert(str_contains($setup, 'class="card'), 'Setup must retain native card panels.');
foreach (['cf7aiv_save_guided_policy','cf7aiv_policy_publish'] as $action) {
	admin_assert(str_contains($policy, 'value="' . $action . '"'), "Policy Lab must retain {$action}.");
}
foreach (['cf7aiv_setup_scan','cf7aiv_setup_suggestion','cf7aiv_save_guided_policy','cf7aiv_lifecycle_transition'] as $action) {
	admin_assert(str_contains($setup, 'value="' . $action . '"'), "Setup must retain {$action}.");
}
admin_assert(str_contains($setup, 'explicit_opt_in') && str_contains($setup, 'whitelist_fingerprint'), 'Calibration must retain explicit opt-in and whitelist binding.');
ob_start();CF7AIV\Admin\IntegrationsPage::render();$integrations=ob_get_clean();foreach(['deepseek_api_key','openai_api_key','deepseek_base_url','openai_base_url','ga4_api_secret','microsoft_uet_token','webhook_secret','delete_on_uninstall','cf7aiv_automation_transition','cf7aiv_ga_transition','cf7aiv_mapping_publish','cf7aiv_mapping_enable','cf7aiv_mapping_disable','cf7aiv_save_consent','Return automation to shadow','Disable GA4 live / return to dry-run'] as $control){admin_assert(str_contains($integrations,$control),"Integrations must render functional control {$control}.");}admin_assert(!str_contains($integrations,'ga-secret'),'Integrations must never render stored secret values.');admin_assert(str_contains($integrations,'<div class="wrap cf7aiv-admin">'),'Integrations must use the shared admin wrapper.');foreach(['cf7aiv-mapping-key','cf7aiv-mapping-version','cf7aiv-mapping-tier','cf7aiv-mapping-destination','cf7aiv-mapping-event','cf7aiv-mapping-value','cf7aiv-mapping-currency','cf7aiv_mapping_publish-key','cf7aiv_mapping_publish-version','cf7aiv_mapping_enable-key','cf7aiv_mapping_enable-version','cf7aiv_mapping_disable-key','cf7aiv_mapping_disable-version'] as $mappingFieldId){admin_assert(str_contains($integrations,'for="'.$mappingFieldId.'"')&&str_contains($integrations,'id="'.$mappingFieldId.'"'),'Mapping fields need associated labels and stable IDs.');}foreach(['Destination mapping lifecycle','Automation lifecycle','GA4 delivery lifecycle'] as $lifecycleLegend){admin_assert(str_contains($integrations,'<legend>'.$lifecycleLegend.'</legend>'),"Integrations must group {$lifecycleLegend} controls.");}
admin_assert(str_contains($integrations, 'cf7aiv-integrations-page'), 'Integrations needs a dedicated root.');
admin_assert(str_contains($integrations, 'cf7aiv-integration-tabs'), 'Integrations needs tab navigation.');
admin_assert(str_contains($integrations, 'cf7aiv-save-bar'), 'Integrations needs a persistent save bar.');
admin_assert(str_contains($integrations, 'data-provider="deepseek"'), 'DeepSeek controls need a provider scope.');
admin_assert(str_contains($integrations, 'data-provider="openai"'), 'OpenAI controls need a provider scope.');
foreach(['cf7aiv_test_microsoft','SEND_ZERO_VALUE_MICROSOFT_TEST','Microsoft UET transport test'] as $control){admin_assert(str_contains($integrations,$control),"Integrations must render guarded Microsoft control {$control}.");}
admin_assert(substr_count($integrations, 'class="card cf7aiv-integration-panel') >= 7, 'Integration concerns must be grouped into native panels.');
admin_assert(str_contains($integrations, 'uses only a one-off synthetic anonymous ID required by CAPI'), 'Microsoft test copy must describe the required synthetic ID.');
admin_assert(! str_contains($integrations, 'identifier-free diagnostic event'), 'Microsoft test copy must not claim the event is identifier-free.');
admin_assert(str_contains($integrations, 'button button-primary') && str_contains($integrations, 'button button-secondary'), 'Integration actions must use WordPress button hierarchy.');
foreach (['cf7aiv_save_settings','cf7aiv_test_microsoft','cf7aiv_automation_transition','cf7aiv_save_consent','cf7aiv_validate_ga4','cf7aiv_ga_transition','cf7aiv_save_mapping','cf7aiv_mapping_publish','cf7aiv_mapping_enable','cf7aiv_mapping_disable'] as $action) {
	admin_assert(str_contains($integrations, 'value="' . $action . '"'), "Integrations must retain action {$action}.");
}
admin_assert(str_contains($integrations,'name="confirmation"')&&str_contains($integrations,'autocomplete="off"')&&!str_contains($integrations,'type="hidden" name="confirmation"'),'Microsoft testing must require a blank, explicitly typed confirmation rather than a prefilled hidden value.');
admin_assert(method_exists(CF7AIV\Cli\Commands::class,'microsoft_test'),'WP-CLI must expose the guarded Microsoft transport-test command.');
admin_assert(str_contains($integrations,'name="cf7aiv[ui][advanced_mode]" value="0"')&&str_contains($integrations,'name="cf7aiv[ui][advanced_mode]" value="1"')&&str_contains($integrations,'Advanced mode'),'Integrations must expose a functional Advanced-mode toggle with an unchecked value.');
admin_assert(str_contains($integrations,'Google conversion route')&&str_contains($integrations,'Recommended for most users')&&!str_contains($integrations,'server_gtm_api_secret'),'Simple Integrations must recommend direct GA4 without exposing existing-sGTM credentials.');
$microsoftPanelPosition = strpos($integrations, '<section class="card cf7aiv-integration-panel"><h2>Microsoft UET</h2>');
$webhookPanelPosition = strpos($integrations, '<section class="card cf7aiv-integration-panel"><h2>Webhook</h2>');
admin_assert(
	$microsoftPanelPosition !== false
	&& $webhookPanelPosition !== false
	&& $microsoftPanelPosition < $webhookPanelPosition,
	'Microsoft UET and webhook configuration must render as distinct native panels in their existing order.'
);
admin_assert(
	preg_match('/<section class="card cf7aiv-integration-panel"><h2>Microsoft UET<\/h2>(?:(?!<\/section>).)*name="cf7aiv\[destinations\]\[microsoft_uet\]\[tag_id\]"(?:(?!<\/section>).)*<\/section>/s', $integrations) === 1
	&& preg_match('/<section class="card cf7aiv-integration-panel"><h2>Webhook<\/h2>(?:(?!<\/section>).)*name="cf7aiv\[destinations\]\[webhook\]\[url\]"(?:(?!<\/section>).)*<\/section>/s', $integrations) === 1,
	'Microsoft UET and webhook panels must retain their exact destination field boundaries.'
);
$GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]['ui']['advanced_mode']=true;
ob_start();CF7AIV\Admin\IntegrationsPage::render();$advancedIntegrations=ob_get_clean();
admin_assert(
	str_contains($advancedIntegrations,'Existing server-side GTM')
	&&str_contains($advancedIntegrations,'server_gtm_api_secret')
	&&str_contains($advancedIntegrations,'cf7aiv[destinations][server_gtm][endpoint_url]')
	&&str_contains($advancedIntegrations,'destination key <code>server_gtm</code>')
	&&str_contains($advancedIntegrations,'does not provision Google Cloud')
	&&str_contains($advancedIntegrations,'does not use the Google Ads API'),
	'Advanced Integrations must expose a concise existing-sGTM route without suggesting Cloud or Ads API provisioning.'
);
admin_assert(str_contains($advancedIntegrations, '<details class="card cf7aiv-advanced"><summary>Provider usage diagnostics</summary>'), 'Advanced provider usage must use a native disclosure.');
$GLOBALS['admin_options']['cf7aiv_secrets']['server_gtm_api_secret']=CF7AIV\Infrastructure\Crypto::encrypt('registry-sgtm-secret');
$GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]['destinations']['server_gtm']=['enabled'=>true,'dry_run'=>true,'endpoint_url'=>'https://metrics.example.test','measurement_id'=>'G-TEST'];
$registeredDestinations=CF7AIV\Delivery\DeliveryHooks::destinations([]);
admin_assert(($registeredDestinations['server_gtm']??null) instanceof CF7AIV\Destinations\ServerGtmDestination,'Enabled existing-sGTM settings must register the concrete destination through the public registry.');
$GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]['ui']['advanced_mode']=false;
ob_start();CF7AIV\Admin\AdminPages::setup();$setup=ob_get_clean();admin_assert(str_contains($setup,'<form')&&str_contains($setup,'shadow mode'),'Setup wizard must be skippable, functional, and explicitly leave automation in shadow mode.');
ob_start();CF7AIV\Admin\AdminPages::reviewQueue();$reviewQueue=ob_get_clean();
$GLOBALS['admin_nonce_valid']=true;$_GET=['review_error'=>'1','review_state'=>'review'];ob_start();CF7AIV\Admin\AdminPages::reviewQueue();$failedReviewNotice=ob_get_clean();unset($_GET);
admin_assert(str_contains($failedReviewNotice,'Approve-and-dispatch was not completed')&&str_contains($failedReviewNotice,'review'),'Review Queue must render the failed approval and actual review state returned by administration.');
$simplePages=$setup.$policyPage.$reviewQueue.$integrations;
admin_assert(str_contains($reviewQueue,'detail_item=91')&&str_contains($reviewQueue,'Review item 91'),'Simple Review Queue must link to a sanitized per-item review control.');
admin_assert(str_contains($reviewQueue,'name="review_revisions[91]" value="7"'),'Bulk review must post the revision rendered for each selected row.');
admin_assert(str_contains($reviewQueue, 'cf7aiv-toolbar cf7aiv-filter-toolbar'), 'Review filtering must have a dedicated toolbar.');
admin_assert(str_contains($reviewQueue, 'cf7aiv-toolbar cf7aiv-bulk-toolbar'), 'Bulk review must have a separate toolbar.');
admin_assert(str_contains($reviewQueue, 'aria-label="Review queue results"') && str_contains($reviewQueue, 'tabindex="0"'), 'Review results must use a labelled local scroll region.');
$sharedAdminPages = [
	'Overview' => [$overview, '<h1>AI Enquiry Validator', 'cf7aiv-metric-grid'],
	'Review Queue' => [$reviewQueue, '<h1>Review Queue</h1>', 'cf7aiv-decision-legend'],
	'Policy Lab' => [$policy, '<h1>Policy Lab</h1>', 'cf7aiv_save_guided_policy'],
	'Integrations' => [$integrations, '<h1>Integrations</h1>', 'cf7aiv_save_settings'],
	'Tracking Health' => [$healthPage, '<h1>Tracking Health &amp; Logs</h1>', 'cf7aiv_backfill'],
	'Setup' => [$setup, '<h1>AI Enquiry Validator Setup</h1>', 'cf7aiv_setup_scan'],
];
foreach ($sharedAdminPages as $pageLabel => [$pageHtml, $heading, $workflow]) {
	admin_assert(
		str_contains($pageHtml, '<div class="cf7aiv-page-header"><h1>')
		&& str_contains($pageHtml, $heading)
		&& str_contains($pageHtml, $workflow),
		"{$pageLabel} must use the shared page header while retaining its h1 and workflow."
	);
}
admin_assert(str_contains($reviewQueue, 'Review classifications, correct outcomes, and control approved delivery.'), 'Review Queue must describe its review and delivery purpose concisely.');
$_GET=['detail_item'=>91,'_wpnonce'=>'nonce'];
ob_start();CF7AIV\Admin\AdminPages::reviewQueue();$simpleReviewDetail=ob_get_clean();unset($_GET);
admin_assert(str_contains($simpleReviewDetail,'value="change_tier"')&&str_contains($simpleReviewDetail,'name="tier_id"')&&str_contains($simpleReviewDetail,'name="outcome_id"')&&!str_contains($simpleReviewDetail,'private-source-reference')&&!str_contains($simpleReviewDetail,'Original source detail'),'Simple item detail must support tier/outcome correction without rendering raw source.');
admin_assert(str_contains($simpleReviewDetail,'name="review_revision" value="7"'),'Per-item review must post the exact revision the administrator viewed.');
admin_assert(
	preg_match('/<form method="post"[^>]*><input type="hidden" name="action" value="cf7aiv_review">.*?<p class="cf7aiv-actions"><button type="submit" class="button button-primary">Apply review<\/button><\/p><\/form>/s', $simpleReviewDetail) === 1,
	'Per-item review submit must use the shared action target-size wrapper inside its existing form.'
);
$GLOBALS['admin_nonce_valid']=false;$_GET=['detail_item'=>91,'_wpnonce'=>'bad'];
ob_start();CF7AIV\Admin\AdminPages::reviewQueue();$blockedSimpleReviewDetail=ob_get_clean();unset($_GET);$GLOBALS['admin_nonce_valid']=true;
admin_assert(!str_contains($blockedSimpleReviewDetail,'value="change_tier"'),'Simple per-item review controls must be view-nonce guarded.');
foreach(['guided[pack_key]','guided[business_description]','guided[services]','guided[audiences]','guided[primary_definition]','guided[exclusion_codes][]','guided[ai_whitelist][]','cf7aiv_save_guided_policy'] as $guidedControl){admin_assert(str_contains($setup,$guidedControl)&&str_contains($policyPage,$guidedControl),"Simple Setup and Policy Lab must share functional guided draft control {$guidedControl}.");}
foreach(['Care and supported living','Marketing, web, and design agencies','Training and education','Business consultancy','Home and professional services','B2B products and services','Custom business'] as $packLabel){admin_assert(str_contains($setup,$packLabel),"Setup must render policy pack {$packLabel}.");}
foreach(['Setup','Testing','Live','value="25"','explicit_opt_in','Strong match','Needs review','Not eligible'] as $simpleLabel){admin_assert(str_contains($simplePages,$simpleLabel),"Simple administration must render {$simpleLabel}.");}
admin_assert(!str_contains(strtolower($simplePages),'confidence')&&!str_contains($simplePages,'0.9876')&&!str_contains($simplePages,'private-source-reference'),'Simple administration must never render model confidence or raw source references.');
admin_assert(str_contains($setup,'Historical calibration')&&str_contains($setup,'calibration-only historical sample'),'Historical calibration must be a separate, explicitly consented setup panel after a draft exists.');
admin_assert(str_contains($setup,'Fields that may be sent to AI under the currently published policies')&&str_contains($setup,'actual-message, actual-subject')&&str_contains($setup,'Free text may still contain personal or sensitive information')&&str_contains($setup,'name="form_ids"')&&str_contains($setup,'name="whitelist_fingerprint"'),'Historical calibration must preview and bind the actual active published-policy whitelist, repeat the sensitive-free-text warning, and expose optional form scoping before opt-in.');
$beforeStaleWhitelistQueue=count($GLOBALS['admin_async']);$staleWhitelistRefused=false;
try{$_POST=['_wpnonce'=>'nonce','source'=>'test','explicit_opt_in'=>'1','date_from'=>'2026-07-01','date_to'=>'2026-07-22','whitelist_fingerprint'=>str_repeat('0',64)];CF7AIV\Admin\AdminHooks::backfill();}catch(InvalidArgumentException){$staleWhitelistRefused=true;}unset($_POST);
admin_assert($staleWhitelistRefused&&count($GLOBALS['admin_async'])===$beforeStaleWhitelistQueue,'Historical calibration must refuse a stale or mismatched whitelist preview without scheduling any work.');
admin_assert(str_contains($integrations,'Verify automatic conversion candidates with an additional deeper AI call. This can improve caution but uses more tokens and may increase cost.'),'A capable selected provider must expose the exact optional verification wording.');
admin_assert(str_contains($integrations,'type="hidden" name="cf7aiv[automation][verification_mode]" value="off"'),'A capable-provider verification checkbox must submit off when unchecked.');
$GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]['setup']['stage']='testing';
ob_start();CF7AIV\Admin\AdminPages::setup();$testingSetup=ob_get_clean();
admin_assert(str_contains($testingSetup,'value="live"')&&str_contains($testingSetup,'Enter Live')&&str_contains($testingSetup,'automation remains shadow'),'Testing must expose a lifecycle-only Live transition that does not enable automation.');
$GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]['setup']['stage']='live';
ob_start();CF7AIV\Admin\AdminPages::setup();$liveSetup=ob_get_clean();
admin_assert(str_contains($liveSetup,'value="testing"')&&str_contains($liveSetup,'Return to Testing'),'Live must expose a return-to-Testing lifecycle control.');
$GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]['automation']['mode']='live';
ob_start();CF7AIV\Admin\AdminPages::setup();$liveAutomationSetup=ob_get_clean();
admin_assert(str_contains($liveAutomationSetup,'Automation is currently live mode')&&!str_contains($liveAutomationSetup,'Automation is currently shadow mode'),'Setup must render the current automation mode rather than always claiming shadow mode.');
$GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]['automation']['mode']='shadow';
$GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]['setup']['stage']='setup';

$GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]['ui']['advanced_mode']=true;
ob_start();CF7AIV\Admin\AdminPages::policyLab();$advancedPolicy=ob_get_clean();
ob_start();CF7AIV\Admin\AdminPages::reviewQueue();$advancedReview=ob_get_clean();
ob_start();CF7AIV\Admin\IntegrationsPage::render();$advancedIntegrations=ob_get_clean();
$advancedPages=$advancedPolicy.$advancedReview.$advancedIntegrations;
admin_assert(str_contains($advancedPages,'Evidence rules')&&str_contains($advancedPages,'policy_json')&&str_contains($advancedPages,'AI diagnostic, not dispatch score.')&&str_contains($advancedPages,'Reasoning tokens')&&!str_contains($advancedPages,'private-source-reference'),'Advanced administration must show evidence, policy JSON, diagnostic-only confidence wording, and aggregate usage without eagerly rendering raw source detail.');
$GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]['providers']['selected']='openai';
$GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]['ui']['advanced_mode']=false;
ob_start();CF7AIV\Admin\IntegrationsPage::render();$unsupportedIntegrations=ob_get_clean();
admin_assert(str_contains($unsupportedIntegrations,'Not supported by the selected provider')&&!str_contains($unsupportedIntegrations,'name="cf7aiv[automation][verification_mode]"'),'Unsupported providers must not expose a verification checkbox.');

CF7AIV\Admin\AdminHooks::enqueueAssets('post.php');
admin_assert($GLOBALS['admin_enqueued_styles']===[],'Admin CSS must not load on unrelated WordPress screens.');
$_GET=['page'=>'cf7aiv-review'];CF7AIV\Admin\AdminHooks::enqueueAssets('ai-enquiry-validator_page_cf7aiv-review');unset($_GET);
admin_assert(count($GLOBALS['admin_enqueued_styles'])===1&&str_ends_with($GLOBALS['admin_enqueued_styles'][0]['src'],'assets/css/admin.css')&&$GLOBALS['admin_enqueued_styles'][0]['version']===CF7AIV\VERSION,'Admin CSS must load only on plugin screens using the plugin version.');
$_GET=['page'=>'cf7aiv-setup'];CF7AIV\Admin\AdminHooks::enqueueAssets('ai-enquiry-validator_page_cf7aiv-setup');unset($_GET);
admin_assert(count($GLOBALS['admin_enqueued_scripts'])===1&&str_ends_with($GLOBALS['admin_enqueued_scripts'][0]['src'],'assets/js/admin.js')&&$GLOBALS['admin_enqueued_scripts'][0]['version']===CF7AIV\VERSION,'Setup navigation JavaScript must load only on the Setup screen using the plugin version.');
foreach(['admin_post_cf7aiv_review','admin_post_cf7aiv_bulk_review','admin_post_cf7aiv_save_settings','admin_post_cf7aiv_save_guided_policy','admin_post_cf7aiv_lifecycle_transition','admin_post_cf7aiv_automation_transition','admin_post_cf7aiv_ga_transition','admin_post_cf7aiv_save_consent','admin_post_cf7aiv_policy_import','admin_post_cf7aiv_policy_publish','admin_post_cf7aiv_save_mapping','admin_post_cf7aiv_mapping_publish','admin_post_cf7aiv_mapping_enable','admin_post_cf7aiv_mapping_disable','admin_post_cf7aiv_validate_ga4','admin_post_cf7aiv_test_microsoft','admin_post_cf7aiv_setup','admin_post_cf7aiv_backfill','admin_enqueue_scripts','cf7aiv_backfill_batch','cf7aiv_daily_maintenance'] as $hook){admin_assert(isset($GLOBALS['admin_actions'][$hook]),"Required secured/operational hook {$hook} must be registered.");}

$GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]=CF7AIV\Infrastructure\Settings::defaults();
$GLOBALS['wpdb']->queries=[];
$GLOBALS['wpdb']->acceptedSuggestions=[['suggestion_type'=>'service','value_json'=>'"Conversion optimisation"','provenance_json'=>'["page:1"]']];
$_POST=['_wpnonce'=>'nonce','guided'=>[
	'pack_key'=>'agency','policy_key'=>'global','version'=>'guided-v1',
	'business_description'=>'Agency for website projects',
	'services'=>"Web design\nSEO",
	'audiences'=>"Small businesses\nCharities",
	'primary_definition'=>'A concrete website project with a clear next step',
	'exclusion_codes'=>['supplier_pitch','recruitment'],
	'ai_whitelist'=>['message','subject'],
],'return_page'=>'policy'];CF7AIV\Admin\AdminHooks::saveGuidedPolicy();unset($_POST);
$guidedSettings=$GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION];
$guidedQueries=json_encode($GLOBALS['wpdb']->queries,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
admin_assert(($guidedSettings['setup']['draft_summary']['version']??'')==='guided-v1'&&($guidedSettings['setup']['draft_summary']['pack_key']??'')==='agency'&&($guidedSettings['setup']['draft_summary']['status']??'')==='draft','A guided save must persist a non-sensitive draft summary.');
admin_assert(str_contains($guidedQueries,'cf7aiv-policy/v2')&&str_contains($guidedQueries,'Conversion optimisation')&&str_contains($guidedQueries,'Web design')&&!str_contains($guidedQueries,"status='published'"),'Guided values and accepted safe suggestions must be validated into a v2 draft without silent publication.');
ob_start();CF7AIV\Admin\AdminPages::policyLab();$guidedPolicyPage=ob_get_clean();
admin_assert(str_contains($guidedPolicyPage,'Publish reviewed draft')&&str_contains($guidedPolicyPage,'value="guided-v1"')&&str_contains($guidedPolicyPage,'cf7aiv_policy_publish'),'Simple Policy Lab must provide an explicit guarded publish action for its saved draft summary.');
$GLOBALS['wpdb']->queries=[];
$slashedPolicy=(new CF7AIV\Classification\PolicyPackRegistry())->get('training_education');
admin_assert(is_array($slashedPolicy),'The Training and Education starter policy must be available for admin import coverage.');
$_POST=admin_wp_slash(['_wpnonce'=>'nonce','policy_key'=>'form:6262','version'=>'slash-v1','policy_json'=>json_encode($slashedPolicy,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
CF7AIV\Admin\AdminHooks::policyImport();unset($_POST);
$slashedPolicyQueries=json_encode($GLOBALS['wpdb']->queries,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
admin_assert(str_contains($slashedPolicyQueries,'form:6262')&&str_contains($slashedPolicyQueries,'training_education')&&str_contains((string)($GLOBALS['admin_redirect']??''),'page=cf7aiv-policy'),'Advanced policy import must recursively unslash normal WordPress admin POST data before decoding JSON.');
$GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]['automation']['verification_mode']='automatic_candidates';
$_POST=['_wpnonce'=>'nonce','cf7aiv'=>['automation'=>['verification_mode'=>'off']]];CF7AIV\Admin\AdminHooks::saveSettings();unset($_POST);
admin_assert($GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]['automation']['verification_mode']==='off','Submitting a capable provider form with verification unchecked must persist off.');
$providerBeforeModeToggle=$GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]['providers']['selected'];
$_POST=['_wpnonce'=>'nonce','cf7aiv'=>['ui'=>['advanced_mode'=>'1']]];CF7AIV\Admin\AdminHooks::saveSettings();unset($_POST);
ob_start();CF7AIV\Admin\AdminPages::policyLab();$toggledAdvancedPolicy=ob_get_clean();
admin_assert($GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]['ui']['advanced_mode']===true&&$GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]['providers']['selected']===$providerBeforeModeToggle&&str_contains($toggledAdvancedPolicy,'Advanced policy controls'),'Enabling Advanced mode must persist without changing unrelated settings and must reveal advanced controls.');
$_POST=['_wpnonce'=>'nonce','cf7aiv'=>['ui'=>['advanced_mode'=>'0']]];CF7AIV\Admin\AdminHooks::saveSettings();unset($_POST);
ob_start();CF7AIV\Admin\AdminPages::policyLab();$toggledSimplePolicy=ob_get_clean();
admin_assert($GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]['ui']['advanced_mode']===false&&$GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]['providers']['selected']===$providerBeforeModeToggle&&!str_contains($toggledSimplePolicy,'Advanced policy controls'),'Hiding Advanced mode must retain unrelated settings and return to the Simple view.');
$GLOBALS['wpdb']->publishedV2=false;
$_POST=['_wpnonce'=>'nonce','transition'=>'testing'];CF7AIV\Admin\AdminHooks::lifecycleTransition();unset($_POST);
admin_assert($GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]['setup']['stage']==='testing'&&$GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]['automation']['mode']==='shadow','Setup may enter Testing but must leave automation shadow.');
$_POST=['_wpnonce'=>'nonce','transition'=>'live'];CF7AIV\Admin\AdminHooks::lifecycleTransition();unset($_POST);
admin_assert($GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]['setup']['stage']==='testing','Testing must not enter Live without a published v2 policy.');
$GLOBALS['wpdb']->publishedV2=true;
$GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]['providers']['selected']='openai';
$GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]['automation']['verification_mode']='automatic_candidates';
$_POST=['_wpnonce'=>'nonce','transition'=>'live'];CF7AIV\Admin\AdminHooks::lifecycleTransition();unset($_POST);
admin_assert($GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]['setup']['stage']==='live'&&$GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]['automation']['mode']==='shadow'&&$GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]['automation']['verification_mode']==='off','Testing may enter Live only with a published v2 policy, remaining shadow and disabling unsupported verification.');
$_POST=['_wpnonce'=>'nonce','transition'=>'live'];CF7AIV\Admin\AdminHooks::automationTransition();unset($_POST);
admin_assert($GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]['automation']['mode']==='live','Automation live mode must remain a separately nonced action after lifecycle Live.');
$_POST=['_wpnonce'=>'nonce','transition'=>'testing'];CF7AIV\Admin\AdminHooks::lifecycleTransition();unset($_POST);
admin_assert($GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]['setup']['stage']==='testing'&&$GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]['automation']['mode']==='shadow','Returning to Testing must force automation shadow.');
$GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]['automation']['mode']='shadow';
$GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]['digest']=['enabled'=>true,'email'=>'digest@example.test'];$GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]['attribution']['retention_days']=45;$GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]['delivery']['pause_threshold']=8;
$_POST=['_wpnonce'=>'nonce','cf7aiv'=>['automation'=>['mode'=>'live'],'providers'=>['selected'=>'openai','openai_model'=>'gpt-5.4-mini','openai_base_url'=>'https://api.openai.com/v1/responses','openai_api_key'=>'write-only-secret'],'secrets'=>['ga4_api_secret'=>'ga-write-only-secret'],'destinations'=>['ga4'=>['enabled'=>'1','dry_run'=>'1','measurement_id'=>'G-TEST','live_enabled'=>'1','debug_validated'=>'1']],'privacy'=>['retention_days'=>120,'delete_on_uninstall'=>'1']]];CF7AIV\Admin\AdminHooks::saveSettings();unset($_POST);
$saved=$GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION];admin_assert($saved['automation']['mode']==='shadow'&&$saved['providers']['selected']==='openai'&&$saved['privacy']['delete_on_uninstall']===true,'General integration settings must be functional but unable to bypass the separate live transition.');
admin_assert($saved['digest']['enabled']===true&&$saved['attribution']['retention_days']===45&&$saved['delivery']['pause_threshold']===8&&empty($saved['destinations']['ga4']['live_enabled'])&&empty($saved['destinations']['ga4']['debug_validated']),'Partial integration saves must preserve unrelated settings and reject crafted server-controlled GA transition flags.');
admin_assert(!str_contains(json_encode($GLOBALS['admin_options']['cf7aiv_secrets']??[]),'write-only-secret'),'Write-only provider secrets must be encrypted and never stored in renderable settings.');
$_POST=['_wpnonce'=>'nonce','cf7aiv'=>['destinations'=>['server_gtm'=>['enabled'=>'1','dry_run'=>'1','endpoint_url'=>'https://metrics.example.test','measurement_id'=>'G-SGTM']],'secrets'=>['server_gtm_api_secret'=>'saved-sgtm-secret']]];CF7AIV\Admin\AdminHooks::saveSettings();unset($_POST);
$savedServerGtm=$GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]['destinations']['server_gtm']??[];
admin_assert(
	($savedServerGtm['enabled']??false)===true
	&&($savedServerGtm['dry_run']??false)===true
	&&($savedServerGtm['endpoint_url']??'')==='https://metrics.example.test'
	&&CF7AIV\Infrastructure\Crypto::secret('server_gtm_api_secret')==='saved-sgtm-secret',
	'The guarded integration save must persist existing-sGTM configuration while keeping its credential encrypted and write-only.'
);

$saved['destinations']['ga4']=['enabled'=>true,'dry_run'=>false,'measurement_id'=>'G-OLD','debug_validated'=>true,'live_enabled'=>true];$GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]=$saved;
$_POST=['_wpnonce'=>'nonce','cf7aiv'=>['destinations'=>['ga4'=>['enabled'=>'1','measurement_id'=>'G-NEW']]]];CF7AIV\Admin\AdminHooks::saveSettings();unset($_POST);$ga=$GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]['destinations']['ga4'];admin_assert(empty($ga['debug_validated'])&&empty($ga['live_enabled'])&&!empty($ga['dry_run']),'Changing GA measurement ID must invalidate debug/live state and force dry-run.');
$ga=['enabled'=>true,'dry_run'=>false,'measurement_id'=>'G-NEW','debug_validated'=>true,'live_enabled'=>true];$GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]['destinations']['ga4']=$ga;
$_POST=['_wpnonce'=>'nonce','cf7aiv'=>['destinations'=>['ga4'=>['enabled'=>'1','measurement_id'=>'G-NEW']],'secrets'=>['ga4_api_secret'=>'replacement-ga-secret']]];CF7AIV\Admin\AdminHooks::saveSettings();unset($_POST);$ga=$GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]['destinations']['ga4'];admin_assert(empty($ga['debug_validated'])&&empty($ga['live_enabled'])&&!empty($ga['dry_run']),'Updating the write-only GA secret must invalidate debug/live state and force dry-run.');
$ga=['enabled'=>true,'dry_run'=>false,'measurement_id'=>'G-NEW','debug_validated'=>true,'live_enabled'=>true];$GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]['destinations']['ga4']=$ga;
$_POST=['_wpnonce'=>'nonce','cf7aiv'=>['destinations'=>['ga4'=>['measurement_id'=>'G-NEW']]]];CF7AIV\Admin\AdminHooks::saveSettings();unset($_POST);$ga=$GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]['destinations']['ga4'];admin_assert(empty($ga['enabled'])&&empty($ga['debug_validated'])&&empty($ga['live_enabled'])&&!empty($ga['dry_run']),'Disabling GA must clear validation/live state and force dry-run.');

$saved['destinations']['ga4']=['enabled'=>true,'dry_run'=>true,'measurement_id'=>'G-TEST','debug_validated'=>false,'live_enabled'=>false];$GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]=$saved;
$_POST=['_wpnonce'=>'nonce','transition'=>'live'];CF7AIV\Admin\AdminHooks::gaTransition();unset($_POST);$ga=$GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]['destinations']['ga4'];admin_assert(empty($ga['live_enabled'])&&!empty($ga['dry_run']),'GA4 live enable must remain blocked until debug validation succeeds.');
$GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]['destinations']['ga4']['debug_validated']=true;
$_POST=['_wpnonce'=>'nonce','transition'=>'live'];CF7AIV\Admin\AdminHooks::gaTransition();unset($_POST);$ga=$GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]['destinations']['ga4'];admin_assert(!empty($ga['live_enabled'])&&empty($ga['dry_run']),'Separately nonced GA4 transition may enable live only after debug validation.');

$_POST=['_wpnonce'=>'nonce','mode'=>'legal_basis_override','reason'=>'Approved documented legitimate-interest assessment.'];CF7AIV\Admin\AdminHooks::saveConsent();unset($_POST);$override=$GLOBALS['admin_options'][CF7AIV\Infrastructure\Settings::OPTION]['consent']['override']??[];admin_assert(($override['administrator_id']??0)===77&&($override['reason']??'')!==''&&strtotime((string)($override['date']??''))!==false,'Consent override must persist administrator, timestamp, and reason audit fields.');
$overrideAudit=$GLOBALS['admin_options']['cf7aiv_consent_override_audit']??[];admin_assert(count($overrideAudit)===1&&($overrideAudit[0]['administrator_id']??0)===77,'Legal-basis overrides must append to a dedicated durable audit option.');

$_POST=['_wpnonce'=>'nonce','mapping'=>['key'=>'admin-map','version'=>'v1','tier_id'=>'high_value_care','destination_key'=>'ga4','event_name'=>'qualify_lead','value'=>'10','currency'=>'GBP','status'=>'published','enabled'=>'1']];CF7AIV\Admin\AdminHooks::saveMapping();unset($_POST);
$_POST=['_wpnonce'=>'nonce','mapping_key'=>'admin-map','version'=>'v1'];CF7AIV\Admin\AdminHooks::mappingPublish();CF7AIV\Admin\AdminHooks::mappingEnable();CF7AIV\Admin\AdminHooks::mappingDisable();unset($_POST);$mappingSql=json_encode($GLOBALS['wpdb']->queries);admin_assert(str_contains($mappingSql,'admin-map')&&str_contains($mappingSql,"SET status='published'")&&str_contains($mappingSql,'SET enabled=CASE')&&str_contains($mappingSql,'SET enabled=0')&&substr_count($mappingSql,'START TRANSACTION')===3&&substr_count($mappingSql,'COMMIT')===3,'Mappings must save as drafts and require explicit atomic publish, enable, and disable transitions.');

echo "PASS AdminTest\n";
