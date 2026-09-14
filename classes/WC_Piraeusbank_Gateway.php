<?php

namespace Papaki\PiraeusBank\WooCommerce;
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * @property int      $pb_PayMerchantId
 * @property int      $pb_AcquirerId
 * @property int      $pb_PosId
 * @property string   $pb_Username
 * @property string   $pb_Password
 * @property string   $pb_ProxyHost
 * @property string   $pb_ProxyPort
 * @property string   $pb_ProxyUsername
 * @property string   $pb_ProxyPassword
 * @property string   $pb_authorize
 * @property int      $pb_installments
 * @property string   $pb_installments_variation
 * @property string   $pb_render_logo
 * @property string   $pb_cardholder_name
 * @property string   $pb_enable_log
 * @property string   $pb_order_note
 * @property string   $notify_url
 * @property string|null $redirect_page_id
 * @noinspection DuplicatedCode
 * @noinspection PhpMissingReturnTypeInspection
 * @noinspection PhpMissingParamTypeInspection
 * @noinspection PhpUnusedParameterInspection
 */
class WC_Piraeusbank_Gateway extends \WC_Payment_Gateway {

    protected int $pb_PayMerchantId;
    protected int $pb_AcquirerId;
    protected int $pb_PosId;
    protected string $pb_Username;
    protected string $pb_Password;
    protected string $pb_ProxyHost;
    protected string $pb_ProxyPort;
    protected string $pb_ProxyUsername;
    protected string $pb_ProxyPassword;
    protected string $pb_authorize;
    protected int $pb_installments;
    protected string $pb_installments_variation;
    protected string $pb_render_logo;
    protected string $pb_cardholder_name;
    protected string $pb_enable_log;
    protected string $pb_order_note;
    protected string $notify_url;
    protected ?string $redirect_page_id;

    /** Guards against duplicate hook registration when the gateway is instantiated more than once per request. */
    protected static bool $pb_hooks_registered = false;

    /** Per-request cache of already issued Piraeus ticket forms, keyed by order id. */
    protected static array $pb_issued_forms = [];

    /** Customer facing notice restored from the order, still to be printed on this request. */
    protected ?array $pb_pending_notice = null;

    public function __construct() {
        global $wpdb;

        $this->id                 = 'piraeusbank_gateway';
        $this->has_fields         = true;
        $this->notify_url         = WC()->api_request_url( 'WC_Piraeusbank_Gateway' );
        $this->method_description = __( 'Piraeus bank Payment Gateway allows you to accept payment through various channels such as Maestro, Mastercard, AMex cards, Diners  and Visa cards On your Woocommerce Powered Site.', Application::PLUGIN_NAMESPACE );
        $this->redirect_page_id   = $this->get_option( 'redirect_page_id' );
        $this->method_title       = 'Piraeus bank Gateway';

        // Load the form fields.
        $this->init_form_fields();

        $tableCheck = $wpdb->get_var( "SHOW TABLES LIKE '" . $wpdb->prefix . "piraeusbank_transactions'" );

        if ( $tableCheck !== $wpdb->prefix . 'piraeusbank_transactions' ) {
            $wpdb->query( 'CREATE TABLE IF NOT EXISTS ' . $wpdb->prefix . 'piraeusbank_transactions (id int(11) unsigned NOT NULL AUTO_INCREMENT, merch_ref varchar(50) not null, trans_ticket varchar(32) not null , timestamp datetime default null, PRIMARY KEY (id))' );
        }

        // Load the settings.
        $this->init_settings();

        // Define user set variables
        $this->title                     = sanitize_text_field( $this->get_option( 'title' ) );
        $this->description               = sanitize_text_field( $this->get_option( 'description' ) );
        $this->pb_PayMerchantId          = absint( $this->get_option( 'pb_PayMerchantId' ) );
        $this->pb_AcquirerId             = absint( $this->get_option( 'pb_AcquirerId' ) );
        $this->pb_PosId                  = absint( $this->get_option( 'pb_PosId' ) );
        $this->pb_Username               = sanitize_text_field( $this->get_option( 'pb_Username' ) );
        $this->pb_Password               = sanitize_text_field( $this->get_option( 'pb_Password' ) );
        $this->pb_ProxyHost              = $this->get_option( 'pb_ProxyHost' );
        $this->pb_ProxyPort              = $this->get_option( 'pb_ProxyPort' );
        $this->pb_ProxyUsername          = $this->get_option( 'pb_ProxyUsername' );
        $this->pb_ProxyPassword          = $this->get_option( 'pb_ProxyPassword' );
        $this->pb_authorize              = sanitize_text_field( $this->get_option( 'pb_authorize' ) );
        $this->pb_installments           = absint( $this->get_option( 'pb_installments' ) );
        $this->pb_installments_variation = sanitize_text_field( $this->get_option( 'pb_installments_variation' ) );
        $this->pb_render_logo            = sanitize_text_field( $this->get_option( 'pb_render_logo' ) );
        $this->pb_cardholder_name        = sanitize_text_field( $this->get_option( 'pb_cardholder_name' ) );
        $this->pb_enable_log             = sanitize_text_field( $this->get_option( 'pb_enable_log' ) );
        $this->pb_order_note             = sanitize_text_field( $this->get_option( 'pb_order_note' ) );

        //Actions
        // These callbacks must only ever run once per request. The gateway is instantiated several
        // times per request (WooCommerce gateway registry + checkout block helpers) and each new
        // object used to register its own callback, which made the receipt page issue one Piraeus
        // ticket (IssueNewTicket) per instance for the very same MerchantReference.
        if ( ! self::$pb_hooks_registered ) {
            self::$pb_hooks_registered = true;

            add_action( 'woocommerce_receipt_piraeusbank_gateway', [ $this, 'receipt_page' ] );

            // Payment listener/API hook
            add_action( 'woocommerce_api_wc_piraeusbank_gateway', [ $this, 'check_piraeusbank_response' ] );

            // The bank answers with a cross-site POST. With SameSite=Lax cookies the WooCommerce
            // session cookie is not sent on it, so wc_add_notice() there ends up in a throw away
            // session. The customer facing message is stored on the order instead and rendered
            // here, on the next (same-site) page load.
            add_action( 'wp', [ $this, 'pb_maybe_render_stored_notice' ], 5 );
            add_action( 'template_redirect', [ $this, 'pb_maybe_render_stored_notice' ], 5 );
            add_action( 'before_woocommerce_pay', [ $this, 'pb_output_pending_notices' ], 5 );
            add_action( 'woocommerce_before_thankyou', [ $this, 'pb_output_pending_notices' ], 5 );
        }

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );

        if ( class_exists( \SoapClient::class) !== true ) {
            add_action( 'admin_notices', [ $this, 'soap_error_notice' ] );
        }

        if ( $this->pb_authorize === "yes" ) {
            add_action( 'admin_notices', [ $this, 'authorize_warning_notice' ] );
        }
        if ( $this->pb_render_logo === "yes" ) {
            $this->icon = apply_filters( 'piraeusbank_icon', plugins_url( '../img/piraeusbank.svg', __FILE__ ) );
        }

        $this->cardholderNameFunctionality();
    }

    public function cardholderNameFunctionality() {
        if ( $this->pb_cardholder_name === 'yes' ) {
            add_filter( 'woocommerce_billing_fields', [ $this, 'custom_override_checkout_fields' ] );
            //add_filter( 'woocommerce_customer_meta_fields', [ $this, 'add_woocommerce_customer_meta_fields' ] );
            add_action( 'woocommerce_checkout_update_order_meta', [ $this, 'my_custom_checkout_field_update_order_meta' ] );

            wc_enqueue_js( '
                    jQuery(function(){
                        jQuery( \'body\' )
                        .on( \'updated_checkout\', function() {
                            usingGateway();

                            jQuery(\'input[name="payment_method"]\').change(function(){
                                usingGateway();
                            });
                        });
                    });

                    function usingGateway(){
                        if(jQuery(\'form[name="checkout"] input[name="payment_method"]:checked\').val() === \'piraeusbank_gateway\'){
                            jQuery("#cardholder_name_field").show();
                            document.getElementById("cardholder_name").scrollIntoView({behavior: "smooth", block: "center", inline: "nearest"});
                        }else{
                            jQuery("#cardholder_name_field").hide();
                        }
                    }
                ' );
        }
    }

    public function custom_override_checkout_fields( $billing_fields ) {
        $billing_fields['cardholder_name'] = [
            'type'        => 'text',
            'label'       => __( 'Cardholder Name', Application::PLUGIN_NAMESPACE ),
            'placeholder' => __( 'Card holder name is optional by Piraeus Bank for validation', Application::PLUGIN_NAMESPACE ),
            'required'    => false,
            'class'       => [ 'form-row-wide' ],
            'clear'       => true,
        ];

        return $billing_fields;
    }

    public function my_custom_checkout_field_update_order_meta( $order_id ) {
        if ( ! empty( $_POST['cardholder_name'] ) ) {
            $order = wc_get_order( $order_id ); if ( $order ) { $order->update_meta_data( 'cardholder_name', sanitize_text_field( $_POST['cardholder_name'] ) ); $order->save(); }
        }
    }

    public function admin_options() {
        $ch = curl_init();
        curl_setopt( $ch, CURLOPT_URL, 'https://api.ipify.org' );
        curl_setopt( $ch, CURLOPT_HEADER, 0 );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
        $output = curl_exec( $ch );

        curl_close( $ch );

        echo '<h3>' . __( 'Piraeus Bank Gateway', Application::PLUGIN_NAMESPACE ) . '</h3>';
        echo '<p>' . __( 'Piraeus Bank Gateway allows you to accept payment through various channels such as Maestro, Mastercard, AMex cards, Diners  and Visa cards.', Application::PLUGIN_NAMESPACE ) . '</p>';
        $base_url = $_SERVER['HTTP_HOST'] ?: $_SERVER['SERVER_NAME'];
        // $host = (is_ssl() === true ? 'https://' : 'http://') . $base_url . '/';
        $host = get_bloginfo( 'url' ) . '/';


        echo '<div style="border: 1px dashed #000; display: inline-block; padding: 10px;">';
        echo '<h4>' . __( 'Technical data to be submitted to Piraeus Bank', Application::PLUGIN_NAMESPACE ) . '</h4>';
        echo '<p>' . __( 'The data to be submitted to Piraeus Bank(<a href="mailto:epayments@piraeusbank.gr">epayments@piraeusbank.gr</a>) in order to provide the necessary technical info (test/live account) for transactions are as follows', Application::PLUGIN_NAMESPACE ) . ':</p>';
        echo '<ul>';
        echo '<li><strong>Website URL:</strong> ' . $host . '</li>';
        echo '<li><strong>Referrer url:</strong> ' . $host . 'checkout/' . ' </li>';
        echo '<li><strong>Success page: </strong>' . $host . ( get_option( 'permalink_structure' ) ? 'wc-api/WC_Piraeusbank_Gateway?peiraeus=success' : '?wc-api=WC_Piraeusbank_Gateway&peiraeus=success' ) . ' </li>';
        echo '<li><strong>Failure page:</strong> ' . $host . ( get_option( 'permalink_structure' ) ? 'wc-api/WC_Piraeusbank_Gateway?peiraeus=fail' : '?wc-api=WC_Piraeusbank_Gateway&peiraeus=fail' ) . ' </li>';
        echo '<li><strong>Backlink page:</strong> ' . $host . ( get_option( 'permalink_structure' ) ? 'wc-api/WC_Piraeusbank_Gateway?peiraeus=cancel' : '?wc-api=WC_Piraeusbank_Gateway&peiraeus=cancel' ) . ' </li>';
        echo '<li><strong>Response method :</strong> GET / POST  (Preferred one: POST)</li>';
        $ip = ! empty( $output ) ? $output : gethostbyname( $base_url );
        echo '<li><strong>Server Ip:</strong> ' . $ip . '</li>';
        echo '</ul>';
        echo '<p style="font-style:italic;">* Σημείωση: Τα urls Success, Failure, Backlink δημιουργούνται αυτόματα απο το plugin μας, ΔΕΝ χρείαζεται να δημιουργήσετε εσείς κάποια  επισπρόσθετη σελίδα</p>';
        echo '</div>';
        echo '<table class="form-table">';
        $this->generate_settings_html();
        echo '</table>';


    }

    public function soap_error_notice() {
        echo '<div class="error notice">';
        echo '<p>' . __( '<strong>SOAP have to be enabled in your Server/Hosting</strong>, it is required for this plugin to work properly!', Application::PLUGIN_NAMESPACE ) . '</p>';
        echo '</div>';
    }

    public function authorize_warning_notice() {
        echo '<div class="notice-warning notice">';
        echo '<p>' . __( '<strong>Important Notice:</strong> Piraeus Bank has announced that it will gradually abolish the Preauthorized Payment Service for all merchants, beginning from the ones obtained MIDs from 29/1/2019 onwards.<br /> You are highly recommended to disable the preAuthorized Payment Service as soon as possible.', Application::PLUGIN_NAMESPACE ) . '</p>';
        echo '</div>';
    }

    /**
     * Initialise Gateway Settings Form Fields
     * */
    public function init_form_fields() {
        $this->form_fields = [
            'enabled'                   => [
                'title'       => __( 'Enable/Disable', Application::PLUGIN_NAMESPACE ),
                'type'        => 'checkbox',
                'label'       => __( 'Enable Piraeus Bank Gateway', Application::PLUGIN_NAMESPACE ),
                'description' => __( 'Enable or disable the gateway.', Application::PLUGIN_NAMESPACE ),
                'desc_tip'    => true,
                'default'     => 'yes',
            ],
            'title'                     => [
                'title'       => __( 'Title', Application::PLUGIN_NAMESPACE ),
                'type'        => 'text',
                'description' => __( 'This controls the title which the user sees during checkout.', Application::PLUGIN_NAMESPACE ),
                'desc_tip'    => false,
                'default'     => __( 'Piraeus Bank Gateway', Application::PLUGIN_NAMESPACE ),
            ],
            'description'               => [
                'title'       => __( 'Description', Application::PLUGIN_NAMESPACE ),
                'type'        => 'textarea',
                'description' => __( 'This controls the description which the user sees during checkout.', Application::PLUGIN_NAMESPACE ),
                'default'     => __( 'Pay Via Piraeus Bank - Pay by Card or IRIS.', Application::PLUGIN_NAMESPACE ),
            ],
            'pb_render_logo'            => [
                'title'       => __( 'Display the logo of Piraeus Bank', Application::PLUGIN_NAMESPACE ),
                'type'        => 'checkbox',
                'description' => __( 'Enable to display the logo of Piraeus Bank next to the title which the user sees during checkout.', Application::PLUGIN_NAMESPACE ),
                'default'     => 'yes',
            ],
            'pb_PayMerchantId'          => [
                'title'       => __( 'Piraeus Bank Merchant ID', Application::PLUGIN_NAMESPACE ),
                'type'        => 'text',
                'description' => __( 'Enter Your Piraeus Bank Merchant ID', Application::PLUGIN_NAMESPACE ),
                'default'     => '',
                'desc_tip'    => true,
            ],
            'pb_AcquirerId'             => [
                'title'       => __( 'Piraeus Bank Acquirer ID', Application::PLUGIN_NAMESPACE ),
                'type'        => 'text',
                'description' => __( 'Enter Your Piraeus Bank Acquirer ID', Application::PLUGIN_NAMESPACE ),
                'default'     => '',
                'desc_tip'    => true,
            ],
            'pb_PosId'                  => [
                'title'       => __( 'Piraeus Bank POS ID', Application::PLUGIN_NAMESPACE ),
                'type'        => 'text',
                'description' => __( 'Enter your Piraeus Bank POS ID', Application::PLUGIN_NAMESPACE ),
                'default'     => '',
                'desc_tip'    => true,
            ],
            'pb_Username'               => [
                'title'       => __( 'Piraeus Bank Username', Application::PLUGIN_NAMESPACE ),
                'type'        => 'text',
                'description' => __( 'Enter your Piraeus Bank Username', Application::PLUGIN_NAMESPACE ),
                'default'     => '',
                'desc_tip'    => true,
            ],
            'pb_Password'               => [
                'title'       => __( 'Piraeus Bank Password', Application::PLUGIN_NAMESPACE ),
                'type'        => 'password',
                'description' => __( 'Enter your Piraeus Bank Password', Application::PLUGIN_NAMESPACE ),
                'default'     => '',
                'desc_tip'    => true,
            ],
            'pb_ProxyHost'              => [
                'title'       => __( 'HTTP Proxy Hostname', Application::PLUGIN_NAMESPACE ),
                'type'        => 'text',
                'description' => __( 'Used when your server is not behind a static IP. Leave blank for normal HTTP connection.', Application::PLUGIN_NAMESPACE ),
                'desc_tip'    => false,
                'default'     => '',
            ],
            'pb_ProxyPort'              => [
                'title'       => __( 'HTTP Proxy Port', Application::PLUGIN_NAMESPACE ),
                'type'        => 'text',
                'description' => __( 'Used with Proxy Host.', Application::PLUGIN_NAMESPACE ),
                'desc_tip'    => false,
                'default'     => '',
            ],
            'pb_ProxyUsername'          => [
                'title'       => __( 'HTTP Proxy Login Username', Application::PLUGIN_NAMESPACE ),
                'type'        => 'text',
                'description' => __( 'Used with Proxy Host. Leave blank for anonymous connection.', Application::PLUGIN_NAMESPACE ),
                'desc_tip'    => false,
                'default'     => '',
            ],
            'pb_ProxyPassword'          => [
                'title'       => __( 'HTTP Proxy Login Password', Application::PLUGIN_NAMESPACE ),
                'type'        => 'password',
                'description' => __( ' Used with Proxy Host. Leave blank for anonymous connection.', Application::PLUGIN_NAMESPACE ),
                'desc_tip'    => false,
                'default'     => '',
            ],
            'pb_authorize'              => [
                'title'       => __( 'Pre-Authorize', Application::PLUGIN_NAMESPACE ),
                'type'        => 'checkbox',
                'label'       => __( 'Enable to capture preauthorized payments', Application::PLUGIN_NAMESPACE ),
                'default'     => 'no',
                'description' => __( '<strong>Important Notice:</strong> Piraeus Bank has announced that it will gradually abolish the Preauthorized Payment Service for all merchants, beginning from the ones obtained MIDs from 29/1/2019 onwards.<br /> Default payment method is Purchase, enable for Pre-Authorized payments. You will then need to accept them from Piraeus Bank AdminTool', Application::PLUGIN_NAMESPACE ),
            ],
            'redirect_page_id'          => [
                'title'       => __( 'Return page URL <br />(Successful or Failed Transactions)', Application::PLUGIN_NAMESPACE ),
                'type'        => 'select',
                'options'     => $this->pb_get_pages( 'Select Page' ),
                'description' => __( 'We recommend you to select the default “Thank You Page”, in order to automatically serve both successful and failed transactions, with the latter also offering the option to try the payment again.<br /> If you select a different page, you will have to handle failed payments yourself by adding custom code.', Application::PLUGIN_NAMESPACE ),
                'default'     => -1,
            ],
            'pb_installments'           => [
                'title'       => __( 'Maximum number of installments regardless of the total order amount', Application::PLUGIN_NAMESPACE ),
                'type'        => 'select',
                'options'     => $this->pb_get_installments( 'Select Installments' ),
                'description' => __( '1 to 24 Installments,1 for one time payment. You must contact Piraeus Bank first<br /> If you have filled the "Max Number of installments depending on the total order amount", the value of this field will be ignored.', Application::PLUGIN_NAMESPACE ),
            ],
            'pb_installments_variation' => [
                'title'       => __( 'Maximum number of installments depending on the total order amount', Application::PLUGIN_NAMESPACE ),
                'type'        => 'text',
                'description' => __( 'Example 80:2, 160:4, 300:8</br> total order greater or equal to 80 -> allow 2 installments, total order greater or equal to 160 -> allow 4 installments, total order greater or equal to 300 -> allow 8 installments</br> Leave the field blank if you do not want to limit the number of installments depending on the amount of the order.', Application::PLUGIN_NAMESPACE ),
            ],
            'pb_cardholder_name'        => [
                'title'       => __( 'Enable Cardholder Name Field', Application::PLUGIN_NAMESPACE ),
                'type'        => 'checkbox',
                'label'       => __( 'Enabling this field allows customers to insert a cardholder name', Application::PLUGIN_NAMESPACE ),
                'default'     => 'yes',
                'description' => __( 'According to Piraeus bank’s technical requirements related to 3D secure and SCA, the cardholder’s name must be sent before the customer is redirected to the bank’s payment environment. If you choose not to show this field, we will automatically send the full name inserted for the order, with the risk of having the bank refusing the transaction due to the validity of this field.', Application::PLUGIN_NAMESPACE ),
            ],
            'pb_enable_log'             => [
                'title'       => __( 'Enable Debug mode', Application::PLUGIN_NAMESPACE ),
                'type'        => 'checkbox',
                'label'       => __( 'Enabling this will log certain information', Application::PLUGIN_NAMESPACE ),
                'default'     => 'no',
                'description' => __( 'Enabling this (and the debug mode from your wp-config file) will log information, e.g. bank responses, which will help in debugging issues.', Application::PLUGIN_NAMESPACE ),
            ],
            'pb_order_note'             => [
                'title'       => __( 'Enable 2nd “payment received” email', Application::PLUGIN_NAMESPACE ),
                'type'        => 'checkbox',
                'label'       => __( 'Enable sending Customer order note with transaction details', Application::PLUGIN_NAMESPACE ),
                'default'     => 'no',
                'description' => __( 'Enabling this will send an email with the support reference id and transaction id to the customer, after the transaction has been completed (either on success or failure)', Application::PLUGIN_NAMESPACE ),
            ],

        ];
    }

    /**
     * @param string|bool $title
     * @param bool        $indent
     *
     * @return array
     */
    public function pb_get_pages( $title = false, $indent = true ) {
        $wp_pages  = get_pages( 'sort_column=menu_order' );
        $page_list = [];
        if ( $title ) {
            $page_list[] = $title;
        }
        foreach ($wp_pages as $page) {
            $prefix = '';
            // show indented child pages?
            if ( $indent ) {
                $has_parent = $page->post_parent;
                while ( $has_parent ) {
                    $prefix     .= ' - ';
                    $next_page   = get_post( $has_parent );
                    $has_parent  = $next_page->post_parent;
                }
            }
            // add to page list array array
            $page_list[ $page->ID ] = $prefix . $page->post_title;
        }
        $page_list[ -1 ] = __( 'Thank you page', Application::PLUGIN_NAMESPACE );

        return $page_list;
    }

    /**
     * @param string|bool $title
     * @param bool        $indent
     *
     * @return array
     */
    public function pb_get_installments( $title = false, $indent = true ) {
        for ( $i = 1; $i <= 24; $i++ ) {
            $installment_list[ $i ] = $i;
        }

        return $installment_list;
    }

    /**
     * @return void
     */
    public function payment_fields() {
        global $woocommerce;

        $amount = 0;

        //get: order or cart total, to compute max installments number.
        if ( absint( get_query_var( 'order-pay' ) ) ) {
            $order_id = absint( get_query_var( 'order-pay' ) );
            $order    = wc_get_order( $order_id );
            $amount   = $order->get_total();
        if ( ! $order ) { return; }
        } elseif ( ! $woocommerce->cart->is_empty() ) {
            $amount = $woocommerce->cart->total;
        }

        if ( $description = $this->get_description() ) {
            echo wpautop( wptexturize( $description ) );
        }

        $max_installments       = $this->pb_installments ?? 1;
        $installments_variation = $this->pb_installments_variation ?? [];

        if ( ! empty( $installments_variation ) ) {
            $max_installments   = 1; // initialize the max installments
            $installments_split = explode( ',', $installments_variation );
            foreach ($installments_split as $value) {
                $installment = explode( ':', $value );
                if ( ( is_array( $installment ) && count( $installment ) !== 2 ) ||
                    ( ! is_numeric( $installment[0] ) || ! is_numeric( $installment[1] ) ) ) {
                    // not valid rule for installments
                    continue;
                }

                if ( $amount >= ( $installment[0] ) ) {
                    $max_installments = $installment[1];
                }
            }
        }

        if ( $max_installments > 1 ) {
                $doseis_field = '<p class="form-row ">
                    <label for="' . esc_attr( $this->id ) . '-card-doseis">' . __( 'Choose Installments', Application::PLUGIN_NAMESPACE ) . ' <span class="required">*</span></label>
                                <select id="' . esc_attr( $this->id ) . '-card-doseis" name="' . esc_attr( $this->id ) . '-card-doseis" class="input-select wc-credit-card-form-card-doseis">
                                ';
            for ( $i = 1; $i <= $max_installments; $i++ ) {
                $doseis_field  .= '<option value="' . $i . '">' . ( $i === 1 ? __( 'Without installments', Application::PLUGIN_NAMESPACE ) : $i ) . '</option>';
            }
            $doseis_field  .= '</select>
                        </p>'; // <img width="100%" height="100%" style="max-height:100px!important" src="'. plugins_url('img/alpha_cards.png', __FILE__) .'" >

            echo $doseis_field;
        }
    }

    /**
     * Generate the  Piraeus Payment button link
     * */
    public function generate_piraeusbank_form( $order_id ) {
        global $wpdb;

        // Safety net: never issue more than one ticket per order within the same request.
        if ( array_key_exists( $order_id, self::$pb_issued_forms ) ) {
            return self::$pb_issued_forms[ $order_id ];
        }

        $availableLocales = [
            'en'             => 'en-US',
            'en_US'          => 'en-US',
            'en_AU'          => 'en-US',
            'en_CA'          => 'en-US',
            'en_GB'          => 'en-US',
            'en_NZ'          => 'en-US',
            'en_ZA'          => 'en-US',
            'el'             => 'el-GR',
            'ru_RU'          => 'ru-RU',
            'de_DE'          => 'de-DE',
            'de_DE_formal'   => 'de-DE',
            'de_CH'          => 'de-DE',
            'de_CH_informal' => 'de-DE',
        ];

        $lang  = $availableLocales[get_locale()] ?? 'en-US';
        $order = wc_get_order( $order_id );

        if ( ! $order ) { return; }
        $requestType   = $this->pb_authorize === "yes" ? '00' : '02';
        $ExpirePreauth = $this->pb_authorize === "yes" ? '30' : '0';

        if ( method_exists( $order, 'get_meta' ) ) {
            $installments = $order->get_meta( '_doseis' );
            if ( $installments === '' ) {
                $installments = 1;
            }
        } else {
            $installments = $order->get_meta( '_doseis', true ); if ( $installments === '' ) { $installments = 1; }
        }

        try {
            if ( $this->pb_ProxyHost !== '' ) {
                if ( $this->pb_ProxyUsername !== '' && $this->pb_ProxyPassword !== '' ) {
                    $soap = new \SoapClient( "https://paycenter.piraeusbank.gr/services/tickets/issuer.asmx?WSDL",
                        [
                            'proxy_host'     => $this->pb_ProxyHost,
                            'proxy_port'     => (int) $this->pb_ProxyPort,
                            'proxy_login'    => $this->pb_ProxyUsername,
                            'proxy_password' => $this->pb_ProxyPassword,
                        ]
                    );
                } else {
                    $soap = new \SoapClient( "https://paycenter.piraeusbank.gr/services/tickets/issuer.asmx?WSDL",
                        [
                            'proxy_host' => $this->pb_ProxyHost,
                            'proxy_port' => (int) $this->pb_ProxyPort,
                        ]
                    );
                }
            } else {
                $soap = new \SoapClient( "https://paycenter.piraeusbank.gr/services/tickets/issuer.asmx?WSDL" );
            }

            //initialize new 3DS information
            $BillAddrCity      = mb_substr( $order->get_billing_city(), 0, 50 ); // TODO: add regexp for greek latin and special chars
            $BillAddrCountry   = $this->pb_getCountryNumericCode( $order->get_billing_country() ); // TODO: add regexp for greek latin and special chars
            $BillAddrLine1     = mb_substr( $order->get_billing_address_1(), 0, 50 );
            $BillAddrPostCode  = $order->get_billing_postcode();
            $BillAddrState     = $order->get_billing_state();
            $BillAddrStateCode = $this->pb_validateStateCode( $BillAddrState, $order->get_billing_country() );

            $ShipAddrCity      = mb_substr( ! empty( $order->get_shipping_city() ) ? $order->get_shipping_city() : $order->get_billing_city(), 0, 50 );
            $ShipAddrCountry   = ! empty( $order->get_shipping_country() ) ? $this->pb_getCountryNumericCode( $order->get_shipping_country() ) : $BillAddrCountry;
            $ShipAddrLine1     = mb_substr( ! empty( $order->get_shipping_address_1() ) ? $order->get_shipping_address_1() : $order->get_billing_address_1(), 0, 50 );
            $ShipAddrPostCode  = ! empty( $order->get_shipping_postcode() ) ? $order->get_shipping_postcode() : $BillAddrPostCode;
            $ShipAddrState     = ! empty( $order->get_shipping_state() ) ? $order->get_shipping_state() : $BillAddrState;
            $ShipAddrStateCode = $this->pb_validateStateCode( $ShipAddrState, ! empty( $order->get_shipping_country() ) ? $order->get_shipping_country() : $order->get_billing_country() );
            $Email             = $order->get_billing_email();

            $HomePhone   = $this->pb_validatePhoneNumberAllCountries( $order->get_billing_phone(), $order->get_billing_country() );
            $MobilePhone = $this->pb_validatePhoneNumberAllCountries( $order->get_billing_phone(), $order->get_billing_country() );
            $WorkPhone   = $this->pb_validatePhoneNumberAllCountries( $order->get_billing_phone(), $order->get_billing_country() );

            $name           = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
            $CardholderName = $this->pb_getCardholderName( $order->get_id(), $name, $this->pb_cardholder_name );


            $ticketRequest = [
                'Username'          => $this->pb_Username,
                'Password'          => hash( 'md5', $this->pb_Password ),
                'MerchantId'        => $this->pb_PayMerchantId,
                'PosId'             => $this->pb_PosId,
                'AcquirerId'        => $this->pb_AcquirerId,
                'MerchantReference' => $order_id,
                'RequestType'       => $requestType,
                'ExpirePreauth'     => $ExpirePreauth,
                'Amount'            => $order->get_total(),
                'CurrencyCode'      => '978',
                'Installments'      => $installments,
                'Bnpl'              => '0',
                'Parameters'        => '',
                'BillAddrCity'      => $BillAddrCity,
                'BillAddrCountry'   => $BillAddrCountry,
                'BillAddrLine1'     => $BillAddrLine1,
                'BillAddrPostCode'  => $BillAddrPostCode,
                'BillAddrState'     => $BillAddrStateCode,
                'ShipAddrCity'      => $ShipAddrCity,
                'ShipAddrCountry'   => $ShipAddrCountry,
                'ShipAddrLine1'     => $ShipAddrLine1,
                'ShipAddrPostCode'  => $ShipAddrPostCode,
                'ShipAddrState'     => $ShipAddrStateCode,
                'CardholderName'    => $CardholderName,
                'Email'             => $Email,
                'HomePhone'         => $HomePhone,
                'MobilePhone'       => $MobilePhone,
                'WorkPhone'         => $WorkPhone,
            ];

            $xml = [
                'Request' => $ticketRequest,
            ];

            /** @noinspection PhpUndefinedMethodInspection */
            $oResult = $soap->IssueNewTicket( $xml );

            $this->safe_log( '---- Piraeus Transaction Ticket -----', $ticketRequest );
            $this->safe_log( '---- End of Piraeus Transaction Ticket ----' );

			if ( (int) $oResult->IssueNewTicketResult->ResultCode === 0 ) {
				$wpdb->insert( $wpdb->prefix . 'piraeusbank_transactions', [ 'trans_ticket' => $oResult->IssueNewTicketResult->TranTicket, 'merch_ref' => $order_id, 'timestamp' => current_time( 'mysql', 1 ) ] );

				// Store order ID in session for callback validation
				if ( WC()->session ) {
					WC()->session->set( 'pb_pending_order_id', $order_id );
				}

                wc_enqueue_js( '
                $.blockUI({
                        message: "' . esc_js( __( 'Thank you for your order. We are now redirecting you to Piraeus Bank to make payment.', Application::PLUGIN_NAMESPACE ) ) . '",
                        baseZ: 99999,
                        overlayCSS:
                        {
                            background: "#fff",
                            opacity: 0.6
                        },
                        css: {
                            padding:        "20px",
                            zindex:         "9999999",
                            textAlign:      "center",
                            color:          "#555",
                            border:         "3px solid #aaa",
                            backgroundColor:"#fff",
                            cursor:         "wait",
                            lineHeight:        "24px",
                        }
                    });
                    jQuery("#submit_pb_payment_form").click();
                ' );

                $LanCode = $lang;

                return self::$pb_issued_forms[ $order_id ] = '<form action="' . esc_url( "https://paycenter.piraeusbank.gr/redirection/pay.aspx" ) . '" method="post" id="pb_payment_form" target="_top">

                        <input type="hidden" id="AcquirerId" name="AcquirerId" value="' . esc_attr( $this->pb_AcquirerId ) . '"/>
                        <input type="hidden" id="MerchantId" name="MerchantId" value="' . esc_attr( $this->pb_PayMerchantId ) . '"/>
                        <input type="hidden" id="PosID" name="PosID" value="' . esc_attr( $this->pb_PosId ) . '"/>
                        <input type="hidden" id="User" name="User" value="' . esc_attr( $this->pb_Username ) . '"/>
                        <input type="hidden" id="LanguageCode"  name="LanguageCode" value="' . $LanCode . '"/>
                        <input type="hidden" id="MerchantReference" name="MerchantReference"  value="' . esc_attr( $order_id ) . '"/>
                    <!-- Button Fallback -->
                    <div class="payment_buttons">
                        <input type="submit" class="button alt" id="submit_pb_payment_form" value="' . __( 'Pay via Pireaus Bank', Application::PLUGIN_NAMESPACE ) . '" /> <a class="button cancel" href="' . esc_url( $order->get_cancel_order_url() ) . '">' . __( 'Cancel order &amp; restore cart', Application::PLUGIN_NAMESPACE ) . '</a>

                    </div>
                    <script type="text/javascript">
                    jQuery(".payment_buttons").hide();
                    </script>
                </form>';
            }

            echo __( 'An error occured, please contact the Administrator. ', Application::PLUGIN_NAMESPACE );
            echo ( 'Result code is ' . sanitize_text_field( $oResult->IssueNewTicketResult->ResultCode ) );
            echo ( '. : ' . sanitize_text_field( $oResult->IssueNewTicketResult->ResultDescription ) );
            $order->add_order_note( __( 'Error' . sanitize_text_field( $oResult->IssueNewTicketResult->ResultCode ) . ':' . sanitize_text_field( $oResult->IssueNewTicketResult->ResultDescription ), '' ) );
        }
        catch ( \SoapFault $fault ) {
            $order->add_order_note( __( 'Error' . sanitize_text_field( $fault ), '' ) );
            echo __( 'Error' . sanitize_text_field( $fault ), '' );
        }

        return '';
    }

    /**
     * @param int $order_id
     *
     * @return array
     */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );

        if ( ! $order ) { return array(); }
        $key = esc_attr( $this->id ) . '-card-doseis';

        $doseis = isset( $_POST[ $key ] ) ? (int) $_POST[ $key ] : 1;
        if ( $doseis > 0 ) {
            $this->generic_add_meta( $order_id, '_doseis', $doseis );
        }

        return [
            'result'   => 'success',
            'redirect' => add_query_arg( 'order-pay', $order->get_id(), add_query_arg( 'key', $order->get_order_key(), wc_get_page_permalink( 'checkout' ) ) ),
        ];
    }

    /**
     * @param int $order
     *
     * @return void
     */
    public function receipt_page( $order ) {
        echo '<p>' . __( 'Thank you - your order is now pending payment. You should be automatically redirected to Piraeus Paycenter to make payment.', Application::PLUGIN_NAMESPACE ) . '</p>';
        echo $this->generate_piraeusbank_form( $order );
    }

    /**
     * @return void
     */
    /** Serialize callbacks before loading the order, including simultaneous bank retries. */
    public function check_piraeusbank_response() {
        global $wpdb;

        $action = isset( $_REQUEST['peiraeus'] ) ? $_REQUEST['peiraeus'] : '';
        if ( ! in_array( $action, [ 'success', 'fail' ], true ) ) {
            $this->pb_process_response();
            return;
        }

        $reference = isset( $_REQUEST['MerchantReference'] ) ? $_REQUEST['MerchantReference'] : '';
        if ( ! is_scalar( $reference ) || ! ctype_digit( (string) $reference ) || (int) $reference < 1 ) {
            wp_redirect( wc_get_checkout_url() );
            exit;
        }

        $lock = 'pb_callback_' . md5( DB_NAME . ':' . $wpdb->prefix . ':' . (int) $reference );
        if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ) ) {
            // Do not acknowledge a callback we could not safely process.
            wp_die( 'Payment confirmation is being processed. Please try again shortly.', '', [ 'response' => 503 ] );
            return;
        }

        $released = false;
        $release = static function () use ( $wpdb, $lock, &$released ) {
            if ( ! $released ) {
                $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
                $released = true;
            }
        };
        // The existing redirect paths call exit, which does not run a finally block.
        register_shutdown_function( $release );
        try {
            $this->pb_process_response();
        } finally {
            $release();
        }
    }

    /** Preserve payment history even if an order was subsequently refunded or marked failed. */
    private function pb_payment_was_completed( $order ) {
        return $order->is_paid() || $order->get_date_paid() || $order->get_meta( '_piraeusbank_payment_processed', true );
    }

    private function pb_process_response() {
        global $wpdb;

        $pb_message   = [];
        $message      = '';
        $consHashHmac = '';
        $order        = null;

        $this->safe_log( '---- Piraeus Response -----', $_REQUEST );
        $this->safe_log( '---- End of Piraeus Response ----' );

        if ( isset( $_REQUEST['peiraeus'] ) && ( $_REQUEST['peiraeus'] === 'success' ) ) {
            $ResultCode = (int) sanitize_text_field( $_REQUEST['ResultCode'] );
            $order_id   = sanitize_text_field( $_REQUEST['MerchantReference'] );
            $order      = wc_get_order( $order_id );

            if ( ! $order ) { return; }
            if ( ! $order || ! $order->get_id() ) {
                $this->safe_log( '---- Invalid Order ID -----', $order_id );

                wp_redirect( wc_get_checkout_url() );
                exit;
            }


            if ( $ResultCode !== 0 ) {
                if ( $this->pb_payment_was_completed( $order ) ) {
                    $this->safe_log( 'Piraeus: ignored late technical failure for a previously paid order.', [ 'MerchantReference' => $order_id ] );
                    // This branch is not authenticated; do not disclose an order-key URL.
                    wp_redirect( wc_get_checkout_url() );
                    exit;
                }
                // Technical failure reported by Paycenter (spec 3.1 - Result codes).
                $ResultDescription  = isset( $_REQUEST['ResultDescription'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['ResultDescription'] ) ) : '';
                $SupportReferenceID = isset( $_REQUEST['SupportReferenceID'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['SupportReferenceID'] ) ) : '';

                $this->pb_store_response_data( $order, $_REQUEST );

                // ResultDescription is stored on the order only, it is never shown to the customer.
                $order->add_order_note(
                    __( 'Piraeus Paycenter technical failure.', Application::PLUGIN_NAMESPACE )
                    . '<br />SupportReferenceID: ' . $SupportReferenceID
                    . '<br />MerchantReference: ' . $order_id
                    . '<br />ResultCode: ' . $ResultCode
                    . '<br />ResultDescription: ' . $ResultDescription
                );

                $message      = $this->pb_technical_error_message( $ResultCode );
                $message_type = 'error';

                $this->set_message( $order, $message, $message_type );
                $this->pb_set_customer_notice( $order, $message, $message_type );

                if ( function_exists( 'wc_add_notice' ) ) {
                    wc_add_notice( $message, $message_type );
                }

                // ResultCode 1048 means the order was already paid (recharge attempt): such an
                // order must not be demoted to 'failed'.
                if ( ! $order->is_paid() ) {
                    $order->update_status( 'failed' );
                }

                $this->safe_log( '---- Piraeus Error -----', [
                    'ResultCode'         => $ResultCode,
                    'ResultDescription'  => $ResultDescription,
                    'SupportReferenceID' => $SupportReferenceID,
                    'MerchantReference'  => $order_id,
                ] );

                if ( WC()->session ) {
                    WC()->session->set( 'pb_pending_order_id', null );
                }

                wp_redirect( $this->pb_failure_redirect_url( $order ) );
                exit;
            }

            $ResponseCode       = sanitize_text_field( $_REQUEST['ResponseCode'] );
            $StatusFlag         = sanitize_text_field( $_REQUEST['StatusFlag'] );
            $HashKey            = sanitize_text_field( $_REQUEST['HashKey'] );
            $SupportReferenceID = absint( $_REQUEST['SupportReferenceID'] );
            $ApprovalCode       = sanitize_text_field( $_REQUEST['ApprovalCode'] );
            $Parameters         = sanitize_text_field( $_REQUEST['Parameters'] );
            $TransactionId      = isset( $_REQUEST['TransactionId'] ) ? absint( $_REQUEST['TransactionId'] ) : '';


			// AuthStatus and PackageNo may be omitted due to IRIS payments — read them defensively only
			$AuthStatus    = isset( $_REQUEST['AuthStatus'] ) ? sanitize_text_field( $_REQUEST['AuthStatus'] ) : '';
			$PackageNo     = !empty( $_REQUEST['PackageNo'] ) ? absint( $_REQUEST['PackageNo'] ) : '';

			// PaymentMethod and CardType
			$PaymentMethod    = isset( $_REQUEST['PaymentMethod'] ) ? sanitize_text_field( $_REQUEST['PaymentMethod'] ) : '';
			$CardType         = isset( $_REQUEST['CardType'] ) ? sanitize_text_field( $_REQUEST['CardType'] ) : '';

			// Log PaymentMethod and CardType for debugging
			if ( $this->pb_enable_log === 'yes' ) {
				error_log( 'PaymentMethod: ' . $PaymentMethod . ' CardType: ' . $CardType );
			}

            $ttquery = $wpdb->prepare(
                'SELECT trans_ticket FROM ' . $wpdb->prefix . 'piraeusbank_transactions WHERE merch_ref = %s LIMIT 100',
                $order_id
            );

            $tt = $wpdb->get_results( $ttquery );
            if ( ! is_array( $tt ) || $wpdb->last_error !== '' ) {
                wp_die( 'Payment confirmation is temporarily unavailable. Please try again shortly.', '', [ 'response' => 503 ] );
                return;
            }

            // Successful tickets are removed from the pending table, but retained on the order.
            // Re-verify retries with that ticket; an ID alone is never proof of payment.
            $saved_ticket = (string) $order->get_meta( '_piraeusbank_trans_ticket', true );
            if ( $saved_ticket !== '' ) {
                $tt[] = (object) [ 'trans_ticket' => $saved_ticket ];
            }

            $this->safe_log( '---- Ticket lookup -----', [ 'MerchantReference' => $order_id, 'candidate_count' => count( $tt ) ] );
            $this->safe_log( '---- End of ttquery ----' );

            $hasHashKeyNotMatched = true;
            $transTicket          = '';

            foreach ( $tt as $transaction ) {
                $candidateTicket = $transaction->trans_ticket;

                // Spec 3.1: HMAC-SHA256 (hex, UPPERCASE), secret key = TranTicket, message = the
                // following fields concatenated with ';' in exactly this order.
                $stconHmac = implode( ';', [
                    $candidateTicket,
                    $this->pb_PosId,
                    $this->pb_AcquirerId,
                    $order_id,
                    $ApprovalCode,
                    $Parameters,
                    $ResponseCode,
                    $SupportReferenceID,
                    $AuthStatus,
                    $PackageNo,
                    $StatusFlag,
                ] );

                $consHashHmac = strtoupper( hash_hmac( 'sha256', $stconHmac, $candidateTicket ) );

                if ( ! hash_equals( $consHashHmac, strtoupper( (string) $HashKey ) ) ) {
                    continue;
                }

                $transTicket          = $candidateTicket;
                $hasHashKeyNotMatched = false;
                break;
            }

            if ( $hasHashKeyNotMatched ) {
                if ( $this->pb_payment_was_completed( $order ) ) {
                    $this->safe_log( 'Piraeus: rejected invalid HashKey without changing a previously paid order.', [ 'MerchantReference' => $order_id ] );
                    wp_redirect( wc_get_checkout_url() );
                    exit;
                }
                // Spec 3.1: a response whose HashKey cannot be verified must NEVER complete the order.
                $message      = 'Δεν ήταν δυνατή η επαλήθευση της απάντησης της τράπεζας. Η παραγγελία δεν ολοκληρώθηκε. Παρακαλούμε επικοινωνήστε με το κατάστημα.';
                $message_type = 'error';
                $pb_message   = [ 'message' => $message, 'message_type' => $message_type ];

                $this->generic_add_meta( $order_id, '_piraeusbank_message', $pb_message );
                $this->generic_add_meta( $order_id, '_piraeusbank_message_debug', [ $pb_message, $consHashHmac . '!=' . $HashKey ] );
                $this->pb_set_customer_notice( $order, $message, $message_type );

                $order->add_order_note(
                    __( 'Piraeus Paycenter: HashKey verification FAILED - the order was NOT completed.', Application::PLUGIN_NAMESPACE )
                    . '<br />SupportReferenceID: ' . $SupportReferenceID
                    . '<br />MerchantReference: ' . $order_id
                    . '<br />ResponseCode: ' . $ResponseCode
                    . '<br />StatusFlag: ' . $StatusFlag
                );

                $order->update_status( 'failed' );

                if ( function_exists( 'wc_add_notice' ) ) {
                    wc_add_notice( $message, $message_type );
                }

                $this->safe_log( '---- Piraeus HashKey mismatch -----', [
                    'MerchantReference'  => $order_id,
                    'SupportReferenceID' => $SupportReferenceID,
                ] );

                if ( WC()->session ) {
                    WC()->session->set( 'pb_pending_order_id', null );
                }

                wp_redirect( $this->pb_failure_redirect_url( $order ) );
                exit;
            }

            // Only authenticated responses reach this point. Do not repeat order mutations,
            // payment hooks, notes, stock changes or notifications for an already handled payment.
            if ( $this->pb_payment_was_completed( $order ) ) {
                $this->safe_log( 'Piraeus: ignored verified callback for a previously paid order.', [ 'MerchantReference' => $order_id ] );
                wp_redirect( $this->get_return_url( $order ) );
                exit;
            }

            $this->pb_store_response_data( $order, $_REQUEST );

            // Spec 3.1: an approved transaction has ResultCode 0 AND StatusFlag 'Success'.
            $pb_is_approved = ( $StatusFlag === '' || strcasecmp( $StatusFlag, 'Success' ) === 0 );

            if ( $pb_is_approved && ( $ResponseCode == 0 || $ResponseCode == 8 || $ResponseCode == 10 || $ResponseCode == 16 ) ) {
                $this->generic_add_meta( $order_id, '_piraeusbank_transaction_id', $TransactionId );
                $this->generic_add_meta( $order_id, '_piraeusbank_support_reference_id', $SupportReferenceID );
                $this->generic_add_meta( $order_id, '_piraeusbank_trans_ticket', $transTicket );
                $this->generic_add_meta( $order_id, '_piraeusbank_response_code', $ResponseCode );
                $this->generic_add_meta( $order_id, '_piraeusbank_processed_at', current_time( 'mysql' ) );

                $order->payment_complete( $TransactionId );
                if ( $order->is_paid() || $order->get_date_paid() ) {
                    $order->update_meta_data( '_piraeusbank_payment_processed', 1 );
                    $order->save();
                }

				//Add admin order note
                $order->add_order_note( __( 'Payment Via Peiraeus Bank<br />Transaction ID: ', Application::PLUGIN_NAMESPACE ) . $TransactionId . __( '<br />Support Reference ID: ', Application::PLUGIN_NAMESPACE ) . $SupportReferenceID );

				// Mark IRIS payments explicitly when detected
				if ( !empty( $PaymentMethod ) && strtoupper( $PaymentMethod ) === 'IRIS' ) {
                    $order->add_order_note( __( 'Payment method detected: IRIS (Piraeus Bank).', Application::PLUGIN_NAMESPACE ) );
				} elseif ( !empty( $CardType ) && (string) $CardType === '15' ) {
					// CardType '15' also indicates IRIS according to epay spec
                    $order->add_order_note( __( 'CardType indicates IRIS payment (CardType:15).', Application::PLUGIN_NAMESPACE ) );
				}

                $message = '';
                if ( $order->get_status() === 'processing' ) {
                    $message = __( 'Thank you for shopping with us.<br />Your transaction was successful, payment was received.<br />Your order is currently being processed.', Application::PLUGIN_NAMESPACE );

                    if ( $this->pb_order_note === 'yes' ) {
                        $order->add_order_note( __( 'Payment Received.<br />Your order is currently being processed.<br />We will be shipping your order to you soon.<br />Peiraeus Bank ID: ', Application::PLUGIN_NAMESPACE ) . $TransactionId . __( '<br />Support Reference ID: ', Application::PLUGIN_NAMESPACE ) . $SupportReferenceID, 1 );
                    }
                } else if ( $order->get_status() === 'completed' ) {
                    $message = __( 'Thank you for shopping with us.<br />Your transaction was successful, payment was received.<br />Your order is now complete.', Application::PLUGIN_NAMESPACE );

                    if ( $this->pb_order_note === 'yes' ) {
                        $order->add_order_note( __( 'Payment Received.<br />Your order is now complete.<br />Peiraeus Transaction ID: ', Application::PLUGIN_NAMESPACE ) . $TransactionId . __( '<br />Support Reference ID: ', Application::PLUGIN_NAMESPACE ) . $SupportReferenceID, 1 );
                    }
                }

                $message_type = 'success';

                $pb_message = $this->set_message( $order, $message, $message_type );

                $this->safe_log( '---- Piraeus Payment Success -----', [
                    'ResponseCode' => $ResponseCode,
                    'message'      => $message,
                ] );

				// Empty cart (WC()->cart is not initialised on the bank cross-site POST)
				if ( WC()->cart ) {
					WC()->cart->empty_cart();
				}
                $wpdb->delete(
                    $wpdb->prefix . 'piraeusbank_transactions',
                    [ 'trans_ticket' => $transTicket ],
                    [ '%s' ]
                );

                // Clear session after successful processing
				if ( WC()->session ) {
					WC()->session->set( 'pb_pending_order_id', null );
				}
			}
			else if ( $ResponseCode == 11 ) {
				$message      = __( 'Thank you for shopping with us.<br />Your transaction was previously received.<br />', Application::PLUGIN_NAMESPACE );
				$message_type = 'success';

                $pb_message = $this->set_message( $order, $message, $message_type );

				// Clear session after processing
				if ( WC()->session ) {
					WC()->session->set( 'pb_pending_order_id', null );
				}

                $this->safe_log( '---- Piraeus Payment Previously Received -----', [
                    'ResponseCode' => $ResponseCode,
                    'message'      => $message,
                ] );
            } else { // Declined by the issuer: ResultCode 0 but StatusFlag != 'Success' (spec 3.1).
                $ResponseDescription = isset( $_REQUEST['ResponseDescription'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['ResponseDescription'] ) ) : '';

                $order->add_order_note(
                    __( 'Piraeus Paycenter: transaction DECLINED.', Application::PLUGIN_NAMESPACE )
                    . '<br />SupportReferenceID: ' . $SupportReferenceID
                    . '<br />MerchantReference: ' . $order_id
                    . '<br />ResultCode: ' . $ResultCode
                    . '<br />ResponseCode: ' . $ResponseCode
                    . '<br />ResponseDescription: ' . $ResponseDescription
                    . '<br />StatusFlag: ' . $StatusFlag
                );

                // Generic message only - the spec forbids showing the raw bank description.
                $message      = 'Η συναλλαγή δεν εγκρίθηκε από την εκδότρια τράπεζα. Παρακαλούμε δοκιμάστε ξανά ή χρησιμοποιήστε άλλη κάρτα.';
                $message_type = 'error';

                $pb_message = $this->set_message( $order, $message, $message_type );
                $this->pb_set_customer_notice( $order, $message, $message_type );

                if ( ! $order->is_paid() ) {
                    $order->update_status( 'failed' );
                }

                if ( function_exists( 'wc_add_notice' ) ) {
                    wc_add_notice( $message, $message_type );
                }

                $this->safe_log( '---- Piraeus Payment NOT Received -----', [
                    'ResponseCode' => $ResponseCode,
                    'StatusFlag'   => $StatusFlag,
                    'message'      => $message,
                ] );

                if ( WC()->session ) {
                    WC()->session->set( 'pb_pending_order_id', null );
                }

                wp_redirect( $this->pb_failure_redirect_url( $order ) );
                exit;
            }
        }

        if ( isset( $_REQUEST['peiraeus'], $_REQUEST['MerchantReference'] ) && $_REQUEST['peiraeus'] === 'fail' ) {
            $order_id     = sanitize_text_field( $_REQUEST['MerchantReference'] );

			// Session validation: ensure the callback matches the user who initiated the payment
			// The bank posts cross-site: with SameSite=Lax the WooCommerce session cookie is
			// normally NOT sent, so a missing session must not be treated as a rejection - that
			// silently dropped the customer on the checkout page without any message.
			$session_order_id = WC()->session ? WC()->session->get( 'pb_pending_order_id' ) : null;
			if ( $session_order_id !== null && $session_order_id != $order_id ) {
				if ( $this->pb_enable_log === 'yes' ) {
					error_log( 'Piraeus Bank fail callback rejected: session validation failed for order ' . $order_id );
				}
				wp_redirect( wc_get_checkout_url() );
				exit;
			}

            $order        = wc_get_order( $order_id );
            $message      = __( 'Thank you for shopping with us. <br />However, the transaction wasn\'t successful, payment wasn\'t received.', Application::PLUGIN_NAMESPACE );
            if ( ! $order ) { return; }
            if ( $this->pb_payment_was_completed( $order ) ) {
                $this->safe_log( 'Piraeus: ignored late fail callback for a previously paid order.', [ 'MerchantReference' => $order_id ] );
                wp_redirect( wc_get_checkout_url() );
                exit;
            }
            $message_type = 'error';

            $transaction_id = absint( $_REQUEST['SupportReferenceID'] );
            if ( $this->pb_order_note === 'yes' ) {
                //Add Customer Order Note
                $order->add_order_note( $message . '<br />Piraeus Bank Support Reference ID: ' . $transaction_id, 1 );
            }

            //Add Admin Order Note
            $order->add_order_note( $message . '<br />Piraeus Bank Support Reference ID: ' . $transaction_id );


            //Update the order status - never demote an order that has already been paid.
            if ( ! $order->is_paid() ) {
                $order->update_status( 'failed' );
            }

            $pb_message = $this->set_message( $order, $message, $message_type );
            $this->pb_set_customer_notice( $order, $message, $message_type );

            if ( function_exists( 'wc_add_notice' ) ) {
                wc_add_notice( $message, $message_type );
            }

            $this->safe_log( '---- Piraeus Payment Failed -----', [
                'message' => $message,
            ] );

			// Clear session after fail processing
			if ( WC()->session ) {
				WC()->session->set( 'pb_pending_order_id', null );
			}

            wp_redirect( $this->pb_failure_redirect_url( $order ) );
            exit;
        }

        if ( isset( $_REQUEST['peiraeus'] ) && ( $_REQUEST['peiraeus'] === 'cancel' ) ) {
            $this->safe_log( '---- Piraeus Payment Canceled -----' );

			// Session validation for cancel callback
			$cancel_order_id = isset( $_REQUEST['MerchantReference'] ) ? filter_var( $_REQUEST['MerchantReference'], FILTER_SANITIZE_STRING ) : null;
			$session_order_id = WC()->session ? WC()->session->get( 'pb_pending_order_id' ) : null;

			// Reject if session doesn't match (when order ID is provided)
			if ( $cancel_order_id !== null && ( $session_order_id === null || $session_order_id != $cancel_order_id ) ) {
				if ( $this->pb_enable_log === 'yes' ) {
					error_log( 'Piraeus Bank cancel callback rejected: session validation failed for order ' . $cancel_order_id );
				}
				wp_redirect( wc_get_checkout_url() );
				exit;
			}

			// Clear session after cancel processing
			if ( WC()->session ) {
				WC()->session->set( 'pb_pending_order_id', null );
			}

            $checkout_url = wc_get_checkout_url();
            wp_redirect( $checkout_url );
            exit;
        }

        if ( $this->redirect_page_id == -1 && $order !== null ) {
            $redirect_url = $this->get_return_url( $order );
        } else {
            $redirect_url = add_query_arg( [ 'msg' => urlencode( isset( $pb_message['message'] ) ? $pb_message['message'] : '' ), 'type' => ( isset( $pb_message['message_type'] ) ? $pb_message['message_type'] : '' ) ], ( $this->redirect_page_id === "" || $this->redirect_page_id === 0 ) ? get_site_url() . "/" : get_permalink( $this->redirect_page_id ) );
        }

        wp_redirect( $redirect_url );

        exit;
    }

    /**
     * @param $orderid
     * @param $key
     * @param $value
     *
     * @return void
     */
    /**
     * Persist every field returned by Piraeus Paycenter on the order (spec 3.1).
     *
     * @param \WC_Order $order
     * @param array     $data
     *
     * @return void
     */
    private function pb_store_response_data( $order, $data ) {
        if ( ! $order || ! is_array( $data ) ) {
            return;
        }

        $fields = [
            'SupportReferenceID'  => '_piraeusbank_support_reference_id',
            'MerchantReference'   => '_piraeusbank_merchant_reference',
            'TransactionId'       => '_piraeusbank_transaction_id',
            'ResponseCode'        => '_piraeusbank_response_code',
            'ResponseDescription' => '_piraeusbank_response_description',
            'ApprovalCode'        => '_piraeusbank_approval_code',
            'PackageNo'           => '_piraeusbank_package_no',
            'AuthStatus'          => '_piraeusbank_auth_status',
            'PaymentMethod'       => '_piraeusbank_payment_method',
            'TraceID'             => '_piraeusbank_trace_id',
            'StatusFlag'          => '_piraeusbank_status_flag',
            'ResultCode'          => '_piraeusbank_result_code',
            'ResultDescription'   => '_piraeusbank_result_description',
        ];

        $note_lines = [];

        foreach ( $fields as $key => $meta_key ) {
            if ( ! isset( $data[ $key ] ) || $data[ $key ] === '' ) {
                continue;
            }

            $value = sanitize_text_field( wp_unslash( $data[ $key ] ) );

            $order->update_meta_data( sanitize_key( $meta_key ), $value );
            $note_lines[] = $key . ': ' . $value;
        }

        $order->save();

        if ( ! empty( $note_lines ) ) {
            $order->add_order_note( __( 'Piraeus Paycenter response', Application::PLUGIN_NAMESPACE ) . '<br />' . implode( '<br />', $note_lines ) );
        }
    }

    /**
     * Friendly customer facing message for a technical ResultCode (spec 3.1).
     * The raw ResultDescription is never shown to the customer.
     *
     * @param int|string $result_code
     *
     * @return string
     */
    private function pb_technical_error_message( $result_code ) {
        $code = (string) $result_code;

        $map = [
            '1'    => 'Παρουσιάστηκε γενικό σφάλμα κατά την επεξεργασία της πληρωμής. Παρακαλούμε δοκιμάστε ξανά.',
            '981'  => 'Τα στοιχεία της κάρτας δεν είναι έγκυρα. Παρακαλούμε ελέγξτε τα και δοκιμάστε ξανά.',
            '1045' => 'Η συναλλαγή βρίσκεται ήδη σε επεξεργασία. Παρακαλούμε δοκιμάστε ξανά σε λίγο.',
            '1048' => 'Η παραγγελία έχει ήδη πληρωθεί ή ο κωδικός συναλλαγής έχει ήδη χρησιμοποιηθεί.',
            '1072' => 'Η τράπεζα εκτελεί κλείσιμο πακέτου συναλλαγών. Παρακαλούμε δοκιμάστε ξανά σε λίγα λεπτά.',
        ];

        if ( isset( $map[ $code ] ) ) {
            return $map[ $code ];
        }

        if ( preg_match( '/^50[0-9]$/', $code ) ) {
            return 'Δεν ήταν δυνατή η επικοινωνία με την τράπεζα. Παρακαλούμε δοκιμάστε ξανά σε λίγο.';
        }

        return 'Δεν ήταν δυνατή η ολοκλήρωση της πληρωμής.';
    }

    /**
     * Validation used on the "Customer payment page" (checkout/order-pay).
     *
     * WooCommerce (WC_Form_Handler::pay_action()) calls validate_fields() there too, but that
     * form does NOT post any billing_* field, so the classic $_POST based validation always
     * failed, wc_notice_count('error') became > 0 and process_payment() was never reached:
     * the page simply reloaded showing "... is a mandatory field!" errors.
     *
     * @return bool
     */
    private function pb_validate_order_for_pay() {
        global $wp;

        $order_id = 0;

        if ( isset( $wp->query_vars['order-pay'] ) ) {
            $order_id = absint( $wp->query_vars['order-pay'] );
        }

        if ( ! $order_id && isset( $_GET['order-pay'] ) ) {
            $order_id = absint( $_GET['order-pay'] );
        }

        $order = $order_id ? wc_get_order( $order_id ) : false;

        if ( ! $order ) {
            return true;
        }

        $missing = [];

        if ( ! $order->get_billing_email() )     { $missing[] = 'E-mail'; }
        if ( ! $order->get_billing_city() )      { $missing[] = 'Πόλη'; }
        if ( ! $order->get_billing_country() )   { $missing[] = 'Χώρα'; }
        if ( ! $order->get_billing_address_1() ) { $missing[] = 'Διεύθυνση'; }
        if ( ! $order->get_billing_postcode() )  { $missing[] = 'Τ.Κ.'; }

        if ( empty( $missing ) ) {
            return true;
        }

        wc_add_notice( 'Λείπουν στοιχεία χρέωσης από την παραγγελία (' . implode( ', ', $missing ) . '). Παρακαλούμε επικοινωνήστε με το κατάστημα.', 'error' );

        return false;
    }

    public function generic_add_meta( $orderid, $key, $value ) {
        $order = wc_get_order( absint( $orderid ) );
        if ( $order ) {
            $order->update_meta_data( sanitize_key( $key ), $value );
            $order->save();
        }
    }

    /**
     * @param \WC_Order $order
     * @param string   $message
     * @param string   $message_type
     *
     * @return array{message: string, message_type: string}
     */
    public function set_message( \WC_Order $order, string $message, string $message_type ) {
        $pb_message = [
            'message'      => $message,
            'message_type' => $message_type,
        ];

        $this->generic_add_meta( $order->get_id(), '_piraeusbank_message', $pb_message );
        $this->generic_add_meta( $order->get_id(), '_piraeusbank_message_debug', $pb_message );

        return $pb_message;
    }

    /**
     * Persist the customer facing message on the order itself.
     *
     * The bank answers with a cross-site POST to the WC API endpoint. Because of SameSite=Lax the
     * WooCommerce session cookie is not sent with it, so wc_add_notice() writes the notice into a
     * brand new session that is thrown away straight afterwards. Storing the message on the order
     * makes it survive until the customer browser follows the redirect.
     *
     * @param \WC_Order|null $order
     * @param string         $message
     * @param string         $message_type 'error' or 'notice'.
     *
     * @return void
     */
    public function pb_set_customer_notice( $order, $message, $message_type = 'error' ) {
        if ( ! $order || ! ( $order instanceof \WC_Order ) || '' === (string) $message ) {
            return;
        }

        $order->update_meta_data( '_piraeusbank_customer_notice', [
            'message' => (string) $message,
            'type'    => in_array( $message_type, [ 'error', 'success', 'notice' ], true ) ? $message_type : 'error',
        ] );
        $order->save();
    }

    /**
     * Where the customer has to be sent after a failed / declined transaction.
     *
     * @param \WC_Order|null $order
     *
     * @return string
     */
    private function pb_failure_redirect_url( $order ) {
        if ( ! $order || ! ( $order instanceof \WC_Order ) ) {
            return add_query_arg( 'pb_notice', '1', wc_get_checkout_url() );
        }

        // An order that is already paid (ResultCode 1048 recharge attempt) must never be sent to
        // the cart/checkout: the cart is legitimately empty there, which is exactly the blank
        // "your cart is empty" page the customer was left with.
        if ( $order->is_paid() || ! $order->needs_payment() ) {
            $url = $order->get_checkout_order_received_url();
        } else {
            $url = $order->get_checkout_payment_url( false );
        }

        return add_query_arg( 'pb_notice', '1', $url );
    }

    /**
     * Resolve and validate the order of the current front-end request.
     *
     * @return \WC_Order|null
     */
    private function pb_resolve_order_from_request() {
        global $wp;

        $order_id = 0;

        foreach ( [ 'order-pay', 'order-received', 'view-order' ] as $pb_query_var ) {
            if ( isset( $wp->query_vars[ $pb_query_var ] ) && absint( $wp->query_vars[ $pb_query_var ] ) ) {
                $order_id = absint( $wp->query_vars[ $pb_query_var ] );
                break;
            }
        }

        if ( ! $order_id && isset( $_GET['order-pay'] ) ) {
            $order_id = absint( $_GET['order-pay'] );
        }

        if ( ! $order_id && isset( $_GET['order-received'] ) ) {
            $order_id = absint( $_GET['order-received'] );
        }

        if ( ! $order_id && isset( $_GET['order_id'] ) ) {
            $order_id = absint( $_GET['order_id'] );
        }

        if ( ! $order_id ) {
            return null;
        }

        $order = wc_get_order( $order_id );

        if ( ! $order || ! ( $order instanceof \WC_Order ) ) {
            return null;
        }

        $key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';

        if ( '' !== $key ) {
            return hash_equals( (string) $order->get_order_key(), $key ) ? $order : null;
        }

        // No order key in the URL (my-account/view-order): only the owner may see the message.
        $customer_id = (int) $order->get_customer_id();

        return ( $customer_id && get_current_user_id() === $customer_id ) ? $order : null;
    }

    /**
     * Print the message that check_piraeusbank_response() stored on the order, exactly once.
     *
     * @return void
     */
    public function pb_maybe_render_stored_notice() {
        static $pb_already_run = false;

        if ( $pb_already_run || is_admin() || ! isset( $_GET['pb_notice'] ) ) {
            return;
        }

        $order = $this->pb_resolve_order_from_request();

        if ( ! $order ) {
            return;
        }

        $notice = $order->get_meta( '_piraeusbank_customer_notice', true );

        if ( empty( $notice ) || empty( $notice['message'] ) ) {
            return;
        }

        $pb_already_run = true;

        $message = (string) $notice['message'];
        $type    = ! empty( $notice['type'] ) ? (string) $notice['type'] : 'error';

        // Show it only once.
        $order->delete_meta_data( '_piraeusbank_customer_notice' );
        $order->save();

        $this->pb_pending_notice = [ 'message' => $message, 'type' => $type ];

        if ( WC()->session && function_exists( 'wc_add_notice' ) ) {
            wc_add_notice( $message, $type );
            $this->pb_pending_notice = null;
        }
    }

    /**
     * The order-received template does not print the WooCommerce notice queue on its own, and a
     * customer whose session cookie was dropped has no queue at all, so render it explicitly.
     *
     * @return void
     */
    public function pb_output_pending_notices() {
        if ( ! empty( $this->pb_pending_notice ) && function_exists( 'wc_print_notice' ) ) {
            wc_print_notice( $this->pb_pending_notice['message'], $this->pb_pending_notice['type'] );
            $this->pb_pending_notice = null;

            return;
        }

        if ( function_exists( 'wc_notice_count' ) && wc_notice_count() > 0 && function_exists( 'woocommerce_output_all_notices' ) ) {
            woocommerce_output_all_notices();
        }
    }

    /**
     * Validate frontend fields.
     *
     * Validate payment fields on the frontend.
     *
     * @return bool
     */
    public function validate_fields() {
        // On the "Customer payment page" (checkout/order-pay) the billing fields are not part of
        // the submitted form; validate the order instead of $_POST (see pb_validate_order_for_pay).
        if ( isset( $_POST['woocommerce_pay'] ) || ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) ) {
            return $this->pb_validate_order_for_pay();
        }
        $requiredFields = [
            'billing_email'     => 'E-mail address',
            'billing_city'      => 'Billing town/city',
            'billing_country'   => 'Billing country / region',
            'billing_state'     => 'Billing state / county',
            'billing_address_1' => 'Billing street address',
            'billing_postcode'  => 'Billing postcode / ZIP',
        ];

        $validation_failed = false;

        foreach ($requiredFields as $field => $info) {
            if ( ! isset( $_POST[ $field ] ) || trim( $_POST[ $field ] ) === '' ) {

                if ( defined( 'REST_REQUEST' ) ) {
                    //$parameters = $request->get_query_params();
                    //We're inside a rest API request.
                    //var_dump($_GET);
                    return false;
                }

                wc_add_notice(
                    __( $info . ' is a mandatory field!' ),
                    'error'
                );
                $validation_failed = true;
            }
        }
        return ! $validation_failed;
    }

    /**
     * Safely log data by removing sensitive information
     *
     * @param string $message
     * @param mixed $data
     * @return void
     */
    private function safe_log( $message, $data = null ) {
        if ( $this->pb_enable_log !== 'yes' ) {
            return;
        }

        if ( function_exists( 'wc_get_logger' ) ) {
            $logger  = wc_get_logger();
            $context = [ 'source' => 'piraeusbank_gateway' ];

            $logger->info( $message, $context );

            if ( $data !== null ) {
                $sanitized = $this->sanitize_log_data( $data );
                $logger->debug( print_r( $sanitized, true ), $context );
            }
            return;
        }

        error_log( $message );

        if ( $data !== null ) {
            $sanitized = $this->sanitize_log_data( $data );
            error_log( print_r( $sanitized, true ) );
        }
    }

    /**
     * Remove sensitive information from data before logging
     *
     * @param mixed $data
     * @return mixed
     */
    private function sanitize_log_data( $data ) {
        if ( is_string( $data ) ) {
            // Replace password with redacted text
            $data = str_replace( $this->pb_Password, '[REDACTED]', $data );
            $data = str_replace( hash( 'md5', $this->pb_Password ), '[REDACTED]', $data );
        } elseif ( is_array( $data ) ) {
            // Sanitize sensitive keys in arrays
            $sensitive_keys = [ 'Password', 'pb_Password', 'HashKey' ];
            foreach ($data as $key => $value) {
                if ( in_array( $key, $sensitive_keys ) ) {
                    $data[ $key ] = '[REDACTED]';
                } else {
                    $data[ $key ] = $this->sanitize_log_data( $value );
                }
            }
        }

        return $data;
    }

    private function pb_getCardholderName( $orderId, $name, $enabled ) {
        //check if has the field
        if ( $enabled == 'yes' ) {
            $order = wc_get_order( $orderId ); $cardholder_field = $order ? $order->get_meta( 'cardholder_name', true ) : ' ';
            if ( ! empty( $cardholder_field ) ) {
                return $this->pb_convertNonLatinToLatin( $cardholder_field );
            }
        }
        return $this->pb_convertNonLatinToLatin( $name );
    }

    private function pb_getCountryNumericCode( $country ) {
        $countries = array(
            "AF" => "004",
            "AL" => "008",
            "DZ" => "012",
            "AS" => "016",
            "AD" => "020",
            "AO" => "024",
            "AI" => "660",
            "AQ" => "010",
            "AG" => "028",
            "AR" => "032",
            "AM" => "051",
            "AW" => "533",
            "AU" => "036",
            "AT" => "040",
            "AZ" => "031",
            "BS" => "044",
            "BH" => "048",
            "BD" => "050",
            "BB" => "052",
            "BY" => "112",
            "BE" => "056",
            "BZ" => "084",
            "BJ" => "204",
            "BM" => "060",
            "BT" => "064",
            "BO" => "068",
            "BQ" => "535",
            "BA" => "070",
            "BW" => "072",
            "BV" => "074",
            "BR" => "076",
            "IO" => "086",
            "BN" => "096",
            "BG" => "100",
            "BF" => "854",
            "BI" => "108",
            "CV" => "132",
            "KH" => "116",
            "CM" => "120",
            "CA" => "124",
            "KY" => "136",
            "CF" => "140",
            "TD" => "148",
            "CL" => "152",
            "CN" => "156",
            "CX" => "162",
            "CC" => "166",
            "CO" => "170",
            "KM" => "174",
            "CD" => "180",
            "CG" => "178",
            "CK" => "184",
            "CR" => "188",
            "HR" => "191",
            "CU" => "192",
            "CW" => "531",
            "CY" => "196",
            "CZ" => "203",
            "CI" => "384",
            "DK" => "208",
            "DJ" => "262",
            "DM" => "212",
            "DO" => "214",
            "EC" => "218",
            "EG" => "818",
            "SV" => "222",
            "GQ" => "226",
            "ER" => "232",
            "EE" => "233",
            "SZ" => "748",
            "ET" => "231",
            "FK" => "238",
            "FO" => "234",
            "FJ" => "242",
            "FI" => "246",
            "FR" => "250",
            "GF" => "254",
            "PF" => "258",
            "TF" => "260",
            "GA" => "266",
            "GM" => "270",
            "GE" => "268",
            "DE" => "276",
            "GH" => "288",
            "GI" => "292",
            "GR" => "300",
            "GL" => "304",
            "GD" => "308",
            "GP" => "312",
            "GU" => "316",
            "GT" => "320",
            "GG" => "831",
            "GN" => "324",
            "GW" => "624",
            "GY" => "328",
            "HT" => "332",
            "HM" => "334",
            "VA" => "336",
            "HN" => "340",
            "HK" => "344",
            "HU" => "348",
            "IS" => "352",
            "IN" => "356",
            "ID" => "360",
            "IR" => "364",
            "IQ" => "368",
            "IE" => "372",
            "IM" => "833",
            "IL" => "376",
            "IT" => "380",
            "JM" => "388",
            "JP" => "392",
            "JE" => "832",
            "JO" => "400",
            "KZ" => "398",
            "KE" => "404",
            "KI" => "296",
            "KP" => "408",
            "KR" => "410",
            "KW" => "414",
            "KG" => "417",
            "LA" => "418",
            "LV" => "428",
            "LB" => "422",
            "LS" => "426",
            "LR" => "430",
            "LY" => "434",
            "LI" => "438",
            "LT" => "440",
            "LU" => "442",
            "MO" => "446",
            "MG" => "450",
            "MW" => "454",
            "MY" => "458",
            "MV" => "462",
            "ML" => "466",
            "MT" => "470",
            "MH" => "584",
            "MQ" => "474",
            "MR" => "478",
            "MU" => "480",
            "YT" => "175",
            "MX" => "484",
            "FM" => "583",
            "MD" => "498",
            "MC" => "492",
            "MN" => "496",
            "ME" => "499",
            "MS" => "500",
            "MA" => "504",
            "MZ" => "508",
            "MM" => "104",
            "NA" => "516",
            "NR" => "520",
            "NP" => "524",
            "NL" => "528",
            "NC" => "540",
            "NZ" => "554",
            "NI" => "558",
            "NE" => "562",
            "NG" => "566",
            "NU" => "570",
            "NF" => "574",
            "MP" => "580",
            "NO" => "578",
            "OM" => "512",
            "PK" => "586",
            "PW" => "585",
            "PS" => "275",
            "PA" => "591",
            "PG" => "598",
            "PY" => "600",
            "PE" => "604",
            "PH" => "608",
            "PN" => "612",
            "PL" => "616",
            "PT" => "620",
            "PR" => "630",
            "QA" => "634",
            "MK" => "807",
            "RO" => "642",
            "RU" => "643",
            "RW" => "646",
            "RE" => "638",
            "BL" => "652",
            "SH" => "654",
            "KN" => "659",
            "LC" => "662",
            "MF" => "663",
            "PM" => "666",
            "VC" => "670",
            "WS" => "882",
            "SM" => "674",
            "ST" => "678",
            "SA" => "682",
            "SN" => "686",
            "RS" => "688",
            "SC" => "690",
            "SL" => "694",
            "SG" => "702",
            "SX" => "534",
            "SK" => "703",
            "SI" => "705",
            "SB" => "090",
            "SO" => "706",
            "ZA" => "710",
            "GS" => "239",
            "SS" => "728",
            "ES" => "724",
            "LK" => "144",
            "SD" => "729",
            "SR" => "740",
            "SJ" => "744",
            "SE" => "752",
            "CH" => "756",
            "SY" => "760",
            "TW" => "158",
            "TJ" => "762",
            "TZ" => "834",
            "TH" => "764",
            "TL" => "626",
            "TG" => "768",
            "TK" => "772",
            "TO" => "776",
            "TT" => "780",
            "TN" => "788",
            "TR" => "792",
            "TM" => "795",
            "TC" => "796",
            "TV" => "798",
            "UG" => "800",
            "UA" => "804",
            "AE" => "784",
            "GB" => "826",
            "UM" => "581",
            "US" => "840",
            "UY" => "858",
            "UZ" => "860",
            "VU" => "548",
            "VE" => "862",
            "VN" => "704",
            "VG" => "092",
            "VI" => "850",
            "WF" => "876",
            "EH" => "732",
            "YE" => "887",
            "ZM" => "894",
            "ZW" => "716",
            "AX" => "248",
        );

        if ( isset( $countries[ $country ] ) ) {
            return $countries[ $country ];
        }
        // if nothing found - return Greece
        return '300';
    }

    private function pb_getCountryPhoneCode( $country ) {
        if ( empty( $country ) ) {
            $default_location = wc_get_customer_default_location();
            $country          = $default_location['country'];
        }

        $countries_phone_codes = array(
            "AF" => "93",
            "AL" => "355",
            "DZ" => "213",
            "AS" => "1684",
            "AD" => "376",
            "AO" => "244",
            "AI" => "1264",
            "AQ" => "672",
            "AG" => "1268",
            "AR" => "54",
            "AM" => "374",
            "AW" => "297",
            "AU" => "61",
            "AT" => "43",
            "AZ" => "994",
            "BS" => "1242",
            "BH" => "973",
            "BD" => "880",
            "BB" => "1246",
            "BY" => "375",
            "BE" => "32",
            "BZ" => "501",
            "BJ" => "229",
            "BM" => "1441",
            "BT" => "975",
            "BO" => "591",
            "BA" => "387",
            "BW" => "267",
            "BV" => "74",
            "BR" => "55",
            "BL" => "590",
            "BQ" => "599",
            "CW" => "599",
            "GG" => "44",
            "IO" => "246",
            "BN" => "673",
            "BG" => "359",
            "GR" => "30",
            "AX" => "358",
            "GB" => "44",
            "IM" => "44",
            "JE" => "44",
            "ME" => "382",
            "MF" => "1599",
            "PS" => "970",
            "RS" => "381",
            "SX" => "1721",
            "TL" => "670",
            "IR" => "98",
            "BF" => "226",
            "BI" => "257",
            "KH" => "855",
            "CM" => "237",
            "CA" => "1",
            "CV" => "238",
            "KY" => "1345",
            "CF" => "236",
            "TD" => "235",
            "CL" => "56",
            "CN" => "86",
            "CX" => "61",
            "CC" => "61",
            "CO" => "57",
            "KM" => "269",
            "CG" => "242",
            "CD" => "243",
            "CK" => "682",
            "CR" => "506",
            "CI" => "225",
            "HR" => "385",
            "CY" => "357",
            "CZ" => "420",
            "DK" => "45",
            "DJ" => "253",
            "DM" => "1767",
            "DO" => "1809",
            "EC" => "593",
            "EG" => "20",
            "SV" => "503",
            "GQ" => "240",
            "ER" => "291",
            "EE" => "372",
            "ET" => "251",
            "FK" => "500",
            "FO" => "298",
            "FJ" => "679",
            "FI" => "358",
            "FR" => "33",
            "GF" => "594",
            "PF" => "689",
            "GA" => "241",
            "GM" => "220",
            "GE" => "995",
            "DE" => "49",
            "GH" => "233",
            "GI" => "350",
            "GL" => "299",
            "GD" => "1473",
            "GP" => "590",
            "GU" => "1671",
            "GT" => "502",
            "GN" => "224",
            "GW" => "245",
            "GY" => "592",
            "HT" => "509",
            "VA" => "39",
            "HN" => "504",
            "HK" => "852",
            "HU" => "36",
            "IS" => "354",
            "IN" => "91",
            "ID" => "62",
            "IQ" => "964",
            "IE" => "353",
            "IL" => "972",
            "IT" => "39",
            "JM" => "1876",
            "JP" => "81",
            "JO" => "962",
            "KZ" => "7",
            "KE" => "254",
            "KI" => "686",
            "KR" => "82",
            "KW" => "965",
            "KG" => "996",
            "LA" => "856",
            "LV" => "371",
            "LB" => "961",
            "LS" => "266",
            "LR" => "231",
            "LI" => "423",
            "LT" => "370",
            "LU" => "352",
            "MO" => "853",
            "MK" => "389",
            "MG" => "261",
            "MW" => "265",
            "MY" => "60",
            "MV" => "960",
            "ML" => "223",
            "MT" => "356",
            "MH" => "692",
            "MQ" => "596",
            "MR" => "222",
            "MU" => "230",
            "YT" => "262",
            "MX" => "52",
            "FM" => "691",
            "MD" => "373",
            "MC" => "377",
            "MN" => "976",
            "MS" => "1664",
            "MA" => "212",
            "MZ" => "258",
            "NA" => "264",
            "NR" => "674",
            "NP" => "977",
            "NL" => "31",
            "NC" => "687",
            "NZ" => "64",
            "NI" => "505",
            "NE" => "227",
            "NG" => "234",
            "NU" => "683",
            "NF" => "672",
            "MP" => "1670",
            "NO" => "47",
            "OM" => "968",
            "PK" => "92",
            "PW" => "680",
            "PA" => "507",
            "PG" => "675",
            "PY" => "595",
            "PE" => "51",
            "PH" => "63",
            "PN" => "870",
            "PL" => "48",
            "PT" => "351",
            "PR" => "1",
            "QA" => "974",
            "RE" => "262",
            "RO" => "40",
            "RU" => "7",
            "RW" => "250",
            "SH" => "290",
            "KN" => "1869",
            "LC" => "1758",
            "PM" => "508",
            "VC" => "1784",
            "WS" => "685",
            "SM" => "378",
            "ST" => "239",
            "SA" => "966",
            "SN" => "221",
            "SC" => "248",
            "SL" => "232",
            "SG" => "65",
            "SK" => "421",
            "SI" => "386",
            "SB" => "677",
            "SO" => "252",
            "ZA" => "27",
            "GS" => "500",
            "ES" => "34",
            "LK" => "94",
            "SR" => "597",
            "SJ" => "47",
            "SZ" => "268",
            "SE" => "46",
            "CH" => "41",
            "TW" => "886",
            "TJ" => "992",
            "TZ" => "255",
            "TH" => "66",
            "TG" => "228",
            "TK" => "690",
            "TO" => "676",
            "TT" => "1868",
            "TN" => "216",
            "TR" => "90",
            "TM" => "993",
            "TC" => "1649",
            "TV" => "688",
            "UG" => "256",
            "UA" => "380",
            "AE" => "971",
            "US" => "1",
            "UY" => "598",
            "UZ" => "998",
            "VU" => "678",
            "VE" => "58",
            "VN" => "84",
            "VG" => "1284",
            "VI" => "1340",
            "WF" => "681",
            "YE" => "967",
            "ZM" => "260",
            "IC" => "34",
        );
        if ( isset( $countries_phone_codes[ $country ] ) ) {
            return $countries_phone_codes[ $country ];
        }
        // if nothing found - return Greece
        return '30';
    }

    private function pb_validatePhoneNumberAllCountries( $phone, $country ) {
        $countries_phone_codes = array(
            "AF" => "93",
            "AL" => "355",
            "DZ" => "213",
            "AS" => "1684",
            "AD" => "376",
            "AO" => "244",
            "AI" => "1264",
            "AQ" => "672",
            "AG" => "1268",
            "AR" => "54",
            "AM" => "374",
            "AW" => "297",
            "AU" => "61",
            "AT" => "43",
            "AZ" => "994",
            "BS" => "1242",
            "BH" => "973",
            "BD" => "880",
            "BB" => "1246",
            "BY" => "375",
            "BE" => "32",
            "BZ" => "501",
            "BJ" => "229",
            "BM" => "1441",
            "BT" => "975",
            "BO" => "591",
            "BA" => "387",
            "BW" => "267",
            "BV" => "74",
            "BR" => "55",
            "BL" => "590",
            "BQ" => "599",
            "CW" => "599",
            "GG" => "44",
            "IO" => "246",
            "BN" => "673",
            "BG" => "359",
            "GR" => "30",
            "AX" => "358",
            "GB" => "44",
            "IM" => "44",
            "JE" => "44",
            "ME" => "382",
            "MF" => "1599",
            "PS" => "970",
            "RS" => "381",
            "SX" => "1721",
            "TL" => "670",
            "IR" => "98",
            "BF" => "226",
            "BI" => "257",
            "KH" => "855",
            "CM" => "237",
            "CA" => "1",
            "CV" => "238",
            "KY" => "1345",
            "CF" => "236",
            "TD" => "235",
            "CL" => "56",
            "CN" => "86",
            "CX" => "61",
            "CC" => "61",
            "CO" => "57",
            "KM" => "269",
            "CG" => "242",
            "CD" => "243",
            "CK" => "682",
            "CR" => "506",
            "CI" => "225",
            "HR" => "385",
            "CY" => "357",
            "CZ" => "420",
            "DK" => "45",
            "DJ" => "253",
            "DM" => "1767",
            "DO" => "1809",
            "EC" => "593",
            "EG" => "20",
            "SV" => "503",
            "GQ" => "240",
            "ER" => "291",
            "EE" => "372",
            "ET" => "251",
            "FK" => "500",
            "FO" => "298",
            "FJ" => "679",
            "FI" => "358",
            "FR" => "33",
            "GF" => "594",
            "PF" => "689",
            "GA" => "241",
            "GM" => "220",
            "GE" => "995",
            "DE" => "49",
            "GH" => "233",
            "GI" => "350",
            "GL" => "299",
            "GD" => "1473",
            "GP" => "590",
            "GU" => "1671",
            "GT" => "502",
            "GN" => "224",
            "GW" => "245",
            "GY" => "592",
            "HT" => "509",
            "VA" => "39",
            "HN" => "504",
            "HK" => "852",
            "HU" => "36",
            "IS" => "354",
            "IN" => "91",
            "ID" => "62",
            "IQ" => "964",
            "IE" => "353",
            "IL" => "972",
            "IT" => "39",
            "JM" => "1876",
            "JP" => "81",
            "JO" => "962",
            "KZ" => "7",
            "KE" => "254",
            "KI" => "686",
            "KR" => "82",
            "KW" => "965",
            "KG" => "996",
            "LA" => "856",
            "LV" => "371",
            "LB" => "961",
            "LS" => "266",
            "LR" => "231",
            "LI" => "423",
            "LT" => "370",
            "LU" => "352",
            "MO" => "853",
            "MK" => "389",
            "MG" => "261",
            "MW" => "265",
            "MY" => "60",
            "MV" => "960",
            "ML" => "223",
            "MT" => "356",
            "MH" => "692",
            "MQ" => "596",
            "MR" => "222",
            "MU" => "230",
            "YT" => "262",
            "MX" => "52",
            "FM" => "691",
            "MD" => "373",
            "MC" => "377",
            "MN" => "976",
            "MS" => "1664",
            "MA" => "212",
            "MZ" => "258",
            "NA" => "264",
            "NR" => "674",
            "NP" => "977",
            "NL" => "31",
            "NC" => "687",
            "NZ" => "64",
            "NI" => "505",
            "NE" => "227",
            "NG" => "234",
            "NU" => "683",
            "NF" => "672",
            "MP" => "1670",
            "NO" => "47",
            "OM" => "968",
            "PK" => "92",
            "PW" => "680",
            "PA" => "507",
            "PG" => "675",
            "PY" => "595",
            "PE" => "51",
            "PH" => "63",
            "PN" => "870",
            "PL" => "48",
            "PT" => "351",
            "PR" => "1",
            "QA" => "974",
            "RE" => "262",
            "RO" => "40",
            "RU" => "7",
            "RW" => "250",
            "SH" => "290",
            "KN" => "1869",
            "LC" => "1758",
            "PM" => "508",
            "VC" => "1784",
            "WS" => "685",
            "SM" => "378",
            "ST" => "239",
            "SA" => "966",
            "SN" => "221",
            "SC" => "248",
            "SL" => "232",
            "SG" => "65",
            "SK" => "421",
            "SI" => "386",
            "SB" => "677",
            "SO" => "252",
            "ZA" => "27",
            "GS" => "500",
            "ES" => "34",
            "LK" => "94",
            "SR" => "597",
            "SJ" => "47",
            "SZ" => "268",
            "SE" => "46",
            "CH" => "41",
            "TW" => "886",
            "TJ" => "992",
            "TZ" => "255",
            "TH" => "66",
            "TG" => "228",
            "TK" => "690",
            "TO" => "676",
            "TT" => "1868",
            "TN" => "216",
            "TR" => "90",
            "TM" => "993",
            "TC" => "1649",
            "TV" => "688",
            "UG" => "256",
            "UA" => "380",
            "AE" => "971",
            "US" => "1",
            "UY" => "598",
            "UZ" => "998",
            "VU" => "678",
            "VE" => "58",
            "VN" => "84",
            "VG" => "1284",
            "VI" => "1340",
            "WF" => "681",
            "YE" => "967",
            "ZM" => "260",
            "IC" => "34",
        );
        $found                 = false;
        foreach ($countries_phone_codes as $key => $country_prefix) {
            $final_phone = preg_replace( '/[^0-9]/', '', $phone );
            $pattern     = '/^(?:\+|0{0,2}?)((' . $country_prefix . '))( |\.|-)?([\d \-\(\)]*)/';

            preg_match( $pattern, $final_phone, $matches );

            if ( ! empty( $matches ) && ! $found ) {
                if ( ! empty( $matches[4] ) ) {
                    $found     = true;
                    $int_phone = $country_prefix . '-' . $matches[4];
                }
            }
        }

        if ( ! $found ) {
            $country_prefix = $this->pb_getCountryPhoneCode( $country );
            $int_phone      = $country_prefix . '-' . preg_replace( '/[^0-9]/', '', $phone );
        }
        return substr( $int_phone, 0, 19 );
    }

    private function pb_validateStateCode( $state, $country ) {
        $country_prefix = $this->pb_getCountryPhoneCode( $country );
        $pattern        = '/(' . $country . '-?)(.*)/';
        preg_match( $pattern, $state, $matches );
        $stateCode = $state;

        if ( ! empty( $matches ) ) {
            if ( ! empty( $matches[2] ) ) {
                $stateCode = $matches[2];
            }
        }
        if ( empty( $stateCode ) ) {
            //if nothing found for state, assume that is for Attiki
            $stateCode = 'I';
        }
        return $stateCode;
    }

    private function pb_nonLatinChars() {
        return array(
            'À',
            'à',
            'Á',
            'á',
            'Â',
            'â',
            'Ã',
            'ã',
            'Ä',
            'ä',
            'Å',
            'å',
            'Ā',
            'ā',
            'Ă',
            'ă',
            'Ą',
            'ą',
            'Ǟ',
            'ǟ',
            'Ǻ',
            'ǻ',
            'Α',
            'α',
            'ά',
            'Ά',
            'Ḃ',
            'ḃ',
            'Б',
            'б',
            'Ć',
            'ć',
            'Ç',
            'ç',
            'Č',
            'č',
            'Ĉ',
            'ĉ',
            'Ċ',
            'ċ',
            'Ч',
            'ч',
            'Χ',
            'χ',
            'Ḑ',
            'ḑ',
            'Ď',
            'ď',
            'Ḋ',
            'ḋ',
            'Đ',
            'đ',
            'Ð',
            'ð',
            'Д',
            'д',
            'Δ',
            'δ',
            'Ǳ',
            'ǲ',
            'ǳ',
            'Ǆ',
            'ǅ',
            'ǆ',
            'È',
            'è',
            'É',
            'é',
            'Ě',
            'ě',
            'Ê',
            'ê',
            'Ë',
            'ë',
            'Ē',
            'ē',
            'Ĕ',
            'ĕ',
            'Ę',
            'ę',
            'Ė',
            'ė',
            'Ʒ',
            'ʒ',
            'Ǯ',
            'ǯ',
            'Е',
            'е',
            'Э',
            'э',
            'Ε',
            'ε',
            'ё',
            'є',
            'Є',
            'έ',
            'Έ',
            'Ḟ',
            'ḟ',
            'ƒ',
            'Ф',
            'ф',
            'Φ',
            'φ',
            'ﬁ',
            'ﬂ',
            'Ǵ',
            'ǵ',
            'Ģ',
            'ģ',
            'Ǧ',
            'ǧ',
            'Ĝ',
            'ĝ',
            'Ğ',
            'ğ',
            'Ġ',
            'ġ',
            'Ǥ',
            'ǥ',
            'Г',
            'г',
            'Γ',
            'γ',
            'Ĥ',
            'ĥ',
            'Ħ',
            'ħ',
            'Ж',
            'ж',
            'Х',
            'х',
            'Ή',
            'ή',
            'Ì',
            'ì',
            'Í',
            'í',
            'Î',
            'î',
            'Ĩ',
            'ĩ',
            'Ï',
            'ï',
            'Ī',
            'ī',
            'Ĭ',
            'ĭ',
            'Į',
            'į',
            'İ',
            'ı',
            'И',
            'и',
            'Η',
            'η',
            'Ι',
            'ι',
            'і',
            'І',
            'ї',
            'Ї',
            'ί',
            'ϊ',
            'Ί',
            'Ϊ',
            'ΐ',
            'Ĳ',
            'ĳ',
            'Ĵ',
            'ĵ',
            'Ḱ',
            'ḱ',
            'Ķ',
            'ķ',
            'Ǩ',
            'ǩ',
            'К',
            'к',
            'Κ',
            'κ',
            'Ĺ',
            'ĺ',
            'Ļ',
            'ļ',
            'Ľ',
            'ľ',
            'Ŀ',
            'ŀ',
            'Ł',
            'ł',
            'Л',
            'л',
            'Λ',
            'λ',
            'Ǉ',
            'ǈ',
            'ǉ',
            'Ṁ',
            'ṁ',
            'М',
            'м',
            'Μ',
            'μ',
            'Ń',
            'ń',
            'Ņ',
            'ņ',
            'Ň',
            'ň',
            'Ñ',
            'ñ',
            'ŉ',
            'Ŋ',
            'ŋ',
            'Н',
            'н',
            'Ν',
            'ν',
            'Ǌ',
            'ǋ',
            'ǌ',
            'Ò',
            'ò',
            'Ó',
            'ó',
            'Ô',
            'ô',
            'Õ',
            'õ',
            'Ö',
            'ö',
            'Ō',
            'ō',
            'Ŏ',
            'ŏ',
            'Ø',
            'ø',
            'Ő',
            'ő',
            'Ǿ',
            'ǿ',
            'О',
            'о',
            'Ο',
            'ο',
            'Ω',
            'ω',
            'ό',
            'ώ',
            'Ό',
            'Ώ',
            'Œ',
            'œ',
            'Ṗ',
            'ṗ',
            'П',
            'п',
            'Π',
            'π',
            'Ψ',
            'ψ',
            'Ŕ',
            'ŕ',
            'Ŗ',
            'ŗ',
            'Ř',
            'ř',
            'Р',
            'р',
            'Ρ',
            'ρ',
            'Ś',
            'ś',
            'Ş',
            'ş',
            'Š',
            'š',
            'Ŝ',
            'ŝ',
            'Ṡ',
            'ṡ',
            'ſ',
            'ß',
            'С',
            'с',
            'Ш',
            'ш',
            'Щ',
            'щ',
            'Σ',
            'σ',
            'ς',
            'Ţ',
            'ţ',
            'Ť',
            'ť',
            'Ṫ',
            'ṫ',
            'Ŧ',
            'ŧ',
            'Þ',
            'þ',
            'Т',
            'т',
            'Ц',
            'ц',
            'Θ',
            'θ',
            'Τ',
            'τ',
            'Ù',
            'ù',
            'Ú',
            'ú',
            'Û',
            'û',
            'Ũ',
            'ũ',
            'Ü',
            'ü',
            'Ů',
            'ů',
            'Ū',
            'ū',
            'Ŭ',
            'ŭ',
            'Ų',
            'ų',
            'Ű',
            'ű',
            'У',
            'у',
            'В',
            'в',
            'Β',
            'β',
            'Ẁ',
            'ẁ',
            'Ẃ',
            'ẃ',
            'Ŵ',
            'ŵ',
            'Ẅ',
            'ẅ',
            'Ξ',
            'ξ',
            'Ỳ',
            'ỳ',
            'Ý',
            'ý',
            'Ŷ',
            'ŷ',
            'Ÿ',
            'ÿ',
            'Й',
            'й',
            'Ы',
            'ы',
            'Ю',
            'ю',
            'Я',
            'я',
            'Υ',
            'υ',
            'ύ',
            'ϋ',
            'Ύ',
            'Ϋ',
            'ΰ',
            'Ź',
            'ź',
            'Ž',
            'ž',
            'Ż',
            'ż',
            'З',
            'з',
            'Ζ',
            'ζ',
            'Æ',
            'æ',
            'Ǽ',
            'ǽ',
            'а',
            'А',
            'ь',
            'ъ',
            'Ъ',
            'Ь',
        );
    }

    private function pb_latinChars() {
        return array(
            'A',
            'a',
            'A',
            'a',
            'A',
            'a',
            'A',
            'a',
            'A',
            'a',
            'A',
            'a',
            'A',
            'a',
            'A',
            'a',
            'A',
            'a',
            'A',
            'a',
            'A',
            'a',
            'A',
            'a',
            'a',
            'A',
            'B',
            'b',
            'B',
            'b',
            'C',
            'c',
            'C',
            'c',
            'C',
            'c',
            'C',
            'c',
            'C',
            'c',
            'CH',
            'ch',
            'CH',
            'ch',
            'D',
            'd',
            'D',
            'd',
            'D',
            'd',
            'D',
            'd',
            'D',
            'd',
            'D',
            'd',
            'D',
            'd',
            'DZ',
            'Dz',
            'dz',
            'DZ',
            'Dz',
            'dz',
            'E',
            'e',
            'E',
            'e',
            'E',
            'e',
            'E',
            'e',
            'E',
            'e',
            'E',
            'e',
            'E',
            'e',
            'E',
            'e',
            'E',
            'e',
            'E',
            'e',
            'E',
            'e',
            'E',
            'e',
            'E',
            'e',
            'E',
            'e',
            'e',
            'e',
            'E',
            'e',
            'E',
            'F',
            'f',
            'f',
            'F',
            'f',
            'F',
            'f',
            'fi',
            'fl',
            'G',
            'g',
            'G',
            'g',
            'G',
            'g',
            'G',
            'g',
            'G',
            'g',
            'G',
            'g',
            'G',
            'g',
            'G',
            'g',
            'G',
            'g',
            'H',
            'h',
            'H',
            'h',
            'ZH',
            'zh',
            'H',
            'h',
            'H',
            'h',
            'I',
            'i',
            'I',
            'i',
            'I',
            'i',
            'I',
            'i',
            'I',
            'i',
            'I',
            'i',
            'I',
            'i',
            'I',
            'i',
            'I',
            'i',
            'I',
            'i',
            'I',
            'i',
            'I',
            'i',
            'i',
            'I',
            'i',
            'I',
            'i',
            'i',
            'I',
            'I',
            'i',
            'IJ',
            'ij',
            'J',
            'j',
            'K',
            'k',
            'K',
            'k',
            'K',
            'k',
            'K',
            'k',
            'K',
            'k',
            'L',
            'l',
            'L',
            'l',
            'L',
            'l',
            'L',
            'l',
            'L',
            'l',
            'L',
            'l',
            'L',
            'l',
            'LJ',
            'Lj',
            'lj',
            'M',
            'm',
            'M',
            'm',
            'M',
            'm',
            'N',
            'n',
            'N',
            'n',
            'N',
            'n',
            'N',
            'n',
            'n',
            'N',
            'n',
            'N',
            'n',
            'N',
            'n',
            'NJ',
            'Nj',
            'nj',
            'O',
            'o',
            'O',
            'o',
            'O',
            'o',
            'O',
            'o',
            'O',
            'o',
            'O',
            'o',
            'O',
            'o',
            'O',
            'o',
            'O',
            'o',
            'O',
            'o',
            'O',
            'o',
            'O',
            'o',
            'O',
            'o',
            'o',
            'o',
            'O',
            'O',
            'OE',
            'oe',
            'P',
            'p',
            'P',
            'p',
            'P',
            'p',
            'PS',
            'ps',
            'R',
            'r',
            'R',
            'r',
            'R',
            'r',
            'R',
            'r',
            'R',
            'r',
            'S',
            's',
            'S',
            's',
            'S',
            's',
            'S',
            's',
            'S',
            's',
            's',
            'ss',
            'S',
            's',
            'SH',
            'sh',
            'SHCH',
            'shch',
            'S',
            's',
            's',
            'T',
            't',
            'T',
            't',
            'T',
            't',
            'T',
            't',
            'T',
            't',
            'T',
            't',
            'TS',
            'ts',
            'TH',
            'th',
            'T',
            't',
            'U',
            'u',
            'U',
            'u',
            'U',
            'u',
            'U',
            'u',
            'U',
            'u',
            'U',
            'u',
            'U',
            'u',
            'U',
            'u',
            'U',
            'u',
            'U',
            'u',
            'U',
            'u',
            'V',
            'v',
            'V',
            'v',
            'W',
            'w',
            'W',
            'w',
            'W',
            'w',
            'W',
            'w',
            'X',
            'x',
            'Y',
            'y',
            'Y',
            'y',
            'Y',
            'y',
            'Y',
            'y',
            'Y',
            'y',
            'Y',
            'y',
            'YU',
            'yu',
            'YA',
            'ya',
            'Y',
            'y',
            'y',
            'y',
            'Y',
            'Y',
            'y',
            'Z',
            'z',
            'Z',
            'z',
            'Z',
            'z',
            'Z',
            'z',
            'Z',
            'z',
            'AE',
            'ae',
            'AE',
            'ae',
            'a',
            'A',
            '',
            '',
            '',
            '',
        );
    }

    private function pb_convertNonLatinToLatin( $str ) {
        $converted_name = str_replace( $this->pb_nonLatinChars(), $this->pb_latinChars(), $str );

        // for extra check if any char is not ascii, ignore it.
        $conv_name = iconv( 'utf-8', 'ASCII//IGNORE', $converted_name );

        //replace any no digit in piraeus accepted chars lantin and /:_().,+-
        $pattern = '/([^a-zA-Z| \/:_().,+-]*?)/';
        $name    = preg_replace( $pattern, '', $conv_name );

        return $name;

    }
}
