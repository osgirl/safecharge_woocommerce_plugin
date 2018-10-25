<?php

if (!session_id()) {
    session_start();
}

require_once 'sc_config.php';

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
    private $refund_url = '';
    private $apm_payment_url = '';
    private $cpanel_url = '';
    // GW settings
    private $settings = array();
    
    /**
     * Function sc_refund_order
     * Create a refund.
     * 
     * @params array $settings - the GW settings
     * @params string $refund_json - system last refund data as json
     * @params array $order_meta_data - additional meta data for the order
     * @params string $currency - used currency
     * @params string $notify_url
     */
    public function sc_refund_order(array $settings, $refund_json, array $order_meta_data, $currency, $notify_url)
    {
        $this->settings = $settings;
        $refund = json_decode($refund_json, true);
        
        $this->refund_url = SC_TEST_REFUND_URL;
        $this->cpanel_url = SC_TEST_CPANEL_URL;
        
        if($this->settings['test'] == 'no') {
            $this->refund_url = SC_LIVE_REFUND_URL;
            $this->cpanel_url = SC_LIVE_CPANEL_URL;
        }
        
        $time = date('YmdHis', time());
        
        // order transaction ID
        $ord_tr_id = $order_meta_data['order_tr_id'];
        if(!$ord_tr_id || empty($ord_tr_id)) {
            return 'The Order does not have Transaction ID. Refund can not procceed.';
        }
        
        $ref_parameters = array(
            'merchantId'            => $this->settings['merchant_id'],
            'merchantSiteId'        => $this->settings['merchantsite_id'],
            'clientRequestId'       => $time . '_' . $ord_tr_id,
            'clientUniqueId'        => $ord_tr_id,
            'amount'                => number_format($refund['data']['amount'], 2),
            'currency'              => $currency,
            'relatedTransactionId'  => $ord_tr_id, // GW Transaction ID
            'authCode'              => $order_meta_data['auth_code'],
            'comment'               => $refund['data']['reason'], // optional
            'url'                   => $notify_url,
            'timeStamp'             => $time,
        );
        
        $other_params = array(
            'urlDetails'            => array('notificationUrl' => $notify_url),
        );
        
        $this->create_log($ref_parameters, 'refund_parameters: ');
        $this->create_log($other_params, 'other_params: ');
        
        $json_arr = $this->call_rest_api(
            $this->refund_url,
            $ref_parameters,
            $this->settings['secret'],
            $this->settings['hash_type'],
            $other_params
        );
        
        $note = '';
        $error_note = 'Please manually delete request Refund #'
            .$refund['id'].' form the order or login into <i>'. $this->cpanel_url
            .'</i> and refund Transaction ID '.$ord_tr_id;
        
        if($json_arr === false){
            return 'The REST API retun false. ' . $error_note;
        }
        
        if(!is_array($json_arr)) {
            parse_str($resp, $json_arr);
        }

        if(!is_array($json_arr)) {
            return 'Invalid API response. ' . $error_note;
        }
        
        $this->create_log($json_arr, 'json_arr: ');
        
        // the status of the request
        if(isset($json_arr['status']) && $json_arr['status'] == 'ERROR') {
            return 'Error, Invalid checksum. ' . $error_note;
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
            
            return $note. '. ' .$error_note;
        }
        
        // create refund note
        return 'Your request - Refund #' . $refund['id'] . ', was successful.';
    }
    
    /**
     * Function call_rest_api
     * Call REST API with cURL post and get response.
     * The URL depends from the case.
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
        try {
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
        catch(Exception $e) {
            $this->create_log($e, 'Catche error when call to REST API: ');
            return false;
        }
    }
    
    /**
     * Function get_rest_apms
     * Get REST API APMs by passed data.
     * 
     * @param array $data - session data, or other passed data
     * @param bool $is_ajax - is ajax call, after country changed
     * 
     * @return string - json
     */
    public function get_rest_apms($data = array(), $is_ajax = false)
    {
        // getSessionToken
        $session_token_data = $this->get_session_token($data);
        $session_token = $session_token_data['sessionToken'];
        
        if(!$session_token) {
            $this->create_log('', 'Session Token is FALSE.');
            
            if($is_ajax) {
                echo json_encode(array('status' => 0));
                exit;
            }
            
            return json_encode(array('status' => 0));
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
            'currencyCode'      => $data['currencyCode'],
            'countryCode'       => $data['sc_country'],
            'languageCode'      => $data['languageCode'],
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
        
        if($is_ajax) {
            echo json_encode(array('status' => 1, 'data' => $resp_arr));
            exit;
        }

        return json_encode(array('status' => 1, 'data' => $resp_arr));
        # get merchant payment methods END
    }
    
    /**
     * Function process_payment
     * 
     * @param array $data
     * @param array $sc_variables
     * @param string $order_pay
     * 
     * @return array|bool
     */
    public function process_payment($data, $sc_variables, $order_pay)
    {
        $this->apm_payment_url = SC_TEST_PAYMENT_URL;
        if($sc_variables['test'] == 'no') {
            $this->apm_payment_url = SC_LIVE_PAYMENT_URL;
        }
        
        $session_token_data = $this->get_session_token($sc_variables);
        $session_token = $session_token_data['sessionToken'];
        $time = date('YmdHis', time());
        
        // some optional parameters are not included
        $params = array(
            'sessionToken'      => $session_token,
            'merchantId'        => $sc_variables['merchant_id'],
            'merchantSiteId'    => $sc_variables['merchantsite_id'],
            'userTokenId'       => '', // optional - ID of the user in the merchant’s system.
            'clientUniqueId'    => $order_pay,
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
            'userDetails'       => array(
                'firstName'             => $data['first_name'],
                'lastName'              => $data['last_name'],
                'address'               => $data['address1'],
                'phone'                 => $data['phone1'],
                'zip'                   => $data['zip'],
                'city'                  => $data['city'],
                'country'               => $data['country'],
                'state'                 => '', // ????
                'email'                 => $data['email'],
                'county'                => '',
            ),
            'shippingAddress'   => array(
                'firstName'             => $data['shippingFirstName'],
                'lastName'              => $data['shippingLastName'],
                'address'               => $data['shippingAddress'],
                'cell'                  => '',
                'phone'                 => '',
                'zip'                   => $data['shippingZip'],
                'city'                  => $data['shippingCity'],
                'country'               => $data['shippingCountry'],
                'state'                 => '',
                'email'                 => '',
                'shippingCounty'        => $data['shippingCountry'],
            ),
            'paymentMethod'         => $sc_variables['APM_data']['payment_method'],
            'urlDetails'            => $data['urlDetails'],
            'timeStamp'             => current(explode('_', $sc_variables['cri2'])),
            'checksum'              => $data['checksum'],
        );
        
        $resp = $this->call_rest_api(
            $this->apm_payment_url,
            $params,
            '',
            '',
            array(),
            $data['checksum']
        );
        
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
        if(!isset($data['merchant_id'], $data['merchantsite_id'])) {
            $this->create_log($data, 'No session variables: ');
            return false;
        }
        
        $time = date('YmdHis', time());

        $params = array(
            'merchantId'        => $data['merchant_id'],
            'merchantSiteId'    => $data['merchantsite_id'],
            'clientRequestId'   => $data['cri1'],
            'timeStamp'         => current(explode('_', $data['cri1'])),
        );

        $resp_arr = $this->call_rest_api(
            $data['test'] == 'yes' ? SC_TEST_SESSION_TOKEN_URL : SC_LIVE_SESSION_TOKEN_URL,
            $params,
            '',
            '',
            array(),
            $data['cs1']
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
        if(!defined('SC_DEBUG') || SC_DEBUG === false) {
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
            $d = '<pre>'.$data.'</pre>';
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
        
        if(defined('SC_LOG_FILE_PATH')) {
            try {
                file_put_contents(SC_LOG_FILE_PATH, date('H:i:s') . ': ' . $d."\r\n"."\r\n", FILE_APPEND);
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
}

// work with Ajax when change country from the select menu manually
// The following fileds are MANDATORY for success
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
