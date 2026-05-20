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
		add_action( 'woocommerce_order_after_calculate_totals', array( $this, 'capture_pos_order_contribution' ), 30, 2 );
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'wcpos-storeapps-smart-coupons', false, dirname( plugin_basename( dirname( __DIR__ ) . '/wcpos-storeapps-smart-coupons.php' ) ) . '/languages' );
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
