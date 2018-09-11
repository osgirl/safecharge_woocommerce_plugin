<?php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

class WC_SC extends WC_Payment_Gateway
{
    public function __construct()
    {
        include_once(  plugin_dir_path( __FILE__ ) . 'sc_versions_resolver.php' );
        
        $plugin_dir = basename(dirname(__FILE__));
        $this -> plugin_path = plugin_dir_path( __FILE__ ) . $plugin_dir . '/';
        $this -> plugin_url = get_site_url() . '/wp-content/plugins/'.$plugin_dir.'/';
        
        # settings to get/save options
		$this -> id = 'sc';
		$this -> method_title = 'SafeCharge';
		$this -> method_description = 'Pay with SafeCharge.';
        $this -> icon = $this -> plugin_url."icons/g2s.png";
		$this -> has_fields = false;

		$this -> init_form_fields();
		$this -> init_settings();

		$this -> title = $this -> settings['title'];
		$this -> description = $this -> settings['description'];
		$this -> merchant_id = $this -> settings['merchant_id'];
		$this -> merchantsite_id = $this -> settings['merchantsite_id'];
        $this -> secret = $this -> settings['secret'];
		$this -> test = $this -> settings['test'];
		$this -> URL = $this -> settings['URL'];
		$this -> load_payment_options = $this -> settings['load_payment_options'];
        
		$_SESSION['merchant_id'] = $this -> merchant_id;
		$_SESSION['merchantsite_id'] = $this -> merchantsite_id;
		
		$this -> liveurl = 'https://secure.safecharge.com/ppp/purchase.do';
		$this -> testurl = 'https://ppp-test.safecharge.com/ppp/purchase.do';
		$this -> liveWSDL = 'https://secure.xtpayments.com/PaymentOptionInfoService?wsdl';
		$this -> testWSDL = 'https://ppp-test.safecharge.com/PaymentOptionInfoService?wsdl';
		
		$this -> msg['message'] = "";
		$this -> msg['class'] = "";

        SC_Versions_Resolver::process_admin_options($this);
		add_action('woocommerce_checkout_process', array($this, 'sc_checkout_process'));
		add_action('woocommerce_receipt_'.$this -> id, array($this, 'receipt_page'));
		add_action('woocommerce_api_wc_gateway_sc', array($this, 'process_sc_notification'));
	}

	function init_form_fields()
    {
       $this -> form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'sc'),
                'type' => 'checkbox',
                'label' => __('Enable SafeCharge Payment Module.', 'sc'),
                'default' => 'no'
            ),
           'title' => array(
                'title' => __('Title:', 'sc'),
                'type'=> 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'sc'),
                'default' => __('SafeCharge', 'sc')
            ),
            'description' => array(
                'title' => __('Description:', 'sc'),
                'type' => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'sc'),
                'default' => __('Pay securely by Credit or Debit card or local payment option through SafeCharge secured payment page.', 'sc')
            ),
            'merchant_id' => array(
                'title' => __('Merchant ID', 'sc'),
                'type' => 'text',
                'description' => __('Merchant ID is provided by SafeCharge.')
            ),
            'merchantsite_id' => array(
                'title' => __('Merchant Site ID', 'sc'),
                'type' => 'text',
                'description' => __('Merchant Site ID is provided by SafeCharge.')
            ),
            'secret' => array(
                'title' => __('Secret key', 'sc'),
                'type' => 'text',
                'description' =>  __('Secret key is provided by SafeCharge', 'sc'),
            ),
            'URL' => array(
                'title' => __('Payment URL', 'sc'),
                'type' => 'text',
                'description' =>  __('Url to the payment gateway', 'sc'),
                'default' => 'https://secure.safecharge.com/ppp/purchase.do'
            ),
            'test' => array(
                'title' => __('Test mode', 'sc'),
                'type' => 'checkbox',
                'label' => __('Enable test mode', 'sc'),
                'default' => 'no'
            ),
            'load_payment_options' => array(
                'title' => __('Load payment options', 'sc'),
                'type' => 'checkbox',
                'label' => __('All available payment options will be loaded dynamically', 'sc'),
                'default' => 'yes'
            )
        );
    }

    public function admin_options()
    {
        // Generate the HTML For the settings form.
        echo
            '<h3>'.__('SafeCharge ', 'g2s').'</h3>'
            .'<p>'.__('SC payment option').'</p>'
            .'<table class="form-table">';
                $this -> generate_settings_html();
        echo '</table>';
    }

	/**
     *  Add fields on the payment page
     **/
    function payment_fields()
    {
		if($this -> description)
            echo wpautop(wptexturize($this -> description));
		
        $apms = $this->getAPMS();
		
        if ($apms)
            echo $apms;
    }

    // in paymen page shows SafeCharge sub-option and available payment methods
	function showAPMs($apms)
    {
		$data='<br />';
		
        if(isset($apms["PaymentOptionsDetails"]["displayInfo"])) {
			$logo = $this -> plugin_url.'icons/'.utf8_encode($apms["PaymentOptionsDetails"]["optionName"]).'.png';
			$logo2 = $this->plugin_path.'icons/'.utf8_encode($apms["PaymentOptionsDetails"]["optionName"]).'.png';
			
            $data .=
                '<input id="payment_method_'.$this->id.'_'.$apms["PaymentOptionsDetails"]["optionName"].'" type="radio" class="input-radio" name="payment_method_sc" value="'.$this->id.'_'.$apms["PaymentOptionsDetails"]["optionName"].'"  />'
                .'<label for="payment_method_'.$this->id.'_'.$apms["PaymentOptionsDetails"]["optionName"].'">'
                    .utf8_encode($apms["PaymentOptionsDetails"]["displayInfo"]["paymentOptionDisplayName"]).' ';
			
            if (file_exists($logo2)) {
                $data .= '<img src="'.$this -> plugin_url.'icons/'.utf8_encode($apms["PaymentOptionsDetails"]["optionName"]).'.png" height="30px">';
            }
			
            $data .= '</label>';
		}
        else if(isset($apms["PaymentOptionsDetails"])) {
			foreach($apms["PaymentOptionsDetails"] as $apmDetails) {
				$logo = $this -> plugin_url.'icons/'.utf8_encode($apmDetails["optionName"]).'.png';
				$logo2 = $this->plugin_path.'icons/'.utf8_encode($apmDetails["optionName"]).'.png';
				
                $data .=
                    '<div style="paddin:10px 0px;">'
                        .'<input id="payment_method_'.$this->id.'_'.$apmDetails["optionName"].'" type="radio" class="input-radio" name="payment_method_sc" value="'.$this->id.'_'.$apmDetails["optionName"].'"  />'
                        .'<label for="payment_method_'.$this->id.'_'.$apmDetails["optionName"].'" >'
                            .utf8_encode($apmDetails["displayInfo"]["paymentOptionDisplayName"]).' ';
				
                if (file_exists($logo2)) {
                    $data .= '<img src="'.$logo.'" style="height:20px;">';
                }
				
                $data .= '</label>'
                    .'</div>';
			}
		}
        
		return $data;
	}

	/**
     * Receipt Page
     **/
    function receipt_page($order_id)
    {
    //   echo  $this -> generate_sc_form($order_id);
       $this -> generate_sc_form($order_id);
    }

	 /**
     * Generate pay button link
     **/
    public function generate_sc_form($order_id)
    {
        global $woocommerce;
        
		$TimeStamp = date('Ymdhis');
        $order = new WC_Order($order_id);
        
        $order->add_order_note("User is redicted to Safecharge Payment page");
        $order->save();
        
		$items = $order->get_items();
		$item_price=0;
		$i=1;
		
        $this->setEnvironment();
		
        foreach ( $items as $item ) {
			$params['item_name_'.$i]        = ($item['name']);
		//	$params['item_number_'.$i]      = $item["item_meta"]['_product_id'][0];
			$params['item_number_'.$i]      = $item['product_id'];
			$params['item_amount_'.$i]      = number_format($item['line_total']/(int)$item['qty'],2,'.', '');
			$params['item_quantity_'.$i]    = $item['qty'];
			
            $item_price += number_format(($item['line_total']),2,'.', '');
            $i++;
		}
        
        $params['numberofitems'] = $i-1;
        
		$item_price_total = number_format($item_price,2,'.', '');
		
        $params['handling'] = number_format(
            (SC_Versions_Resolver::get_order_data($order, 'order_total') - $item_price_total),
            2, '.', ''
        );
		
		if ($params['handling'] < 0) {
			$params['discount'] = abs($params['handling']); 
		}
		
		$params['merchant_id'] = $this -> merchant_id;
		$params['merchant_site_id'] = $this -> merchantsite_id;
		$params['time_stamp'] = $TimeStamp;
		$params['encoding'] ='utf8';
		$params['version'] = '4.0.0';

        $payment_page = SC_Versions_Resolver::get_page_id($order, 'pay');
        
		if ( get_option( 'woocommerce_force_ssl_checkout' ) == 'yes' )
            $payment_page = str_replace( 'http:', 'https:', $payment_page );
		
        // set different URLs for prod and debug env
//        if(defined('WP_DEBUG') && WP_DEBUG === true) {
//            $notify_url = 'http://hostmi.eu/home/resp_listener/';
//        }
//        else {
            $notify_url = add_query_arg(array('wc-api' => 'WC_Gateway_SC'), home_url('/'));
    //    }

        $params['success_url']          = $this->get_return_url();
		$params['pending_url']          = $this->get_return_url();
		$params['error_url']            = $this->get_return_url();
		$params['back_url']             = $payment_page;
		$params['notify_url']           = $notify_url;
		$params['invoice_id']           = $order_id.'_'.$TimeStamp;
		$params['merchant_unique_id']   = $order_id.'_'.$TimeStamp;
        
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
		
        $params['email']                = SC_Versions_Resolver::get_order_data($order, 'billing_email');
        $params['user_token']           = "auto";
        $params['user_token_id']        = SC_Versions_Resolver::get_order_data($order, 'billing_email');
		$params['payment_method']       = str_replace($this->id.'_','',$_SESSION['sc_subpayment']);
		$params['merchantLocale']       = $this->formatLocation(get_locale());
		$params['total_amount']         = SC_Versions_Resolver::get_order_data($order, 'order_total');
        $params['currency']             = get_woocommerce_currency();
        
		$for_hash = '';
        
		foreach($params as $k=>$v){
			$for_hash .= $v;
		}
        
        $params['checksum']             = md5( stripslashes($this->secret.$for_hash));

        $params_array = array();
        
        foreach($params as $key => $value){
            $params_array[] = "<input type='hidden' name='$key' value='$value'/>";
        }
        
        $this->create_log($params, 'Order params');
        
        echo
            '<form action="'.$this -> URL.'" method="post" id="sc_payment_form">'
                .implode('', $params_array)
                .'<noscript>'
                    .'<input type="submit" class="button-alt" id="submit_sc_payment_form" value="'.__('Pay via SafeCharge', 'sc').'" /><a class="button cancel" href="'.$order->get_cancel_order_url().'">'.__('Cancel order &amp; restore cart', 'sc').'</a>'
                .'</noscript>'
                .'<script type="text/javascript">'
                    .'jQuery(function(){'
                        .'jQuery("body").block({'
                            .'message: "<img src=\"'.$this->plugin_url.'/icons/loading.gif\" alt=\"Redirecting!\" style=\"width:100px;float:left; margin-right: 10px;\" />'.__('Thank you for your order. We are now redirecting you to SafeCharge Payment Gateway to make payment.', 'sc').'",'
                            .'overlayCSS: {background: "#fff", opacity: 0.6},'
                            .'css: {'
                                .'padding:        20,'
                                .'textAlign:      "center",'
                                .'color:          "#555",'
                                .'border:         "3px solid #aaa",'
                                .'backgroundColor:"#fff",'
                                .'cursor:         "wait",'
                                .'lineHeight:"32px"}'
                        .'});'
                        .'jQuery("#sc_payment_form").submit();'
                    .'});'
                .'</script>'
            .'</form>';
    }
    
	 /**
     * Process the payment and return the result
     **/
    function process_payment($order_id)
    {
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
    function process_sc_notification()
    {
        global $woocommerce;

		try {
			$arr = explode("_",$_REQUEST['invoice_id']);
			$order_id  = $arr[0];
			$order = new WC_Order($order_id);
			
            if ($order){
				$verified = false;
				// md5sig validation
				if ($this->secret) {
					$hash  =  $this->secret.$_REQUEST['ppp_status'].$_REQUEST['PPP_TransactionID'];
					$md5hash = md5($hash);
					$md5sig = $_REQUEST['responsechecksum'];
					if ($md5hash == $md5sig) {
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

				switch($status) {
					case 'CANCELED':
						$message = 'Payment status changed to:'.$transactionType.'. PPP_TransactionID = '.$_REQUEST['PPP_TransactionID'].", Status = ".$status.', GW_TransactionID = '.$this->request->get['TransactionID'];
						$this -> msg['message'] = $message;
                        $this -> msg['class'] = 'woocommerce_message';
						$order -> update_status('failed');
						$order -> add_order_note('Failed');
						$order -> add_order_note($this->msg['message']);
					break;

					case 'APPROVED':
						$message ='The amount has been authorized and captured by SafeCharge. PPP_TransactionID = '.$_REQUEST['PPP_TransactionID'].", Status = ".$status.", TransactionType = ".$transactionType.', GW_TransactionID = '.$_REQUEST['TransactionID'];
						$this -> msg['message'] = $message;
                        $this -> msg['class'] = 'woocommerce_message';
						$order -> payment_complete($order_id);
						
						$order->update_status( 'completed' );
						$order -> add_order_note('SafeCharge payment is successful<br/>Unique Id: '.$_REQUEST['PPP_TransactionID']);
						$order -> add_order_note($this->msg['message']);
						$woocommerce -> cart -> empty_cart();
					break;

					case 'ERROR':
					case 'DECLINED':
						$message ='Payment failed. PPP_TransactionID = '.$this->request->get['PPP_TransactionID'].", Status = ".$status.", Error code = ".$_REQUEST['ErrCode'].", Message = ".$this->request->get['message'].", TransactionType = ".$transactionType.', GW_TransactionID = '.$_REQUEST['TransactionID'];
						$this -> msg['message'] = $message;
                        $this -> msg['class'] = 'woocommerce_message';
						$order -> update_status('failed');
						$order -> add_order_note('Failed');
						$order -> add_order_note($this->msg['message']);
					break;

					case 'PENDING':
                        if ($order -> get_status() == 'processing') {
                          break;
                        }
                        else {
                            $message ='Payment is still pending '.$_REQUEST['PPP_TransactionID'].", Status = ".$status.", TransactionType = ".$transactionType.', GW_TransactionID = '.$_REQUEST['TransactionID'];
                            $this -> msg['message'] = $message;
                            $this -> msg['class'] = 'woocommerce_message woocommerce_message_info';
                            $order -> add_order_note('SafeCharge payment status is pending<br/>Unique Id: '.$_REQUEST['PPP_TransactionID']);
                            $order -> add_order_note($this->msg['message']);
                            $order -> update_status('on-hold');
                            $woocommerce -> cart -> empty_cart();
                        };
                    break;
				}

			}
            else{
				$this -> msg['class'] = 'error';
				$this -> msg['message'] = "Security Error. Illegal access detected";
				$order -> update_status('failed');
				$order -> add_order_note('Failed');
				$order -> add_order_note($this->msg['message']);
			}
            
			add_action('the_content', array(&$this, 'showMessage'));
		}
        catch(Exception $e){
			$msg = "Error";
		}
	}

	function showMessage($content)
    {
        return '<div class="box '.$this -> msg['class'].'-box">'.$this -> msg['message'].'</div>'.$content;
    }

     // get all pages
    function get_pages($title = false, $indent = true)
    {
        $wp_pages = get_pages('sort_column=menu_order');
        $page_list = array();
        
        if ($title)
            $page_list[] = $title;
        
        foreach ($wp_pages as $page) {
            $prefix = '';
            // show indented child pages?
            if ($indent) {
                $has_parent = $page->post_parent;
                while($has_parent) {
                    $prefix .=  ' - ';
                    $next_page = get_page($has_parent);
                    $has_parent = $next_page->post_parent;
                }
            }
            
            // add to page list array array
            $page_list[$page->ID] = $prefix . $page->post_title;
        }
        
        return $page_list;
    }

	function checkAdvancedCheckSum()
    {
        if (isset($_GET['advanceResponseChecksum'])){
            $str = md5($this->secret.$_GET['totalAmount'].$_GET['currency']
                .$_GET['responseTimeStamp'].$_GET['PPP_TransactionID'].$_GET['Status'].$_GET['productId']);

            if ($str == $_GET['advanceResponseChecksum'])
                return true;
            else
                return false;
        }
        else
            return false;
	}
    
    function setEnvironment()
    {
		if ($this->test == 'yes'){
			$this->useWSDL =  $this->testWSDL;
		}
        else {
			$this->useWSDL =  $this->liveWSDL;
		}
	}
    
    private function getAPMS()
    {
		include_once ('nusoap/nusoap.php');
		global $woocommerce;
		
        $cl =  new WC_Customer;
		$this->setEnvironment();
		$client = new nusoap_client($this->useWSDL,true);
        
        $this->create_log($client, 'NuSoap Client data');
        
		$parameters = array(
            "merchantId"        => $this->merchant_id,
            "merchantSiteId"    => $this->merchantsite_id,
            "amount"            => "1",
            "languageCode"      => $this->formatLocation(get_locale()),
            "gwMerchantName"    => "",
            "gwPassword"        => "",
            "currencyIsoCode"   => get_woocommerce_currency(),
            "countryIsoCode"    => (isset($_SESSION['sc_country']) && !empty($_SESSION['sc_country']))
                ? $_SESSION['sc_country'] : SC_Versions_Resolver::get_client_country($cl),
        );
        
        $this->create_log($parameters, 'getAPMS params: ');
        $this->create_log($_SESSION, '$_SESSION: ');
		
		if ($this->load_payment_options=='yes'){
			try{
				$soap_response = $client->call('getMerchantSitePaymentOptions', $parameters);
				return $this->showAPMs($soap_response);
			}
            catch(nusoap_fault $fault){
                $this->create_log('error when try to get soap response');
				return false;
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
     * Function create_log
     * Create logs
     * 
     * @param mixed $data
     * @param string $title - title of the printed log
     */
    private function create_log($data, $title = '')
    {
        if(!defined('WP_DEBUG') || WP_DEBUG === false) {
            return;
        }
        
        $file = plugin_dir_path( __FILE__ ) . 'logs' . DIRECTORY_SEPARATOR . date("Y-m-d") . '.txt';
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
            file_put_contents($file, date('H:i:s') . ': ' . $d."\r\n"."\r\n", FILE_APPEND);
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

?>
