<?php
/**
 * Tools: kuduy-woo-order-status, kuduy-woo-order-note.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Woo;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Packages;

/**
 * Order status and notes through WC_Order::update_status() / add_order_note() (D10). Anything that makes
 * WooCommerce email the customer is confirm-first (D11): the first call names the emails and changes nothing.
 * "refunded" is refused, because WooCommerce then creates a refund record (no refunds, D11). Undo of a status change
 * puts the previous status back without sending any customer email; an email already sent cannot be taken back.
 */
final class OrderWrite implements Tool, Snapshottable {

	public const STATUSES = array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'failed' );

	/**
	 * Mode.
	 *
	 * @param string $mode status | note.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-woo-order-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return 'status' === $this->mode ? __( 'Change an order\'s status', 'kuduy-mcp' ) : __( 'Add an order note', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'status' === $this->mode
			? 'Changes a WooCommerce order\'s status (pending, processing, on-hold, completed, cancelled, failed) the way the order screen does, with an optional note. WooCommerce may email the customer and change stock: if a customer email would be sent, the first call only reports it (done: false) — ask the user, then call again with confirm: true. Refunds are not offered ("refunded" is refused). Undo with kuduy-snapshot-restore restores the previous status and sends no email, but an email already sent cannot be taken back.'
			: 'Adds a note to a WooCommerce order. Private notes (default) are for store staff only. customer_note: true emails the note to the customer, so it is confirm-first: the first call only reports it (done: false) — ask the user, then call again with confirm: true. Notes cannot be undone through KUDUY.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$properties = array(
			'id' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		);
		if ( 'status' === $this->mode ) {
			$properties += array(
				'status' => array(
					'type' => 'string',
					'enum' => array_merge( self::STATUSES, array( 'refunded' ) ),
				),
				'note'   => array(
					'type'      => 'string',
					'maxLength' => 2000,
				),
			);
			$required    = array( 'id', 'status' );
		} else {
			$properties += array(
				'note'          => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 5000,
				),
				'customer_note' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			);
			$required    = array( 'id', 'note' );
		}//end if
		$properties['confirm'] = Packages::confirm_input();
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'              => array( 'type' => 'integer' ),
				'status'          => array( 'type' => 'string' ),
				'done'            => array(
					'type'        => 'boolean',
					'description' => 'False when only the impact was reported (confirm needed).',
				),
				'customer_emails' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'impact'          => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'note_id'         => array( 'type' => array( 'integer', 'null' ) ),
				'_snapshot_id'    => array( 'type' => 'integer' ),
			),
			'required'   => array( 'id', 'status', 'done', 'customer_emails', 'impact' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return array(
			'readOnlyHint'    => false,
			'destructiveHint' => 'status' === $this->mode,
			'idempotentHint'  => 'status' === $this->mode,
			'openWorldHint'   => true,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'kuduy:write';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string|array {
		$id = (int) ( $input['id'] ?? 0 );
		return $id > 0 && wc_get_order( $id ) instanceof \WC_Order ? array( 'edit_post', $id ) : 'edit_shop_orders';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return Woo::available();
	}

	/**
	 * Customer emails WooCommerce would send for a status change (enabled customer emails hooked on the change's
	 * notification hooks). May over-report, never under-report.
	 *
	 * @param string $from Current status.
	 * @param string $to   New status.
	 * @return list<string> Email titles.
	 */
	public static function customer_emails( string $from, string $to ): array {
		return self::emails_on( array( "woocommerce_order_status_{$from}_to_{$to}_notification", "woocommerce_order_status_{$to}_notification" ) );
	}

	/**
	 * Enabled customer emails attached to notification hooks.
	 *
	 * @param list<string> $hooks Hook names.
	 * @return list<string> Email titles.
	 */
	private static function emails_on( array $hooks ): array {
		global $wp_filter;
		WC()->mailer();
		$out = array();
		foreach ( $hooks as $hook ) {
			if ( ! isset( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof \WP_Hook ) {
				continue;
			}
			foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$email = is_array( $callback['function'] ) ? $callback['function'][0] : null;
					if ( $email instanceof \WC_Email && $email->is_customer_email() && $email->is_enabled() ) {
						$out[ $email->id ] = $email->get_title();
					}
				}
			}
		}
		return array_values( $out );
	}

	/**
	 * Stock side effects of a status change (WooCommerce's own stock hooks).
	 *
	 * @param string $to New status.
	 * @return list<string>
	 */
	private static function stock_impact( string $to ): array {
		if ( in_array( $to, array( 'processing', 'completed', 'on-hold' ), true ) ) {
			return array( 'WooCommerce reduces the stock of the ordered products if it has not done so for this order yet.' );
		}
		if ( in_array( $to, array( 'cancelled', 'pending', 'failed' ), true ) ) {
			return array( 'WooCommerce puts reduced stock for this order back.' );
		}
		return array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): ?array {
		if ( 'status' !== $this->mode ) {
			return null;
		}
		$order = wc_get_order( (int) ( $input['id'] ?? 0 ) );
		$to    = (string) ( $input['status'] ?? '' );
		if ( ! $order instanceof \WC_Order || ! in_array( $to, self::STATUSES, true ) || $order->get_status() === $to ) {
			return null;
		}
		if ( self::customer_emails( $order->get_status(), $to ) && empty( $input['confirm'] ) ) {
			return null;
		}
		return array(
			'object_type' => 'order',
			'object_id'   => (string) $order->get_id(),
			'payload'     => array(
				'id'     => $order->get_id(),
				'status' => $order->get_status(),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When the order is gone.
	 */
	public function restore( array $payload ): void {
		$order = Woo::order( (int) ( $payload['id'] ?? 0 ) );
		$to    = (string) ( $payload['status'] ?? '' );
		$from  = $order->get_status();
		if ( $from === $to ) {
			return;
		}
		// No customer email on undo: WooCommerce's email dispatch for this change is unhooked (that also covers sites
		// that defer emails to Action Scheduler) and every customer email reports itself disabled while it runs.
		$dispatch = array( array( 'WC_Emails', 'send_transactional_email' ), array( 'WC_Emails', 'queue_transactional_email' ) );
		$removed  = array();
		foreach ( array( "woocommerce_order_status_{$from}_to_{$to}", "woocommerce_order_status_{$to}" ) as $hook ) {
			foreach ( $dispatch as $callback ) {
				if ( remove_action( $hook, $callback, 10 ) ) {
					$removed[] = array( $hook, $callback );
				}
			}
		}
		$filters = array();
		foreach ( WC()->mailer()->get_emails() as $email ) {
			if ( $email instanceof \WC_Email && $email->is_customer_email() ) {
				$filters[] = 'woocommerce_email_enabled_' . $email->id;
				add_filter( 'woocommerce_email_enabled_' . $email->id, '__return_false', PHP_INT_MAX );
			}
		}
		try {
			$order->update_status( $to, __( 'Status put back through KUDUY MCP undo (no customer email sent).', 'kuduy-mcp' ), true );
		} finally {
			foreach ( $filters as $filter ) {
				remove_filter( $filter, '__return_false', PHP_INT_MAX );
			}
			foreach ( $removed as [ $hook, $callback ] ) {
				add_action( $hook, $callback, 10, 10 );
			}
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the order is missing, the status is not offered or WooCommerce refuses.
	 */
	public function execute( array $input ): array {
		$order = Woo::order( (int) $input['id'] );
		Woo::assert_order_permission( 'edit', $order->get_id() );
		AuditLog::target( 'order', (string) $order->get_id() );
		return 'status' === $this->mode ? $this->status( $order, $input ) : $this->note( $order, $input );
	}

	/**
	 * Status change.
	 *
	 * @param \WC_Order            $order Order.
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the status is "refunded".
	 */
	private function status( \WC_Order $order, array $input ): array {
		$to = (string) $input['status'];
		if ( ! in_array( $to, self::STATUSES, true ) ) {
			throw new ToolError( 'Refunds are not offered through KUDUY MCP: setting "refunded" makes WooCommerce create a refund. Refund from the order screen in WordPress instead. Nothing was changed.' );
		}
		$from = $order->get_status();
		$out  = array(
			'id'              => $order->get_id(),
			'status'          => $from,
			'done'            => true,
			'customer_emails' => array(),
			'impact'          => array(),
			'note_id'         => null,
		);
		if ( $from === $to ) {
			$out['impact'] = array( 'The order is already ' . $to . '; nothing changed.' );
			return $out;
		}
		$out['customer_emails'] = self::customer_emails( $from, $to );
		$out['impact']          = self::stock_impact( $to );
		if ( $out['customer_emails'] ) {
			$out['impact'][] = 'WooCommerce emails the customer (' . Pii::email( $order->get_billing_email() ) . '): ' . implode( ', ', $out['customer_emails'] ) . '.';
		}
		if ( $out['customer_emails'] && empty( $input['confirm'] ) ) {
			$out['done'] = false;
			return $out;
		}
		if ( ! $order->update_status( $to, (string) ( $input['note'] ?? '' ), true ) ) {
			throw new ToolError( 'WooCommerce did not change the status.' );
		}
		$out['status'] = Woo::order( $order->get_id() )->get_status();
		return $out;
	}

	/**
	 * Note.
	 *
	 * @param \WC_Order            $order Order.
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When WooCommerce does not add it.
	 */
	private function note( \WC_Order $order, array $input ): array {
		$customer = ! empty( $input['customer_note'] );
		$emails   = $customer ? self::emails_on( array( 'woocommerce_new_customer_note_notification' ) ) : array();
		$out      = array(
			'id'              => $order->get_id(),
			'status'          => $order->get_status(),
			'done'            => true,
			'customer_emails' => $emails,
			'impact'          => $emails ? array( 'WooCommerce emails this note to the customer (' . Pii::email( $order->get_billing_email() ) . ').' ) : array(),
			'note_id'         => null,
		);
		if ( $emails && empty( $input['confirm'] ) ) {
			$out['done'] = false;
			return $out;
		}
		$note_id = $order->add_order_note( (string) $input['note'], $customer ? 1 : 0, true );
		if ( ! $note_id ) {
			throw new ToolError( 'WooCommerce did not add the note.' );
		}
		$out['note_id'] = (int) $note_id;
		return $out;
	}
}
