<?php
/***********************************************
* File      :   backend.php
* Project   :   Z-Push
* Descr     :   This is what C++ people
*				(and PHP5) would call an
*				abstract class. All backend
*				modules should adhere to this
*				specification. All communication
*				with this module is done via
*				the Sync* object types, the
*				backend module itself is
*				responsible for converting any
*				necessary types and formats.
*
*				If you wish to implement a new
*				backend, all you need to do is
*				to subclass the following class,
*				and place the subclassed file in
*				the backend/ directory. You can
*				then use your backend by
*				specifying it in the config.php file
*
*
* Created   :   01.10.2007
*
* © Zarafa Deutschland GmbH, www.zarafaserver.de
* This file is distributed under GPL v2.
* Consult LICENSE file for details
************************************************/

define('EXPORT_HIERARCHY', 1);
define('EXPORT_CONTENTS', 2);

define('BACKEND_DISCARD_DATA', 1);

class ImportContentsChanges {
    function ImportMessageChange($message) {}

    function ImportMessageDeletion($message) {}
    
    function ImportMessageReadStateChange($message) {}

    function ImportMessageMove($message) {}
};

class ImportHierarchyChanges {
    function ImportFolderChange($folder) {}

    function ImportFolderDeletion($folder) {}
};

class ExportChanges {
    // Exports (returns) changes since '$synckey' as an array of Sync* objects. $flags
    // can be EXPORT_HIERARCHY or EXPORT_CONTENTS. $restrict contains the restriction on which
    // messages should be filtered. Synckey is updated via reference (!)
    function ExportChanges($importer, $folderid, $restrict, $syncstate, $flags) {}
};

class Backend {
    var $hierarchyimporter;
    var $contentsimporter;
    var $exporter;
    
    // Returns TRUE if the logon succeeded, FALSE if not
    function Logon($username, $domain, $password) {}
    
    // called before closing connection
    function Logoff() {}

    // Returns an array of SyncFolder types for the entire folder hierarchy
    // on the server (the array itself is flat, but refers to parents via the 'parent'
    // property)
    function GetHierarchy() {}
    
    // Called when a message has to be sent and the message needs to be saved to the 'sent items'
    // folder
    function SendMail($rfc822, $forward = false, $reply = false, $parent = false) {}
};

?>