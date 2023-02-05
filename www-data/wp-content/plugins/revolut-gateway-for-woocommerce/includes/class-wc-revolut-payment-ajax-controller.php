<?php
/**
 * Handles AJAX calls, related to Revolut Payment.
 *
 * @package    WooCommerce
 * @category   Payment Gateways
 * @author     Revolut
 * @since      3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC_Revolut_Payment_Ajax_Controller class.
 */
class WC_Revolut_Payment_Ajax_Controller {

	use WC_Gateway_Revolut_Helper_Trait;

	/**
	 * API client
	 *
	 * @var WC_Revolut_API_Client
	 */
	public $api_client;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->api_settings = revolut_wc()->api_settings;
		$this->api_client   = new WC_Revolut_API_Client( $this->api_settings );

		add_action(
			'wc_ajax_wc_revolut_validate_checkout_fields',
			array(
				$this,
				'wc_revolut_validate_checkout_fields',
			)
		);
		add_action(
			'wc_ajax_wc_revolut_get_order_pay_billing_info',
			array(
				$this,
				'wc_revolut_get_order_pay_billing_info',
			)
		);
		add_action( 'wc_ajax_wc_revolut_get_customer_info', array( $this, 'wc_revolut_get_customer_info' ) );
		add_action( 'wc_ajax_wc_revolut_process_payment_result', array( $this, 'wc_revolut_process_payment_result' ) );
		add_action( 'wc_ajax_revolut_payment_request_cancel_order', array( $this, 'revolut_payment_request_ajax_cancel_order' ) );
		add_action( 'wc_ajax_revolut_payment_request_set_error_message', array( $this, 'revolut_payment_request_ajax_set_error_message' ) );
		add_action( 'wc_ajax_revolut_payment_request_log_error', array( $this, 'revolut_payment_request_ajax_log_error' ) );

		add_action( 'wp_ajax_wc_revolut_set_webhook', array( $this, 'wc_revolut_set_webhook' ) );
		add_action(
			'wp_ajax_wc_revolut_onboard_applepay_domain',
			array(
				$this,
				'wc_revolut_onboard_applepay_domain',
			)
		);
	}

	/**
	 * Process Revolut Order
	 *
	 * @throws Exception Exception.
	 */
	public function wc_revolut_process_payment_result() {
		try {
			$wc_order_id      = $this->get_posted_integer_data( 'wc_order_id' );
			$selected_gateway = $this->get_post_request_data( 'revolut_gateway' );

			if ( empty( $wc_order_id ) || empty( $selected_gateway ) || empty( $this->get_post_request_data( 'revolut_public_id' ) ) ) {
				$this->log_error(
					array(
						'wc_order_id'       => $wc_order_id,
						'selected_gateway'  => $selected_gateway,
						'revolut_public_id' => $this->get_post_request_data( 'revolut_public_id' ),
					)
				);

				throw new Exception( __( 'We are unable to process your order, please try again.', 'woocommerce' ) );
			}

			$revolut_gateway = new WC_Gateway_Revolut_CC();

			if ( 'revolut_pay' === $selected_gateway ) {
				$revolut_gateway = new WC_Gateway_Revolut_Pay();
			} elseif ( 'revolut_payment_request' === $selected_gateway ) {
				$revolut_gateway = new WC_Gateway_Revolut_Payment_Request();
			}

			$result = $revolut_gateway->process_payment( $wc_order_id );

		} catch ( Exception $e ) {
			$result = array(
				'messages' => $e->getMessage(),
				'result'   => 'fail',
				'redirect' => '',
			);
		}

		try {
			if ( ! empty( $wc_order_id ) && isset( $result['result'] ) && 'success' === $result['result'] ) {
				$result['order_id'] = $wc_order_id;
				apply_filters( 'woocommerce_payment_successful_result', $result, $wc_order_id );
			}
		} catch ( Exception $e ) {
			// if hook was unsuccessful do not prevent order process.
			$this->log_error( $e->getMessage() );
		}

		wp_send_json( $result );
	}

	/**
	 * Setup webhook
	 *
	 * @throws Exception Exception.
	 */
	public function wc_revolut_set_webhook() {
		try {
			if ( $this->check_is_post_data_submited( 'apiKey' ) || empty( $this->get_post_request_data( 'apiKey' ) ) ) {
				wp_die( false );
			}

			if ( ! $this->check_is_post_data_submited( 'mode' ) || empty( $this->get_post_request_data( 'mode' ) ) ) {
				wp_die( false );
			}

			$web_hook_url = get_site_url( null, '/wp-json/wc/v3/revolut', 'https' );

			$body = array(
				'url'    => $web_hook_url,
				'events' => array(
					'ORDER_COMPLETED',
					'ORDER_AUTHORISED',
				),
			);

			$mode = $this->get_post_request_data( 'mode' );

			if ( 'live' === $mode ) {
				$this->api_client->api_url = $this->api_client->api_url_live;
			} elseif ( 'sandbox' === $mode ) {
				$this->api_client->api_url = $this->api_client->api_url_sandbox;
			} elseif ( 'dev' === $mode ) {
				$this->api_client->api_url = $this->api_client->api_url_dev;
			}

			$this->api_client->api_url .= '/api/1.0';
			$this->api_client->api_key  = $this->get_post_request_data( 'apiKey' );

			$web_hook_url_list = $this->api_client->get( '/webhooks' );
			if ( ! empty( $web_hook_url_list ) ) {
				$web_hook_url_list = array_column( $web_hook_url_list, 'url' );

				if ( in_array( $web_hook_url, $web_hook_url_list, true ) ) {
					wp_send_json(
						array(
							'success' => true,
						)
					);
				}
			}

			$response = $this->api_client->post( '/webhooks', $body );

			if ( isset( $response['id'] ) && ! empty( $response['id'] ) ) {
				wp_send_json(
					array(
						'success' => true,
					)
				);
			}
		} catch ( Exception $e ) {
			$this->log_error( $e->getMessage() );
			wp_send_json(
				array(
					'success' => false,
					'message' => $e->getMessage(),
				)
			);
		}

		wp_send_json(
			array(
				'success' => true,
			)
		);
	}

	/**
	 * Onboard Apple Pay domain
	 *
	 * @throws Exception Exception.
	 */
	public function wc_revolut_onboard_applepay_domain() {
		try {
			$domain_name = str_replace( array( 'https://', 'http://' ), '', get_site_url() );

			$onboarding_file = untrailingslashit( ABSPATH ) . '/.well-known/apple-developer-merchantid-domain-association';

			$is_exist = fopen( $onboarding_file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen

			if ( ! $is_exist ) {
				wp_send_json(
					array(
						'success' => false,
						'message' => 'Can not find Apple Pay on-boarding file: ' . $onboarding_file,
					)
				);
			}

			$request_body = array(
				'domain' => $domain_name,
			);

			$this->api_settings = revolut_wc()->api_settings;
			$this->api_client   = new WC_Revolut_API_Client( $this->api_settings, true );

			$response = $this->api_client->post( '/apple-pay/domains/register', $request_body );

			$revolut_payment_request_settings                                        = get_option( 'woocommerce_revolut_payment_request_settings', array() );
			$revolut_payment_request_settings['apple_pay_merchant_onboarded_domain'] = $domain_name;
			$revolut_payment_request_settings['apple_pay_merchant_onboarded_api_key'] = $this->api_client->api_key;
			$revolut_payment_request_settings['apple_pay_merchant_onboarded']         = 'yes';
			update_option( 'woocommerce_revolut_payment_request_settings', $revolut_payment_request_settings );

			wp_send_json(
				array(
					'success'  => true,
					'response' => $response,
				)
			);

		} catch ( Exception $e ) {
			$this->log_error( $e->getMessage() );
			wp_send_json(
				array(
					'success' => false,
					'message' => $e->getMessage(),
				)
			);
		}

		wp_send_json(
			array(
				'success' => false,
				'message' => 'Something went wrong.',
			)
		);
	}

	/**
	 * Validate checkout fields
	 *
	 * @throws Exception Exception.
	 */
	public function wc_revolut_validate_checkout_fields() {
		wc_maybe_define_constant( 'WOOCOMMERCE_CHECKOUT', true );

		do_action( 'woocommerce_before_checkout_process' );

		if ( WC()->cart->is_empty() ) {
			/* translators: %s: shop cart url */
			throw new Exception( sprintf( __( 'Sorry, your session has expired. <a href="%s" class="wc-backward">Return to shop</a>', 'woocommerce' ), esc_url( wc_get_page_permalink( 'shop' ) ) ) );
		}

		do_action( 'woocommerce_checkout_process' );

		$validate_checkout = new WC_Revolut_Validate_Checkout();

		$validate_checkout->validate_checkout_fields();

		if ( 0 === wc_notice_count( 'error' ) ) {
			wp_send_json(
				array(
					'result' => 'success',
				)
			);
		}

		$validate_checkout->return_ajax_failure_response();
	}

	/**
	 * Get billing info for manual order payments
	 */
	public function wc_revolut_get_order_pay_billing_info() {
		check_ajax_referer( 'wc-revolut-get-billing-info', 'security' );

		$order_id  = $this->get_posted_integer_data( 'order_id' );
		$order_key = $this->get_post_request_data( 'order_key' );
		$order     = wc_get_order( $order_id );
		// validate order key.
		if ( $order && $order_key === $order->get_order_key() ) {
			$billing_address = $order->get_address( 'billing' );
			$billing_info    = array(
				'name'           => $billing_address['first_name'] . ' ' . $billing_address['last_name'],
				'email'          => $billing_address['email'],
				'phone'          => $billing_address['phone'],
				'billingAddress' => array(
					'countryCode' => $billing_address['country'],
					'region'      => $billing_address['state'],
					'city'        => $billing_address['city'],
					'streetLine1' => $billing_address['address_1'],
					'streetLine2' => $billing_address['address_2'],
					'postcode'    => $billing_address['postcode'],
				),
			);
			wp_send_json( $billing_info );
		}
		wp_send_json( array() );
	}

	/**
	 * Get billing info for payment method save
	 */
	public function wc_revolut_get_customer_info() {
		check_ajax_referer( 'wc-revolut-get-customer-info', 'security' );

		$customer_id = get_current_user_id();
		$customer    = new WC_Customer( $customer_id );
		// validate order key.
		if ( $customer_id ) {
			$billing_info = array(
				'name'  => $customer->get_first_name() . ' ' . $customer->get_last_name(),
				'email' => $customer->get_email(),
				'phone' => $customer->get_billing_phone(),
			);
			wp_send_json( $billing_info );
		} else {
			wp_send_json(
				array(
					'error' => true,
					'msg'   => 'Can not find customer address',
				)
			);
		}
		wp_die();
	}

	/**
	 * Cancel api order
	 */
	public function revolut_payment_request_ajax_cancel_order() {
		check_ajax_referer( 'wc-revolut-cancel-order', 'security' );
		$revolut_public_id = $this->get_post_request_data( 'revolut_public_id' );
		$revolut_order_id  = $this->get_revolut_order_by_public_id( $revolut_public_id );

		try {
			$revolut_gateway = new WC_Gateway_Revolut_CC();
			$revolut_gateway->action_revolut_order( $revolut_order_id, 'cancel' );
			$revolut_gateway->clear_temp_session( $revolut_order_id );
			$revolut_public_id = $this->create_revolut_order( $revolut_gateway->get_revolut_order_descriptor(), true );
			$revolut_gateway->set_revolut_express_checkout_public_id( $revolut_public_id );
			wp_send_json(
				array(
					'success'           => true,
					'revolut_public_id' => $revolut_public_id,
				)
			);
		} catch ( Exception $e ) {
			wp_send_json( array( 'success' => false ) );
			$this->log_error( $e );
		}
	}

	/**
	 * Set error message
	 */
	public function revolut_payment_request_ajax_set_error_message() {
		$error_message = $this->get_post_request_data( 'revolut_payment_request_error' );

		if ( empty( $error_message ) ) {
			$error_message = __( 'Something went wrong', 'revolut-gateway-for-woocommerce' );
		}

		wc_add_notice( $error_message, 'error' );
	}

	/**
	 * Log error message
	 */
	public function revolut_payment_request_ajax_log_error() {
		$error_message = $this->get_post_request_data( 'revolut_payment_request_error' );
		$this->log_error( $error_message );
	}
}
