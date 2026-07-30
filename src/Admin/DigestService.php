<?php
declare(strict_types=1);
namespace CF7AIV\Admin;
use CF7AIV\Infrastructure\Settings;
final class DigestService {
	private $mail;private $settings;
	public function __construct(?callable $mail=null,?callable $settings=null){$this->mail=$mail??static function(string $to,string $subject,string $body):void{if(function_exists('wp_mail')){wp_mail($to,$subject,$body);}};$this->settings=$settings??static fn():array=>function_exists('get_option')?(array)get_option(Settings::OPTION,Settings::defaults()):Settings::defaults();}
	public function send():bool{$settings=($this->settings)();if(empty($settings['digest']['enabled'])){return false;}$email=(string)($settings['digest']['email']??'');if(!filter_var($email,FILTER_VALIDATE_EMAIL)){return false;}global $wpdb;$pending=0;$permanent=0;if(isset($wpdb)){$pending=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cf7aiv_items WHERE review_state IN ('pending','review')");$permanent=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cf7aiv_deliveries WHERE state='failed'");}$paused=function_exists('get_option')?count((array)get_option('cf7aiv_paused_destinations',[])):0;($this->mail)($email,'AI Enquiry Validator daily digest',"Pending reviews: {$pending}\nPaused integrations: {$paused}\nPermanent failures: {$permanent}");return true;}
}
