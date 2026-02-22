<?php
/**
 * Rental GCal Sync – Sync Engine
 *
 * Handles creating, updating, and deleting Google Calendar events
 * in response to WooCommerce order status changes.
 *
 * Requires the Google API PHP Client:
 *   composer require google/apiclient:^2.0
 */

if (!defined('ABSPATH')) {
	exit;
}

require_once __DIR__ . '/../includes/lk-common.php';

class Rental_GCal_Sync {

	/**
	 * Order meta key where we store the Google Calendar event ID.
	 */
	const META_EVENT_ID = '_rental_gcal_event_id';

	/** @var Google\Service\Calendar */
	private $service;

	/** @var string */
	private $calendar_id;

	/**
	 * @throws RuntimeException if credentials or calendar ID are missing.
	 */
	public function __construct() {
		$this->calendar_id = get_option(Rental_GCal_Admin::OPTION_CALENDAR_ID, '');

		if (empty($this->calendar_id)) {
			throw new RuntimeException(
				__('Rental GCal Sync: no Calendar ID configured.', 'rental-gcal')
			);
		}

		$this->service = $this->build_service();
	}

	private function parse_date(string $raw): ?DateTime {
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

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Create a new calendar event for an order.
	 * Stores the resulting event ID in order meta.
	 *
	 * @throws Exception on API error.
	 */
	public function create_event(WC_Order $order): void {
		$event = $this->build_event($order);
		$created = $this->service->events->insert($this->calendar_id, $event);

		$order->update_meta_data(self::META_EVENT_ID, $created->getId());
		$order->save_meta_data();

		$this->log(sprintf('Created event %s for order #%d', $created->getId(), $order->get_id()));
	}

	/**
	 * Update the existing calendar event for an order.
	 * Falls back to creating a new event if none is stored.
	 *
	 * @throws Exception on API error.
	 */
	public function update_event(WC_Order $order): void {
		$event_id = $order->get_meta(self::META_EVENT_ID);

		if (empty($event_id)) {
			$this->create_event($order);
			return;
		}

		// Fetch the existing event so we preserve any fields we don't own
		try {
			$event = $this->service->events->get($this->calendar_id, $event_id);
		} catch (Google\Service\Exception $e) {
			// Event was deleted on the Google side – recreate it
			if ($e->getCode() === 404) {
				$order->delete_meta_data(self::META_EVENT_ID);
				$order->save_meta_data();
				$this->create_event($order);
				return;
			}
			throw $e;
		}

		$this->populate_event($event, $order);
		$this->service->events->update($this->calendar_id, $event_id, $event);

		$this->log(sprintf('Updated event %s for order #%d', $event_id, $order->get_id()));
	}

	/**
	 * Delete the calendar event for an order and remove the stored event ID.
	 *
	 * @throws Exception on API error (except 404 which is silently ignored).
	 */
	public function delete_event(WC_Order $order): void {
		$event_id = $order->get_meta(self::META_EVENT_ID);

		if (empty($event_id)) {
			return; // nothing to delete
		}

		try {
			$this->service->events->delete($this->calendar_id, $event_id);
			$this->log(sprintf('Deleted event %s for order #%d', $event_id, $order->get_id()));
		} catch (Google\Service\Exception $e) {
			// Already gone – that's fine
			if ($e->getCode() !== 404) {
				throw $e;
			}
		}

		$order->delete_meta_data(self::META_EVENT_ID);
		$order->save_meta_data();
	}

	/**
	 * Verify that the service account can access the configured calendar.
	 * Returns true on success or an error message string on failure.
	 *
	 * @return true|string
	 */
	public function test_connection() {
		try {
			$this->service->calendars->get($this->calendar_id);
			return true;
		} catch (Google\Service\Exception $e) {
			return $this->friendly_api_error($e);
		} catch (Exception $e) {
			return $e->getMessage();
		}
	}

	// -------------------------------------------------------------------------
	// Hook handlers (called from main plugin file)
	// -------------------------------------------------------------------------

	public static function handle_order_save(WC_Order $order): void {
		if (get_option(Rental_GCal_Admin::OPTION_ENABLED, 'no') !== 'yes') return;

		$sync = new self();

		if (!lk_order_is_calendar_valid($order)) {
			$sync->delete_event($order); // no-op if no event ID
		} else {
			$event_id = $order->get_meta(self::META_EVENT_ID);
			$event_id ? $sync->update_event($order) : $sync->create_event($order);
		}
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a new Google Calendar Event object from an order.
	 */
	private function build_event(WC_Order $order): Google\Service\Calendar\Event {
		$event = new Google\Service\Calendar\Event();
		return $this->populate_event($event, $order);
	}

	/**
	 * Populate (or re-populate) a Google Calendar Event from order data.
	 */
	private function populate_event(
		Google\Service\Calendar\Event $event,
		WC_Order $order
	): Google\Service\Calendar\Event {

		$start_date = lk_order_get_start_date($order);
		$end_date = lk_order_get_end_date($order);

		self::log($start_date);

		$start_dt = $this->parse_date($start_date);
		$end_dt = $this->parse_date($end_date);

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

		$event->setSummary($this->build_summary($order));
		$event->setDescription($this->build_description($order));

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

		self::log('Event payload: ' . wp_json_encode($event->toSimpleObject()));

		return $event;
	}

	private function build_summary(WC_Order $order): string {
		return sprintf(
		/* translators: 1: order number, 2: customer full name */
			__('Rental #%1$s — %2$s', 'rental-gcal'),
			$order->get_order_number(),
			trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name())
		);
	}

	private function build_description(WC_Order $order): string {
		$items = array_map(
			fn(WC_Order_Item $item) => sprintf('%s × %d', $item->get_name(), $item->get_quantity()),
			array_values($order->get_items())
		);

		$start = lk_order_get_start_date($order);
		$end = lk_order_get_end_date($order);

		$lines = [
			__('Items:', 'rental-gcal') . ' ' . implode(', ', $items),
			__('Start:', 'rental-gcal') . ' ' . $start,
			__('End:', 'rental-gcal') . ' ' . $end,
			__('Customer:', 'rental-gcal') . ' ' . $order->get_formatted_billing_full_name(),
			__('Email:', 'rental-gcal') . ' ' . $order->get_billing_email(),
			__('Phone:', 'rental-gcal') . ' ' . $order->get_billing_phone(),
			__('Order:', 'rental-gcal') . ' ' . $order->get_edit_order_url(),
		];

		return implode("\n", array_filter($lines));
	}

	/**
	 * Build an authenticated Google Calendar service using the stored service account JSON.
	 *
	 * @throws RuntimeException if credentials are missing or invalid.
	 */
	private function build_service(): Google\Service\Calendar {
		if (!class_exists('Google\Client')) {
			throw new RuntimeException(
				__('Google API Client library is not installed. Run: composer require google/apiclient:^2.0', 'rental-gcal')
			);
		}

		$admin = new Rental_GCal_Admin();
		$json = $admin->get_credentials_json();

		if (empty($json)) {
			throw new RuntimeException(
				__('Rental GCal Sync: no service account credentials saved.', 'rental-gcal')
			);
		}

		$client = new Google\Client();
		$client->setAuthConfig(json_decode($json, true));
		$client->setScopes([Google\Service\Calendar::CALENDAR]);

		return new Google\Service\Calendar($client);
	}

	/**
	 * Translate a Google API exception into a human-readable string.
	 */
	private function friendly_api_error(Google\Service\Exception $e): string {
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

	private static function active_statuses(): array {
		return apply_filters('rental_gcal_active_statuses', ['processing', 'on-hold', 'completed']);
	}

	private static function cancelled_statuses(): array {
		return apply_filters('rental_gcal_cancelled_statuses', ['cancelled', 'refunded', 'failed']);
	}

	private static function log(string $message, string $level = 'info'): void {
		if (!defined('WP_DEBUG') || !WP_DEBUG) {
			return;
		}
		error_log('[Rental GCal] ' . $level . ': ' . $message);
	}
}
