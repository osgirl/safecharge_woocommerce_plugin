<?php

define("SC_TOCKEN", "Safe_Charge");

function generateToken($timestamp){
	$str = explode("_", SC_TOCKEN);
	if (count($str) > 1) {
		$sec_token = $str[0]. $timestamp. $str[count($str) - 1];
	}
    else{
		$sec_token = $str. $timestamp;
	}
    
	return md5($sec_token);
}

function checkToken($timestamp,$token) {
	if($token == generateToken($timestamp)) {
		return true;
	}
    else {
        return false;
    }
}
?>