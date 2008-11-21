<?php
/***********************************************
* File      :   debug.php
* Project   :   Z-Push
* Descr     :   Debuging functions
*
* Created   :   01.10.2007
*
* © Zarafa Deutschland GmbH, www.zarafaserver.de
* This file is distributed under GPL v2.
* Consult LICENSE file for details
************************************************/

global $debugstr;

function debug($str) {
    global $debugstr;
    $debugstr .= "$str\n";
}

function getDebugInfo() {
    global $debugstr;
    
    return $debugstr;
}

function debugLog($message) {
    @$fp = fopen(BASE_PATH . "/debug.txt","a");
    @$date = strftime("%x %X");
    @fwrite($fp, "$date [". getmypid() ."] $message\n");
    @fclose($fp);
}

function zarafa_error_handler($errno, $errstr, $errfile, $errline, $errcontext) {	
	debugLog("$errfile:$errline $errstr ($errno)");
}

error_reporting(E_ALL);
set_error_handler("zarafa_error_handler");

?>