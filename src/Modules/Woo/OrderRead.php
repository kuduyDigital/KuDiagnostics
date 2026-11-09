<?php
/**
 * Tools: kuduy-woo-order-list, kuduy-woo-order-get.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Woo;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * Orders read through wc_get_orders() / wc_get_order(), so the same code works with HPOS on and off. Read access is
 * WooCommerce's (`read_private_shop_orders`); customer details are masked unless the call asks for them and the user
 * may edit that order (D12). Order keys, cart hashes and payment tokens are never returned.
 */
final class OrderRead implements Tool {

	private const STATUSES = array( 'any', 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed', 'checkout-draft' );

	/**
	 * Mode.
	 *
	 * @param string $mode list | get.
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
		return 'list' === $this->mode ? __( 'List orders', 'kuduy-mcp' ) : __( 'Read an order', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'list' === $this->mode
			? 'Lists WooCommerce orders, newest first: number, status, date, total, customer name and a masked email. Filters: status, customer_id, billing_email (exact), date_after / date_before (YYYY-MM-DD). Paged.'
			: 'Returns one WooCommerce order: status, totals, items, shipping, fees, coupons, refunds (read only) and notes. Customer address, email, phone, IP and the customer\'s own note are masked; send include_pii: true only when the task needs them (needs edit rights on the order).';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$date = array(
			'type'    => 'string',
			'pattern' => '^\d{4}-\d{2}-\d{2}$',
		);
		if ( 'list' === $this->mode ) {
			$properties = array(
				'status'        => array(
					'type'    => 'string',
					'enum'    => self::STATUSES,
					'default' => 'any',
				),
				'customer_id'   => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'billing_email' => array(
					'type'   => 'string',
					'format' => 'email',
				),
				'date_after'    => $date,
				'date_before'   => $date,
			) + Content::paging_input();
		} else {
			$properties = array(
				'id'          => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'include_pii' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Full customer address, email, phone, IP and note. Only when needed.',
				),
			);
		}//end if
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => 'list' === $this->mode ? array() : array( 'id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Schema of one list row.
	 *
	 * @return array<string, mixed>
	 */
	public static function row_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'                   => array( 'type' => 'integer' ),
				'number'               => array( 'type' => 'string' ),
				'status'               => array( 'type' => 'string' ),
				'date_created'         => array( 'type' => array( 'string', 'null' ) ),
				'total'                => array( 'type' => 'string' ),
				'currency'             => array( 'type' => 'string' ),
				'customer_id'          => array( 'type' => 'integer' ),
				'name'                 => array( 'type' => 'string' ),
				'email'                => array( 'type' => 'string' ),
				'item_count'           => array( 'type' => 'integer' ),
				'payment_method_title' => array( 'type' => 'string' ),
			),
			'required'   => array( 'id', 'status', 'total' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		if ( 'list' === $this->mode ) {
			return array(
				'type'       => 'object',
				'properties' => array(
					'orders'      => array(
						'type'  => 'array',
						'items' => self::row_schema(),
					),
					'total'       => array( 'type' => 'integer' ),
					'total_pages' => array( 'type' => 'integer' ),
					'page'        => array( 'type' => 'integer' ),
					'per_page'    => array( 'type' => 'integer' ),
				),
				'required'   => array( 'orders', 'total', 'total_pages', 'page', 'per_page' ),
			);
		}
		$list                  = array(
			'type'  => 'array',
			'items' => array( 'type' => 'object' ),
		);
		$address               = array(
			'type'                 => 'object',
			'additionalProperties' => array( 'type' => 'string' ),
		);
		$schema                = self::row_schema();
		$schema['properties'] += array(
			'pii'                 => array( 'type' => 'boolean' ),
			'subtotal'            => array( 'type' => 'string' ),
			'discount_total'      => array( 'type' => 'string' ),
			'shipping_total'      => array( 'type' => 'string' ),
			'total_tax'           => array( 'type' => 'string' ),
			'total_refunded'      => array( 'type' => 'string' ),
			'prices_include_tax'  => array( 'type' => 'boolean' ),
			'payment_method'      => array( 'type' => 'string' ),
			'date_paid'           => array( 'type' => array( 'string', 'null' ) ),
			'date_completed'      => array( 'type' => array( 'string', 'null' ) ),
			'created_via'         => array( 'type' => 'string' ),
			'billing'             => $address,
			'shipping'            => $address,
			'customer_note'       => array( 'type' => array( 'string', 'null' ) ),
			'customer_ip_address' => array( 'type' => array( 'string', 'null' ) ),
			'customer_user_agent' => array( 'type' => array( 'string', 'null' ) ),
			'transaction_id'      => array( 'type' => array( 'string', 'null' ) ),
			'items'               => $list,
			'shipping_lines'      => $list,
			'fee_lines'           => $list,
			'coupons'             => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
			'refunds'             => $list,
			'notes'               => $list,
		);
		$schema['required'][]  = 'pii';
		return $schema;
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return Content::read_only();
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'kuduy:read';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		return 'read_private_shop_orders';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return Woo::available();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the order is missing or customer details are not allowed.
	 */
	public function execute( array $input ): array {
		if ( 'list' === $this->mode ) {
			Woo::assert_order_permission( 'read' );
			return $this->items( $input );
		}
		$order = Woo::order( (int) $input['id'] );
		Woo::assert_order_permission( 'read', $order->get_id() );
		AuditLog::target( 'order', (string) $order->get_id() );
		$pii = ! empty( $input['include_pii'] );
		if ( $pii && ! current_user_can( 'edit_post', $order->get_id() ) ) {
			throw new ToolError( 'Full customer details need edit rights on this order. Read it without include_pii to see the masked version.' );
		}
		return self::detail( $order, $pii );
	}

	/**
	 * One list row (masked).
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string, mixed>
	 */
	public static function row( \WC_Order $order ): array {
		$created = $order->get_date_created();
		return array(
			'id'                   => $order->get_id(),
			'number'               => (string) $order->get_order_number(),
			'status'               => $order->get_status(),
			'date_created'         => $created ? $created->date( 'c' ) : null,
			'total'                => (string) $order->get_total(),
			'currency'             => $order->get_currency(),
			'customer_id'          => $order->get_customer_id(),
			'name'                 => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'email'                => Pii::email( $order->get_billing_email() ),
			'item_count'           => $order->get_item_count(),
			'payment_method_title' => $order->get_payment_method_title(),
		);
	}

	/**
	 * A page of orders.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 */
	private function items( array $input ): array {
		$per_page = (int) ( $input['per_page'] ?? 20 );
		$page     = (int) ( $input['page'] ?? 1 );
		$args     = array(
			'type'     => 'shop_order',
			'limit'    => $per_page,
			'page'     => $page,
			'paginate' => true,
			'orderby'  => 'date',
			'order'    => 'DESC',
		);
		if ( 'any' !== ( $input['status'] ?? 'any' ) ) {
			$args['status'] = array( 'wc-' . $input['status'] );
		}
		if ( isset( $input['customer_id'] ) ) {
			$args['customer_id'] = (int) $input['customer_id'];
		}
		if ( isset( $input['billing_email'] ) ) {
			$args['billing_email'] = (string) $input['billing_email'];
		}
		$after  = $input['date_after'] ?? null;
		$before = $input['date_before'] ?? null;
		if ( $after && $before ) {
			$args['date_created'] = $after . '...' . $before;
		} elseif ( $after ) {
			$args['date_created'] = '>=' . $after;
		} elseif ( $before ) {
			$args['date_created'] = '<=' . $before;
		}
		$result = wc_get_orders( $args );
		$orders = array();
		foreach ( (array) $result->orders as $order ) {
			if ( $order instanceof \WC_Order ) {
				$orders[] = self::row( $order );
			}
		}
		return array(
			'orders'      => $orders,
			'total'       => (int) $result->total,
			'total_pages' => (int) $result->max_num_pages,
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}

	/**
	 * Full order (customer details masked unless $pii).
	 *
	 * @param \WC_Order $order Order.
	 * @param bool      $pii   Include customer details.
	 * @return array<string, mixed>
	 */
	public static function detail( \WC_Order $order, bool $pii ): array {
		$items = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			$items[] = array(
				'id'           => $item->get_id(),
				'name'         => $item->get_name(),
				'product_id'   => $item->get_product_id(),
				'variation_id' => $item->get_variation_id(),
				'sku'          => $product instanceof \WC_Product ? $product->get_sku() : '',
				'quantity'     => (int) $item->get_quantity(),
				'subtotal'     => (string) $item->get_subtotal(),
				'total'        => (string) $item->get_total(),
				'total_tax'    => (string) $item->get_total_tax(),
			);
		}
		$shipping = array();
		foreach ( $order->get_shipping_methods() as $line ) {
			$shipping[] = array(
				'method_id'    => $line->get_method_id(),
				'method_title' => $line->get_method_title(),
				'total'        => (string) $line->get_total(),
			);
		}
		$fees = array();
		foreach ( $order->get_fees() as $fee ) {
			$fees[] = array(
				'name'  => $fee->get_name(),
				'total' => (string) $fee->get_total(),
			);
		}
		$refunds = array();
		foreach ( $order->get_refunds() as $refund ) {
			$date      = $refund->get_date_created();
			$refunds[] = array(
				'id'     => $refund->get_id(),
				'amount' => (string) $refund->get_amount(),
				'reason' => $refund->get_reason(),
				'date'   => $date ? $date->date( 'c' ) : null,
			);
		}
		$notes = array();
		foreach ( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) as $note ) {
			$notes[] = array(
				'id'            => (int) $note->id,
				'content'       => (string) $note->content,
				'customer_note' => (bool) $note->customer_note,
				'added_by'      => (string) $note->added_by,
				'date'          => $note->date_created instanceof \WC_DateTime ? $note->date_created->date( 'c' ) : null,
			);
		}
		$paid      = $order->get_date_paid();
		$completed = $order->get_date_completed();
		return self::row( $order ) + array(
			'pii'                 => $pii,
			'subtotal'            => (string) $order->get_subtotal(),
			'discount_total'      => (string) $order->get_discount_total(),
			'shipping_total'      => (string) $order->get_shipping_total(),
			'total_tax'           => (string) $order->get_total_tax(),
			'total_refunded'      => (string) $order->get_total_refunded(),
			'prices_include_tax'  => (bool) $order->get_prices_include_tax(),
			'payment_method'      => $order->get_payment_method(),
			'date_paid'           => $paid ? $paid->date( 'c' ) : null,
			'date_completed'      => $completed ? $completed->date( 'c' ) : null,
			'created_via'         => $order->get_created_via(),
			'billing'             => Pii::address( $order->get_address( 'billing' ), $pii ),
			'shipping'            => Pii::address( $order->get_address( 'shipping' ), $pii ),
			'customer_note'       => $pii ? $order->get_customer_note() : null,
			'customer_ip_address' => $pii ? $order->get_customer_ip_address() : null,
			'customer_user_agent' => $pii ? $order->get_customer_user_agent() : null,
			'transaction_id'      => $pii ? $order->get_transaction_id() : null,
			'items'               => $items,
			'shipping_lines'      => $shipping,
			'fee_lines'           => $fees,
			'coupons'             => array_values( array_map( 'strval', $order->get_coupon_codes() ) ),
			'refunds'             => $refunds,
			'notes'               => $notes,
		);
	}
}
