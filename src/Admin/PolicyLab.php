<?php
declare(strict_types=1);
namespace CF7AIV\Admin;
use CF7AIV\Classification\PolicyValidator;
use CF7AIV\Classification\StarterPolicy;
use InvalidArgumentException;
final class PolicyLab {
	/** @return array<string,mixed> */ public static function import(string $json):array{$data=json_decode($json,true);if(!is_array($data)){throw new InvalidArgumentException('Policy JSON is invalid.');}return PolicyValidator::import($data);}
	/** @param array<string,mixed> $policy */ public static function export(array $policy):string{return json_encode(PolicyValidator::import($policy),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
	/** @param array<string,mixed> $policy */ public static function assistantBrief(array $policy):string{return "Review this CF7 enquiry-classification policy. Suggest non-identifying draft improvements only; do not include personal data or change published behavior:\n".self::export($policy);}
	/** @return array<string,mixed> */ public static function starter():array{return StarterPolicy::definition();}
}
