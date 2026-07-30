<?php
declare(strict_types=1);
namespace CF7AIV\Admin;
use CF7AIV\Classification\Redactor;
final class AdminSafe { public static function log(string $value):string{$value=preg_replace('/\b(api[_-]?key|token|secret|authorization)\s*[:=]\s*[^\s,;]+/i','$1=[redacted]',$value)??'';return substr(Redactor::redact($value),0,1000);} }
