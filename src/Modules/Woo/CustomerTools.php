<?php
/**
 * Tools: kuduy-woo-customer-list, -get, -update.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Woo;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Audit\MasksAuditArgs;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * Customers through WC_Customer with WooCommerce's user permissions (`wc_rest_check_user_permissions()`: list_users
 * to read; edit_users per user to change, and a shop manager only edits customers). Personal data is masked unless
 * asked for and allowed (D12), and the addresses sent to kuduy-woo-customer-update never reach the audit log. Only
 * billing and shipping addresses can change: no account email, role or password.
 */
final class CustomerTools implements Tool, Snapshottable, MasksAuditArgs {

	private const ADDRESS = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' );

	/**
	 * Mode.
	 *
	 * @param string $mode list | get | update.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-woo-customer-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'list'  => __( 'List customers', 'kuduy-mcp' ),
			'get'   => __( 'Read a customer', 'kuduy-mcp' ),
			default => __( 'Change a customer\'s addresses', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'list'  => 'Lists WooCommerce customers (role customer, or all users with role: any): name, masked email, registration date, order count, total spent, country. search matches name or email. Paged.',
			'get'   => 'Returns one WooCommerce customer: order count, total spent, last order, and billing/shipping addresses masked to names and country. Send include_pii: true only when the task needs the full addresses, email and phone (needs edit rights on that user).',
			default => 'Changes a WooCommerce customer\'s billing and/or shipping address fields (only those you send). Not the account email, role or password. A shop manager can change customers only. Addresses are not written to the audit log. Snapshot first; undo with kuduy-snapshot-restore.',
		};
	}

	/**
	 * Address object schema.
	 *
	 * @param bool $billing Billing (has email).
	 * @return array<string, mixed>
	 */
	private static function address_schema( bool $billing ): array {
		$properties = array();
		foreach ( self::ADDRESS as $field ) {
			$properties[ $field ] = array(
				'type'      => 'string',
				'maxLength' => 'country' === $field ? 2 : 200,
			);
		}
		if ( $billing ) {
			$properties['email'] = array(
				'type'      => 'string',
				'maxLength' => 254,
			);
		}
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$id                        = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		[ $properties, $required ] = match ( $this->mode ) {
			'list'  => array(
				array(
					'search' => array(
						'type'      => 'string',
						'maxLength' => 200,
					),
					'role'   => array(
						'type'    => 'string',
						'enum'    => array( 'customer', 'any' ),
						'default' => 'customer',
					),
				) + Content::paging_input(),
				array(),
			),
			'get'   => array(
				array(
					'id'          => $id,
					'include_pii' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
				array( 'id' ),
			),
			default => array(
				array(
					'id'       => $id,
					'billing'  => self::address_schema( true ),
					'shipping' => self::address_schema( false ),
				),
				array( 'id' ),
			),
		};//end match
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
		$row = array(
			'type'       => 'object',
			'properties' => array(
				'id'              => array( 'type' => 'integer' ),
				'name'            => array( 'type' => 'string' ),
				'email'           => array( 'type' => 'string' ),
				'date_registered' => array( 'type' => array( 'string', 'null' ) ),
				'order_count'     => array( 'type' => 'integer' ),
				'total_spent'     => array( 'type' => 'string' ),
				'country'         => array( 'type' => 'string' ),
			),
			'required'   => array( 'id', 'name', 'email' ),
		);
		if ( 'list' === $this->mode ) {
			return array(
				'type'       => 'object',
				'properties' => array(
					'customers'   => array(
						'type'  => 'array',
						'items' => $row,
					),
					'total'       => array( 'type' => 'integer' ),
					'total_pages' => array( 'type' => 'integer' ),
					'page'        => array( 'type' => 'integer' ),
					'per_page'    => array( 'type' => 'integer' ),
				),
				'required'   => array( 'customers', 'total', 'total_pages', 'page', 'per_page' ),
			);
		}
		$address            = array(
			'type'                 => 'object',
			'additionalProperties' => array( 'type' => 'string' ),
		);
		$row['properties'] += array(
			'pii'                => array( 'type' => 'boolean' ),
			'roles'              => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
			'last_order_id'      => array( 'type' => array( 'integer', 'null' ) ),
			'last_order_date'    => array( 'type' => array( 'string', 'null' ) ),
			'is_paying_customer' => array( 'type' => 'boolean' ),
			'billing'            => $address,
			'shipping'           => $address,
			'changed'            => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
			'_snapshot_id'       => array( 'type' => 'integer' ),
		);
		return $row;
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		if ( 'update' !== $this->mode ) {
			return Content::read_only();
		}
		return array(
			'readOnlyHint'    => false,
			'destructiveHint' => true,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'update' === $this->mode ? 'kuduy:write' : 'kuduy:read';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string|array {
		$id = (int) ( $input['id'] ?? 0 );
		if ( 'update' === $this->mode && $id > 0 && get_userdata( $id ) ) {
			return array( 'edit_user', $id );
		}
		return 'update' === $this->mode ? 'edit_users' : 'list_users';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return Woo::available();
	}

	/**
	 * Addresses sent to the update tool are personal data: the audit row keeps only which fields were sent.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 */
	public function audit_args( array $args ): array {
		foreach ( array( 'billing', 'shipping' ) as $type ) {
			if ( isset( $args[ $type ] ) && is_array( $args[ $type ] ) ) {
				$args[ $type ] = array_fill_keys( array_keys( $args[ $type ] ), Pii::MASK );
			}
		}
		return $args;
	}

	/**
	 * A user that exists, or a ToolError.
	 *
	 * @param int $id User id.
	 * @throws ToolError When the user does not exist.
	 */
	private static function customer( int $id ): \WC_Customer {
		if ( $id <= 0 || ! get_userdata( $id ) ) {
			throw new ToolError( 'No user with id ' . (int) $id . '.' );
		}
		return new \WC_Customer( $id );
	}

	/**
	 * WooCommerce's user permission check.
	 *
	 * @param string $context read | edit.
	 * @param int    $id      User id.
	 * @throws ToolError When WooCommerce refuses.
	 */
	private static function assert_permission( string $context, int $id = 0 ): void {
		if ( ! wc_rest_check_user_permissions( $context, $id ) ) {
			throw new ToolError(
				'edit' === $context
					? 'WooCommerce does not allow your account to change this user (a shop manager can change customers only).'
					: 'WooCommerce does not allow your account to see customers.'
			);
		}
	}

	/**
	 * Masked row.
	 *
	 * @param \WC_Customer $customer Customer.
	 * @return array<string, mixed>
	 */
	private static function row( \WC_Customer $customer ): array {
		$created = $customer->get_date_created();
		return array(
			'id'              => $customer->get_id(),
			'name'            => trim( $customer->get_first_name() . ' ' . $customer->get_last_name() ),
			'email'           => Pii::email( $customer->get_email() ),
			'date_registered' => $created ? $created->date( 'c' ) : null,
			'order_count'     => (int) $customer->get_order_count(),
			'total_spent'     => (string) $customer->get_total_spent(),
			'country'         => $customer->get_billing_country(),
		);
	}

	/**
	 * Full customer (masked unless $pii).
	 *
	 * @param \WC_Customer $customer Customer.
	 * @param bool         $pii      Include personal data.
	 * @return array<string, mixed>
	 */
	private static function detail( \WC_Customer $customer, bool $pii ): array {
		$last    = $customer->get_last_order();
		$created = $last ? $last->get_date_created() : null;
		$user    = get_userdata( $customer->get_id() );
		$out     = self::row( $customer ) + array(
			'pii'                => $pii,
			'roles'              => $user ? array_values( (array) $user->roles ) : array(),
			'last_order_id'      => $last ? $last->get_id() : null,
			'last_order_date'    => $created ? $created->date( 'c' ) : null,
			'is_paying_customer' => (bool) $customer->get_is_paying_customer(),
			'billing'            => Pii::address( $customer->get_billing(), $pii ),
			'shipping'           => Pii::address( $customer->get_shipping(), $pii ),
		);
		if ( $pii ) {
			$out['email'] = $customer->get_email();
		}
		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): ?array {
		$id = (int) ( $input['id'] ?? 0 );
		if ( 'update' !== $this->mode || $id <= 0 || ! get_userdata( $id ) ) {
			return null;
		}
		$customer = new \WC_Customer( $id );
		return array(
			'object_type' => 'user',
			'object_id'   => (string) $id,
			'payload'     => array(
				'id'       => $id,
				'billing'  => $customer->get_billing(),
				'shipping' => $customer->get_shipping(),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 */
	public function restore( array $payload ): void {
		$customer = self::customer( (int) ( $payload['id'] ?? 0 ) );
		self::apply(
			$customer,
			array(
				'billing'  => (array) ( $payload['billing'] ?? array() ),
				'shipping' => (array) ( $payload['shipping'] ?? array() ),
			)
		);
		$customer->save();
	}

	/**
	 * Set address fields (not saved).
	 *
	 * @param \WC_Customer         $customer Customer.
	 * @param array<string, mixed> $input    billing / shipping maps.
	 * @return list<string> Changed fields as type.field.
	 * @throws ToolError When a value is refused.
	 */
	private static function apply( \WC_Customer $customer, array $input ): array {
		$changed = array();
		$props   = array();
		foreach ( array( 'billing', 'shipping' ) as $type ) {
			foreach ( (array) ( $input[ $type ] ?? array() ) as $field => $value ) {
				$field = (string) $field;
				if ( ! in_array( $field, self::ADDRESS, true ) && ! ( 'billing' === $type && 'email' === $field ) ) {
					continue;
				}
				$value = sanitize_text_field( (string) $value );
				if ( 'email' === $field && '' !== $value && ! is_email( $value ) ) {
					throw new ToolError( 'The billing email is not a valid email address. Nothing was changed.' );
				}
				$props[ $type . '_' . $field ] = 'country' === $field ? strtoupper( $value ) : $value;
				$changed[]                     = $type . '.' . $field;
			}
		}
		// WooCommerce's own setters (set_billing_city() …); errors are collected, nothing is saved here.
		$result = $customer->set_props( $props );
		if ( is_wp_error( $result ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON text, never HTML.
			throw new ToolError( 'WooCommerce refused the value: ' . wp_strip_all_tags( $result->get_error_message() ) . ' Nothing was changed.' );
		}
		return $changed;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the user is missing or the change is not allowed.
	 */
	public function execute( array $input ): array {
		if ( 'list' === $this->mode ) {
			self::assert_permission( 'read' );
			return $this->items( $input );
		}
		$customer = self::customer( (int) $input['id'] );
		AuditLog::target( 'user', (string) $customer->get_id() );
		if ( 'get' === $this->mode ) {
			self::assert_permission( 'read', $customer->get_id() );
			$pii = ! empty( $input['include_pii'] );
			if ( $pii && ! wc_rest_check_user_permissions( 'edit', $customer->get_id() ) ) {
				throw new ToolError( 'Full customer details need edit rights on this user. Read it without include_pii to see the masked version.' );
			}
			return self::detail( $customer, $pii );
		}
		self::assert_permission( 'edit', $customer->get_id() );
		$changed = self::apply( $customer, $input );
		if ( ! $changed ) {
			throw new ToolError( 'Send at least one billing or shipping field to change.' );
		}
		$customer->save();
		return self::detail( self::customer( $customer->get_id() ), false ) + array( 'changed' => $changed );
	}

	/**
	 * A page of customers.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 */
	private function items( array $input ): array {
		$per_page = (int) ( $input['per_page'] ?? 20 );
		$page     = (int) ( $input['page'] ?? 1 );
		$args     = array(
			'number'  => $per_page,
			'paged'   => $page,
			'orderby' => 'registered',
			'order'   => 'DESC',
			'fields'  => 'ID',
		);
		if ( 'any' !== ( $input['role'] ?? 'customer' ) ) {
			$args['role'] = 'customer';
		}
		if ( ! empty( $input['search'] ) ) {
			$args['search'] = '*' . (string) $input['search'] . '*';
		}
		$query     = new \WP_User_Query( $args );
		$customers = array();
		foreach ( (array) $query->get_results() as $id ) {
			$customers[] = self::row( new \WC_Customer( (int) $id ) );
		}
		$total = (int) $query->get_total();
		return array(
			'customers'   => $customers,
			'total'       => $total,
			'total_pages' => (int) ceil( $total / max( 1, $per_page ) ),
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}
}
