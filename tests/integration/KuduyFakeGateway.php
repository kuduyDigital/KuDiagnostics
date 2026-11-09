<?php
/**
 * Test double for WooStoreSettingsTest: a payment gateway like an extension's. Loaded only when WooCommerce is active.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

/**
 * A gateway like an extension's, with a password field under a free name and a secret under a key-like name.
 */
final class KuduyFakeGateway extends \WC_Payment_Gateway {
	public function __construct() {
		$this->id           = 'kuduy_fake';
		$this->method_title = 'Kuduy Fake Pay';
		$this->form_fields  = array(
			'enabled'       => array(
				'title' => 'Enable',
				'type'  => 'checkbox',
			),
			'title'         => array(
				'title' => 'Title',
				'type'  => 'text',
			),
			'testmode'      => array(
				'title' => 'Test mode',
				'type'  => 'checkbox',
			),
			'merchant_pin'  => array(
				'title' => 'Merchant PIN',
				'type'  => 'password',
			),
			'api_signature' => array(
				'title' => 'API signature',
				'type'  => 'text',
			),
			// A checkbox whose name looks like a credential (Stripe has sepa_tokens_for_ideal): it holds yes/no only.
			'sepa_tokens'   => array(
				'title' => 'Save SEPA tokens',
				'type'  => 'checkbox',
			),
		);
		$this->init_settings();
		$this->title   = (string) $this->get_option( 'title' );
		$this->enabled = (string) $this->get_option( 'enabled' );
	}
}
