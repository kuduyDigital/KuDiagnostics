<?php
/**
 * Tools: kuduy-woo-coupon-list, -get, -create, -update, -trash.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Woo;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * Coupons through WC_Coupon (D10) with WooCommerce's coupon permissions (`wc_rest_check_post_permissions(
 * 'shop_coupon' )`). Codes are unique, compared the way WooCommerce does (wc_get_coupon_id_by_code). The customers who
 * used a coupon are never listed (only the count); email restrictions are masked unless the user may edit the coupon.
 */
final class CouponTools implements Tool, Snapshottable {

	/**
	 * Mode.
	 *
	 * @param string $mode list | get | create | update | trash.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * Editable coupon fields: name => JSON Schema (`get_{name}( 'edit' )` / `set_{name}()`).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function fields(): array {
		$ids   = array(
			'type'  => 'array',
			'items' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		);
		$price = array(
			'type'    => 'string',
			'pattern' => Woo::PRICE,
		);
		$count = array(
			'type'        => 'integer',
			'minimum'     => 0,
			'description' => '0 = no limit.',
		);
		return array(
			'code'                        => array(
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 200,
			),
			'discount_type'               => array(
				'type' => 'string',
				'enum' => array( 'percent', 'fixed_cart', 'fixed_product' ),
			),
			'amount'                      => $price,
			'status'                      => array(
				'type' => 'string',
				'enum' => array( 'draft', 'pending', 'private', 'publish' ),
			),
			'description'                 => array( 'type' => 'string' ),
			'date_expires'                => array(
				'type'        => array( 'string', 'null' ),
				'description' => 'Expiry date (YYYY-MM-DD); null removes it.',
			),
			'individual_use'              => array( 'type' => 'boolean' ),
			'product_ids'                 => $ids,
			'excluded_product_ids'        => $ids,
			'usage_limit'                 => $count,
			'usage_limit_per_user'        => $count,
			'limit_usage_to_x_items'      => array(
				'type'    => array( 'integer', 'null' ),
				'minimum' => 0,
			),
			'free_shipping'               => array( 'type' => 'boolean' ),
			'product_categories'          => $ids,
			'excluded_product_categories' => $ids,
			'exclude_sale_items'          => array( 'type' => 'boolean' ),
			'minimum_amount'              => $price,
			'maximum_amount'              => $price,
			'email_restrictions'          => array(
				'type'  => 'array',
				'items' => array(
					'type'      => 'string',
					'maxLength' => 254,
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-woo-coupon-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'list'   => __( 'List coupons', 'kuduy-mcp' ),
			'get'    => __( 'Read a coupon', 'kuduy-mcp' ),
			'create' => __( 'Create a coupon', 'kuduy-mcp' ),
			'update' => __( 'Change a coupon', 'kuduy-mcp' ),
			default  => __( 'Move a coupon to the trash', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'list'   => 'Lists WooCommerce coupons: code, type, amount, expiry, how often used and the usage limit. Filters: search (code), status. Paged.',
			'get'    => 'Returns one WooCommerce coupon by id or code with every field kuduy-woo-coupon-update can change. Shows how often it was used, never who used it.',
			'create' => 'Creates a WooCommerce coupon. The code must be unique (WooCommerce compares codes without case). discount_type percent (amount up to 100), fixed_cart or fixed_product; amounts are strings ("10"). Active (status publish) unless status says otherwise. Undo: kuduy-woo-coupon-trash.',
			'update' => 'Changes only the fields you send on a WooCommerce coupon (same names as kuduy-woo-coupon-get): code, type, amount, expiry, usage limits, product and category rules, minimum/maximum spend, allowed emails, free shipping. Snapshot first; undo with kuduy-snapshot-restore.',
			default  => 'Moves a WooCommerce coupon to the trash, so it no longer applies. Never deletes permanently. Undo with kuduy-snapshot-restore.',
		};
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
			'list'   => array(
				array(
					'search' => array(
						'type'      => 'string',
						'maxLength' => 200,
					),
					'status' => array(
						'type'    => 'string',
						'enum'    => array( 'any', 'draft', 'pending', 'private', 'publish', 'trash' ),
						'default' => 'any',
					),
				) + Content::paging_input(),
				array(),
			),
			'get'    => array(
				array(
					'id'   => $id,
					'code' => array(
						'type'      => 'string',
						'minLength' => 1,
					),
				),
				array(),
			),
			'create' => array( self::fields(), array( 'code' ) ),
			'update' => array( array( 'id' => $id ) + self::fields(), array( 'id' ) ),
			default  => array( array( 'id' => $id ), array( 'id' ) ),
		};//end match
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
			'additionalProperties' => false,
		);
	}

	/**
	 * Schema of one coupon row.
	 *
	 * @return array<string, mixed>
	 */
	private static function row_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'            => array( 'type' => 'integer' ),
				'code'          => array( 'type' => 'string' ),
				'status'        => array( 'type' => 'string' ),
				'discount_type' => array( 'type' => 'string' ),
				'amount'        => array( 'type' => 'string' ),
				'date_expires'  => array( 'type' => array( 'string', 'null' ) ),
				'usage_count'   => array( 'type' => 'integer' ),
				'usage_limit'   => array( 'type' => 'integer' ),
				'description'   => array( 'type' => 'string' ),
			),
			'required'   => array( 'id', 'code', 'status', 'discount_type', 'amount' ),
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
					'coupons'     => array(
						'type'  => 'array',
						'items' => self::row_schema(),
					),
					'total'       => array( 'type' => 'integer' ),
					'total_pages' => array( 'type' => 'integer' ),
					'page'        => array( 'type' => 'integer' ),
					'per_page'    => array( 'type' => 'integer' ),
				),
				'required'   => array( 'coupons', 'total', 'total_pages', 'page', 'per_page' ),
			);
		}
		$schema = self::row_schema();
		if ( 'get' === $this->mode ) {
			foreach ( self::fields() as $key => $property ) {
				unset( $property['minLength'], $property['maxLength'], $property['pattern'], $property['enum'], $property['minimum'] );
				$schema['properties'][ $key ] = $schema['properties'][ $key ] ?? $property;
			}
		}
		if ( 'update' === $this->mode ) {
			$schema['properties']['changed'] = array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			);
		}
		$schema['properties']['_snapshot_id'] = array( 'type' => 'integer' );
		return $schema;
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		if ( in_array( $this->mode, array( 'list', 'get' ), true ) ) {
			return Content::read_only();
		}
		return array(
			'readOnlyHint'    => false,
			'destructiveHint' => 'create' !== $this->mode,
			'idempotentHint'  => 'create' !== $this->mode,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return in_array( $this->mode, array( 'list', 'get' ), true ) ? 'kuduy:read' : 'kuduy:write';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string|array {
		$id = (int) ( $input['id'] ?? 0 );
		return match ( $this->mode ) {
			'list', 'get' => 'read_private_shop_coupons',
			'create'      => 'publish_shop_coupons',
			default       => $id > 0 && 'shop_coupon' === get_post_type( $id ) ? array( 'trash' === $this->mode ? 'delete_post' : 'edit_post', $id ) : 'edit_shop_coupons',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return Woo::available();
	}

	/**
	 * A coupon by id, or a ToolError.
	 *
	 * @param int $id Coupon id.
	 * @throws ToolError When there is none.
	 */
	private static function coupon( int $id ): \WC_Coupon {
		if ( $id <= 0 || 'shop_coupon' !== get_post_type( $id ) ) {
			throw new ToolError( 'No WooCommerce coupon with id ' . (int) $id . '.' );
		}
		return new \WC_Coupon( $id );
	}

	/**
	 * WooCommerce's coupon permission check.
	 *
	 * @param string $context read | create | edit | delete.
	 * @param int    $id      Coupon id.
	 * @throws ToolError When WooCommerce refuses.
	 */
	private static function assert_permission( string $context, int $id = 0 ): void {
		if ( ! wc_rest_check_post_permissions( 'shop_coupon', $context, $id ) ) {
			throw new ToolError( 'WooCommerce does not allow your account to ' . esc_html( $context ) . ' this coupon.' );
		}
	}

	/**
	 * Current values of fields, in the tool shape.
	 *
	 * @param \WC_Coupon    $coupon Coupon.
	 * @param array<string> $keys   Fields.
	 * @return array<string, mixed>
	 */
	private static function read( \WC_Coupon $coupon, array $keys ): array {
		$out = array();
		foreach ( $keys as $key ) {
			$getter = 'get_' . $key;
			if ( ! method_exists( $coupon, $getter ) ) {
				continue;
			}
			$value = $coupon->$getter( 'edit' );
			if ( $value instanceof \WC_DateTime ) {
				$value = $value->date( 'Y-m-d' );
			} elseif ( in_array( $key, array( 'amount', 'minimum_amount', 'maximum_amount' ), true ) ) {
				$value = (string) $value;
			} elseif ( in_array( $key, array( 'usage_limit', 'usage_limit_per_user' ), true ) ) {
				$value = (int) $value;
			} elseif ( 'limit_usage_to_x_items' === $key ) {
				$value = null === $value || '' === $value ? null : (int) $value;
			} elseif ( 'email_restrictions' === $key ) {
				$value = array_values( array_map( 'strval', (array) $value ) );
			} elseif ( is_array( $value ) ) {
				$value = array_values( array_map( 'intval', $value ) );
			}
			$out[ $key ] = $value;
		}//end foreach
		return $out;
	}

	/**
	 * Apply fields (not saved).
	 *
	 * @param \WC_Coupon           $coupon Coupon.
	 * @param array<string, mixed> $fields Fields.
	 * @throws ToolError When a value is refused.
	 */
	private static function apply( \WC_Coupon $coupon, array $fields ): void {
		if ( isset( $fields['code'] ) ) {
			$existing = wc_get_coupon_id_by_code( (string) $fields['code'], $coupon->get_id() );
			if ( $existing ) {
				throw new ToolError( 'The coupon code already exists (coupon ' . (int) $existing . '). Nothing was changed.' );
			}
		}
		$type   = (string) ( $fields['discount_type'] ?? $coupon->get_discount_type( 'edit' ) );
		$amount = (float) ( $fields['amount'] ?? $coupon->get_amount( 'edit' ) );
		if ( 'percent' === $type && $amount > 100 ) {
			throw new ToolError( 'A percentage discount cannot be more than 100. Nothing was changed.' );
		}
		foreach ( (array) ( $fields['email_restrictions'] ?? array() ) as $email ) {
			if ( ! is_email( (string) $email ) && ! str_contains( (string) $email, '*' ) ) {
				throw new ToolError( 'Allowed emails must be email addresses (a * wildcard is allowed). Nothing was changed.' );
			}
		}
		try {
			foreach ( $fields as $key => $value ) {
				if ( in_array( $key, array( 'amount', 'minimum_amount', 'maximum_amount' ), true ) && '' !== $value ) {
					$value = wc_format_decimal( (string) $value );
				}
				$setter = 'set_' . $key;
				$coupon->$setter( $value );
			}
		} catch ( \WC_Data_Exception $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON text, never HTML.
			throw new ToolError( 'WooCommerce refused the value: ' . wp_strip_all_tags( $e->getMessage() ) . ' Nothing was changed.' );
		}
	}

	/**
	 * One coupon row.
	 *
	 * @param \WC_Coupon $coupon Coupon.
	 * @return array<string, mixed>
	 */
	private static function row( \WC_Coupon $coupon ): array {
		$expires = $coupon->get_date_expires( 'edit' );
		return array(
			'id'            => $coupon->get_id(),
			'code'          => $coupon->get_code(),
			'status'        => (string) get_post_status( $coupon->get_id() ),
			'discount_type' => $coupon->get_discount_type( 'edit' ),
			'amount'        => (string) $coupon->get_amount( 'edit' ),
			'date_expires'  => $expires ? $expires->date( 'Y-m-d' ) : null,
			'usage_count'   => (int) $coupon->get_usage_count( 'edit' ),
			'usage_limit'   => (int) $coupon->get_usage_limit( 'edit' ),
			'description'   => $coupon->get_description( 'edit' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): ?array {
		$id = (int) ( $input['id'] ?? 0 );
		if ( ! in_array( $this->mode, array( 'update', 'trash' ), true ) || 'shop_coupon' !== get_post_type( $id ) ) {
			return null;
		}
		$coupon = new \WC_Coupon( $id );
		if ( 'trash' === $this->mode ) {
			$status = (string) get_post_status( $id );
			return 'trash' === $status ? null : array(
				'object_type' => 'coupon',
				'object_id'   => (string) $id,
				'payload'     => array(
					'trash_id' => $id,
					'status'   => $status,
				),
			);
		}
		return array(
			'object_type' => 'coupon',
			'object_id'   => (string) $id,
			'payload'     => array(
				'id'     => $id,
				'fields' => self::read( $coupon, array_keys( array_intersect_key( $input, self::fields() ) ) ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When the coupon is gone.
	 */
	public function restore( array $payload ): void {
		if ( isset( $payload['trash_id'] ) ) {
			$id = (int) $payload['trash_id'];
			if ( ! get_post( $id ) ) {
				throw new ToolError( 'The coupon was deleted permanently, so it cannot be restored.' );
			}
			if ( 'trash' === get_post_status( $id ) && ! wp_untrash_post( $id ) ) {
				throw new ToolError( 'WordPress could not take the coupon out of the trash.' );
			}
			$coupon = self::coupon( $id );
			$coupon->set_status( (string) ( $payload['status'] ?? 'draft' ) );
			$coupon->save();
			return;
		}
		$coupon = self::coupon( (int) ( $payload['id'] ?? 0 ) );
		self::apply( $coupon, (array) ( $payload['fields'] ?? array() ) );
		$coupon->save();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the coupon is missing or the change is refused.
	 */
	public function execute( array $input ): array {
		if ( 'list' === $this->mode ) {
			self::assert_permission( 'read' );
			return $this->items( $input );
		}
		if ( 'create' === $this->mode ) {
			self::assert_permission( 'create' );
			$coupon = new \WC_Coupon();
			self::apply( $coupon, array_intersect_key( $input, self::fields() ) + array( 'status' => 'publish' ) );
			$id = $coupon->save();
			if ( $id <= 0 ) {
				throw new ToolError( 'WooCommerce did not save the coupon.' );
			}
			AuditLog::target( 'coupon', (string) $id );
			return self::row( self::coupon( $id ) );
		}
		if ( 'get' === $this->mode ) {
			$id = isset( $input['id'] ) ? (int) $input['id'] : wc_get_coupon_id_by_code( (string) ( $input['code'] ?? '' ) );
			if ( ! $id ) {
				throw new ToolError( 'Send the coupon id or an existing code.' );
			}
			$coupon = self::coupon( $id );
			self::assert_permission( 'read', $id );
			AuditLog::target( 'coupon', (string) $id );
			$out = self::row( $coupon ) + self::read( $coupon, array_keys( self::fields() ) );
			if ( ! current_user_can( 'edit_post', $id ) ) {
				$out['email_restrictions'] = array_map( array( Pii::class, 'email' ), (array) $out['email_restrictions'] );
			}
			return $out;
		}
		$coupon = self::coupon( (int) $input['id'] );
		self::assert_permission( 'trash' === $this->mode ? 'delete' : 'edit', $coupon->get_id() );
		AuditLog::target( 'coupon', (string) $coupon->get_id() );
		if ( 'trash' === $this->mode ) {
			if ( 'trash' === get_post_status( $coupon->get_id() ) ) {
				throw new ToolError( 'This coupon is already in the trash.' );
			}
			if ( ! EMPTY_TRASH_DAYS ) {
				throw new ToolError( 'The trash is turned off on this site (EMPTY_TRASH_DAYS is 0), so deleting would be permanent. Not done.' );
			}
			$id = $coupon->get_id();
			if ( ! $coupon->delete( false ) ) {
				throw new ToolError( 'WooCommerce did not move the coupon to the trash.' );
			}
			return self::row( self::coupon( $id ) );
		}
		$fields = array_intersect_key( $input, self::fields() );
		if ( ! $fields ) {
			throw new ToolError( 'Send at least one field to change.' );
		}
		self::apply( $coupon, $fields );
		$coupon->save();
		return self::row( self::coupon( $coupon->get_id() ) ) + array( 'changed' => array_keys( $fields ) );
	}

	/**
	 * A page of coupons.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 */
	private function items( array $input ): array {
		$per_page = (int) ( $input['per_page'] ?? 20 );
		$page     = (int) ( $input['page'] ?? 1 );
		$status   = (string) ( $input['status'] ?? 'any' );
		$query    = new \WP_Query(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => 'any' === $status ? array( 'draft', 'pending', 'private', 'publish', 'future' ) : $status,
				's'              => (string) ( $input['search'] ?? '' ),
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		$coupons  = array();
		foreach ( $query->posts as $id ) {
			$coupons[] = self::row( new \WC_Coupon( (int) $id ) );
		}
		return array(
			'coupons'     => $coupons,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}
}
