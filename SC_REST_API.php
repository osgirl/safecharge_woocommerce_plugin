<?php

if (!session_id()) {
    session_start();
}

/**
 * SC_REST_API Class
 * 
 * A class for work with SafeCharge REST API.
 * 
 * 2018/10
 *
 * @author SafeCharge
 */
class SC_REST_API
{
    // the Refund is available ONLY with the REST API
    private $live_refund_url = 'https://secure.safecharge.com/ppp/api/v1/refundTransaction.do';
    private $test_refund_url = 'https://ppp-test.safecharge.com/ppp/api/v1/refundTransaction.do';
    private $use_refund_url = '';
    
    // the URLs for APM payments
    private $live_apm_payment_url = 'https://secure.safecharge.com/ppp/api/v1/paymentAPM.do';
    private $test_apm_payment_url = 'https://ppp-test.safecharge.com/ppp/api/v1/paymentAPM.do';
    private $use_apm_payment_url = '';
    
    // the two options for user CPanel
    private $user_test_cpanel_url = 'sandbox.safecharge.com';
    private $user_live_cpanel_url = 'cpanel.safecharge.com';
    private $use_cpanel_url = '';
    
    // GW settings
    private $settings = array();
    
    // to get APMs we need session token
    private $use_session_token_url = '';
    private $use_merch_paym_meth_url = '';
    
    private $notify_url = SC_NOTIFY_URL . 'Rest';
    
    /**
     * Function sc_refund_order
     * Create a refund
     */
    public function sc_refund_order()
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
        
        $order = new WC_Order( (int)$_REQUEST['order_id'] );
        $refunds = $order->get_refunds();
        
        $time = date('YmdHis', time());
        $ord_tr_id = $order->get_meta(SC_GW_TRANS_ID_KEY);
        
        // at the moment with the REST API we do not recieve transaction ID,
        // and we get empty string bleow
        if(!$ord_tr_id || empty($ord_tr_id)) {
            $note = __('The Order does not have Transaction ID. Refund can not procceed.');
            $order -> add_order_note(__($note, 'sc'));
            $order->save();
            
            wp_send_json_success();
        }
        
        $ref_parameters = array(
            'merchantId'            => $this->settings['merchant_id'],
            'merchantSiteId'        => $this->settings['merchantsite_id'],
            'clientRequestId'       => $time . '_' . $ord_tr_id,
            'clientUniqueId'        => $ord_tr_id,
            'amount'                => number_format($refunds[0]->data['amount'], 2),
            'currency'              => get_woocommerce_currency(),
            'relatedTransactionId'  => $ord_tr_id, // GW Transaction ID
            'authCode'              => $order->get_meta(SC_AUTH_CODE_KEY),
            'comment'               => $refunds[0]->data['reason'], // optional
            'url'                   => $this->notify_url,
            'timeStamp'             => $time,
        );
        
        $other_params = array(
            'urlDetails'            => array('notificationUrl' => $this->notify_url),
        );
        
        $this->create_log($ref_parameters, 'refund_parameters: ');
        $this->create_log($other_params, 'other_params: ');
        
        $json_arr = $this->call_rest_api(
            $this->use_refund_url,
            $ref_parameters,
            $this->settings['secret'],
            $this->settings['hash_type'],
            $other_params
        );
        
        $note = '';
        $error_note = 'Please check your e-mail for details and manually delete request Refund #'
            .$refunds[0]->id.' form the order or login into '. $this->use_cpanel_url
            .' and refund Transaction ID '.$ord_tr_id;
        
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
        
        $this->create_log($json_arr, 'json_arr: ');
        
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
        $note = 'Your request - Refund #' . $refunds[0]->id . ', was successful.';
        $order -> add_order_note(__($note, 'sc'));
        $order->save();
        
        wp_send_json_success();
    }
    
    /**
     * Function call_rest_api
     * Call REST API with cURL post and get response.
     * 
     * @param type $url - API URL
     * @param array $checksum_params - parameters we use for checksum
     * @param string $secret - merchant secret
     * @param string $hash - merchant hash
     * @param array $other_params - other parameters we use
     * @param string $checksum - the checksum
     * 
     * @return mixed
     */
    public function call_rest_api($url, $checksum_params, $secret, $hash, $other_params = array(), $checksum = '')
    {
        $checksum_params['checksum'] = $checksum;
        
        if($checksum == '') {
            foreach($checksum_params as $val) {
                $checksum .= $val;
            }

            $checksum .= $secret;
            $checksum_params['checksum'] = hash($hash, $checksum);
        }
        
        if(!empty($other_params) and is_array($other_params)) {
            $params = array_merge($checksum_params, $other_params);
        }
        else {
            $params = $checksum_params;
        }
        
        $json_post = json_encode($params);
        $this->create_log($params, 'json_post as array: ');

        // create cURL post
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_post);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type:application/json', 'Content-Length: ' . strlen($json_post))
        );
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $resp = curl_exec($ch);
        curl_close ($ch);
        
        if($resp === false) {
            return false;
        }
        
        return json_decode($resp, true);
    }
    
    /**
     * Function get_rest_apms
     * Get REST API APMs by passed data.
     * 
     * @param array $data - session data
     * @return array
     */
    public function get_rest_apms($data = array())
    {
        require_once 'sc_config.php';
        
        $this->create_log($data, 'Params for Session Token: ');
        
        // getSessionToken
        $session_token_data = $this->get_session_token($data);
        $session_token = $session_token_data['sessionToken'];
        
        if(!$session_token) {
            $this->create_log('', 'Session Token is FALSE.');
            echo json_encode(array('status' => 0));
            exit;
        }
        
        # get merchant payment methods
        $checksum_params = array(
            'merchantId'        => $data['merchant_id'],
            'merchantSiteId'    => $data['merchantsite_id'],
            'clientRequestId'   => $data['cri2'],
            'timeStamp'         => current(explode('_', $data['cri2'])),
        );

        $other_params = array(
            'sessionToken'      => $session_token,
            'currencyCode'      => $data['currencyCode'], // optional
            'countryCode'       => $data['sc_country'], // optional
            'languageCode'      => $data['languageCode'], // optional
            'type'              => '', // optional
        );

        $resp_arr = $this->call_rest_api(
            $data['test'] == 'yes' ? SC_TEST_REST_PAYMENT_METHODS_URL : SC_LIVE_REST_PAYMENT_METHODS_URL,
            $checksum_params,
            '',
            '',
            $other_params,
            $data['cs2']
        );
        
        echo json_encode(array('status' => 1, 'data' => $resp_arr));
        exit;
        # get merchant payment methods END
    }
    
    /**
     * Function process_payment
     * 
     * @param array $data
     * @return array|bool
     */
    public function process_payment($data)
    {
        $this->use_apm_payment_url = $this->test_apm_payment_url;
        if($_SESSION['SC_Variables']['test'] == 'no') {
            $this->use_apm_payment_url = $this->live_apm_payment_url;
        }
        
        echo 'process_payment Data: <pre>'.print_r($data,true).'</pre>';
        
        $session_token = $this->get_session_token($data);
        $time = date('YmdHis', time());
        
        $params = array(
            'sessionToken'      => $session_token,
        //    'orderId'         => $session_token, // optional
            'merchantId'        => $_SESSION['SC_Variables']['merchant_id'],
            'merchantSiteId'    => $_SESSION['SC_Variables']['merchantsite_id'],
            'userTokenId'       => '', // optional - ID of the user in the merchant’s system.
            'clientUniqueId'    => $_REQUEST['order-pay'],
            'clientRequestId'   => $data['client_request_id'],
            'currency'          => $data['currency'],
            'amount'            => (string) $data['total_amount'],
            'amountDetails'     => array(
                'totalShipping'     => 0, // ?
                'totalHandling'     => $data['handling'], // this is actually shipping
                'totalDiscount'     => $data['discount'],
                'totalTax'          => $data['total_tax'],
            ),
            'items'             => $data['items'],
        //    'deviceDetails'     => '', // optionals
            'userDetails'       => array(
                'firstName'         => $data['first_name'],
                'lastName'          => $data['last_name'],
                'address'           => $data['address1'],
                'phone'             => $data['phone1'],
                'zip'               => $data['zip'],
                'city'              => $data['city'],
                'country'           => $data['country'],
                'state'             => '', // ????
                'email'             => $data['email'],
                'county'            => '',
            ),
            'shippingAddress'   => array(
                'firstName'         => $data['shippingFirstName'],
                'lastName'          => $data['shippingLastName'],
                'address'           => $data['shippingAddress'],
                'cell'              => '',
                'phone'             => '',
                'zip'               => $data['shippingZip'],
                'city'              => $data['shippingCity'],
                'country'           => $data['shippingCountry'],
                'state'             => '',
                'email'             => '',
                'shippingCounty'    => $data['shippingCountry'],
            ),
        //    'billingAddress'        => array(), // optional
        //    'dynamicDescriptor'     => array(), // optional
        //    'merchantDetails'       => array(), // optional
        //    'addendums'             => array(), // optional
            'paymentMethod'         => $_SESSION['SC_Variables']['APM_data']['payment_method'],
        //    'userAccountDetails'    => '', // optional
        //    'userPaymentOption'     => array(), // optional
            'urlDetails'            => $data['urlDetails'],
        //    'subMethodDetails'      => array(), // optional
            'timeStamp'             => current(explode('_', $_SESSION['SC_Variables']['cri2'])),
            'checksum'              => $data['checksum'],
        );
        
        $resp = $this->call_rest_api(
            $this->use_apm_payment_url,
            $params,
            '',
            '',
            array(),
            $data['checksum']
        );
        
    //    echo '<h4>Payment APM resp: </h4><pre>'.print_r($resp, true).'</pre>';
        
        if(!is_array(@$resp)) {
            return false;
        }
        
        return $resp;
    }
    
    /**
     * Function get_session_token
     * Get session tokens for different actions with the REST API
     * 
     * @param array $data
     * @return array|bool
     */
    private function get_session_token($data)
    {
        if(!isset($_SESSION['SC_Variables']['merchant_id'], $_SESSION['SC_Variables']['merchantsite_id'])) {
            $this->create_log('', 'No session variables. ');
            return false;
        }
        
        $time = date('YmdHis', time());

        $params = array(
            'merchantId'        => $_SESSION['SC_Variables']['merchant_id'],
            'merchantSiteId'    => $_SESSION['SC_Variables']['merchantsite_id'],
            'clientRequestId'   => $_SESSION['SC_Variables']['cri1'],
            'timeStamp'         => current(explode('_', $_SESSION['SC_Variables']['cri1'])),
        );

        $resp_arr = $this->call_rest_api(
            $_SESSION['SC_Variables']['test'] == 'yes' ? SC_TEST_SESSION_TOKEN_URL : SC_LIVE_SESSION_TOKEN_URL,
            $params,
            '',
            '',
            array(),
            $_SESSION['SC_Variables']['cs1']
        );
        
        if(
            !$resp_arr
            || !is_array($resp_arr)
            || !isset($resp_arr['status'])
            || $resp_arr['status'] != 'SUCCESS'
        ) {
            $this->create_log($resp_arr, 'getting getSessionToken error: ');
            return false;
        }
        
        return $resp_arr;
    }
    
    /**
     * Function create_log
     * Create logs. You MUST have defined SC_LOG_FILE_PATH const,
     * holding the full path to the log file.
     * 
     * @param mixed $data
     * @param string $title - title of the printed log
     */
    private function create_log($data, $title = '')
    {
        if(!defined('WP_DEBUG') || WP_DEBUG === false) {
            return;
        }
        
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
            if(defined('SC_LOG_FILE_PATH')) {
                file_put_contents(SC_LOG_FILE_PATH, date('H:i:s') . ': ' . $d."\r\n"."\r\n", FILE_APPEND);
            }
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

// work with Ajax when change country from the select menu manually
if(
    isset(
        $_SERVER['HTTP_X_REQUESTED_WITH']
        ,$_SESSION['SC_Variables']['merchant_id']
        ,$_SESSION['SC_Variables']['merchantsite_id']
        ,$_SESSION['SC_Variables']['sc_country']
        ,$_SESSION['SC_Variables']['currencyCode']
        ,$_SESSION['SC_Variables']['languageCode']
        ,$_SESSION['SC_Variables']['payment_api']
        ,$_SESSION['SC_Variables']['cs1']
        ,$_SESSION['SC_Variables']['cs1']
        ,$_SESSION['SC_Variables']['cri1']
        ,$_SESSION['SC_Variables']['cri2']
        ,$_SESSION['SC_Variables']['test']
        ,$_POST['callFromJS']
    )
    && $_SESSION['SC_Variables']['payment_api'] == 'rest'
    && $_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest'
    // set shis param in JS call to separate JS call from simple class load
    && $_POST['callFromJS'] == 1
) {
    // if the Country come as POST variable
    if(!empty($_SESSION['SC_Variables']['sc_country'])) {
        $apms_getter = new SC_REST_API();
        $apms_getter->get_rest_apms($_SESSION['SC_Variables']);
    }
    // WC calls this method twice, so we want to get APMs only on first call
    else {
        $_SESSION['SC_Variables']['sc_country'] = $_POST['country'];
        
        $apms_getter = new SC_REST_API();
        $apms_getter->get_rest_apms($_SESSION['SC_Variables']);
    }
}
