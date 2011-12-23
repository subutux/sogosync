<?php
/***********************************************
* File      :   config.php
* Project   :   Z-Push
* Descr     :   Main configuration file
*
* Created   :   01.10.2007
*
* Copyright 2007 - 2010 Zarafa Deutschland GmbH
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU Affero General Public License, version 3,
* as published by the Free Software Foundation with the following additional
* term according to sec. 7:
*
* According to sec. 7 of the GNU Affero General Public License, version 3,
* the terms of the AGPL are supplemented with the following terms:
*
* "Zarafa" is a registered trademark of Zarafa B.V.
* "Z-Push" is a registered trademark of Zarafa Deutschland GmbH
* The licensing of the Program under the AGPL does not imply a trademark license.
* Therefore any rights, title and interest in our trademarks remain entirely with us.
*
* However, if you propagate an unmodified version of the Program you are
* allowed to use the term "Z-Push" to indicate that you distribute the Program.
* Furthermore you may use our trademarks where it is necessary to indicate
* the intended purpose of a product or service provided you use it in accordance
* with honest practices in industrial or commercial matters.
* If you want to propagate modified versions of the Program under the name "Z-Push",
* you may only do so if you have a written permission by Zarafa Deutschland GmbH
* (to acquire a permission please contact Zarafa at trademark@zarafa.com).
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU Affero General Public License for more details.
*
* You should have received a copy of the GNU Affero General Public License
* along with this program.  If not, see <http://www.gnu.org/licenses/>.
*
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

    define('STATE_DIR', BASE_PATH.'/state');

    // Try to set unlimited timeout
    define('SCRIPT_TIMEOUT', 0);

    //Max size of attachments to display inline. Default is 1MB
    define('MAX_EMBEDDED_SIZE', 1048576);

    // Device Provisioning
    define('PROVISIONING', true);

    // This option allows the 'loose enforcement' of the provisioning policies for older
    // devices which don't support provisioning (like WM 5 and HTC Android Mail) - dw2412 contribution
    // false (default) - Enforce provisioning for all devices
    // true - allow older devices, but enforce policies on devices which support it
    define('LOOSE_PROVISIONING', false);

    // Default conflict preference
    // Some devices allow to set if the server or PIM (mobile)
    // should win in case of a synchronization conflict
    //   SYNC_CONFLICT_OVERWRITE_SERVER - Server is overwritten, PIM wins
    //   SYNC_CONFLICT_OVERWRITE_PIM    - PIM is overwritten, Server wins (default)
    define('SYNC_CONFLICT_DEFAULT', SYNC_CONFLICT_OVERWRITE_PIM);

    // Global limitation of items to be synchronized
    // The mobile can define a sync back period for calendar and email items
    // For large stores with many items the time period could be limited to a max value
    // If the mobile transmits a wider time period, the defined max value is used
    // Applicable values:
    //   SYNC_FILTERTYPE_ALL (default, no limitation)
    //   SYNC_FILTERTYPE_1DAY, SYNC_FILTERTYPE_3DAYS, SYNC_FILTERTYPE_1WEEK, SYNC_FILTERTYPE_2WEEKS,
    //   SYNC_FILTERTYPE_1MONTH, SYNC_FILTERTYPE_3MONTHS, SYNC_FILTERTYPE_6MONTHS
    define('SYNC_FILTERTIME_MAX', SYNC_FILTERTYPE_ALL);

    // Interval in seconds before checking if there are changes on the server when in Ping.
    // It means the highest time span before a change is pushed to a mobile. Set it to
    // a higher value if you have a high load on the server.
    define('PING_INTERVAL', 30);

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
    // forward messages inline (default off - as attachment)
    define('IMAP_INLINE_FORWARD', false);
    // use imap_mail() to send emails (default) - off uses mail()
    define('IMAP_USE_IMAPMAIL', true);


    // ************************
    //  BackendMaildir settings
    // ************************
    define('MAILDIR_BASE', '/tmp');
    define('MAILDIR_SUBDIR', 'Maildir');

    // **********************
    //  BackendVCDir settings
    // **********************
    define('VCARDDIR_DIR', '/home/%u/.kde/share/apps/kabc/stdvcf');

    // Alternative backend to perform SEARCH requests (GAL search)
    // if an empty value is used, the default search functionality of the main backend is used
    // use 'SearchLDAP' to search in a LDAP directory (see backend/searchldap/config.php)
    define('SEARCH_PROVIDER', '');

?>