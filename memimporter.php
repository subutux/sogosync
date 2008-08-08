<?php
/***********************************************
* File      :   memimporter.php
* Project   :   Z-Push
* Descr     :   Classes that collect changes
*
* Created   :   01.10.2007
*
* © Zarafa Deutschland GmbH, www.zarafaserver.de
* This file is distributed under GPL v2.
* Consult LICENSE file for details
************************************************/

class ImportContentsChangesMem extends ImportContentsChanges {
    function ImportContentsChangesMem() {
    }
    
    function ImportMessageChange($message) { return true; }

    function ImportMessageDeletion($message) { return true; }
    
    function ImportMessageReadFlag($message) { return true; }

    function ImportMessageMove($message) { return true; }
};

// This simply collects all changes so that they can be retrieved later, for
// statistics gathering for example
class ImportHierarchyChangesMem extends ImportHierarchyChanges {
    var $changed;
    var $deleted;
    var $count;
    
    function ImportHierarchyChangesMem() {
        $this->changed = array();
        $this->deleted = array();
        $this->count = 0;
        
        return true;
    }
    
    function ImportFolderChange($folder) {
        array_push($this->changed, $folder);
        
        $this->count++;

        return true;
    }

    function ImportFolderDeletion($id) {
        array_push($this->deleted, $id);
        
        $this->count++;
        
        return true;
    }
};

?>