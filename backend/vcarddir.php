<?
/***********************************************
* File      :   vcarddir.php
* Project   :   Z-Push
* Descr     :   This backend is for vcard
*				directories.
*
* Created   :   01.10.2007
*
* © Zarafa Deutschland GmbH, www.zarafaserver.de
* This file is distributed under GPL v2.
* Consult LICENSE file for details
************************************************/
include_once('diffbackend.php');

class BackendVCDir extends BackendDiff {

    function SendMail($rfc822, $forward = false, $reply = false, $parent = false) {
        return true;
    }
    
    function GetWasteBasket() {
        return false;
    }
    
    function GetMessageList($folderid) {
        $messages = array();
        
        $dir = opendir($this->getPath());
        if(!$dir)
            return false;
            
        while($entry = readdir($dir)) {
            if($entry{0} == '.')
                continue;
         
            $message = array();
            $message["id"] = $entry;
            $stat = stat($this->getPath() ."/".$entry);
            $message["mod"] = $stat["mtime"];
            $message["flags"] = 1; // always 'read'
            
            $messages[] = $message;
        }
        
        return $messages;
    }
    
    function GetFolderList() {
        $contacts = array();
        $folder = $this->StatFolder("root");
        $contacts[] = $folder;

        return $contacts;
    }
    
    function GetFolder($id) {
        if($id == "root") {
            $folder = new SyncFolder();
            $folder->serverid = $id;
            $folder->parentid = "0";
            $folder->displayname = "Contacts";
            $folder->type = SYNC_FOLDER_TYPE_CONTACT;
            
            return $folder;
        } else return false;
    }
    
    function StatFolder($id) {
        $folder = $this->GetFolder($id);
        
        $stat = array();
        $stat["id"] = $id;
        $stat["parent"] = $folder->parentid;
        $stat["mod"] = $folder->displayname;
        
        return $stat;
    }
    
    function GetAttachmentData($attname) {
        return false;
    }

    function StatMessage($folderid, $id) {
        if($folderid != "root")
            return false;
            
        $stat = stat($this->getPath() . "/" . $id);
        
        $message = array();
        $message["mod"] = $stat["mtime"];
        $message["id"] = $id;
        $message["flags"] = 1;
        
        return $message;
    }
    
    function GetMessage($folderid, $id) {
        if($folderid != "root")
            return;

        // Parse the vcard            
        $message = new SyncContact();
        $data = file_get_contents($this->getPath() . "/" . $id);
        
        $lines = explode("\n", $data);
        
        $vcard = array();
        
        foreach($lines as $line) {
            // remove cr
            $line = str_replace("\r","",$line);
            
            if(preg_match("/([^:]+):(.*)/", $line, $matches)) {
                $field = $matches[1];
                $value = $matches[2];
                
                $fieldparts = explode(";", $field);
                
                $type = strtolower(array_shift($fieldparts));
                
                $fieldvalue = array();
                $fieldvalue["value"] = explode(";", $value);
                foreach($fieldparts as $fieldpart) {
                    if(preg_match("/([^=]+)=(.+)/", $fieldpart, $matches))
                        $fieldvalue[strtolower($matches[1])] = strtolower($matches[2]);
                    else
                        $fieldvalue[strtolower($fieldpart)] = true;
                }
                  
                if(!isset($vcard[$type]))
                    $vcard[$type] = array();
                    
                array_push($vcard[$type], $fieldvalue);
            }
        }
        
        // Each entry is put in $vcard[HEADERTYPE], which is an array for multiple lines
        // Each of these entries contains 'value', an array of all the values after the colon
        // and any other options (eg. type=work) are stored in their respective keys
        
        // eg.
        //
        // $vcard = array( "tel" => array ( "type" => "work", value => array ("123456789") ), array ( "type" => "home", "value" => array ("987654321") ) ) );
        
        $message->email1address = $vcard["email"][0]["value"][0];
        $message->email2address = $vcard["email"][1]["value"][0];
        $message->email3address = $vcard["email"][2]["value"][0];
        
        foreach($vcard["tel"] as $tel) {
            if($tel["type"] == "work")
                $message->businessphonenumber = $tel["value"][0];
            if($tel["type"] == "home")
                $message->homephonenumber = $tel["value"][0];
            if($tel["type"] == "cell")
                $message->mobilephonenumber = $tel["value"][0];
        }
        $message->lastname = $vcard["n"][0]["value"][0];
        $message->firstname = $vcard["n"][0]["value"][1];
        $message->middlename = $vcard["n"][0]["value"][2];
        $message->birthday = $vcard["bday"][0]["value"][0];
        $message->companyname = $vcard["org"][0]["value"][0];
        $message->body = $vcard["note"][0]["value"][0];
        $message->bodysize = strlen($message["body"]);
        $message->bodytruncated = 0;
        $message->jobtitle = $vcard["role"][0]["value"][0];
        $message->title = $vcard["title"][0]["value"][0];
        $message->categories = explode('\,', $vcard["categories"][0]["value"][0]);
        
        return $message;
    }
    
    function DeleteMessage($folderid, $id) {
        return unlink($this->getPath() . "/" . $id);
    }
    
    function SetReadFlag($folderid, $id, $flags) {
        return false;
    }
    
    function ChangeMessage($folderid, $id, $message) {
        return false;
    }
    
    function MoveMessage($folderid, $id, $newfolderid) {
        return false;
    }

    // -----------------------------------
    
    function getPath() {
        return "/root/" . VCARDDIR_SUBDIR;
        return VCARDDIR_BASE . "/" . $this->_user . "/" . VCARDDIR_SUBDIR;
    }
};


?>