<?php

declare(strict_types=1);

namespace CF7AIV\Classification;

use CF7AIV\Hooks;
use CF7AIV\Infrastructure\Crypto;
use CF7AIV\Infrastructure\Queue;
use CF7AIV\Infrastructure\Settings;
use CF7AIV\Infrastructure\WordPressHttpTransport;
use CF7AIV\Providers\DeepSeekProvider;
use CF7AIV\Providers\OpenAIProvider;

final class ClassificationHooks {
	public static function register(): void {
		add_action(Hooks::AFTER_CAPTURE, [self::class, 'afterCapture'], 10, 1);
		add_action('cf7aiv_classify_item', [self::class, 'classify'], 10, 3);
		add_filter('cf7aiv_ai_providers', [self::class, 'registerProviders']);
	}

	public static function afterCapture(mixed $itemId): void {
		if ((int) $itemId > 0) { Queue::enqueue('cf7aiv_classify_item', [(int) $itemId]); }
	}

	public static function classify(mixed $itemId, mixed $replacePrediction = false, mixed $historicalWhitelistSnapshot = []): void {
		$whitelist = is_array($historicalWhitelistSnapshot) ? ContextBuilder::safeWhitelist($historicalWhitelistSnapshot) : [];
		if ((int) $itemId > 0) { ClassificationWorker::standard()->process((int) $itemId, $replacePrediction === true, $whitelist); }
	}

	/**
	 * Build the providers that classification and verification use.
	 *
	 * These read the configured timeout like the site scan does. They were
	 * built with a fixed 30 seconds, so raising the setting changed the scan and
	 * nothing else, and a deep-reasoning verification pass timed out whatever the
	 * setting said.
	 *
	 * @param array<string, mixed> $providers @return array<string, mixed>
	 */
	public static function registerProviders(array $providers): array {
		$settings = Settings::withDefaults(function_exists('get_option') ? (array) get_option(Settings::OPTION, Settings::defaults()) : []);
		$transport = new WordPressHttpTransport();
		$timeout = (int) ($settings['providers']['timeout'] ?? Settings::DEFAULT_PROVIDER_TIMEOUT);
		$providers['deepseek'] = new DeepSeekProvider(
			$transport, Crypto::secret('deepseek_api_key'),
			(string) ($settings['providers']['deepseek_base_url'] ?? 'https://api.deepseek.com/chat/completions'),
			(string) ($settings['providers']['deepseek_model'] ?? DeepSeekProvider::DEFAULT_MODEL),
			$timeout,
			(string) ($settings['providers']['deepseek_reasoning_effort'] ?? 'off'),
		);
		$providers['openai'] = new OpenAIProvider(
			$transport, Crypto::secret('openai_api_key'),
			(string) ($settings['providers']['openai_base_url'] ?? 'https://api.openai.com/v1/responses'),
			(string) ($settings['providers']['openai_model'] ?? 'gpt-5.4-mini'),
			$timeout,
			(string) ($settings['providers']['openai_reasoning_effort'] ?? 'unsupported'),
		);
		return $providers;
	}
}
