<?php
/***********************************************
* File      :   compat.php
* Project   :   Z-Push
* Descr     :   Help function for files
*
* Created   :   01.10.2007
*
* � Zarafa Deutschland GmbH, www.zarafaserver.de
* This file is distributed under GPL v2.
* Consult LICENSE file for details
************************************************/

if (!function_exists("file_put_contents")) {
    function file_put_contents($n,$d) {
        $f=@fopen($n,"w");
        if (!$f) {
            return false;
        } else {
            fwrite($f,$d);
            fclose($f);
            return true;
        }
    }
}

?>