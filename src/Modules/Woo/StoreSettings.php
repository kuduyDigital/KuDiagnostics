<?php
/**
 * Tools: kuduy-woo-shipping, kuduy-woo-tax, kuduy-woo-payment-gateways (read only).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Woo;

use Kuduy\Mcp\Audit\Redactor;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * Store configuration through WooCommerce's own classes: WC_Shipping_Zones / WC_Shipping_Zone, WC_Tax, and the
 * payment gateway registry. Read only in Phase 7 (writes later, after the reads are checked on a real host). Every
 * settings field of type password, and every field whose name looks like a credential, is returned as
 * `value: null, has_value: true|false`: WooCommerce's own REST API returns gateway secrets in clear text, KUDUY does
 * not (RESEARCH.md §10).
 */
final class StoreSettings implements Tool {

	/**
	 * Mode.
	 *
	 * @param string $mode shipping | tax | gateways.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'gateways' === $this->mode ? 'kuduy-woo-payment-gateways' : 'kuduy-woo-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'shipping' => __( 'Shipping zones and methods', 'kuduy-mcp' ),
			'tax'      => __( 'Tax settings and rates', 'kuduy-mcp' ),
			default    => __( 'Payment methods', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'shipping' => 'Lists WooCommerce shipping zones in their matching order (the "rest of the world" zone last) with their regions (country:DE, state:US:CA, postcode:…, continent:EU) and shipping methods: type, title, enabled, and their settings (cost, minimum amount, …). Read only.',
			'tax'      => 'WooCommerce tax settings (taxes on, prices entered with tax, tax based on, shop and cart display) and every tax class with its rates: country, state, postcodes, cities, rate, name, priority, compound, applies to shipping. Read only.',
			default    => 'Lists WooCommerce payment methods: id, title, enabled, test/sandbox mode, supported features and their settings. Secrets (API keys, passwords, signatures, tokens) are never shown: such fields have value null and has_value true/false. Read only.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		$list = array(
			'type'  => 'array',
			'items' => array( 'type' => 'object' ),
		);
		return match ( $this->mode ) {
			'shipping' => array(
				'type'       => 'object',
				'properties' => array(
					'enabled' => array( 'type' => 'boolean' ),
					'zones'   => $list,
				),
				'required'   => array( 'enabled', 'zones' ),
			),
			'tax'      => array(
				'type'       => 'object',
				'properties' => array(
					'enabled'  => array( 'type' => 'boolean' ),
					'settings' => array(
						'type'                 => 'object',
						'additionalProperties' => array( 'type' => 'string' ),
					),
					'classes'  => $list,
				),
				'required'   => array( 'enabled', 'settings', 'classes' ),
			),
			default    => array(
				'type'       => 'object',
				'properties' => array(
					'gateways' => $list,
				),
				'required'   => array( 'gateways' ),
			),
		};//end match
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
		// Payment configuration is store administration (D13); shipping and tax are everyday store facts.
		return 'gateways' === $this->mode ? 'kuduy:admin' : 'kuduy:read';
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
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When WooCommerce refuses.
	 */
	public function execute( array $input ): array {
		$object = match ( $this->mode ) {
			'shipping' => 'shipping_methods',
			'tax'      => 'settings',
			default    => 'payment_gateways',
		};
		if ( ! wc_rest_check_manager_permissions( $object, 'read' ) ) {
			throw new ToolError( 'WooCommerce does not allow your account to read the store settings.' );
		}
		return match ( $this->mode ) {
			'shipping' => self::shipping(),
			'tax'      => self::tax(),
			default    => self::gateways(),
		};
	}

	/**
	 * Settings fields with their values; secrets never leave.
	 *
	 * @param array<string, mixed> $fields Form fields (id => field).
	 * @param callable             $value  Value of a field id.
	 * @return list<array<string, mixed>>
	 */
	public static function settings( array $fields, callable $value ): array {
		$out = array();
		foreach ( $fields as $id => $field ) {
			$field = is_array( $field ) ? $field : array();
			$type  = (string) ( $field['type'] ?? 'text' );
			if ( in_array( $type, array( 'title', 'sectionend' ), true ) ) {
				continue;
			}
			$raw    = $value( (string) $id );
			$secret = 'password' === $type || Redactor::is_secret_key( (string) $id );
			$out[]  = array(
				'id'        => (string) $id,
				'label'     => wp_strip_all_tags( (string) ( $field['title'] ?? $field['label'] ?? $id ) ),
				'type'      => $type,
				'value'     => $secret ? null : ( is_scalar( $raw ) || is_array( $raw ) ? $raw : null ),
				'has_value' => '' !== $raw && null !== $raw && array() !== $raw,
			);
		}
		return $out;
	}

	/**
	 * Shipping zones.
	 *
	 * @return array<string, mixed>
	 */
	private static function shipping(): array {
		$ids   = array_map( 'intval', array_keys( \WC_Shipping_Zones::get_zones( 'admin' ) ) );
		$ids[] = 0;
		$zones = array();
		foreach ( $ids as $id ) {
			$zone    = \WC_Shipping_Zones::get_zone( $id );
			$regions = array();
			foreach ( $zone->get_zone_locations( 'edit' ) as $location ) {
				$regions[] = $location->type . ':' . $location->code;
			}
			$methods = array();
			foreach ( $zone->get_shipping_methods( false, 'admin' ) as $method ) {
				$methods[] = array(
					'instance_id'  => (int) $method->instance_id,
					'method_id'    => (string) $method->id,
					'method_title' => (string) $method->get_method_title(),
					'title'        => (string) $method->get_title(),
					'enabled'      => $method->is_enabled(),
					'settings'     => array_column(
						self::settings(
							$method->get_instance_form_fields(),
							static fn( string $key ) => $method->get_instance_option( $key )
						),
						'value',
						'id'
					),
				);
			}
			$zones[] = array(
				'id'      => $id,
				'name'    => 0 === $id ? __( 'Locations not covered by your other zones', 'kuduy-mcp' ) : $zone->get_zone_name( 'edit' ),
				'order'   => (int) $zone->get_zone_order( 'edit' ),
				'regions' => $regions,
				'methods' => $methods,
			);
		}//end foreach
		return array(
			'enabled' => wc_shipping_enabled(),
			'zones'   => $zones,
		);
	}

	/**
	 * Tax settings and rates.
	 *
	 * @return array<string, mixed>
	 */
	private static function tax(): array {
		$settings = array();
		foreach ( array( 'prices_include_tax', 'tax_based_on', 'shipping_tax_class', 'tax_round_at_subtotal', 'tax_display_shop', 'tax_display_cart', 'tax_total_display' ) as $key ) {
			$settings[ $key ] = (string) get_option( 'woocommerce_' . $key, '' );
		}
		$classes = array(
			'' => __( 'Standard', 'kuduy-mcp' ),
		);
		foreach ( \WC_Tax::get_tax_classes() as $index => $name ) {
			$classes[ \WC_Tax::get_tax_class_slugs()[ $index ] ?? sanitize_title( $name ) ] = $name;
		}
		$out = array();
		foreach ( $classes as $slug => $name ) {
			$rates = array();
			foreach ( \WC_Tax::get_rates_for_tax_class( (string) $slug ) as $rate ) {
				$rates[] = array(
					'id'        => (int) $rate->tax_rate_id,
					'country'   => (string) $rate->tax_rate_country,
					'state'     => (string) $rate->tax_rate_state,
					'postcodes' => array_values( array_map( 'strval', (array) ( $rate->postcode ?? array() ) ) ),
					'cities'    => array_values( array_map( 'strval', (array) ( $rate->city ?? array() ) ) ),
					'rate'      => (string) $rate->tax_rate,
					'name'      => (string) $rate->tax_rate_name,
					'priority'  => (int) $rate->tax_rate_priority,
					'compound'  => (bool) $rate->tax_rate_compound,
					'shipping'  => (bool) $rate->tax_rate_shipping,
				);
			}
			$out[] = array(
				'slug'  => (string) $slug,
				'name'  => (string) $name,
				'rates' => $rates,
			);
		}//end foreach
		return array(
			'enabled'  => wc_tax_enabled(),
			'settings' => $settings,
			'classes'  => $out,
		);
	}

	/**
	 * Payment gateways, secrets masked.
	 *
	 * @return array<string, mixed>
	 */
	private static function gateways(): array {
		$out = array();
		foreach ( WC()->payment_gateways()->payment_gateways() as $gateway ) {
			if ( ! $gateway instanceof \WC_Payment_Gateway ) {
				continue;
			}
			$settings = self::settings( $gateway->get_form_fields(), static fn( string $key ) => $gateway->get_option( $key ) );
			$test     = null;
			foreach ( array( 'testmode', 'sandbox', 'test_mode' ) as $key ) {
				if ( array_key_exists( $key, $gateway->get_form_fields() ) ) {
					$test = 'yes' === $gateway->get_option( $key );
				}
			}
			$out[] = array(
				'id'           => (string) $gateway->id,
				'title'        => wp_strip_all_tags( (string) $gateway->get_title() ),
				'method_title' => wp_strip_all_tags( (string) $gateway->get_method_title() ),
				'enabled'      => 'yes' === $gateway->enabled,
				'test_mode'    => $test,
				'supports'     => array_values( array_map( 'strval', (array) $gateway->supports ) ),
				'settings'     => $settings,
			);
		}//end foreach
		return array( 'gateways' => $out );
	}
}
