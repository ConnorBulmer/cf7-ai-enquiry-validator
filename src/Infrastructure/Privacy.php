<?php

declare(strict_types=1);

namespace CF7AIV\Infrastructure;

/**
 * WordPress privacy API integration. The plugin holds no raw identity; it can
 * only match a requested email against an optional, consented normalized hash.
 */
final class Privacy {
	public static function register(): void {
		if (! function_exists('add_filter')) {
			return;
		}
		add_filter('wp_privacy_personal_data_exporters', [self::class, 'registerExporter']);
		add_filter('wp_privacy_personal_data_erasers', [self::class, 'registerEraser']);
	}

	/** @param array<string, mixed> $exporters @return array<string, mixed> */
	public static function registerExporter(array $exporters): array {
		$exporters['cf7aiv'] = [
			'exporter_friendly_name' => __('CF7 AI Enquiry Validator', 'cf7-ai-enquiry-validator'),
			'callback' => [self::class, 'export'],
		];
		return $exporters;
	}

	/** @param array<string, mixed> $erasers @return array<string, mixed> */
	public static function registerEraser(array $erasers): array {
		$erasers['cf7aiv'] = [
			'eraser_friendly_name' => __('CF7 AI Enquiry Validator', 'cf7-ai-enquiry-validator'),
			'callback' => [self::class, 'erase'],
		];
		return $erasers;
	}

	/** @return array{data:list<array<string,mixed>>,done:bool} */
	public static function export(string $emailAddress, int $page = 1): array {
		global $wpdb;
		if (! isset($wpdb) || ! isset($wpdb->prefix) || ! method_exists($wpdb, 'get_results')) { return ['data' => [], 'done' => true]; }
		$limit=50;$offset=max(0,$page-1)*$limit;$table=$wpdb->prefix.'cf7aiv_items';
		$rows=$wpdb->get_results($wpdb->prepare("SELECT id,outcome_id,tier_id,decision_state,reason_code,confidence,evidence_json,policy_version,state,review_state,created_at FROM {$table} WHERE email_hash=%s ORDER BY id ASC LIMIT %d OFFSET %d",self::normalizedEmailHash($emailAddress),$limit,$offset),ARRAY_A);
		$data=[];
		foreach(is_array($rows)?$rows:[] as $row){
			$fields=[];
			foreach(['outcome_id'=>'Outcome','tier_id'=>'Tier','decision_state'=>'Decision','reason_code'=>'Reason'] as $key=>$label){
				$value=self::safeCode($row[$key]??null);
				if($value!==null){$fields[]=['name'=>$label,'value'=>$value];}
			}
			if(isset($row['confidence'])&&$row['confidence']!==''&&is_numeric($row['confidence'])){$fields[]=['name'=>'AI diagnostic confidence','value'=>(string)$row['confidence']];}
			$evidence=self::evidenceCodes($row['evidence_json']??null);
			if($evidence!==''){$fields[]=['name'=>'Evidence codes','value'=>$evidence];}
			foreach(['policy_version'=>'Policy version','state'=>'Processing state','review_state'=>'Review state'] as $key=>$label){
				$value=self::safeCode($row[$key]??null);
				if($value!==null){$fields[]=['name'=>$label,'value'=>$value];}
			}
			$captured=(string)($row['created_at']??'');
			if(preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:[+-]\d{2}:\d{2}|Z)?$/',$captured)===1){$fields[]=['name'=>'Captured at','value'=>$captured];}
			$data[]=['group_id'=>'cf7aiv','group_label'=>__('CF7 AI Enquiry Validator','cf7-ai-enquiry-validator'),'item_id'=>'cf7aiv-item-'.(int)($row['id']??0),'data'=>$fields];
		}
		return ['data'=>$data,'done'=>count($data)<$limit];
	}

	/** @return array{items_removed:bool,items_retained:bool,messages:list<string>,done:bool} */
	public static function erase(string $emailAddress, int $page = 1): array {
		global $wpdb;
		$deleted = 0;
		if (isset($wpdb) && isset($wpdb->prefix)) {
			$table = $wpdb->prefix . 'cf7aiv_items';
			$ids=method_exists($wpdb,'get_col')?(array)$wpdb->get_col($wpdb->prepare("SELECT id FROM {$table} WHERE email_hash=%s",self::normalizedEmailHash($emailAddress))):[];
			$ids=array_values(array_filter(array_map('intval',$ids),static fn(int $id):bool=>$id>0));
			if($ids!==[]&&method_exists($wpdb,'query')){$placeholders=implode(',',array_fill(0,count($ids),'%d'));$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}cf7aiv_review_suggestions WHERE item_id IN ({$placeholders})",...$ids));$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}cf7aiv_deliveries WHERE item_id IN ({$placeholders})",...$ids));}
			$deleted = (int) $wpdb->delete($table, ['email_hash' => self::normalizedEmailHash($emailAddress)], ['%s']);
		}
		return ['items_removed' => $deleted > 0, 'items_retained' => false, 'messages' => [], 'done' => true];
	}

	public static function normalizedEmailHash(string $email): string {
		return hash('sha256', strtolower(trim($email)));
	}

	private static function safeCode(mixed $value): ?string {
		if(!is_scalar($value)){return null;}
		$value=trim((string)$value);
		return preg_match('/^[a-zA-Z0-9_-]{1,64}$/',$value)===1?$value:null;
	}

	private static function evidenceCodes(mixed $encoded): string {
		if(!is_string($encoded)||$encoded===''){return '';}
		try{$rows=json_decode($encoded,true,32,JSON_THROW_ON_ERROR);}catch(\JsonException){return '';}
		if(!is_array($rows)){return '';}
		$codes=[];
		foreach($rows as $row){
			if(!is_array($row)){continue;}
			$code=self::safeCode($row['code']??null);
			if($code===null){continue;}
			$field=self::safeCode($row['field_key']??null);
			$codes[]=$field===null?$code:$code.' ('.$field.')';
		}
		return implode(', ',array_values(array_unique($codes)));
	}
}
