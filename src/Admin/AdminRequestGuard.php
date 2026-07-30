<?php
declare(strict_types=1);
namespace CF7AIV\Admin;
use RuntimeException;
final class AdminRequestGuard { public static function authorize(string $nonce,string $action):void{if(!function_exists('current_user_can')||!current_user_can('manage_options')){throw new RuntimeException('Administrator capability is required.');}if(!function_exists('wp_verify_nonce')||wp_verify_nonce($nonce,$action)===false){throw new RuntimeException('Admin request nonce is invalid.');}} }
