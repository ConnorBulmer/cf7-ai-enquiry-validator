<?php

declare(strict_types=1);

namespace CF7AIV\Contracts;

/**
 * Lets a destination registered by another plugin say when it can send.
 *
 * The delivery worker sends only to a destination it knows to be configured,
 * holding identifiers it can attribute with, under the consent it needs. It
 * knows that for the built-in destinations from their settings, and nothing
 * for any other key - so a destination added through cf7aiv_destinations was
 * gated "destination not configured" every time. A destination that
 * implements this interface answers those questions for itself. One that does
 * not stays blocked.
 *
 * Every method should be fast and side-effect free: the worker calls them for
 * each item, and Tracking Health calls them to explain a decision.
 */
interface DestinationReadinessInterface extends DestinationInterface {
	/** Whether the destination has everything it needs to send, or to dry-run: endpoint, credentials, enabled. */
	public function isConfigured(): bool;

	/**
	 * Whether this enquiry carries the identifiers the destination attributes with.
	 *
	 * @param array<string,mixed> $attribution The attribution captured with the enquiry (click ids, GA client and session ids, UTMs, pages).
	 * @param array<string,string> $consent The actual consent states: ad_storage, analytics_storage, ad_user_data, ad_personalization.
	 */
	public function signalsReady(array $attribution, array $consent): bool;

	/**
	 * Consent keys that must be granted before sending under the default "respect" consent mode.
	 *
	 * Only ad_storage, analytics_storage, ad_user_data and ad_personalization are
	 * recognised. An empty or unrecognised list falls back to requiring both
	 * analytics_storage and ad_storage: a destination cannot opt out of consent.
	 *
	 * @return list<string>
	 */
	public function requiredConsent(): array;
}
