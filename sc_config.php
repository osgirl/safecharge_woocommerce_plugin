<?php

/* 
 * Put all Constants here
 */

define('SC_GATEWAY_TITLE', 'SafeCharge');

// common notify URL for the plugin
define('SC_NOTIFY_URL', $_SERVER['HTTP_HOST'] . '/?wc-api=WC_Gateway_SC');

// some keys for order metadata, we make them hiden when starts with underscore
define('SC_AUTH_CODE_KEY', '_authCode');
define('SC_GW_TRANS_ID_KEY', '_relatedTransactionId');
define('SC_LOG_FILE_PATH', dirname( __FILE__ ). DIRECTORY_SEPARATOR . 'logs'. DIRECTORY_SEPARATOR. date("Y-m-d"). '.txt');

// URLs for session token
define('SC_LIVE_SESSION_TOKEN_URL', 'https://secure.safecharge.com/ppp/api/v1/getSessionToken.do');
define('SC_TEST_SESSION_TOKEN_URL', 'https://ppp-test.safecharge.com/ppp/api/v1/getSessionToken.do');

// payment methods URL for REST API
define('SC_LIVE_REST_PAYMENT_METHODS_URL', 'https://secure.safecharge.com/ppp/api/v1/getMerchantPaymentMethods.do');
define('SC_TEST_REST_PAYMENT_METHODS_URL', 'https://ppp-test.safecharge.com/ppp/api/v1/getMerchantPaymentMethods.do');