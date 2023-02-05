<?php
/**
 * Revolut Payment Request
 *
 * Provides a gateway to accept payments through Apple and Google Pay.
 *
 * @package WooCommerce
 * @category Payment Gateways
 * @author Revolut
 * @since 3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC_Gateway_Revolut_Payment_Request class.
 */
class WC_Gateway_Revolut_Payment_Request extends WC_Payment_Gateway_Revolut {


	/**
	 * Constructor
	 */
	public function __construct() {
		$this->id = 'revolut_payment_request';

		$this->method_title = __( 'Apple Pay / Google Pay', 'revolut-gateway-for-woocommerce' );
		/* translators:%1s: %$2s: */
		$this->method_description = sprintf( __( 'Accept Apple and Google payments easily and securely via %1$sRevolut%2$s.', 'revolut-gateway-for-woocommerce' ), '<a href="https://www.revolut.com/business/online-payments">', '</a>' );
		$this->tab_title          = __( 'Apple Pay / Google Pay', 'revolut-gateway-for-woocommerce' );

		$this->title = __( 'Digital Wallet (ApplePay/GooglePay)', 'revolut-gateway-for-woocommerce' );

		parent::__construct();

		add_action( 'wc_ajax_revolut_payment_request_add_to_cart', array( $this, 'revolut_payment_request_ajax_add_to_cart' ) );
		add_action( 'wc_ajax_revolut_payment_request_get_shipping_options', array( $this, 'revolut_payment_request_ajax_get_shipping_options' ) );
		add_action( 'wc_ajax_revolut_payment_request_update_shipping_method', array( $this, 'revolut_payment_request_ajax_update_shipping_method' ) );
		add_action( 'wc_ajax_revolut_payment_request_update_payment_total', array( $this, 'revolut_payment_request_update_revolut_order_with_cart_total' ) );
		add_action( 'wc_ajax_revolut_payment_request_create_order', array( $this, 'revolut_payment_request_ajax_create_order' ) );
		add_action( 'wc_ajax_revolut_payment_request_get_payment_request_params', array( $this, 'revolut_payment_request_ajax_get_payment_request_params' ) );

		if ( 'yes' !== $this->enabled || $this->api_settings->get_option( 'mode' ) === 'sandbox' ) {
			return;
		}

		add_action( 'woocommerce_after_add_to_cart_quantity', array( $this, 'display_payment_request_button_html' ), 2 );
		add_action( 'woocommerce_proceed_to_checkout', array( $this, 'display_payment_request_button_html' ), 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'revolut_enqueue_payment_request_scripts' ) );
	}

	/**
	 * Get express checkout params
	 */
	public function revolut_payment_request_ajax_get_payment_request_params() {
		check_ajax_referer( 'wc-revolut-get-payment-request-params', 'security' );

		try {
			wp_send_json(
				array(
					'success'           => true,
					'revolut_public_id' => $this->create_express_checkout_public_id(),
					'checkout_nonce'    => wp_create_nonce( 'woocommerce-process_checkout' ),
				)
			);
		} catch ( Exception $e ) {
			wp_send_json( array( 'success' => false ) );
			$this->log_error( $e );
		}
	}

	/**
	 * Add required scripts
	 */
	public function revolut_enqueue_payment_request_scripts() {
		try {
			if ( ! $this->page_supports_payment_request_button( $this->get_option( 'payment_request_button_locations' ) ) ) {
				return false;
			}

			wp_register_script( 'revolut-core', $this->api_client->base_url . '/embed.js', false, WC_GATEWAY_REVOLUT_VERSION, true );
			wp_register_script(
				'revolut-woocommerce-payment-request',
				plugins_url( 'assets/js/revolut-payment-request.js', WC_REVOLUT_MAIN_FILE ),
				array(
					'revolut-core',
					'jquery',
				),
				WC_GATEWAY_REVOLUT_VERSION,
				true
			);

			wp_localize_script(
				'revolut-woocommerce-payment-request',
				'wc_revolut_payment_request_params',
				$this->get_wc_revolut_payment_request_params()
			);

			wp_enqueue_script( 'revolut-woocommerce-payment-request' );
		} catch ( Exception $e ) {
			$this->log_error( $e->getMessage() );
		}
	}

	/**
	 * Check if the Revolut Pay Fast checkout payments active.
	 */
	public function is_revolut_pay_fast_checkout_active() {
		$revolut_cc_gateway_options = get_option( 'woocommerce_revolut_pay_settings' );
		return isset( $revolut_cc_gateway_options['revolut_pay_button_locations'] ) && $this->page_supports_payment_request_button( $revolut_cc_gateway_options['revolut_pay_button_locations'] ) && $this->is_shipping_required();
	}

	/**
	 * Update Revolut order with cart total amount
	 *
	 * @param string $revolut_public_id Revolut public id.
	 *
	 * @param bool   $is_revolut_pay indicator.
	 *
	 * @throws Exception Exception.
	 */
	public function update_revolut_order_with_cart_total( $revolut_public_id, $is_revolut_pay = false ) {
		$revolut_order_id             = $this->get_revolut_order_by_public_id( $revolut_public_id );
		$revolut_order                = $this->api_client->get( "/orders/$revolut_order_id" );
		$revolut_order_shipping_total = $this->get_revolut_order_total_shipping( $revolut_order );

		$cart_subtotal = round( (float) ( WC()->cart->get_subtotal() + WC()->cart->get_subtotal_tax() + $revolut_order_shipping_total ), 2 );

		$this->log_info(
			array(
				'is_revolut_pay'              => $is_revolut_pay,
				'cart_total_without_shipping' => $cart_subtotal,
				'cart_total'                  => WC()->cart->get_total( '' ),
				'wc_shipping_total'           => WC()->cart->get_shipping_total(),
				'revolut_shipping_total'      => $revolut_order_shipping_total,
			)
		);

		$descriptor = new WC_Revolut_Order_Descriptor( $is_revolut_pay ? $cart_subtotal : WC()->cart->get_total( '' ), get_woocommerce_currency(), null );
		$public_id  = $this->update_revolut_order( $descriptor, $revolut_public_id, true );
		if ( $public_id !== $revolut_public_id ) {
			throw new Exception( 'Can not update the Order' );
		}
		$revolut_order_id = $this->get_revolut_order_by_public_id( $public_id );
		$revolut_order    = $this->api_client->get( "/orders/$revolut_order_id" );
		$this->log_info( 'revolut_order' );
		$this->log_info( $revolut_order );

		return $this->get_revolut_order_amount( $revolut_order );
	}

	/**
	 * Check is payment method available
	 */
	public function is_available() {
		if ( ( 'yes' === $this->enabled && is_product() ) || ( $this->check_is_post_data_submited( 'payment_method' ) && $this->get_post_request_data( 'payment_method' ) === $this->id ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Initialise Gateway Settings Form Fields
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'                          => array(
				'title'       => __( 'Enable/Disable', 'revolut-gateway-for-woocommerce' ),
				'label'       => sprintf(
				/* translators:%1s: %$2s: %3$s: %4$s: */
					__( 'Enable Payment Request Buttons. (Apple Pay/Google Pay) %1$sBy using Apple Pay, you agree with %2$sApple\'s%3$s terms of service. (Apple Pay domain verification is performed automatically in live mode.) %4$s', 'revolut-gateway-for-woocommerce' ),
					'<br />',
					'<a href="https://www.revolut.com/legal/payment-terms-applepay" target="_blank">',
					'</a>',
					$this->check_authentication_required() ? '<br /> <p style="color:red">' . __( 'Payment with Apple/Google Pay buttons wont be possible for guest users until Guest checkout is enabled', 'revolut-gateway-for-woocommerce' ) . '</p>' : ''
				),
				'type'        => 'checkbox',
				'description' => __( 'If enabled, users will be able to pay using Apple Pay (Safari & iOS), Google Pay (Chrome & Android) or native W3C Payment Requests if supported by the browser.', 'revolut-gateway-for-woocommerce' ),
				'default'     => 'yes',
				'desc_tip'    => true,
			),
			'payment_request_button_type'      => array(
				'title'       => __( 'Payment Request Button Action', 'revolut-gateway-for-woocommerce' ),
				'label'       => __( 'Button Action', 'revolut-gateway-for-woocommerce' ),
				'type'        => 'select',
				'description' => __( 'Select the button type you would like to show.', 'revolut-gateway-for-woocommerce' ),
				'default'     => 'buy',
				'desc_tip'    => true,
				'options'     => array(
					'buy'    => __( 'Buy', 'revolut-gateway-for-woocommerce' ),
					'donate' => __( 'Donate', 'revolut-gateway-for-woocommerce' ),
					'pay'    => __( 'Pay', 'revolut-gateway-for-woocommerce' ),
				),
			),
			'payment_request_button_theme'     => array(
				'title'       => __( 'Payment Request Button Theme', 'revolut-gateway-for-woocommerce' ),
				'label'       => __( 'Button Theme', 'revolut-gateway-for-woocommerce' ),
				'type'        => 'select',
				'description' => __( 'Select the button theme you would like to show.', 'revolut-gateway-for-woocommerce' ),
				'default'     => 'dark',
				'desc_tip'    => true,
				'options'     => array(
					'dark'           => __( 'Dark', 'revolut-gateway-for-woocommerce' ),
					'light'          => __( 'Light', 'revolut-gateway-for-woocommerce' ),
					'light-outlined' => __( 'Light-Outline', 'revolut-gateway-for-woocommerce' ),
				),
			),
			'payment_request_button_radius'    => array(
				'title'       => __( 'Payment Request Button Radius', 'revolut-gateway-for-woocommerce' ),
				'label'       => __( 'Button Radius', 'revolut-gateway-for-woocommerce' ),
				'type'        => 'select',
				'description' => __( 'Select the button radius you would like to show.', 'revolut-gateway-for-woocommerce' ),
				'default'     => 'none',
				'desc_tip'    => true,
				'options'     => array(
					'none'  => __( 'None', 'revolut-gateway-for-woocommerce' ),
					'small' => __( 'Small', 'revolut-gateway-for-woocommerce' ),
					'large' => __( 'Large', 'revolut-gateway-for-woocommerce' ),
				),
			),
			'payment_request_button_size'      => array(
				'title'       => __( 'Payment Request Button Size', 'revolut-gateway-for-woocommerce' ),
				'label'       => __( 'Button Size', 'revolut-gateway-for-woocommerce' ),
				'type'        => 'select',
				'description' => __( 'Select the button size you would like to show.', 'revolut-gateway-for-woocommerce' ),
				'default'     => 'large',
				'desc_tip'    => true,
				'options'     => array(
					'small' => __( 'Small', 'revolut-gateway-for-woocommerce' ),
					'large' => __( 'Large', 'revolut-gateway-for-woocommerce' ),
				),
			),
			'payment_request_button_locations' => array(
				'title'             => __( 'Payment Request Button Locations', 'revolut-gateway-for-woocommerce' ),
				'type'              => 'multiselect',
				'description'       => __( 'Select where you would like Payment Request Buttons to be displayed', 'revolut-gateway-for-woocommerce' ),
				'desc_tip'          => true,
				'class'             => 'wc-enhanced-select',
				'options'           => array(
					'product' => __( 'Product', 'revolut-gateway-for-woocommerce' ),
					'cart'    => __( 'Cart', 'revolut-gateway-for-woocommerce' ),
				),
				'default'           => array( 'product', 'cart' ),
				'custom_attributes' => array(
					'data-placeholder' => __( 'Select pages', 'revolut-gateway-for-woocommerce' ),
				),
			),
		);

		if ( $this->get_option( 'apple_pay_merchant_onboarded' ) === 'no' ) {
			$this->form_fields['onboard_applepay'] = array(
				'title'       => __( 'Onboard shop domain for Apple Pay', 'revolut-gateway-for-woocommerce' ),
				'type'        => 'text',
				'description' => '<button class="setup-applepay-domain" style="min-height: 30px;">Setup</button>
                                    <p class="setup-applepay-domain-error" style="color:red;display: none"></p>
            					<p>Seems that there is a problem automatically onboarding your website for Apple Pay. You can also set this up manually by downloading this <a href="https://assets.revolut.com/api-docs/merchant-api/files/domain_validation_file_prod">file</a> and adding it to the root folder of your shop with the following uri <code>/.well-known/apple-developer-merchantid-domain-association</code>. To learn more about how to do this, visit our <a href="https://developer.revolut.com/docs/accept-payments/plugins/woocommerce/features#set-up-apple-pay-manually" target="_blank">documentation</a></p>',
			);
		}
	}

	/**
	 * Supported functionality
	 */
	public function init_supports() {
		parent::init_supports();
		$this->supports[] = 'refunds';
	}

	/**
	 * Display payment request button html
	 */
	public function display_payment_request_button_html() {
		if ( ! $this->page_supports_payment_request_button( $this->get_option( 'payment_request_button_locations' ) ) ) {
			return false;
		}

		if ( $this->is_revolut_pay_fast_checkout_active() ) {
			?>
			<div class="wc-revolut-payment-request-instance" id="wc-revolut-payment-request-container" style="clear:both;">
			<div id="revolut-payment-request-button"></div>
			<p id="wc-revolut-payment-request-button-separator" style="margin-top:1.5em;text-align:center;">&mdash;&nbsp;<?php echo esc_html( __( 'OR', 'revolut-gateway-for-woocommerce' ) ); ?>
				&nbsp;&mdash;</p>
		</div>
			<?php
			return;
		}

		?>
		<div class="wc-revolut-payment-request-instance" id="wc-revolut-payment-request-container" style="clear:both;padding-top:1.5em;">
			<div id="revolut-payment-request-button"></div>
			<p id="wc-revolut-payment-request-button-separator" style="margin-top:1.5em;text-align:center;">&mdash;&nbsp;<?php echo esc_html( __( 'OR', 'revolut-gateway-for-woocommerce' ) ); ?>
				&nbsp;&mdash;</p>
		</div>
		<?php
	}

	/**
	 * Ajax endpoint in order to create WooCommerce order
	 */
	public function revolut_payment_request_ajax_create_order() {

		if ( WC()->cart->is_empty() ) {
			wp_send_json_error( __( 'Empty cart', 'revolut-gateway-for-woocommerce' ) );
		}

		if ( ! defined( 'WOOCOMMERCE_CHECKOUT' ) ) {
			define( 'WOOCOMMERCE_CHECKOUT', true );
		}

		$errors = new WP_Error();

		try {
			$wc_order_data = $this->get_wc_order_details();
		} catch ( Exception $e ) {
			$this->log_error( $e->getMessage() );
			$errors->add( 'payment', __( 'Something went wrong', 'woocommerce' ) );
			if ( ! empty( $this->get_post_request_data( 'revolut_payment_error' ) ) ) {
				$errors->add( 'payment', $this->get_post_request_data( 'revolut_payment_error' ) );
				$this->log_error( $this->get_post_request_data( 'revolut_payment_error' ) );
			}
		}

		foreach ( $errors->errors as $code => $messages ) {
			$data = $errors->get_error_data( $code );
			foreach ( $messages as $message ) {
				wc_add_notice( $message, 'error', $data );
			}
		}

		if ( 0 === wc_notice_count( 'error' ) ) {
			$_POST = array_merge( $_POST, $wc_order_data ); // phpcs:ignore
			unset( $_POST['address_info'] ); // phpcs:ignore
			$_POST['_wpnonce'] = wp_create_nonce( 'woocommerce-process_checkout' );
			WC()->checkout()->process_checkout();
		}

		$messages = wc_print_notices( true );

		wp_send_json(
			array(
				'result'   => 'failure',
				'messages' => $messages,
			)
		);
	}

	/**
	 * Get order details
	 *
	 * @throws Exception Exception.
	 */
	public function get_wc_order_details() {
		$public_id = $this->get_post_request_data( 'revolut_public_id' );

		if ( empty( $public_id ) ) {
			throw new Exception( 'Public ID is missing for the session' );
		}

		$order_id = $this->get_revolut_order_by_public_id( $public_id );

		if ( empty( $order_id ) ) {
			throw new Exception( 'Can not find revolut order id' );
		}

		$address_info = $this->get_post_request_data( 'address_info' );

		if ( empty( $address_info ) ) {
			throw new Exception( 'Address information is missing' );
		}

		if ( empty( $address_info['billingAddress'] ) ) {
			throw new Exception( 'Billing address is missing' );
		}

		if ( $this->get_posted_integer_data( 'shipping_required' ) && empty( $address_info['shippingAddress'] ) ) {
			throw new Exception( 'Shipping address is missing' );
		}

		if ( empty( $address_info['email'] ) ) {
			throw new Exception( 'User Email information is missing' );
		}

		$revolut_billing_address         = $address_info['billingAddress'];
		$revolut_customer_email          = $address_info['email'];
		$revolut_customer_full_name      = ! empty( $revolut_billing_address['recipient'] ) ? $revolut_billing_address['recipient'] : '';
		$revolut_customer_billing_phone  = ! empty( $revolut_billing_address['phone'] ) ? $revolut_billing_address['phone'] : '';
		$revolut_customer_shipping_phone = '';
		$wc_shipping_address             = array();

		list($billing_firstname, $billing_lastname) = $this->parse_customer_name( $revolut_customer_full_name );

		if ( isset( $address_info['shippingAddress'] ) && ! empty( $address_info['shippingAddress'] ) ) {
			$revolut_shipping_address            = $address_info['shippingAddress'];
			$revolut_customer_shipping_phone     = ! empty( $revolut_shipping_address['phone'] ) ? $revolut_shipping_address['phone'] : '';
			$revolut_customer_shipping_full_name = ! empty( $revolut_shipping_address['recipient'] ) ? $revolut_shipping_address['recipient'] : '';

			$shipping_firstname = $billing_firstname;
			$shipping_lastname  = $billing_lastname;

			if ( ! empty( $revolut_customer_shipping_full_name ) ) {
				list($shipping_firstname, $shipping_lastname) = $this->parse_customer_name( $revolut_customer_shipping_full_name );
			}

			if ( empty( $revolut_customer_shipping_phone ) && ! empty( $revolut_customer_billing_phone ) ) {
				$revolut_customer_shipping_phone = $revolut_customer_billing_phone;
			}

			$wc_shipping_address = $this->get_wc_shipping_address( $revolut_shipping_address, $revolut_customer_email, $revolut_customer_shipping_phone, $shipping_firstname, $shipping_lastname );
		}

		if ( empty( $revolut_customer_billing_phone ) && ! empty( $revolut_customer_shipping_phone ) ) {
			$revolut_customer_billing_phone = $revolut_customer_shipping_phone;
		}

		$wc_billing_address = $this->get_wc_billing_address( $revolut_billing_address, $revolut_customer_email, $revolut_customer_billing_phone, $billing_firstname, $billing_lastname );

		if ( $this->get_posted_integer_data( 'shipping_required' ) ) {
			$wc_order_data = array_merge( $wc_billing_address, $wc_shipping_address );
		} else {
			$wc_order_data = $wc_billing_address;
		}

		$wc_order_data['ship_to_different_address']     = $this->get_posted_integer_data( 'shipping_required' );
		$wc_order_data['revolut_pay_express_checkkout'] = $this->get_post_request_data( 'revolut_gateway' ) === 'revolut_pay';
		$wc_order_data['terms']                         = 1;
		$wc_order_data['order_comments']                = '';

		return $wc_order_data;
	}

	/**
	 * Create billing address for order.
	 *
	 * @param array  $billing_address Billing address.
	 * @param string $revolut_customer_email Email.
	 * @param string $revolut_customer_phone Phone.
	 * @param string $firstname Firstname.
	 * @param string $lastname Lastname.
	 */
	public function get_wc_billing_address( $billing_address, $revolut_customer_email, $revolut_customer_phone, $firstname, $lastname ) {
		$address                       = array();
		$address['billing_first_name'] = $firstname;
		$address['billing_last_name']  = $lastname;

		$address['billing_email']     = $revolut_customer_email;
		$address['billing_phone']     = $revolut_customer_phone;
		$address['billing_country']   = ! empty( $billing_address['country'] ) ? $billing_address['country'] : '';
		$address['billing_address_1'] = ! empty( $billing_address['addressLine'][0] ) ? $billing_address['addressLine'][0] : '';
		$address['billing_address_2'] = ! empty( $billing_address['addressLine'][1] ) ? $billing_address['addressLine'][1] : '';
		$address['billing_city']      = ! empty( $billing_address['city'] ) ? $billing_address['city'] : '';
		$address['billing_state']     = ! empty( $billing_address['region'] ) ? $this->convert_state_name_to_id( $billing_address['country'], $billing_address['region'] ) : '';
		$address['billing_postcode']  = ! empty( $billing_address['postalCode'] ) ? $billing_address['postalCode'] : '';
		$address['billing_company']   = '';

		return $address;
	}

	/**
	 * Create billing address for order.
	 *
	 * @param array  $shipping_address Shipping address.
	 * @param string $revolut_customer_email Email.
	 * @param string $revolut_customer_phone Phone.
	 * @param string $firstname Firstname.
	 * @param string $lastname Lastname.
	 */
	public function get_wc_shipping_address( $shipping_address, $revolut_customer_email, $revolut_customer_phone, $firstname, $lastname ) {
		$address['shipping_first_name'] = $firstname;
		$address['shipping_last_name']  = $lastname;
		$address['shipping_email']      = $revolut_customer_email;
		$address['shipping_phone']      = $revolut_customer_phone;
		$address['shipping_country']    = ! empty( $shipping_address['country'] ) ? $shipping_address['country'] : '';
		$address['shipping_address_1']  = ! empty( $shipping_address['addressLine'][0] ) ? $shipping_address['addressLine'][0] : '';
		$address['shipping_address_2']  = ! empty( $shipping_address['addressLine'][1] ) ? $shipping_address['addressLine'][1] : '';
		$address['shipping_city']       = ! empty( $shipping_address['city'] ) ? $shipping_address['city'] : '';
		$address['shipping_state']      = ! empty( $shipping_address['region'] ) ? $this->convert_state_name_to_id( $shipping_address['country'], $shipping_address['region'] ) : '';
		$address['shipping_postcode']   = ! empty( $shipping_address['postalCode'] ) ? $shipping_address['postalCode'] : '';
		$address['shipping_company']    = '';

		return $address;
	}

	/**
	 * Get first and lastname from customer full name string.
	 *
	 * @param string $full_name Customer full name.
	 */
	public function parse_customer_name( $full_name ) {
		$full_name_list = explode( ' ', $full_name );
		if ( count( $full_name_list ) > 1 ) {
			$lastname  = array_pop( $full_name_list );
			$firstname = implode( ' ', $full_name_list );
			return array( $firstname, $lastname );
		}

		$firstname = $full_name;
		$lastname  = 'undefined';

		return array( $firstname, $lastname );
	}

	/**
	 * Ajax endpoint for adding product to cart.
	 *
	 * @throws Exception Exception.
	 */
	public function revolut_payment_request_ajax_add_to_cart() {
		try {
			check_ajax_referer( 'wc-revolut-pr-add-to-cart', 'security' );

			$revolut_public_id = $this->get_post_request_data( 'revolut_public_id' );

			if ( empty( $revolut_public_id ) ) {
				throw new Exception( 'Can not get required parameter' );
			}

			if ( ! defined( 'WOOCOMMERCE_CART' ) ) {
				define( 'WOOCOMMERCE_CART', true );
			}

			WC()->shipping->reset_shipping();

			$product_id     = $this->get_posted_integer_data( 'product_id' );
			$is_revolut_pay = $this->get_posted_integer_data( 'is_revolut_pay' );
			$qty            = ! $this->check_is_post_data_submited( 'qty' ) ? 1 : $this->get_posted_integer_data( 'qty' );
			$product        = wc_get_product( $product_id );
			$product_type   = $product->get_type();
			$global_cart    = WC()->cart;

			if ( ! $this->get_posted_integer_data( 'add_to_cart' ) ) {
				WC()->cart = clone WC()->cart;
			}

			WC()->cart->empty_cart();

			if ( 'simple' === $product_type || 'subscription' === $product_type ) {
				WC()->cart->add_to_cart( $product->get_id(), $qty );
			} elseif ( $this->check_is_post_data_submited( 'attributes' ) && ( 'variable' === $product_type || 'variable-subscription' === $product_type ) ) {
				$attributes   = $this->get_post_request_data( 'attributes' );
				$data_store   = WC_Data_Store::load( 'product' );
				$variation_id = $data_store->find_matching_product_variation( $product, $attributes );
				WC()->cart->add_to_cart( $product->get_id(), $qty, $variation_id, $attributes );
			}

			WC()->shipping->reset_shipping();
			WC()->cart->calculate_totals();

			$total_amount  = $this->update_revolut_order_with_cart_total( $revolut_public_id, $is_revolut_pay );
			$is_cart_empty = ! WC()->cart->is_empty();

			if ( ! $this->get_posted_integer_data( 'add_to_cart' ) ) {
				WC()->cart = $global_cart;
			}

			$data['total']['amount'] = $total_amount;
			$data['checkout_nonce']  = wp_create_nonce( 'woocommerce-process_checkout' );
			$data['status']          = 'success';
			$data['success']         = $is_cart_empty;
			wp_send_json( $data );
		} catch ( Exception $e ) {
			$this->log_error( $e );
			$data['status']  = 'fail';
			$data['success'] = false;
			wp_send_json( $data );
		}
	}

	/**
	 * Ajax endpoint for listing shipping options
	 *
	 * @throws Exception Exception.
	 */
	public function revolut_payment_request_ajax_get_shipping_options() {
		check_ajax_referer( 'wc-revolut-payment-request-shipping', 'security' );

		try {

			$revolut_public_id = $this->get_post_request_data( 'revolut_public_id' );

			if ( empty( $revolut_public_id ) ) {
				throw new Exception( 'Can not get required parameter' );
			}

			$shipping_address = filter_input_array(
				INPUT_POST,
				array(
					'country'   => FILTER_SANITIZE_STRING,
					'state'     => FILTER_SANITIZE_STRING,
					'postcode'  => FILTER_SANITIZE_STRING,
					'city'      => FILTER_SANITIZE_STRING,
					'address'   => FILTER_SANITIZE_STRING,
					'address_2' => FILTER_SANITIZE_STRING,
				)
			);

			$shipping_options = $this->get_shipping_options( $shipping_address );

			if ( count( $shipping_options ) > 0 ) {
				$this->update_shipping_method( $shipping_options[0] );
			}

			$total_amount = $this->update_revolut_order_with_cart_total( $revolut_public_id );

			$data['success']         = true;
			$data['status']          = 'success';
			$data['total']['amount'] = $total_amount;
			$data['shippingOptions'] = $shipping_options;

			wp_send_json( $data );
		} catch ( Exception $e ) {
			$this->log_error( $e );
			$data['status']          = 'fail';
			$data['success']         = false;
			$data['total']['amount'] = 0;
			$data['shippingOptions'] = array();
			wp_send_json( $data );
		}
	}

	/**
	 * Ajax endpoint for updating shipping options
	 *
	 * @throws Exception Exception.
	 */
	public function revolut_payment_request_ajax_update_shipping_method() {
		check_ajax_referer( 'wc-revolut-update-shipping-method', 'security' );

		try {
			if ( ! defined( 'WOOCOMMERCE_CART' ) ) {
				define( 'WOOCOMMERCE_CART', true );
			}

			$revolut_public_id = $this->get_post_request_data( 'revolut_public_id' );

			if ( empty( $revolut_public_id ) ) {
				throw new Exception( 'Can not get required parameter' );
			}

			$shipping_method = filter_input( INPUT_POST, 'shipping_method', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY );
			$this->update_shipping_method( $shipping_method );

			WC()->cart->calculate_totals();

			$total_amount = $this->update_revolut_order_with_cart_total( $revolut_public_id );

			$data['success']         = true;
			$data['status']          = 'success';
			$data['total']['amount'] = $total_amount;
			wp_send_json( $data );
		} catch ( Exception $e ) {
			$this->log_error( $e );
			$data['status']          = 'fail';
			$data['success']         = false;
			$data['total']['amount'] = 0;
			wp_send_json( $data );
		}
	}

	/**
	 * Ajax endpoint for updating shipping options
	 *
	 * @throws Exception Exception.
	 */
	public function revolut_payment_request_update_revolut_order_with_cart_total() {
		check_ajax_referer( 'wc-revolut-update-order-total', 'security' );

		try {
			$revolut_public_id = $this->get_post_request_data( 'revolut_public_id' );

			if ( empty( $revolut_public_id ) ) {
				throw new Exception( 'Can not get required parameter' );
			}

			WC()->cart->calculate_totals();

			$total_amount = $this->update_revolut_order_with_cart_total( $revolut_public_id, true );

			$data['success']         = true;
			$data['status']          = 'success';
			$data['total']['amount'] = $total_amount;
			wp_send_json( $data );
		} catch ( Exception $e ) {
			$this->log_error( $e );
			$data['status']          = 'fail';
			$data['success']         = false;
			$data['total']['amount'] = 0;
			wp_send_json( $data );
		}
	}

}
