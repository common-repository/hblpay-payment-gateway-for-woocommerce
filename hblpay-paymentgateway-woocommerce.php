<?php
/*
* Plugin Name: HBLPAY Payment Gateway for WooCommerce
* Plugin URI: https://wordpress.org/plugins/hblpay-payment-gateway-for-woocommerce
* Description: Collect payment from multiple payment gateway on your store.
* Author: HBL
* Author URI: https://hbl.com
* Version: 4.0.0
* Requires at least: 5.8.0
* Requires PHP: 7.4.1
* Tested up to: 6.6
* WC requires at least: 6.9.4
* WC tested up to:  9.1.2
* License: GPL v2 or later
* License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

//class of utilities for dealing with orders.
use Automattic\WooCommerce\Utilities\OrderUtil;
ob_start();

//----------------**setting version requirements** defing minimum version for WC---------------------------//
define( 'WC_HBLPAY_MIN_WC_VER', '6.9.4' );
//--------function declared here and called in main init class----------------//
function woocommerce_hblpay_missing_wc_notice() {
	/* translators: 1. URL link. */
	echo '<div class="error"><p><strong>' . sprintf( esc_html__( 'HBLPAY requires WooCommerce to be installed and active. You can download %s here.', 'hblpay-payment-gateway-for-woocommerce' ), '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>' ) . '</strong></p></div>';
}
function woocommerce_hblpay_wc_not_supported() {
	/* translators: $1. Minimum WooCommerce version. $2. Current WooCommerce version. */
	echo '<div class="error"><p><strong>' . sprintf( esc_html__( 'HBLPAY requires WooCommerce %1$s or greater to be installed and active. WooCommerce %2$s is no longer supported.', 'hblpay-payment-gateway-for-woocommerce' ), WC_HBLPAY_MIN_WC_VER, WC_VERSION ) . '</strong></p></div>';
}
//---------------**end**---------------------------------------//
						////******************************** HPOS COMPATIBILITY DECLARATION *********************************/////
						add_action( 'before_woocommerce_init', 'hblpay_hpos_compatibility' );

						function hblpay_hpos_compatibility() {

							if( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
								\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
									'custom_order_tables',
									__FILE__,
									true // true (compatible, default) or false (not compatible)
								);
							}
						}
						//////// **************************** END HPOS **************************************************************////////
						////******************************** CHECKOUT BLOCKS COMPATIBILITY DECLARATION *********************************/////
						// Hook the custom function to the 'before_woocommerce_init' action
						add_action('before_woocommerce_init', 'declare_cart_checkout_blocks_compatibility');
						function declare_cart_checkout_blocks_compatibility() {
						    // Check if the required class exists
						    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
						        // Declare compatibility for 'cart_checkout_blocks'
						        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
						    }
						}
						add_action( 'woocommerce_blocks_loaded', 'hblpay_register_order_approval_payment_method_type' );
						/*** Custom function to register a payment method type*/
						function hblpay_register_order_approval_payment_method_type() {
						    // Check if the required class exists
						    if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
						        return;
						    }
						    // Include the custom Blocks Checkout class
							require_once( 'hblpay-paymentgateway-woocommerce-checkout-block.php' );
						    // Hook the registration function to the 'woocommerce_blocks_payment_method_type_registration' action
						    add_action(
						        'woocommerce_blocks_payment_method_type_registration',
						        function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
						            // Register an instance of WC_Hblpay_Blocks
						            $payment_method_registry->register( new WC_Hblpay_Blocks );
						        }
						    );
							}
								/////// **************************** END CHECKOUT BLOCKS **************************************************************////////
//This action hook registers our PHP class as a WooCommerce payment gateway
//////Receiving Data On Return URL Start ***API END POINT********///
add_action( 'rest_api_init', 'HBLPAYPGW_mark_status_completed');
function HBLPAYPGW_mark_status_completed()
{
	register_rest_route('hblpay_response/v1','checkout',array(
		'methods' => WP_REST_Server::READABLE,
		'callback' => 'hblpay_response_result',
		'permission_callback' => '__return_true'
	));
}
//-----------------hook for page creation at the time of plugin activation for cancel order---------------MZ
 register_activation_hook(__FILE__, 'HBLPAYPGW_add_page_cancel_order');
 //function for page creation-------------------------MZ
function HBLPAYPGW_add_page_cancel_order()
{
   $post_details = array(
  'post_title'    => 'Order Cancelled',
  'post_content'  => '',
  'post_status'   => 'publish',
  'post_author'   => 1,
  'post_type' => 'page',

   );
   wp_insert_post( $post_details );
}
//-----------------on deactivation of  plugin delete a page----------------//
function HBLPAYPGW_delete_page_cancel_order() {
    $page = get_page_by_path( 'order-cancelled' );
    wp_delete_post($page->ID);
}
register_deactivation_hook( __FILE__, 'HBLPAYPGW_delete_page_cancel_order' );
//---------------end of page hook--------------------------//

function hblpay_response_result()
{
try{

	if(isset($_GET['data']))
	{
		$redirection_url = '';
		$admin_settings = WC()->payment_gateways->payment_gateways()['hblpay']->settings;
		$dec_key = $admin_settings['private_key'];
		$dec_key = str_replace("-----BEGIN RSA PRIVATE KEY-----","-----BEGIN RSA PRIVATE KEY-----\n",$dec_key);
		$dec_key = str_replace("-----END RSA PRIVATE KEY-----","\n-----END RSA PRIVATE KEY-----",$dec_key);
		$encryptedData = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
		$encryptedData = str_replace("data=", "", $encryptedData);
		$url_params = decryptData($encryptedData, $dec_key);

		if($url_params === '')
		{
			$redirection_url = '/checkout/order-cancelled?data='.encodeString('Your Order is cancelled. Please Try again later.');
			wp_safe_redirect( home_url() . $redirection_url, 301 );
			exit;
		}

		parse_str($url_params,$paramArray);
		error_log(":::::RETURN RESPONSE::::\n" . json_encode($paramArray, JSON_PRETTY_PRINT));

		$order_message = '';
		$order = wc_get_order($paramArray['ORDER_REF_NUMBER']);

		$user_id=$order->get_customer_id();
		$order_data = $order->get_data();

		$orstat = $order_data['status'];

		if($orstat != 'pending')
		{
			$paramArray['RESPONSE_MESSAGE'] = 'You are not authorized to process this order';
			$redirection_url = '/checkout/order-cancelled?cusdata='.encodeString('RESPONSE_MESSAGE='.$paramArray['RESPONSE_MESSAGE'] . '&ORDER_REF_NUMBER='. $paramArray['ORDER_REF_NUMBER'] .'&RESPONSE_CODE='.$paramArray['RESPONSE_CODE']);
			wp_safe_redirect( home_url() . $redirection_url, 301 );
			exit;
		}
///***************SUCCESS RESPONSE************************/////////////////////////
		if($paramArray['RESPONSE_CODE'] == '100' || $paramArray['RESPONSE_CODE'] == '0')
        {
            if($paramArray['ORDER_REF_NUMBER'])
            {
                HBLPAYPGW_mark_order_completed($paramArray['ORDER_REF_NUMBER'], $paramArray['RESPONSE_MESSAGE']);
								error_log('::::::::::::::::UPDATING ORDER STATUS TO COMPLETED/Processing::::::::::::::::::ORDERID:::::'.$paramArray['ORDER_REF_NUMBER']);
								if($paramArray['DISCOUNTED_AMOUNT'] != 0) {
								wc_order_add_discount_campaign($paramArray['ORDER_REF_NUMBER'],$paramArray['DISCOUNTED_AMOUNT'],$paramArray['DISCOUNT_CAMPAIGN_ID']);
								error_log('::::::::::::::::Added Bin Discounts::::::::::::::::::ORDERID:::::'.$paramArray['ORDER_REF_NUMBER']);
							}
					  }
            $redirection_url = '/checkout/order-received?cusdata='.encodeString('RESPONSE_MESSAGE='.$paramArray['RESPONSE_MESSAGE'] . '&ORDER_REF_NUMBER='. $paramArray['ORDER_REF_NUMBER'] .'&RESPONSE_CODE='.$paramArray['RESPONSE_CODE']);
        }
///********************CANCELLED RESPONSE*****************/////////////////////////
		else if ($paramArray['RESPONSE_CODE'] == '' || $paramArray['RESPONSE_CODE'] == '112')
		{
			if(isset($paramArray['ORDER_REF_NUMBER']) && $paramArray['ORDER_REF_NUMBER'] != null && $paramArray['ORDER_REF_NUMBER'] != '')
			{
				HBLPAYPGW_mark_order_cancelled($paramArray['ORDER_REF_NUMBER'], 'Order is Cancelled');
				error_log('::::::::::::::::UPDATING ORDER STATUS TO CANCELLED:::::::ORDERID:::::'.$paramArray['ORDER_REF_NUMBER']);
			}
			$redirection_url = '/checkout/order-cancelled?cusdata='.encodeString('RESPONSE_MESSAGE='.$paramArray['RESPONSE_MESSAGE'] . '&ORDER_REF_NUMBER='. $paramArray['ORDER_REF_NUMBER'] .'&RESPONSE_CODE='.$paramArray['RESPONSE_CODE']);
		}
///********FOR REST OF RESPONSES**************************************////////////////////////////////////
        else
        {
            HBLPAYPGW_mark_order_failed($paramArray['ORDER_REF_NUMBER'], $paramArray['RESPONSE_MESSAGE']);
						error_log('::::::::::::::::UPDATING ORDER STATUS TO FAILED:::::::ORDERID:::::'.$paramArray['ORDER_REF_NUMBER']);
            $redirection_url ='/order-cancelled?cusdata='.encodeString('RESPONSE_MESSAGE='.$paramArray['RESPONSE_MESSAGE'] . '&ORDER_REF_NUMBER='. $paramArray['ORDER_REF_NUMBER'] .'&RESPONSE_CODE='.$paramArray['RESPONSE_CODE'] );
        }
		 $decrypted_string = $url_params;
		 wp_safe_redirect( home_url() . $redirection_url, 301 );
		 exit;
		return 'success';
	}
}
	catch (Exception $e)
	{
		error_log("::::ORDERID:::::.'.$order_id:::Web Exception Raised...".$e->getMessage().':::Error code: ' . $e->getCode().':::Error Line:' . $e->getLine());
	}
}

//content for cancelled order_page
function HBLPAYPGW_content_for_cancelled_order( $content ) {
	$encodedStr = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
		$str_params = strpos($encodedStr ?? '', 'cusdata=');
	//echo $str_params;
	if($str_params === 0)
	{
		$encodedStr = str_replace("cusdata=", "", $encodedStr);
		$items = decodeString($encodedStr);
		parse_str($items, $paramArray);
		return $paramArray['RESPONSE_MESSAGE'] .' Your Order number is '.$paramArray['ORDER_REF_NUMBER'].'.'.'<form action="'.home_url().'"><input type="submit" value="Return To Home" /></form>';
}
		return $content;
}
add_filter( 'the_content', 'HBLPAYPGW_content_for_cancelled_order' );

add_filter( 'woocommerce_endpoint_order-received_title', 'HBLPAYPGW_thank_you_title' );
function HBLPAYPGW_thank_you_title($old_title)
{
	$encodedStr = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
	$str_params = strpos($encodedStr, 'cusdata=');
	if($str_params === 0)
	{
		$encodedStr = str_replace("cusdata=", "", $encodedStr);
		$items = decodeString($encodedStr);
		parse_str($items, $paramArray);
		if($paramArray['RESPONSE_CODE'] == '')
		{
			return 'Order cancelled';
		}
		else
		{
			return 'Order received';
		}
	}
	else
	{
		return 'Order received';
	}
}

add_filter( 'woocommerce_thankyou_order_received_text', 'HBLPAYPGW_thank_you_title_modify', 20, 2 );
function HBLPAYPGW_thank_you_title_modify()
{
	$encodedStr = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
	if(strpos($encodedStr, 'cusdata=')){
		$str_params = strpos($encodedStr, 'cusdata=');
		$encodedStr = str_replace("cusdata=", "", $encodedStr);
		$items = decodeString($encodedStr);
		parse_str($items, $paramArray);
	if($str_params === 0)
	{
		if($paramArray['RESPONSE_CODE'] === '')
		{
			return $paramArray['RESPONSE_MESSAGE'] . '<form action="'.home_url().'"><input type="submit" value="Return To Home" /></form>';
		}
		else
		{
			//when payment is successfull order received page message***
			return $paramArray['RESPONSE_MESSAGE'] . 'Your order number is ' .$paramArray['ORDER_REF_NUMBER'] . '<form action="'.home_url().'"><input type="submit" value="Return To Home" /></form>';
		}
}
	else
	{
			return 'Thank you. Your order has been received.' . 'Your order number is ' .$paramArray['ORDER_REF_NUMBER'] . '<form action="'.home_url().'"><input type="submit" value="Return To Home" /></form>';
}
}
else{
	return 'Thank you. Your order has been received.';
}
}

function HBLPAYPGW_mark_order_completed( $order_id, $note )
{
  $order = wc_get_order( $order_id );
	$order_data = $order->get_data();
	$order_status = $order_data['status'];

	if($order_status === 'pending')
	{
		$order->update_status( 'processing' );
		$order->add_order_note($note);
	}
}

function HBLPAYPGW_mark_order_cancelled( $order_id, $note )
{
  $order = wc_get_order( $order_id );
	$order_data = $order->get_data();
	$order_status = $order_data['status'];

	if($order_status === 'pending')
	{
		$order->update_status( 'cancelled' );
		$order->add_order_note($note);
	}
}

//**new status for failed order
function HBLPAYPGW_mark_order_failed( $order_id, $note )
{
  $order = wc_get_order( $order_id );
	$order_data = $order->get_data();
	$order_status = $order_data['status'];

	if($order_status === 'pending')
	{
		$order->update_status( 'failed' );
		$order->add_order_note($note);
	}
}
///****************GATEWAY MAIN CLASSS****************************////////
add_filter( 'woocommerce_payment_gateways', 'hblpay_add_gateway_class' );
function hblpay_add_gateway_class( $gateways )
{
	$gateways[] = 'WC_HblPay_Gateway'; //CLASSNAME
	return $gateways;
}
/** The class itself, please note that it is inside plugins_loaded action hook*/
add_action( 'plugins_loaded', 'hblpay_init_gateway_class' );
function hblpay_init_gateway_class()
{
	//notification if woocommerce is missing--calling
	if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', 'woocommerce_hblpay_missing_wc_notice' );
			return;
		}

	//using define to compare WC require version
	if ( version_compare( WC_VERSION, WC_HBLPAY_MIN_WC_VER, '<' ) ) {
		add_action( 'admin_notices', 'woocommerce_hblpay_wc_not_supported' );
		return;
	}
	//ACTION HOOK FOR SHOWING BIN DISCOUNT TO ORDER TOTALS ADMIN PAGE
	add_action( 'woocommerce_admin_order_totals_after_discount', 'wc_show_discount_admin');
		function wc_show_discount_admin( $order_id ){
			//IF HPOS IS ENABLED
	if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
		$order = wc_get_order( $order_id );
		if($order) :
		$bin_discount = $order->get_meta( '_discount', true );
			endif;
}
		//LEGACY POST TABLES
else{
	$order = wc_get_order($order_id);
	$bin_discount =	get_post_meta($order->get_id(), "_discount", true);
}
if($bin_discount):
	?>
	<tr>
				<td class="label">Bin Discount</td>
				<td width="1%"></td>
				<td class="total">
					<span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol"></span>
						<?php echo wc_price($bin_discount); ?></bdi></span>
					</td>
			<?php
				endif;
}

//ACTION HOOK FOR SHOWING CAMPAIGN ID TO ORDER DEATILS SECTION
add_action( 'woocommerce_admin_order_data_after_shipping_address', 'wc_show_campaignid_admin');
function wc_show_campaignid_admin( $order_id ){
	//IF HPOS IS ENABLED
	if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
		$order = wc_get_order( $order_id );
		if($order) :
		$campaign_id = $order->get_meta( '_campaign_id', true );
			endif;
			}
			//LEAGACY POST TABLES
	else{
			$order = wc_get_order($order_id);
			$campaign_id =	get_post_meta($order->get_id(), "_campaign_id", true);
		}
	 if($campaign_id):?>
		 <div class="address">
	 			<strong>Campaign ID</strong>
				<?php echo $campaign_id; ?>
			</p>
		</div>
		<?php
endif;
}
  //ACTION HOOK TO DISPLAY BIN DISCOUNT TO CLIENT
	add_action('woocommerce_order_details_after_order_table_items','wc_show_discount_client');
	function wc_show_discount_client( $order_id ){
		//HPOS ENABLED
		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$order = wc_get_order( $order_id );
			if($order) :
			$discount = $order->get_meta( '_discount', true );
				endif;
		}
		//LEGACY POST TABLES
			else{
		$order = wc_get_order($order_id);
		$discount =	get_post_meta($order->get_id(), "discount", true);
	}
		if($discount) :
	?>
	<tr>
			<th scope="row">Bin Discount:</th>
			<td><?php echo wc_price($discount); ?></td>
		</tr>
			<?php
			endif;
	}

#[AllowDynamicProperties]

	class WC_HblPay_Gateway extends WC_Payment_Gateway
	{

 		/*** Class constructor*/
 		public function __construct()
		{
			$this->id = 'hblpay'; // payment gateway plugin ID
			$this->icon = ''; // URL of the icon that will be displayed on checkout page near your gateway name
			$this->has_fields = true; // in case you need a custom credit card form
			$this->method_title = 'HBLPAY Payment Gateway For Woocommerce';
			$this->method_description = 'HBLPay Payment Gateway is simple checkout platform that enables ecommerce merchants to accept online payments from its VISA, Master and Union Pay credit/debit cards customers.'; // will be displayed on the options page
			$this->supports = array(
				'products',
				'subscriptions'
			);
			// Method with all the options fields
			$this->init_form_fields();
			// Load the settings.
			$this->init_settings();
			$this->title = $this->get_option( 'title' );
			$this->description = $this->get_option( 'description' );
			$this->enabled = $this->get_option( 'enabled' );
			$this->private_key = $this->get_option( 'private_key' );
			$this->publishable_key = $this->get_option( 'gateway_public_key' );
			// This action hook saves the settings
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options'));
 		}
/*** Plugin options*******/
		public function init_form_fields()
		{
				$this->form_fields = array(
				'enabled' => array(
					'title'       => 'Enable/Disable',
					'label'       => 'Enable HBLPay Gateway',
					'type'        => 'checkbox',
					'description' => '',
					'default'     => 'no'
				),
				'is_live' => array(
					'title'       => 'Live Environment',
					'label'       => 'Enable Live Environment',
					'type'        => 'checkbox',
					'description' => '',
					'default'     => 'no'
				),
				'use_proxy' => array(
					'title'       => 'Use Proxy',
					'label'       => 'Enable Proxy',
					'type'        => 'checkbox',
					'description' => '',
					'default'     => 'no'
				),
				'proxy' => array(
					'title'       => 'Proxy',
					'type'        => 'text'
				),
				'title' => array(
					'title'       => 'Title',
					'type'        => 'text',
					'description' => 'This controls the title which the user sees during checkout.',
					'default'     => 'HBL Pay',
					'desc_tip'    => true,
				),
				'description' => array(
					'title'       => 'Description',
					'type'        => 'textarea',
					'description' => 'This controls the description which the user sees during checkout.',
					'default'     => 'Pay through HBL payment gateway and do whatever you want',
				),
				'client_name' => array(
					'title'       => 'Client Name',
					'type'        => 'text'
				),
				'channel' => array(
					'title'       => 'Channel',
					'type'        => 'text'
				),
				'password' => array(
					'title'       => 'Password',
					'type'        => 'password'
				),
				'gateway_public_key' => array(
					'title'       => 'Gateway Public Key',
					'type'        => 'text'
				),
				'private_key' => array(
					'title'       => 'Store Private Key',
					'type'        => 'password'
				),
			'selected_method' => array(
				'title'       => 'Selected Payment Gateway',
				'type'        => 'select',
				'options'       => array(
					'0'          => __("None", "woocommerce"),
					'1001'  => __("Union Pay", "woocommerce"),
					'1002'  => __("Cybersource", "woocommerce"),
					)
			)
			);
		}
		//show Description ON CHECKOUT PAGE-----------MZ
			 public function payment_fields(){
				 $this->init_settings();
					if( $this->description ){
					 echo wp_kses_post( wpautop( wptexturize( $this->description ) ) );
				 }
				}

		public function process_payment($order_id)
		{
			error_log('::::::::::::::::PROCESS PAYMENT STARTED::::::::::::::::::'.$order_id);
			global $woocommerce;
			$order = wc_get_order($order_id);
			$customer_id = $order->get_user_id();
			$admin_settings = WC()->payment_gateways->payment_gateways()['hblpay']->settings;
			$requestData = $this->getRequestObjectAES($order, $order_id);

			$jsondata = json_encode($requestData);
			error_log($jsondata);
			$order->update_status('pending');
			$api_url = '';
			$page_url = '';
			$admin_settings = WC()->payment_gateways->payment_gateways()['hblpay']->settings;

			if($admin_settings['is_live'] === 'yes')
			{
					$api_url = 'https://digitalbankingportal.hbl.com/HostedCheckout/api/checkout/V2';
					$page_url = 'https://digitalbankingportal.hbl.com/HostedCheckout/Site/index.html#/checkout?data=';
			}
			else
			{
					$api_url = 'https://testpaymentapi.hbl.com/HBLPay/api/Checkout/V2';
					$page_url = 'https://testpaymentapi.hbl.com/HBLPay/Site/index.html#/checkout?data=';
			}
			error_log('::::::::::::::::Before Api call::::::::::ORDERID::::::::'.$order_id);
			try{
				$f_response = callAPI('POST', $api_url, $jsondata,$order_id);
				error_log('::::::::::Response from API::::::'.print_r($f_response,1).':::::::::ORDERID:::::::::'.$order_id);
			}
			 catch (Exception $e)
			{
					error_log("::::ORDERID:::::.'.$order_id:::Web Exception Raised...".$e->getMessage().':::Error code: ' . $e->getCode().':::Error Line:' . $e->getLine());
			}
			 if(!$f_response){
				 error_log('::::::::::::::::NO RESPONSE RECEIEVED DUE TO EXECEPTION:::::::::ORDERID:::::::::'.$order_id);
			 }
			 else{

					error_log('::::::::::::::::After Api Call Encrypted data receieved successfully:::::::::ORDERID:::::::::'.$order_id);

					$jsonData = json_decode($f_response,true);

			if($jsonData){
			error_log(':::::::::::::::Decrypted data successfully::::::::ORDERID::::::::::'.$order_id);
			}

			 $f_url = $page_url . encodeString($jsonData['Data']['SESSION_ID']);

			 if(!$f_url){
				error_log('::::::::::::::::Redirection Stopped ::::::::::ORDERID::::::::'.$order_id);
				return false;
			}

			error_log('::::::::::::::::Redirecting to URL with Session ID::::::::::ORDERID::::::::'.$f_url);

			if(!isset($jsonData['Data']['SESSION_ID']))
			{
				error_log('::::::::::::::::Redirection not successfull-- NO Session ID::::::::::ORDERID::::::::'.$order_id);
				return false;
			}

		 error_log('::::::::::::::::process_payment ENDED WITH SUCCESS URL'. $f_url .'::::::::ORDERID::::::::'.$order_id);
		 return array(
			 'result'   => 'success',
			 'redirect' => $f_url
		 );
		}
	}

		//************Request object of AES**********************
		public function getRequestObjectAES($order, $order_id)
		{
			try{
				error_log('::::::::::::::::GET REQUEST OBJECT STARTED::::::::::::::::::ORDERID::::'.$order_id );
			global $wp_version;
			$php_version = phpversion();

			$shippingPackageName = '';
			$shippingPackageCost = '0';
			foreach( $order->get_items( 'shipping' ) as $item_id => $shipping_item_obj )
			{
			  $shippingPackageName = $shipping_item_obj->get_name();
			  $shippingPackageCost = strval($shipping_item_obj->get_total());
			}

			$shippingName = 'none';
			$ddays = 0;
			$shippingcost = $shippingPackageCost;
			if(strlen($shippingPackageName) > 0)
			{
				$shippingName = $shippingPackageName;
			}
			else
			{
				$shippingName = 'none';
			}

			$orderTotalDiscount = 0;
			$DISCOUNT_ON_TOTAL = $orderTotalDiscount;
			$SUBTOTAL = $order->get_total();

			$bill_to_forename = $order->get_billing_first_name();
			$BillToSurName = $order->get_billing_last_name();
			$bill_to_email =  $order->get_billing_email();
			$BillToPhone = $order->get_billing_phone();
			$bill_to_address_line1 = $order->get_billing_address_1();
			if($order->get_billing_address_2() != '')
			{
				$bill_to_address_line1 .= $bill_to_address_line1.' '.$order->get_billing_address_2();
			}

			$BillToCity = $order->get_billing_city();
			$bill_to_address_state = $order->get_billing_state();
			$BillToCountry = $order->get_billing_country();
			$billing_postcode=sanitize_text_field($order->get_billing_postcode());

			if(isset($billing_postcode)){
				$bill_to_address_postal_code = $billing_postcode;
			}
			else {
				$bill_to_address_postal_code = '';
			}

				$ShipToForeName = $order->get_shipping_first_name();
				$ShipToSurName = $order->get_shipping_last_name();
				$ShipToEmail = $order->get_billing_email();
				$ShipToPhone = $order->get_billing_phone();
				$ShipToAddressLine1 = $order->get_shipping_address_1();
				if($order->get_shipping_address_2() != '')
				{
					$ShipToAddressLine1 .= $ShipToAddressLine1.' '.$order->get_shipping_address_2();
				}

				$ShipToCity = $order->get_shipping_city();
				$ShipToState = $order->get_shipping_state();
				$ShipToCountry = $order->get_shipping_country();
				$ShipToPostalCode = $order->get_shipping_postcode();
				$admin_settings = WC()->payment_gateways->payment_gateways()['hblpay']->settings;

				$USER_ID = $admin_settings['client_name'];
				$PASSWORD = $admin_settings['password'];
				$CHANNEL = $admin_settings['channel'];
				$RETURN_URL = home_url() . '/wp-json/hblpay_response/v1/checkout';
				$CANCEL_URL = home_url() . '/wp-json/hblpay_response/v1/checkout';
				$Default_Payment_Gateway = $admin_settings['selected_method'];

				/////////-------Section MDD3 and MDD4 Start-------/////////
				global $woocommerce;
				$cart_items = $woocommerce->cart->get_cart();
				$cb_items = array();

				 if(!empty($cart_items))
				 {
					 if(count($cart_items) == 1)
					 {
						 $x = 0;
						 foreach($cart_items  as $values)
						 {
							 if($values[ 'product_id' ])
							 {
								 $product = new WC_Product( $values['product_id']);
								 $products_cats = $product->get_category_ids();
							 }
							 else
								 {
									 $product = new WC_Product( $values['variation_id']);
									 $products_cats = $product->get_category_ids();
								 }

								 $_product = $values['data']->post;

								 $cb_items[ 'merchant_defined_data4' ] = cyber_clean($_product->post_title);

								if( is_array($products_cats) && ! empty($products_cats))
								{
									$c = array();
									foreach ($products_cats as $value)
									{
										if( $term = get_term_by( 'id', $value, 'product_cat' ) )
										{
											$c[] = $term->name;
										}
									}

									$products_cats = implode( ',', $c );
								}
								else
								{
									$products_cats = '';
								}

								$cb_items['merchant_defined_data3'] = cyber_clean($products_cats);

								 $x++;
							 }
						 }
						 else
						 {
							$x = 0;
							foreach( $cart_items  as $values )
							{

								if( $values[ 'product_id' ] )
								{
									$product = new WC_Product( $values['product_id']);
									$products_cats = $product->get_category_ids();
								}
								else
								{
										$product = new WC_Product( $values['variation_id']);
										$products_cats = $product->get_category_ids();
									}

									$_product = $values['data']->post;

									$mdd_product['name'][] = $_product->post_title;

									if( ! empty($products_cats) && is_array($products_cats))
									{
										foreach ( $products_cats as $key => $value)
										{
											$mdd_product[ 'cats' ][]  = $value;
										}
									}
									else
									{
										$mdd_product[ 'cats' ]  = $products_cats;
									}

									$x++;
								}

								if(!empty($mdd_product[ 'name' ]) && is_array($mdd_product[ 'name' ]))
								{
									$cb_items['merchant_defined_data4'] = cyber_clean(implode( ',', $mdd_product[ 'name' ]));
								}

								if( ! empty($mdd_product[ 'cats' ]) && is_array($mdd_product[ 'cats' ] ))
								{
									$c = array();

									foreach ($mdd_product[ 'cats' ] as  $value)
									{
										if( $term = get_term_by( 'id', $value, 'product_cat' ) )
										{
											$c[] = $term->name;
										}
									}
									$cb_items[ 'merchant_defined_data3' ] = cyber_clean(implode( ',', $c));
								}

							 }

						 }
						 $consumer_id=0;
						 	/////////-------Section MDD3 and MDD4 END-------/////////
						$previous_customer = ( $consumer_id == 0 ? 'NO' : 'YES' );
						$mdd1 = 'WC';
						$mdd2 = 'YES';
						$mdd3 = $cb_items[ 'merchant_defined_data3' ];
						$mdd4 = $cb_items[ 'merchant_defined_data4' ];
						$mdd5 = '';
						if($previous_customer)
						{
							$mdd5 = $consumer_id;
						}
						$mdd6 = $shippingPackageName;
						$mdd7 = $woocommerce->cart->get_cart_contents_count();
						$mdd8 = $woocommerce->customer->get_billing_country();
						$mdd20 = __('NO', 'woocommerce');
						$mddallowedlength = 100;
						$currency_code = $order->get_currency();
						$customer_id = $order->get_user_id();
						$enc_key = $admin_settings['gateway_public_key'];
						$enc_key = str_replace("-----BEGIN PUBLIC KEY-----","-----BEGIN PUBLIC KEY-----\n",$enc_key);
						$enc_key = str_replace("-----END PUBLIC KEY-----","\n-----END PUBLIC KEY-----",$enc_key);

	if($ShipToForeName == '' && $ShipToSurName == '' &&  $ShipToAddressLine1 == '' && $ShipToCity == ''
		&& $ShipToState == '' && $ShipToCountry == '' && $ShipToPostalCode == '' ) {

		$custom_data = array (//all fields will be plain text
			'CHANNEL' => $CHANNEL,
			'RETURN_URL' => $RETURN_URL,
			'CANCEL_URL' => $CANCEL_URL,
			'TYPE_ID' => $Default_Payment_Gateway,
			'SHIPPING_DETAIL' => array (
				'NAME' => $shippingName,
				'DELIEVERY_DAYS' => $ddays,//modify if shipping days applicable
				'SHIPPING_COST' => $shippingcost
			),
			'ORDER' => array (
				'DISCOUNT_ON_TOTAL' => $DISCOUNT_ON_TOTAL,
				'SUBTOTAL' => $SUBTOTAL
			),
			'ADDITIONAL_DATA' => array (
				'BILL_TO_FORENAME' => $bill_to_forename,
				'BILL_TO_SURNAME' => $BillToSurName,
				'BILL_TO_EMAIL' => $bill_to_email,
				'BILL_TO_PHONE' => $BillToPhone,
				'BILL_TO_ADDRESS_LINE' => TrimString(clean_special_chars($bill_to_address_line1),50),
				'BILL_TO_ADDRESS_CITY' => TrimString(clean_special_chars($BillToCity),50),
				'BILL_TO_ADDRESS_STATE' => $bill_to_address_state,
				'BILL_TO_ADDRESS_COUNTRY' => $BillToCountry,
				'BILL_TO_ADDRESS_POSTAL_CODE' => $bill_to_address_postal_code,
				'CURRENCY' => $currency_code,
				'REFERENCE_NUMBER' => $order_id,
				'CUSTOMER_ID' => $customer_id,
				'MerchantFields' => array (
					'MDD1' => TrimString($mdd1,$mddallowedlength),
					'MDD2' => TrimString($mdd2,$mddallowedlength),
					'MDD3' => TrimString($mdd3,$mddallowedlength),
					'MDD4' => TrimString($mdd4,$mddallowedlength),
					'MDD5' => TrimString($mdd5,$mddallowedlength),
					'MDD6' => TrimString($mdd6,$mddallowedlength),
					'MDD7' => TrimString($mdd7,$mddallowedlength),
					'MDD8' => TrimString($mdd8,$mddallowedlength),
					'MDD20' => TrimString($mdd20,$mddallowedlength)
				)
			)
		 );
}
	else{

	$custom_data = array (
				'CHANNEL' => $CHANNEL,
				'RETURN_URL' => $RETURN_URL,
				'CANCEL_URL' => $CANCEL_URL,
				'TYPE_ID' => $Default_Payment_Gateway,
				'SHIPPING_DETAIL' => array (
					'NAME' => $shippingName,
					'DELIEVERY_DAYS' => $ddays,//modify if shipping days applicable
					'SHIPPING_COST' => $shippingcost
				),
				'ORDER' => array (
					'DISCOUNT_ON_TOTAL' => $DISCOUNT_ON_TOTAL,
					'SUBTOTAL' => $SUBTOTAL
				),
				'ADDITIONAL_DATA' => array (
					'BILL_TO_FORENAME' => $bill_to_forename,
					'BILL_TO_SURNAME' => $BillToSurName,
					'BILL_TO_EMAIL' => $bill_to_email,
					'BILL_TO_PHONE' => $BillToPhone,
					'BILL_TO_ADDRESS_LINE' => TrimString(clean_special_chars($bill_to_address_line1),50),
					'BILL_TO_ADDRESS_CITY' => TrimString(clean_special_chars($BillToCity),50),
					'BILL_TO_ADDRESS_STATE' => $bill_to_address_state,
					'BILL_TO_ADDRESS_COUNTRY' => $BillToCountry,
					'BILL_TO_ADDRESS_POSTAL_CODE' => $bill_to_address_postal_code,

					'SHIP_TO_FORENAME' => $ShipToForeName,
					'SHIP_TO_SURNAME' => $ShipToSurName,
					'SHIP_TO_PHONE' => $ShipToPhone,
					'SHIP_TO_ADDRESS_LINE' => TrimString(clean_special_chars($ShipToAddressLine1),50),
					'SHIP_TO_ADDRESS_CITY' => TrimString(clean_special_chars($ShipToCity),29),
					'SHIP_TO_ADDRESS_STATE' => $ShipToState,
					'SHIP_TO_ADDRESS_COUNTRY' => $ShipToCountry,
					'SHIP_TO_ADDRESS_POSTAL_CODE' => $ShipToPostalCode,

					'CURRENCY' => $currency_code,
					'REFERENCE_NUMBER' => $order_id,
					// 'PAYMENT_TYPE'=> $payment_type,
					'CUSTOMER_ID' => $customer_id,
					'MerchantFields' => array (
						'MDD1' => TrimString($mdd1,$mddallowedlength),
						'MDD2' => TrimString($mdd2,$mddallowedlength),
						'MDD3' => TrimString($mdd3,$mddallowedlength),
						'MDD4' => TrimString($mdd4,$mddallowedlength),
						'MDD5' => TrimString($mdd5,$mddallowedlength),
						'MDD6' => TrimString($mdd6,$mddallowedlength),
						'MDD7' => TrimString($mdd7,$mddallowedlength),
						'MDD8' => TrimString($mdd8,$mddallowedlength),
						'MDD20' => TrimString($mdd20,$mddallowedlength)
					)
				)
			 );

	}
		$custom_data['ORDER'] =  array (
				'DISCOUNT_ON_TOTAL' => $DISCOUNT_ON_TOTAL,
				'SUBTOTAL' => $SUBTOTAL,
				'OrderSummaryDescription' => array()
			);

		$OrderSummaryDescription = array();

		$parent_category = 'Uncategorized';
		$child_category = 'Uncategorized';

		$orderTotalDiscount = 0;
		foreach ($order->get_items() as $item_id => $item)
		{
			$product = $item->get_product();

			$terms = get_the_terms ( $product->get_id(), 'product_cat' );

			foreach ( $terms as $term )
			{
				if($term->parent == 0)
				{
					$parent_category = $term->name;
				}
				else if($term->parent != 0)
				{
					$child_category = $term->name;
				}

				if(count($terms) == 1)
				{
					$child_category = $parent_category;
				}
			}
			$saving_price=0;
			$regular_price = (float) $product->get_regular_price();
			$sale_price = (float) $product->get_price();
			$saving_price = $regular_price - $sale_price;

			$product_quantity = $item->get_quantity();
			$orderTotalDiscount += (string)((int)$saving_price * (int)$product_quantity);

			$product_name = $item->get_name();
			$product_total_price = $item['subtotal'];
			$product_unit_price = (string)((int)$product_total_price / (int)$product_quantity);

			if( $product->is_on_sale() ) {
					$old_price = $product->get_regular_price();
					}
				else{
				$old_price = '';
				}

			$OrderSummaryDescription[] = [
					'ITEM_NAME' => TrimString(RemoveBlackListCharacters($product_name),100),
					'QUANTITY' => intval($product_quantity),
					'UNIT_PRICE' => $product_unit_price,
					'OLD_PRICE' => $old_price,
					'CATEGORY' => TrimString(RemoveBlackListCharacters($parent_category),100),
					'SUB_CATEGORY' => TrimString(RemoveBlackListCharacters($child_category),100)
					];
		}

		$custom_data['ORDER']['OrderSummaryDescription'] = $OrderSummaryDescription;
			//-----------------------------AES encryption-------------------------------//
				//convert custom_data into json so that it can be encrypted
				$json_custom_data=json_encode($custom_data);
				//method for encryption
				define('AES_256_CBC', 'aes-256-cbc');
				// Generate a 256-bit AES encryption key
				$secret_key = bin2hex(openssl_random_pseudo_bytes(16));
				// Generate an initialization vector
				$ivlength = openssl_cipher_iv_length(AES_256_CBC);
				$iv = bin2hex(openssl_random_pseudo_bytes($ivlength / 2));
				$sk_iv=$secret_key."||".$iv;
				//using function to encrypt object
				$encrypted_obj=encrypt_data_aes($json_custom_data,$secret_key,$iv);
				error_log("ENCRYPTED OBJECT...".$encrypted_obj);
			//-------------------------end of encryption--------------------------------//
				$main_data = array (
				'Data1' => $USER_ID,//plain text
				'Data2' => encryptData($PASSWORD,$enc_key),//password RSA protected
				'Data3' => encryptData($sk_iv,$enc_key),//secret key and iv RSA protected
				'Data4' => $encrypted_obj //custom_data object AES protected,
	);

	error_log('::::::::::::::::getRequestObject ENDED::::::::ORDERID:::::.'.$order_id.'.:::::'.json_encode($main_data,JSON_PRETTY_PRINT));
	error_log('::::::::::::::::Billing First Name::::::::::::::::::'.$bill_to_forename.'::::ORDERID:::::.'.$order_id);
	error_log('::::::::::::::::Billing Last Name::::::::::::::::::'.$BillToSurName.'::::ORDERID:::::.'.$order_id);
				return $main_data;
			}
			catch (Exception $e)
				{
						error_log("::::ORDERID:::::.'.$order_id:::Web Exception Raised...".$e->getMessage().':::Error code: ' . $e->getCode().':::Error Line:' . $e->getLine());
				}
		}
 	}

	function callAPI($method, $url, $data,$order_id)
	{
		error_log('::::::::::::::::CALL API STARTED::::::::::ORDERID::::::::'.$order_id);
		 $admin_settings = WC()->payment_gateways->payment_gateways()['hblpay']->settings;
			 $curl = curl_init();
		 switch ($method)
		 {
			 case "POST":
				 curl_setopt($curl, CURLOPT_POST, 1);
				 if ($data)
					 curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
				 break;
			 case "PUT":
				 curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "PUT");
				 if ($data)
				 curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
				 break;
			 default:
				 if ($data)
					 $url = sprintf("%s?%s", $url, http_build_query($data));
		 }

		 curl_setopt($curl, CURLOPT_URL, $url);
		 curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
		 curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
		 curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
		 curl_setopt($curl, CURLOPT_FAILONERROR, true);
		 //PROTOCOL_ERROR
		 curl_setopt($curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
		 error_log('URL : '.$url);

		 if($admin_settings['is_live'] === 'yes')
		 {
			 curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
			 curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
		 }

		 if($admin_settings['use_proxy'] === 'yes')
		 {
			 $proxy = $admin_settings['proxy'];
			 curl_setopt($curl, CURLOPT_PROXY, $proxy);
		 }

		 $result = curl_exec($curl);
		 if (curl_errno($curl)) {

			 $error_msg = curl_error($curl);

			 }

			 if (isset($error_msg)) {
				 error_log('::::::::::Web Exception Raised:::::'.$error_msg.':::ORDERID::::'.$order_id);
			 }

		 curl_close($curl);
		 error_log('::::::::::::::::closing CURL connection::::::::ORDERID::::'.$order_id);

		 error_log('::::::::::::::::callAPI ENDED::::::::::::::::::ORDERID::::'.$order_id);
		 return $result;
	}

	function encryptData($plainData, $publicPEMKey)
	{
		//using instead of utf8_encode
		$plainData=iconv('UTF-8', 'ISO-8859-1', $plainData ?? '');
		$partialEncrypted = '';
		$encryptionOk = openssl_public_encrypt($plainData, $partialEncrypted, $publicPEMKey,OPENSSL_PKCS1_PADDING);
		if(!$encryptionOk){
			throw new Exception("Something went wrong with Encryption");
		}
		return base64_encode($partialEncrypted);
	}

	function encodeString($plainData)
	{
			//using instead of utf8_encode
		$plainData=mb_convert_encoding($plainData, 'UTF-8', mb_list_encodings());
		return base64_encode($plainData);
	}


	function decodeString($encodedData)
	{
		$encodedData = base64_decode($encodedData);
			//using instead of utf8_decode
		return mb_convert_encoding($encodedData, 'ISO-8859-1', 'UTF-8');
	}

	function decryptData($data, $privatePEMKey)
	{
		$DECRYPT_BLOCK_SIZE = 512;
		$decrypted = '';

		$data = str_split(base64_decode($data), $DECRYPT_BLOCK_SIZE);
		foreach($data as $chunk)
		{
			$partial = '';

			$decryptionOK = openssl_private_decrypt($chunk, $partial, $privatePEMKey, OPENSSL_PKCS1_PADDING);

			if($decryptionOK === false)
			{
				$decrypted = '';
				return $decrypted;
			}
			$decrypted .= $partial;
		}

		return mb_convert_encoding($decrypted, 'ISO-8859-1', 'UTF-8');
	}

	function cyber_clean($string)
	{
		$string = str_replace(' ', '-', $string); // Replaces all spaces with hyphens.
		$string = preg_replace('/[^A-Za-z0-9\-]/', '', $string); // Removes special chars.
		return preg_replace('/-+/', '-', $string); // Replaces multiple hyphens with single one.
	}

	function clean_special_chars($string)
	{
		$string = preg_replace('/[^A-Za-z0-9\-]/', ' ', $string);
		return preg_replace('/-+/', '-', $string);
	}

	function TrimString($value,$length)
	{
		$value = substr($value ?? '', 0, $length);
		return $value;
	}


	function RemoveBlackListCharacters($str)
	{
		$chr = array("/","sleep","wait","insert","update","delete","$","~","`","'","truncate","drop","alter","modify","--");
		$res = str_replace($chr,"",$str);
		return $res;
	}
		//-----------------function to encrypt data using AES-256-CBC------------------------------//
		function encrypt_data_aes($data,$secret_key,$iv){

				// Encrypt $data using aes-256-cbc cipher with the given encryption key and
				// our initialization vector. The 0 gives us the default options, but can
				// be changed to OPENSSL_RAW_DATA or OPENSSL_ZERO_PADDING
				$encrypted_aes = openssl_encrypt($data, AES_256_CBC, $secret_key, 0, $iv);
				return $encrypted_aes;
				}
		//--------------------end of function ---------------------------------------//
		function wc_order_add_discount_campaign( $order_id,$discounted_amount,$campaign_id) {
					$order = wc_get_order($order_id);
					$total = $order->get_total();
					$discount = $total - $discounted_amount;
			if($discounted_amount != null && $discounted_amount > 0){
				if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
					$order = wc_get_order( $order_id );
					$order->update_meta_data( '_discount', sanitize_text_field( $discount ));
					$order->update_meta_data( '_campaign_id', sanitize_text_field( $campaign_id));
					$order->set_total($discounted_amount);
					$order->save();

	} else {
			global $wpdb;
				update_post_meta( $order_id, '_discount', sanitize_text_field( $discount));
				update_post_meta( $order_id, '_campaign_id', sanitize_text_field( $campaign_id));
				 	$wpdb->update("wp_postmeta", array(
				 	"meta_value" => $discounted_amount
				 ),
				 array("post_id" => $order_id ,"meta_key" => '_order_total')
			  );
			}
		}
				return true;
		}
}
 ?>
