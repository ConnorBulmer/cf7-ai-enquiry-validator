<?php

declare(strict_types=1);

namespace CF7AIV\Classification;

use CF7AIV\Domain\ClassificationResult;
use CF7AIV\Infrastructure\Settings;
use InvalidArgumentException;

final class WpClassificationItemStore implements ClassificationItemStoreInterface {
	public function get(int $itemId): ?array {
		global $wpdb;
		if (! isset($wpdb->prefix)) { return null; }
		$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}cf7aiv_items WHERE id=%d", $itemId), ARRAY_A);
		return is_array($row) ? $row : null;
	}

	public function linkSource(int $itemId, string $sourceType, string $sourceReference): void {
		global $wpdb;
		if (! isset($wpdb->prefix)) { return; }
		$wpdb->update($wpdb->prefix . 'cf7aiv_items', [
			'source_type' => $sourceType,
			'source_reference' => $sourceReference,
			'updated_at' => function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s'),
		], ['id' => $itemId]);
	}

	public function succeed(int $itemId, ClassificationResult $result, array $policy, EvidenceDecision $evidenceDecision, string $verificationState, string $reviewState, bool $isShadow, bool $replacePrediction = false): bool {
		if (! in_array($verificationState, Settings::VERIFICATION_STATES, true)) {
			throw new InvalidArgumentException('Verification state is invalid.');
		}
		global $wpdb;
		if (! isset($wpdb->prefix)) { return false; }
		$existing = $this->get($itemId) ?? [];
		$hasPrediction = (string) ($existing['predicted_tier_id'] ?? '') !== ''
			&& (string) ($existing['predicted_outcome_id'] ?? '') !== '';
		if ($hasPrediction && ! $replacePrediction) {
			return false;
		}
		$reviewMutable = in_array((string)($existing['review_state']??''),['','pending','review','error'],true)
			&& (int)($existing['reviewed_by']??0)===0
			&& (string)($existing['reviewed_at']??'')==='';
		$expectedRevision=max(0,(int)($existing['review_revision']??0));
		$table = $wpdb->prefix . 'cf7aiv_items';
		// The origin is first-classification immutable: a later reanalysis after an
		// administrator enables live mode cannot turn a shadow record dispatchable.
		$shadowUpdated = $wpdb->query($wpdb->prepare("UPDATE {$table} SET is_shadow=COALESCE(is_shadow,%d) WHERE id=%d", $isShadow ? 1 : 0, $itemId));
		if ($shadowUpdated === false) {
			return false;
		}
		$data = [
			'policy_id' => (int) ($policy['id'] ?? 0),
			'policy_version' => (string) $policy['version'],
			'policy_hash' => (string) $policy['compiled_prompt_hash'],
			'state' => 'classified', 'reason_code' => $result->reasonCode,
			'predicted_tier_id' => $result->tierId, 'predicted_outcome_id' => $result->outcomeId,
			'decision_state' => $evidenceDecision->state, 'verification_state' => $verificationState,
			'confidence' => $result->confidence, 'ambiguity_json' => json_encode($result->ambiguityFlags, JSON_THROW_ON_ERROR),
			'evidence_json' => json_encode(array_map(static fn (EvidenceAssertion $assertion): array => [
				'dimension' => $assertion->dimension,
				'code' => $assertion->code,
				'field_key' => $assertion->fieldKey,
				'grounded' => $assertion->grounded,
			], $result->evidence), JSON_THROW_ON_ERROR),
			'missing_evidence_json' => json_encode($result->missingEvidence, JSON_THROW_ON_ERROR),
			'exclusion_json' => json_encode($result->exclusionCodes, JSON_THROW_ON_ERROR),
			'provider_usage_json' => json_encode($result->providerProfile === [] ? $result->providerUsage : $result->providerUsage + ['profile' => $result->providerProfile], JSON_THROW_ON_ERROR),
			'summary' => $result->summary, 'last_error' => null,
			'updated_at' => function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s'),
		];
		if ($reviewMutable) {
			$data['review_state'] = $reviewState;
			$data['tier_id'] = $result->tierId;
			$data['outcome_id'] = $result->outcomeId;
			$data['review_revision']=$expectedRevision+1;
		}
		$where=['id'=>$itemId];
		if($reviewMutable){$where['review_revision']=$expectedRevision;}
		$updated=$wpdb->update($table,$data,$where);
		if($updated===false){return false;}
		if($updated===1){return true;}
		if($updated!==0||$reviewMutable){return false;}

		// wpdb reports zero both for a missing/conflicted row and for an identical
		// update. Accept the latter only when both the intended classification and
		// the reviewer-owned fields still match the snapshot read before the write.
		$stored=$this->get($itemId);
		return $stored!==null&&$this->matchesUnchangedSuccess($stored,$existing,$data);
	}

	public function fail(int $itemId, string $message, bool $transient, bool $replacePrediction = false): int|false {
		global $wpdb;
		if (! isset($wpdb->prefix)) { return false; }
		$table = $wpdb->prefix . 'cf7aiv_items';
		$existing=$this->get($itemId)??[];
		$reviewMutable=!$replacePrediction
			&&in_array((string)($existing['review_state']??''),['','pending','review','error'],true)
			&&(int)($existing['reviewed_by']??0)===0
			&&(string)($existing['reviewed_at']??'')==='';
		$state=$transient?'retrying':'error';$message=substr($message,0,500);
		if($reviewMutable){
			$revision=max(0,(int)($existing['review_revision']??0));
			$updated=$wpdb->query($wpdb->prepare("UPDATE {$table} SET state=%s,review_state='error',review_revision=review_revision+1,classification_attempts=classification_attempts+1,last_error=%s,updated_at=UTC_TIMESTAMP() WHERE id=%d AND review_revision=%d AND reviewed_by IS NULL AND reviewed_at IS NULL",$state,$message,$itemId,$revision));
			if($updated!==1){return false;}
			$attempt=(int)$wpdb->get_var($wpdb->prepare("SELECT classification_attempts FROM {$table} WHERE id=%d",$itemId));
			return $attempt>0?$attempt:false;
		}
		$updated=$wpdb->query($wpdb->prepare("UPDATE {$table} SET state=%s,classification_attempts=classification_attempts+1,last_error=%s,updated_at=UTC_TIMESTAMP() WHERE id=%d",$state,$message,$itemId));
		if($updated!==1){return false;}
		$attempt=(int)$wpdb->get_var($wpdb->prepare("SELECT classification_attempts FROM {$table} WHERE id=%d",$itemId));
		return $attempt>0?$attempt:false;
	}

	/**
	 * @param array<string,mixed> $stored
	 * @param array<string,mixed> $before
	 * @param array<string,mixed> $intended
	 */
	private function matchesUnchangedSuccess(array $stored,array $before,array $intended):bool {
		foreach(['review_revision','reviewed_by'] as $key){
			if((int)($stored[$key]??0)!==(int)($before[$key]??0)){return false;}
		}
		foreach(['review_state','reviewed_at','reviewed_tier_id','reviewed_outcome_id','tier_id','outcome_id','dispatch_approval_id'] as $key){
			if((string)($stored[$key]??'')!==(string)($before[$key]??'')){return false;}
		}
		foreach($intended as $key=>$value){
			if($key==='updated_at'){continue;}
			$actual=$stored[$key]??null;
			if($key==='policy_id'){
				if((int)$actual!==(int)$value){return false;}
				continue;
			}
			if($key==='confidence'){
				if(abs((float)$actual-(float)$value)>0.000001){return false;}
				continue;
			}
			if($value===null){
				if($actual!==null){return false;}
				continue;
			}
			if((string)$actual!==(string)$value){return false;}
		}
		return true;
	}
}
