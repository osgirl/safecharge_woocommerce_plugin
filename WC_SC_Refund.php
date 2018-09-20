<?php

/**
 * Class WC_SC_Refund
 * 
 * Refund using SafeCharge REST API
 *
 * @author SafeCharge
 */
class WC_SC_Refund extends WC_Order_Refund
{
    // the Refund is available ONLY with the REST API
    private $live_refund_url = 'https://secure.safecharge.com/ppp/api/v1/refundTransaction.do';
    private $test_refund_url = 'https://ppp-test.safecharge.com/ppp/api/v1/refundTransaction.do';
    private $settings = array();
    private $use_refund_url = '';
    
    public function __construct()
    {
        require_once 'WC_SC.php';
        $gateway = new WC_SC();
        $this->settings = $gateway->settings;
        
        $this->use_refund_url = $this->test_refund_url;
        if($this->settings['test'] == 'no') {
            $this->use_refund_url = $this->live_refund_url;
        }
        
//        echo 'test: ';
//        $order = new WC_Order((int)$_REQUEST['order_id'] );
//        var_dump($order->get_user_id());
//        die;
    }
    
    public function sc_refund_order()
    {
        check_ajax_referer( 'order-item', 'security' );

        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_die( -1 );
        }
        
        $request = $_REQUEST;
        $order = new WC_Order((int)$_REQUEST['order_id'] );
        
        $ref_parameters = array(
            'merchantId'            => $this->settings['merchant_id'],
            'merchantSiteId'        => $this->settings['merchantsite_id'],
            'clientRequestId'       => $order->get_user_id(),
            'clientUniqueId'        => '', // GW_TransactionID ?
            'amount'                => $request['refund_amount'],
            'currency'              => get_woocommerce_currency(),
            'relatedTransactionId'  => $order->get_transaction_id(), // PPP_TransactionID
            'authCode'              => '', // ?
            'comment'               => $request['refund_reason'], // optional
            'urlDetails'            => '', // optional
            'timeStamp'             => date('YMDHis', time()),
        );
        
        $checksum_str = '';
        foreach($ref_parameters as $val) {
            $checksum_str .= $val;
        }
        $checksum_str .= $this->settings['secret'];
        
        $ref_parameters['checksum'] = sha1($checksum_str);
        
        
    //    echo '<pre>'.print_r($_REQUEST, true).'</pre>';
    //    die('WC_SC_Refund->sc_refund_order');
        
    //    wp_send_json_success( array('status' => true) );
    //    wp_send_json_error( array( 'error' => $e->getMessage() ) );
        
    //    $this->wc_create_refund($_REQUEST);
    //    echo json_encode(array('status' => true));
    //    exit;
    }
}
