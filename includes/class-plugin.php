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
	 * Order currently being rendered for a WCPOS receipt.
	 *
	 * @var WC_Order|null
	 */
	private $receipt_order = null;

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
		add_action( 'woocommerce_order_status_changed', array( $this, 'add_store_credit_audit_note_after_status_change' ), 30, 4 );
		add_action( 'woocommerce_pos_before_template_render', array( $this, 'set_receipt_order_context' ), 10, 2 );
		add_action( 'woocommerce_pos_after_template_render', array( $this, 'clear_receipt_order_context' ) );
		add_filter( 'rest_request_before_callbacks', array( $this, 'set_rest_receipt_order_context' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( $this, 'clear_rest_receipt_order_context' ), 10, 3 );
		add_filter( 'woocommerce_coupon_get_description', array( $this, 'append_store_credit_receipt_label' ), 10, 2 );
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

		$contribution       = array();
		$smart_coupon_items = array();
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

			$smart_coupon_items[] = $coupon_item;
			$used                 = abs( (float) $coupon_item->get_discount() );
			if ( $this->store_credit_includes_tax( $order ) ) {
				$used += abs( (float) $coupon_item->get_discount_tax() );
			}

			if ( $used <= 0 ) {
				$used = $this->infer_single_store_credit_usage( $order, $coupon, $smart_coupon_items );
			}

			if ( $used <= 0 ) {
				continue;
			}

			$formatted_code                  = wc_format_coupon_code( $code );
			$contribution[ $formatted_code ] = (float) wc_format_decimal( $used );
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
	 * Infer usage for POS payloads that include a smart-coupon line but no line discount.
	 *
	 * Some POS order payloads carry coupon discounts as zero or signed values even
	 * when the order value was paid by store credit. StoreApps requires a positive
	 * smart_coupons_contribution amount. Only infer a zero line when there is a
	 * single smart-coupon line, because splitting an order-level discount across
	 * multiple store-credit coupons would be guesswork.
	 *
	 * @param WC_Order              $order              Order object.
	 * @param WC_Coupon             $coupon             Store credit coupon.
	 * @param WC_Order_Item_Coupon[] $smart_coupon_items Smart coupon lines seen so far.
	 * @return float
	 */
	private function infer_single_store_credit_usage( WC_Order $order, WC_Coupon $coupon, array $smart_coupon_items ): float {
		if ( 1 !== count( $smart_coupon_items ) || $this->order_has_multiple_store_credit_coupons( $order ) ) {
			return 0.0;
		}

		if ( $this->order_has_ambiguous_zero_value_non_store_credit_coupon( $order ) ) {
			return 0.0;
		}

		$non_store_credit_usage = $this->get_non_store_credit_coupon_usage( $order );
		$used                   = abs( (float) $order->get_discount_total() );
		if ( $this->store_credit_includes_tax( $order ) ) {
			$used += abs( (float) $order->get_discount_tax() );
		}
		$used -= $non_store_credit_usage;

		if ( $used <= 0 ) {
			$used = $this->infer_store_credit_usage_from_reduced_order_total( $order ) - $non_store_credit_usage;
		}

		if ( $used <= 0 ) {
			return 0.0;
		}

		return min( $used, abs( (float) $coupon->get_amount() ) );
	}

	/**
	 * Check if the order has more than one StoreApps store-credit coupon line.
	 *
	 * @param WC_Order $order Order object.
	 * @return bool
	 */
	private function order_has_multiple_store_credit_coupons( WC_Order $order ): bool {
		$count = 0;

		foreach ( $order->get_items( 'coupon' ) as $coupon_item ) {
			if ( ! $coupon_item instanceof WC_Order_Item_Coupon ) {
				continue;
			}

			$coupon = new WC_Coupon( $coupon_item->get_code() );
			if ( $this->is_store_credit_coupon( $coupon ) ) {
				++$count;
			}

			if ( $count > 1 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether a non-store-credit coupon line has hidden discount usage.
	 *
	 * POS payloads can include zero-value coupons such as free-shipping codes
	 * alongside the store-credit coupon. Those are not ambiguous when the coupon
	 * has no monetary amount; order-level discount totals can still be attributed
	 * to the single store-credit line. A zero line for a monetary coupon is
	 * ambiguous because the order-level discount could include that coupon's
	 * hidden usage.
	 *
	 * @param WC_Order $order Order object.
	 * @return bool
	 */
	private function order_has_ambiguous_zero_value_non_store_credit_coupon( WC_Order $order ): bool {
		foreach ( $order->get_items( 'coupon' ) as $coupon_item ) {
			if ( ! $coupon_item instanceof WC_Order_Item_Coupon ) {
				continue;
			}

			$coupon = new WC_Coupon( $coupon_item->get_code() );
			if ( $this->is_store_credit_coupon( $coupon ) ) {
				continue;
			}

			$used = abs( (float) $coupon_item->get_discount() );
			if ( $this->store_credit_includes_tax( $order ) ) {
				$used += abs( (float) $coupon_item->get_discount_tax() );
			}

			if ( $used <= 0 && abs( (float) $coupon->get_amount() ) > 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get coupon usage already attributable to non-store-credit coupon lines.
	 *
	 * @param WC_Order $order Order object.
	 * @return float
	 */
	private function get_non_store_credit_coupon_usage( WC_Order $order ): float {
		$used = 0.0;

		foreach ( $order->get_items( 'coupon' ) as $coupon_item ) {
			if ( ! $coupon_item instanceof WC_Order_Item_Coupon ) {
				continue;
			}

			$coupon = new WC_Coupon( $coupon_item->get_code() );
			if ( $this->is_store_credit_coupon( $coupon ) ) {
				continue;
			}

			$used += abs( (float) $coupon_item->get_discount() );
			if ( $this->store_credit_includes_tax( $order ) ) {
				$used += abs( (float) $coupon_item->get_discount_tax() );
			}
		}

		return $used;
	}

	/**
	 * Infer store credit usage from an order total already reduced by coupons.
	 *
	 * @param WC_Order $order Order object.
	 * @return float
	 */
	private function infer_store_credit_usage_from_reduced_order_total( WC_Order $order ): float {
		$pre_coupon_total = 0.0;

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$pre_coupon_total += abs( (float) $item->get_subtotal() );
			if ( $this->store_credit_includes_tax( $order ) ) {
				$pre_coupon_total += abs( (float) $item->get_subtotal_tax() );
			}
		}

		foreach ( $order->get_items( 'shipping' ) as $item ) {
			$pre_coupon_total += abs( (float) $item->get_total() );
			if ( $this->store_credit_includes_tax( $order ) ) {
				$pre_coupon_total += abs( (float) $item->get_total_tax() );
			}
		}

		foreach ( $order->get_items( 'fee' ) as $item ) {
			$pre_coupon_total += (float) $item->get_total();
			if ( $this->store_credit_includes_tax( $order ) ) {
				$pre_coupon_total += (float) $item->get_total_tax();
			}
		}

		$remaining_total = abs( (float) $order->get_total() );
		if ( ! $this->store_credit_includes_tax( $order ) ) {
			$remaining_total -= abs( (float) $order->get_total_tax() );
		}

		$used = $pre_coupon_total - $remaining_total;

		return $used > 0 ? $used : 0.0;
	}

	/**
	 * Add a private order note after StoreApps has processed a POS store-credit status change.
	 *
	 * @param int      $order_id   Order ID.
	 * @param string   $old_status Previous order status.
	 * @param string   $new_status New order status.
	 * @param WC_Order $order      Order object.
	 */
	public function add_store_credit_audit_note_after_status_change( $order_id, $old_status, $new_status, $order ): void {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}

		if ( ! $order instanceof WC_Order || ! $this->is_pos_order( $order ) || ! $this->is_storeapps_available() ) {
			return;
		}

		if ( ! in_array( $new_status, array( 'processing', 'completed', 'on-hold' ), true ) ) {
			return;
		}

		if ( 'yes' === $order->get_meta( '_wcpos_storeapps_smart_coupons_audit_note_added', true ) ) {
			return;
		}

		$contribution = $order->get_meta( 'smart_coupons_contribution', true );
		if ( empty( $contribution ) || ! is_array( $contribution ) ) {
			return;
		}

		$lines = array();
		foreach ( $contribution as $code => $used ) {
			$coupon = new WC_Coupon( $code );
			if ( ! $this->is_store_credit_coupon( $coupon ) ) {
				continue;
			}

			$lines[] = sprintf(
				/* translators: 1: coupon code, 2: used amount, 3: current coupon balance */
				__( 'Coupon %1$s used %2$s. Current balance: %3$s.', 'wcpos-storeapps-smart-coupons' ),
				'<code>' . esc_html( wc_format_coupon_code( $code ) ) . '</code>',
				'<strong>' . wp_kses_post( $this->format_order_price( $order, (float) $used ) ) . '</strong>',
				wp_kses_post( $this->format_order_price( $order, (float) $coupon->get_amount() ) )
			);
		}

		if ( empty( $lines ) ) {
			return;
		}

		$order->add_order_note(
			sprintf(
				/* translators: %s: store-credit audit lines */
				__( 'StoreApps Smart Coupons store credit recorded for WCPOS: %s', 'wcpos-storeapps-smart-coupons' ),
				implode( ' ', $lines )
			)
		);
		$order->update_meta_data( '_wcpos_storeapps_smart_coupons_audit_note_added', 'yes' );
		$order->save_meta_data();
	}

	/**
	 * Format an amount using the order currency.
	 *
	 * @param WC_Order $order  Order object.
	 * @param float    $amount Amount.
	 * @return string
	 */
	private function format_order_price( WC_Order $order, float $amount ): string {
		return wc_price(
			$amount,
			array(
				'currency' => $order->get_currency(),
			)
		);
	}

	/**
	 * Set receipt context while WCPOS renders a server-side receipt template.
	 *
	 * @param int      $order_id Order ID.
	 * @param WC_Order $order    Order object.
	 */
	public function set_receipt_order_context( $order_id, $order ): void {
		if ( $order instanceof WC_Order ) {
			$this->receipt_order = $order;
			return;
		}

		$order               = wc_get_order( $order_id );
		$this->receipt_order = $order instanceof WC_Order ? $order : null;
	}

	/**
	 * Clear receipt context after WCPOS renders a server-side receipt template.
	 */
	public function clear_receipt_order_context(): void {
		$this->receipt_order = null;
	}

	/**
	 * Set receipt context while WCPOS REST receipt data is being built.
	 *
	 * @param mixed            $response Current REST response.
	 * @param array            $handler  Route handler.
	 * @param \WP_REST_Request $request  REST request.
	 * @return mixed
	 */
	public function set_rest_receipt_order_context( $response, $handler, $request ) {
		unset( $handler );

		if ( ! $request instanceof \WP_REST_Request || 0 !== strpos( $request->get_route(), '/wcpos/v1/receipts/' ) ) {
			return $response;
		}

		$order_id = (int) $request->get_param( 'order_id' );
		if ( ! $order_id ) {
			$order_id = (int) $request->get_param( 'id' );
		}

		$order               = $order_id ? wc_get_order( $order_id ) : null;
		$this->receipt_order = $order instanceof WC_Order ? $order : null;

		return $response;
	}

	/**
	 * Clear receipt context after WCPOS REST receipt data is built.
	 *
	 * @param mixed            $response Current REST response.
	 * @param array            $handler  Route handler.
	 * @param \WP_REST_Request $request  REST request.
	 * @return mixed
	 */
	public function clear_rest_receipt_order_context( $response, $handler, $request ) {
		unset( $handler );

		if ( $request instanceof \WP_REST_Request && 0 === strpos( $request->get_route(), '/wcpos/v1/receipts/' ) ) {
			$this->receipt_order = null;
		}

		return $response;
	}

	/**
	 * Append StoreApps store-credit balance text to coupon descriptions during WCPOS receipt rendering.
	 *
	 * WCPOS receipt templates already render coupon descriptions in the discount
	 * label. This keeps the integration inside the existing coupon/discount
	 * framework instead of adding receipt-template-specific data.
	 *
	 * @param string    $description Coupon description.
	 * @param WC_Coupon $coupon      Coupon object.
	 * @return string
	 */
	public function append_store_credit_receipt_label( $description, $coupon ): string {
		if ( ! $this->receipt_order instanceof WC_Order || ! $coupon instanceof WC_Coupon ) {
			return (string) $description;
		}

		if ( ! $this->is_pos_order( $this->receipt_order ) || ! $this->is_storeapps_available() || ! $this->is_store_credit_coupon( $coupon ) ) {
			return (string) $description;
		}

		$contribution = $this->receipt_order->get_meta( 'smart_coupons_contribution', true );
		if ( empty( $contribution ) || ! is_array( $contribution ) ) {
			return (string) $description;
		}

		$code = wc_format_coupon_code( $coupon->get_code() );
		if ( ! array_key_exists( $code, $contribution ) ) {
			return (string) $description;
		}

		$balance_label = sprintf(
			/* translators: %s: current store-credit balance */
			__( 'Store credit balance: %s', 'wcpos-storeapps-smart-coupons' ),
			$this->format_order_price_plain( $this->receipt_order, (float) $coupon->get_amount() )
		);

		$description = trim( wp_strip_all_tags( (string) $description ) );
		if ( '' !== $description && false !== strpos( $description, $balance_label ) ) {
			return $description;
		}

		return '' === $description ? $balance_label : $description . ' — ' . $balance_label;
	}

	/**
	 * Format an amount for text-only receipt labels.
	 *
	 * @param WC_Order $order  Order object.
	 * @param float    $amount Amount.
	 * @return string
	 */
	private function format_order_price_plain( WC_Order $order, float $amount ): string {
		return html_entity_decode(
			wp_strip_all_tags( $this->format_order_price( $order, $amount ) ),
			ENT_QUOTES | ENT_SUBSTITUTE,
			'UTF-8'
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
