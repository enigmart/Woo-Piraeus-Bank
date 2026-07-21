<?php

namespace Papaki\PiraeusBank\WooCommerce;
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {

    final class WC_Piraeusbank_Gateway_Checkout_Block extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {
        private $gateway;
        protected $name = 'piraeusbank_gateway';

        // your payment gateway name

        public function initialize() {
            $this->gateway = new WC_Piraeusbank_Gateway();
        }

        public function is_active() {
            return $this->gateway->is_available();
        }

        public function get_payment_method_script_handles() {
            $handle = $this->name . '_gc-blocks-integration';

            wp_register_script(
                $handle,
                plugin_dir_url( __FILE__ ) . '../assets/js/blocks/checkout.js',
                [
                    'wc-blocks-registry',
                    'wc-settings',
                    'wp-element',
                    'wp-html-entities',
                    'wp-i18n',
                ],
                null,
                true
            );

            if ( function_exists( 'wp_set_script_translations' ) ) {
                wp_set_script_translations( $handle, Application::PLUGIN_NAMESPACE, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

            }
            return [ $handle ];
        }

        public function get_payment_method_data() {
            $data = [
                'title'       => $this->gateway->title,
                'description' => $this->gateway->description,
            ];

            if ( ! empty( $this->gateway->icon ) ) {
                $data['icon'] = $this->gateway->icon;
            }
            // expose some settings for the block frontend so it can show/hide fields depending
            // on whether the merchant enabled them and also provide installments options
            $pb_installments = (int) $this->gateway->get_option( 'pb_installments' );
            if ( $pb_installments < 1 ) {
                $pb_installments = 1;
            }

            $amount = 0;
            if ( absint( get_query_var( 'order-pay' ) ) ) {
                $order_id = absint( get_query_var( 'order-pay' ) );
                $order    = new \WC_Order( $order_id );
                $amount   = $order->get_total();
            } elseif ( \WC()->cart && ! \WC()->cart->is_empty() ) {
                $amount = \WC()->cart->total;
            }

            $pb_installments_variation = $this->gateway->get_option( 'pb_installments_variation' ) ?? '';
            $max = $pb_installments;
            if ( ! empty( $pb_installments_variation ) ) {
                $max = 1;
                $installments_split = explode( ',', $pb_installments_variation );
                foreach ( $installments_split as $value ) {
                    $installment = explode( ':', $value );
                    if ( is_array( $installment ) && count( $installment ) === 2 && is_numeric( $installment[0] ) && is_numeric( $installment[1] ) ) {
                        if ( $amount >= ( $installment[0] ) ) {
                            $max = max( $max, (int) $installment[1] );
                        }
                    }
                }
            }

            $installmentsOptions = [];
            for ( $i = 1; $i <= $max; $i++ ) {
                $installmentsOptions[] = [
                    'value' => $i,
                    'label' => ( $i === 1 ? __( 'Without installments', Application::PLUGIN_NAMESPACE ) : (string) $i ),
                ];
            }

            $data['cardHolderEnabled'] = $this->gateway->get_option( 'pb_cardholder_name' ) === 'yes';
            $data['installmentsOptions'] = $installmentsOptions;
            $data['pb_installments'] = $pb_installments;
            $data['pb_installments_variation'] = $pb_installments_variation;
            $data['no_installments_label'] = __( 'Without installments', Application::PLUGIN_NAMESPACE );

            return $data;
        }
    }

} else {

    final class WC_Piraeusbank_Gateway_Checkout_Block {
        public function __construct() {
            // Do nothing - blocks not supported
        }
    }

}
