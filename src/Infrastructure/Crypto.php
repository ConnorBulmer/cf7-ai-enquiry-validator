<?php

declare(strict_types=1);

namespace CF7AIV\Infrastructure;

use RuntimeException;

final class Crypto {
	private const CIPHER = 'aes-256-gcm';

	public static function encrypt(string $plaintext): string {
		if (! function_exists('openssl_encrypt')) {
			throw new RuntimeException('OpenSSL is required to encrypt CF7 AI Enquiry Validator data.');
		}
		$iv = random_bytes(12);
		$tag = '';
		$ciphertext = openssl_encrypt($plaintext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
		if ($ciphertext === false) {
			throw new RuntimeException('Could not encrypt CF7 AI Enquiry Validator data.');
		}
		return 'v1.' . self::base64url($iv) . '.' . self::base64url($tag) . '.' . self::base64url($ciphertext);
	}

	public static function decrypt(string $encoded): string {
		$parts = explode('.', $encoded, 4);
		if (count($parts) !== 4 || $parts[0] !== 'v1') {
			throw new RuntimeException('Invalid encrypted CF7 AI Enquiry Validator payload.');
		}
		[$version, $iv, $tag, $ciphertext] = $parts;
		$plaintext = openssl_decrypt(self::unbase64url($ciphertext), self::CIPHER, self::key(), OPENSSL_RAW_DATA, self::unbase64url($iv), self::unbase64url($tag));
		if ($plaintext === false) {
			throw new RuntimeException('Could not decrypt CF7 AI Enquiry Validator data.');
		}
		return $plaintext;
	}

	public static function secret(string $name): string {
		$constant = 'CF7AIV_' . strtoupper($name);
		if (defined($constant)) {
			return (string) constant($constant);
		}
		$secrets = function_exists('get_option') ? (array) get_option('cf7aiv_secrets', []) : [];
		return isset($secrets[$name]) ? self::decrypt((string) $secrets[$name]) : '';
	}

	private static function key(): string {
		$material = (defined('AUTH_KEY') ? (string) AUTH_KEY : '') . '|' . (defined('SECURE_AUTH_KEY') ? (string) SECURE_AUTH_KEY : '') . '|cf7aiv/v1';
		return hash('sha256', $material, true);
	}

	private static function base64url(string $value): string {
		return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
	}

	private static function unbase64url(string $value): string {
		$decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
		if ($decoded === false) {
			throw new RuntimeException('Invalid encrypted CF7 AI Enquiry Validator payload encoding.');
		}
		return $decoded;
	}
}
