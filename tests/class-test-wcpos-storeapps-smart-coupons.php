<?php

use WCPOS\StoreAppsSmartCoupons\Plugin;

if ( ! defined( 'WC_SC_PLUGIN_FILE' ) ) {
	define( 'WC_SC_PLUGIN_FILE', __FILE__ );
}

add_filter(
	'woocommerce_coupon_discount_types',
	static function ( array $discount_types ): array {
		$discount_types['smart_coupon'] = 'Store Credit';
		return $discount_types;
	}
);

class Test_Wcpos_Storeapps_Smart_Coupons extends WP_UnitTestCase {
	private function create_store_credit_coupon( string $code, string $amount, string $original_amount = '', string $description = '' ): WC_Coupon {
		$coupon = new WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'smart_coupon' );
		$coupon->set_amount( $amount );
		if ( '' !== $description ) {
			$coupon->set_description( $description );
		}
		if ( '' !== $original_amount ) {
			$coupon->update_meta_data( 'wc_sc_original_amount', $original_amount );
		}
		$coupon->save();

		return $coupon;
	}

	private function create_fixed_cart_coupon( string $code, string $amount ): WC_Coupon {
		$coupon = new WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'fixed_cart' );
		$coupon->set_amount( $amount );
		$coupon->save();

		return $coupon;
	}

	private function create_pos_order_with_coupon( string $code, string $coupon_discount, string $coupon_discount_tax = '0' ): WC_Order {
		$order = new WC_Order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->save();

		$item = new WC_Order_Item_Coupon();
		$item->set_code( $code );
		$item->set_discount( $coupon_discount );
		$item->set_discount_tax( $coupon_discount_tax );
		$order->add_item( $item );

		return $order;
	}

	private function add_product_line( WC_Order $order, string $subtotal, string $total, string $subtotal_tax = '0', string $total_tax = '0' ): void {
		$item = new WC_Order_Item_Product();
		$item->set_name( 'Test product' );
		$item->set_quantity( 1 );
		$item->set_subtotal( $subtotal );
		$item->set_total( $total );
		$item->set_subtotal_tax( $subtotal_tax );
		$item->set_total_tax( $total_tax );
		$order->add_item( $item );
	}

	private function add_fee_line( WC_Order $order, string $total, string $total_tax = '0' ): void {
		$item = new WC_Order_Item_Fee();
		$item->set_name( 'Manager discount' );
		$item->set_total( $total );
		$item->set_total_tax( $total_tax );
		$order->add_item( $item );
	}

	/**
	 * @dataProvider explicit_coupon_discount_provider
	 */
	public function test_pos_order_coupon_lines_capture_explicit_smart_coupon_usage( string $coupon_discount, string $coupon_discount_tax, float $expected ): void {
		$this->create_store_credit_coupon( 'STORE100', '100', '', 'Gift card' );
		$order = $this->create_pos_order_with_coupon( 'STORE100', $coupon_discount, $coupon_discount_tax );

		Plugin::instance()->capture_pos_order_contribution( true, $order );

		$this->assertEquals(
			array( 'store100' => $expected ),
			$order->get_meta( 'smart_coupons_contribution' )
		);
	}

	public function explicit_coupon_discount_provider(): array {
		return array(
			'positive discount'               => array( '35', '0', 35.0 ),
			'negative POS discount'           => array( '-35', '0', 35.0 ),
			'negative discount including tax' => array( '-30', '-5', 35.0 ),
		);
	}

	public function test_pos_order_zero_coupon_line_uses_order_discount_total_for_single_smart_coupon(): void {
		$this->create_store_credit_coupon( 'STORE100', '100' );
		$order = $this->create_pos_order_with_coupon( 'STORE100', '0', '0' );
		$order->set_discount_total( '35' );
		$order->set_discount_tax( '0' );

		Plugin::instance()->capture_pos_order_contribution( true, $order );

		$this->assertEquals(
			array( 'store100' => 35.0 ),
			$order->get_meta( 'smart_coupons_contribution' )
		);
	}

	public function test_pos_order_zero_coupon_line_can_infer_full_store_credit_payment_from_reduced_order_total(): void {
		$this->create_store_credit_coupon( 'STORE100', '100' );
		$order = $this->create_pos_order_with_coupon( 'STORE100', '0', '0' );
		$this->add_product_line( $order, '35', '35' );
		$order->set_total( '0' );

		Plugin::instance()->capture_pos_order_contribution( true, $order );

		$this->assertEquals(
			array( 'store100' => 35.0 ),
			$order->get_meta( 'smart_coupons_contribution' )
		);
	}

	public function test_pos_order_zero_coupon_line_preserves_negative_fees_when_inferring_from_reduced_order_total(): void {
		$this->create_store_credit_coupon( 'STORE100', '100' );
		$order = $this->create_pos_order_with_coupon( 'STORE100', '0', '0' );
		$this->add_product_line( $order, '100', '100' );
		$this->add_fee_line( $order, '-10' );
		$order->set_total( '40' );

		Plugin::instance()->capture_pos_order_contribution( true, $order );

		$this->assertEquals(
			array( 'store100' => 50.0 ),
			$order->get_meta( 'smart_coupons_contribution' )
		);
	}

	public function test_pos_order_zero_coupon_line_infers_tax_exclusive_credit_when_smart_coupons_apply_before_tax(): void {
		update_option( 'woocommerce_smart_coupon_apply_before_tax', 'yes' );

		try {
			$this->create_store_credit_coupon( 'STORE100', '100' );
			$order = $this->create_pos_order_with_coupon( 'STORE100', '0', '0' );
			$this->add_product_line( $order, '100', '50', '20', '10' );
			$order->set_cart_tax( '10' );
			$order->set_total( '60' );

			Plugin::instance()->capture_pos_order_contribution( true, $order );

			$this->assertEquals(
				array( 'store100' => 50.0 ),
				$order->get_meta( 'smart_coupons_contribution' )
			);
		} finally {
			update_option( 'woocommerce_smart_coupon_apply_before_tax', 'no' );
		}
	}

	public function test_pos_order_zero_coupon_lines_do_not_guess_when_multiple_store_credit_coupons_are_ambiguous(): void {
		$this->create_store_credit_coupon( 'STORE50A', '50' );
		$this->create_store_credit_coupon( 'STORE50B', '50' );

		$order = $this->create_pos_order_with_coupon( 'STORE50A', '0', '0' );
		$item  = new WC_Order_Item_Coupon();
		$item->set_code( 'STORE50B' );
		$item->set_discount( '0' );
		$item->set_discount_tax( '0' );
		$order->add_item( $item );
		$order->set_discount_total( '35' );

		Plugin::instance()->capture_pos_order_contribution( true, $order );

		$this->assertSame( '', $order->get_meta( 'smart_coupons_contribution' ) );
	}

	public function test_pos_order_zero_store_credit_line_subtracts_regular_coupon_discount_from_order_discount_total(): void {
		$this->create_store_credit_coupon( 'STORE100', '100' );
		$this->create_fixed_cart_coupon( 'SAVE20', '20' );

		$order = $this->create_pos_order_with_coupon( 'STORE100', '0', '0' );
		$item  = new WC_Order_Item_Coupon();
		$item->set_code( 'SAVE20' );
		$item->set_discount( '20' );
		$item->set_discount_tax( '0' );
		$order->add_item( $item );
		$order->set_discount_total( '55' );

		Plugin::instance()->capture_pos_order_contribution( true, $order );

		$this->assertEquals(
			array( 'store100' => 35.0 ),
			$order->get_meta( 'smart_coupons_contribution' )
		);
	}

	public function test_pos_order_zero_store_credit_line_does_not_infer_when_regular_coupon_discount_is_also_zero(): void {
		$this->create_store_credit_coupon( 'STORE100', '100' );
		$this->create_fixed_cart_coupon( 'SAVE20', '20' );

		$order = $this->create_pos_order_with_coupon( 'STORE100', '0', '0' );
		$item  = new WC_Order_Item_Coupon();
		$item->set_code( 'SAVE20' );
		$item->set_discount( '0' );
		$item->set_discount_tax( '0' );
		$order->add_item( $item );
		$order->set_discount_total( '55' );

		Plugin::instance()->capture_pos_order_contribution( true, $order );

		$this->assertSame( '', $order->get_meta( 'smart_coupons_contribution' ) );
	}

	public function test_pos_order_zero_store_credit_line_allows_zero_amount_regular_coupon_with_order_discount_total(): void {
		$this->create_store_credit_coupon( 'STORE100', '100' );
		$this->create_fixed_cart_coupon( 'FREESHIP', '0' );

		$order = $this->create_pos_order_with_coupon( 'STORE100', '0', '0' );
		$item  = new WC_Order_Item_Coupon();
		$item->set_code( 'FREESHIP' );
		$item->set_discount( '0' );
		$item->set_discount_tax( '0' );
		$order->add_item( $item );
		$order->set_discount_total( '35' );

		Plugin::instance()->capture_pos_order_contribution( true, $order );

		$this->assertEquals(
			array( 'store100' => 35.0 ),
			$order->get_meta( 'smart_coupons_contribution' )
		);
	}

	/**
	 * @dataProvider storeapps_balance_regression_provider
	 */
	public function test_storeapps_balance_update_uses_positive_pos_contribution( string $coupon_discount, string $order_discount_total, float $expected_balance ): void {
		if ( ! class_exists( 'WC_SC_Coupon_Process' ) || ! is_callable( array( 'WC_SC_Coupon_Process', 'get_instance' ) ) ) {
			$this->markTestSkipped( 'StoreApps Smart Coupons source is not available in this test environment.' );
		}

		$this->create_store_credit_coupon( 'STORE10', '10' );
		$order = $this->create_pos_order_with_coupon( 'STORE10', $coupon_discount, '0' );
		$order->set_discount_total( $order_discount_total );

		Plugin::instance()->capture_pos_order_contribution( true, $order );
		$order->save();

		WC_SC_Coupon_Process::get_instance()->update_smart_coupon_balance( $order->get_id() );

		$this->assertEquals( $expected_balance, (float) ( new WC_Coupon( 'STORE10' ) )->get_amount() );
	}

	public function storeapps_balance_regression_provider(): array {
		return array(
			'positive POS discount subtracts' => array( '2', '2', 8.0 ),
			'negative POS discount subtracts' => array( '-2', '0', 8.0 ),
			'zero POS discount subtracts'     => array( '0', '2', 8.0 ),
		);
	}

	public function test_pos_order_coupon_lines_create_smart_coupons_contribution_meta(): void {
		$coupon = $this->create_store_credit_coupon( 'STORE100', '100', '', 'Gift card' );
		$order  = $this->create_pos_order_with_coupon( 'STORE100', '35', '0' );

		Plugin::instance()->capture_pos_order_contribution( true, $order );

		$this->assertEquals(
			array( 'store100' => 35.0 ),
			$order->get_meta( 'smart_coupons_contribution' )
		);
		$this->assertEquals( 'no', $order->get_meta( 'wc_sc_environment' )['apply_before_tax'] );

		$order->save();
		Plugin::instance()->add_store_credit_audit_note_after_status_change( $order->get_id(), 'pending', 'completed', $order );

		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$this->assertCount( 1, $notes );
		$this->assertStringContainsString( 'StoreApps Smart Coupons store credit recorded for WCPOS', $notes[0]->content );
		$this->assertStringContainsString( 'store100', $notes[0]->content );
		$this->assertStringContainsString( '35', $notes[0]->content );

		$coupon->set_amount( '65' );
		$coupon->save();

		Plugin::instance()->set_receipt_order_context( $order->get_id(), $order );
		try {
			$receipt_coupon_description = ( new WC_Coupon( 'STORE100' ) )->get_description();
		} finally {
			Plugin::instance()->clear_receipt_order_context();
		}

		$this->assertStringContainsString( 'Gift card', $receipt_coupon_description );
		$this->assertStringContainsString( 'Store credit balance:', $receipt_coupon_description );
		$this->assertStringContainsString( '65', $receipt_coupon_description );
	}
}
