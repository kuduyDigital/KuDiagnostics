<?php
/**
 * Tool: kuduy-woo-status.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Woo;

use Automattic\WooCommerce\Utilities\OrderUtil;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * Store overview: WooCommerce version, order storage (HPOS), currency and the settings that change how the other
 * kuduy-woo-* tools behave. No secrets: payment gateways have their own admin-only tool.
 */
final class WooStatus implements Tool {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-woo-status';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return __( 'WooCommerce store status', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'WooCommerce version, order storage (HPOS on/off, compatibility sync), currency and price format, whether prices include tax, whether tax, shipping, coupons, reviews and analytics are on, stock management, coming-soon mode (store hidden from visitors) and the store pages. Start here before changing products or orders.';
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
		$flag = array( 'type' => 'boolean' );
		return array(
			'type'       => 'object',
			'properties' => array(
				'version'            => array( 'type' => 'string' ),
				'hpos'               => array(
					'type'       => 'object',
					'properties' => array(
						'enabled' => $flag,
						'sync'    => $flag,
					),
				),
				'currency'           => array(
					'type'       => 'object',
					'properties' => array(
						'code'      => array( 'type' => 'string' ),
						'symbol'    => array( 'type' => 'string' ),
						'decimals'  => array( 'type' => 'integer' ),
						'position'  => array( 'type' => 'string' ),
						'thousands' => array( 'type' => 'string' ),
						'decimal'   => array( 'type' => 'string' ),
					),
				),
				'base_country'       => array( 'type' => 'string' ),
				'prices_include_tax' => $flag,
				'tax_enabled'        => $flag,
				'shipping_enabled'   => $flag,
				'coupons_enabled'    => $flag,
				'reviews_enabled'    => $flag,
				'manage_stock'       => $flag,
				'analytics_enabled'  => $flag,
				'coming_soon'        => $flag,
				'pages'              => array(
					'type'                 => 'object',
					'additionalProperties' => array( 'type' => array( 'integer', 'null' ) ),
				),
			),
			'required'   => array( 'version', 'hpos', 'currency', 'coming_soon' ),
		);
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
		// Store staff: anyone who can work on products (shop managers and administrators by default).
		return 'edit_products';
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
	 */
	public function execute( array $input ): array {
		$pages = array();
		foreach ( array( 'shop', 'cart', 'checkout', 'myaccount', 'terms' ) as $page ) {
			$id             = (int) wc_get_page_id( $page );
			$pages[ $page ] = $id > 0 ? $id : null;
		}
		return array(
			'version'            => (string) WC_VERSION,
			'hpos'               => array(
				'enabled' => OrderUtil::custom_orders_table_usage_is_enabled(),
				'sync'    => OrderUtil::custom_orders_table_data_sync_is_enabled(),
			),
			'currency'           => array(
				'code'      => get_woocommerce_currency(),
				'symbol'    => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
				'decimals'  => wc_get_price_decimals(),
				'position'  => (string) get_option( 'woocommerce_currency_pos', 'left' ),
				'thousands' => wc_get_price_thousand_separator(),
				'decimal'   => wc_get_price_decimal_separator(),
			),
			'base_country'       => (string) WC()->countries->get_base_country(),
			'prices_include_tax' => wc_prices_include_tax(),
			'tax_enabled'        => wc_tax_enabled(),
			'shipping_enabled'   => wc_shipping_enabled(),
			'coupons_enabled'    => wc_coupons_enabled(),
			'reviews_enabled'    => 'yes' === get_option( 'woocommerce_enable_reviews', 'yes' ),
			'manage_stock'       => 'yes' === get_option( 'woocommerce_manage_stock', 'yes' ),
			'analytics_enabled'  => 'no' !== get_option( 'woocommerce_analytics_enabled', 'yes' ),
			'coming_soon'        => 'yes' === get_option( 'woocommerce_coming_soon', 'no' ),
			'pages'              => $pages,
		);
	}
}
