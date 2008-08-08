<?php
/***********************************************
* File      :   statemachine.php
* Project   :   Z-Push
* Descr     :   This class handles state requests;
*				Each differential mechanism can 
*				store its own state information,
*				which is stored through the
*				state machine. SyncKey's are
*				of the  form {UUID}N, in which
*				UUID is allocated during the
*				first sync, and N is incremented
*				for each request to 'getNewSyncKey'.
*				A sync state is simple an opaque
*				string value that can differ
*				for each backend used - normally
*				a list of items as the backend has
*				sent them to the PIM. The backend
*				can then use this backend
*				information to compute the increments
*				with current data.
*				
*				Old sync states are not deleted
*				until a sync state is requested.
*				At that moment, the PIM is
*				apparently requesting an update
*				since sync key X, so any sync
*				states before X are already on
*				the PIM, and can therefore be
*				removed. This algorithm is
*				automatically enforced by the
*				StateMachine class.
*
*
* Created   :   01.10.2007
*
* © Zarafa Deutschland GmbH, www.zarafaserver.de
* This file is distributed under GPL v2.
* Consult LICENSE file for details
************************************************/


class StateMachine {
    // Gets the sync state for a specified sync key. Requesting a sync key also implies
    // that previous sync states for this sync key series are no longer needed, and the
    // state machine will tidy up these files.
    function getSyncState($synckey) {
        // No sync state for sync key '0'
        if($synckey == "0")
            return "";
            
        // Check if synckey is allowed
        if(!preg_match('/^\{([0-9A-Za-z-]+)\}([0-9]+)$/', $synckey, $matches)) {
            return false;
        }
        
        // Remember synckey GUID and ID
        $guid = $matches[1];
        $n = $matches[2];
        
        // Cleanup all older syncstates
        $dir = opendir(BASE_PATH . STATE_DIR);
        if(!$dir) 
            return false;
            
        while($entry = readdir($dir)) {
            if(preg_match('/^\{([0-9A-Za-z-]+)\}([0-9]+)$/', $entry, $matches)) {
                if($matches[1] == $guid && $matches[2] < $n) {
                    unlink(BASE_PATH . STATE_DIR . "/$entry");
                }
            }
        }
        
        // Read current sync state
        $filename = BASE_PATH . STATE_DIR . "/$synckey";
        
        if(file_exists($filename))
            return file_get_contents(BASE_PATH . STATE_DIR . "/$synckey");
        else return false;
    }
    
    // Gets the new sync key for a specified sync key. You must save the new sync state
    // under this sync key when done sync'ing (by calling setSyncState);
    function getNewSyncKey($synckey) {
        if(!isset($synckey) || $synckey == "0") {
            return "{" . $this->uuid() . "}" . "1";
        } else {
            if(preg_match('/^\{([a-fA-F0-9-]+)\}([0-9]+)$/', $synckey, $matches)) {
                $n = $matches[2];
                $n++;
                return "{" . $matches[1] . "}" . $n;
            } else return false;
        }
    }
    
    // Writes the sync state to a new synckey
    function setSyncState($synckey, $syncstate) {
        // Check if synckey is allowed
        if(!preg_match('/^\{[0-9A-Za-z-]+\}[0-9]+$/', $synckey)) {
            return false;
        }
                                
        return file_put_contents(BASE_PATH . STATE_DIR . "/$synckey", $syncstate);
    }

    function uuid()
    {
        return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                    mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
                    mt_rand( 0, 0x0fff ) | 0x4000,
                    mt_rand( 0, 0x3fff ) | 0x8000,
                    mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ) );
    }
};

?>
