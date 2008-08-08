<?php
/***********************************************
* File      :   streamimporter.php
* Project   :   Z-Push
* Descr     :   Stream import classes
*
* Created   :   01.10.2007
*
* © Zarafa Deutschland GmbH, www.zarafaserver.de
* This file is distributed under GPL v2.
* Consult LICENSE file for details
************************************************/

// We don't support caching changes for messages
class ImportContentsChangesStream {
    var $_encoder;
    
    function ImportContentsChangesStream(&$encoder, $type) {
        $this->_encoder = &$encoder;
        $this->_type = $type;
    }
    
    function ImportMessageChange($id, $message) {
        if(strtolower(get_class($message)) != $this->_type)
            return true; // ignore other types

		if ($message->flags === false || $message->flags === SYNC_NEWMESSAGE)           
        	$this->_encoder->startTag(SYNC_ADD);
        else
        	$this->_encoder->startTag(SYNC_MODIFY);
        	
        $this->_encoder->startTag(SYNC_SERVERENTRYID);
        $this->_encoder->content($id);
        $this->_encoder->endTag();
        $this->_encoder->startTag(SYNC_DATA);
        $message->encode($this->_encoder);
        $this->_encoder->endTag();
        $this->_encoder->endTag();
        
        return true;
    }
    
    function ImportMessageDeletion($id) {
        $this->_encoder->startTag(SYNC_REMOVE);
        $this->_encoder->startTag(SYNC_SERVERENTRYID);
        $this->_encoder->content($id);
        $this->_encoder->endTag();
        $this->_encoder->endTag();
        
        return true;
    }
    
    function ImportMessageReadFlag($id, $flags) {
        if($this->_type != "syncmail")
            return true;
        $this->_encoder->startTag(SYNC_MODIFY);
            $this->_encoder->startTag(SYNC_SERVERENTRYID);
                $this->_encoder->content($id);
            $this->_encoder->endTag();
            $this->_encoder->startTag(SYNC_DATA);
                $this->_encoder->startTag(SYNC_POOMMAIL_READ);
                    $this->_encoder->content($flags);
                $this->_encoder->endTag();
            $this->_encoder->endTag();
        $this->_encoder->endTag();
        
        return true;
    }

    function ImportMessageMove($message) {
        return true;
    }
};

class ImportHierarchyChangesStream {
    
    function ImportHierarchyChangesStream() {
        return true;
    }
    
    function ImportFolderChange($folder) {
        return true;
    }

    function ImportFolderDeletion($folder) {
        return true;
    }
};

?>