<?php
/**
 * Plugin Name: WCPOS StoreApps Smart Coupons
 * Description: StoreApps Smart Coupons store credit compatibility for WCPOS.
 * Version: 0.1.0
 * Author: kilbot
 * Update URI: https://github.com/wcpos/wcpos-storeapps-smart-coupons
 * Requires Plugins: woocommerce, woocommerce-pos, woocommerce-smart-coupons
 * Text Domain: wcpos-storeapps-smart-coupons
 */

namespace WCPOS\StoreAppsSmartCoupons;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION = '0.1.0';

require_once __DIR__ . '/includes/class-plugin.php';

Plugin::instance();
