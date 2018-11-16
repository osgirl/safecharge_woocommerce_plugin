<?php

/**
 * Work with Ajax when change country from the select menu manually.
 * 
 * 2018
 * 
 * @author SafeCharge
 */

if (!session_id()) {
    session_start();
}

require_once 'sc_config.php';

// The following fileds are MANDATORY for success
if(
    isset(
        $_SERVER['HTTP_X_REQUESTED_WITH']
        ,$_SESSION['SC_Variables']['merchantId']
        ,$_SESSION['SC_Variables']['merchantSiteId']
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
    require_once 'SC_REST_API.php';
    
    // when we want Session Token
    if(@$_POST['needST'] == 1) {
        SC_REST_API::get_session_token($_SESSION['SC_Variables'], true);
    }
    // when we want APMs
    else {
        // if the Country come as POST variable
        if(empty($_SESSION['SC_Variables']['sc_country'])) {
            $_SESSION['SC_Variables']['sc_country'] = $_POST['country'];
        }
        
        SC_REST_API::get_rest_apms($_SESSION['SC_Variables'], true);
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