<?php
/*
Plugin Name: SafeCharge WooCommerce PlugIn
Plugin URI: http://www.safecharge.com
Description: SafeCharge gateway for woocommerce
Version: 1.7
Author: SafeCharge
Author URI:http://safecharge.com
*/

if(!defined('ABSPATH')) {
    $die = file_get_contents(dirname( __FILE__ ) . DIRECTORY_SEPARATOR . 'die.html');
    echo $die;
    die;
}

require_once 'sc_config.php';

$wc_sc = null;

add_action('plugins_loaded', 'woocommerce_sc_init', 0);

function woocommerce_sc_init()
{
    if(!class_exists('WC_Payment_Gateway')) {
        return;
    }
    
    require_once 'WC_SC.php';
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    
    global $wc_sc;
    $wc_sc = new WC_SC();
 
    add_filter('woocommerce_payment_gateways', 'woocommerce_add_sc_gateway' );
	add_action('init', 'sc_enqueue'); // need WC_SC
	add_action('woocommerce_thankyou_order_received_text', 'sc_show_final_text'); // need WC_SC
    // Check checkout for selected apm ONLY when payment api is REST
    add_action( 'woocommerce_checkout_process', 'sc_check_checkout_apm', 20 );
    // add void and/or settle buttons to completed orders
    add_action( 'woocommerce_order_item_add_action_buttons', 'sc_add_buttons'); // need WC_SC
    // Refun hook, when create refund from WC, we do not want this to be activeted from DMN
    add_action('woocommerce_create_refund', 'sc_create_refund'); // need WC_SC
    
    // for WPML plugin
    if(is_plugin_active('sitepress-multilingual-cms' . DIRECTORY_SEPARATOR . 'sitepress.php')) {
        add_filter( 'woocommerce_get_checkout_order_received_url', 'sc_wpml_thank_you_page', 10, 2 );
    }
    
    // if the merchant needs to rewrite the DMN URL
    if(isset($wc_sc->settings['rewrite_dmn']) && $wc_sc->settings['rewrite_dmn'] == 'yes') {
        add_action('template_redirect', 'sc_rewrite_dmn'); // need WC_SC
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
    global $wc_sc;
    
    # DMNs catch
    if(isset($_REQUEST['wc-api']) && $_REQUEST['wc-api'] == 'sc_listener') {
        $wc_sc->process_dmns();
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
            'ajaxurl' => WP_PLUGIN_URL . '/' . $plugin_dir .'/sc_ajax.php',
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
    global $wc_sc;
    
    $msg = __("Thank you. Your payment process is completed. Your order status will be updated soon.", 'sc');
   
    // Cashier
    if(@$_REQUEST['invoice_id'] && @$_REQUEST['ppp_status'] && $wc_sc->checkAdvancedCheckSum()) {
        try {
            $arr = explode("_",$_REQUEST['invoice_id']);
            $order_id  = $arr[0];
            $order = new WC_Order($order_id);

            if (strtolower($_REQUEST['ppp_status']) == 'fail') {
                $order->add_order_note('User order failed.');
                $order->update_status('failed', 'User order failed.');

                $msg = __("Your payment failed. Please, try again.", 'sc');
            }
            else{
                $transactionId = "TransactionId = "
                    . (isset($_REQUEST['TransactionID']) ? $_REQUEST['TransactionID'] : "");

                $pppTransactionId = "; PPPTransactionId = "
                    . (isset($_REQUEST['PPP_TransactionID']) ? $_REQUEST['PPP_TransactionID'] : "");

                $order->add_order_note("User returned from Safecharge Payment page; ". $transactionId. $pppTransactionId);
                $woocommerce -> cart -> empty_cart();
            }
            
            $order->save();
        }
        catch (Exception $ex) {
            create_log($ex->getMessage(), 'Cashier handle exception error: ');
        }
        
    }
    // REST API tahnk you page handler
    else{
        if ( strtolower($_REQUEST['Status']) == 'fail' ) {
            $msg = __("Your payment failed. Please, try again.", 'sc');
        }
        else {
            $woocommerce -> cart -> empty_cart();
        }
    }
    // clear session variables for the order
    if(isset($_SESSION['SC_Variables'])) {
        unset($_SESSION['SC_Variables']);
    }
    
    echo $msg;
}

function sc_create_refund()
{
    if(!isset($_REQUEST['wc-api'])) {
        // get GW so we can use its settings
        require_once 'WC_SC.php';
        global $wc_sc;

        $notify_url = $wc_sc->set_notify_url();

        // get order refunds
        if(isset($_REQUEST['order_id'])) {
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
            $resp = $sc_api->refund_order(
                $wc_sc->settings
                ,$refunds[0]->data
                ,$order_meta_data
                ,get_woocommerce_currency()
                ,$notify_url . 'sc_listener&action=refund&order_id=' . $_REQUEST['order_id']
            );

            $order->add_order_note(__($resp['msg'], 'sc'));

            create_log($resp, 'Refund response from sc_create_refund(): ');

    //        if(!empty($resp['new_order_status'])) {
    //            $order->set_status($resp['new_order_status']);
    //        }

            $order->save();
            wp_send_json_success();
        }
    }
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

function sc_add_buttons()
{
    $order = new WC_Order($_REQUEST['post']);
    $order_status = strtolower($order->get_status());
    
    if($order_status == 'completed') {
        # Data for the VOID
    //    require_once 'WC_SC.php';
        require_once 'SC_REST_API.php';
        require_once 'SC_Versions_Resolver.php';
        
        global $wc_sc;
        
        $sc_api = new SC_REST_API();
        $sc_v_res = new SC_Versions_Resolver();
        
        $time = date('YmdHis', time());
        $order_tr_id = $order->get_meta(SC_GW_TRANS_ID_KEY);
        $notify_url = $wc_sc->set_notify_url();
        
        $_SESSION['SC_Variables'] = array(
            'merchantId'            => $wc_sc->settings['merchantId'],
            'merchantSiteId'        => $wc_sc->settings['merchantSiteId'],
            'clientRequestId'       => $time . '_' . $order_tr_id,
            'clientUniqueId'        => $order_tr_id,
            'amount'                => $sc_v_res->get_order_data($order, 'order_total'),
        //    'amount'                => $wc_sc->get_order_data($order, 'order_total'),
            'currency'              => get_woocommerce_currency(),
            'relatedTransactionId'  => $order_tr_id,
            'authCode'              => $order->get_meta(SC_AUTH_CODE_KEY),
            
            'urlDetails'            => array('notificationUrl' => $notify_url
                .'sc_listener&action=void&clientRequestId=' . $_REQUEST['post']),
            
            'timeStamp'             => $time,
            // optional fields for sc_ajax.php
            'test'                  => $wc_sc->settings['test'],
            'payment_api'           => 'rest',
            'save_logs'             => $wc_sc->settings['save_logs'],
        );
        
        $checksum = hash(
            $wc_sc->settings['hash_type'],
            $wc_sc->settings['merchantId'] . $wc_sc->settings['merchantSiteId']
                .($time . '_' . $order_tr_id) . $order_tr_id . $_SESSION['SC_Variables']['amount']
                .$_SESSION['SC_Variables']['currency'] . $order_tr_id
                .$_SESSION['SC_Variables']['authCode']
                .$_SESSION['SC_Variables']['urlDetails']['notificationUrl']
                .$time . $wc_sc->settings['secret']
        );
        
        $_SESSION['SC_Variables']['checksum'] = $checksum;
        # Data for the VOID END
        
        $buttons_html = 
            ' <button type="button" onclick="cancelOrder(\''
            . __( 'Are you sure, you want to Cancel Order #'. $_REQUEST['post'] .'?', 'sc' ) .'\', '
            . $_REQUEST['post'] .')" class="button generate-items">'. __( 'Void', 'sc' ) .'</button>';
        
        if($wc_sc->settings['transaction_type'] == 'a&s') {
            $buttons_html .=
                ' <button type="button" onclick="alert(\'no action for the moment.\')" class="button generate-items">'. __( 'Settle', 'sc' ) .'</button>';
        }
        
        $buttons_html .=
            '<div id="custom_loader" class="blockUI blockOverlay"></div>';
        
        echo $buttons_html;
    }
}

/**
 * Function sc_rewrite_dmn
 * When user have problem with white spaces in the URL, it have option to
 * rewrite the DMN URL and redirect to new one.
 * 
 * @global WC_SC $wc_sc
 */
function sc_rewrite_dmn()
{
    global $wc_sc;
    $new_url = '';
    $host = '';
    
    if(
        isset($_REQUEST['ppp_status']) && $_REQUEST['ppp_status'] != ''
        && (!isset($_REQUEST['wc_sc_redirected']) || $_REQUEST['wc_sc_redirected'] == 0)
    ) {
        $host = $wc_sc->get_return_url();
        
        if(isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] != '') {
            $new_url = preg_replace('/\+|\s|\%20/', '_', $_SERVER['QUERY_STRING']);
            // put flag the URL was rewrited
            $new_url .= '&wc_sc_redirected=1';
            
            wp_redirect( $host . '?' . $new_url );
            exit;
        }
    }
}

/**
 * Function sc_wpml_thank_you_page
 * Fix for WPML plugin "Thank you" page
 * 
 * @param string $order_received_url
 * @param WC_Order $order
 * @return string $order_received_url
 */
function sc_wpml_thank_you_page( $order_received_url, $order )
{
    $lang_code = get_post_meta( $order->id, 'wpml_language', true );
    $order_received_url = apply_filters( 'wpml_permalink', $order_received_url , $lang_code );
    
    create_log($order_received_url, 'sc_wpml_thank_you_page: ');
 
    return $order_received_url;
}

/**
* Function create_log
* Create logs. You MUST have defined SC_LOG_FILE_PATH const,
* holding the full path to the log file.
* 
* @param mixed $data
* @param string $title - title of the printed log
*/
function create_log($data, $title = '')
{
   if(
       !isset($_SESSION['SC_Variables']['save_logs'])
       || $_SESSION['SC_Variables']['save_logs'] == 'no'
       || $_SESSION['SC_Variables']['save_logs'] === null
   ) {
       return;
   }

   $d = '';

   if(is_array($data) || is_object($data)) {
       $d = print_r($data, true);
   }
   elseif(is_bool($data)) {
       $d = $data ? 'true' : 'false';
   }
   else {
       $d = $data;
   }

   if(!empty($title)) {
       $d = $title . "\r\n" . $d;
   }

   if(defined('SC_LOG_FILE_PATH')) {
       try {
           file_put_contents(SC_LOG_FILE_PATH, date('H:i:s') . ': ' . $d . "\r\n"."\r\n", FILE_APPEND);
       }
       catch (Exception $exc) {
           echo
               '<script>'
                   .'error.log("Log file was not created, by reason: '.$exc.'");'
                   .'console.log("Log file was not created, by reason: '.$data.'");'
               .'</script>';
       }
   }
}