<?php

namespace Papaki\PiraeusBank\WooCommerce;
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Checkout_Block {
    private $entrypoint_path;

    public function __construct( $entrypoint ) {
        $this->entrypoint_path = $entrypoint;
    }

    public function init() {
        add_action( 'before_woocommerce_init', [ $this, 'declare_cart_checkout_blocks_compatibility' ] );
        add_action( 'woocommerce_blocks_loaded', [ $this, 'woo_register_order_approval_payment_method_type' ] );
        add_action( 'woocommerce_init', [ $this, 'woo_checkout_block_additional_fields' ] );
        add_action( 'woocommerce_set_additional_field_value', [ $this, 'set_additional_field_value' ], 10, 4 );
    }

    public function woo_checkout_block_additional_fields() {
        $gateway = new WC_Piraeusbank_Gateway();

        $pb_cardholder_name = $gateway->get_option( 'pb_cardholder_name' );

        if ( function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
            if ( $pb_cardholder_name === 'yes' ) {
                woocommerce_register_additional_checkout_field(
                    [
                        'id'         => Application::PLUGIN_NAMESPACE . '/card-holder',
                        'label'      => __( 'Cardholder Name', Application::PLUGIN_NAMESPACE ),
                        'location'   => 'order',
                        'required'   => false,
                        'show_in_rest' => [
                            'schema' => [
                                'type' => 'string',
                            ],
                        ],
                        // Add an attribute to this field so frontend JS can find it and show/hide it
                        'attributes' => [
                            'data-payment-method' => 'piraeusbank_gateway',
                            'data-field-id' => 'piraeusbank-card-holder',
                        ],
                    ],
                );
            }

            woocommerce_register_additional_checkout_field(
                [
                    'id'         => Application::PLUGIN_NAMESPACE . '/installments',
                    'label'      => __( 'Installments', Application::PLUGIN_NAMESPACE ),
                    'location'   => 'order',
                    'type'       => 'select',
                    'required'   => false,
                    // Use calculated-by-cart/order total options so frontend matches the gateway limitations
                    'options'    => $this->get_installments_options_up_to( $this->calculate_max_installments() ),
                    'show_in_rest' => [
                        'schema' => [
                            'type' => 'string',
                        ],
                    ],
                    'attributes' => [
                        'data-payment-method' => 'piraeusbank_gateway',
                        'data-field-id' => 'piraeusbank-installments',
                    ],
                ],
            );
        }
    }

    /**
     * Custom function to declare compatibility with cart_checkout_blocks feature
     */
    public function declare_cart_checkout_blocks_compatibility() {
        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', $this->entrypoint_path, true );
        }
    }

    /**
     * Custom function to register a payment method type
     */
    public function woo_register_order_approval_payment_method_type() {
        if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
            return;
        }

        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            function ( \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
                // Register an instance of WC_Piraeusbank_Gateway_Checkout_Block
                $payment_method_registry->register( new WC_Piraeusbank_Gateway_Checkout_Block );
            }
        );
    }

    private function get_installments_options_up_to( $max_installments ) {
        $options = [];
        for ( $i = 1; $i <= $max_installments; $i++ ) {
            if( $i === 1 ) {
                $options[] = [ 'value' => $i, 'label' => __( 'Without installments', Application::PLUGIN_NAMESPACE ) ];
            } else {
                $options[] = [ 'value' => $i, 'label' => (string) $i ];
            }
        }
        return $options;
    }

    private function calculate_max_installments() {
        $gateway = new WC_Piraeusbank_Gateway();
        $pb_installments = (int) $gateway->get_option( 'pb_installments' );
        $pb_installments_variation = $gateway->get_option( 'pb_installments_variation' ) ?? '';

        $max_installments = $pb_installments ?? 1;

        if ( ! empty( $pb_installments_variation ) ) {
            $max_installments = 1;
            $installments_split = explode( ',', $pb_installments_variation );
            foreach ($installments_split as $value) {
                $installment = explode( ':', $value );
                if ( ( is_array( $installment ) && count( $installment ) !== 2 ) ||
                    ( ! is_numeric( $installment[0] ) || ! is_numeric( $installment[1] ) ) ) {
                    continue;
                }

                $max_installments = max( $max_installments, $installment[1] );
            }
        }

        return $max_installments;
    }

    public function set_additional_field_value( $key, $value, $group, $wc_object ) {
        // determine which field we are handling
        if ( Application::PLUGIN_NAMESPACE . '/card-holder' === $key ) {
            $gateway = new WC_Piraeusbank_Gateway();
            $pb_cardholder_name = $gateway->get_option( 'pb_cardholder_name' );
            if ( $pb_cardholder_name !== 'yes' ) {
                return;
            }
            $wc_object->update_meta_data( 'cardholder_name', sanitize_text_field( $value ) );
            return;
        }

        if ( Application::PLUGIN_NAMESPACE . '/installments' === $key ) {
            // The classic plugin uses _doseis as installments meta key
            $v = absint( $value );
            if ( $v < 1 ) {
                $v = 1;
            }
            $wc_object->update_meta_data( '_doseis', $v );
            return;
        }
    }
}
