<?php

declare(strict_types=1);

namespace CF7AIV\Admin;

use CF7AIV\Classification\Redactor;
use CF7AIV\Contracts\DestinationInterface;
use CF7AIV\Delivery\ConsentPolicy;
use CF7AIV\Delivery\DestinationReadiness;
use CF7AIV\Delivery\WpMappingRepository;
use CF7AIV\Infrastructure\Crypto;
use CF7AIV\Infrastructure\Migration;
use CF7AIV\Infrastructure\Settings;
use CF7AIV\Intake\AttributionDiagnostics;
use Throwable;

final class DiagnosticsService {
	/** @return array<string,mixed> */
	public function inspect(): array {
		$settings = $this->settings();
		$result = [
			'plugin_version' => defined('CF7AIV\\VERSION') ? \CF7AIV\VERSION : 'unknown',
			'schema' => [
				'expected' => Migration::VERSION,
				'installed' => function_exists('get_option') ? get_option('cf7aiv_schema_version', null) : null,
			],
			'configuration' => [
				'automation_mode' => (string) ($settings['automation']['mode'] ?? 'shadow'),
				'consent_mode' => (string) ($settings['consent']['mode'] ?? 'respect'),
				'consent_integrations' => $this->consentIntegrations(),
			],
			'queue' => [
				'by_state' => [],
				'captured_unclassified' => 0,
				'oldest_captured_at' => null,
			],
			'attribution' => [
				'total' => 0,
				'ready' => 0,
				'unavailable' => 0,
				'issue_codes' => [],
			],
			'destinations' => $this->destinationReadiness($settings),
			'mappings' => [],
		];
		$wpdb = $this->wpdb();
		if ($wpdb === null) {
			return $result;
		}
		$items = $wpdb->prefix . 'cf7aiv_items';
		$stateRows = $wpdb->get_results("SELECT state AS label, COUNT(*) AS total FROM {$items} GROUP BY state", $this->arrayConstant());
		foreach (is_array($stateRows) ? $stateRows : [] as $row) {
			$result['queue']['by_state'][(string) ($row['label'] ?? '')] = (int) ($row['total'] ?? 0);
		}
		$result['queue']['captured_unclassified'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$items} WHERE state='captured' AND classification_attempts=0");
		$result['queue']['oldest_captured_at'] = $wpdb->get_var("SELECT MIN(created_at) FROM {$items} WHERE state='captured'") ?: null;
		$result['attribution']['total'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$items}");
		$result['attribution']['ready'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$items} WHERE attribution_ready=1");
		$result['attribution']['unavailable'] = max(0, $result['attribution']['total'] - $result['attribution']['ready']);
		$rows = $wpdb->get_results("SELECT consent_encrypted FROM {$items} WHERE attribution_ready=0 AND consent_encrypted IS NOT NULL ORDER BY id DESC LIMIT 1000", $this->arrayConstant());
		$issueCodes = [];
		foreach (is_array($rows) ? $rows : [] as $row) {
			foreach ($this->storedIssueCodes((string) ($row['consent_encrypted'] ?? '')) as $code) {
				$issueCodes[$code] = (int) ($issueCodes[$code] ?? 0) + 1;
			}
		}
		arsort($issueCodes);
		$result['attribution']['issue_codes'] = $issueCodes;
		$result['mappings'] = $this->mappingReadiness($wpdb);
		return $result;
	}

	/** @return array<string,mixed> */
	public function diagnose(int $itemId): array {
		if ($itemId < 1) {
			return ['status' => 'invalid_item_id', 'item_id' => $itemId];
		}
		$wpdb = $this->wpdb();
		if ($wpdb === null) {
			return ['status' => 'database_unavailable', 'item_id' => $itemId];
		}
		$table = $wpdb->prefix . 'cf7aiv_items';
		$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $itemId), $this->arrayConstant());
		if (! is_array($row)) {
			return ['status' => 'not_found', 'item_id' => $itemId];
		}
		$settings = $this->settings();
		[$attribution, $consent, $storedIssues] = $this->signals($row);
		$currentDiagnostics = AttributionDiagnostics::inspect($attribution, $consent, AttributionDiagnostics::gaIdentityNeeded($settings));
		$issues = array_values(array_unique(array_merge($storedIssues, $currentDiagnostics['issues'])));
		$gates = [];
		$this->gate($gates, 'item', true, 'item_found');
		$attempts = (int) ($row['classification_attempts'] ?? 0);
		$classified = trim((string) ($row['decision_state'] ?? '')) !== '' || trim((string) ($row['tier_id'] ?? '')) !== '';
		$this->gate($gates, 'classification', $classified, $classified ? 'classification_available' : ($attempts > 0 ? 'classification_failed' : 'classification_not_attempted'));
		$dispatchable = empty($row['is_shadow']) && empty($row['is_calibration']) && empty($row['is_historical']);
		$this->gate($gates, 'lifecycle', $dispatchable, $dispatchable ? 'live_item' : 'shadow_or_calibration_item');
		$attributionReady = ! empty($row['attribution_ready']) && $currentDiagnostics['ready'];
		$this->gate($gates, 'attribution', $attributionReady, $attributionReady ? 'attribution_ready' : ($issues[0] ?? 'attribution_unavailable'));
		$consentReady = $this->consentReady($consent);
		$this->gate($gates, 'consent', $consentReady, $consentReady ? 'consent_granted' : $this->consentIssue($consent));
		$tier = trim((string) ($row['tier_id'] ?? ''));
		$mappings = [];
		if ($tier !== '') {
			foreach ((new WpMappingRepository())->publishedForTier($tier) as $mapping) {
				$destinationKey = $mapping->destinationKey;
				$destination = in_array($destinationKey, DestinationReadiness::KEYS, true) ? null : $this->registeredDestination($destinationKey);
				$ready = $this->destinationReady($destinationKey, $settings, $destination);
				$required = DestinationReadiness::requiredConsent($destinationKey, $destination);
				$consentDecision = null;
				try {
					$consentDecision = ConsentPolicy::evaluate((string) ($settings['consent']['mode'] ?? 'respect'), $consent, (array) ($settings['consent']['override'] ?? []), $required);
				} catch (Throwable) {
					$consentDecision = null;
				}
				$permitted = $consentDecision !== null && $consentDecision->permitted;
				$signalsReady = $this->signalsReady($destinationKey, $attribution, $row, $settings, $consent, $destination);
				$mappings[] = [
					'key' => $mapping->key,
					'version' => $mapping->version,
					'destination' => $destinationKey,
					'published' => $mapping->isPublished(),
					'paused' => $this->isPaused($destinationKey),
					'configured' => $ready,
					'consent_permitted' => $permitted,
					'signals_ready' => $signalsReady,
					'next_gate' => ! $ready ? 'destination_not_configured' : ($this->isPaused($destinationKey) ? 'destination_paused' : (! $permitted ? 'consent_not_permitted' : (! $signalsReady ? 'signals_not_ready' : 'claim_not_attempted'))),
				];
			}
		}
		if ($mappings === []) {
			$mappings[] = ['next_gate' => $tier === '' ? 'tier_not_classified' : 'no_published_mapping'];
		}
		return [
			'status' => 'ok', 'item_id' => $itemId,
			'source_type' => (string) ($row['source_type'] ?? ''), 'created_at' => (string) ($row['created_at'] ?? ''),
			'state' => (string) ($row['state'] ?? ''), 'review_state' => (string) ($row['review_state'] ?? ''),
			'decision_state' => (string) ($row['decision_state'] ?? ''), 'tier_id' => $tier, 'outcome_id' => (string) ($row['outcome_id'] ?? ''),
			'classification_attempts' => $attempts, 'attribution_ready' => $attributionReady,
			'consent_states' => $this->safeStates($consent), 'attribution_issue_codes' => $issues,
			'gates' => $gates, 'mappings' => $mappings, 'read_only' => true,
		];
	}

	/**
	 * Describe a caught failure in terms an administrator can act on.
	 *
	 * Setup previously reduced every provider failure to one sentence, so a
	 * credential that was never stored, a credential the provider rejected, and a
	 * host that blocks outbound HTTP all presented identically. That is not a
	 * usable signal: the only remaining move is to guess.
	 *
	 * The exception class and its message already separate those cases, so both
	 * are kept. Everything that could carry a secret or an enquiry is stripped
	 * first - Redactor does not know about API keys, so credentials are scrubbed
	 * here before it runs - and the result is length-capped.
	 */
	public static function describeFailure(Throwable $error): string {
		$class = $error::class;
		$position = strrpos($class, '\\');
		$short = $position === false ? $class : substr($class, $position + 1);

		$message = Redactor::redact(self::scrubCredentials($error->getMessage()));
		$message = trim((string) preg_replace('/\s+/', ' ', $message));

		return substr($short . ($message === '' ? '' : ': ' . $message), 0, 300);
	}

	/**
	 * Remove credential-shaped runs before anything is shown or logged.
	 *
	 * Redactor covers enquiry content - addresses, numbers, click identifiers -
	 * but a provider error is a different hazard: it can quote the key it was
	 * given. Widening Redactor would change the classification path too, so the
	 * credential patterns live here instead.
	 */
	private static function scrubCredentials(string $text): string {
		$patterns = [
			'/\b(?:sk|pk|rk)[-_][A-Za-z0-9_\-]{6,}/' => '[REDACTED_KEY]',
			'/\bBearer\s+\S+/i' => 'Bearer [REDACTED_KEY]',
			'/((?:api[_\-]?key|access[_\-]?token|token|secret|password)\s*[:=]\s*)\S+/i' => '$1[REDACTED_KEY]',
			'/\b[A-Za-z0-9_\-]{32,}\b/' => '[REDACTED_KEY]',
		];
		foreach ($patterns as $pattern => $replacement) {
			$text = preg_replace($pattern, $replacement, $text) ?? $text;
		}
		return $text;
	}

	public static function recordGate(int $itemId, string $destination, string $reason): void {
		$context = ['item_id' => $itemId, 'destination' => $destination, 'reason' => $reason];
		if (function_exists('do_action')) { do_action('cf7aiv_delivery_gate', $itemId, $destination, $reason, $context); }
		if (defined('CF7AIV_DEBUG') && CF7AIV_DEBUG && function_exists('error_log')) { error_log('[CF7AIV delivery_gate] ' . (string) json_encode($context, JSON_UNESCAPED_SLASHES)); }
	}
	/** @param list<array<string,mixed>> $gates */
	private function gate(array &$gates, string $name, bool $passed, string $reason): void { $gates[] = ['gate' => $name, 'passed' => $passed, 'reason' => $reason]; }
	/** @return array<string,mixed> */
	private function settings(): array { return Settings::withDefaults(function_exists('get_option') ? (array) get_option(Settings::OPTION, Settings::defaults()) : Settings::defaults()); }
	private function wpdb(): ?object { global $wpdb; return isset($wpdb) && isset($wpdb->prefix) && method_exists($wpdb, 'get_var') ? $wpdb : null; }
	private function arrayConstant(): mixed { return defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A'; }
	/** @return list<string> */
	private function consentIntegrations(): array { $integrations = []; if (function_exists('wp_get_consent_type') || function_exists('wp_has_consent')) { $integrations[] = 'wordpress_consent_api'; } if (function_exists('cmplz_has_consent')) { $integrations[] = 'complianz'; } return $integrations === [] ? ['none_detected'] : $integrations; }
	/** @param array<string,mixed> $settings @return array<string,array<string,mixed>> */
	private function destinationReadiness(array $settings): array { $result = []; foreach (DestinationReadiness::KEYS as $key) { $config = (array) ($settings['destinations'][$key] ?? []); $result[$key] = ['enabled' => ! empty($config['enabled']), 'dry_run' => ! empty($config['dry_run']), 'configured' => $this->destinationReady($key, $settings), 'paused' => $this->isPaused($key)]; } return $result; }
	/** @return list<array<string,mixed>> */
	private function mappingReadiness(object $wpdb): array { $table = $wpdb->prefix . 'cf7aiv_mappings'; $rows = $wpdb->get_results("SELECT tier_id,destination_key,COUNT(*) AS total FROM {$table} WHERE status='published' AND enabled=1 GROUP BY tier_id,destination_key", $this->arrayConstant()); $result = []; foreach (is_array($rows) ? $rows : [] as $row) { $result[] = ['tier_id' => (string) ($row['tier_id'] ?? ''), 'destination' => (string) ($row['destination_key'] ?? ''), 'published_enabled' => (int) ($row['total'] ?? 0)]; } return $result; }
	/** @param array<string,mixed> $settings */
	private function destinationReady(string $key, array $settings, ?DestinationInterface $destination = null): bool { return DestinationReadiness::configured($key, $settings, fn (string $name): string => $this->secret($name), $destination); }
	/** A destination another plugin registered under this key, looked up only for keys that are not built in. */
	private function registeredDestination(string $key): ?DestinationInterface { try { $destinations = function_exists('apply_filters') ? (array) apply_filters('cf7aiv_destinations', []) : []; } catch (Throwable) { return null; } $destination = $destinations[$key] ?? null; return $destination instanceof DestinationInterface ? $destination : null; }
	private function secret(string $name): string { try { return trim(Crypto::secret($name)); } catch (Throwable) { return ''; } }
	private function isPaused(string $key): bool { $paused = function_exists('get_option') ? (array) get_option('cf7aiv_paused_destinations', []) : []; return isset($paused[$key]); }
	/** @return array{0:array<string,mixed>,1:array<string,scalar>,2:list<string>} */
	private function signals(array $row): array { $attribution = []; $consent = []; $issues = []; try { $decoded = json_decode(Crypto::decrypt((string) ($row['attribution_encrypted'] ?? '')), true, 32, JSON_THROW_ON_ERROR); $attribution = is_array($decoded) ? $decoded : []; } catch (Throwable) {} try { $decoded = json_decode(Crypto::decrypt((string) ($row['consent_encrypted'] ?? '')), true, 32, JSON_THROW_ON_ERROR); if (is_array($decoded)) { $consent = is_array($decoded['states'] ?? null) ? $decoded['states'] : $decoded; $issues = $this->safeCodes($decoded['diagnostics'] ?? []); } } catch (Throwable) {} return [$attribution, $consent, $issues]; }
	/** @return list<string> */
	private function storedIssueCodes(string $encrypted): array { try { $decoded = json_decode(Crypto::decrypt($encrypted), true, 32, JSON_THROW_ON_ERROR); return is_array($decoded) ? $this->safeCodes($decoded['diagnostics'] ?? []) : []; } catch (Throwable) { return []; } }
	/** @return list<string> */
	private function safeCodes(mixed $value): array { if (! is_array($value)) { return []; } $result = []; foreach ($value as $code) { if (is_scalar($code) && preg_match('/^[a-z0-9_\-]{1,80}$/', (string) $code) === 1) { $result[] = (string) $code; } } return array_values(array_unique($result)); }
	/** @param array<string,scalar> $consent */
	private function consentReady(array $consent): bool { foreach (['analytics_storage', 'ad_storage'] as $key) { if (($consent[$key] ?? 'unknown') !== 'granted') { return false; } } return true; }
	/** @param array<string,scalar> $consent */
	private function consentIssue(array $consent): string { foreach (['analytics_storage', 'ad_storage'] as $key) { if (($consent[$key] ?? 'unknown') === 'unknown') { return 'missing_consent'; } } return 'consent_denied'; }
	/** @param array<string,mixed> $consent @return array<string,string> */
	private function safeStates(array $consent): array { $result = []; foreach ($consent as $key => $value) { if (is_scalar($value) && preg_match('/^[a-z_]{1,40}$/', (string) $key) === 1) { $result[(string) $key] = substr((string) $value, 0, 40); } } return $result; }
	/** @param array<string,mixed> $attribution @param array<string,mixed> $item @param array<string,mixed> $settings @param array<string,scalar> $consent */
	private function signalsReady(string $key, array $attribution, array $item, array $settings, array $consent, ?DestinationInterface $destination = null): bool { return DestinationReadiness::signalsReady($key, $attribution, $item, $settings, $consent, $destination); }
}
