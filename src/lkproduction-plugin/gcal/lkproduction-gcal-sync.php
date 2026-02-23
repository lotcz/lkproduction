<?php
/**
 *
 * Requires the Google API PHP Client:
 *   composer require google/apiclient:^2.0
 */

if (!defined('ABSPATH')) {
	exit;
}

require_once __DIR__ . '/../includes/lk-common.php';
require_once __DIR__ . '/lkproduction-gcal-common.php';
require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Order meta key where we store the Google Calendar event ID.
 */
const LK_GCAL_META_EVENT_ID = '_rental_gcal_event_id';

/**
 * Reset Google Event ID associated with order
 */
function lk_gcal_reset_event_id(WC_Order $order): void {
	$order->delete_meta_data(LK_GCAL_META_EVENT_ID);
	$order->save_meta_data();
}

/**
 * Save Google Event ID associated with order
 */
function lk_gcal_set_event_id(WC_Order $order, string $event_id): void {
	$order->update_meta_data(LK_GCAL_META_EVENT_ID, $event_id);
	$order->save_meta_data();
}

/**
 * Get Google Event ID associated with order
 */
function lk_gcal_get_event_id(WC_Order $order): string {
	return $order->get_meta(LK_GCAL_META_EVENT_ID);
}

function lk_gcal_parse_date(string $raw): ?DateTime {
	$raw = trim($raw);

	// Unix timestamp
	if (ctype_digit($raw)) {
		return (new DateTime())->setTimestamp((int)$raw);
	}

	// Try common formats explicitly before falling back to strtotime
	$formats = [
		'Y-m-d\TH:i',      // 2026-02-12T11:59
		'Y-m-d\TH:i:s',    // 2026-02-12T11:59:00
		'Y-m-d H:i:s',     // 2026-02-12 11:59:00
		'Y-m-d H:i',       // 2026-02-12 11:59
		'Y-m-d',           // 2026-02-12
		'd/m/Y H:i',       // 12/02/2026 11:59
		'd/m/Y',           // 12/02/2026
		'd.m.Y',           // 12.02.2026
	];

	$tz = wp_timezone();

	foreach ($formats as $format) {
		$dt = DateTime::createFromFormat($format, $raw, $tz);
		if ($dt !== false) {
			return $dt;
		}
	}

	// Last resort — strtotime ignores $tz but at least parses the value
	$ts = strtotime($raw);
	return $ts !== false ? (new DateTime('@' . $ts))->setTimezone($tz) : null;
}

function lk_gcal_build_summary(WC_Order $order): string {
	$event_name = lk_order_get_event_name($order);
	return empty($event_name) ? sprintf("Objednávka #%s", $order->get_order_number()) : $event_name;
}

function lk_gcal_build_description(WC_Order $order): string {
	$lines = [
		sprintf("Od: %s", lk_datetime(lk_order_get_start_date($order))),
		sprintf("Do: %s", lk_datetime(lk_order_get_end_date($order))),
		sprintf("Zákazník: %s", $order->get_formatted_billing_full_name()),
		sprintf("Email: %s", $order->get_billing_email()),
		sprintf("Telefon: %s", $order->get_billing_phone()),
		sprintf("Objednávka: %s", $order->get_edit_order_url())
	];

	return implode("\n", $lines);
}

/**
 * Populate (or re-populate) a Google Calendar Event from order data.
 */
function lk_gcal_populate_event(Google\Service\Calendar\Event $event, WC_Order $order): Google\Service\Calendar\Event {
	$start_date = lk_order_get_start_date($order);
	$end_date = lk_order_get_end_date($order);

	$start_dt = lk_gcal_parse_date($start_date);
	$end_dt = lk_gcal_parse_date($end_date);

	if (!$start_dt || !$end_dt) {
		throw new RuntimeException(
			sprintf(
				__('Order #%d has unparseable rental dates: start=%s end=%s', 'rental-gcal'),
				$order->get_id(),
				$start_date,
				$end_date
			)
		);
	}

	$tz = wp_timezone();

	$event->setSummary(lk_gcal_build_summary($order));
	$event->setDescription(lk_gcal_build_description($order));

	$event->setStart(new Google\Service\Calendar\EventDateTime([
		'dateTime' => $start_dt->format(DateTime::ATOM),
		'timeZone' => $tz->getName(),
	]));
	$event->setEnd(new Google\Service\Calendar\EventDateTime([
		'dateTime' => $end_dt->format(DateTime::ATOM),
		'timeZone' => $tz->getName(),
	]));
	// Store WC order ID in extended properties so it's queryable via the API
	$event->setExtendedProperties(new Google\Service\Calendar\EventExtendedProperties([
		'private' => ['wc_order_id' => (string)$order->get_id()],
	]));

	return $event;
}

/**
 * Build a new Google Calendar Event object from an order.
 */
function lk_gcal_build_event(WC_Order $order): Google\Service\Calendar\Event {
	$event = new Google\Service\Calendar\Event();
	return lk_gcal_populate_event($event, $order);
}

/**
 * Build an authenticated Google Calendar service using the stored service account JSON.
 *
 * @throws RuntimeException if credentials are missing or invalid.
 */
function lk_gcal_build_service(): Google\Service\Calendar {
	if (!class_exists('Google\Client')) {
		throw new RuntimeException(
			__('Google API Client library is not installed. Run: composer require google/apiclient:^2.0', 'rental-gcal')
		);
	}

	$credentials = lk_gcal_get_credentials();
	$client = new Google\Client();
	$client->setAuthConfig($credentials);
	$client->setScopes([Google\Service\Calendar::CALENDAR]);

	return new Google\Service\Calendar($client);
}

/**
 * Create a new calendar event for an order.
 * Stores the resulting event ID in order meta.
 *
 * @throws Exception on API error.
 */
function lk_gcal_create_event(WC_Order $order): void {
	$event = lk_gcal_build_event($order);
	$service = lk_gcal_build_service();
	$created = $service->events->insert(lk_gcal_get_calendar_id(), $event);
	lk_gcal_set_event_id($order, $created->getId());
}

/**
 * Delete the calendar event for an order and remove the stored event ID.
 *
 * @throws Exception on API error (except 404 which is silently ignored).
 */
function lk_gcal_delete_event(WC_Order $order): void {
	$event_id = lk_gcal_get_event_id($order);

	if (empty($event_id)) {
		return; // nothing to delete
	}

	$calendar_id = lk_gcal_get_calendar_id();
	$service = lk_gcal_build_service();

	try {
		$service->events->delete($calendar_id, $event_id);
	} catch (Google\Service\Exception $e) {
		// Already gone – that's fine
		if ($e->getCode() !== 404) {
			throw $e;
		}
	}

	lk_gcal_reset_event_id($order);
}

/**
 * Update the existing calendar event for an order.
 * Falls back to creating a new event if none is stored.
 *
 * @throws Exception on API error.
 */
function lk_gcal_update_event(WC_Order $order): void {
	if (!lk_order_is_calendar_valid($order)) {
		lk_gcal_delete_event($order);
		return;
	}

	$event_id = lk_gcal_get_event_id($order);

	if (empty($event_id)) {
		lk_gcal_create_event($order);
		return;
	}

	$calendar_id = lk_gcal_get_calendar_id();
	$service = lk_gcal_build_service();

	// Fetch the existing event so we preserve any fields we don't own
	try {
		$event = $service->events->get($calendar_id, $event_id);
	} catch (Google\Service\Exception $e) {
		// Event was deleted on the Google side – recreate it
		if ($e->getCode() === 404) {
			lk_gcal_reset_event_id($order);
			lk_gcal_create_event($order);
			return;
		}
		throw $e;
	}

	lk_gcal_populate_event($event, $order);
	$service->events->update($calendar_id, $event_id, $event);
}

/**
 * Translate a Google API exception into a human-readable string.
 */
function lk_gcal_friendly_api_error(Google\Service\Exception $e): string {
	$errors = $e->getErrors();
	$message = !empty($errors[0]['message']) ? $errors[0]['message'] : $e->getMessage();

	switch ($e->getCode()) {
		case 401:
			return __('Authentication failed (401). Check that the service account key is valid.', 'rental-gcal');
		case 403:
			return sprintf(
			/* translators: %s = API error message */
				__('Permission denied (403): %s. Make sure the calendar is shared with the service account.', 'rental-gcal'),
				$message
			);
		case 404:
			return __('Calendar not found (404). Check the Calendar ID.', 'rental-gcal');
		default:
			return sprintf('(%d) %s', $e->getCode(), $message);
	}
}

/**
 * Verify that the service account can access the configured calendar.
 * Returns true on success or an error message string on failure.
 *
 * @return true|string
 */
function lk_gcal_test_connection() {
	try {
		$calendar_id = lk_gcal_get_calendar_id();
		$service = lk_gcal_build_service();
		$service->calendars->get($calendar_id);
		return true;
	} catch (Google\Service\Exception $e) {
		return lk_gcal_friendly_api_error($e);
	} catch (Exception $e) {
		return $e->getMessage();
	}
}
