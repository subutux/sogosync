<?php

/***********************************************
* File      :   utils.php
* Project   :   Z-Push
* Descr     :   
*
* Created   :   03.04.2008
*
*  Zarafa Deutschland GmbH, www.zarafaserver.de
* This file is distributed under GPL v2.
* Consult LICENSE file for details
************************************************/

// saves information about folder data for a specific device	
function _saveFolderData($devid, $folders) {
	if (!is_array($folders) || empty ($folders))
		return false;

	$unique_folders = array ();

	foreach ($folders as $folder) {	

		// don't save folder-ids for emails
		if ($folder->type == SYNC_FOLDER_TYPE_INBOX)
			continue;

		// no folder from that type	or the default folder		
		if (!array_key_exists($folder->type, $unique_folders) || $folder->parentid == 0) {
			$unique_folders[$folder->type] = $folder->serverid;
		}
	}
	
	// Treo does initial sync for calendar and contacts too, so we need to fake 
	// these folders if they are not supported by the backend
	if (!array_key_exists(SYNC_FOLDER_TYPE_APPOINTMENT, $unique_folders)) 	
		$unique_folders[SYNC_FOLDER_TYPE_APPOINTMENT] = SYNC_FOLDER_TYPE_DUMMY;
	if (!array_key_exists(SYNC_FOLDER_TYPE_CONTACT, $unique_folders)) 		
		$unique_folders[SYNC_FOLDER_TYPE_CONTACT] = SYNC_FOLDER_TYPE_DUMMY;

	if (!file_put_contents(BASE_PATH.STATE_DIR."/compat-$devid", serialize($unique_folders))) {
		debugLog("_saveFolderData: Data could not be saved!");
	}
}

// returns information about folder data for a specific device	
function _getFolderID($devid, $class) {
	$filename = BASE_PATH.STATE_DIR."/compat-$devid";

	if (file_exists($filename)) {
		$arr = unserialize(file_get_contents($filename));

		if ($class == "Calendar")
			return $arr[SYNC_FOLDER_TYPE_APPOINTMENT];
		if ($class == "Contacts")
			return $arr[SYNC_FOLDER_TYPE_CONTACT];

	}

	return false;
}

/**
 * Function which converts a hex entryid to a binary entryid.
 * @param string @data the hexadecimal string
 */
function hex2bin($data)
{
    $len = strlen($data);
    $newdata = "";

    for($i = 0;$i < $len;$i += 2)
    {
        $newdata .= pack("C", hexdec(substr($data, $i, 2)));
    } 
    return $newdata;
}

function utf8_to_windows1252($string, $option = "")
{
    if (function_exists("iconv")){
        return @iconv("UTF-8", "Windows-1252" . $option, $string);
    }else{
        return utf8_decode($string); // no euro support here
    }
}

function windows1252_to_utf8($string, $option = "")
{
    if (function_exists("iconv")){
        return @iconv("Windows-1252", "UTF-8" . $option, $string);
    }else{
        return utf8_encode($string); // no euro support here
    }
}

function w2u($string) { return windows1252_to_utf8($string); }
function u2w($string) { return utf8_to_windows1252($string); }

function w2ui($string) { return windows1252_to_utf8($string, "//TRANSLIT"); }
function u2wi($string) { return utf8_to_windows1252($string, "//TRANSLIT"); }

/**
 * Build an address string from the components
 *
 * @param string $street - the street
 * @param string $zip - the zip code
 * @param string $city - the city
 * @param string $state - the state
 * @param string $country - the country
 * @return string the address string or null
 */
function buildAddressString($street, $zip, $city, $state, $country) {
	$out = "";
	
	if (isset($country) && $street != "") $out = $country;
	
	$zcs = "";
	if (isset($zip) && $zip != "") $zcs = $zip;
	if (isset($city) && $city != "") $zcs .= (($zcs)?" ":"") . $city;
	if (isset($state) && $state != "") $zcs .= (($zcs)?" ":"") . $state;
	if ($zcs) $out = $zcs . "\r\n" . $out;
	
	if (isset($street) && $street != "") $out = $street . (($out)?"\r\n\r\n". $out: "") ;
	
	return ($out)?$out:null;
}
?>