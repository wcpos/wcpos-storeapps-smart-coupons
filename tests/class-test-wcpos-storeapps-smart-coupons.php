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

	public function test_pos_order_coupon_lines_create_smart_coupons_contribution_meta(): void {
		$coupon = $this->create_store_credit_coupon( 'STORE100', '100', '', 'Gift card' );

		$order = new WC_Order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->save();

		$item = new WC_Order_Item_Coupon();
		$item->set_code( 'STORE100' );
		$item->set_discount( '35' );
		$item->set_discount_tax( '0' );
		$order->add_item( $item );

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
