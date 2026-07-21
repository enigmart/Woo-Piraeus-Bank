<?php
/*
  Plugin Name: Piraeus Bank WooCommerce Payment Gateway
  Plugin URI: https://www.enigmart.com
  Description: Piraeus Bank Payment Gateway allows you to accept payment through various channels such as Maestro, Mastercard, AMex cards, Diners  and Visa cards On your Woocommerce Powered Site.
  Version: 3.2.0
  Author: enigmart
  Author URI: https://www.enigmart.com
  License: GPL-3.0+
  License URI: http://www.gnu.org/licenses/gpl-3.0.txt
  WC tested up to: 10.4.2
  Text Domain: woo-payment-gateway-for-piraeus-bank
  Domain Path: /languages
*/
/*
Based on original plugin "Piraeus Bank Greece Payment Gateway for WooCommerce" by emspace.gr [https://wordpress.org/plugins/woo-payment-gateway-piraeus-bank-greece/]
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'plugins_loaded', function () {
    if ( ! class_exists( '\WC_Payment_Gateway' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p>';
            echo __( 'Piraeus Bank Payment Gateway requires WooCommerce to be installed and active.', 'woo-payment-gateway-for-piraeus-bank' );
            echo '</p></div>';
        } );
        return;
    }

    spl_autoload_register( function ( $class ) {
        $prefix   = 'Papaki\\PiraeusBank\\WooCommerce\\';
        $base_dir = plugin_dir_path( __FILE__ ) . 'classes/';

        $len = strlen( $prefix );
        if ( strncmp( $prefix, $class, $len ) !== 0 ) {
            return;
        }

        $relative_class = substr( $class, $len );
        $file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

        if ( file_exists( $file ) ) {
            require $file;
        }
    } );

    new \Papaki\PiraeusBank\WooCommerce\Application( plugin_basename( __FILE__ ) );
}, 0 );
