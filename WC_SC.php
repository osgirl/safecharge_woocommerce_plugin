<?php

/**
 * WC_SC Class
 * 
 * Main class for the SafeCharge Plugin
 * 
 * 2018
 * 
 * @author SafeCharge
 */

if (!session_id()) {
    session_start();
}

class WC_SC extends WC_Payment_Gateway
{
    # payments URL
    private $URL = '';
    
    public function __construct()
    {
        require_once 'SC_Versions_Resolver.php';
        
        $plugin_dir = basename(dirname(__FILE__));
        $this->plugin_path = plugin_dir_path( __FILE__ ) . $plugin_dir . '/';
        $this->plugin_url = get_site_url() . '/wp-content/plugins/'.$plugin_dir.'/';
        
        # settings to get/save options
		$this->id                   = 'sc';
		$this->method_title         = SC_GATEWAY_TITLE;
		$this->method_description   = 'Pay with '. SC_GATEWAY_TITLE .'.';
        $this->icon                 = $this->plugin_url."icons/safecharge.png";
		$this->has_fields           = false;

		$this->init_form_fields();
		$this->init_settings();
        
		$this->title            = @$this->settings['title'] ? $this->settings['title'] : '';
		$this->description      = @$this->settings['description'] ? $this->settings['description'] : '';
		$this->merchantId       = @$this->settings['merchantId'] ? $this->settings['merchantId'] : '';
		$this->merchantSiteId   = @$this->settings['merchantSiteId'] ? $this->settings['merchantSiteId'] : '';
        $this->secret           = @$this->settings['secret'] ? $this->settings['secret'] : '';
		$this->test             = @$this->settings['test'] ? $this->settings['test'] : 'yes';
		$this->use_http         = @$this->settings['use_http'] ? $this->settings['use_http'] : 'yes';
		$this->show_thanks_msg  = @$this->settings['show_thanks_msg'] ? $this->settings['show_thanks_msg'] : 'no';
        $this->save_logs        = @$this->settings['save_logs'] ? $this->settings['save_logs'] : 'yes';
        $this->hash_type        = @$this->settings['hash_type'] ? $this->settings['hash_type'] : 'sha256';
		$this->payment_api      = @$this->settings['payment_api'] ? $this->settings['payment_api'] : 'cashier';
		$this->transaction_type = @$this->settings['transaction_type'] ? $this->settings['transaction_type'] : 'a&s';
		$this->rewrite_dmn      = @$this->settings['rewrite_dmn'] ? $this->settings['rewrite_dmn'] : 'no';
        
        # set session variables for REST API, according REST variables names
        $_SESSION['SC_Variables']['merchantId']         = $this->merchantId;
        $_SESSION['SC_Variables']['merchantSiteId']     = $this->merchantSiteId;
        $_SESSION['SC_Variables']['currencyCode']       = get_woocommerce_currency();
        $_SESSION['SC_Variables']['languageCode']       = $this->formatLocation(get_locale());
        $_SESSION['SC_Variables']['payment_api']        = $this->payment_api;
        $_SESSION['SC_Variables']['transactionType']    = $this->transaction_type;
        $_SESSION['SC_Variables']['test']               = $this->test;
        $_SESSION['SC_Variables']['save_logs']          = $this->save_logs;
        $_SESSION['SC_Variables']['rewrite_dmn']        = $this->rewrite_dmn;
        
        $_SESSION['SC_Variables']['sc_country'] = SC_Versions_Resolver::get_client_country(new WC_Customer);
        if(isset($_POST["billing_country"]) && !empty($_POST["billing_country"])) {
            $_SESSION['SC_Variables']['sc_country'] = $_POST["billing_country"];
        }
        
        # Client Request ID 1 and Checksum 1 for Session Token 1
        // client request id 1
        $time = date('YmdHis', time());
        $_SESSION['SC_Variables']['cri1'] = $time. '_' .uniqid();
        
        // checksum 1 - checksum for session token
        $_SESSION['SC_Variables']['cs1'] = hash(
            $this->hash_type,
            $this->merchantId . $this->merchantSiteId
                . $_SESSION['SC_Variables']['cri1'] . $time . $this->secret
        );
        # Client Request ID 1 and Checksum 1 END
        
        # Client Request ID 2 and Checksum 2 to get AMPs
        // client request id 2
        $time = date('YmdHis', time());
        $_SESSION['SC_Variables']['cri2'] = $time. '_' .uniqid();
        
        // checksum 2 - checksum for get apms
        $time = date('YmdHis', time());
        $_SESSION['SC_Variables']['cs2'] = hash(
            $this->hash_type,
            $this->merchantId . $this->merchantSiteId
                . $_SESSION['SC_Variables']['cri2'] . $time . $this->secret
        );
        # set session variables for future use END
        
		$this->msg['message'] = "";
		$this->msg['class'] = "";

        SC_Versions_Resolver::process_admin_options($this);
		add_action('woocommerce_checkout_process', array($this, 'sc_checkout_process'));
		add_action('woocommerce_receipt_'.$this->id, array($this, 'receipt_page'));
		add_action('woocommerce_api_wc_gateway_sc', array($this, 'process_sc_notification'));
		add_action('woocommerce_api_wc_sc_rest', array($this, 'process_sc_notification'));
	}

    /**
     * Function init_form_fields
     * Set all fields for admin settings page.
     */
	public function init_form_fields()
    {
       $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'sc'),
                'type' => 'checkbox',
                'label' => __('Enable '. SC_GATEWAY_TITLE .' Payment Module.', 'sc'),
                'default' => 'no'
            ),
           'title' => array(
                'title' => __('Title:', 'sc'),
                'type'=> 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'sc'),
                'default' => __(SC_GATEWAY_TITLE, 'sc')
            ),
            'description' => array(
                'title' => __('Description:', 'sc'),
                'type' => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'sc'),
                'default' => __('Pay securely by Credit or Debit card or local payment option through '
                    .SC_GATEWAY_TITLE .' secured payment page.', 'sc')
            ),
            'merchantId' => array(
                'title' => __('Merchant ID', 'sc'),
                'type' => 'text',
                'description' => __('Merchant ID is provided by '. SC_GATEWAY_TITLE .'.')
            ),
            'merchantSiteId' => array(
                'title' => __('Merchant Site ID', 'sc'),
                'type' => 'text',
                'description' => __('Merchant Site ID is provided by '. SC_GATEWAY_TITLE .'.')
            ),
            'secret' => array(
                'title' => __('Secret key', 'sc'),
                'type' => 'text',
                'description' =>  __('Secret key is provided by '. SC_GATEWAY_TITLE, 'sc'),
            ),
            'hash_type' => array(
                'title' => __('Hash type', 'sc'),
                'type' => 'select',
                'description' => __('Choose Hash type provided by '. SC_GATEWAY_TITLE, 'sc'),
                'options' => array(
                    'sha256' => 'sha256',
                    'md5' => 'md5',
                )
            ),
            'payment_api' => array(
                'title' => __('Payment API', 'sc'),
                'type' => 'select',
                'description' => __('Select '. SC_GATEWAY_TITLE .' payment API', 'sc'),
                'options' => array(
                    'cashier' => 'Cashier',
                    'rest' => 'REST API',
                )
            ),
            'transaction_type' => array(
                'title' => __('Transaction Type', 'sc'),
                'type' => 'select',
                'description' => __('Select preferred Transaction Type.', 'sc'),
                'options' => array(
                    'a&s' => 'Auth and Settle',
                    'sale' => 'Sale',
                )
            ),
            'test' => array(
                'title' => __('Test mode', 'sc'),
                'type' => 'checkbox',
                'label' => __('Enable test mode', 'sc'),
                'default' => 'no'
            ),
            'use_http' => array(
                'title' => __('Use HTTP', 'sc'),
                'type' => 'checkbox',
                'label' => __('Force protocol where receive DMNs to be HTTP. You must have valid certificate for HTTPS! In case the checkbox is not set the default Protocol will be used.', 'sc'),
                'default' => 'no'
            ),
            'rewrite_dmn' => array(
                'title' => __('Rewrite DMN', 'sc'),
                'type' => 'checkbox',
                'label' => __('Check this option ONLY when URL symbols like "+", " " and "%20" in the DMN cause error 404 - Page not found.', 'sc'),
                'default' => 'no'
            ),
            'show_thanks_msg' => array(
                'title' => __('Show "Loading message"', 'sc'),
                'type' => 'checkbox',
                'label' => __('Show "Loading message" when redirect to secure Cashier. Does not work on the REST API.', 'sc'),
                'default' => 'no'
            ),
            'save_logs' => array(
                'title' => __('Save logs', 'sc'),
                'type' => 'checkbox',
                'label' => __('Create and save daily log files. This can help for debugging and catching bugs.', 'sc'),
                'default' => 'yes'
            ),
        );
    }

    public function admin_options()
    {
        // Generate the HTML For the settings form.
        echo
            '<h3>'.__(SC_GATEWAY_TITLE .' ', 'sc').'</h3>'
            .'<p>'.__('SC payment option').'</p>'
            .'<table class="form-table">';
                $this->generate_settings_html();
        echo '</table>';
    }

	/**
     * Function payment_fields
     * 
     *  Add fields on the payment page. Because we get APMs with Ajax
     * here we add only AMPs fields modal.
     **/
    public function payment_fields()
    {
		if($this->description) {
            echo wpautop(wptexturize($this->description));
        }
        
        // echo here some html part if needed
    }

	/**
     * Receipt Page
     **/
    public function receipt_page($order_id)
    {
       $this->generate_sc_form($order_id);
    }

	 /**
      * Function generate_sc_form
      * 
      * The function generates form form the order fields and prepare to send
      * them to the SC PPP.
      * We can send this data to the cashier generating pay button link and form,
      * or to the REST API as curl post.
      * 
      * @param int $order_id
     **/
    public function generate_sc_form($order_id)
    {
        global $woocommerce;
        
		$TimeStamp = date('Ymdhis');
        $order = new WC_Order($order_id);
        $order_status = strtolower($order->get_status());
        
        $cust_fields = $order->get_meta_data();
        $cust_fields2 = get_post_meta($order_id, 'payment_method_sc', true);
        
    //    $this->create_log($order, "the order data: ");
        
        $order->add_order_note("User is redicted to ".SC_GATEWAY_TITLE." Payment page.");
        $order->save();
        
		$items = $order->get_items();
		$i = 1;
		
        $this->set_environment();
        
        $notify_url = $this->set_notify_url();
        
        $params['site_url'] = get_site_url();
        // easy way to pass them to REST API
        $params['items'] = array();
		
        foreach ( $items as $item ) {
			$params['item_name_'.$i]        = $item['name'];
			$params['item_number_'.$i]      = $item['product_id'];
            $params['item_quantity_'.$i]    = $item['qty'];
            
            // $item['line_total']  = price - discount
        //    $amount                         = number_format($item['line_total'] / (int) $item['qty'], 2, '.', '');
            // this is the real price
            $item_price                     = number_format($item['line_subtotal'] / (int) $item['qty'], 2, '.', '');
			$params['item_amount_'.$i]      = $item_price;
            
            // set product img url
            $prod_img_path = '';
            $prod_img_data = wp_get_attachment_image_src(get_post_thumbnail_id($item['product_id']));
            if(
                $prod_img_data
                && is_array($prod_img_data)
                && !empty($prod_img_data)
                && isset($prod_img_data[0])
                && $prod_img_data[0] != ''
            ) {
                $prod_img_path = str_replace($params['site_url'], '', $prod_img_data[0]);
            }
        //    $params['item_image_'.$i] = $prod_img_path;
            // set product img url END
			
            // Use this ONLY when the merchant is not using open amount and when there is an items table on their theme.
            //$params['item_discount_'.$i]    = number_format(($item_price - $amount), 2, '.', '');
            
            $params['items'][] = array(
                'name' => $item['name'],
                'price' => $item_price,
                'quantity' => $item['qty'],
            );
            
            $i++;
		}
        
        $params['numberofitems'] = $i-1;
        
        $params['handling'] = SC_Versions_Resolver::get_shipping($order);
        $params['discount'] = number_format($order->get_discount_total(), 2, '.', '');
        
		if ($params['handling'] < 0) {
			$params['discount'] += abs($params['handling']); 
		}
        
        // we are not sure can woocommerce support more than one tax.
        // if it can, may be sum them is not the correct aproch, this must be tested
        $total_tax_prec = 0;
        $taxes = WC_Tax::get_rates();
        foreach($taxes as $data) {
            $total_tax_prec += $data['rate'];
        }
        
        $params['total_tax'] = number_format($total_tax_prec, 2, '.', '');
		$params['merchant_id'] = $this->merchantId;
		$params['merchant_site_id'] = $this->merchantSiteId;
		$params['time_stamp'] = $TimeStamp;
		$params['encoding'] ='utf8';
		$params['version'] = '4.0.0';

        $payment_page = SC_Versions_Resolver::get_page_id($order, 'pay');
        
		if ( get_option( 'woocommerce_force_ssl_checkout' ) == 'yes' ) {
            $payment_page = str_replace( 'http:', 'https:', $payment_page );
        }
		
        $params['success_url']          = $this->get_return_url();
		$params['pending_url']          = $this->get_return_url();
		$params['error_url']            = $this->get_return_url();
		$params['back_url']             = $payment_page;
		$params['notify_url']           = $notify_url . 'WC_Gateway_SC';
		$params['invoice_id']           = $order_id.'_'.$TimeStamp;
		$params['merchant_unique_id']   = $order_id.'_'.$TimeStamp;
        
        // get and pass to cashier billing data
		$params['first_name'] =
            urlencode(preg_replace("/[[:punct:]]/", '', SC_Versions_Resolver::get_order_data($order, 'billing_first_name')));
		$params['last_name'] =
            urlencode(preg_replace("/[[:punct:]]/", '', SC_Versions_Resolver::get_order_data($order, 'billing_last_name')));
		$params['address1'] =
            urlencode(preg_replace("/[[:punct:]]/", '', SC_Versions_Resolver::get_order_data($order, 'billing_address_1')));
		$params['address2'] =
            urlencode(preg_replace("/[[:punct:]]/", '', SC_Versions_Resolver::get_order_data($order, 'billing_address_2')));
		$params['zip'] =
            urlencode(preg_replace("/[[:punct:]]/", '', SC_Versions_Resolver::get_order_data($order, 'billing_zip')));
		$params['city'] =
            urlencode(preg_replace("/[[:punct:]]/", '', SC_Versions_Resolver::get_order_data($order, 'billing_city')));
		$params['state'] =
            urlencode(preg_replace("/[[:punct:]]/", '', SC_Versions_Resolver::get_order_data($order, 'billing_state')));
		$params['country'] =
            urlencode(preg_replace("/[[:punct:]]/", '', SC_Versions_Resolver::get_order_data($order, 'billing_country')));
		$params['phone1'] =
            urlencode(preg_replace("/[[:punct:]]/", '', SC_Versions_Resolver::get_order_data($order, 'billing_phone')));
		
        $params['email']            = SC_Versions_Resolver::get_order_data($order, 'billing_email');
        $params['user_token_id']    = SC_Versions_Resolver::get_order_data($order, 'billing_email');
        // get and pass to cashier billing data END
        
        // get and pass to cashier hipping data
        $sh_f_name = urlencode(
            preg_replace(
                "/[[:punct:]]/",
                '',
                SC_Versions_Resolver::get_order_data($order, 'shipping_first_name')
            )
        );
        
        if(empty(trim($sh_f_name))) {
            $sh_f_name = $params['first_name'];
        }
        $params['shippingFirstName'] = $sh_f_name;
        
        $sh_l_name = urlencode(preg_replace("/[[:punct:]]/", '', SC_Versions_Resolver::get_order_data($order, 'shipping_last_name')));
        if(empty(trim($sh_l_name))) {
            $sh_l_name = $params['last_name'];
        }
        $params['shippingLastName'] = $sh_l_name;
        
        $sh_addr = urlencode(preg_replace("/[[:punct:]]/", '', SC_Versions_Resolver::get_order_data($order, 'shipping_address_1')));
        if(empty(trim($sh_addr))) {
            $sh_addr = $params['address1'];
        }
        $params['shippingAddress'] = $sh_addr;
        
        $sh_city = urlencode(preg_replace("/[[:punct:]]/", '', SC_Versions_Resolver::get_order_data($order, 'shipping_city')));
        if(empty(trim($sh_city))) {
            $sh_city = $params['city'];
        }
        $params['shippingCity'] = $sh_city;
        
        $sh_country = urlencode(preg_replace("/[[:punct:]]/", '', SC_Versions_Resolver::get_order_data($order, 'shipping_country')));
        if(empty(trim($sh_country))) {
            $sh_city = $params['country'];
        }
        $params['shippingCountry'] = $sh_country;
        
        $sh_zip = urlencode(preg_replace("/[[:punct:]]/", '', SC_Versions_Resolver::get_order_data($order, 'shipping_postcode')));
        if(empty(trim($sh_zip))) {
            $sh_zip = $params['zip'];
        }
        $params['shippingZip'] = $sh_zip;
        // get and pass to cashier hipping data END
        
        $params['user_token'] = "auto";
        
        $params['payment_method'] = '';
        if(isset($_SESSION['sc_subpayment']) && $_SESSION['sc_subpayment'] != '') {
            $params['payment_method'] = str_replace($this->id.'_', '', $_SESSION['sc_subpayment']);
        }
		
        $params['merchantLocale']   = $this->formatLocation(get_locale());
        $params['total_amount']     = SC_Versions_Resolver::get_order_data($order, 'order_total');
        $params['currency']         = get_woocommerce_currency();
        
        $for_hash = '';
		foreach($params as $k => $v) {
            if(!is_array($v)) {
                $for_hash .= $v;
            }
		}
        
        # Cashier payment
        if($this->payment_api == 'cashier') {
            $params['checksum'] = hash($this->hash_type, stripslashes($this->secret . $for_hash));
            
            $params_array = array();
            foreach($params as $key => $value) {
                if(!is_array($value)) {
                    $params_array[] = "<input type='hidden' name='$key' value='$value'/>";
                }
            }

            $this->create_log($this->URL, 'Endpoint URL: ');
            $this->create_log($for_hash, '$for_hash: ');
            $this->create_log($this->hash_type, '$this->hash_type: ');
            $this->create_log($params, 'Order params');

            $html =
                '<form action="'.$this->URL.'" method="post" id="sc_payment_form">'
                    .implode('', $params_array)
                    .'<noscript>'
                        .'<input type="submit" class="button-alt" id="submit_sc_payment_form" value="'.__('Pay via '. SC_GATEWAY_TITLE, 'sc').'" /><a class="button cancel" href="'.$order->get_cancel_order_url().'">'.__('Cancel order &amp; restore cart', 'sc').'</a>'
                    .'</noscript>'
                    .'<script type="text/javascript">'
                        .'jQuery(function(){';

            if(isset($this->show_thanks_msg) && $this->show_thanks_msg == 'yes') {
                $html .=
                            'jQuery("body").block({'
                                .'message: \'<img src="'.$this->plugin_url.'icons/loading.gif" alt="Redirecting!" style="width:100px; float:left; margin-right: 10px;" />'.__('Thank you for your order. We are now redirecting you to '. SC_GATEWAY_TITLE .' Payment Gateway to make payment.', 'sc').'\','
                                .'overlayCSS: {background: "#fff", opacity: 0.6},'
                                .'css: {'
                                    .'padding: 20,'
                                    .'textAlign: "center",'
                                    .'color: "#555",'
                                    .'border: "3px solid #aaa",'
                                    .'backgroundColor: "#fff",'
                                    .'cursor: "wait",'
                                    .'lineHeight: "32px"'
                                .'}'
                            .'});';
            }

            $html .=
                            'jQuery("#sc_payment_form").submit();'
                        .'});'
                    .'</script>'
                .'</form>';

            echo $html;
        }
        # REST API payment
        elseif($this->payment_api == 'rest') {
            // map here variables names different for Cashier and REST
            $params['merchantId'] = $this->merchantId;
            $params['merchantSiteId'] = $this->merchantSiteId;
            // map here variables names different for Cashier and REST END
            
            $params['client_request_id'] = $TimeStamp .'_'. uniqid();
            
            $params['urlDetails'] = array(
                'successUrl'        => $this->get_return_url(),
                'failureUrl'        => $this->get_return_url(),
                'pendingUrl'        => $this->get_return_url(),
                'notificationUrl'   => $notify_url . 'WC_SC_Rest'
            );
                
            $params['checksum'] = hash($this->settings['hash_type'], stripslashes(
                $_SESSION['SC_Variables']['merchantId']
                .$_SESSION['SC_Variables']['merchantSiteId']
                .$params['client_request_id']
                .$params['total_amount']
                .$params['currency']
                .$TimeStamp
                .$this->secret
            ));
            
            require_once 'SC_REST_API.php';
            
            // set the payment method type
            $payment_method = 'apm';
            if(@$_SESSION['SC_Variables']['APM_data']['payment_method'] == 'cc_card') {
                $payment_method = 'd3d';
            }
            
            $this->create_log($params, 'params sent to REST: ');
            $this->create_log($_SESSION['SC_Variables'], 'SC_Variables: ');
            $this->create_log($payment_method, 'payment method: ');
            
            // ALWAYS CHECK USED PARAMS IN process_payment
            $resp = SC_REST_API::process_payment(
                $params
                ,$_SESSION['SC_Variables']
                ,$_REQUEST['order-pay']
                ,$payment_method
            );
            
            $this->create_log($resp, 'REST API response: ');
            
            if(!$resp) {
                if($order_status == 'pending') {
                    $order->set_status('failed');
                }
                
                $order->add_order_note(__('Payment API response is FALSE.', 'sc'));
                $order->save();
                
                $this->create_log($resp, 'REST API Payment ERROR: ');
                
                echo 
                    '<script>'
                        .'window.location.href = "'.$params['error_url'].'&status=failed";'
                    .'</script>';
                exit;
            }
            
            // in case of Payment 3D we get almost same array as sent one
            if(!isset($resp['status']) && isset($resp['checksum'], $resp['paResponse'])) {
                // put the response parameters in new session variable
                $_SESSION['SC_P3D_Params'] = $resp;
                
                // now redirect to the acsURL and wait for response DMN
                $html =
                    '<form action="'. $resp['acsUrl'] .'" method="post" id="sc_payment_form">'
                        .'<input type="hidden" name="PaReq" value="'. $resp['paResponse'] .'">'
                        .'<input type="hidden" name="TermUrl" value="'. $params['pending_url'] .'?wc-api=rest&action=p3d">'
                        .'<noscript>'
                            .'<input type="submit" class="button-alt" id="submit_sc_payment_form" value="'.__('Pay via '. SC_GATEWAY_TITLE, 'sc').'" /><a class="button cancel" href="'.$order->get_cancel_order_url().'">'.__('Cancel order &amp; restore cart', 'sc').'</a>'
                        .'</noscript>'
                        
                        .'<script type="text/javascript">'
                            .'jQuery(function(){'
                                .'jQuery("#sc_payment_form").submit();'
                            .'});'
                        .'</script>'
                    .'</form>';

                echo $html;
            }
            
            // If we get Transaction ID save it as meta-data
            if(isset($resp['transactionId']) && $resp['transactionId']) {
                $order->update_meta_data(SC_GW_TRANS_ID_KEY, $resp['transactionId'], 0);
            }
            
            if(@$resp['status'] == 'ERROR' || @$resp['transactionStatus'] == 'ERROR') {
                if($order_status == 'pending') {
                    $order->set_status('failed');
                }
                
                $error_txt = 'Payment error';
                
                if(@$resp['reason'] != '') {
                    $error_txt = ': '.$resp['reason'].'.';
                }
                elseif(@$resp['transactionStatus'] != '') {
                    $error_txt = ': '.$resp['transactionStatus'].'.';
                }
                
                $order->add_order_note($error_txt);
                $order->save();
                
                $this->create_log($resp['errCode'].': '.$resp['reason'], 'REST API Payment ERROR: ');
                
                echo 
                    '<script>'
                        .'window.location.href = "'.$params['error_url'].'&status=failed";'
                    .'</script>';
                exit;
            }
            
            // pay with redirect URL
            if(@$resp['status'] == 'SUCCESS') {
                
                
                
                // in case we have redirectURL
                if(isset($resp['redirectURL']) && !empty($resp['redirectURL'])) {
                    echo 
                        '<script>'
                            .'var newTab = window.open("'.$resp['redirectURL'].'", "_blank");'
                            .'newTab.focus();'
                            .'window.location.href = "'.$params['success_url'].'&status=waiting";'
                        .'</script>';
                    
                    exit;
                }
            }
            
            $order_status = strtolower($order->get_status());
            if($order_status == 'pending') {
                $order->set_status('completed');
            }
            
            if(@$resp['transactionId']) {
                $order->add_order_note(__('Payment succsess for Transaction Id ', 'sc') . $resp['transactionId']);
            }
            else {
                $order->add_order_note(__('Payment succsess.'));
            }
            $order->save();
            
            echo 
                '<script>'
                    .'window.location.href = "'
                        .$params['error_url'].'&status=success";'
                .'</script>';
            
            exit;
        }
        # ERROR - not existing payment api
        else {
            $this->create_log('the payment api is set to: '.$this->payment_api, 'Payment form ERROR: ');
            
            echo 
                '<script>'
                    .'window.location.href = "'
                        .$params['error_url'].'&status=failed&invoice_id='
                        .$order_id.'&wc-api=WC_SC_Rest&reason=not existing payment API'
                .'</script>';
            exit;
        }
    }
    
	 /**
      * Function process_payment
      * Process the payment and return the result. This is the place where site
      * POST the form and then redirect. Here we will get our custom fields.
      * 
      * @param int $order_id
     **/
    function process_payment($order_id)
    {
        // get AMP fields and add them to the session for future use
        if(isset($_POST, $_POST['payment_method_sc']) && !empty($_POST['payment_method_sc'])) {
            $_SESSION['SC_Variables']['APM_data']['payment_method'] = $_POST['payment_method_sc'];
            
            if(
                isset($_POST[$_POST['payment_method_sc']])
                && !empty($_POST[$_POST['payment_method_sc']]) 
                && is_array($_POST[$_POST['payment_method_sc']])
            ) {
                $_SESSION['SC_Variables']['APM_data']['apm_fields'] = $_POST[$_POST['payment_method_sc']];
            }
        }
        
        // lst parameter is passed from the form. It is the session token used for
        // card tokenization. We MUST use the same token for D3D Payment
        if(isset($_POST, $_POST['lst']) && !empty($_POST['lst'])) {
            $_SESSION['SC_Variables']['lst'] = $_POST['lst'];
        }
        
        $order = new WC_Order($order_id);
       
        return array(
            'result' 	=> 'success',
            'redirect'	=> SC_Versions_Resolver::get_redirect_url($order),
        );
    }

	function sc_checkout_process()
    {
        $_SESSION['sc_subpayment'] = '';
        if(isset($_POST['payment_method_sc'])) {
            $_SESSION['sc_subpayment'] = $_POST['payment_method_sc'];
        }
        
		return true;
	}

    /**
     * Check for valid callback
     **/
    public function process_sc_notification()
    {
        $this->create_log($_REQUEST, 'call process_sc_notification()');
        
		try {
            // Cashier DMN
            if(@$_REQUEST['invoice_id'] !== '') {
                $arr = explode("_",$_REQUEST['invoice_id']);
                $order_id  = $arr[0];
                $order = new WC_Order($order_id);

                if ($order){
                    $verified = false;
                    // hash validation
                    if ($this->secret) {
                        $hash = hash(
                            $this->hash_type,
                            $this->secret . $_REQUEST['ppp_status'] . $_REQUEST['PPP_TransactionID']
                        );
                        
                        if ($hash == $_REQUEST['responsechecksum']) {
                            $verified = true;
                        }
                    }
                }

                if ($verified) {
                    $status = $_REQUEST['Status'];
                    $transactionType = $_REQUEST['transactionType'];

                    echo "Transaction Type: ".$transactionType;

                    if ( ($transactionType=='Void') || ($transactionType=='Chargeback') || ($transactionType=='Credit') ){
                        $status = 'CANCELED';
                    }
                    
                    $this->change_order_status($order, $order_id, $status, $transactionType);
                }
                else {
                    $this->msg['class'] = 'error';
                    $this->msg['message'] = "Security Error. Checksum validation faild.";
                    $order->update_status('failed');
                    $order->add_order_note('Failed');
                    $order->add_order_note($this->msg['message']);
                }

                add_action('the_content', array(&$this, 'showMessage'));
            }
            // REST API DMN
            else {
                $order_id  = $_REQUEST['clientUniqueId'];
                $order = new WC_Order($order_id);
                
                $status = $_REQUEST['Status'];
                $transactionType = $_REQUEST['transactionType'];

                echo "Transaction Type: " . $transactionType;

                if (
                    $transactionType == 'Void'
                    || $transactionType == 'Chargeback'
                    || $transactionType == 'Credit'
                ) {
                    $status = 'CANCELED';
                }
                
                $this->change_order_status($order, $order_id, $status, $transactionType);
            }
		}
        catch(Exception $e){
            $this->create_log($e, 'Error in process_sc_notification(): ');
			$msg = "Error.";
		}
        
        // clear session variables for the order
        if(isset($_SESSION['SC_Variables'])) {
            unset($_SESSION['SC_Variables']);
        }
	}

	public function showMessage($content)
    {
        return '<div class="box '.$this->msg['class'].'-box">'.$this->msg['message'].'</div>'.$content;
    }

	public function checkAdvancedCheckSum()
    {
        if (isset($_GET['advanceResponseChecksum'])) {
            $str = hash(
                $this->hash_type,
                $this->secret.$_GET['totalAmount'].$_GET['currency']
                    .$_GET['responseTimeStamp'] . $_GET['PPP_TransactionID']
                    .$_GET['Status'] . $_GET['productId']
            );
                
            if ($str == $_GET['advanceResponseChecksum'])
                return true;
            else
                return false;
        }
        else
            return false;
	}
    
    public function set_notify_url()
    {
        $protocol = '';
        $url = $_SERVER['HTTP_HOST'] . '/?wc-api=';
        
        // force Notification URL protocol
        if(isset($this->use_http) && $this->use_http == 'yes') {
            $protocol = 'http://';
        }
        else {
            if(
                (isset($_SERVER["HTTPS"]) && !empty($_SERVER["HTTPS"]) && strtolower ($_SERVER['HTTPS']) != 'off')
                || (isset($_SERVER["SERVER_PROTOCOL"]) && strpos($_SERVER["SERVER_PROTOCOL"], 'HTTPS/') !== false)
            ) {
                $protocol = 'https://';
            }
            elseif(isset($_SERVER["SERVER_PROTOCOL"]) && strpos($_SERVER["SERVER_PROTOCOL"], 'HTTP/') !== false) {
                $protocol = 'http://';
            }
        }
        
        return $protocol . $url;
    }
    
    private function set_environment()
    {
		if ($this->test == 'yes'){
            $this->use_session_token_url    = SC_TEST_SESSION_TOKEN_URL;
            $this->use_merch_paym_meth_url  = SC_TEST_REST_PAYMENT_METHODS_URL;
            
            // set payment URL
            if($this->payment_api == 'cashier') {
                $this->URL = SC_TEST_CASHIER_URL;
            }
            elseif($this->payment_api == 'rest') {
                $this->URL = SC_TEST_PAYMENT_URL;
            }
		}
        else {
            $this->use_session_token_url    = SC_LIVE_SESSION_TOKEN_URL;
            $this->use_merch_paym_meth_url  = SC_LIVE_REST_PAYMENT_METHODS_URL;
            
            // set payment URL
            if($this->payment_api == 'cashier') {
                $this->URL = SC_LIVE_CASHIER_URL;
            }
            elseif($this->payment_api == 'rest') {
                $this->URL = SC_LIVE_PAYMENT_URL;
            }
		}
	}
    
    private function formatLocation($locale)
    {
		switch ($locale){
            case 'de_DE':
				return 'de';
                
            case 'en_GB':
            default:
                return 'en';
		}
	}
    
    /**
     * Function change_order_status
     * Change order status of the order.
     * 
     * @param object $order
     * @param int $order_id
     * @param string $status
     * @param string $transactionType
     */
    private function change_order_status($order, $order_id, $status, $transactionType)
    {
        create_log($status . ' - ' . $order_id, 'WC_SC change_order_status() status-order: ');
        
        global $woocommerce;
        
        switch($status) {
            case 'CANCELED':
                $message = 'Payment status changed to:' .$transactionType
                    .'. PPP_TransactionID = '. @$_REQUEST['PPP_TransactionID']
                    .", Status = " .$status. ', GW_TransactionID = '
                    .@$_REQUEST['TransactionID'];
                
                $this->msg['message'] = $message;
                $this->msg['class'] = 'woocommerce_message';
                $order->update_status('failed');
                $order->add_order_note('Failed');
                $order->add_order_note($this->msg['message']);
            break;

            case 'APPROVED':
                $message ='The amount has been authorized and captured by '
                    .SC_GATEWAY_TITLE .'. PPP_TransactionID = '
                    .@$_REQUEST['PPP_TransactionID'] .", Status = ". $status
                    .", TransactionType = ". $transactionType
                    .', GW_TransactionID = '. @$_REQUEST['TransactionID'];
                
                $this->msg['message'] = $message;
                $this->msg['class'] = 'woocommerce_message';
                $order->payment_complete($order_id);

                $order->update_status( 'completed' );
                $order->add_order_note(SC_GATEWAY_TITLE .' payment is successful<br/>Unique Id: '
                    .@$_REQUEST['PPP_TransactionID']);
                
                $order->add_order_note($this->msg['message']);
                $woocommerce->cart->empty_cart();
            break;

            case 'ERROR':
            case 'DECLINED':
                $message ='Payment failed. PPP_TransactionID = '. @$_REQUEST['PPP_TransactionID']
                    .", Status = ". $status .", Error code = ". @$_REQUEST['ErrCode']
                    .", Message = ". @$_REQUEST['message'] .", TransactionType = "
                    .$transactionType .', GW_TransactionID = '. $_REQUEST['TransactionID'];
                
                $this->msg['message'] = $message;
                $this->msg['class'] = 'woocommerce_message';
                $order->update_status('failed');
                $order->add_order_note('Failed');
                $order->add_order_note($this->msg['message']);
            break;

            case 'PENDING':
                $ord_status = $order->get_status();
                if ($ord_status == 'processing' || $ord_status == 'completed') {
                    break;
                }
                
                $message ='Payment is still pending, PPP_TransactionID '
                    .@$_REQUEST['PPP_TransactionID'] .", Status = ". $status
                    .", TransactionType = ". $transactionType
                    .', GW_TransactionID = '. @$_REQUEST['TransactionID'];
                
                $this->msg['message'] = $message;
                $this->msg['class'] = 'woocommerce_message woocommerce_message_info';
                $order->add_order_note(SC_GATEWAY_TITLE .' payment status is pending<br/>Unique Id: '
                    .@$_REQUEST['PPP_TransactionID']);
                
                $order->add_order_note($this->msg['message']);
                $order->update_status('on-hold');
                $woocommerce->cart->empty_cart();
            break;
        }
        
        $order->save();
        $this->save_update_order_numbers($order);
    }
    
    /**
     * Function save_update_order_numbers
     * Save or update order AuthCode and TransactionID on status change.
     * 
     * @param object $order
     */
    private function save_update_order_numbers($order)
    {
        // save or update AuthCode and GW Transaction ID
        $auth_code = isset($_REQUEST['AuthCode']) ? $_REQUEST['AuthCode'] : '';
        $saved_ac = $order->get_meta(SC_AUTH_CODE_KEY);

        if(!$saved_ac || empty($saved_ac) || $saved_ac !== $auth_code) {
            $order->update_meta_data(SC_AUTH_CODE_KEY, $auth_code);
        }

        $gw_transaction_id = isset($_REQUEST['TransactionID']) ? $_REQUEST['TransactionID'] : '';
        $saved_tr_id = $order->get_meta(SC_GW_TRANS_ID_KEY);

        if(!$saved_tr_id || empty($saved_tr_id) || $saved_tr_id !== $gw_transaction_id) {
            $order->update_meta_data(SC_GW_TRANS_ID_KEY, $gw_transaction_id, 0);
        }

        $order->save();
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
        if(
            !isset($this->save_logs)
            || $this->save_logs == 'no'
            || $this->save_logs === null
        ) {
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

?>
