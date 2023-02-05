<?php
/**
 * WC_Payment_Gateway_Revolut
 *
 * Abstract Revolut Payment Gateway
 *
 * @package    WooCommerce
 * @category   Payment Gateways
 * @author     Revolut
 * @since      2.0.0
 */

defined( 'ABSPATH' ) || exit();

if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
	return;
}

define( 'FAILED_CARD', 2005 );

/**
 * WC_Payment_Gateway_Revolut class.
 */
abstract class WC_Payment_Gateway_Revolut extends WC_Payment_Gateway_CC {


	use WC_Gateway_Revolut_Helper_Trait;
	use WC_Gateway_Revolut_Express_Checkout_Helper_Trait;

	/**
	 * API client
	 *
	 * @var WC_Revolut_API_Client
	 */
	public $api_client;

	/**
	 * Revolut saved cards
	 *
	 * @var bool
	 */
	public $revolut_saved_cards = false;

	/**
	 * Default payment gateway title
	 *
	 * @var string
	 */
	protected $default_title;

	/**
	 * User friendly error message code
	 *
	 * @var int
	 */
	protected $user_friendly_error_message_code = 1000;

	/**
	 * Available currency list
	 *
	 * @var array
	 */
	public $available_currency_list = array( 'AED', 'AUD', 'BGN', 'CAD', 'CHF', 'CZK', 'DKK', 'EUR', 'GBP', 'HKD', 'HUF', 'ILS', 'ISK', 'JPY', 'MXN', 'NOK', 'NZD', 'PLN', 'QAR', 'RON', 'SAR', 'SEK', 'SGD', 'THB', 'TRY', 'USD', 'ZAR' );

	/**
	 * Card Payments available currency list
	 *
	 * @var array
	 */
	public $card_payments_currency_list = array( 'AUD', 'CAD', 'CHF', 'CZK', 'DKK', 'EUR', 'GBP', 'HKD', 'HUF', 'JPY', 'NOK', 'NZD', 'PLN', 'RON', 'SEK', 'SGD', 'USD', 'ZAR' );

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->api_settings = revolut_wc()->api_settings;
		$this->has_fields   = true;
		$this->icon         = $this->get_icon();

		$this->init_supports();
		$this->init_form_fields();
		$this->init_settings();

		$this->api_client = new WC_Revolut_API_Client( $this->api_settings );

		add_filter( 'wc_revolut_settings_nav_tabs', array( $this, 'admin_nav_tab' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'woocommerce_checkout_revolut_order_processed' ), 300, 3 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'order_action_from_woocommerce' ), 300, 3 );
	}

	/**
	 * Init required js and css assets
	 */
	protected function init_scripts() {
		add_action( 'wp_enqueue_scripts', array( $this, 'wc_revolut_enqueue_scripts' ) );
	}

	/**
	 * Validates if the WooCommerce order created successfully.
	 *
	 * @since 2.0.0
	 *
	 * @param int      $order_id The ID of the order.
	 * @param array    $posted_data The ID of the order.
	 * @param WC_Order $order Created WC order.
	 */
	public function woocommerce_checkout_revolut_order_processed( $order_id, $posted_data, $order ) {
		if ( ! $this->check_is_post_data_submited( 'revolut_create_wc_order' ) || $posted_data['payment_method'] !== $this->id ) {
			return;
		}

		WC()->session->set( 'order_awaiting_payment', $order_id );

		$order_total         = $order->get_total();
		$order_currency      = $order->get_currency();
		$public_id           = $this->get_post_request_data( 'revolut_public_id' );
		$is_express_checkout = (bool) $this->get_post_request_data( 'is_express_checkout' );

		if ( ! $is_express_checkout ) {
			// update payment amount and currency after order creation in order to be sure that the payment will be exactly same with order.
			$update_revolut_order_result = false;
			try {
				$update_revolut_order_result = $this->update_revolut_order_total( $order_total, $order_currency, $public_id );
			} catch ( Exception $e ) {
				$this->log_error( $e->getMessage() );
			}

			if ( ! $update_revolut_order_result ) {
				wc_add_notice( __( 'Something went wrong while checking out. Payment was not taken. Please try again', 'revolut-gateway-for-woocommerce' ), 'error' );

				wp_send_json(
					array(
						'refresh-checkout' => true,
						'wc_order_id'      => $order_id,
						'result'           => 'revolut_wc_order_created',
					)
				);
			}
		}

		$this->save_wc_order_id( $public_id, $order_id );

		wp_send_json(
			array(
				'wc_order_id' => $order_id,
				'reload'      => isset( WC()->session->reload_checkout ),
				'result'      => 'revolut_wc_order_created',
			)
		);
	}

	/**
	 * Supported functionality
	 */
	public function init_supports() {
		$this->supports = array(
			'products',
		);
	}

	/**
	 * Add default options
	 */
	public function add_default_options() {
		try {
			$this->update_option( 'title', $this->default_title );
			$this->update_option( 'enabled', 'yes' );
		} catch ( Exception $e ) {
			$this->log_error( $e );
		}
	}

	/**
	 * Display icon in checkout
	 *
	 * @abstract
	 */
	public function get_icon() {
	}

	/**
	 * Add script to load card form
	 */
	public function wc_revolut_enqueue_scripts() {
		wp_register_style( 'revolut-custom-style', plugins_url( 'assets/css/style.css', WC_REVOLUT_MAIN_FILE ), array(), WC_GATEWAY_REVOLUT_VERSION );
		wp_enqueue_style( 'revolut-custom-style' );

		wp_enqueue_script( 'revolut-core', $this->api_client->base_url . '/embed.js', false, WC_GATEWAY_REVOLUT_VERSION, true );

		wp_enqueue_script( 'jquery' );
		wp_enqueue_script(
			'revolut-woocommerce',
			plugins_url( 'assets/js/revolut.js', WC_REVOLUT_MAIN_FILE ),
			array(
				'revolut-core',
				'jquery',
			),
			WC_GATEWAY_REVOLUT_VERSION,
			true
		);

		wp_localize_script(
			'revolut-woocommerce',
			'wc_revolut',
			array(
				'ajax_url'  => WC_AJAX::get_endpoint( '%%wc_revolut_gateway_ajax_endpoint%%' ),
				'page'      => $this->wc_revolut_get_current_page(),
				'order_id'  => $this->wc_revolut_get_current_order_id(),
				'order_key' => $this->wc_revolut_get_current_order_key(),
				'nonce'     => array(
					'billing_info'        => wp_create_nonce( 'wc-revolut-get-billing-info' ),
					'customer_info'       => wp_create_nonce( 'wc-revolut-get-customer-info' ),
					'get_order_public_id' => wp_create_nonce( 'wc-revolut-get-order-public-id' ),
				),
			)
		);
	}

	/**
	 * Check the current page
	 */
	public function wc_revolut_get_current_page() {
		global $wp;
		if ( is_product() ) {
			return 'product';
		}
		if ( is_cart() ) {
			return 'cart';
		}
		if ( is_checkout() ) {
			if ( ! empty( $wp->query_vars['order-pay'] ) ) {
				return 'order_pay';
			}

			return 'checkout';
		}
		if ( is_add_payment_method_page() ) {
			return 'add_payment_method';
		}

		return '';
	}

	/**
	 * Get current order id on 'order_pay' page
	 */
	public function wc_revolut_get_current_order_id() {
		global $wp;
		if ( is_checkout() ) {
			if ( ! empty( $wp->query_vars['order-pay'] ) && absint( $wp->query_vars['order-pay'] ) > 0 ) {
				return absint( $wp->query_vars['order-pay'] );

			}
		}
		return '';
	}

	/**
	 * Get current order key
	 */
	public function wc_revolut_get_current_order_key() {
		$order_id = $this->wc_revolut_get_current_order_id();
		if ( $order_id ) {
			$order = wc_get_order( $order_id );
			return $order->get_order_key();
		}
		return '';
	}

	/**
	 * Send order action request from Woocommerce to API.
	 *
	 * @param String            $revolut_order_id Revolut order id.
	 * @param String            $action Api action.
	 * @param array|object|null $body Request body.
	 *
	 * @return mixed
	 * @throws Exception Exception.
	 */
	public function action_revolut_order( $revolut_order_id, $action, $body = null ) {
		if ( empty( $revolut_order_id ) ) {
			return array();
		}

		$json = $this->api_client->post( "/orders/$revolut_order_id/$action", $body );

		if ( ! empty( $json ) && ! isset( $json['id'] ) && isset( $json['code'] ) ) {
			if ( ! empty( $json['code'] ) && FAILED_CARD === $json['code'] ) {
				/* translators: %s: Order Action. */
				throw new Exception( sprintf( __( 'Customer will not be able to get a %s using this card!', 'revolut-gateway-for-woocommerce' ), $action ) );
			}

			/* translators:%1s: Order Action. %$2s: Order Action.*/
			throw new Exception( sprintf( __( 'Cannot %1$s Order - Error Id: %2$s.', 'revolut-gateway-for-woocommerce' ), $action, $json['code'] ) );
		}

		return $json;
	}

	/**
	 * Add public_id field and logo on card form.
	 *
	 * @abstract
	 * @param String $public_id            Revolut public id.
	 * @param String $merchant_public_key  Revolut public key.
	 * @param String $display_tokenization Available saved card tokens.
	 *
	 * @return string
	 */
	public function generate_inline_revolut_form( $public_id, $merchant_public_key, $display_tokenization ) {
		return '';
	}

	/**
	 * Add save checkbox on payment form
	 *
	 * @abstract
	 *
	 * @return string
	 */
	public function save_payment_method_checkbox() {
		return '';
	}

	/**
	 * Check if save action requested for the payment method.
	 *
	 * @abstract
	 *
	 * @return bool
	 */
	public function save_payment_method_requested() {
		return false;
	}

	/**
	 * Check if saved payment method requested for making the payment.
	 *
	 * @abstract
	 *
	 * @return bool
	 */
	public function is_using_saved_payment_method() {
		return false;
	}

	/**
	 * Add update checkbox on payment form
	 *
	 * @abstract
	 *
	 * @return string
	 */
	public function display_update_subs_payment_checkout() {
		return '';
	}

	/**
	 * Save Payment method
	 *
	 * @param int $order_id The ID of the order.
	 *
	 * @return WC_Payment_Token_CC
	 * @throws Exception Exception.
	 */
	public function save_payment_method( $order_id ) {
		// get revolut customer ID from Revolut order.
		$revolut_order = null;
		for ( $i = 0; $i <= 9; $i++ ) {
			$revolut_order = $this->api_client->get( '/orders/' . $order_id );
			if ( isset( $revolut_order['customer_id'] ) && ! empty( $revolut_order['customer_id'] ) && 'PROCESSING' !== $revolut_order['state'] ) {
				$revolut_customer_id = $revolut_order['customer_id'];
				break;
			}
			sleep( 2 );
		}

		if ( empty( $revolut_customer_id ) ) {
			throw new Exception( 'An error occurred while saving the card' );
		}

		if ( ! $this->get_revolut_customer_id() ) {
			$this->insert_revolut_customer_id( $revolut_customer_id );
		}

		$revolut_customer = $this->api_client->get( '/customers/' . $revolut_customer_id );

		if ( empty( $revolut_customer['payment_methods'] ) || 0 === count( $revolut_customer['payment_methods'] ) ) {
			throw new Exception( 'Can not save Payment Methods through API' );
		}

		$payment_methods = $revolut_customer['payment_methods'];
		$exist_tokens    = WC_Payment_Tokens::get_customer_tokens( get_current_user_id(), $this->id );
		$stored_tokens   = array();

		foreach ( $exist_tokens as $token ) {
			$stored_tokens[ $token->get_token() ] = $token;
		}

		if ( empty( $revolut_order ) ) {
			$revolut_order = $this->api_client->get( '/orders/' . $order_id );
		}

		$current_payment_list = isset( $revolut_order['payments'] ) && ! empty( $revolut_order['payments'] ) ? $revolut_order['payments'] : array();
		$current_token        = null;

		foreach ( $payment_methods as $payment_method ) {

			if ( in_array( $payment_method['id'], array_keys( $stored_tokens ), true ) ) {
				continue;
			}

			$token = new WC_Payment_Token_CC();
			$token->set_token( $payment_method['id'] );
			$token->set_gateway_id( $this->id );
			$method_details  = $payment_method['method_details'];
			$card_type       = $payment_method['type'];
			$current_payment = self::searchListKeyValue( $current_payment_list, 'id', $payment_method['id'] );

			if ( isset( $current_payment['payment_method'] )
				&& isset( $current_payment['payment_method']['card'] )
				&& isset( $current_payment['payment_method']['card']['card_brand'] ) ) {
				$card_type = $current_payment['payment_method']['card']['card_brand'];
			}

			$token->set_card_type( $card_type );
			$token->set_last4( $method_details['last4'] );
			$token->set_expiry_month( $method_details['expiry_month'] );
			$token->set_expiry_year( $method_details['expiry_year'] );
			$token->set_user_id( get_current_user_id() );
			$token->save();
			$current_token = $token;
		}

		return $current_token;
	}

	/**
	 * Add new Payment method
	 *
	 * @throws Exception Exception.
	 */
	public function add_payment_method() {
		try {
			// find public_id.
			$revolut_payment_public_id = $this->get_post_request_data( 'revolut_public_id' );
			if ( empty( $revolut_payment_public_id ) ) {
				throw new Exception( 'Missing revolut_public_id parameter' );
			}

			// resolve revolut_public_id into revolut_order_id.
			$revolut_order_id = $this->get_revolut_order_by_public_id( $revolut_payment_public_id );
			if ( empty( $revolut_order_id ) ) {
				throw new Exception( 'Missing revolut order id parameter' );
			}

			$wc_token = $this->save_payment_method( $revolut_order_id );
			if ( null === $wc_token ) {
				throw new Exception( 'An error occured while saving payment method' );
			}

			$this->handle_add_payment_method( null, $wc_token, get_current_user_id() );

			return array(
				'result'   => 'success',
				'redirect' => wc_get_endpoint_url( 'payment-methods' ),
			);

		} catch ( Exception $e ) {
			$this->log_error( $e );

			wc_add_notice( $e->getMessage(), 'error' );
			return false;
		}
	}

	/**
	 * Process the payment and return the result.
	 *
	 * @param int $wc_order_id WooCommerce order id.
	 *
	 * @return array
	 *
	 * @throws Exception Exception.
	 */
	public function process_payment( $wc_order_id ) {
		$wc_order = wc_get_order( $wc_order_id );

		try {
			// find public_id.
			$is_express_checkout       = (bool) $this->get_posted_integer_data( 'is_express_checkout' );
			$revolut_payment_public_id = $this->get_post_request_data( 'revolut_public_id' );
			if ( empty( $revolut_payment_public_id ) ) {
				throw new Exception( 'Missing revolut_public_id parameter' );
			}

			$revolut_payment_error = $this->get_post_request_data( 'revolut_payment_error' );
			if ( ! empty( $revolut_payment_error ) ) {
				throw new Exception( $revolut_payment_error, $this->user_friendly_error_message_code );
			}

			// resolve revolut_public_id into revolut_order_id.
			$revolut_order_id = $this->get_revolut_order_by_public_id( $revolut_payment_public_id );
			if ( empty( $revolut_order_id ) ) {
				throw new Exception( 'Can not find Revolut order ID' );
			}

			// express checkout are the manual orders needs to be captured during processing.
			if ( $is_express_checkout && 'authorize_and_capture' === $this->api_settings->get_option( 'payment_action' ) ) {
				$this->action_revolut_order( $revolut_order_id, 'capture' );
			}

			// check if it needs to process payment with previously saved method.
			$previously_saved_wc_token = $this->maybe_pay_by_saved_method( $revolut_order_id );

			$this->save_wc_order_id( $revolut_payment_public_id, $wc_order_id );

			// payment should be processed until this point, if not throw an error.
			$this->check_payment_processed( $revolut_order_id );
			// payment process began...
			$wc_order->update_status( 'on-hold' );
			$wc_order->add_order_note( 'Payment has been successfully authorized (Order ID: ' . $revolut_order_id . ').' );

			// check payment result and update order status.
			$this->handle_revolut_order_result( $wc_order, $revolut_order_id );
			// check save method requested.
			$newly_saved_wc_token = $this->maybe_save_payment_method( $revolut_order_id, $wc_order );
			// check if there is any saved or used payment token.
			$wc_token = null;
			if ( $previously_saved_wc_token ) {
				$wc_token = $previously_saved_wc_token;
			} else {
				$wc_token = $newly_saved_wc_token;
			}

			$this->save_payment_token_to_order( $wc_order, $wc_token, get_current_user_id() );
			$this->verify_order_total( $revolut_order_id, $wc_order );
			$this->update_payment_method_title( $revolut_order_id, $wc_order );

			return $this->checkout_return( $wc_order, $revolut_order_id );
		} catch ( Exception $e ) {
			$this->log_error( $e->getMessage() );
			$wc_order->update_status( 'failed' );
			$wc_order->add_order_note( 'Customer attempted to pay, but the payment failed or got declined. (Error: ' . $e->getMessage() . ')' );
			$error_message_for_user = 'Something went wrong';
			if ( $e->getCode() === $this->user_friendly_error_message_code ) {
				$error_message_for_user = $e->getMessage();
			}

			// if page will be reloaded add the error message as notice, otherwise they're lost in the page reload.
			if ( $this->get_posted_integer_data( 'reload_checkout' ) ) {
				unset( WC()->session->reload_checkout );
				wc_add_notice( $error_message_for_user, 'error' );
			}

			return array(
				'messages' => $error_message_for_user,
				'result'   => 'fail',
				'redirect' => '',
			);
		}
	}

	/**
	 * Process the payment and return the result.
	 *
	 * @param string   $revolut_order_id Revolut order id.
	 * @param WC_Order $wc_order WooCommerce order.
	 */
	protected function update_payment_method_title( $revolut_order_id, $wc_order ) {
		try {
			if ( 'revolut_payment_request' !== $this->id ) {
				return;
			}
			$revolut_order          = $this->api_client->get( '/orders/' . $revolut_order_id );
			$revolut_order_total    = $this->get_revolut_order_amount( $revolut_order );
			$revolut_order_currency = $this->get_revolut_order_currency( $revolut_order );

			if ( empty( $revolut_order_total ) || empty( $revolut_order_currency ) ) {
				/* translators: %s: Revolut order id. */
				$wc_order->add_order_note( sprintf( __( 'Can\'t retrieve payment amount for this order. Please check your Revolut Business account (Order ID: %s)', 'revolut-gateway-for-woocommerce' ), $revolut_order_id ) );
				return;
			}

			if ( ! isset( $revolut_order['payments'][0]['payment_method']['type'] ) || empty( $revolut_order['payments'][0]['payment_method']['type'] ) ) {
				return;
			}

			$payment_method = $revolut_order['payments'][0]['payment_method']['type'];

			if ( 'APPLE_PAY' === $payment_method ) {
				$payment_method_title = 'Apple Pay (via Revolut)';
			} elseif ( 'GOOGLE_PAY' === $payment_method ) {
				$payment_method_title = 'Google Pay (via Revolut)';
			} else {
				$payment_method_title = $this->title;
			}

			$wc_order->set_payment_method_title( $payment_method_title );
			$wc_order->save();
		} catch ( Exception $e ) {
			$this->log_error( $e->getMessage() );
		}
	}

	/**
	 * Verify is paid amount and order total are queal
	 *
	 * @param string   $revolut_order_id Revolut order id.
	 * @param WC_Order $wc_order WooCommerce order.
	 *
	 * @throws Exception Exception.
	 */
	protected function verify_order_total( $revolut_order_id, $wc_order ) {
		$revolut_order          = $this->api_client->get( '/orders/' . $revolut_order_id );
		$revolut_order_total    = $this->get_revolut_order_amount( $revolut_order );
		$revolut_order_currency = $this->get_revolut_order_currency( $revolut_order );

		if ( empty( $revolut_order_total ) || empty( $revolut_order_currency ) ) {
			/* translators: %s: Revolut order id. */
			$wc_order->add_order_note( sprintf( __( 'Can\'t retrieve payment amount for this order. Please check your Revolut Business account (Order ID: %s)', 'revolut-gateway-for-woocommerce' ), $revolut_order_id ) );
			return;
		}

		$wc_order_currency = $wc_order->get_currency();
		$wc_order_total    = $this->get_revolut_order_total( $wc_order->get_total(), $wc_order_currency );

		if ( $wc_order_total !== $revolut_order_total || strtolower( $revolut_order_currency ) !== strtolower( $wc_order_currency ) ) {
			if ( abs( $wc_order_total - $revolut_order_total ) < 50 ) {
				return;
			}

			$wc_order_total      = $this->get_wc_order_total( $wc_order_total, $wc_order_currency );
			$revolut_order_total = $this->get_wc_order_total( $revolut_order_total, $revolut_order_currency );

			$order_message  = '<b>Difference detected between order and payment total.</b> Please verify order with the customer. (Order ID: ' . $revolut_order_id . ').';
			$order_message .= ' Order Total: ' . $wc_order_total . strtoupper( $wc_order_currency );
			$order_message .= ' Paid amount: ' . $revolut_order_total . strtoupper( $revolut_order_currency );

			$wc_order->update_status( 'on-hold' );
			$wc_order->add_order_note( wp_kses_post( $order_message ) );
		}
	}

	/**
	 * Update internal table to avoid piggybacking on already paid order.
	 *
	 * @param string $public_id Revolut public id.
	 * @param int    $wc_order_id WooCommerce order id.
	 *
	 * @throws Exception Exception.
	 */
	protected function save_wc_order_id( $public_id, $wc_order_id ) {
		try {
			global $wpdb;

			$updated_rows = $wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . $wpdb->prefix . "wc_revolut_orders
					SET wc_order_id=%d
					WHERE public_id=UNHEX(REPLACE(%s, '-', ''))",
					array( $wc_order_id, $public_id )
				)
			); // db call ok; no-cache ok.

			if ( 1 !== $updated_rows && ! empty( $wpdb->last_error ) ) {
				$this->log_error( 'Can not update wc_order_id for Revolut order record on DB: ' . $wpdb->last_error );
				return false;
			}

			$revolut_order_id = $this->get_revolut_order_by_public_id( $public_id );

			$body = array(
				'merchant_order_ext_ref' => $wc_order_id,
			);

			$this->api_client->patch( "/orders/$revolut_order_id", $body );
		} catch ( Exception $e ) {
			$this->log_error( $e->getMessage() );
		}
	}

	/**
	 * Update WooCommerce Order status based on payment result.
	 *
	 * @param WC_Order $wc_order WooCommerce order.
	 * @param string   $revolut_order_id Revolut order id.
	 *
	 * @throws Exception Exception.
	 */
	protected function handle_revolut_order_result( $wc_order, $revolut_order_id ) {
		$wc_order_id = $wc_order->get_id();

		// verify that the order was paid.
		$mode = $this->api_settings->get_option( 'payment_action' );

		for ( $i = 0; $i < WC_REVOLUT_FETCH_API_ORDER_ATTEMPTS; $i++ ) {
			if ( isset( $revolut_order_id ) && ! empty( $revolut_order_id ) ) {
				$order           = $this->api_client->get( '/orders/' . $revolut_order_id );
				$wc_order_status = empty( $wc_order->get_status() ) ? '' : $wc_order->get_status();
				$check_wc_status = 'processing' === $wc_order_status || 'completed' === $wc_order_status;
				if ( isset( $order['state'] ) && ! $check_wc_status ) {
					if ( 'COMPLETED' === $order['state'] && 'authorize_and_capture' === $mode ) {
						update_post_meta( $wc_order_id, 'revolut_capture', 'yes' );
						$wc_order->payment_complete( $revolut_order_id );
						$wc_order->add_order_note( 'Payment has been successfully captured (Order ID: ' . $revolut_order_id . ').' );
						return true;
					} elseif ( 'AUTHORISED' === $order['state'] && 'authorize' === $mode ) {
						return true;
					} elseif ( 'PENDING' === $order['state'] ) {
						$wc_order->add_order_note( 'Something went wrong while completing this payment. Please reach out to your customer and ask them to try again.' );
						throw new Exception( 'Something went wrong while completing this payment.' );
					} elseif ( 9 === $i && ( 'AUTHORISED' === $order['state'] || 'PROCESSING' === $order['state'] || 'IN_SETTLEMENT' === $order['state'] ) ) {
						if ( 'authorize_and_capture' === $mode ) {
							$wc_order->add_order_note(
								'Payment is taking a bit longer than expected to be completed. 
							                If the order is not moved to the “Processing” state after 24h, please check your Revolut account to verify that this payment was taken. 
							                You might need to contact your customer if it wasn’t.'
							);
						}

						return true;
					}

					sleep( WC_REVOLUT_WAIT_FOR_ORDER_TIME );
				} elseif ( $check_wc_status ) {
					return true;
				}
			} else {
				throw new Exception( 'Revolut order ID is missing' );
			}
		}

		return true;
	}

	/**
	 * Check is Payment processed.
	 *
	 * @param string $revolut_order_id Revolut order id.
	 *
	 * @throws Exception Exception.
	 */
	protected function check_payment_processed( $revolut_order_id ) {
		$revolut_order = $this->api_client->get( '/orders/' . $revolut_order_id );
		if ( ! isset( $revolut_order['state'] ) || ( isset( $revolut_order['state'] ) && 'PENDING' === $revolut_order['state'] ) ) {
			throw new Exception( 'Something went wrong while completing this payment. Please try again.' );
		}
	}

	/**
	 * Build payment fields area - including fields for logged-in users, and the payment fields.
	 */
	public function payment_fields() {
		if ( 'sandbox' === $this->api_settings->get_option( 'mode' ) ) {
			if ( 'revolut_cc' === $this->id ) {
				echo wp_kses_post( "<p style='color:red'>The payment gateway is in Sandbox Mode. You can use our <a href='https://developer.revolut.com/docs/accept-payments/tutorials/test-in-the-sandbox-environment/test-cards' target='_blank'>test cards</a> to simulate different payment scenarios." );
			} elseif ( 'revolut_pay' === $this->id ) {
				echo wp_kses_post( "<p style='color:red'>The payment gateway is in Sandbox Mode." );
			}
		}

		if ( ! $this->check_currency_support() ) {
			$this->currency_support_error();
			return false;
		}

		$public_id            = $this->get_revolut_public_id();
		$revolut_customer_id  = $this->get_revolut_customer_id();
		$descriptor           = new WC_Revolut_Order_Descriptor( WC()->cart->get_total( '' ), get_woocommerce_currency(), $revolut_customer_id );
		$display_tokenization = ! empty( $revolut_customer_id ) && $this->supports( 'tokenization' ) && ( is_checkout() || $this->get_request_data( 'pay_for_order' ) ) && $this->revolut_saved_cards;

		if ( $display_tokenization ) {
			try {
				$this->normalise_payment_methods( $revolut_customer_id );
			} catch ( Exception $e ) {
				$display_tokenization = false;
				$this->log_error( $e->getMessage() );
			}
		}

		$merchant_public_key = $this->get_merchant_public_api_key();

		try {
			if ( null === $public_id || is_add_payment_method_page() ) {
				$public_id = $this->create_revolut_order( $descriptor );
			} else {
				$public_id = $this->update_revolut_order( $descriptor, $public_id );
			}

			$this->set_revolut_public_id( $public_id );

			if ( $display_tokenization ) {
				$this->tokenization_script();
				$this->saved_payment_methods();
			}

			echo wp_kses_post( $this->generate_inline_revolut_form( $public_id, $merchant_public_key, $display_tokenization ) );
			echo wp_kses(
				$this->save_payment_method_checkbox(),
				array(
					'label' => array(
						'style' => array(),
						'for'   => array(),
					),
					'input' => array(
						'id'      => array(),
						'type'    => array(),
						'name'    => array(),
						'value'   => array(),
						'checked' => array(),
						'style'   => array(),
					),
					'p'     => array(
						'class' => array(),
					),
				)
			);
			echo wp_kses_post( $this->display_update_subs_payment_checkout() );
		} catch ( Exception $e ) {
			$this->log_error( $e->getMessage() );
			echo wp_kses_post( 'To receive payments using the Revolut Gateway for WooCommerce plugin, please <a href="https://developer.revolut.com/docs/accept-payments/#plugins-plugins-woocommerce-configure-the-woocommerce-plugin" target="_blank">configure your API key</a>.<br><br>If you are still seeing this message after the configuration of your API key, please reach out via the support chat in your Revolut Business account.' );
		}
	}

	/**
	 * Remove Payment Tokens which are not available in the API.
	 *
	 * @param string $revolut_customer_id Revolut customer id.
	 *
	 * @throws Exception Exception.
	 */
	protected function normalise_payment_methods( $revolut_customer_id ) {
		$exist_tokens = WC_Payment_Tokens::get_customer_tokens( get_current_user_id(), $this->id );

		if ( empty( $exist_tokens ) ) {
			return array();
		}

		$revolut_customer = $this->api_client->get( '/customers/' . $revolut_customer_id );

		if ( ! isset( $revolut_customer['id'] ) || empty( $revolut_customer['id'] ) ) {
			$this->remove_all_payment_tokens( $exist_tokens );
			throw new Exception( 'Can not find Revolut Customer' );
		}

		if ( ! isset( $revolut_customer['payment_methods'] ) || empty( $revolut_customer['payment_methods'] ) ) {
			$this->remove_all_payment_tokens( $exist_tokens );
			throw new Exception( 'Revolut Customer does not have any saved payment methods' );
		}

		$saved_revolut_payment_tokens = array_column( $revolut_customer['payment_methods'], 'id' );

		foreach ( $exist_tokens as $wc_token ) {
			$wc_token_id      = $wc_token->get_id();
			$wc_payment_token = $wc_token->get_token();
			if ( ! in_array( $wc_payment_token, $saved_revolut_payment_tokens, true ) ) {
				WC_Payment_Tokens::delete( $wc_token_id );
			}
		}
	}

	/**
	 * Clear all saved payment tokens.
	 *
	 * @param array $exist_tokens list of payment tokens.
	 */
	public function remove_all_payment_tokens( $exist_tokens ) {
		if ( empty( $exist_tokens ) ) {
			return;
		}

		foreach ( $exist_tokens as $wc_token ) {
			$wc_token_id = $wc_token->get_id();
			WC_Payment_Tokens::delete( $wc_token_id );
		}
	}

	/**
	 * Save Revolut customer id.
	 *
	 * @param string $revolut_customer_id Revolut customer id.
	 *
	 * @throws Exception Exception.
	 */
	protected function insert_revolut_customer_id( $revolut_customer_id ) {
		global $wpdb;
		$revolut_customer_id = "{$this->api_client->mode}_$revolut_customer_id";
		$result              = $wpdb->insert(
			$wpdb->prefix . 'wc_revolut_customer',
			array(
				'wc_customer_id'      => get_current_user_id(),
				'revolut_customer_id' => $revolut_customer_id,
			)
		); // db call ok; no-cache ok.

		if ( ! $result ) {
			throw new Exception( 'Something went wrong: while saving customer on db' );
		}
	}


	/**
	 * Check is order needs to paid with the saved payment method.
	 *
	 * @param string $revolut_order_id Revolut order id.
	 */
	protected function maybe_pay_by_saved_method( $revolut_order_id ) {
		if ( $this->is_using_saved_payment_method() ) {
			$wc_token = $this->get_selected_payment_token();
			return $this->pay_by_saved_method( $revolut_order_id, $wc_token );
		}

		return null;
	}

	/**
	 * Charge customer with previously saved payment method.
	 *
	 * @param string              $revolut_order_id Revolut order id.
	 * @param WC_Payment_Token_CC $wc_token WooCommerce payment token.
	 */
	protected function pay_by_saved_method( $revolut_order_id, $wc_token ) {
		$payment_method_id = $wc_token->get_token();

		$body = array(
			'payment_method_id' => $payment_method_id,
		);

		$this->action_revolut_order( $revolut_order_id, 'confirm', $body );
		return $wc_token;
	}

	/**
	 * Check if the payment methods should be saved.
	 *
	 * @param string   $revolut_order_id Revolut order id.
	 * @param WC_Order $wc_order WooCommerce order.
	 */
	protected function maybe_save_payment_method( $revolut_order_id, $wc_order ) {
		if ( $this->save_payment_method_requested() && ! $this->is_using_saved_payment_method() ) {
			try {
				return $this->save_payment_method( $revolut_order_id );
			} catch ( Exception $e ) {
				$wc_order->add_order_note( 'Card save process failed. (Error: ' . $e->getMessage() . ')' );
			}
		}
		return null;
	}

	/**
	 * Check if the payment methods should be saved.
	 *
	 * @param WC_Order            $order WooCommerce order.
	 * @param WC_Payment_Token_CC $wc_token WooCommerce payment token.
	 * @param int                 $wc_customer_id WooCommerce customer id.
	 *
	 * @throws Exception Exception.
	 */
	protected function save_payment_token_to_order( $order, $wc_token, $wc_customer_id ) {
		if ( null !== $wc_token && ! empty( $wc_token->get_id() ) ) {
			$id_payment_token = $wc_token->get_id();
			$order_id         = $order->get_id();

			if ( empty( $id_payment_token ) || empty( $order_id ) ) {
				throw new Exception( 'Can not save payment into order meta' );
			}

			$order->update_meta_data( '_payment_token', $wc_token->get_token() );
			$order->update_meta_data( '_payment_token_id', $id_payment_token );
			$order->update_meta_data( '_wc_customer_id', $wc_customer_id );

			if ( is_callable( array( $order, 'save' ) ) ) {
				$order->save();
			}

			// Also store it on the subscriptions being purchased or paid for in the order.
			if ( function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order_id ) ) {
				$subscriptions = wcs_get_subscriptions_for_order( $order_id );
			} elseif ( function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order_id ) ) {
				$subscriptions = wcs_get_subscriptions_for_renewal_order( $order_id );
			} else {
				$subscriptions = array();
			}

			foreach ( $subscriptions as $subscription ) {
				$subscription_id = $subscription->get_id();
				update_post_meta( $subscription_id, '_payment_token', $wc_token->get_token() );
				update_post_meta( $subscription_id, '_payment_token_id', $id_payment_token );
				update_post_meta( $subscription_id, '_wc_customer_id', $wc_customer_id );
			}
		}
	}

	/**
	 * Updates all active subscriptions payment method.
	 *
	 * @abstract
	 *
	 * @param WC_Subscription $current_subscription WooCommerce Subscription.
	 * @param object          $wc_token WooCommerce Payment Token.
	 * @param int             $wc_customer_id WooCommerce Customer id.
	 * @return bool
	 */
	public function handle_add_payment_method( $current_subscription, $wc_token, $wc_customer_id ) {
		return false;
	}

	/**
	 * Grab selected payment token from Request
	 *
	 * @abstract
	 *
	 * @return String
	 */
	public function get_selected_payment_token() {
		return '';
	}

	/**
	 * Return after checkout successfully.
	 *
	 * @param int    $wc_order WooCommerce order id.
	 * @param String $revolut_order_id Revolut order id.
	 *
	 * @return array
	 */
	public function checkout_return( $wc_order, $revolut_order_id ) {
		$this->clear_temp_session( $revolut_order_id );
		$this->unset_revolut_public_id();
		$this->unset_revolut_express_checkout_public_id();
		if ( isset( WC()->cart ) ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $wc_order ),
		);
	}

	/**
	 * Clear temporary payment sessipm.
	 *
	 * @param String $revolut_order_id Revolut order id.
	 *
	 * @return void
	 */
	public function clear_temp_session( $revolut_order_id ) {
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'wc_revolut_temp_session', array( 'order_id' => $revolut_order_id ) );  // db call ok; no-cache ok.
	}

	/**
	 * Add admin notice when use revolut payment without API key
	 */
	public function admin_notices() {
		if ( 'yes' !== $this->get_option( 'enabled' )
			|| ! empty( $this->api_client->api_key ) ) {
			return;
		}

		if ( empty( $this->api_client->api_key ) ) {
			echo wp_kses_post(
				'<div class="error revolut-passphrase-message"><p>'
				. __( 'Revolut requires an API Key to work.', 'revolut-gateway-for-woocommerce' )
				. '</p></div>'
			);
		}
	}

	/**
	 * Handle Order action from Woocommerce to API.
	 *
	 * @param int    $order_id WooCommerce Order Id.
	 * @param String $old_status WooCommerce Order Status.
	 * @param String $new_status WooCommerce Order Status.
	 *
	 * @throws Exception Exception.
	 */
	public function order_action_from_woocommerce( $order_id, $old_status, $new_status ) {
		$wc_order = wc_get_order( $order_id );
		if ( in_array( $wc_order->get_payment_method(), WC_REVOLUT_GATEWAYS, true ) ) {
			$revolut_order_id = $this->get_revolut_order( $order_id );
			if ( ! empty( $revolut_order_id ) && 'yes' === $this->api_settings->get_option( 'accept_capture' ) ) {
				$order = $this->api_client->get( '/orders/' . $revolut_order_id );
				$state = isset( $order['state'] ) ? $order['state'] : '';
				if ( 'authorize' === $this->api_settings->get_option( 'payment_action' ) ) {
					// capture order.
					if ( 'processing' === $new_status || 'completed' === $new_status ) {
						// check fraud order.
						$order_amount = $this->get_revolut_order_amount( $order );
						$currency     = $this->get_revolut_order_currency( $order );
						$total        = $this->get_revolut_order_total( $wc_order->get_total(), $currency );
						if ( $total !== $order_amount ) {
							$wc_order->add_order_note( __( 'Order amount can\'t be partially captured. Please try again or capture this payment from your Revolut Business web portal.', 'revolut-gateway-for-woocommerce' ) );
						}

						if ( 'AUTHORISED' === $state ) {
							$response       = $this->action_revolut_order( $revolut_order_id, 'capture' );
							$order_response = $this->api_client->get( '/orders/' . $revolut_order_id );

							if ( 'COMPLETED' === $order_response['state'] || 'IN_SETTLEMENT' === $order_response['state'] ) {
								$wc_order->payment_complete( $revolut_order_id );
								$wc_order->add_order_note( __( 'Payment amount has been captured successfully.', 'revolut-gateway-for-woocommerce' ) );
								update_post_meta( $order_id, 'revolut_capture', 'yes' );
							} else {
								$wc_order->add_order_note( __( 'Order capture wasn\'t successful. Please try again or check your Revolut Business web portal for more information', 'revolut-gateway-for-woocommerce' ) );
							}
						}
					}
				}
			}
		}
	}

	/**
	 * Get Revolut Order from database
	 *
	 * @param String $order_id Revolut Order Id.
	 *
	 * @return string|string[]|null
	 */
	public function get_revolut_order( $order_id ) {
		global $wpdb;

		$revolut_order_id = $this->uuid_dashes(
			$wpdb->get_col(
				$wpdb->prepare(
					'SELECT HEX(order_id) FROM ' . $wpdb->prefix . 'wc_revolut_orders
                WHERE wc_order_id=%s',
					array( $order_id )
				)
			)
		); // db call ok; no-cache ok.

		return $revolut_order_id;
	}

	/**
	 * Process a refund if supported.
	 *
	 * @param int    $order_id Order ID.
	 * @param float  $amount Refund amount.
	 * @param string $reason Refund reason.
	 *
	 * @return bool|WP_Error
	 * @throws Exception Exception.
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$wc_order = wc_get_order( $order_id );

		if ( ! $this->can_refund_order( $wc_order ) ) {
			return new WP_Error( 'error', __( 'Order can\'t be refunded.', 'woocommerce' ) );
		}

		$revolut_order_id = $this->get_revolut_order( $order_id );

		if ( ! isset( $revolut_order_id ) ) {
			throw new Exception( __( 'Can\'t retrieve order information right now. Please try again later or process the refund via your Revolut Business account.', 'revolut-gateway-for-woocommerce' ) );
		} else {
			$order = $this->api_client->get( '/orders/' . $revolut_order_id );
			if ( 'PAYMENT' === $order['type'] && 'COMPLETED' === $order['state'] || 'IN_SETTLEMENT' === $order['state'] ) {
				if ( $order['refunded_amount']['value'] === $order['order_amount']['value'] ) {
					throw new Exception( __( 'The amount remaining for this order is less than the amount being refunded. Please check your Revolut Business account.', 'revolut-gateway-for-woocommerce' ) );
				}

				$amount   = round( $amount, 2 );
				$currency = $this->get_revolut_order_currency( $order );
				if ( $this->is_zero_decimal( $currency ) && ( $amount - floor( $amount ) ) > 0 ) {
					throw new Exception( __( 'Revolut: Can\'t refund this amount for this order. Please check your Revolut Business account.', 'revolut-gateway-for-woocommerce' ) );
				}
				$refund_amount     = $this->get_revolut_order_total( $amount, $currency );
				$refund_amount_api = (float) $order['refunded_amount']['value'];
				$order_amount_api  = $this->get_revolut_order_amount( $order );

				if ( $refund_amount_api < $order_amount_api && $refund_amount <= $order_amount_api - $refund_amount_api ) {
					$body     = array(
						'amount'      => $refund_amount,
						'currency'    => $wc_order->get_currency(),
						'description' => $reason,
					);
					$response = $this->action_revolut_order( $revolut_order_id, 'refund', $body );
					if ( isset( $response['id'] ) && ! empty( $response['id'] ) ) {
						/* translators: %s: Revolut refund id. */
						$wc_order->add_order_note( sprintf( __( 'Order has been successfully refunded (Refund ID: %s).', 'revolut-gateway-for-woocommerce' ), $response['id'] ) );

						return true;
					}
				} else {
					throw new Exception( __( 'Revolut: This amount can\'t be refunded for this order. Please check your Revolut Business account.', 'revolut-gateway-for-woocommerce' ) );
				}
			} else {
				throw new Exception( __( 'Revolut: Incomplete order can\'t be refunded', 'revolut-gateway-for-woocommerce' ) );
			}
		}

		return false;
	}

	/**
	 * Add setting tab to admin configuration.
	 *
	 * @param array $tabs setting tabs.
	 */
	public function admin_nav_tab( $tabs ) {
		$tabs[ $this->id ] = $this->tab_title;

		return $tabs;
	}

	/**
	 * Check is currency supported.
	 */
	public function check_currency_support() {
		if ( ! in_array( get_woocommerce_currency(), $this->available_currency_list, true ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Add currency not supported error.
	 */
	public function currency_support_error() {
		echo wp_kses_post( get_woocommerce_currency() . ' currency is not supported, please use a different currency to check out. You can check the supported currencies in the <a href="https://www.revolut.com/en-HR/business/help/merchant-accounts/payments/in-which-currencies-can-i-accept-payments" target="_blank">[following link]</a>' );
	}

	/**
	 * Search in multidimentional array by key and value pair.
	 *
	 * @param array  $list list for search.
	 * @param string $skey search key.
	 * @param mixed  $svalue search value.
	 */
	public function searchListKeyValue( $list, $skey, $svalue ) {
		foreach ( $list as $element ) {
			if ( isset( $element['payment_method'] ) ) {
				foreach ( $element['payment_method'] as $key => $value ) {
					if ( $key === $skey && $svalue === $value ) {
						return $element;
					}
				}
			}
		}

		return null;
	}
}
