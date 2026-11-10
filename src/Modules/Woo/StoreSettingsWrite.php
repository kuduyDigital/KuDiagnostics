<?php
/**
 * Tools: kuduy-woo-shipping-zone-save, kuduy-woo-shipping-method-save, kuduy-woo-tax-rate-save,
 * kuduy-woo-tax-rate-delete.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Woo;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;

/**
 * Safe writes to shipping and tax settings through WooCommerce's own wc/v3 REST controllers, run in-process as the
 * current user (decision D24 A): WooCommerce's validation, sanitizing and permission check
 * (wc_rest_check_manager_permissions( 'settings', … ) = manage_woocommerce) apply. Store-wide settings, so the admin
 * scope. Every change is snapshotted; a deleted tax rate is undone by creating it again (it gets a new id). Shipping
 * zones and methods are not deleted through KuDiagnostics (a method can be switched off with enabled: false).
 */
final class StoreSettingsWrite implements Tool, Snapshottable {

	/**
	 * Tax rate fields the taxes controller takes.
	 */
	private const TAX_FIELDS = array( 'country', 'state', 'postcodes', 'cities', 'rate', 'name', 'priority', 'compound', 'shipping', 'order', 'class' );

	/**
	 * Mode.
	 *
	 * @param string $mode zone | method | tax | tax-delete.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return match ( $this->mode ) {
			'zone'   => 'kuduy-woo-shipping-zone-save',
			'method' => 'kuduy-woo-shipping-method-save',
			'tax'    => 'kuduy-woo-tax-rate-save',
			default  => 'kuduy-woo-tax-rate-delete',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'zone'   => __( 'Save a shipping zone', 'kuduy-mcp' ),
			'method' => __( 'Save a shipping method', 'kuduy-mcp' ),
			'tax'    => __( 'Save a tax rate', 'kuduy-mcp' ),
			default  => __( 'Delete a tax rate', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'zone'   => 'Creates a WooCommerce shipping zone (no id) or changes one: name, order, locations (list of {code, type}: type country, state, postcode or continent, e.g. {"code": "US:CA", "type": "state"}; given locations replace the old ones). Snapshot first for changes; undo with kuduy-snapshot-restore. Zones are not deleted through KuDiagnostics.',
			'method' => 'Adds a shipping method to a zone (zone_id + method_id such as flat_rate, free_shipping, local_pickup) or changes one (zone_id + instance_id): enabled, order and settings (e.g. {"title": "Standard", "cost": "5"}; keys as in kuduy-woo-shipping). Switch a method off with enabled: false. Snapshot first for changes; undo with kuduy-snapshot-restore.',
			'tax'    => 'Creates a WooCommerce tax rate (no id) or changes one: country, state, postcodes, cities, rate (percent, e.g. "20.0000"), name, priority, compound, shipping, order, class ("standard" or a tax class slug). Snapshot first for changes; undo with kuduy-snapshot-restore.',
			default  => 'Deletes a WooCommerce tax rate. Snapshot first: undo with kuduy-snapshot-restore creates the same rate again (with a new id).',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$id                        = array(
			'type'    => 'integer',
			'minimum' => 0,
		);
		$text                      = array(
			'type'      => 'string',
			'maxLength' => 200,
		);
		[ $properties, $required ] = match ( $this->mode ) {
			'zone'   => array(
				array(
					'id'        => $id,
					'name'      => $text + array( 'minLength' => 1 ),
					'order'     => array( 'type' => 'integer' ),
					'locations' => array(
						'type'     => 'array',
						'maxItems' => 500,
						'items'    => array(
							'type'       => 'object',
							'properties' => array(
								'code' => $text + array( 'minLength' => 1 ),
								'type' => array(
									'type' => 'string',
									'enum' => array( 'postcode', 'state', 'country', 'continent' ),
								),
							),
							'required'   => array( 'code', 'type' ),
						),
					),
				),
				array(),
			),
			'method' => array(
				array(
					'zone_id'     => $id,
					'instance_id' => $id,
					'method_id'   => array(
						'type'    => 'string',
						'pattern' => '^[a-z0-9_-]{1,64}$',
					),
					'enabled'     => array( 'type' => 'boolean' ),
					'order'       => array( 'type' => 'integer' ),
					'settings'    => array(
						'type'                 => 'object',
						'additionalProperties' => array( 'type' => array( 'string', 'number', 'boolean' ) ),
					),
				),
				array( 'zone_id' ),
			),
			'tax'    => array(
				array(
					'id'        => $id,
					'country'   => array(
						'type'      => 'string',
						'maxLength' => 2,
					),
					'state'     => $text,
					'postcodes' => array(
						'type'     => 'array',
						'items'    => $text,
						'maxItems' => 1000,
					),
					'cities'    => array(
						'type'     => 'array',
						'items'    => $text,
						'maxItems' => 1000,
					),
					'rate'      => array(
						'type'    => 'string',
						'pattern' => '^\d{1,4}(\.\d{1,4})?$',
					),
					'name'      => $text,
					'priority'  => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'compound'  => array( 'type' => 'boolean' ),
					'shipping'  => array( 'type' => 'boolean' ),
					'order'     => array( 'type' => 'integer' ),
					'class'     => array(
						'type'    => 'string',
						'pattern' => '^[a-z0-9-]{1,100}$',
					),
				),
				array(),
			),
			default  => array( array( 'id' => array( 'minimum' => 1 ) + $id ), array( 'id' ) ),
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
		return array(
			'type'       => 'object',
			'properties' => array(
				'item'         => array( 'type' => 'object' ),
				'created'      => array( 'type' => 'boolean' ),
				'deleted'      => array( 'type' => 'boolean' ),
				'_snapshot_id' => array( 'type' => 'integer' ),
			),
			'required'   => array( 'item' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return array(
			'readOnlyHint'    => false,
			'destructiveHint' => true,
			'idempotentHint'  => 'tax-delete' === $this->mode,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'kuduy:admin';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		return 'manage_woocommerce';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return Woo::available();
	}

	/**
	 * Run a wc/v3 route as the current user.
	 *
	 * @param string               $method GET | POST | PUT | DELETE.
	 * @param string               $route  Route under /wc/v3.
	 * @param array<string, mixed> $params Parameters.
	 * @return array<string, mixed>|list<mixed>
	 * @throws ToolError When WooCommerce refuses.
	 */
	private static function wc( string $method, string $route, array $params = array() ): array {
		$request = new \WP_REST_Request( $method, '/wc/v3' . $route );
		if ( 'GET' === $method || 'DELETE' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_body_params( $params );
		}
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON text, never HTML.
			throw new ToolError( 'WooCommerce refused: ' . wp_strip_all_tags( $response->as_error()->get_error_message() ) . ' Nothing was changed.' );
		}
		return (array) json_decode( (string) wp_json_encode( $response->get_data() ), true );
	}

	/**
	 * Current state of what the call changes, for the snapshot.
	 *
	 * @param string               $mode  zone | method | tax | tax-delete.
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>|null
	 */
	private static function before( string $mode, array $input ): ?array {
		$id = (int) ( $input['id'] ?? 0 );
		if ( 'zone' === $mode ) {
			if ( $id <= 0 ) {
				return null;
			}
			$zone = self::wc( 'GET', '/shipping/zones/' . $id );
			return array(
				'zone'      => array(
					'name'  => (string) ( $zone['name'] ?? '' ),
					'order' => (int) ( $zone['order'] ?? 0 ),
				),
				'locations' => array_map(
					static fn( $l ) => array(
						'code' => (string) $l['code'],
						'type' => (string) $l['type'],
					),
					self::wc( 'GET', '/shipping/zones/' . $id . '/locations' )
				),
			);
		}
		if ( 'method' === $mode ) {
			$instance = (int) ( $input['instance_id'] ?? 0 );
			if ( $instance <= 0 ) {
				return null;
			}
			$method   = self::wc( 'GET', '/shipping/zones/' . (int) $input['zone_id'] . '/methods/' . $instance );
			$settings = array();
			foreach ( (array) ( $method['settings'] ?? array() ) as $key => $field ) {
				$settings[ (string) $key ] = $field['value'] ?? '';
			}
			return array(
				'enabled'  => (bool) ( $method['enabled'] ?? false ),
				'order'    => (int) ( $method['order'] ?? 0 ),
				'settings' => $settings,
			);
		}
		if ( $id <= 0 ) {
			return null;
		}
		$rate = self::wc( 'GET', '/taxes/' . $id );
		return array_intersect_key( $rate, array_flip( self::TAX_FIELDS ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): ?array {
		$state = self::before( $this->mode, $input );
		if ( null === $state ) {
			return null;
		}
		return array(
			'object_type' => 'woo_' . $this->mode,
			'object_id'   => (string) (int) ( $input['instance_id'] ?? $input['id'] ?? 0 ),
			'payload'     => array(
				'mode'        => $this->mode,
				'id'          => (int) ( $input['id'] ?? 0 ),
				'zone_id'     => (int) ( $input['zone_id'] ?? 0 ),
				'instance_id' => (int) ( $input['instance_id'] ?? 0 ),
				'state'       => $state,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 */
	public function restore( array $payload ): void {
		$state = (array) ( $payload['state'] ?? array() );
		switch ( (string) ( $payload['mode'] ?? '' ) ) {
			case 'zone':
				$id = (int) $payload['id'];
				self::wc( 'PUT', '/shipping/zones/' . $id, (array) ( $state['zone'] ?? array() ) );
				self::wc_json( 'PUT', '/shipping/zones/' . $id . '/locations', array_values( (array) ( $state['locations'] ?? array() ) ) );
				break;
			case 'method':
				self::wc( 'PUT', '/shipping/zones/' . (int) $payload['zone_id'] . '/methods/' . (int) $payload['instance_id'], $state );
				break;
			case 'tax':
				self::wc( 'PUT', '/taxes/' . (int) $payload['id'], $state );
				break;
			default:
				self::wc( 'POST', '/taxes', $state );
		}
	}

	/**
	 * A wc/v3 call whose body is a JSON list (zone locations).
	 *
	 * @param string             $method PUT.
	 * @param string             $route  Route under /wc/v3.
	 * @param list<array<mixed>> $items Body.
	 * @return array<int|string, mixed>
	 * @throws ToolError When WooCommerce refuses.
	 */
	private static function wc_json( string $method, string $route, array $items ): array {
		$request = new \WP_REST_Request( $method, '/wc/v3' . $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $items ) );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON text, never HTML.
			throw new ToolError( 'WooCommerce refused the locations: ' . wp_strip_all_tags( $response->as_error()->get_error_message() ) );
		}
		return (array) json_decode( (string) wp_json_encode( $response->get_data() ), true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the input is incomplete or WooCommerce refuses.
	 */
	public function execute( array $input ): array {
		$id = (int) ( $input['id'] ?? 0 );
		switch ( $this->mode ) {
			case 'zone':
				$body = array_intersect_key( $input, array_flip( array( 'name', 'order' ) ) );
				if ( $id <= 0 && empty( $body['name'] ) ) {
					throw new ToolError( 'A new zone needs a name. Nothing was created.' );
				}
				$zone = $id > 0 ? ( $body ? self::wc( 'PUT', '/shipping/zones/' . $id, $body ) : self::wc( 'GET', '/shipping/zones/' . $id ) ) : self::wc( 'POST', '/shipping/zones', $body );
				$zid  = (int) ( $zone['id'] ?? 0 );
				AuditLog::target( 'woo_zone', (string) $zid );
				if ( isset( $input['locations'] ) ) {
					self::wc_json( 'PUT', '/shipping/zones/' . $zid . '/locations', array_values( (array) $input['locations'] ) );
				}
				return array(
					'item'    => $zone + array( 'locations' => self::wc( 'GET', '/shipping/zones/' . $zid . '/locations' ) ),
					'created' => $id <= 0,
				);
			case 'method':
				$zone     = (int) $input['zone_id'];
				$instance = (int) ( $input['instance_id'] ?? 0 );
				$body     = array_intersect_key( $input, array_flip( array( 'enabled', 'order', 'settings' ) ) );
				AuditLog::target( 'woo_zone', (string) $zone );
				if ( $instance > 0 ) {
					return array(
						'item'    => self::wc( 'PUT', '/shipping/zones/' . $zone . '/methods/' . $instance, $body ),
						'created' => false,
					);
				}
				if ( empty( $input['method_id'] ) ) {
					throw new ToolError( 'Adding a method needs method_id (e.g. flat_rate, free_shipping, local_pickup). Nothing was added.' );
				}
				$added = self::wc( 'POST', '/shipping/zones/' . $zone . '/methods', array( 'method_id' => (string) $input['method_id'] ) );
				if ( $body ) {
					$added = self::wc( 'PUT', '/shipping/zones/' . $zone . '/methods/' . (int) $added['instance_id'], $body );
				}
				return array(
					'item'    => $added,
					'created' => true,
				);
			case 'tax':
				$body = array_intersect_key( $input, array_flip( self::TAX_FIELDS ) );
				if ( $id <= 0 && ! isset( $body['rate'] ) ) {
					throw new ToolError( 'A new tax rate needs rate. Nothing was created.' );
				}
				$rate = $id > 0 ? self::wc( 'PUT', '/taxes/' . $id, $body ) : self::wc( 'POST', '/taxes', $body );
				AuditLog::target( 'woo_tax_rate', (string) (int) ( $rate['id'] ?? 0 ) );
				return array(
					'item'    => $rate,
					'created' => $id <= 0,
				);
			default:
				AuditLog::target( 'woo_tax_rate', (string) $id );
				$deleted = self::wc( 'DELETE', '/taxes/' . $id, array( 'force' => true ) );
				return array(
					'item'    => $deleted,
					'deleted' => true,
				);
		}//end switch
	}
}
