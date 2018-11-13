<?php
/*
Plugin Name: SafeCharge WooCommerce PlugIn
Plugin URI: http://www.safecharge.com
Description: SafeCharge gateway for woocommerce
Version: 1.5
Author: SafeCharge
Author URI:http://safecharge.com
*/

if(!defined('ABSPATH')) {
    $die = file_get_contents(dirname( __FILE__ ) . DIRECTORY_SEPARATOR . 'die.html');
    echo $die;
    die;
}

require_once 'sc_config.php';

add_action('plugins_loaded', 'woocommerce_sc_init', 0);

function woocommerce_sc_init()
{
    if(!class_exists('WC_Payment_Gateway')) {
        return;
    }
    
    include_once 'WC_SC.php';
 
    add_filter('woocommerce_payment_gateways', 'woocommerce_add_sc_gateway' );
	add_action('init', 'sc_enqueue');
	add_action('woocommerce_thankyou_order_received_text', 'sc_show_final_text');
    add_action('woocommerce_create_refund', 'sc_create_refund');
    // Check checkout for selected apm ONLY when payment api is REST
    add_action( 'woocommerce_checkout_process', 'sc_check_checkout_apm', 20 ) ;
}

/**
* Add the Gateway to WooCommerce
**/
function woocommerce_add_sc_gateway($methods)
{
    $methods[] = 'WC_SC';
    return $methods;
}

// first method we come in
function sc_enqueue($hook)
{
    /*  Skip order status update if currentlly received status is 'pending' and curent order status is 'completed'.
    * For the rest of the cases the status should be updated.   */
    if(
        isset($_REQUEST['wc-api'], $_REQUEST['Status'], $_REQUEST['invoice_id'])
        && !empty($_REQUEST['wc-api'])
        && !empty($_REQUEST['Status'])
        && !empty($_REQUEST['invoice_id'])
        && strtolower($_REQUEST['wc-api']) == 'wc_gateway_sc'
    ) {
        $arr = explode("_", $_REQUEST['invoice_id']);

        if(is_array($arr) && $arr) {
            $order_id  = $arr[0];
            $order = new WC_Order($order_id);

            if($order_id && $order && strtolower($_REQUEST['Status']) == 'pending') {
                $order_status = strtolower($order->get_status());

                if ($order_status != 'completed') {
                    $order->set_status($_REQUEST['Status']);
                }
            }
        }
    }
    
    # load external files
    $plugin_dir = basename(dirname(__FILE__));
   
    // main JS, use min on production
    if(!defined('WP_DEBUG') || WP_DEBUG === false) {
        wp_register_script("sc_js_script", WP_PLUGIN_URL . '/' . $plugin_dir . '/js/sc.min.js', array('jquery') );
    }
    else {
        wp_register_script("sc_js_script", WP_PLUGIN_URL . '/' . $plugin_dir . '/js/sc.js', array('jquery') );
    }
    
    wp_localize_script(
        'sc_js_script',
        'myAjax',
        array(
            'ajaxurl' => WP_PLUGIN_URL . '/' . $plugin_dir .'/SC_REST_API.php',
        )
    );  
    wp_enqueue_script( 'sc_js_script' );
    
    // novo style
    wp_register_style ('novo_style', WP_PLUGIN_URL. '/'. $plugin_dir. '/css/novo.css', '' , '', 'all' );
    wp_enqueue_style( 'novo_style' );
    
    // the Tokenization script
    wp_register_script("sc_token_js", 'https://cdn.safecharge.com/js/v1/safecharge.js', array('jquery') );
    wp_enqueue_script( 'sc_token_js' );
    # load external files END
}

// show final payment text
function sc_show_final_text()
{
    global $woocommerce;
    $msg = __("Thank you. Your payment process is completed. Your order status will be updated soon.", 'sc');
    
    // REST API
    if(isset($_REQUEST['wc-api']) && strtolower($_REQUEST['wc-api']) == 'wc_sc_rest') {
        if ( strtolower($_REQUEST['status']) == 'failed' ) {
            $msg = __("Your payment failed. Please, try again.", 'sc');
        }
        elseif(strtolower($_REQUEST['status']) == 'success') {
            $woocommerce -> cart -> empty_cart();
        }
        else {
            $woocommerce -> cart -> empty_cart();
        }
    }
    // Cashier
    elseif(@$_REQUEST['invoice_id'] && @$_REQUEST['ppp_status']) {
        $g = new WC_SC;
        $arr = explode("_",$_REQUEST['invoice_id']);
        $order_id  = $arr[0];
        $order = new WC_Order($order_id);
        
        if (
            strtolower($_REQUEST['ppp_status']) == 'failed'
            || strtolower($_REQUEST['ppp_status']) == 'fail'
        ) {
            $order -> add_order_note('User order failed.');
            $msg = __("Your payment failed. Please, try again.", 'sc');
        }
        elseif ($g->checkAdvancedCheckSum()) {
            $transactionId = "TransactionId = " . (isset($_GET['TransactionID']) ? $_GET['TransactionID'] : "");
            $pppTransactionId = "; PPPTransactionId = " . (isset($_GET['PPP_TransactionID']) ? $_GET['PPP_TransactionID'] : "");

            $order->add_order_note("User returned from Safecharge Payment page; ". $transactionId. $pppTransactionId);
            $woocommerce -> cart -> empty_cart();
        }
        
        $order->save();
    }
    else {
        $woocommerce -> cart -> empty_cart();
        
        if ( strtolower(@$_REQUEST['status']) == 'failed' ) {
            $msg = __("Your payment failed. Please, try again.", 'sc');
        }
    }
    
    if(strtolower(@$_REQUEST['status']) == 'waiting') {
        $msg = __("Thank you. If you completed your payment, the order status will be updated soon", 'sc');
        $woocommerce -> cart -> empty_cart();
    }
    
    // clear session variables for the order
    if(isset($_SESSION['SC_Variables'])) {
        unset($_SESSION['SC_Variables']);
    }
    
    echo $msg;
}

function sc_create_refund()
{
    // get GW so we can use its settings
    require_once 'WC_SC.php';
    $gateway = new WC_SC();
    
    // get order refunds
    $order = new WC_Order( (int)$_REQUEST['order_id'] );
    $refunds = $order->get_refunds();
    
    if(!$refunds || !isset($refunds[0]) || !is_array($refunds[0]->data)) {
        $order -> add_order_note(__('There are no refunds data. If refund was made, delete it manually!', 'sc'));
        $order->save();
        wp_send_json_success();
    }
    
    $order_meta_data = array(
        'order_tr_id' => $order->get_meta(SC_GW_TRANS_ID_KEY),
        'auth_code' => $order->get_meta(SC_AUTH_CODE_KEY),
    );
    
    // call refund method
    require_once 'SC_REST_API.php';
    $sc_api = new SC_REST_API();
    
    // execute refund, the response must be array('msg' => 'some msg', 'new_order_status' => 'some status')
    $resp = $sc_api->sc_refund_order(
        $gateway->settings
        ,$refunds[0]->data
        ,$order_meta_data
        ,get_woocommerce_currency()
        ,SC_NOTIFY_URL . 'Rest'
    );
    
    $order -> add_order_note(__($resp['msg'], 'sc'));
    
    if(!empty($resp['new_order_status'])) {
        $order->set_status($resp['new_order_status']);
    }
    
    $order->save();
    wp_send_json_success();
}

function sc_check_checkout_apm()
{
    // if custom fields are empty stop checkout process displaying an error notice.
    if(
        isset($_SESSION['SC_Variables']['payment_api'])
        && $_SESSION['SC_Variables']['payment_api'] == 'rest'
        && empty($_POST['payment_method_sc'])
    ) {
        $notice = __( 'Please select '. SC_GATEWAY_TITLE .' payment method to continue!', 'sc' );
        wc_add_notice( '<strong>' . $notice . '</strong>', 'error' );
    }
}
