<?php

declare(strict_types=1);

namespace CF7AIV\Infrastructure;

use CF7AIV\Providers\PermanentProviderException;
use CF7AIV\Providers\TransientProviderException;

final class WordPressHttpTransport implements HttpTransportInterface {
	public function post(string $url, array $arguments): HttpResponse {
		if (! function_exists('wp_remote_post')) {
			throw new PermanentProviderException('WordPress HTTP transport is unavailable.');
		}
		$response = wp_remote_post($url, $arguments);
		if ((function_exists('is_wp_error') && is_wp_error($response)) || (class_exists('WP_Error', false) && $response instanceof \WP_Error)) {
			$message = method_exists($response, 'get_error_message') ? (string) $response->get_error_message() : 'WordPress HTTP request failed.';
			$code = method_exists($response, 'get_error_code') ? strtolower((string) $response->get_error_code()) : '';
			if (str_contains($code, 'timeout') || str_contains(strtolower($message), 'timed out')) {
				throw new TransientProviderException('Provider request timed out.');
			}
			throw new TransientProviderException('Provider request failed in transport.');
		}
		$status = function_exists('wp_remote_retrieve_response_code') ? (int) wp_remote_retrieve_response_code($response) : (int) ($response['response']['code'] ?? 0);
		$body = function_exists('wp_remote_retrieve_body') ? (string) wp_remote_retrieve_body($response) : (string) ($response['body'] ?? '');
		return new HttpResponse($status, $body);
	}
}
