<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_Hblpay_Blocks extends AbstractPaymentMethodType {

	private $gateway;
	protected $name = 'hblpay';// payment gateway name

	public function initialize() {
		$this->settings = get_option( 'woocommerce_hblpay_settings', [] );
		$this->gateway = new WC_HblPay_Gateway();
	}

	public function is_active() {
		return $this->get_setting( 'enabled' ) === "yes";
	}

//get script js and necessary elements
	public function get_payment_method_script_handles() {

		wp_register_script(
			'wc-hblpay-blocks-integration',
            plugin_dir_url(__FILE__) . 'includes/block/checkout.js',
			[
				'wc-blocks-registry',
				'wc-settings',
				'wp-element',
				'wp-html-entities',
				'wp-i18n',
			],
			false,
			true
		);

		if( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'wc-hblpay-blocks-integration');
		}
		return [ 'wc-hblpay-blocks-integration' ];
	}
//get payment method name and Description
	public function get_payment_method_data() {
		return [

			'title' => $this->gateway->title,
			'description' => $this->gateway->description,
		];
	}}
