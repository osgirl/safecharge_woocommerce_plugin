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
    )
    && $_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest'
    && !empty($_SESSION['SC_Variables']['merchantId'])
    && !empty($_SESSION['SC_Variables']['merchantSiteId'])
    && in_array($_SESSION['SC_Variables']['payment_api'], array('cashier', 'rest'))
) {
    require_once 'SC_REST_API.php';
    
    // when merchant cancel the order via Void button
    if(isset($_POST['cancelOrder']) && $_POST['cancelOrder'] == 1) {
        SC_REST_API::void_and_settle_order($_SESSION['SC_Variables'], 'void', true);
        unset($_SESSION['SC_Variables']);
        exit;
    }
    
    // When user want to delete logs.
    if(isset($_POST['deleteLogs']) && $_POST['deleteLogs'] == 1) {
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
    
    if($_SESSION['SC_Variables']['payment_api'] == 'rest') {
        // when we want Session Token
        if(isset($_POST['needST']) && $_POST['needST'] == 1) {
            SC_REST_API::get_session_token($_SESSION['SC_Variables'], true);
        }
        // when we want APMs
        elseif(isset($_POST['country']) && $_POST['country'] != '') {
            // if the Country come as POST variable
            if(empty($_SESSION['SC_Variables']['sc_country'])) {
                $_SESSION['SC_Variables']['sc_country'] = $_POST['country'];
            }

            SC_REST_API::get_rest_apms($_SESSION['SC_Variables'], true);
        }
        // when merchant settle the order via Settle button
        elseif(isset($_POST['settleOrder']) && $_POST['settleOrder'] == 1) {
            SC_REST_API::void_and_settle_order($_SESSION['SC_Variables'], 'settle', true);
            unset($_SESSION['SC_Variables']);
        }
        
        exit;
    }
    // here we no need REST API
    else {}
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