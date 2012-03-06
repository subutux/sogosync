<?php
/***********************************************
* File      :   caldav.php
* Project   :   Z-Push
* Descr     :   This backend is based on
*               'BackendDiff' which handles the
*               intricacies of generating
*               differentials from static
*               snapshots. This means that the
*               implementation here needs no
*               state information, and can simply
*               return the current state of the
*               messages. The diffbackend will
*               then compare the current state
*               to the known last state of the PDA
*               and generate change increments
*               from that.
*
* Created   :   20.05.2011
*
* Copyright 2011 Jeroen Dekkers <jeroen@dekkers.ch>
* Copyright 2012 xbgmsharp <xbgmsharp@gmail.com>
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU Affero General Public License as
* published by the Free Software Foundation, either version 3 of the
* License, or (at your option) any later version.
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
* GNU Affero General Public License for more details.
*
* You should have received a copy of the GNU Affero General Public License
* along with this program.  If not, see <http://www.gnu.org/licenses/>.
*
* Consult LICENSE file for details
************************************************/

require_once('diffbackend.php');

// This is caldav client library from davical
require_once('caldav-client-v2.php');

require_once('iCalendar.php');

class BackendCaldav extends BackendDiff {
    /* Called to logon a user. These are the three authentication strings that you must
     * specify in ActiveSync on the PDA. Normally you would do some kind of password
     * check here. Alternatively, you could ignore the password here and have Apache
     * do authentication via mod_auth_*
     */
    function Logon($username, $domain, $password) {
	debugLog("CaldavBackend: " . __FUNCTION__ . "(" . implode(", ", func_get_args()) . ")");
	$this->_caldav = new CalDAVClient("http://sogo-demo.inverse.ca/SOGo/dav/", $username, $password);
	$this->_events = array();

	$options = $this->_caldav->DoOptionsRequest();
	if ( isset($options["PROPFIND"]) ) {
	    $this->_username = $username;
	    return true;
	} else {
	    return false;
	}
    }

    /* Called before shutting down the request to close the IMAP connection
     */
    function Logoff() {
	debugLog("CaldavBackend: " . __FUNCTION__ . "(" . implode(", ", func_get_args()) . ")");

    }

    /* Called directly after the logon. This specifies the client's protocol version
     * and device id. The device ID can be used for various things, including saving
     * per-device state information.
     * The $user parameter here is normally equal to the $username parameter from the
     * Logon() call. In theory though, you could log on a 'foo', and then sync the emails
     * of user 'bar'. The $user here is the username specified in the request URL, while the
     * $username in the Logon() call is the username which was sent as a part of the HTTP
     * authentication.
     */
    function Setup($user, $devid, $protocolversion) {
	debugLog("CaldavBackend: " . __FUNCTION__ . "(" . implode(", ", func_get_args()) . ")");
        $this->_user = $user;
        $this->_devid = $devid;
        $this->_protocolversion = $protocolversion;

        return true;
    }

    /* Sends a message which is passed as rfc822. You basically can do two things
     * 1) Send the message to an SMTP server as-is
     * 2) Parse the message yourself, and send it some other way
     * It is up to you whether you want to put the message in the sent items folder. If you
     * want it in 'sent items', then the next sync on the 'sent items' folder should return
     * the new message as any other new message in a folder.
     */
    function SendMail($rfc822, $forward = false, $reply = false, $parent = false) {
	debugLog("CaldavBackend: " . __FUNCTION__ . "(" . implode(", ", func_get_args()) . ")");

	// Unimplemented
        return false;
    }

    /* Should return a wastebasket folder if there is one. This is used when deleting
     * items; if this function returns a valid folder ID, then all deletes are handled
     * as moves and are sent to your backend as a move. If it returns FALSE, then deletes
     * are always handled as real deletes and will be sent to your importer as a DELETE
     */
    function GetWasteBasket() {
	debugLog("CaldavBackend: " . __FUNCTION__ . "(" . implode(", ", func_get_args()) . ")");
        return false;
    }

    /* Should return a list (array) of messages, each entry being an associative array
     * with the same entries as StatMessage(). This function should return stable information; ie
     * if nothing has changed, the items in the array must be exactly the same. The order of
     * the items within the array is not important though.
     *
     * The cutoffdate is a date in the past, representing the date since which items should be shown.
     * This cutoffdate is determined by the user's setting of getting 'Last 3 days' of e-mail, etc. If
     * you ignore the cutoffdate, the user will not be able to select their own cutoffdate, but all
     * will work OK apart from that.
     */
    function GetMessageList($folderid, $cutoffdate) {
	debugLog("CaldavBackend: " . __FUNCTION__ . "(" . implode(", ", func_get_args()) . ")");

	$messages = array();

	$eventlist = $this->_get_eventlist($folderid);

	foreach($eventlist as $event) {
	    $message = array();
	    $message["mod"] = $event["etag"];
	    $message["id"] = $event["href"];
	    $message["flags"] = 0;

	    $messages[] = $message;
	}

	return $messages;
    }

    /* This function is analogous to GetMessageList.
     */
    function GetFolderList() {
	debugLog("CaldavBackend: " . __FUNCTION__ . "(" . implode(", ", func_get_args()) . ")");

	$folderlist = array();

	$list = $this->_caldav->FindCalendars();
	foreach ($list as $val) {
	    $folder = array();
	    $folder["id"]  = $val->id;
	    $folder["parent"] = "0";
	    $folder["mod"] = $val->displayname;

	    $folderlist[] = $folder;
	}

	return $folderlist;
    }

    /* GetFolder should return an actual SyncFolder object with all the properties set. Folders
     * are pretty simple really, having only a type, a name, a parent and a server ID.
     */
    function GetFolder($id) {
	debugLog("CaldavBackend: " . __FUNCTION__ . "(" . implode(", ", func_get_args()) . ")");

	$cal = $this->_get_calendar($id);

        $folder = new SyncFolder();
        $folder->serverid = $id;
	$folder->displayname = $cal->displayname;
	$folder->parentid = "0";

	// FIXME
	if ($id == "personal") {
	    $folder->type = SYNC_FOLDER_TYPE_APPOINTMENT;
	} else {
	    $folder->type = SYNC_FOLDER_TYPE_USER_APPOINTMENT;
	}

	return $folder;
    }

    /* Return folder stats. This means you must return an associative array with the
     * following properties:
     * "id" => The server ID that will be used to identify the folder. It must be unique, and not too long
     *         How long exactly is not known, but try keeping it under 20 chars or so. It must be a string.
     * "parent" => The server ID of the parent of the folder. Same restrictions as 'id' apply.
     * "mod" => This is the modification signature. It is any arbitrary string which is constant as long as
     *          the folder has not changed. In practice this means that 'mod' can be equal to the folder name
     *          as this is the only thing that ever changes in folders. (the type is normally constant)
     */
    function StatFolder($id) {
	debugLog("CaldavBackend: " . __FUNCTION__ . "(" . implode(", ", func_get_args()) . ")");

	$cal = $this->_get_calendar($id);

	$folder = array();
	$folder["id"]  = $cal->id;
	$folder["parent"] = "0";
	$folder["mod"] = $cal->displayname;

	return $folder;
    }

    /* Creates or modifies a folder
     * "folderid" => id of the parent folder
     * "oldid" => if empty -> new folder created, else folder is to be renamed
     * "displayname" => new folder name (to be created, or to be renamed to)
     * "type" => folder type, ignored in IMAP
     *
     */
    function ChangeFolder($folderid, $oldid, $displayname, $type){
	debugLog("CaldavBackend: " . __FUNCTION__ . "(" . implode(", ", func_get_args()) . ")");

	return false;
    }

    /* Should return attachment data for the specified attachment. The passed attachment identifier is
     * the exact string that is returned in the 'AttName' property of an SyncAttachment. So, you should
     * encode any information you need to find the attachment in that 'attname' property.
     */
    function GetAttachmentData($attname) {
	debugLog("CaldavBackend: " . __FUNCTION__ . "(" . implode(", ", func_get_args()) . ")");
    }

    /* StatMessage should return message stats, analogous to the folder stats (StatFolder). Entries are:
     * 'id'     => Server unique identifier for the message. Again, try to keep this short (under 20 chars)
     * 'flags'     => simply '0' for unread, '1' for read
     * 'mod'    => modification signature. As soon as this signature changes, the item is assumed to be completely
     *             changed, and will be sent to the PDA as a whole. Normally you can use something like the modification
     *             time for this field, which will change as soon as the contents have changed.
     */

    function StatMessage($folderid, $id) {
	debugLog("CaldavBackend: " . __FUNCTION__ . "(" . implode(", ", func_get_args()) . ")");

	$event = $this->_get_event($folderid, $id);

	$message = array();
	$message["mod"] = $event["etag"];
	$message["id"] = $event["href"];
	$message["flags"] = 0;

	return $message;
    }

    /* GetMessage should return the actual SyncXXX object type. You may or may not use the '$folderid' parent folder
     * identifier here.
     * Note that mixing item types is illegal and will be blocked by the engine; ie returning an Email object in a
     * Tasks folder will not do anything. The SyncXXX objects should be filled with as much information as possible,
     * but at least the subject, body, to, from, etc.
     *
     * Truncsize is the size of the body that must be returned. If the message is under this size, bodytruncated should
     * be 0 and body should contain the entire body. If the body is over $truncsize in bytes, then bodytruncated should
     * be 1 and the body should be truncated to $truncsize bytes.
     *
     * Bodysize should always be the original body size.
     */
    function GetMessage($folderid, $id, $truncsize, $mimesupport = 0) {
	debugLog("CaldavBackend: " . __FUNCTION__ . "(" . implode(", ", func_get_args()) . ")");

	$cal = $this->_get_event($folderid, $id);

	$ical = new iCalComponent($cal['data']);

	debugLog($cal['data']);

	$event = new SyncAppointment();

	$vevent = $ical->GetComponents("VEVENT");
	foreach ($vevent[0]->GetProperties() as $property) {
	    switch($property->Name()) {
	    case "PRIORITY":
	    case "SEQUENCE":
	    case "CREATED":
	    case "DTSTAMP":
	        // Not used
		break;

	    case "DTSTART":
		$time = $this->_gettimestamp($property->Value());
		$event->starttime = $time["timestamp"];
		if ($time["allday"])
		    $event->alldayevent="1";
		break;

	    case "DTEND":
		$time = $this->_gettimestamp($property->Value());
		$event->endtime = $time["timestamp"];
		if ($time["allday"])
		    $event->alldayevent="1";
		break;

	    case "LAST-MODIFIED":
		/* ActiveSync dtstamp is time created or modified, so we should map this to LAST-MODIFIED
		 * and not to DTSTAMP, because DTSTAMP is just the creation date.
		 */
		$time = $this->_gettimestamp($property->Value());
		$event->dtstamp = $time["timestamp"];
		break;

	    case "UID":
		$event->uid = $property->Value();
		break;

	    case "SUMMARY":
		$event->subject = $property->Value();
		break;

	    case "LOCATION":
		$event->location = $property->Value();
		break;

	    case "DESCRIPTION":
		$event->body = $property->Value();
		break;

	    case "TRANSP":
		if ($property->Value() == "OPAQUE")
		    $event->busystatus = "2";
		else if ($property->Value() == "TRANSPARENT")
		    $event->busystatus = "0";
		else
		    debugLog("Uknown value for TRANSP: " . $property->Value());
		break;

	    case "CLASS":
		if ($property->Value() == "PUBLIC")
		    $event->sensitivity = "0";
		else if ($property->Value() == "PRIVATE")
		    $event->sensitivity = "2";
		else if ($property->Value() == "CONFIDENTIAL")
		    $event->sensitivity = "3";
		else
		    debugLog("Unknown value for CLASS: " . $property->Value());
		break;

	    case "CATEGORIES":
		$event->categories = explode(',',$property->Value());
		break;

	    case "RRULE":
		$recurrence = new SyncRecurrence();
		$rulepartlist = explode(';', $propety->Value());
		foreach ($rulepartlist as $rulepart) {
		    $rulearray = explode('=', $rulepart);
		    switch ($rulearray[0]) {
		    case "FREQ":
			switch ($rulearray[1]) {
			case "DAILY":
			    $recurrence->type = 0;
			    break;

			case "WEEKLY":
			    $recurrence->type = 1;
			    break;

			case "MONTHLY":
			    $recurrence->type = 2;
			    break;

			case "YEARLY":
			    $recurrence->type = 4;
			    break;

			default:
			    debugLog("Unsupported frequency: " . $rulearray[1]);
			    goto recurrence_error;
			}
			break;

		    case "COUNT":
			$recurrence->occurrences = $rulearray[1];
			break;

		    case "INTERVAL":
			$recurrence->interval = $rulearray[1];
			break;

		    default:
			debugLog("Unsupported rule part: " . $rulepart);
			goto recurrence_error;
		    }
		}
	    recurrence_error:
		break;

	    default:
		if (substr($property->Name(), 0, 2) != "X-")
		    debugLog("WARNING: Not implemented property " . $property->Name() . " with value " . $property->Value());
		break;
	    }
	}

	/* FIXME: ActiveSync only support one reminder that is of the type "minutes before the event starts". If there
	 * are multiple reminders we should somehow choose the most sensible one to send to the PDA.
	 */
	$valarm = $vevent[0]->GetComponents("VALARM");
	if ($valarm) {
	    if (count($valarm) > 1)
		debugLog("WARNING: Multiple alarms set but ActiveSync only supports one, only syncing first one");

	    $action = current($valarm)->GetProperties("ACTION");
	    if (current($action)->Value() != "DISPLAY") {
		debugLog("WARNING: Don't know how to handle non-DISPLAY alarm, not syncing alarm");
		goto no_alarm;
	    }

	    $trigger = current(current($valarm)->GetProperties("TRIGGER"));
	    $params = $trigger->Parameters();
	    if ($params) {
		if (array_key_exists("VALUE", $params) && $params["VALUE"] != "DURATION") {
		    debugLog("WARNING: Only DURATION alarms are currently supported, not syncing alarm");
		    goto no_alarm;
		}
		if (array_key_exists("RELATED", $params) && $params["RELATED"] != "START") {
		    debugLog("WARNING: ActiveSync doesn't support alarms relative to event end, not syncing alarm");
		    goto no_alarm;
		}

		// We now have to parse the duration.
		$minutes = 0;
		$value = $trigger->Value();
		if ($value[0] == '+') {
		    debugLog("WARNING: ActiveSync doesn't supports alarms after event start, not syncing alarm");
		    goto no_alarm;
		}

		if ($value[0] == '-')
		    $value = substr($value, 1);

		if ($value[0] != 'P') {
		    debugLog("ERROR: Malformed duration");
		    goto no_alarm;
		}

		$value = substr($value, 1);

		if (substr($value, -1) == 'W') {
		    $minutes = intval(substr($value, 0, -1))*7*24*60;
		}
		else if (substr($value, -1) == 'D') {
		    $minutes = intval(substr($value, 0, -1))*24*60;
		} else {
		    if(preg_match("/(([0-9]+)D)?T(([0-9]+)H)?(([0-9]+)M)?(([0-9]+)S)?/", $value, $matches)) {
			if ($matches[2])
			    $minutes += $matches[2]*24*60;
			if ($matches[4])
			    $minutes += $matches[4]*60;
			if (count($matches) >= 6 && $matches[6])
			    $minutes += $matches[6];
			if (count($matches) >= 8 && $matches[8]) {
			    if ($minutes == 0) {
				debugLog("ActiveSync doesn't support alarms in seconds, making it one minute");
				$minutes = 1;
			    } else {
				debugLog("ActiveSync doesn't support alarms in seconds, disregarding seconds");
			    }
			}
		    } else {
			debugLog("ERROR: Malformed duration");
		    }
		}
		debugLog("Minutes: " . $minutes);
		$event->reminder = $minutes;
	    }
	}
    no_alarm:
	$event->bodytruncated = 0;
	return $event;
    }

    /* This function is called when the user has requested to delete (really delete) a message. Usually
     * this means just unlinking the file its in or somesuch. After this call has succeeded, a call to
     * GetMessageList() should no longer list the message. If it does, the message will be re-sent to the PDA
     * as it will be seen as a 'new' item. This means that if you don't implement this function, you will
     * be able to delete messages on the PDA, but as soon as you sync, you'll get the item back
     */
    function DeleteMessage($folderid, $id) {
	debugLog("CaldavBackend: " . __FUNCTION__ . "(" . implode(", ", func_get_args()) . ")");
	return false;
    }

    /* This should change the 'read' flag of a message on disk. The $flags
     * parameter can only be '1' (read) or '0' (unread). After a call to
     * SetReadFlag(), GetMessageList() should return the message with the
     * new 'flags' but should not modify the 'mod' parameter. If you do
     * change 'mod', simply setting the message to 'read' on the PDA will trigger
     * a full resync of the item from the server
     */
    function SetReadFlag($folderid, $id, $flags) {
	debugLog("CaldavBackend: " . __FUNCTION__ . "(" . implode(", ", func_get_args()) . ")");
	return false;
    }

    /* This function is called when a message has been changed on the PDA. You should parse the new
     * message here and save the changes to disk. The return value must be whatever would be returned
     * from StatMessage() after the message has been saved. This means that both the 'flags' and the 'mod'
     * properties of the StatMessage() item may change via ChangeMessage().
     * Note that this function will never be called on E-mail items as you can't change e-mail items, you
     * can only set them as 'read'.
     */
    function ChangeMessage($folderid, $id, $message) {
	debugLog("CaldavBackend: " . __FUNCTION__ . "(" . $folderid . "," . $id . ")");
        return false;
    }

    /* This function is called when the user moves an item on the PDA. You should do whatever is needed
     * to move the message on disk. After this call, StatMessage() and GetMessageList() should show the items
     * to have a new parent. This means that it will disappear from GetMessageList() will not return the item
     * at all on the source folder, and the destination folder will show the new message
     */
    function MoveMessage($folderid, $id, $newfolderid) {
	debugLog("CaldavBackend: " . __FUNCTION__ . "(" . implode(", ", func_get_args()) . ")");
        return false;
    }

    /* Parse the message and return only the plaintext body
     */
    function getBody($message) {
	debugLog("CaldavBackend: " . __FUNCTION__ . "(" . implode(", ", func_get_args()) . ")");
	return false;
    }

    // Get all parts in the message with specified type and concatenate them together, unless the
    // Content-Disposition is 'attachment', in which case the text is apparently an attachment
    function getBodyRecursive($message, $subtype, &$body) {
	debugLog("CaldavBackend: " . __FUNCTION__ . "(" . implode(", ", func_get_args()) . ")");
	return false;
    }

    private function _get_calendar($id) {
	$list = $this->_caldav->FindCalendars();
	foreach ($list as $cal) {
	    if ($cal->id == $id) {
		return $cal;
	    }
	}

	return false;
    }

    private function _get_eventlist($folderid) {
	if (!array_key_exists($folderid, $this->_events)) {
	    $cal = $this->_get_calendar($folderid);
	    $this->_caldav->SetCalendar($cal->url);
	    $this->_events[$folderid] = $this->_caldav->GetEvents();
	}

	return $this->_events[$folderid];
    }

    private function _get_event($folderid, $id) {
	$eventlist = $this->_get_eventlist($folderid);

	foreach ($eventlist as $event) {
	    if ($event["href"] == $id)
		return $event;
	}

	return false;
    }

    private function _gettimestamp($str) {
	$ret = array();
	$ret["allday"] = false;
	$tm = strptime($str, '%Y%m%dT%H%M%S');
	if (! $tm)
	    $tm = strptime($str, '%Y%m%dT%H%M%SZ');

	if (! $tm) {
	    $tm = strptime($str, '%Y%m%d');
	    if (! $tm)
		return false;

	    $ret["allday"] = true;
	}

	$ret["timestamp"] = mktime($tm['tm_hour'], $tm['tm_min'], $tm['tm_sec'],
				   $tm['tm_mon'] + 1, $tm['tm_mday'], $tm['tm_year'] + 1900);
	return $ret;
    }
};

?>
