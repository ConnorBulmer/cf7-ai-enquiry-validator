<?php

declare(strict_types=1);

namespace CF7AIV\Intake;

use CF7AIV\Infrastructure\Settings;
use CF7AIV\Sources\CFDB7Source;
use CF7AIV\Sources\FlamingoSource;
use Throwable;

final class IntakeHooks {
	/** @var array<int, true> */
	private static array $capturedSubmissions = [];

	public static function register(): void {
		add_action('wp_enqueue_scripts', [self::class, 'enqueue']);
		add_action('template_redirect', [self::class, 'serverCookie']);
		add_filter('wpcf7_form_elements', [self::class, 'injectFields']);
		add_action('wpcf7_after_flamingo', [self::class, 'afterFlamingo'], 10, 1);
		add_action('wpcf7_submit', [self::class, 'afterSubmit'], 20, 2);
		add_filter('cf7aiv_submission_sources', [self::class, 'registerSources']);
		add_filter('cf7aiv_consent_resolvers', [self::class, 'registerConsentResolvers']);
		$basename = function_exists('plugin_basename') ? plugin_basename(\CF7AIV\FILE) : 'cf7-ai-enquiry-validator/' . basename(\CF7AIV\FILE);
		add_filter('wp_consent_api_registered_' . $basename, [self::class, 'declareConsentApiCompatibility']);
	}

	public static function enqueue(): void {
		try {
			$settings = (array) get_option(Settings::OPTION, []);
			$days = max(1, min(365, (int) ($settings['attribution']['retention_days'] ?? 90)));
			wp_enqueue_script('cf7aiv-attribution', \CF7AIV\URL . 'assets/js/attribution.js', [], \CF7AIV\VERSION, true);
			wp_add_inline_script('cf7aiv-attribution', 'window.cf7aivAttribution=' . wp_json_encode(['cookieName' => AttributionCookie::NAME, 'retentionDays' => $days, 'fields' => FieldNames::map()]) . ';', 'before');
		} catch (Throwable $error) { self::log($error); }
	}

	public static function serverCookie(): void {
		try { AttributionCookie::serverFallback(); } catch (Throwable $error) { self::log($error); }
	}

	public static function injectFields(mixed $html): mixed {
		try { return is_string($html) ? FieldNames::injectMissing($html) : $html; } catch (Throwable $error) { self::log($error); return $html; }
	}

	public static function afterFlamingo(mixed $result): void {
		try {
			if (! is_array($result)) { return; }
			$id = (int) ($result['flamingo_inbound_id'] ?? 0);
			if ($id < 1 || ! class_exists('WPCF7_Submission')) { return; }
			$submission = \WPCF7_Submission::get_instance();
			if (! is_object($submission) || ! method_exists($submission, 'get_contact_form')) { return; }
			if ((new CaptureService())->capture($submission->get_contact_form(), $result, 'flamingo', (string) $id) > 0) {
				self::$capturedSubmissions[spl_object_id($submission)] = true;
			}
		} catch (Throwable $error) { self::log($error); }
	}

	public static function afterSubmit(mixed $contactForm, mixed $result): void {
		try {
			if (! is_object($contactForm) || ! is_array($result)) { return; }
			$submission = class_exists('WPCF7_Submission') ? \WPCF7_Submission::get_instance() : null;
			if (is_object($submission) && isset(self::$capturedSubmissions[spl_object_id($submission)])) { return; }
			(new CaptureService())->capture($contactForm, $result);
		} catch (Throwable $error) { self::log($error); }
	}

	/** @param array<string, mixed> $sources @return array<string, mixed> */
	public static function registerSources(array $sources): array {
		$sources['flamingo'] = new FlamingoSource();
		$sources['cfdb7'] = new CFDB7Source();
		return $sources;
	}

	/** @param array<string, mixed> $resolvers @return array<string, mixed> */
	public static function registerConsentResolvers(array $resolvers): array {
		$resolvers['most_restrictive'] = new ConsentResolver();
		return $resolvers;
	}

	public static function declareConsentApiCompatibility(mixed $registered = false): bool {
		return true;
	}

	private static function log(Throwable $error): void {
		if (function_exists('error_log')) { error_log('[CF7AIV intake] ' . $error->getMessage()); }
	}
}
