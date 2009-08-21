<?php
/***********************************************
* File      :   config.php
* Project   :   Z-Push
* Descr     :   Main configuration file
*
* Created   :   01.10.2007
*
* � Zarafa Deutschland GmbH, www.zarafaserver.de
* This file is distributed under GPL v2.
* Consult LICENSE file for details
************************************************/
    // Defines the default time zone
    if (function_exists("date_default_timezone_set")){
        date_default_timezone_set("Europe/Amsterdam");
    }

    // Defines the base path on the server, terminated by a slash
    define('BASE_PATH', dirname($_SERVER['SCRIPT_FILENAME']) . "/");

    // Define the include paths
    ini_set('include_path',
                        BASE_PATH. "include/" . PATH_SEPARATOR .
                        BASE_PATH. PATH_SEPARATOR .
                        ini_get('include_path') . PATH_SEPARATOR .
                        "/usr/share/php/" . PATH_SEPARATOR .
                        "/usr/share/php5/" . PATH_SEPARATOR .
                        "/usr/share/pear/");

    define('STATE_DIR', 'state');

    // Try to set unlimited timeout
    define('SCRIPT_TIMEOUT', 0);

    //Max size of attachments to display inline. Default is 1MB
    define('MAX_EMBEDDED_SIZE', 1048576);

    // Device Provisioning
    define('PROVISIONING', true);
    
    // The data providers that we are using (see configuration below)
    $BACKEND_PROVIDER = "BackendICS";

    // ************************
    //  BackendICS settings
    // ************************

    // Defines the server to which we want to connect
    define('MAPI_SERVER', 'file:///var/run/zarafa');


    // ************************
    //  BackendIMAP settings
    // ************************

    // Defines the server to which we want to connect
    // recommended to use local servers only
    define('IMAP_SERVER', 'localhost');
    // connecting to default port (143)
    define('IMAP_PORT', 143);
    // best cross-platform compatibility (see http://php.net/imap_open for options)
    define('IMAP_OPTIONS', '/notls/norsh');
    // overwrite the "from" header if it isn't set when sending emails
    // options: 'username'    - the username will be set (usefull if your login is equal to your emailaddress)
    //        'domain'    - the value of the "domain" field is used
    //        '@mydomain.com' - the username is used and the given string will be appended
    define('IMAP_DEFAULTFROM', '');
    // copy outgoing mail to this folder. If not set z-push will try the default folders
    define('IMAP_SENTFOLDER', '');


    // ************************
    //  BackendMaildir settings
    // ************************
    define('MAILDIR_BASE', '/tmp');
    define('MAILDIR_SUBDIR', 'Maildir');

    // **********************
    //  BackendVCDir settings
    // **********************
    define('VCARDDIR_DIR', '/home/%u/.kde/share/apps/kabc/stdvcf');

?>