<?php

if (!defined('ABSPATH')) {
	exit;
}

require_once __DIR__ . '/../includes/lk-common.php';

const LK_GCAL_OPTION_CALENDAR_ID = 'rental_gcal_calendar_id';
const LK_GCAL_OPTION_CREDENTIALS = 'rental_gcal_service_account_json';
const LK_GCAL_OPTION_ENABLED = 'rental_gcal_enabled';
const LK_GCAL_NONCE_ACTION = 'rental_gcal_save_settings';

/**
 * Simple reversible encryption using WP secret keys.
 * For stronger security, consider using libsodium (available since PHP 7.2 / WP 5.2).
 */

function lk_gcal_derive_key(): string {
	// Stretch WP secret key to 32 bytes required by libsodium
	$secret = defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : wp_salt('secure_auth');
	return substr(hash('sha256', $secret, true), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
}

function lk_gcal_encrypt(string $plaintext): string {
	if (function_exists('sodium_crypto_secretbox')) {
		$key = lk_gcal_derive_key();
		$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		$ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);
		return base64_encode($nonce . $ciphertext);
	}
	// Fallback: base64 only (no real encryption — warn in UI)
	return base64_encode($plaintext);
}

function lk_gcal_decrypt(string $encoded): string|false {
	if (function_exists('sodium_crypto_secretbox_open')) {
		$decoded = base64_decode($encoded, true);
		if ($decoded === false) {
			return false;
		}
		$nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		$ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		$key = lk_gcal_derive_key();
		$result = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
		return $result;
	}
	// Fallback
	$decoded = base64_decode($encoded, true);
	return $decoded !== false ? $decoded : false;
}

/**
 * Store and retrieve plugin settings
 */

// ENABLED

function lk_gcal_set_enabled(bool $enabled) {
	update_option(LK_GCAL_OPTION_ENABLED, $enabled ? 'yes' : 'no');
}

function lk_gcal_is_enabled(): bool {
	return get_option(LK_GCAL_OPTION_ENABLED, 'no') === 'yes';
}

// CALENDAR ID

function lk_gcal_set_calendar_id(string $calendar_id) {
	update_option(LK_GCAL_OPTION_CALENDAR_ID, $calendar_id);
}

function lk_gcal_get_calendar_id(): string {
	return get_option(LK_GCAL_OPTION_CALENDAR_ID, '');
}

// CREDENTIALS

function lk_gcal_delete_credentials() {
	delete_option(LK_GCAL_OPTION_CREDENTIALS);
}

function lk_gcal_validate_credentials_json(string $json): true | string {
	$data = json_decode($json, true);

	if (json_last_error() !== JSON_ERROR_NONE) {
		return __('Could not parse JSON – check for syntax errors.', 'rental-gcal');
	}

	$required = ['type', 'project_id', 'private_key_id', 'private_key', 'client_email'];
	foreach ($required as $field) {
		if (empty($data[$field])) {
			return sprintf(__('Missing required field: %s', 'rental-gcal'), $field);
		}
	}

	if ($data['type'] !== 'service_account') {
		return __('JSON "type" must be "service_account".', 'rental-gcal');
	}

	return true;
}

function lk_gcal_set_credentials_json(string $json) {
	$validation = lk_gcal_validate_credentials_json($json);
	if ($validation !== true) {
		throw new Exception('Cannot save Google credentials: ' . $validation);
	}
	$encrypted = lk_gcal_encrypt($json);
	update_option(LK_GCAL_OPTION_CREDENTIALS, $encrypted);
}

function lk_gcal_get_credentials_json(): string {
	$stored = get_option(LK_GCAL_OPTION_CREDENTIALS, '');
	if (empty($stored)) {
		return '';
	}
	return lk_gcal_decrypt($stored) ?: '';
}

function lk_gcal_get_credentials(): array | null {
	$json = lk_gcal_get_credentials_json();
	if (empty($json)) {
		return null;
	}
	return json_decode($json, true);
}

function lk_gcal_has_valid_credentials(): bool {
	$data = lk_gcal_get_credentials();
	return is_array($data) && isset($data['type']) && $data['type'] === 'service_account';
}

function lk_gcal_get_client_email(): string {
	$data = lk_gcal_get_credentials();
	return $data['client_email'] ?? '';
}
