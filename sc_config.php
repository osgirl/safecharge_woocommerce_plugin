<?php

/* 
 * Put all Constants here
 */

define('SC_GATEWAY_TITLE', 'SafeCharge');

// URLs for session token
define('SC_LIVE_SESSION_TOKEN_URL', 'https://secure.safecharge.com/ppp/api/v1/getSessionToken.do');
define('SC_TEST_SESSION_TOKEN_URL', 'https://ppp-test.safecharge.com/ppp/api/v1/getSessionToken.do');

// get merchant payment methods URLs for REST API
define('SC_LIVE_REST_PAYMENT_METHODS_URL', 'https://secure.safecharge.com/ppp/api/v1/getMerchantPaymentMethods.do');
define('SC_TEST_REST_PAYMENT_METHODS_URL', 'https://ppp-test.safecharge.com/ppp/api/v1/getMerchantPaymentMethods.do');

// refund REST URLs
define('SC_LIVE_REFUND_URL', 'https://secure.safecharge.com/ppp/api/v1/refundTransaction.do');
define('SC_TEST_REFUND_URL', 'https://ppp-test.safecharge.com/ppp/api/v1/refundTransaction.do');

// payment URLs
define('SC_LIVE_PAYMENT_URL', 'https://secure.safecharge.com/ppp/api/v1/paymentAPM.do');
define('SC_TEST_PAYMENT_URL', 'https://ppp-test.safecharge.com/ppp/api/v1/paymentAPM.do');

// user CPanel URLs
define('SC_LIVE_CPANEL_URL', 'cpanel.safecharge.com');
define('SC_TEST_CPANEL_URL', 'sandbox.safecharge.com');

// Cashier payments URLs
define('SC_LIVE_CASHIER_URL', 'https://secure.safecharge.com/ppp/purchase.do');
define('SC_TEST_CASHIER_URL', 'https://ppp-test.safecharge.com/ppp/purchase.do');

# OPTIONAL CONSTANTS FOR WC

// some keys for order metadata, we make them hiden when starts with underscore
define('SC_AUTH_CODE_KEY', '_authCode');
define('SC_GW_TRANS_ID_KEY', '_relatedTransactionId');
define('SC_LOG_FILE_PATH', dirname( __FILE__ ). DIRECTORY_SEPARATOR . 'logs'. DIRECTORY_SEPARATOR. date("Y-m-d"). '.txt');

// common notify URL for the WC plugin, set parameter by the api we use
 if(
    (isset($_SERVER["HTTPS"]) && !empty($_SERVER["HTTPS"]) && strtolower ($_SERVER['HTTPS']) != 'off')
    || (isset($_SERVER["SERVER_PROTOCOL"]) && strpos($_SERVER["SERVER_PROTOCOL"], 'HTTPS/') == 0)
) {
    define('SC_NOTIFY_URL', 'https://'. $_SERVER['HTTP_HOST'] . '/?wc-api=');
}
elseif(isset($_SERVER["SERVER_PROTOCOL"]) && strpos($_SERVER["SERVER_PROTOCOL"], 'HTTP/') == 0) {
    define('SC_NOTIFY_URL', 'http://'. $_SERVER['HTTP_HOST'] . '/?wc-api=');
}

if(defined('WP_DEBUG') && WP_DEBUG === true) {
    define('SC_DEBUG', true);
}
else {
    define('SC_DEBUG', false);
}