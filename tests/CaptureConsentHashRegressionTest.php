<?php
/** Standalone regression for consent-gated enhanced identity hashing. */
declare(strict_types=1);
function hash_check(bool $condition, string $message): void { if (! $condition) { throw new RuntimeException($message); } }
$GLOBALS['hash_options'] = []; $GLOBALS['cf7aiv_test_actions'] = []; $GLOBALS['cf7aiv_test_filters'] = [];
$GLOBALS['hash_consent_type'] = false; $GLOBALS['hash_consent_categories'] = ['statistics' => false, 'marketing' => false];
function add_action(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): void { $GLOBALS['cf7aiv_test_actions'][$hook][] = [$callback, $priority, $acceptedArgs]; }
function add_filter(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): void { $GLOBALS['cf7aiv_test_filters'][$hook][] = [$callback, $priority, $acceptedArgs]; }
function do_action(string $hook, mixed ...$args): void {}
function plugin_dir_url(string $file): string { return 'https://example.test/plugin/'; }
function as_enqueue_async_action(): int { return 1; }
function get_option(string $key, mixed $default = false): mixed { return $GLOBALS['hash_options'][$key] ?? $default; }
function current_time(string $type, bool $gmt = false): string { return '2026-07-22 10:00:00'; }
function wp_json_encode(mixed $value): string|false { return json_encode($value); }
function wp_get_consent_type(): mixed { return $GLOBALS['hash_consent_type']; }
function wp_has_consent(string $category): bool { return (bool) ($GLOBALS['hash_consent_categories'][$category] ?? false); }
define('ABSPATH', __DIR__ . '/'); define('AUTH_KEY', 'hash-auth'); define('SECURE_AUTH_KEY', 'hash-secure');
final class WPCF7_Submission { public static array $posted = []; public static function get_instance(): self { return new self(); } public function get_posted_data(): array { return self::$posted; } public function get_posted_data_hash(): string { return 'submission-hash'; } }
final class HashWpdb { public string $prefix = 'wp_'; public int $insert_id = 1; public array $preparedArgs = []; public function prepare(string $sql, mixed ...$args): string { $this->preparedArgs = $args; return $sql; } public function query(string $sql): int { return 1; } public function get_var(string $sql): int { return 1; } }
final class HashContactForm { public function id(): int { return 7; } }
require_once dirname(__DIR__) . '/cf7-ai-enquiry-validator.php';
$settings = CF7AIV\Infrastructure\Settings::defaults(); $settings['privacy']['enhanced_matching'] = true; $GLOBALS['hash_options'][CF7AIV\Infrastructure\Settings::OPTION] = $settings;
$GLOBALS['wpdb'] = new HashWpdb(); $capture = new CF7AIV\Intake\CaptureService();
$base = ['your-email' => 'Person@Example.test', 'your-phone' => '+44 7700 900123', 'cf7aiv_ga_client_id' => '111.222', 'cf7aiv_ga_session_id' => '1234567890'];
WPCF7_Submission::$posted = $base + ['cf7aiv_ad_user_data' => 'granted']; $capture->capture(new HashContactForm(), ['status' => 'mail_sent']);
hash_check($GLOBALS['wpdb']->preparedArgs[8] === hash('sha256', 'person@example.test') && is_string($GLOBALS['wpdb']->preparedArgs[9]), 'Unknown WordPress authority must not override granted posted Google consent for enhanced matching.');
$GLOBALS['hash_consent_type'] = 'optout';
WPCF7_Submission::$posted = $base + ['cf7aiv_ad_user_data' => 'granted']; $capture->capture(new HashContactForm(), ['status' => 'mail_sent']);
hash_check($GLOBALS['wpdb']->preparedArgs[8] === hash('sha256', 'person@example.test') && is_string($GLOBALS['wpdb']->preparedArgs[9]), 'WordPress opt-out defaults must not override granted posted Google consent for enhanced matching.');
$GLOBALS['hash_consent_type'] = 'optin';
WPCF7_Submission::$posted = $base + ['cf7aiv_ad_user_data' => 'granted']; $capture->capture(new HashContactForm(), ['status' => 'mail_sent']);
hash_check($GLOBALS['wpdb']->preparedArgs[8] === null && $GLOBALS['wpdb']->preparedArgs[9] === null, 'Qualified WordPress opt-in denial must remain authoritative over posted Google consent.');
WPCF7_Submission::$posted = $base + ['cf7aiv_ad_user_data' => 'denied']; $capture->capture(new HashContactForm(), ['status' => 'mail_sent']);
hash_check($GLOBALS['wpdb']->preparedArgs[8] === null && $GLOBALS['wpdb']->preparedArgs[9] === null, 'Denied ad_user_data must persist null hashes.');
WPCF7_Submission::$posted = $base; $capture->capture(new HashContactForm(), ['status' => 'mail_sent']);
hash_check($GLOBALS['wpdb']->preparedArgs[8] === null && $GLOBALS['wpdb']->preparedArgs[9] === null, 'Unknown ad_user_data must persist null hashes.');
// Google and Microsoft match on SHA-256 of E.164. A national number hashes to a
// value neither can match, and a field merely containing "tel" is not a phone.
$GLOBALS['hash_consent_type'] = null;
WPCF7_Submission::$posted = ['your-phone' => '+44 7700 900123', 'cf7aiv_ad_user_data' => 'granted']; $capture->capture(new HashContactForm(), ['status' => 'mail_sent']);
hash_check($GLOBALS['wpdb']->preparedArgs[9] === hash('sha256', '+447700900123'), 'An international phone number must be hashed in E.164 form.');
WPCF7_Submission::$posted = ['your-tel' => '0044 7700 900123', 'cf7aiv_ad_user_data' => 'granted']; $capture->capture(new HashContactForm(), ['status' => 'mail_sent']);
hash_check($GLOBALS['wpdb']->preparedArgs[9] === hash('sha256', '+447700900123'), 'A 00 international prefix must normalise to E.164.');
WPCF7_Submission::$posted = ['your-phone' => '07700 900123', 'cf7aiv_ad_user_data' => 'granted']; $capture->capture(new HashContactForm(), ['status' => 'mail_sent']);
hash_check($GLOBALS['wpdb']->preparedArgs[9] === null, 'A national number with no country must not be hashed into a value that can never match.');
WPCF7_Submission::$posted = ['your-phone' => '+44 (0) 7700 900123', 'cf7aiv_ad_user_data' => 'granted']; $capture->capture(new HashContactForm(), ['status' => 'mail_sent']);
hash_check($GLOBALS['wpdb']->preparedArgs[9] === hash('sha256', '+447700900123'), 'The national trunk zero written as (0) must not enter the E.164 number.');
foreach (['phoneNumber', 'yourphone', 'mobilephone', 'telefon', 'tel-123'] as $phoneField) {
	WPCF7_Submission::$posted = [$phoneField => '+44 7700 900123', 'cf7aiv_ad_user_data' => 'granted']; $capture->capture(new HashContactForm(), ['status' => 'mail_sent']);
	hash_check($GLOBALS['wpdb']->preparedArgs[9] === hash('sha256', '+447700900123'), "A field named {$phoneField} must be recognised as a phone number.");
}
WPCF7_Submission::$posted = ['hotel-booking-ref' => '+44 1234 567890', 'cf7aiv_ad_user_data' => 'granted']; $capture->capture(new HashContactForm(), ['status' => 'mail_sent']);
hash_check($GLOBALS['wpdb']->preparedArgs[9] === null, 'A field that only contains "tel" inside another word is not a phone number.');
$repositorySource = file_get_contents(dirname(__DIR__) . '/src/Intake/ItemRepository.php');
hash_check(is_string($repositorySource) && ! str_contains($repositorySource, 'email_hash=COALESCE') && ! str_contains($repositorySource, 'phone_hash=COALESCE'), 'A restrictive duplicate capture must clear previously stored hashes instead of retaining them.');
echo "PASS CaptureConsentHashRegressionTest\n";
