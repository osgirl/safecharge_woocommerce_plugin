<?php

/**
 * Description of SC_APMs_Getter
 *
 * @author miroslavs
 */

if (!session_id()) {
    session_start();
}

class SC_APMs_Getter
{
    private static $use_session_token_url = '';
    private static $use_merch_paym_meth_url = '';
    
    /**
     * Function get_rest_apms
     * Get REST API APMs by passed data.
     * 
     * @param array $data
     * @return array
     */
    public static function get_rest_apms($data)
    {
        require_once 'SC_API_Caller.php';
        require_once 'SC_Versions_Resolver.php';
        require_once 'sc_config.php';
        
        # getSessionToken
        $time = date('YmdHis', time());

        $params = array(
            'merchantId'        => $data['merchant_id'],
            'merchantSiteId'    => $data['merchantsite_id'],
            'clientRequestId'   => $data['cri1'],
            'timeStamp'         => current(explode('_', $data['cri1'])),
        );

        $resp_arr = SC_API_Caller::call_rest_api(
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
            SC_API_Caller::create_log($resp_arr, 'getting getSessionToken error: ');
            echo json_encode(array('status' => 0));
            exit;
        }
        
        
        # getSessionToken END
        
        # get merchant payment methods
        $checksum_params = array(
            'merchantId'        => $data['merchant_id'],
            'merchantSiteId'    => $data['merchantsite_id'],
            'clientRequestId'   => $data['cri2'],
            'timeStamp'         => current(explode('_', $data['cri2'])),
        );

        $other_params = array(
            'sessionToken'      => $resp_arr['sessionToken'],
            'currencyCode'      => $data['currencyCode'], // optional
            'countryCode'       => $data['sc_country'], // optional
            'languageCode'      => $data['languageCode'], // optional
            'type'              => '', // optional
        );

        $resp_arr = SC_API_Caller::call_rest_api(
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
}

if(isset(
    $_SESSION['merchant_id']
    ,$_SESSION['merchantsite_id']
    ,$_SESSION['sc_country']
    ,$_SESSION['currencyCode']
    ,$_SESSION['languageCode']
    ,$_SESSION['payment_api']
    ,$_SESSION['cs1']
    ,$_SESSION['cs1']
    ,$_SESSION['cri1']
    ,$_SESSION['cri2']
    ,$_SESSION['test']
)) {
    if($_SESSION['payment_api'] == 'rest') {
        $apms_getter = new SC_APMs_Getter();
        $apms_getter->get_rest_apms($_SESSION);
    }
    
    exit;
}
