<?php
/**
 * Main plugin class.
 *
 * @package WCPOS\StoreAppsSmartCoupons
 */

namespace WCPOS\StoreAppsSmartCoupons;

use WC_Coupon;
use WC_Order;
use WC_Order_Item_Coupon;
use WP_REST_Request;
use WP_REST_Response;

/**
 * StoreApps Smart Coupons compatibility hooks for WCPOS.
 */
class Plugin {
	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_filter( 'woocommerce_rest_prepare_shop_coupon_object', array( $this, 'add_store_credit_fields' ), 20, 3 );
		add_action( 'woocommerce_order_after_calculate_totals', array( $this, 'capture_pos_order_contribution' ), 30, 2 );
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'wcpos-storeapps-smart-coupons', false, dirname( plugin_basename( dirname( __DIR__ ) . '/wcpos-storeapps-smart-coupons.php' ) ) . '/languages' );
	}

	/**
	 * Add StoreApps store-credit metadata to WCPOS coupon responses.
	 *
	 * @param WP_REST_Response $response Coupon response.
	 * @param WC_Coupon        $coupon   Coupon object.
	 * @param WP_REST_Request  $request  REST request.
	 * @return WP_REST_Response
	 */
	public function add_store_credit_fields( WP_REST_Response $response, WC_Coupon $coupon, WP_REST_Request $request ): WP_REST_Response {
		if ( ! $this->is_wcpos_request( $request ) ) {
			return $response;
		}

		if ( ! $this->is_storeapps_available() || ! $this->is_store_credit_coupon( $coupon ) ) {
			return $response;
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$data['wcpos_storeapps_smart_coupon'] = array(
			'is_store_credit' => true,
			'balance'         => wc_format_decimal( $this->get_coupon_amount( $coupon ) ),
			'original_amount' => wc_format_decimal( $this->get_coupon_original_amount( $coupon ) ),
		);

		$response->set_data( $data );
		return $response;
	}

	/**
	 * Capture StoreApps' smart_coupons_contribution meta for POS REST orders.
	 *
	 * StoreApps normally populates this from WC()->cart during checkout. WCPOS
	 * creates orders through REST, so the cart contribution can be absent even
	 * though coupon line totals were calculated correctly.
	 *
	 * @param bool     $and_taxes Whether taxes were calculated.
	 * @param WC_Order $order     Order object.
	 */
	public function capture_pos_order_contribution( $and_taxes, $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( ! $this->is_pos_order( $order ) ) {
			return;
		}

		if ( ! $this->is_storeapps_available() ) {
			return;
		}

		$existing = $order->get_meta( 'smart_coupons_contribution', true );
		if ( is_array( $existing ) && ! empty( $existing ) ) {
			return;
		}

		$contribution = array();
		foreach ( $order->get_items( 'coupon' ) as $coupon_item ) {
			if ( ! $coupon_item instanceof WC_Order_Item_Coupon ) {
				continue;
			}

			$code = $coupon_item->get_code();
			if ( '' === $code ) {
				continue;
			}

			$coupon = new WC_Coupon( $code );
			if ( ! $this->is_store_credit_coupon( $coupon ) ) {
				continue;
			}

			$used = (float) $coupon_item->get_discount();
			if ( $this->store_credit_includes_tax( $order ) ) {
				$used += (float) $coupon_item->get_discount_tax();
			}

			if ( $used <= 0 ) {
				continue;
			}

			$contribution[ wc_format_coupon_code( $code ) ] = (float) wc_format_decimal( $used );
		}

		if ( empty( $contribution ) ) {
			return;
		}

		$order->update_meta_data( 'smart_coupons_contribution', $contribution );
		$order->update_meta_data(
			'wc_sc_environment',
			array(
				'sc_version'       => $this->get_storeapps_version(),
				'apply_before_tax' => get_option( 'woocommerce_smart_coupon_apply_before_tax', 'no' ),
			)
		);
	}

	/**
	 * Check whether request is for WCPOS REST namespace.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool
	 */
	private function is_wcpos_request( WP_REST_Request $request ): bool {
		return 0 === strpos( $request->get_route(), '/wcpos/v1/' );
	}

	/**
	 * Check whether StoreApps Smart Coupons is active enough to expose behavior.
	 *
	 * @return bool
	 */
	private function is_storeapps_available(): bool {
		return class_exists( 'WC_Smart_Coupons' ) || defined( 'WC_SC_PLUGIN_FILE' );
	}

	/**
	 * Check whether a coupon is a StoreApps store-credit coupon.
	 *
	 * @param WC_Coupon $coupon Coupon object.
	 * @return bool
	 */
	private function is_store_credit_coupon( WC_Coupon $coupon ): bool {
		return is_callable( array( $coupon, 'is_type' ) ) && $coupon->is_type( 'smart_coupon' );
	}

	/**
	 * Get current coupon balance.
	 *
	 * @param WC_Coupon $coupon Coupon object.
	 * @return float
	 */
	private function get_coupon_amount( WC_Coupon $coupon ): float {
		global $woocommerce_smart_coupon;

		if ( is_object( $woocommerce_smart_coupon ) && is_callable( array( $woocommerce_smart_coupon, 'get_amount' ) ) ) {
			return (float) $woocommerce_smart_coupon->get_amount( $coupon );
		}

		return (float) $coupon->get_amount();
	}

	/**
	 * Get original coupon amount when StoreApps has recorded it.
	 *
	 * @param WC_Coupon $coupon Coupon object.
	 * @return float
	 */
	private function get_coupon_original_amount( WC_Coupon $coupon ): float {
		$original_amount = $coupon->get_meta( 'wc_sc_original_amount', true );
		if ( '' !== $original_amount && null !== $original_amount ) {
			return (float) $original_amount;
		}

		return $this->get_coupon_amount( $coupon );
	}

	/**
	 * Determine whether an order was created by WCPOS.
	 *
	 * @param WC_Order $order Order object.
	 * @return bool
	 */
	private function is_pos_order( WC_Order $order ): bool {
		if ( function_exists( 'wcpos_is_pos_order' ) ) {
			return wcpos_is_pos_order( $order );
		}

		if ( function_exists( 'woocommerce_pos_is_pos_order' ) ) {
			return woocommerce_pos_is_pos_order( $order );
		}

		return 'woocommerce-pos' === $order->get_created_via() || '1' === $order->get_meta( '_pos', true );
	}

	/**
	 * Determine whether StoreApps includes tax in stored-credit usage.
	 *
	 * @param WC_Order $order Order object.
	 * @return bool
	 */
	private function store_credit_includes_tax( WC_Order $order ): bool {
		$environment = $order->get_meta( 'wc_sc_environment', true );
		$before_tax  = is_array( $environment ) && isset( $environment['apply_before_tax'] )
			? $environment['apply_before_tax']
			: get_option( 'woocommerce_smart_coupon_apply_before_tax', 'no' );

		return 'yes' !== $before_tax;
	}

	/**
	 * Get StoreApps plugin version if available.
	 *
	 * @return string
	 */
	private function get_storeapps_version(): string {
		global $woocommerce_smart_coupon;

		if ( is_object( $woocommerce_smart_coupon ) && isset( $woocommerce_smart_coupon->plugin_data['Version'] ) ) {
			return (string) $woocommerce_smart_coupon->plugin_data['Version'];
		}

		return '';
	}
}
