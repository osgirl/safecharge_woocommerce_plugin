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
        ,$_SESSION['SC_Variables']['payment_api']
        ,$_SESSION['SC_Variables']['test']
        ,$_POST['callFromJS']
    )
    && $_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest'
    // set shis param in JS call to separate JS call from simple class load
//    && $_POST['callFromJS'] == 1
    // when we cancel order we no need to use rest as value for the payment_api
    && ($_SESSION['SC_Variables']['payment_api'] == 'rest' || isset($_POST['cancelOrder']))
) {
    require_once 'SC_REST_API.php';
    
    // when we want Session Token
    if(isset($_POST['needST']) && $_POST['needST'] == 1) {
        SC_REST_API::get_session_token($_SESSION['SC_Variables'], true);
    }
    // when merchant cancel the order via Void button
    elseif(isset($_POST['cancelOrder']) && $_POST['cancelOrder'] == 1) {
        SC_REST_API::cancel_order($_SESSION['SC_Variables'], true);
        unset($_SESSION['SC_Variables']);
    }
    // When user want to delete logs.
    elseif(isset($_POST['deleteLogs']) && $_POST['deleteLogs'] == 1) {
        $logs = array();
        $logs_dir = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR;
        
        foreach(scandir($logs_dir) as $file) {
            if($file != '.' && $file != '..' && $file != '.htaccess') {
                $logs[] = $file;
            }
        }
        
        if(count($logs) > 30) {
            sort($logs);
            
            for($cnt = 0; $cnt < 30; $cnt++) {
                if(is_file($logs_dir . $logs[$cnt])) {
                    if(!unlink($logs_dir . $logs[$cnt])) {
                        echo json_encode(array(
                            'status' => 0,
                            'msg' => 'Error when try to delete file: ' . $logs[$cnt]
                        ));
                        exit;
                    }
                }
            }
            
            echo json_encode(array('status' => 1, 'msg' => ''));
        }
        else {
            echo json_encode(array('status' => 0, 'msg' => 'The log files are less than 30.'));
        }
        
        exit;
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