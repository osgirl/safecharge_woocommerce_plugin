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
        require_once 'SC_API_Caller.php';
        
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
            'url'                   => SC_NOTIFY_URL,
            'timeStamp'             => $time,
        );
        
        $other_params = array(
            'urlDetails'            => array('notificationUrl' => SC_NOTIFY_URL),
        );
        
        $json_arr = SC_API_Caller::call_rest_api(
            $this->use_refund_url,
            $ref_parameters,
            $this->settings['secret'],
            $this->settings['hash_type'],
            $other_params
        );
        
        $note = '';
        $error_note = 'Please check your e-mail for details and manually delete request Refund #'
            .$refunds[0]->id.' form the order or login into '. $this->use_cpanel_url
            .' and refund Transaction ID '.$order->get_meta(SC_GW_TRANS_ID_KEY);
        
        if($json_arr === false){
            $note = __('The REST API retun false. '.$error_note, 'sc');
            $order -> add_order_note(__($note, 'sc'));
            $order->save();
            
            wp_send_json_success();
        }
        
        if(!is_array($json_arr)) {
            parse_str($resp, $json_arr);
        }

        if(!is_array($json_arr)) {
            $note =  __('Invalid API response. '.$error_note, 'sc');
            $order -> add_order_note(__($note, 'sc'));
            $order->save();
            
            wp_send_json_success();
        }
        
        SC_API_Caller::create_log($json_arr, '$json_arr: ');
        
        // the status of the request
        if(isset($json_arr['status']) && $json_arr['status'] == 'ERROR') {
            $note = __('Error, Invalid checksum. '.$error_note, 'sc');
            $order -> add_order_note(__($note, 'sc'));
            $order->save();
            
            wp_send_json_success();
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
        }
        
        // create refund note
        $note = 'Your request - Refund #' . $refunds[0]->id . ', was successful. Please check your e-mail for details!';
        $order -> add_order_note(__($note, 'sc'));
        $order->save();
        
        wp_send_json_success();
    }
}
