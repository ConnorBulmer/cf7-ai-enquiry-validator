<?php
declare(strict_types=1);

define('CF7AIV\\DIR', dirname(__DIR__) . '/');

spl_autoload_register(static function (string $class): void {
	$prefix = 'CF7AIV\\';
	if (! str_starts_with($class, $prefix)) { return; }
	$path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
	if (is_file($path)) { require_once $path; }
});

function secret_assert(bool $condition, string $message): void {
	if (! $condition) { throw new RuntimeException($message); }
}

use CF7AIV\Admin\SettingsSanitizer;

// The behaviour an administrator cannot see and has to trust: a blank field
// keeps the stored credential rather than erasing it. If this ever regresses,
// saving an unrelated setting would silently destroy a working key.
$blank = SettingsSanitizer::secrets(['providers' => ['deepseek_api_key' => '']]);
secret_assert(! array_key_exists('deepseek_api_key', $blank), 'A blank credential must be skipped, never stored as empty.');

$whitespace = SettingsSanitizer::secrets(['providers' => ['deepseek_api_key' => "   \t  "]]);
secret_assert(! array_key_exists('deepseek_api_key', $whitespace), 'A whitespace-only credential must not overwrite a stored one.');

$absent = SettingsSanitizer::secrets(['providers' => ['deepseek_model' => 'deepseek-v4-flash']]);
secret_assert($absent === [], 'Saving other provider settings must not touch stored credentials.');

$typed = SettingsSanitizer::secrets(['providers' => ['deepseek_api_key' => '  a-real-looking-key  ']]);
secret_assert(($typed['deepseek_api_key'] ?? null) === 'a-real-looking-key', 'A typed credential must be trimmed and stored.');

// The field must report whether a credential exists, because an always-empty
// box is indistinguishable from a failed save.
$page = file_get_contents(__DIR__ . '/../src/Admin/IntegrationsPage.php');
secret_assert(is_string($page), 'Integrations page source must be readable.');
secret_assert(str_contains($page, 'secretIsStored'), 'The field must determine whether a credential is stored.');
secret_assert(str_contains($page, "AdminUi::badge('Stored'"), 'A stored credential must be shown as stored.');
secret_assert(str_contains($page, "AdminUi::badge('Not set'"), 'An absent credential must be shown as absent.');
secret_assert(str_contains($page, 'placeholder='), 'A stored credential must render a masked placeholder.');
secret_assert(str_contains($page, 'Leave this blank to keep it'), 'The field must state what leaving it blank does.');

// The mask must never become a value. As a value it would be submitted and
// saved as the literal credential, silently replacing the real one.
secret_assert(
	! preg_match('/value="(?:&bull;|\x{2022})/u', $page),
	'The mask must be a placeholder, never a value that could be submitted.'
);
secret_assert(str_contains($page, 'value=""'), 'The credential input must always submit empty unless retyped.');

// A constant outranks the database, so reporting "not set" while one is in
// force would be untrue.
secret_assert(str_contains($page, "defined('CF7AIV_'"), 'A credential supplied by constant must count as stored.');

// Removal has to be possible; with blank meaning "keep" there was previously no
// way to take a credential out again.
secret_assert(str_contains($page, 'secrets_clear'), 'The field must offer explicit removal.');

$hooks = file_get_contents(__DIR__ . '/../src/Admin/AdminHooks.php');
secret_assert(is_string($hooks), 'AdminHooks source must be readable.');
secret_assert(str_contains($hooks, 'secretsToClear'), 'Removal must be handled when saving.');
secret_assert(
	strpos($hooks, 'foreach($cleared as $key){unset($stored[$key]);}') < strpos($hooks, 'foreach($secrets as $key=>$value){$stored[$key]=Crypto::encrypt($value);}'),
	'A typed replacement must win over a removal request, so removal is applied first.'
);

echo "PASS SecretFieldStateTest\n";
