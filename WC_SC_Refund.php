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
    }
    
    public function sc_refund_order()
    {
        check_ajax_referer( 'order-item', 'security' );

        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_die( -1 );
        }
        
        $request = $_REQUEST;
        $order = new WC_Order((int)$_REQUEST['order_id'] );
        $refunds = $order->get_refunds();
        
        $time = date('YmdHis', time());
        $ord_tr_id = $order->get_transaction_id();
        
        $ref_parameters = array(
            'merchantId'            => $this->settings['merchant_id'],
            'merchantSiteId'        => $this->settings['merchantsite_id'],
            'clientRequestId'       => $time . '_' . $ord_tr_id,
            'clientUniqueId'        => $ord_tr_id,
        //    'amount'                => $request['refund_amount'],
            'amount'                => (string) $refunds[0]->data['amount'],
            'currency'              => get_woocommerce_currency(),
            'relatedTransactionId'  => $order->get_meta(SC_GW_TRANS_ID_KEY), // GW Transaction ID
            'authCode'              => $order->get_meta(SC_AUTH_CODE_KEY),
        //    'comment'               => $request['refund_reason'], // optional
            'comment'               => $refunds[0]->data['reason'], // optional
            'urlDetails'            => array('notificationUrl' => SC_NOTIFY_URL),
            'timeStamp'             => $time,
        );
        
        $checksum_str = '';
        foreach($ref_parameters as $val) {
            $checksum_str .= $val;
        }
        $checksum_str .= $this->settings['secret'];
        
        $ref_parameters['checksum'] = hash('md5', $checksum_str);
        $json_array = json_encode($ref_parameters);
        
        // create cURL post
        $ch = curl_init();
        
        curl_setopt($ch, CURLOPT_URL, $this->use_refund_url);
        curl_setopt($ch, CURLOPT_POST, 1);
        
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_array);
        curl_setopt($ch, CURLOPT_POSTFIELDS, array(
            'Content-Type:application/json', 'Content-Length: ' . strlen($json_array))
        );
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $resp = curl_exec($ch);
        curl_close ($ch);
        
        if($resp === false){
            wp_send_json_error( array( 'error' => __('The REST API retun false.', 'sc') ) );
            exit;
        }
        
        $resp_str = file_get_contents("php://input");
        $json_arr = null;
        
        $json_arr = json_decode($resp_str, true);
        if(!is_array($json_arr)) {
            parse_str($resp_str, $json_arr);
        }
        
        if(!is_array($json_arr)) {
            wp_send_json_error( array( 'error' => __('Invalid API response', 'sc') ) );
            exit;
        }
        
        $order_refund_text = 'Your request - Refund #' . $refunds[0]->id . ', was ';
        if($json_arr['api_refund'] == 'false') {
            $order_refund_text .= 'not ';
        }
        $order_refund_text .= 'successful. Please check your e-mail for more information.';
        
        $order -> add_order_note(__($order_refund_text, 'sc'));
        $order->save();
        
        $this->create_log($ref_parameters, '$ref_parameters: ');
        $this->create_log($response, '$response: ');
    //    wp_send_json_success( array('status' => true) );
    }
    
    /**
     * Function create_log
     * Create logs
     * 
     * @param mixed $data
     * @param string $title - title of the printed log
     */
    private function create_log($data, $title = '')
    {
        if(!defined('WP_DEBUG') || WP_DEBUG === false) {
            return;
        }
        
        $file = plugin_dir_path( __FILE__ ) . 'logs' . DIRECTORY_SEPARATOR . date("Y-m-d") . '.txt';
        $d = '';
        
        if(is_array($data) || is_object($data)) {
            $d = print_r($data, true);
        //    $d = mb_convert_encoding($d, 'UTF-8');
            $d = '<pre>'.$d.'</pre>';
        }
        elseif(is_string($data)) {
        //    $d = mb_convert_encoding($data, 'UTF-8');
            $d = '<pre>'.$d.'</pre>';
        }
        elseif(is_bool($data)) {
            $d = $data ? 'true' : 'false';
            $d = '<pre>'.$d.'</pre>';
        }
        else {
            $d = '<pre>'.$data.'</pre>';
        }
        
        if(!empty($title)) {
            $d = '<h3>'.$title.'</h3>'."\r\n".$d;
        }
        
        try {
            file_put_contents($file, date('H:i:s') . ': ' . $d."\r\n"."\r\n", FILE_APPEND);
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
