#!/usr/bin/php
<?php
/***********************************************
* File      :   z-push-admin.php
* Project   :   Z-Push
* Descr     :   This is a small command line
*               client to see and modify the
*               wipe status of Zarafa users.
*
* Created   :   14.05.2010
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

define("PHP_MAPI_PATH", "/usr/share/php/mapi/");
define('MAPI_SERVER', 'file:///var/run/zarafa');

main();


function main() {
    zpa_configure();
    zpa_handle();
}

function zpa_configure() {

    if (!isset($_SERVER["TERM"])) {
        echo "This script should not be called in a browser.\n";
        exit(1);
    }

    if (!isset($_SERVER["LOGNAME"])) {
            echo "This script should not be called in a browser.\n";
            exit(1);
    }

    if (!function_exists("getopt")) {
        echo "PHP Function getopt not found. Please check your PHP version and settings.\n";
        exit(1);
    }
}

function zpa_handle() {
    $shortoptions = "w:r:d:li:h:u:p:";
    $options = getopt($shortoptions);

    $mapi = MAPI_SERVER;
    $user = "SYSTEM";
    $pass = "";

    if (isset($options['h']))
        $mapi = $options['h'];

    if (isset($options['u']) && isset($options['p'])) {
        $user = $options['u'];
        $pass = $options['p'];
    }

    $zarafaAdmin = zpa_zarafa_admin_setup($mapi, $user, $pass);
    if (isset($zarafaAdmin['adminStore']) && isset($options['l'])) {
        zpa_get_userlist($zarafaAdmin['adminStore']);
    }
    elseif (isset($zarafaAdmin['adminStore']) && isset($options['d']) && !empty($options['d'])) {
    	zpa_get_userdetails($zarafaAdmin['adminStore'], $zarafaAdmin['session'], trim($options['d']));
    }
    elseif (isset($zarafaAdmin['adminStore']) && isset($options['w']) && !empty($options['w']) && isset($options['i']) && !empty($options['i'])) {
    	zpa_wipe_device($zarafaAdmin['adminStore'], $zarafaAdmin['session'], trim($options['w']), trim($options['i']));
    }
    elseif (isset($zarafaAdmin['adminStore']) && isset($options['r']) && !empty($options['r']) && isset($options['i']) && !empty($options['i'])) {
    	zpa_remove_device($zarafaAdmin['adminStore'], $zarafaAdmin['session'], trim($options['r']), trim($options['i']));
    }
    else {
        echo "Usage:\nz-push-admin.sh [actions] [options]\n\nActions: [-l] | [[-d|-w|-r] username]\n\t-l\t\tlist users\n\t-d user\t\tshow user devices\n\t-w user\t\twipe user device, '-i DeviceId' option required\n\t-r user\t\tremove device from list, '-i DeviceId' option required\n\nGlobal options: [-h path] [[-u remoteuser] [-p password]]\n\t-h path\t\tconnect through <path>, e.g. file:///var/run/socket\n\t-u remoteuser\tlogin as remoteuser\n\t-p password\tpassword of the remoteuser\n\n";    }
}

function zpa_zarafa_admin_setup($mapi, $user, $pass) {
    require(PHP_MAPI_PATH.'mapi.util.php');
    require(PHP_MAPI_PATH.'mapidefs.php');
    require(PHP_MAPI_PATH.'mapicode.php');
    require(PHP_MAPI_PATH.'mapitags.php');
    require(PHP_MAPI_PATH.'mapiguid.php');

    $session = @mapi_logon_zarafa($user, $pass, $mapi);

    if (!$session) {
        echo "User '$user' could not login. The script will exit. Errorcode: 0x". sprintf("%x", mapi_last_hresult()) . "\n";
        exit(1);
    }

    $stores = @mapi_getmsgstorestable($session);
    $storeslist = @mapi_table_queryallrows($stores);
    $adminStore = @mapi_openmsgstore($session, $storeslist[0][PR_ENTRYID]);

    if (!$stores || !$storeslist || !$adminStore ) {
        echo "There was error trying to log in as admin or retrieving admin info. The script will exit.\n";
        exit(1);
    }

    return array("session" => $session, "adminStore" => $adminStore);
}

function zpa_get_userlist($adminStore) {
    $companies = mapi_zarafa_getcompanylist($adminStore);
    if (is_array($companies)) {
        foreach($companies as $company) {
            $users = mapi_zarafa_getuserlist($adminStore, $company['companyid']);
            _zpa_get_userlist_print($users, $company['companyname']);
        }
    }
    else
        _zpa_get_userlist_print(mapi_zarafa_getuserlist($adminStore), "Default");
}

function _zpa_get_userlist_print($users, $company = null) {
    if (isset($company))
        echo "User list for ". $company . "(". count($users) ."):\n";

    if (is_array($users) && !empty($users)) {
        echo "\tusername\t\tfullname\n";
        echo "\t---------------------------------------------\n";
        foreach ($users as $user) {
            $t = 3-floor(strlen($user['username'])/8);
            if ($t < 1) $t = 1;
            echo "\t{$user['username']}".str_repeat("\t", $t)."{$user['fullname']}\n";
        }
    }
    echo "\n";
}

function zpa_get_userdetails($adminStore, $session, $user) {
    $userEntryId = @mapi_msgstore_createentryid($adminStore, $user);
    $userStore = @mapi_openmsgstore($session, $userEntryId);
    $hresult = mapi_last_hresult();

    // Cache the store for later use
    if($hresult != NOERROR) {
        echo "Could not open store for $user. The script will exit.\n";
        exit (1);
    }
    $devicesprops = mapi_getprops($userStore, array(0x6880101E, 0x6881101E, 0x6882101E, 0x6883101E, 0x68841003, 0x6885101E, 0x6886101E, 0x6887101E, 0x68881040, 0x68891040));
    if (isset($devicesprops[0x6881101E]) && is_array($devicesprops[0x6881101E])) {
        $nrdevices = count($devicesprops[0x6881101E]);
        echo "Username:\t\t$user\n";
        for ($i = 0; $i < $nrdevices; $i++) {
            //generate some device id if it is not set, so that it is possible to remove the device
            if (!isset($devicesprops[0x6881101E][$i]) || ! $devicesprops[0x6881101E][$i]) {
                $devicesprops[0x6881101E][$i] = mt_rand(0, 100000);
                mapi_setprops($userStore, array(0x6881101E=>$devicesprops[0x6881101E]));
            }

            echo "-----------------------------------------------------\n";
            echo "DeviceId:\t\t{$devicesprops[0x6881101E][$i]}\n";
            echo "Device type:\t\t".(isset($devicesprops[0x6882101E][$i]) ? $devicesprops[0x6882101E][$i] : "unknown")."\n";
            echo "UserAgent:\t\t".(isset($devicesprops[0x6883101E][$i]) ? $devicesprops[0x6883101E][$i] : "unknown")."\n";
            echo "First sync:\t\t".(isset($devicesprops[0x68881040][$i]) ? strftime("%Y-%m-%d %H:%M", $devicesprops[0x68881040][$i]) : "unknown")."\n";
            echo "Last sync:\t\t".(isset($devicesprops[0x68891040][$i]) ? strftime("%Y-%m-%d %H:%M", $devicesprops[0x68891040][$i]) : "unknown")."\n";
            echo "Status:\t\t\t";
            if (isset($devicesprops[0x68841003][$i]))
                switch ($devicesprops[0x68841003][$i]) {
                    case 1:
                        echo "OK\n";
                        break;
                    case 2:
                        echo "Pending wipe\n";
                        break;
                    case 3:
                        echo "Wiped\n";
                        break;
                    default:
                        echo "Not available\n";
                        break;
                }
            else echo "Not available\n";
            echo "WipeRequest on:\t\t".(isset($devicesprops[0x6885101E][$i]) && $devicesprops[0x6885101E][$i] != "undefined" ? strftime("%Y-%m-%d %H:%M", $devicesprops[0x6885101E][$i]) : "not set")."\n";
            echo "WipeRequest by:\t\t".(isset($devicesprops[0x6886101E][$i]) && $devicesprops[0x6886101E][$i] != "undefined" ? $devicesprops[0x6886101E][$i] : "not set")."\n";
            echo "Wiped on:\t\t".(isset($devicesprops[0x6887101E][$i]) && $devicesprops[0x6887101E][$i] != "undefined" ? strftime("%Y-%m-%d %H:%M", $devicesprops[0x6887101E][$i]) : "not set")."\n";
        }
    }
    else echo "No devices found for $user.\n";
}

function zpa_wipe_device($adminStore, $session, $user, $deviceid) {
    $userEntryId = @mapi_msgstore_createentryid($adminStore, $user);
    $userStore = @mapi_openmsgstore($session, $userEntryId);
    $hresult = mapi_last_hresult();

    if($hresult != NOERROR) {
        echo "Could not open store for $user. The script will exit.\n";
        exit (1);
    }

    $devicesprops = mapi_getprops($userStore, array(0x6880101E, 0x6881101E, 0x6882101E, 0x6883101E, 0x68841003, 0x6885101E, 0x6886101E, 0x6887101E, 0x68881040, 0x68891040));
    if (isset($devicesprops[0x6881101E]) && is_array($devicesprops[0x6881101E])) {
        $ak = array_search($deviceid, $devicesprops[0x6881101E]);
        if ($ak !== false) {
            //set new status remote wipe status
            $devicesprops[0x68841003][$ak] = 2;
            $devicesprops[0x6886101E][$ak] = $_SERVER["LOGNAME"];
            $devicesprops[0x6885101E][$ak] = time();
            $devicesprops[0x6880101E][$ak] = $devicesprops[0x6880101E][$ak]."0";
            mapi_setprops($userStore, array(0x68841003=>$devicesprops[0x68841003], 0x6886101E =>$devicesprops[0x6886101E], 0x6885101E=>$devicesprops[0x6885101E], 0x6880101E=>$devicesprops[0x6880101E]));
            $hresult = mapi_last_hresult();

            if($hresult != NOERROR) {
                echo "Could not set the wipe status for $user. Errorcode 0x".sprintf("%x", $hresult).". The script will exit.\n";
                exit (1);
            }
            else {
                echo "Set the device status to \"Pending wipe\".\n";
            }
        }
        else {
            echo "No device found with the given id.\n";
            exit(1);
        }
    }
    else {
        echo "No devices found for user $user.\n";
        exit(1);
    }
}

function zpa_remove_device($adminStore, $session, $user, $deviceid) {
    $userEntryId = @mapi_msgstore_createentryid($adminStore, $user);
    $userStore = @mapi_openmsgstore($session, $userEntryId);
    $hresult = mapi_last_hresult();

    if($hresult != NOERROR) {
        echo "Could not open store for $user. The script will exit.\n";
        exit (1);
    }

    $devicesprops = mapi_getprops($userStore, array(0x6880101E, 0x6881101E, 0x6882101E, 0x6883101E, 0x68841003, 0x6885101E, 0x6886101E, 0x6887101E, 0x68881040, 0x68891040));
    if (isset($devicesprops[0x6881101E]) && is_array($devicesprops[0x6881101E])) {
        $ak = array_search($deviceid, $devicesprops[0x6881101E]);
        if ($ak !== false) {
            if (count($devicesprops[0x6880101E]) == 1) {
                mapi_deleteprops($userStore, array(0x6880101E, 0x6881101E, 0x6882101E, 0x6883101E, 0x68841003, 0x6885101E, 0x6886101E, 0x6887101E, 0x68881040, 0x68891040));
            }
            else {
                unset(  $devicesprops[0x6880101E][$ak], $devicesprops[0x6881101E][$ak], $devicesprops[0x6882101E][$ak],
                        $devicesprops[0x6883101E][$ak],$devicesprops[0x68841003][$ak],$devicesprops[0x6885101E][$ak],
                        $devicesprops[0x6886101E][$ak],$devicesprops[0x6887101E][$ak],$devicesprops[0x68881040][$ak],
                        $devicesprops[0x68891040][$ak]);
                mapi_setprops($userStore,
                        array(
                            0x6880101E  => isset($devicesprops[0x6880101E]) ? $devicesprops[0x6880101E] : array(),
                            0x6881101E  => isset($devicesprops[0x6881101E]) ? $devicesprops[0x6881101E] : array(),
                            0x6882101E  => isset($devicesprops[0x6882101E]) ? $devicesprops[0x6882101E] : array(),
                            0x6883101E  => isset($devicesprops[0x6883101E]) ? $devicesprops[0x6883101E] : array(),
                            0x68841003  => isset($devicesprops[0x68841003]) ? $devicesprops[0x68841003] : array(),
                            0x6885101E  => isset($devicesprops[0x6885101E]) ? $devicesprops[0x6885101E] : array(),
                            0x6886101E  => isset($devicesprops[0x6886101E]) ? $devicesprops[0x6886101E] : array(),
                            0x6887101E  => isset($devicesprops[0x6887101E]) ? $devicesprops[0x6887101E] : array(),
                            0x68881040  => isset($devicesprops[0x68881040]) ? $devicesprops[0x68881040] : array(),
                            0x68891040  => isset($devicesprops[0x68891040]) ? $devicesprops[0x68891040] : array()
                        ));
            }
            $hresult = mapi_last_hresult();

            if($hresult != NOERROR) {
                echo "Could not remove device from list for $user. Errorcode 0x".sprintf("%x", $hresult).". The script will exit.\n";
                exit (1);
            }
            else {
                echo "Removed device from list.\n";
            }
        }
        else {
            echo "No device found with the given id.\n";
            exit(1);
        }
    }
    else {
        echo "No devices found for the user $user.\n";
        exit(1);
    }
}

?>
