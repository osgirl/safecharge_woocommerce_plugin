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
    private $cpanel_url = '';
    // GW settings
    private $settings = array();
    
    /**
     * Function sc_refund_order
     * Create a refund.
     * 
     * @params array $settings - the GW settings
     * @params array $refund - system last refund data
     * @params array $order_meta_data - additional meta data for the order
     * @params string $currency - used currency
     * @params string $notify_url
     */
    public function sc_refund_order(array $settings, array $refund, array $order_meta_data, $currency, $notify_url)
    {
        $this->settings = $settings;
        $this->create_log($refund, 'Refund data: ');
        
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
            return array(
                'msg' => 'The Order does not have Transaction ID. Refund can not procceed.',
                'new_order_status' => ''
            );
        }
        
        $ref_parameters = array(
            'merchantId'            => $this->settings['merchant_id'],
            'merchantSiteId'        => $this->settings['merchantsite_id'],
            'clientRequestId'       => $time . '_' . $ord_tr_id,
            'clientUniqueId'        => $ord_tr_id,
            'amount'                => number_format($refund['amount'], 2),
            'currency'              => $currency,
            'relatedTransactionId'  => $ord_tr_id, // GW Transaction ID
            'authCode'              => $order_meta_data['auth_code'],
            'comment'               => $refund['reason'], // optional
            'url'                   => $notify_url,
            'timeStamp'             => $time,
        );
        
        $checksum = '';
        foreach($ref_parameters as $val) {
            $checksum .= $val;
        }
        $checksum = hash(
            $this->settings['hash_type'],
            $checksum . $this->settings['secret']
        );
        
        $other_params = array(
            'urlDetails'            => array('notificationUrl' => $notify_url),
        );
        
        $this->create_log($ref_parameters, 'refund_parameters: ');
        $this->create_log($other_params, 'other_params: ');
        
        $json_arr = $this->call_rest_api(
            $this->refund_url,
            $ref_parameters,
            $checksum,
            $other_params
        );
        
        $this->create_log($json_arr, 'Refund Response: ');
        
        $note = '';
        $error_note = 'Please manually delete request Refund #'
            .$refund['id'].' form the order or login into <i>'. $this->cpanel_url
            .'</i> and refund Transaction ID '.$ord_tr_id;
        
        if($json_arr === false){
            return array(
                'msg' => 'The REST API retun false. ' . $error_note,
                'new_order_status' => ''
            );
        }
        
        if(!is_array($json_arr)) {
            parse_str($resp, $json_arr);
        }

        if(!is_array($json_arr)) {
            return array(
                'msg' => 'Invalid API response. ' . $error_note,
                'new_order_status' => ''
            );
        }
        
        // the status of the request is ERROR
        if(isset($json_arr['status']) && $json_arr['status'] == 'ERROR') {
            return array(
                'msg' => 'Request ERROR - "' . $json_arr['reason'] .'" '. $error_note,
                'new_order_status' => ''
            );
        }
        
        // the status of the request is SUCCESS, check the transaction status
        if(isset($json_arr['transactionStatus']) && !empty($json_arr['transactionStatus'])) {
            if($json_arr['transactionStatus'] == 'ERROR') {
                if(isset($json_arr['gwErrorReason']) && !empty($json_arr['gwErrorReason'])) {
                    $note = $json_arr['gwErrorReason'];
                }
                elseif(isset($json_arr['paymentMethodErrorReason']) && !empty($json_arr['paymentMethodErrorReason'])) {
                    $note = $json_arr['paymentMethodErrorReason'];
                }
                else {
                    $note = 'Transaction error';
                }
                
                return array(
                    'msg' => $note. '. ' .$error_note,
                    'new_order_status' => ''
                );
            }
            
            if($json_arr['transactionStatus'] == 'DECLINED') {
                return array(
                    'msg' => 'The refun was declined. ' .$error_note,
                    'new_order_status' => ''
                );
            }
            
            if($json_arr['transactionStatus'] == 'APPROVED') {
                return array(
                    'msg' => 'Your request - Refund #' . $refund['id'] . ', was successful.',
                    'new_order_status' => 'refunded'
                );
            }
        }
        
        return array(
            'msg' => 'The status of request - Refund #' . $refund['id'] . ', is UNKONOWN.',
            'new_order_status' => ''
        );
    }
    
    /**
     * Function call_rest_api
     * Call REST API with cURL post and get response.
     * The URL depends from the case.
     * 
     * @param type $url - API URL
     * @param array $checksum_params - parameters we use for checksum
     * @param string $checksum - the checksum
     * @param array $other_params - other parameters we use
     * 
     * @return mixed
     */
    public function call_rest_api($url, $checksum_params, $checksum, $other_params = array())
    {
        try {
            $checksum_params['checksum'] = $checksum;

            if(!empty($other_params) and is_array($other_params)) {
                $params = array_merge($checksum_params, $other_params);
            }
            else {
                $params = $checksum_params;
            }

            $json_post = json_encode($params);
            $this->create_log($params, 'json_post as array: ');
            $this->create_log($json_post, 'json_post: ');
            
            $header =  array(
                'Content-Type: application/json',
                'Content-Length: ' . strlen($json_post),
            );
            
            // fix for some servers
            if(strpos($url, 'paymentAPM.do') !== false) {
                $header[] = 'Expect: 100-continue';
            }

            // create cURL post
            $ch = curl_init();

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json_post);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

            $resp = curl_exec($ch);
            curl_close ($ch);
            
            $this->create_log($url, 'Call rest api URL: ');
            $this->create_log($resp, 'Call rest api resp: ');

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
            $this->create_log($session_token, 'Session Token is FALSE.');
            
            if($is_ajax) {
                echo json_encode(array(
                    'status' => 0,
                    'msg' => 'No Session Token',
                //    'data' => $data,
                    'ses_t_data' => json_encode($session_token_data),
                ));
                exit;
            }
            
            return json_encode(array('status' => 0, 'msg' => 'No Session Token'));
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
        
        $this->create_log('', 'Call REST API to get REST APMs: ');

        $resp_arr = $this->call_rest_api(
            $data['test'] == 'yes' ? SC_TEST_REST_PAYMENT_METHODS_URL : SC_LIVE_REST_PAYMENT_METHODS_URL,
            $checksum_params,
            $data['cs2'],
            $other_params
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
     * @param array $data - contains the checksum
     * @param array $sc_variables
     * @param string $order_id
     * @param string $payment_method - apm|d3d
     * 
     * @return array|bool
     */
    public function process_payment($data, $sc_variables, $order_id, $payment_method)
    {
        // common parameters for the methods
        $params = array(
            'merchantId'        => $sc_variables['merchant_id'],
            'merchantSiteId'    => $sc_variables['merchantsite_id'],
            'userTokenId'       => $data['email'], // the email of the logged user or user who did the payment
            'clientUniqueId'    => $order_id,
            'clientRequestId'   => $data['client_request_id'],
            'currency'          => $data['currency'],
            'amount'            => (string) $data['total_amount'],
            'amountDetails'     => array(
                'totalShipping'     => '0.00',
                'totalHandling'     => $data['handling'], // this is actually shipping
                'totalDiscount'     => $data['discount'],
                'totalTax'          => $data['total_tax'],
            ),
            'items'             => $data['items'],
            'userDetails'       => array(
                'firstName'         => $data['first_name'],
                'lastName'          => $data['last_name'],
                'address'           => $data['address1'],
                'phone'             => $data['phone1'],
                'zip'               => $data['zip'],
                'city'              => $data['city'],
                'country'           => $data['country'],
                'state'             => '',
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
            'billingAddress'   => array(
                'firstName'         => $data['first_name'],
                'lastName'          => $data['last_name'],
                'address'           => $data['address1'],
                'cell'              => '',
                'phone'             => $data['phone1'],
                'zip'               => $data['zip'],
                'city'              => $data['city'],
                'country'           => $data['country'],
                'state'             => $data['state'],
                'email'             => $data['email'],
                'county'            => '',
            ),
            'urlDetails'        => $data['urlDetails'],
            'timeStamp'         => $data['time_stamp'],
            'checksum'          => $data['checksum'],
        );
        
        // set parameters specific for the payment method
        switch ($payment_method) {
            case 'apm':
                // for D3D we use other token
                $session_token_data = $this->get_session_token($sc_variables);
                $session_token = @$session_token_data['sessionToken'];

                if(!$session_token) {
                    return false;
                }
                
                $params['paymentMethod']    = $sc_variables['APM_data']['payment_method'];
                $params['sessionToken']     = $session_token;
                
                $endpoint_url = $sc_variables['test'] == 'no' ? SC_LIVE_PAYMENT_URL : SC_TEST_PAYMENT_URL;
                break;
            
            case 'd3d':
                // in D3D use the session token from card tokenization
                if(!isset($sc_variables['lst']) || empty($sc_variables['lst']) || !$sc_variables['lst']) {
                    return false;
                }
                
                $params['sessionToken']     = $sc_variables['lst'];
                $params['isDynamic3D']      = 1;
                $params['deviceDetails']    = $this->get_device_details();
                $params['cardData']         = array(
                    'ccTempToken'       => $sc_variables['APM_data']['apm_fields']['ccCardNumber'],
                    'CVV'               => $sc_variables['APM_data']['apm_fields']['CVV'],
                    'cardHolderName'    => $sc_variables['APM_data']['apm_fields']['ccNameOnCard'],
                );
                
                $endpoint_url = $sc_variables['test'] == 'no' ? SC_LIVE_D3D_URL : SC_TEST_D3D_URL;
                break;
            
            // if we can't set $endpoint_url stop here
            default:
                return false;
        }
        
        $this->create_log(json_encode($params), 'Call REST API when Process Payment: ');
        $this->create_log(
            $sc_variables['merchant_id']. $sc_variables['merchantsite_id']. $data['client_request_id']. ((string) $data['total_amount']). $data['currency']. $data['time_stamp']
            ,'Call REST API when Process Payment checksum string: '
        );
        $this->create_log($data['checksum'], 'Checksum went to REST: ');
        
        $resp = $this->call_rest_api(
            $endpoint_url,
            $params,
            $data['checksum']
        );
        
        if(!is_array(@$resp)) {
            $this->create_log($resp, 'Process Payment response: ');
            return false;
        }
        
        return $resp;
    }
    
    /**
     * Function get_session_token
     * Get session tokens for different actions with the REST API.
     * We can call this method with Ajax when need tokenization.
     * 
     * @param array $data
     * @param bool $is_ajax
     * 
     * @return array|bool
     */
    public function get_session_token($data, $is_ajax = false)
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

        $this->create_log($params, 'Call REST API for Session Token $params: ');
        
        $resp_arr = $this->call_rest_api(
            $data['test'] == 'yes' ? SC_TEST_SESSION_TOKEN_URL : SC_LIVE_SESSION_TOKEN_URL,
            $params,
            $data['cs1']
        );
        
        if(
            !$resp_arr
            || !is_array($resp_arr)
            || !isset($resp_arr['status'])
            || $resp_arr['status'] != 'SUCCESS'
        ) {
            $this->create_log($resp_arr, 'getting getSessionToken error: ');
            
            if($is_ajax) {
                echo json_encode(array('status' => 0));
                exit;
            }
            
            return false;
        }
        
        if($is_ajax) {
            $resp_arr['test'] = $_SESSION['SC_Variables']['test'];
            echo json_encode(array('status' => 1, 'data' => $resp_arr));
            exit;
        }
        
        return $resp_arr;
    }
    
    /**
     * Function get_device_details
     * Get browser and device based on HTTP_USER_AGENT.
     * The method is based on D3D payment needs.
     * 
     * @return array $device_details
     */
    private function get_device_details()
    {
        $device_details = array(
            'deviceType'    => 'UNKNOWN', // DESKTOP, SMARTPHONE, TABLET, TV, and UNKNOWN
            'deviceName'    => '',
            'deviceOS'      => '',
            'browser'       => '',
            'ipAddress'     => '',
        );
        
        if(isset($_SERVER['HTTP_USER_AGENT']) && !empty(isset($_SERVER['HTTP_USER_AGENT']))) {
            $user_agent = strtolower($_SERVER['HTTP_USER_AGENT']);
            
            $device_details['deviceName'] = $_SERVER['HTTP_USER_AGENT'];
            
            if(defined('SC_DEVICES_TYPES')) {
                $devs_tps = json_decode(SC_DEVICES_TYPES, true);
                
                if(is_array($devs_tps) && !empty($devs_tps)) {
                    foreach ($devs_tps as $d) {
                        if (strstr($user_agent, $d) !== false) {
                            if($d == 'linux' || $d == 'windows') {
                                $device_details['deviceType'] = 'DESKTOP';
                            }
                            else {
                                $device_details['deviceType'] = $d;
                            }
                            
                            break;
                        }
                    }
                }
            }
            
            
            if(defined('SC_DEVICES')) {
                $devs = json_decode(SC_DEVICES, true);

                if(is_array($devs) && !empty($devs)) {
                    foreach ($devs as $d) {
                        if (strstr($user_agent, $d) !== false) {
                            $device_details['deviceOS'] = $d;
                            break;
                        }
                    }
                }
            }

            if(defined('SC_BROWSERS')) {
                $brs = json_decode(SC_BROWSERS, true);
                
                if(is_array($brs) && !empty($brs)) {
                    foreach ($brs as $b) {
                        if (strstr($user_agent, $b) !== false) {
                            $device_details['browser'] = $b;
                            break;
                        }
                    }
                }
            }
            
            // get ip
            $ip_address = '';

            if (isset($_SERVER["REMOTE_ADDR"])) {
                $ip_address = $_SERVER["REMOTE_ADDR"];
            }
            elseif (isset($_SERVER["HTTP_X_FORWARDED_FOR"])) {
                $ip_address = $_SERVER["HTTP_X_FORWARDED_FOR"];
            }
            elseif (isset($_SERVER["HTTP_CLIENT_IP"])) {
                $ip_address = $_SERVER["HTTP_CLIENT_IP"];
            }

            $device_details['ipAddress'] = (string) $ip_address;
        }
            
        return $device_details;
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
    $apms_getter = new SC_REST_API();
    
    // when we want Session Token
    if(@$_POST['needST'] == 1) {
        $apms_getter->get_session_token($_SESSION['SC_Variables'], true);
    }
    // go to Dynamic 3D payment 
//    elseif(@$_POST['d3d'] == 1) {
//        $apms_getter->process_d3d_payment($_SESSION['SC_Variables']);
//    }
    // when we want APMs
    else {
        // if the Country come as POST variable
        if(empty($_SESSION['SC_Variables']['sc_country'])) {
            $_SESSION['SC_Variables']['sc_country'] = $_POST['country'];
        }
        
        $apms_getter->get_rest_apms($_SESSION['SC_Variables'], true);
    }
}
elseif(
    @$_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest'
    // don't come here when try to refund!
    && @$_REQUEST['action'] != 'woocommerce_refund_line_items'
) {
    echo json_encode(array(
        'status' => 2,
        'msg' => $_SESSION['SC_Variables']['payment_api'] != 'rest'
            ? 'You are using Cashier API. APMs are not available with it.' : 'Missing some of conditions to using REST API.'
    ));
}