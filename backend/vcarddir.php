<?php
/***********************************************
* File      :   vcarddir.php
* Project   :   Z-Push
* Descr     :   This backend is for vcard
*               directories.
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
include_once('diffbackend.php');

class BackendVCDir extends BackendDiff {
    var $_user;
    var $_devid;
    var $_protocolversion;

    function Setup($user, $devid, $protocolversion) {
        $this->_user = $user;
        $this->_devid = $devid;
        $this->_protocolversion = $protocolversion;

        return true;
    }

    function SendMail($rfc822, $forward = false, $reply = false, $parent = false) {
        return false;
    }

    function GetWasteBasket() {
        return false;
    }

    function GetMessageList($folderid, $cutoffdate) {
        debugLog('VCDir::GetMessageList('.$folderid.')');
        $messages = array();

        $dir = opendir($this->getPath());
        if(!$dir)
            return false;

        while($entry = readdir($dir)) {
            if(is_dir($this->getPath() .'/'.$entry))
                continue;

            $message = array();
            $message["id"] = $entry;
            $stat = stat($this->getPath() .'/'.$entry);
            $message["mod"] = $stat["mtime"];
            $message["flags"] = 1; // always 'read'

            $messages[] = $message;
        }

        return $messages;
    }

    function GetFolderList() {
        debugLog('VCDir::GetFolderList()');
        $contacts = array();
        $folder = $this->StatFolder("root");
        $contacts[] = $folder;

        return $contacts;
    }

    function GetFolder($id) {
        debugLog('VCDir::GetFolder('.$id.')');
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
        debugLog('VCDir::StatFolder('.$id.')');
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
        debugLog('VCDir::StatMessage('.$folderid.', '.$id.')');
        if($folderid != "root")
            return false;

        $stat = stat($this->getPath() . "/" . $id);

        $message = array();
        $message["mod"] = $stat["mtime"];
        $message["id"] = $id;
        $message["flags"] = 1;

        return $message;
    }

    function GetMessage($folderid, $id, $truncsize, $mimesupport = 0) {
        debugLog('VCDir::GetMessage('.$folderid.', '.$id.', ..)');
        if($folderid != "root")
            return;

        $types = array ('dom' => 'type', 'intl' => 'type', 'postal' => 'type', 'parcel' => 'type', 'home' => 'type', 'work' => 'type',
            'pref' => 'type', 'voice' => 'type', 'fax' => 'type', 'msg' => 'type', 'cell' => 'type', 'pager' => 'type',
            'bbs' => 'type', 'modem' => 'type', 'car' => 'type', 'isdn' => 'type', 'video' => 'type',
            'aol' => 'type', 'applelink' => 'type', 'attmail' => 'type', 'cis' => 'type', 'eworld' => 'type',
            'internet' => 'type', 'ibmmail' => 'type', 'mcimail' => 'type',
            'powershare' => 'type', 'prodigy' => 'type', 'tlx' => 'type', 'x400' => 'type',
            'gif' => 'type', 'cgm' => 'type', 'wmf' => 'type', 'bmp' => 'type', 'met' => 'type', 'pmb' => 'type', 'dib' => 'type',
            'pict' => 'type', 'tiff' => 'type', 'pdf' => 'type', 'ps' => 'type', 'jpeg' => 'type', 'qtime' => 'type',
            'mpeg' => 'type', 'mpeg2' => 'type', 'avi' => 'type',
            'wave' => 'type', 'aiff' => 'type', 'pcm' => 'type',
            'x509' => 'type', 'pgp' => 'type', 'text' => 'value', 'inline' => 'value', 'url' => 'value', 'cid' => 'value', 'content-id' => 'value',
            '7bit' => 'encoding', '8bit' => 'encoding', 'quoted-printable' => 'encoding', 'base64' => 'encoding',
        );


        // Parse the vcard
        $message = new SyncContact();

        $data = file_get_contents($this->getPath() . "/" . $id);
        $data = str_replace("\x00", '', $data);
        $data = str_replace("\r\n", "\n", $data);
        $data = str_replace("\r", "\n", $data);
        $data = preg_replace('/(\n)([ \t])/i', '', $data);

        $lines = explode("\n", $data);

        $vcard = array();
        foreach($lines as $line) {
            if (trim($line) == '')
                continue;
            $pos = strpos($line, ':');
            if ($pos === false)
                continue;

            $field = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos+1));

            $fieldparts = preg_split('/(?<!\\\\)(\;)/i', $field, -1, PREG_SPLIT_NO_EMPTY);

            $type = strtolower(array_shift($fieldparts));

            $fieldvalue = array();

            foreach ($fieldparts as $fieldpart) {
                if(preg_match('/([^=]+)=(.+)/', $fieldpart, $matches)){
                    if(!in_array(strtolower($matches[1]),array('value','type','encoding','language')))
                        continue;
                    if(isset($fieldvalue[strtolower($matches[1])]) && is_array($fieldvalue[strtolower($matches[1])])){
                        $fieldvalue[strtolower($matches[1])] = array_merge($fieldvalue[strtolower($matches[1])], preg_split('/(?<!\\\\)(\,)/i', $matches[2], -1, PREG_SPLIT_NO_EMPTY));
                    }else{
                        $fieldvalue[strtolower($matches[1])] = preg_split('/(?<!\\\\)(\,)/i', $matches[2], -1, PREG_SPLIT_NO_EMPTY);
                    }
                }else{
                    if(!isset($types[strtolower($fieldpart)]))
                        continue;
                    $fieldvalue[$types[strtolower($fieldpart)]][] = $fieldpart;
                }
            }
            //
            switch ($type) {
                case 'categories':
                    //case 'nickname':
                    $val = preg_split('/(?<!\\\\)(\,)/i', $value);
                    $val = array_map("w2ui", $val);
                    break;
                default:
                    $val = preg_split('/(?<!\\\\)(\;)/i', $value);
                    break;
            }
            if(isset($fieldvalue['encoding'][0])){
                switch(strtolower($fieldvalue['encoding'][0])){
                    case 'q':
                    case 'quoted-printable':
                        foreach($val as $i => $v){
                            $val[$i] = quoted_printable_decode($v);
                        }
                        break;
                    case 'b':
                    case 'base64':
                        foreach($val as $i => $v){
                            $val[$i] = base64_decode($v);
                        }
                        break;
                }
            }else{
                foreach($val as $i => $v){
                    $val[$i] = $this->unescape($v);
                }
            }
            $fieldvalue['val'] = $val;
            $vcard[$type][] = $fieldvalue;
        }

        if(isset($vcard['email'][0]['val'][0]))
            $message->email1address = $vcard['email'][0]['val'][0];
        if(isset($vcard['email'][1]['val'][0]))
            $message->email2address = $vcard['email'][1]['val'][0];
        if(isset($vcard['email'][2]['val'][0]))
            $message->email3address = $vcard['email'][2]['val'][0];

        if(isset($vcard['tel'])){
            foreach($vcard['tel'] as $tel) {
                if(!isset($tel['type'])){
                    $tel['type'] = array();
                }
                if(in_array('car', $tel['type'])){
                    $message->carphonenumber = $tel['val'][0];
                }elseif(in_array('pager', $tel['type'])){
                    $message->pagernumber = $tel['val'][0];
                }elseif(in_array('cell', $tel['type'])){
                    $message->mobilephonenumber = $tel['val'][0];
                }elseif(in_array('home', $tel['type'])){
                    if(in_array('fax', $tel['type'])){
                        $message->homefaxnumber = $tel['val'][0];
                    }elseif(empty($message->homephonenumber)){
                        $message->homephonenumber = $tel['val'][0];
                    }else{
                        $message->home2phonenumber = $tel['val'][0];
                    }
                }elseif(in_array('work', $tel['type'])){
                    if(in_array('fax', $tel['type'])){
                        $message->businessfaxnumber = $tel['val'][0];
                    }elseif(empty($message->businessphonenumber)){
                        $message->businessphonenumber = $tel['val'][0];
                    }else{
                        $message->business2phonenumber = $tel['val'][0];
                    }
                }elseif(empty($message->homephonenumber)){
                    $message->homephonenumber = $tel['val'][0];
                }elseif(empty($message->home2phonenumber)){
                    $message->home2phonenumber = $tel['val'][0];
                }else{
                    $message->radiophonenumber = $tel['val'][0];
                }
            }
        }
        //;;street;city;state;postalcode;country
        if(isset($vcard['adr'])){
            foreach($vcard['adr'] as $adr) {
                if(empty($adr['type'])){
                    $a = 'other';
                }elseif(in_array('home', $adr['type'])){
                    $a = 'home';
                }elseif(in_array('work', $adr['type'])){
                    $a = 'business';
                }else{
                    $a = 'other';
                }
                if(!empty($adr['val'][2])){
                    $b=$a.'street';
                    $message->$b = w2ui($adr['val'][2]);
                }
                if(!empty($adr['val'][3])){
                    $b=$a.'city';
                    $message->$b = w2ui($adr['val'][3]);
                }
                if(!empty($adr['val'][4])){
                    $b=$a.'state';
                    $message->$b = w2ui($adr['val'][4]);
                }
                if(!empty($adr['val'][5])){
                    $b=$a.'postalcode';
                    $message->$b = w2ui($adr['val'][5]);
                }
                if(!empty($adr['val'][6])){
                    $b=$a.'country';
                    $message->$b = w2ui($adr['val'][6]);
                }
            }
        }

        if(!empty($vcard['fn'][0]['val'][0]))
            $message->fileas = w2ui($vcard['fn'][0]['val'][0]);
        if(!empty($vcard['n'][0]['val'][0]))
            $message->lastname = w2ui($vcard['n'][0]['val'][0]);
        if(!empty($vcard['n'][0]['val'][1]))
            $message->firstname = w2ui($vcard['n'][0]['val'][1]);
        if(!empty($vcard['n'][0]['val'][2]))
            $message->middlename = w2ui($vcard['n'][0]['val'][2]);
        if(!empty($vcard['n'][0]['val'][3]))
            $message->title = w2ui($vcard['n'][0]['val'][3]);
        if(!empty($vcard['n'][0]['val'][4]))
            $message->suffix = w2ui($vcard['n'][0]['val'][4]);
        if(!empty($vcard['bday'][0]['val'][0])){
            $tz = date_default_timezone_get();
            date_default_timezone_set('UTC');
            $message->birthday = strtotime($vcard['bday'][0]['val'][0]);
            date_default_timezone_set($tz);
        }
        if(!empty($vcard['org'][0]['val'][0]))
            $message->companyname = w2ui($vcard['org'][0]['val'][0]);
        if(!empty($vcard['note'][0]['val'][0])){
            $message->body = w2ui($vcard['note'][0]['val'][0]);
            $message->bodysize = strlen($vcard['note'][0]['val'][0]);
            $message->bodytruncated = 0;
        }
        if(!empty($vcard['role'][0]['val'][0]))
            $message->jobtitle = w2ui($vcard['role'][0]['val'][0]);//$vcard['title'][0]['val'][0]
        if(!empty($vcard['url'][0]['val'][0]))
            $message->webpage = w2ui($vcard['url'][0]['val'][0]);
        if(!empty($vcard['categories'][0]['val']))
            $message->categories = $vcard['categories'][0]['val'];

        if(!empty($vcard['photo'][0]['val'][0]))
            $message->picture = base64_encode($vcard['photo'][0]['val'][0]);

        return $message;
    }

    function DeleteMessage($folderid, $id) {
        return unlink($this->getPath() . '/' . $id);
    }

    function SetReadFlag($folderid, $id, $flags) {
        return false;
    }

    function ChangeMessage($folderid, $id, $message) {
        debugLog('VCDir::ChangeMessage('.$folderid.', '.$id.', ..)');
        $mapping = array(
            'fileas' => 'FN',
            'lastname;firstname;middlename;title;suffix' => 'N',
            'email1address' => 'EMAIL;INTERNET',
            'email2address' => 'EMAIL;INTERNET',
            'email3address' => 'EMAIL;INTERNET',
            'businessphonenumber' => 'TEL;WORK',
            'business2phonenumber' => 'TEL;WORK',
            'businessfaxnumber' => 'TEL;WORK;FAX',
            'homephonenumber' => 'TEL;HOME',
            'home2phonenumber' => 'TEL;HOME',
            'homefaxnumber' => 'TEL;HOME;FAX',
            'mobilephonenumber' => 'TEL;CELL',
            'carphonenumber' => 'TEL;CAR',
            'pagernumber' => 'TEL;PAGER',
            ';;businessstreet;businesscity;businessstate;businesspostalcode;businesscountry' => 'ADR;WORK',
            ';;homestreet;homecity;homestate;homepostalcode;homecountry' => 'ADR;HOME',
            ';;otherstreet;othercity;otherstate;otherpostalcode;othercountry' => 'ADR',
            'companyname' => 'ORG',
            'body' => 'NOTE',
            'jobtitle' => 'ROLE',
            'webpage' => 'URL',
        );
        $data = "BEGIN:VCARD\nVERSION:2.1\nPRODID:Z-Push\n";
        foreach($mapping as $k => $v){
            $val = '';
            $ks = split(';', $k);
            foreach($ks as $i){
                if(!empty($message->$i))
                    $val .= $this->escape($message->$i);
                $val.=';';
            }
            if(empty($val))
                continue;
            $val = substr($val,0,-1);
            if(strlen($val)>50){
                $data .= $v.":\n\t".substr(chunk_split($val, 50, "\n\t"), 0, -1);
            }else{
                $data .= $v.':'.$val."\n";
            }
        }
        if(!empty($message->categories))
            $data .= 'CATEGORIES:'.implode(',', $this->escape($message->categories))."\n";
        if(!empty($message->picture))
            $data .= 'PHOTO;ENCODING=BASE64;TYPE=JPEG:'."\n\t".substr(chunk_split($message->picture, 50, "\n\t"), 0, -1);
        if(isset($message->birthday))
            $data .= 'BDAY:'.date('Y-m-d', $message->birthday)."\n";
        $data .= "END:VCARD";

// not supported: anniversary, assistantname, assistnamephonenumber, children, department, officelocation, radiophonenumber, spouse, rtf

        if(!$id){
            if(!empty($message->fileas)){
                $name = u2wi($message->fileas);
            }elseif(!empty($message->lastname)){
                $name = $name = u2wi($message->lastname);
            }elseif(!empty($message->firstname)){
                $name = $name = u2wi($message->firstname);
            }elseif(!empty($message->companyname)){
                $name = $name = u2wi($message->companyname);
            }else{
                $name = 'unknown';
            }
            $name = preg_replace('/[^a-z0-9 _-]/i', '', $name);
            $id = $name.'.vcf';
            $i = 0;
            while(file_exists($this->getPath().'/'.$id)){
                $i++;
                $id = $name.$i.'.vcf';
            }
        }
        file_put_contents($this->getPath().'/'.$id, $data);
        return $this->StatMessage($folderid, $id);
    }

    function MoveMessage($folderid, $id, $newfolderid) {
        return false;
    }

    // -----------------------------------

    function getPath() {
        return str_replace('%u', $this->_user, VCARDDIR_DIR);
    }

    function escape($data){
        if (is_array($data)) {
            foreach ($data as $key => $val) {
                $data[$key] = $this->escape($val);
            }
            return $data;
        }
        $data = str_replace("\r\n", "\n", $data);
        $data = str_replace("\r", "\n", $data);
        $data = str_replace(array('\\', ';', ',', "\n"), array('\\\\', '\\;', '\\,', '\\n'), $data);
        return u2wi($data);
    }

    function unescape($data){
        $data = str_replace(array('\\\\', '\\;', '\\,', '\\n','\\N'),array('\\', ';', ',', "\n", "\n"),$data);
        return $data;
    }
};
?>