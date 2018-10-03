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

define('SC_GATEWAY_TITLE', 'SafeCharge');
// common notify URL for the plugin
define('SC_NOTIFY_URL', add_query_arg(array('wc-api' => 'WC_Gateway_SC'), home_url('/')));
// some keys for order metadata, we make them hiden when starts with underscore
define('SC_AUTH_CODE_KEY', '_authCode');
define('SC_GW_TRANS_ID_KEY', '_relatedTransactionId');
define('SC_LOG_FILE_PATH', plugin_dir_path( __FILE__ ). 'logs'. DIRECTORY_SEPARATOR. date("Y-m-d"). '.txt');

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
}

/**
* Add the Gateway to WooCommerce
**/
function woocommerce_add_sc_gateway($methods)
{
    $methods[] = 'WC_SC';
    return $methods;
}

// we come here after DMN redirect
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
    
    
    include_once("token.php");
    
    $timestamp= time();
    $g = new WC_SC;
    $g->setEnvironment();
    
    $plugin_dir = basename(dirname(__FILE__));
    
    wp_register_script("sc_js_script", WP_PLUGIN_URL . '/' . $plugin_dir . '/js/sc.js', array('jquery') );
    wp_localize_script(
        'sc_js_script',
        'myAjax',
        array(
            'ajaxurl' => WP_PLUGIN_URL . '/' . $plugin_dir .'/ajax/getAPMs.php',
            'token' =>generateToken($timestamp),
            't'=>$timestamp
        )
    );  
    wp_enqueue_script( 'jquery' );
    wp_enqueue_script( 'sc_js_script' );
    
    // bootstrap modal
    wp_register_script ('sc_bs_modal', WP_PLUGIN_URL. '/'. $plugin_dir. '/js/bootstrap.min.js', array( 'jquery' ), '1', true );
    wp_register_style ('sc_bs_modal',  WP_PLUGIN_URL. '/'. $plugin_dir. '/css/sc_bs_modal.css', '' , '', 'all' );
    
    wp_enqueue_script( 'sc_bs_modal' );
	wp_enqueue_style( 'sc_bs_modal' );
}

function sc_show_final_text()
{
    global $woocommerce;
    $g = new WC_SC;
    
    $arr = explode("_",$_REQUEST['invoice_id']);
    $order_id  = $arr[0];
    $order = new WC_Order($order_id);
        
    if ( strtolower($_REQUEST['ppp_status']) == 'fail' ) {
        $order -> add_order_note('User order faild.');
        $order->save();
            
        $woocommerce -> cart -> empty_cart();
        echo __("Your payment failed. Please, try again.", 'sc');
    }
    elseif ($g->checkAdvancedCheckSum()) {
        $transactionId = "TransactionId = " . (isset($_GET['TransactionID']) ? $_GET['TransactionID'] : "");
        $pppTransactionId = "; PPPTransactionId = " . (isset($_GET['PPP_TransactionID']) ? $_GET['PPP_TransactionID'] : "");
        
        $order->add_order_note("User returned from Safecharge Payment page; ". $transactionId. $pppTransactionId);
        $order->save();
        
        $woocommerce -> cart -> empty_cart();
        echo __("Thank you. Your payment process is completed. Your order status will be updated soon.", 'sc');
    }
}

function sc_create_refund()
{
    require_once 'WC_SC_Refund.php';
    
    $refund = new WC_SC_Refund();
    $refund->sc_refund_order();
}
