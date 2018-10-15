<?php
/*
Plugin Name: SafeCharge WooCommerce PlugIn
Plugin URI: http://www.safecharge.com
Description: SafeCharge gateway for woocommerce
Version: 1.1
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
    // try to catch ajax
    add_action( 'wp_ajax_my_action', 'my_action' );
    add_action( 'wp_ajax_nopriv_my_action', 'my_action' );
    
    // Check checkout for selected apm ONLY when payment api is REST
    if(isset($_SESSION['SC_Variables']['payment_api']) && $_SESSION['SC_Variables']['payment_api'] == 'rest') {
        add_action( 'woocommerce_checkout_process', 'sc_check_checkout_apm', 20 ) ;
    }
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
    // when we get DMN from Cashier
    if(
        isset($_REQUEST['Status'], $_REQUEST['wc-api'])
        && $_REQUEST['wc-api'] == 'WC_Gateway_SC'
        && !empty($_REQUEST['Status'])
    ) {
        $arr = explode("_", $_REQUEST['invoice_id']);
        $order_id  = $arr[0];
        $order = new WC_Order($order_id);
        $order_status = strtolower($order->get_status());
        
        /*  Skip order status update if currentlly received status is 'pending' and curent order status is 'completed'.
        * For the rest of the cases the status should be updated.   */
        if (
            strtolower($_REQUEST['Status']) == 'pending'
            && $order_status != 'completed'
        ) {
            $order->set_status($_REQUEST['Status']);
        }
        
        // save or update AuthCode and GW Transaction ID
        $auth_code = isset($_REQUEST['AuthCode']) ? $_REQUEST['AuthCode'] : '';
        $saved_ac = $order->get_meta(SC_AUTH_CODE_KEY);
        
        if(!$saved_ac || empty($saved_ac) || $saved_ac !== $auth_code) {
            $resp = $order->update_meta_data(SC_AUTH_CODE_KEY, $auth_code);
        }
        
        $gw_transaction_id = isset($_REQUEST['TransactionID']) ? $_REQUEST['TransactionID'] : '';
        $saved_tr_id = $order->get_meta(SC_GW_TRANS_ID_KEY);
        
        if(!$saved_tr_id || empty($saved_tr_id) || $saved_tr_id !== $gw_transaction_id) {
            $order->update_meta_data(SC_GW_TRANS_ID_KEY, $gw_transaction_id, 0);
        }
        
        $order->save();
        // save or update AuthCode and GW Transaction ID END
    }
    
    // TODO when we get DMN from REST API about the refund save Order Note
    
    // load external files
    $plugin_dir = basename(dirname(__FILE__));
   
    wp_register_script("sc_js_script", WP_PLUGIN_URL . '/' . $plugin_dir . '/js/sc.js', array('jquery') );
    wp_localize_script(
        'sc_js_script',
        'myAjax',
        array(
            'ajaxurl' => WP_PLUGIN_URL . '/' . $plugin_dir .'/SC_REST_API.php',
        )
    );  
    wp_enqueue_script( 'jquery' );
    wp_enqueue_script( 'sc_js_script' );
    
    // bootstrap modal
    wp_register_script ('sc_bs_modal', WP_PLUGIN_URL. '/'. $plugin_dir. '/js/bootstrap.min.js', array( 'jquery' ), '1', true );
    wp_register_style ('sc_bs_modal_style',  WP_PLUGIN_URL. '/'. $plugin_dir. '/css/sc_bs_modal.css', '' , '', 'all' );
    
    wp_enqueue_script( 'sc_bs_modal' );
	wp_enqueue_style( 'sc_bs_modal_style' );
    // load external files END
}

// show final payment text, when use REST API we change order status here
function sc_show_final_text()
{
    global $woocommerce;
    $msg = __("Thank you. Your payment process is completed. Your order status will be updated soon.", 'sc');
    
    // REST API
    if(@$_REQUEST['api'] == 'rest') {
        if ( strtolower($_REQUEST['status']) == 'failed' ) {
            $msg = __("Your payment failed. Please, try again.", 'sc');
        }
        elseif(strtolower($_REQUEST['status']) == 'success') {
            $woocommerce -> cart -> empty_cart();
        }
    }
    // Cashier
    else {
        $g = new WC_SC;
        $arr = explode("_",$_REQUEST['invoice_id']);
        $order_id  = $arr[0];
        $order = new WC_Order($order_id);
        
        if ( strtolower($_REQUEST['ppp_status']) == 'failed' ) {
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
    
    echo $msg;
}

function sc_create_refund()
{
    require_once 'SC_REST_API.php';
    
    $sc_api = new SC_REST_API();
    $sc_api->sc_refund_order();
}

function sc_check_checkout_apm()
{
    // if custom fields are empty stop checkout process displaying an error notice.
    if ( empty($_POST['payment_method_sc']) ) {
        $notice = __( 'Please select '. SC_GATEWAY_TITLE .' payment method to continue!', 'sc' );
        wc_add_notice( '<strong>' . $notice . '</strong>', 'error' );
    }
}
