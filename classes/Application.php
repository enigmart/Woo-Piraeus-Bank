<?php

namespace Papaki\PiraeusBank\WooCommerce;
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Application {
    public const PLUGIN_NAMESPACE = 'woo-payment-gateway-for-piraeus-bank';
    private $entrypoint_path;

    public function __construct( $entrypoint ) {
        $this->entrypoint_path = $entrypoint;

        $this->init();

        add_action( 'init', [ $this, 'load_languages' ] );
        add_filter( 'woocommerce_states', [ $this, 'piraeus_woocommerce_states' ] );
        add_action( 'before_woocommerce_init', [ $this, 'declare_compatibility' ] );

        $checkout_block = new Checkout_Block( $entrypoint );
        $checkout_block->init();
    }

    public function init() {
        add_action( 'wp', [ $this, 'piraeusbank_message' ] );
        add_filter( 'woocommerce_payment_gateways', [ $this, 'woocommerce_add_piraeusbank_gateway' ] );
        add_filter( 'plugin_action_links', [ $this, 'piraeusbank_plugin_action_links' ], 10, 2 );
    }

    public function load_languages() {
        load_plugin_textdomain( self::PLUGIN_NAMESPACE, false, dirname( plugin_basename( __FILE__ ) ) . '/../languages/' );
    }

    public function piraeusbank_message() {
        $order_id = absint( get_query_var( 'order-received' ) );
        $order    = wc_get_order( $order_id );
        if ( method_exists( $order, 'get_payment_method' ) ) {
            $payment_method = $order->get_payment_method();
        } else {
            $payment_method = $order->payment_method;
        }

        if ( is_order_received_page() && ( 'piraeusbank_gateway' == $payment_method ) ) {

            $piraeusbank_message = '';
            if ( method_exists( $order, 'get_meta' ) ) {
                $piraeusbank_message = $order->get_meta( '_piraeusbank_message', true );
            } else {
                $piraeusbank_message = $order->get_meta( '_piraeusbank_message', true );
            }
            if ( ! empty( $piraeusbank_message ) ) {
                $message      = $piraeusbank_message['message'];
                $message_type = $piraeusbank_message['message_type'];
                if ( method_exists( $order, 'delete_meta_data' ) ) {
                    $order->delete_meta_data( '_piraeusbank_message' );
                    $order->save_meta_data();
                } else {
                    $order->delete_meta_data( '_piraeusbank_message' ); $order->save_meta_data();
                }

                wc_add_notice( $message, $message_type );
            }
        }
    }

    public function woocommerce_add_piraeusbank_gateway( $methods ) {
        $methods[] = '\Papaki\PiraeusBank\WooCommerce\WC_Piraeusbank_Gateway';
        return $methods;
    }

    public function piraeusbank_plugin_action_links( $links, $file ) {
        static $this_plugin;

        if ( ! $this_plugin ) {
            $this_plugin = plugin_basename( __FILE__ );
        }

        if ( $file == $this_plugin ) {
            $settings_link = '<a href="' . get_bloginfo( 'wpurl' ) . '/wp-admin/admin.php?page=wc-settings&tab=checkout&section=WC_Piraeusbank_Gateway">Settings</a>';
            array_unshift( $links, $settings_link );
        }
        return $links;
    }

    public function piraeus_woocommerce_states( $states ) {
        $states['CY'] = array(
            '04' => __( 'Ammochostos', self::PLUGIN_NAMESPACE ),
            '06' => __( 'Keryneia', self::PLUGIN_NAMESPACE ),
            '03' => __( 'Larnaka', self::PLUGIN_NAMESPACE ),
            '01' => __( 'Lefkosia', self::PLUGIN_NAMESPACE ),
            '02' => __( 'Lemesos', self::PLUGIN_NAMESPACE ),
            '05' => __( 'Pafos', self::PLUGIN_NAMESPACE ),
        );
        $states['DE'] = array(
            'BW' => __( 'Baden-Württemberg', self::PLUGIN_NAMESPACE ),
            'BY' => __( 'Bayern', self::PLUGIN_NAMESPACE ),
            'BE' => __( 'Berlin', self::PLUGIN_NAMESPACE ),
            'BB' => __( 'Brandenburg', self::PLUGIN_NAMESPACE ),
            'HB' => __( 'Bremen', self::PLUGIN_NAMESPACE ),
            'HH' => __( 'Hamburg', self::PLUGIN_NAMESPACE ),
            'HE' => __( 'Hessen', self::PLUGIN_NAMESPACE ),
            'MV' => __( 'Mecklenburg-Vorpommern', self::PLUGIN_NAMESPACE ),
            'NI' => __( 'Niedersachsen', self::PLUGIN_NAMESPACE ),
            'NW' => __( 'Nordrhein-Westfalen', self::PLUGIN_NAMESPACE ),
            'RP' => __( 'Rheinland-Pfalz', self::PLUGIN_NAMESPACE ),
            'SL' => __( 'Saarland', self::PLUGIN_NAMESPACE ),
            'SN' => __( 'Sachsen', self::PLUGIN_NAMESPACE ),
            'ST' => __( 'Sachsen-Anhalt', self::PLUGIN_NAMESPACE ),
            'SH' => __( 'Schleswig-Holstein', self::PLUGIN_NAMESPACE ),
            'TH' => __( 'Thüringen', self::PLUGIN_NAMESPACE ),
        );
        // __('Piraeus Bank Gateway', 'woo-payment-gateway-for-piraeus-bank')
        return $states;
    }

    /**
     * Custom function to declare compatibility with piraeusbank_transactions feature
     */
    public function declare_compatibility() {
        global $wpdb;

        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', $this->entrypoint_path, true );
        }
    }
}
