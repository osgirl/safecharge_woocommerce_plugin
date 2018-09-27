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
    
    // the two options for user CPanel
    private $user_test_cpanel_url = 'sandbox.safecharge.com';
    private $user_live_cpanel_url = 'cpanel.safecharge.com';
    
    private $settings = array();
    private $use_refund_url = '';
    private $use_cpanel_url = '';
    
    public function __construct()
    {
        require_once 'WC_SC.php';
        $gateway = new WC_SC();
        $this->settings = $gateway->settings;
        
        $this->use_refund_url = $this->test_refund_url;
        $this->use_cpanel_url = $this->user_test_cpanel_url;
        
        if($this->settings['test'] == 'no') {
            $this->use_refund_url = $this->live_refund_url;
            $this->use_cpanel_url = $this->user_live_cpanel_url;
        }
    }
    
    public function sc_refund_order()
    {
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
            'amount'                => number_format($refunds[0]->data['amount'], 2),
            'currency'              => get_woocommerce_currency(),
            'relatedTransactionId'  => $order->get_meta(SC_GW_TRANS_ID_KEY), // GW Transaction ID
            'authCode'              => $order->get_meta(SC_AUTH_CODE_KEY),
            'comment'               => $refunds[0]->data['reason'], // optional
            'urlDetails'            => array('notificationUrl' => SC_NOTIFY_URL),
            'timeStamp'             => $time,
        );
        
        $checksum_str = '';
        foreach($ref_parameters as $key => $val) {
            if($key == 'urlDetails') {
                $checksum_str .= $ref_parameters['urlDetails']['notificationUrl'];
            }
            else {
                $checksum_str .= $val;
            }
        }
        $checksum_str .= $this->settings['secret'];
        
        $ref_parameters['checksum'] = hash($this->settings['hash_type'], $checksum_str);
        $json_post = json_encode($ref_parameters);
        
        $this->create_log($ref_parameters, '$ref_parameters: ');
        $this->create_log($json_post, '$json_post: ');
        
        // create cURL post
        $ch = curl_init();
        
        curl_setopt($ch, CURLOPT_URL, $this->use_refund_url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_post);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type:application/json', 'Content-Length: ' . strlen($json_post))
        );
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $resp = curl_exec($ch);
        curl_close ($ch);
        
        $note = '';
        $error_note = 'Please check your e-mail for details and manually delete request Refund #'
            .$refunds[0]->id.' form the order or login into '. $this->use_cpanel_url
            .' and refund Transaction ID '.$order->get_meta(SC_GW_TRANS_ID_KEY);
        
        if($resp === false){
            $note = __('The REST API retun false. '.$error_note, 'sc');
            $order -> add_order_note(__($note, 'sc'));
            $order->save();
            
            wp_send_json_success();
        //    wp_send_json_error( array( 'error' => $note ) );
        }
        
        $json_arr = json_decode($resp, true);
        if(!is_array($json_arr)) {
            parse_str($resp, $json_arr);
        }

        if(!is_array($json_arr)) {
            $note =  __('Invalid API response. '.$error_note, 'sc');
            $order -> add_order_note(__($note, 'sc'));
            $order->save();
            
            wp_send_json_success();
        //    wp_send_json_error( array( 'error' => $note ) );
        }
        
        $this->create_log($json_arr, '$json_arr: ');
        
        // the status of the request
        if(isset($json_arr['status']) && $json_arr['status'] == 'ERROR') {
            $note = __('Error, Invalid checksum. '.$error_note, 'sc');
            $order -> add_order_note(__($note, 'sc'));
            $order->save();
            
            wp_send_json_success();
        //    wp_send_json_error( array( 'error' => $note ) );
        }
        
        // check the transaction status
        if(isset($json_arr['transactionStatus']) && $json_arr['transactionStatus'] == 'ERROR') {
            if(isset($json_arr['gwErrorReason']) && !empty($json_arr['gwErrorReason'])) {
                $note = $json_arr['gwErrorReason'];
            }
            elseif(isset($json_arr['paymentMethodErrorReason']) && !empty($json_arr['paymentMethodErrorReason'])) {
                $note = $json_arr['paymentMethodErrorReason'];
            }
            else {
                $note = 'Transaction error';
            }
            
            $order -> add_order_note(__($note.'. '.$error_note, 'sc'));
            $order->save();
            
            wp_send_json_success();
        //    wp_send_json_error( array('error' => $note));
        }
        
        // create refund note
        $note = 'Your request - Refund #' . $refunds[0]->id . ', was successful. Please check your e-mail for details!';
        $order -> add_order_note(__($note, 'sc'));
        $order->save();
        
        wp_send_json_success();
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
