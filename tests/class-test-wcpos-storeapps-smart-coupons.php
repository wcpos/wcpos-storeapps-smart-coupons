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
	private function create_store_credit_coupon( string $code, string $amount, string $original_amount = '' ): WC_Coupon {
		$coupon = new WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'smart_coupon' );
		$coupon->set_amount( $amount );
		if ( '' !== $original_amount ) {
			$coupon->update_meta_data( 'wc_sc_original_amount', $original_amount );
		}
		$coupon->save();

		return $coupon;
	}

	public function test_coupon_response_marks_storeapps_store_credit(): void {
		$coupon   = $this->create_store_credit_coupon( 'STORE100', '100', '150' );
		$response = new WP_REST_Response(
			array(
				'id'            => $coupon->get_id(),
				'code'          => $coupon->get_code(),
				'discount_type' => $coupon->get_discount_type(),
			)
		);
		$request  = new WP_REST_Request( 'GET', '/wcpos/v1/coupons/' . $coupon->get_id() );

		$result = Plugin::instance()->add_store_credit_fields( $response, $coupon, $request );
		$data   = $result->get_data();

		$this->assertTrue( $data['wcpos_storeapps_smart_coupon']['is_store_credit'] );
		$this->assertEquals( '100', $data['wcpos_storeapps_smart_coupon']['balance'] );
		$this->assertEquals( '150', $data['wcpos_storeapps_smart_coupon']['original_amount'] );
	}

	public function test_non_store_credit_coupon_response_is_unchanged(): void {
		$coupon = new WC_Coupon();
		$coupon->set_code( 'FIXED10' );
		$coupon->set_discount_type( 'fixed_cart' );
		$coupon->set_amount( '10' );
		$coupon->save();

		$response = new WP_REST_Response( array( 'id' => $coupon->get_id() ) );
		$request  = new WP_REST_Request( 'GET', '/wcpos/v1/coupons/' . $coupon->get_id() );

		$result = Plugin::instance()->add_store_credit_fields( $response, $coupon, $request );
		$data   = $result->get_data();

		$this->assertArrayNotHasKey( 'wcpos_storeapps_smart_coupon', $data );
	}

	public function test_pos_order_coupon_lines_create_smart_coupons_contribution_meta(): void {
		$this->create_store_credit_coupon( 'STORE100', '100' );

		$order = new WC_Order();
		$order->set_created_via( 'woocommerce-pos' );

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
	}
}
