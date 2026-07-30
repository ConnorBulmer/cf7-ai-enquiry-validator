<?php
declare(strict_types=1);
namespace CF7AIV\Admin;
final class WpReviewStore implements ReviewStoreInterface {
	public function get(int $itemId):?array { global $wpdb; if(!isset($wpdb)){return null;} $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}cf7aiv_items WHERE id=%d",$itemId),ARRAY_A);return is_array($row)?$row:null; }
	public function persist(int $itemId,string $reviewState,?string $reviewedTierId,?string $reviewedOutcomeId,int $administratorId,int $expectedRevision):bool {
		global $wpdb;
		if(!isset($wpdb)||$expectedRevision<0||!method_exists($wpdb,'query')||!method_exists($wpdb,'prepare')||!method_exists($wpdb,'get_row')||!method_exists($wpdb,'update')||$wpdb->query('START TRANSACTION')===false){return false;}
		$table=$wpdb->prefix.'cf7aiv_items';
		$current=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d FOR UPDATE",$itemId),ARRAY_A);
		if(!is_array($current)||max(0,(int)($current['review_revision']??0))!==$expectedRevision){$wpdb->query('ROLLBACK');return false;}
		$now=current_time('mysql',true);
		$data=['review_state'=>$reviewState,'reviewed_by'=>$administratorId,'dispatch_approval_id'=>null,'updated_at'=>$now];
		if($reviewedTierId!==null&&$reviewedOutcomeId!==null){
			$data['reviewed_tier_id']=$reviewedTierId;
			$data['reviewed_outcome_id']=$reviewedOutcomeId;
			$data['tier_id']=$reviewedTierId;
			$data['outcome_id']=$reviewedOutcomeId;
			$data['reviewed_at']=$now;
		}
		$data['review_revision']=$expectedRevision+1;
		if($wpdb->update($table,$data,['id'=>$itemId])!==1||$wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return false;}
		return true;
	}
	public function suggest(int $itemId,int $administratorId,string $previousTier,string $correctedTier,string $exampleSummary):void { global $wpdb;$wpdb->insert($wpdb->prefix.'cf7aiv_review_suggestions',['item_id'=>$itemId,'administrator_id'=>$administratorId,'action'=>'change_tier','previous_tier'=>$previousTier,'corrected_tier'=>$correctedTier,'example_summary'=>$exampleSummary,'status'=>'draft','created_at'=>current_time('mysql',true),'updated_at'=>current_time('mysql',true)]); }
	public function approveDispatch(int $itemId,int $administratorId,string $reviewedTierId,string $reviewedOutcomeId,int $expectedRevision):bool {
		global $wpdb;
		if(!isset($wpdb)||!isset($wpdb->prefix)||!method_exists($wpdb,'query')||!method_exists($wpdb,'prepare')||!method_exists($wpdb,'get_row')||!method_exists($wpdb,'update')||!method_exists($wpdb,'insert')
			||$itemId<=0||$administratorId<=0||$expectedRevision<0||preg_match('/^[a-z0-9_-]{1,64}$/',$reviewedTierId)!==1||preg_match('/^[a-z0-9_-]{1,64}$/',$reviewedOutcomeId)!==1){return false;}
		if($wpdb->query('START TRANSACTION')===false){return false;}
		$items=$wpdb->prefix.'cf7aiv_items';
		$audits=$wpdb->prefix.'cf7aiv_review_suggestions';
		$before=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$items} WHERE id=%d FOR UPDATE",$itemId),ARRAY_A);
		if(!is_array($before)||!array_key_exists('is_shadow',$before)||(int)$before['is_shadow']!==0||!empty($before['is_calibration'])||!empty($before['is_historical'])){$wpdb->query('ROLLBACK');return false;}
		if(max(0,(int)($before['review_revision']??0))!==$expectedRevision){$wpdb->query('ROLLBACK');return false;}
		$lockedTier=trim((string)($before['reviewed_tier_id']??''));if($lockedTier===''){$lockedTier=trim((string)($before['predicted_tier_id']??$before['tier_id']??''));}
		$lockedOutcome=trim((string)($before['reviewed_outcome_id']??''));if($lockedOutcome===''){$lockedOutcome=trim((string)($before['predicted_outcome_id']??$before['outcome_id']??''));}
		if($lockedTier===''||$lockedOutcome===''||!hash_equals($lockedTier,$reviewedTierId)||!hash_equals($lockedOutcome,$reviewedOutcomeId)){$wpdb->query('ROLLBACK');return false;}
		$existingId=(string)($before['dispatch_approval_id']??'');
		$isReplay=($before['review_state']??'')==='approved_dispatch'
			&&(int)($before['reviewed_by']??0)===$administratorId
			&&($before['reviewed_tier_id']??'')===$reviewedTierId
			&&($before['reviewed_outcome_id']??'')===$reviewedOutcomeId
			&&($before['tier_id']??'')===$reviewedTierId
			&&($before['outcome_id']??'')===$reviewedOutcomeId
			&&preg_match('/^[a-f0-9]{64}$/',$existingId)===1;
		if($isReplay){
			if(!$this->auditMatches($itemId,$administratorId,$reviewedTierId,$reviewedOutcomeId,$existingId)){
				$revoked=$wpdb->update($items,['review_state'=>'review','dispatch_approval_id'=>null,'review_revision'=>$expectedRevision+1,'updated_at'=>current_time('mysql',true)],['id'=>$itemId,'dispatch_approval_id'=>$existingId]);
				if($revoked!==1||$wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');}
				return false;
			}
			if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return false;}
			return true;
		}
		try{$approvalId=bin2hex(random_bytes(32));}catch(\Throwable){$wpdb->query('ROLLBACK');return false;}
		$now=current_time('mysql',true);
		$updated=$wpdb->update($items,[
			'review_state'=>'approved_dispatch',
			'reviewed_by'=>$administratorId,
			'reviewed_at'=>$now,
			'reviewed_tier_id'=>$reviewedTierId,
			'reviewed_outcome_id'=>$reviewedOutcomeId,
			'tier_id'=>$reviewedTierId,
			'outcome_id'=>$reviewedOutcomeId,
			'dispatch_approval_id'=>$approvalId,
			'review_revision'=>$expectedRevision+1,
			'updated_at'=>$now,
		],['id'=>$itemId]);
		if($updated!==1){$this->abortApproval($before,$approvalId);return false;}
		$stored=$wpdb->insert($audits,[
			'item_id'=>$itemId,
			'administrator_id'=>$administratorId,
			'action'=>'approve_dispatch',
			'dispatch_approval_id'=>$approvalId,
			'previous_tier'=>(string)($before['tier_id']??''),
			'corrected_tier'=>$reviewedTierId,
			'reviewed_outcome_id'=>$reviewedOutcomeId,
			'example_summary'=>'Administrator approved dispatch.',
			'status'=>'accepted',
			'created_at'=>$now,
			'updated_at'=>$now,
		]);
		if($stored===false){$this->abortApproval($before,$approvalId);return false;}
		if($wpdb->query('COMMIT')===false){$this->abortApproval($before,$approvalId);return false;}
		return true;
	}
	private function auditMatches(int $itemId,int $administratorId,string $reviewedTierId,string $reviewedOutcomeId,string $approvalId):bool {
		global $wpdb;
		if(!method_exists($wpdb,'get_var')){return false;}
		$sql=$wpdb->prepare("SELECT dispatch_approval_id FROM {$wpdb->prefix}cf7aiv_review_suggestions WHERE dispatch_approval_id=%s AND item_id=%d AND administrator_id=%d AND action='approve_dispatch' AND corrected_tier=%s AND reviewed_outcome_id=%s AND status='accepted' LIMIT 1",$approvalId,$itemId,$administratorId,$reviewedTierId,$reviewedOutcomeId);
		$stored=(string)$wpdb->get_var($sql);
		return $stored!==''&&hash_equals($approvalId,$stored);
	}
	/** @param array<string,mixed> $before */
	private function abortApproval(array $before,string $approvalId):void {
		global $wpdb;
		$wpdb->query('ROLLBACK');
		if(method_exists($wpdb,'delete')){$wpdb->delete($wpdb->prefix.'cf7aiv_review_suggestions',['dispatch_approval_id'=>$approvalId],['%s']);}
		$restore=[];
		foreach(['review_state','reviewed_by','reviewed_at','reviewed_tier_id','reviewed_outcome_id','tier_id','outcome_id','dispatch_approval_id','review_revision','updated_at'] as $key){$restore[$key]=$before[$key]??null;}
		$wpdb->update($wpdb->prefix.'cf7aiv_items',$restore,['id'=>(int)($before['id']??0),'dispatch_approval_id'=>$approvalId]);
	}
}
