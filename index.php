<?php
/*
Plugin Name: SafeCharge WooCommerce PlugIn
Plugin URI: http://www.safecharge.com
Description: SafeCharge gateway for woocommerce
Version: 1.1
Author: SafeCharge
Author URI:http://safecharge.com
*/

if(!defined('ABSPATH'))
{
    $die = file_get_contents(dirname( __FILE__ ) . DIRECTORY_SEPARATOR . 'die.html');
    echo $die;
    die;
}

add_action('plugins_loaded', 'woocommerce_g2s_init', 0);

function woocommerce_g2s_init()
{
    if(!class_exists('WC_Payment_Gateway')) {
        return;
    }
    
    include_once 'WC_SC.php';
 
    add_filter('woocommerce_payment_gateways', 'woocommerce_add_sc_gateway' );
	add_action( 'init', 'sc_enqueue' );
	add_action( 'woocommerce_thankyou_order_received_text', 'sc_show_final_text' );
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
}

function sc_show_final_text()
{
    global $woocommerce;
    $g = new WC_SC;
    
    if ( $_GET['ppp_status'] == 'FAIL' ){
        $arr = explode("_",$_REQUEST['invoice_id']);
        $order_id  = $arr[0];
        $order = new WC_Order($order_id);
        $order -> add_order_note('User order faild.');
        $order->save();
            
        $woocommerce -> cart -> empty_cart();
        echo __("Your payment failed. Please, try again.", 'sc');
    }
    elseif ($g->checkAdvancedCheckSum()) {
        $woocommerce -> cart -> empty_cart();
        echo __("Thank you. Your payment process is completed. Your order status will be updated soon.", 'sc');
    }
}
