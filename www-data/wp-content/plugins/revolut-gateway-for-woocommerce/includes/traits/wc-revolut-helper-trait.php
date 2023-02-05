<?php
/**
 * Revolut Helper
 *
 * Helper class for required tools.
 *
 * @package WooCommerce
 * @category Payment Gateways
 * @author Revolut
 * @since 2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC_Gateway_Revolut_Helper_Trait trait.
 */
trait WC_Gateway_Revolut_Helper_Trait {


	use WC_Revolut_Settings_Trait;
	use WC_Revolut_Logger_Trait;

	/**
	 * Create Revolut Order
	 *
	 * @param WC_Revolut_Order_Descriptor $order_descriptor Revolut Order Descriptor.
	 *
	 * @param bool                        $is_express_checkout indicator.
	 *
	 * @return mixed
	 * @throws Exception Exception.
	 */
	public function create_revolut_order( WC_Revolut_Order_Descriptor $order_descriptor, $is_express_checkout = false ) {
		$capture = 'authorize' === $this->api_settings->get_option( 'payment_action' ) || $is_express_checkout ? 'MANUAL' : 'AUTOMATIC';

		$body = array(
			'amount'       => $order_descriptor->amount,
			'currency'     => $order_descriptor->currency,
			'customer_id'  => $order_descriptor->revolut_customer_id,
			'capture_mode' => $capture,
		);

		$json = $this->api_client->post( '/orders', $body );

		if ( empty( $json['id'] ) || empty( $json['public_id'] ) ) {
			throw new Exception( 'Something went wrong: ' . wp_json_encode( $json, JSON_PRETTY_PRINT ) );
		}

		global $wpdb;
		$result = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . $wpdb->prefix . "wc_revolut_orders (order_id, public_id)
            VALUES (UNHEX(REPLACE(%s, '-', '')), UNHEX(REPLACE(%s, '-', '')))",
				array(
					$json['id'],
					$json['public_id'],
				)
			)
		); // db call ok; no-cache ok.

		if ( 1 !== $result ) {
			throw new Exception( 'Can not save Revolut order record on DB:' . $wpdb->last_error );
		}

		if ( $is_express_checkout ) {
			$this->add_or_update_temp_session( $json['id'] );
		}

		return $json['public_id'];
	}

	/**
	 * Update Revolut Order
	 *
	 * @param WC_Revolut_Order_Descriptor $order_descriptor Revolut Order Descriptor.
	 * @param String                      $public_id Revolut public id.
	 * @param Bool                        $is_revpay_express_checkout is revpay express checkout.
	 *
	 * @return mixed
	 * @throws Exception Exception.
	 */
	public function update_revolut_order( WC_Revolut_Order_Descriptor $order_descriptor, $public_id, $is_revpay_express_checkout = false ) {
		$order_id = $this->get_revolut_order_by_public_id( $public_id );

		$body = array(
			'amount'      => $order_descriptor->amount,
			'currency'    => $order_descriptor->currency,
			'customer_id' => $order_descriptor->revolut_customer_id,
		);

		if ( empty( $order_id ) ) {
			return $this->create_revolut_order( $order_descriptor, $is_revpay_express_checkout );
		}

		$revolut_order = $this->api_client->get( "/orders/$order_id" );

		if ( ! isset( $revolut_order['public_id'] ) || ! isset( $revolut_order['id'] ) || 'PENDING' !== $revolut_order['state'] ) {
			return $this->create_revolut_order( $order_descriptor, $is_revpay_express_checkout );
		}

		$revolut_order = $this->api_client->patch( "/orders/$order_id", $body );

		if ( ! isset( $revolut_order['public_id'] ) || ! isset( $revolut_order['id'] ) ) {
			return $this->create_revolut_order( $order_descriptor, $is_revpay_express_checkout );
		}

		if ( $is_revpay_express_checkout ) {
			$this->add_or_update_temp_session( $revolut_order['id'] );
		}

		return $revolut_order['public_id'];
	}

	/**
	 * Convert saved customer session into current session.
	 *
	 * @param string $id_revolut_order Revolut order id.
	 *
	 * @return void.
	 */
	public function convert_revolut_order_metadata_into_wc_session( $id_revolut_order ) {
		global $wpdb;
		$temp_session = $wpdb->get_row( $wpdb->prepare( 'SELECT temp_session FROM ' . $wpdb->prefix . 'wc_revolut_temp_session WHERE order_id=%s', array( $id_revolut_order ) ), ARRAY_A ); // db call ok; no-cache ok.
		$this->log_info( 'start convert_revolut_order_metadata_into_wc_session temp_session:' );
		$this->log_info( $temp_session['temp_session'] );

		$wc_order_metadata = json_decode( $temp_session['temp_session'], true );
		$id_wc_customer    = (int) $wc_order_metadata['id_customer'];

		if ( $id_wc_customer ) {
			wp_set_current_user( $id_wc_customer );
		}

		WC()->session->set( 'cart', $wc_order_metadata['cart'] );
		WC()->session->set( 'cart_totals', $wc_order_metadata['cart_totals'] );
		WC()->session->set( 'applied_coupons', $wc_order_metadata['applied_coupons'] );
		WC()->session->set( 'coupon_discount_totals', $wc_order_metadata['coupon_discount_totals'] );
		WC()->session->set( 'coupon_discount_tax_totals', $wc_order_metadata['coupon_discount_tax_totals'] );
		WC()->session->set( 'get_removed_cart_contents', $wc_order_metadata['get_removed_cart_contents'] );
	}

	/**
	 * Save or Update customer session temporarily.
	 *
	 * @param string $revolut_order_id Revolut order id.
	 *
	 * @throws Exception Exception.
	 */
	public function add_or_update_temp_session( $revolut_order_id ) {
		$order_metadata['id_customer']                = get_current_user_id();
		$order_metadata['cart']                       = WC()->cart->get_cart_for_session();
		$order_metadata['cart_totals']                = WC()->cart->get_totals();
		$order_metadata['applied_coupons']            = WC()->cart->get_applied_coupons();
		$order_metadata['coupon_discount_totals']     = WC()->cart->get_coupon_discount_totals();
		$order_metadata['coupon_discount_tax_totals'] = WC()->cart->get_coupon_discount_tax_totals();
		$order_metadata['get_removed_cart_contents']  = WC()->cart->get_removed_cart_contents();

		$temp_session = wp_json_encode( $order_metadata );

		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . $wpdb->prefix . 'wc_revolut_temp_session (order_id, temp_session)
            VALUES (%s, %s) ON DUPLICATE KEY UPDATE temp_session =  VALUES(temp_session)',
				array(
					$revolut_order_id,
					$temp_session,
				)
			)
		); // db call ok; no-cache ok.
	}

	/**
	 * Get Revolut customer id.
	 */
	public function get_revolut_customer_id() {
		$wc_customer_id = get_current_user_id();

		if ( empty( $wc_customer_id ) ) {
			return null;
		}

		global $wpdb;
		$revolut_customer_id = $wpdb->get_col( $wpdb->prepare( 'SELECT revolut_customer_id FROM ' . $wpdb->prefix . 'wc_revolut_customer WHERE wc_customer_id=%s', array( $wc_customer_id ) ) ); // db call ok; no-cache ok.
		$revolut_customer_id = reset( $revolut_customer_id );
		if ( empty( $revolut_customer_id ) ) {
			$revolut_customer_id = null;
		}

		$revolut_customer_id_with_mode = explode( '_', $revolut_customer_id );

		if ( count( $revolut_customer_id_with_mode ) > 1 ) {
			list( $api_mode, $revolut_customer_id ) = $revolut_customer_id_with_mode;

			if ( $api_mode !== $this->api_client->mode ) {
				return null;
			}

			return $revolut_customer_id;
		}

		return $revolut_customer_id;
	}

	/**
	 * Update Revolut Order Total
	 *
	 * @param float  $order_total Order total.
	 * @param string $currency Order currency.
	 * @param string $public_id Order public id.
	 *
	 * @return bool
	 * @throws Exception Exception.
	 */
	public function update_revolut_order_total( $order_total, $currency, $public_id ) {
		$order_id = $this->get_revolut_order_by_public_id( $public_id );

		$order_total = round( $order_total, 2 );

		$revolut_order_total = $this->get_revolut_order_total( $order_total, $currency );

		$body = array(
			'amount'   => $revolut_order_total,
			'currency' => $currency,
		);

		if ( empty( $order_id ) ) {
			return false;
		}

		$revolut_order = $this->api_client->get( "/orders/$order_id" );

		if ( ! isset( $revolut_order['public_id'] ) || ! isset( $revolut_order['id'] ) || 'PENDING' !== $revolut_order['state'] ) {
			return false;
		}

		$revolut_order = $this->api_client->patch( "/orders/$order_id", $body );

		if ( ! isset( $revolut_order['public_id'] ) || ! isset( $revolut_order['id'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Fetch Revolut order by public id
	 *
	 * @param String $public_id Revolut public id.
	 *
	 * @return string|null
	 */
	public function get_revolut_order_by_public_id( $public_id ) {
		global $wpdb;
		// resolve into order_id.
		return $this->uuid_dashes(
			$wpdb->get_col( // phpcs:ignore
				$wpdb->prepare(
					'SELECT HEX(order_id) FROM ' . $wpdb->prefix . "wc_revolut_orders
                WHERE public_id=UNHEX(REPLACE(%s, '-', ''))",
					array( $public_id )
				)
			)
		);
	}

	/**
	 * Load Merchant Public Key from API.
	 *
	 * @return string
	 */
	public function get_merchant_public_api_key() {
		try {
			$merchant_public_key = $this->get_revolut_merchant_public_key();

			if ( ! empty( $merchant_public_key ) ) {
				return $merchant_public_key;
			}

			$merchant_public_key = $this->api_client->get( WC_GATEWAY_PUBLIC_KEY_ENDPOINT, true );
			$merchant_public_key = isset( $merchant_public_key['public_key'] ) ? $merchant_public_key['public_key'] : '';

			if ( empty( $merchant_public_key ) ) {
				return '';
			}

			$this->set_revolut_merchant_public_key( $merchant_public_key );
			return $merchant_public_key;
		} catch ( Exception $e ) {
			return '';
		}
	}

	/**
	 * Check Merchant Account features.
	 *
	 * @return bool
	 */
	public function check_feature_support() {
		try {
			$this->api_client->set_public_key( $this->get_revolut_merchant_public_key() );
			$merchant_features = $this->api_client->get( '/public/merchant', true );

			return isset( $merchant_features['features'] ) && is_array( $merchant_features['features'] ) && in_array(
				WC_GATEWAY_REVPAY_INDEX,
				$merchant_features['features'],
				true
			);
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * Checks if page is pay for order and change subs payment page.
	 *
	 * @return bool
	 */
	public function is_subs_change_payment() {
		return ( isset( $_GET['pay_for_order'] ) && isset( $_GET['change_payment_method'] ) ); // phpcs:ignore
	}

	/**
	 * Unset Revolut public_id
	 */
	protected function unset_revolut_public_id() {
		WC()->session->__unset( "{$this->api_client->mode}_revolut_public_id" );
	}

	/**
	 * Unset Revolut public_id
	 */
	protected function unset_revolut_express_checkout_public_id() {
		WC()->session->__unset( "{$this->api_client->mode}_revolut_express_checkout_public_id" );
	}

	/**
	 * Set Revolut public_id
	 *
	 * @param string $value Revolut public id.
	 */
	protected function set_revolut_public_id( $value ) {
		WC()->session->set( "{$this->api_client->mode}_revolut_public_id", $value );
	}

	/**
	 * Set Revolut public_id
	 *
	 * @param string $value Revolut public id.
	 */
	public function set_revolut_express_checkout_public_id( $value ) {
		WC()->session->set( "{$this->api_client->mode}_revolut_express_checkout_public_id", $value );
	}

	/**
	 * Get Revolut public_id
	 *
	 * @return array|string|null
	 */
	protected function get_revolut_public_id() {
		return WC()->session->get( "{$this->api_client->mode}_revolut_public_id" );
	}

	/**
	 * Get Revolut public_id
	 *
	 * @return array|string|null
	 */
	protected function get_revolut_express_checkout_public_id() {
		return WC()->session->get( "{$this->api_client->mode}_revolut_express_checkout_public_id" );
	}

	/**
	 * Get Revolut Merchant Public Key
	 *
	 * @return array|string|null
	 */
	protected function get_revolut_merchant_public_key() {
		return WC()->session->get( "{$this->api_client->mode}_revolut_merchant_public_key" );
	}

	/**
	 * Set  Revolut Merchant Public Key
	 *
	 * @param string $value Revolut Merchant public Key.
	 */
	protected function set_revolut_merchant_public_key( $value ) {
		WC()->session->set( "{$this->api_client->mode}_revolut_merchant_public_key", $value );
	}

	/**
	 * Replace dashes
	 *
	 * @param mixed $uuid uuid.
	 *
	 * @return string|string[]|null
	 */
	protected function uuid_dashes( $uuid ) {
		if ( is_array( $uuid ) ) {
			if ( isset( $uuid[0] ) ) {
				$uuid = $uuid[0];
			}
		}

		$result = preg_replace( '/(\w{8})(\w{4})(\w{4})(\w{4})(\w{12})/i', '$1-$2-$3-$4-$5', $uuid );

		return $result;
	}

	/**
	 * Check if is not minor currency
	 *
	 * @param string $currency currency.
	 *
	 * @return bool
	 */
	public function is_zero_decimal( $currency ) {
		return 'jpy' === strtolower( $currency );
	}

	/**
	 * Get order total for Api.
	 *
	 * @param float  $order_total order total amount.
	 * @param string $currency currency.
	 */
	public function get_revolut_order_total( $order_total, $currency ) {
		$order_total = round( (float) $order_total, 2 );

		if ( ! $this->is_zero_decimal( $currency ) ) {
			$order_total = round( $order_total * 100 );
		}

		return (int) $order_total;
	}

	/**
	 * Get order total for WC order.
	 *
	 * @param float  $revolut_order_total order total amount.
	 * @param string $currency currency.
	 */
	public function get_wc_order_total( $revolut_order_total, $currency ) {
		$order_total = $revolut_order_total;

		if ( ! $this->is_zero_decimal( $currency ) ) {
			$order_total = round( $order_total / 100, 2 );
		}

		return $order_total;
	}

	/**
	 * Get total amount value from Revolut order.
	 *
	 * @param array $revolut_order Revolut order.
	 */
	public function get_revolut_order_amount( $revolut_order ) {
		return isset( $revolut_order['order_amount'] ) && isset( $revolut_order['order_amount']['value'] ) ? (int) $revolut_order['order_amount']['value'] : 0;
	}

	/**
	 * Get shipping amount value from Revolut order.
	 *
	 * @param array $revolut_order Revolut order.
	 */
	public function get_revolut_order_total_shipping( $revolut_order ) {
		$shipping_total = isset( $revolut_order['delivery_method'] ) && isset( $revolut_order['delivery_method']['amount'] ) ? (int) $revolut_order['delivery_method']['amount'] : 0;
		$currency       = $this->get_revolut_order_currency( $revolut_order );

		if ( $shipping_total ) {
			return $this->get_wc_order_total( $shipping_total, $currency );
		}

		return 0;
	}

	/**
	 * Get currency from Revolut order.
	 *
	 * @param array $revolut_order Revolut order.
	 */
	public function get_revolut_order_currency( $revolut_order ) {
		return isset( $revolut_order['order_amount'] ) && isset( $revolut_order['order_amount']['currency'] ) ? $revolut_order['order_amount']['currency'] : '';
	}

	/**
	 * Get total shipping price.
	 */
	public function get_cart_total_shipping() {
		$cart_totals    = WC()->session->get( 'cart_totals' );
		$shipping_total = 0;
		if ( ! empty( $cart_totals ) && is_array( $cart_totals ) && in_array( 'shipping_total', array_keys( $cart_totals ), true ) ) {
			$shipping_total = $cart_totals['shipping_total'];
		}

		return $this->get_revolut_order_total( $shipping_total, get_woocommerce_currency() );
	}

	/**
	 * Check is data submitted for GET request.
	 *
	 * @param string $submit request key.
	 */
	public function check_is_get_data_submited( $submit ) {
		return isset( $_GET[ $submit ] );  // phpcs:ignore
	}

	/**
	 * Check is data submitted for POST request.
	 *
	 * @param string $submit request key.
	 */
	public function check_is_post_data_submited( $submit ) {
		return isset( $_POST[ $submit ] );  // phpcs:ignore
	}

	/**
	 * Safe get posted integer data
	 *
	 * @param string $post_key request key.
	 */
	public function get_posted_integer_data( $post_key ) {
		if ( ! isset( $_POST[ $post_key ] ) ) { // phpcs:ignore
			return 0;
		}

		return (int) $_POST[ $post_key ];  // phpcs:ignore
	}

	/**
	 * Safe get posted data
	 *
	 * @param string $post_key request key.
	 */
	public function get_post_request_data( $post_key ) {
		if ( ! isset( $_POST[ $post_key ] ) ) { // phpcs:ignore
			return null;
		}

		return $this->recursive_sanitize_text_field( $_POST[ $post_key ]);  // phpcs:ignore
	}

	/**
	 * Safe get request data
	 *
	 * @param string $get_key request key.
	 */
	public function get_request_data( $get_key ) {
		if ( ! isset( $_GET[ $get_key ] ) ) { // phpcs:ignore
			return null;
		}

		return $this->recursive_sanitize_text_field( $_GET[ $get_key ] ); // phpcs:ignore
	}

	/**
	 * Clear data.
	 *
	 * @param mixed $var data for cleaning.
	 */
	public function recursive_sanitize_text_field( $var ) {
		if ( is_array( $var ) ) {
			return array_map( array( $this, 'recursive_sanitize_text_field' ), $var );
		} else {
			return sanitize_text_field( wp_unslash( $var ) );
		}
	}

	/**
	 * Get two-digit language iso code.
	 */
	public function get_lang_iso_code() {
		return substr( get_locale(), 0, 2 );
	}
}
