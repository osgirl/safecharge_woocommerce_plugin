<?php

/**
 * Class SC_Poster
 * 
 * We use it to create/ eceive posts to/from the APIs.
 *
 * @author SafeCharge
 */
class SC_API_Caller
{
    /**
     * Function call_rest_api
     * Call REST API and get response.
     * 
     * @param type $url - API URL
     * @param array $checksum_params - parameters we use for checksum
     * @param string $secret - merchant secret
     * @param type $hash - merchant hash
     * @param type $other_params - other parameters we use
     * 
     * @return mixed
     */
    public static function call_rest_api($url, $checksum_params, $secret, $hash, $other_params = array())
    {
        $checksum = '';
        foreach($checksum_params as $val) {
            $checksum .= $val;
        }

        $checksum .= $secret;
        $checksum_params['checksum'] = hash($hash, $checksum);
        
        if(!empty($other_params) and is_array($other_params)) {
            $params = array_merge($checksum_params, $other_params);
        }
        else {
            $params = $checksum_params;
        }
        
        $json_post = json_encode($params);

        self::create_log($params, 'rest params: ');
        
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
    
    /**
     * Function create_log
     * Create logs. You MUST have defined SC_LOG_FILE_PATH const,
     * holding the full path to the log file.
     * 
     * @param mixed $data
     * @param string $title - title of the printed log
     */
    public static function create_log($data, $file, $title = '')
    {
        if(!defined('WP_DEBUG') || WP_DEBUG === false) {
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
            if(defined('SC_LOG_FILE_PATH')) {
                file_put_contents(SC_LOG_FILE_PATH, date('H:i:s') . ': ' . $d."\r\n"."\r\n", FILE_APPEND);
            }
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
