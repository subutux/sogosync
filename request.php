<?php
/***********************************************
* File      :   request.php
* Project   :   Z-Push
* Descr     :   This file contains the actual
*				request handling routines.
*				The request handlers are optimised
*				so that as little as possible
*				data is kept-in-memory, and all
*				output data is directly streamed
*				to the client, while also streaming
*				input data from the client.
*
* Created   :   01.10.2007
*
*  Zarafa Deutschland GmbH, www.zarafaserver.de
* This file is distributed under GPL v2.
* Consult LICENSE file for details
************************************************/

include_once("proto.php");
include_once("wbxml.php");
include_once("statemachine.php");
include_once("backend/backend.php");
include_once("memimporter.php");
include_once("streamimporter.php");
include_once("zpushdtd.php");
include_once("zpushdefs.php");
include_once("include/utils.php");

function GetObjectClassFromFolderClass($folderclass)
{
    $classes = array ( "Email" => "syncmail", "Contacts" => "synccontact", "Calendar" => "syncappointment", "Tasks" => "synctask" );
    
    return $classes[$folderclass];
}

function HandleMoveItems($backend, $protocolversion) {
    global $zpushdtd;
    global $input, $output;
    
    $decoder = new WBXMLDecoder($input, $zpushdtd);
    $encoder = new WBXMLEncoder($output, $zpushdtd);

    if(!$decoder->getElementStartTag(SYNC_MOVE_MOVES))
        return false;

    $moves = array();
    while($decoder->getElementStartTag(SYNC_MOVE_MOVE)) {
        $move = array();
        if($decoder->getElementStartTag(SYNC_MOVE_SRCMSGID)) {
            $move["srcmsgid"] = $decoder->getElementContent();
            if(!$decoder->getElementEndTag())
                break;
        }
        if($decoder->getElementStartTag(SYNC_MOVE_SRCFLDID)) {
            $move["srcfldid"] = $decoder->getElementContent();
            if(!$decoder->getElementEndTag())
                break;
        }
        if($decoder->getElementStartTag(SYNC_MOVE_DSTFLDID)) {
            $move["dstfldid"] = $decoder->getElementContent();
            if(!$decoder->getElementEndTag())
                break;
        }
        array_push($moves, $move);

        if(!$decoder->getElementEndTag())
            return false;
    }

    if(!$decoder->getElementEndTag())
        return false;

    $encoder->StartWBXML();

    $encoder->startTag(SYNC_MOVE_MOVES);

    foreach($moves as $move) {
        $encoder->startTag(SYNC_MOVE_RESPONSE);
        $encoder->startTag(SYNC_MOVE_SRCMSGID);
        $encoder->content($move["srcmsgid"]);
        $encoder->endTag();

        $importer = $backend->GetContentsImporter($move["srcfldid"]);
        $result = $importer->ImportMessageMove($move["srcmsgid"], $move["dstfldid"]);
        // We discard the importer state for now.

        $encoder->startTag(SYNC_MOVE_STATUS);
        $encoder->content($result ? 3 : 1);
        $encoder->endTag();

        $encoder->startTag(SYNC_MOVE_DSTMSGID);
        $encoder->content(is_string($result)?$result:$move["srcmsgid"]);
        $encoder->endTag();
        $encoder->endTag();
    }

    $encoder->endTag();
    return true;
}

function HandleNotify($backend, $protocolversion) {
    global $zpushdtd;
    global $input, $output;
    
    $decoder = new WBXMLDecoder($input, $zpushdtd);
    $encoder = new WBXMLEncoder($output, $zpushdtd);
    
    if(!$decoder->getElementStartTag(SYNC_AIRNOTIFY_NOTIFY))
        return false;
        
    if(!$decoder->getElementStartTag(SYNC_AIRNOTIFY_DEVICEINFO))
        return false;
        
    if(!$decoder->getElementEndTag())
        return false;
        
    if(!$decoder->getElementEndTag())
        return false;

    $encoder->StartWBXML();

    $encoder->startTag(SYNC_AIRNOTIFY_NOTIFY);
    {
        $encoder->startTag(SYNC_AIRNOTIFY_STATUS);
        $encoder->content(1);
        $encoder->endTag();
        
        $encoder->startTag(SYNC_AIRNOTIFY_VALIDCARRIERPROFILES);
        $encoder->endTag();
    }
    
    $encoder->endTag();
        
    return true;
    
}

// Handle GetHierarchy method - simply returns current hierarchy of all folders
function HandleGetHierarchy($backend, $protocolversion, $devid) {
    global $zpushdtd;
    global $output;
    
    // Input is ignored, no data is sent by the PIM
    $encoder = new WBXMLEncoder($output, $zpushdtd);

    $folders = $backend->GetHierarchy();

    if(!$folders)
        return false;

	// save folder-ids for fourther syncing 
	_saveFolderData($devid, $folders);

    $encoder->StartWBXML();
    $encoder->startTag(SYNC_FOLDERHIERARCHY_FOLDERS);
    
    foreach ($folders as $folder) {
    	$encoder->startTag(SYNC_FOLDERHIERARCHY_FOLDER);
        $folder->encode($encoder);
        $encoder->endTag();
    }
    
    $encoder->endTag();
    return true;
}

// Handles a 'FolderSync' method - receives folder updates, and sends reply with
// folder changes on the server
function HandleFolderSync($backend, $protocolversion) {
    global $zpushdtd;
    global $input, $output;

    // Maps serverid -> clientid for items that are received from the PIM
    $map = array();
    
    $decoder = new WBXMLDecoder($input, $zpushdtd);
    $encoder = new WBXMLEncoder($output, $zpushdtd);

    // Parse input

    if(!$decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_FOLDERSYNC))
        return false;
        
    if(!$decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_SYNCKEY))
        return false;
        
    $synckey = $decoder->getElementContent();
    
    if(!$decoder->getElementEndTag())
        return false;
    
    // First, get the syncstate that is associated with this synckey
    $statemachine = new StateMachine();
    
    // The state machine will discard any sync states before this one, as they are no
    // longer required
    $syncstate = $statemachine->getSyncState($synckey);
    
    // We will be saving the sync state under 'newsynckey'
    $newsynckey = $statemachine->getNewSyncKey($synckey);
    
    if($decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_CHANGES)) {
        // Ignore <Count> if present
        if($decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_COUNT)) {
            $decoder->getElementContent();
            if(!$decoder->getElementEndTag())
                return false;
        }
        
        // Process the changes (either <Add>, <Modify>, or <Remove>)
        $element = $decoder->getElement();

        if($element[EN_TYPE] != EN_TYPE_STARTTAG)
            return false;
        
        while(1) {
            $folder = new SyncFolder();
            if(!$folder->decode($decoder))
                break;

            // Configure importer with last state
            $importer = $backend->GetHierarchyImporter();
            $importer->Config($syncstate);


            switch($element[EN_TAG]) {
                case SYNC_ADD:
                case SYNC_MODIFY:
                    $serverid = $importer->ImportFolderChange($folder);
                    break;
                case SYNC_REMOVE:
                    $serverid = $importer->ImportFolderDeletion($folder);
                    break;
            }
            
            if($serverid)
                $map[$serverid] = $folder->clientid;
        }
    
        if(!$decoder->getElementEndTag())
            return false;
    }
    
    if(!$decoder->getElementEndTag())
        return false;

    // We have processed incoming foldersync requests, now send the PIM
    // our changes
    
    // The MemImporter caches all imports in-memory, so we can send a change count
    // before sending the actual data. As the amount of data done in this operation
    // is rather low, this is not memory problem. Note that this is not done when
    // sync'ing messages - we let the exporter write directly to WBXML.
    $importer = new ImportHierarchyChangesMem($encoder);
    
    // Request changes from backend, they will be sent to the MemImporter passed as the first
    // argument, which stores them in $importer. Returns the new sync state for this exporter.
    $exporter = $backend->GetExporter();
    
    $exporter->Config($importer, false, false, $syncstate, 0, 0);

    while(is_array($exporter->Synchronize()));

    // Output our WBXML reply now
    $encoder->StartWBXML();
    
    $encoder->startTag(SYNC_FOLDERHIERARCHY_FOLDERSYNC);
    {
        $encoder->startTag(SYNC_FOLDERHIERARCHY_STATUS);
        $encoder->content(1);
        $encoder->endTag();

        $encoder->startTag(SYNC_FOLDERHIERARCHY_SYNCKEY);
        $encoder->content($newsynckey);
        $encoder->endTag();
        
        $encoder->startTag(SYNC_FOLDERHIERARCHY_CHANGES);
        {  
            $encoder->startTag(SYNC_FOLDERHIERARCHY_COUNT);
            $encoder->content($importer->count);
            $encoder->endTag();
            
            if(count($importer->changed) > 0) {
                foreach($importer->changed as $folder) {
                    $encoder->startTag(SYNC_FOLDERHIERARCHY_ADD);
                    $folder->encode($encoder);
                    $encoder->endTag();
                }
            }

            if(count($importer->deleted) > 0) {
                foreach($importer->deleted as $folder) {
                    $encoder->startTag(SYNC_FOLDERHIERARCHY_REMOVE);
                        $encoder->startTag(SYNC_FOLDERHIERARCHY_SERVERENTRYID);
                            $encoder->content($folder);
                        $encoder->endTag();
                    $encoder->endTag();
                }
            }
        }
        $encoder->endTag();
    }
    $encoder->endTag();
    
    // Save the sync state for the next time
    $syncstate = $exporter->GetState();
    $statemachine->setSyncState($newsynckey, $syncstate);


    return true;
}

function HandleSync($backend, $protocolversion, $devid) {
    global $zpushdtd;
    global $input, $output;

    // Contains all containers requested    
    $collections = array();
    
    // Init WBXML decoder
    $decoder = new WBXMLDecoder($input, $zpushdtd);

    // Init state machine
    $statemachine = new StateMachine();

    // Start decode
    if(!$decoder->getElementStartTag(SYNC_SYNCHRONIZE))
        return false;
    
    if(!$decoder->getElementStartTag(SYNC_FOLDERS))
        return false;

    while($decoder->getElementStartTag(SYNC_FOLDER))
    {
        $collection = array();
        $collection["truncation"] = SYNC_TRUNCATION_ALL;
        $collection["clientids"] = array();
        $collection["fetchids"] = array();

        if(!$decoder->getElementStartTag(SYNC_FOLDERTYPE))
            return false;
            
        $collection["class"] = $decoder->getElementContent();
        
        if(!$decoder->getElementEndTag())
            return false;
            
        if(!$decoder->getElementStartTag(SYNC_SYNCKEY))
            return false;
            
        $collection["synckey"] = $decoder->getElementContent();
        
        if(!$decoder->getElementEndTag())
            return false;
              
		if($decoder->getElementStartTag(SYNC_FOLDERID)) {
	        $collection["collectionid"] = $decoder->getElementContent();
	        
	        if(!$decoder->getElementEndTag())
	            return false;
		}
		            
        if($decoder->getElementStartTag(SYNC_SUPPORTED)) {
            while(1) {
                $el = $decoder->getElement();
                if($el[EN_TYPE] == EN_TYPE_ENDTAG)
                    break;
            }
        }

        if($decoder->getElementStartTag(SYNC_DELETESASMOVES))
            $collection["deletesasmoves"] = true;
            
        if($decoder->getElementStartTag(SYNC_GETCHANGES))
            $collection["getchanges"] = true;
            
        if($decoder->getElementStartTag(SYNC_MAXITEMS)) {
            $collection["maxitems"] = $decoder->getElementContent();
            if(!$decoder->getElementEndTag())
                return false;
        }
        
        if($decoder->getElementStartTag(SYNC_OPTIONS)) {
			while(1) {        	
	            if($decoder->getElementStartTag(SYNC_FILTERTYPE)) {
	                $collection["filtertype"] = $decoder->getElementContent();
	                if(!$decoder->getElementEndTag())
	                    return false;
	            }
	            if($decoder->getElementStartTag(SYNC_TRUNCATION)) {
	                $collection["truncation"] = $decoder->getElementContent();
	                if(!$decoder->getElementEndTag())
	                    return false;
	            }
	            if($decoder->getElementStartTag(SYNC_RTFTRUNCATION)) {
	                $collection["rtftruncation"] = $decoder->getElementContent();
	                if(!$decoder->getElementEndTag())
	                    return false;
	            }
	            
	            if($decoder->getElementStartTag(SYNC_MIMESUPPORT)) {
	                $collection["mimesupport"] = $decoder->getElementContent();
	                if(!$decoder->getElementEndTag())
	                    return false;
	            }
	            
	            if($decoder->getElementStartTag(SYNC_MIMETRUNCATION)) {
	                $collection["mimetruncation"] = $decoder->getElementContent();
	                if(!$decoder->getElementEndTag())
	                    return false;
	            }
	            
	            if($decoder->getElementStartTag(SYNC_CONFLICT)) {
	                $collection["conflict"] = $decoder->getElementContent();
	                if(!$decoder->getElementEndTag())
	                    return false;
	            }
	            $e = $decoder->peek();
	            if($e[EN_TYPE] == EN_TYPE_ENDTAG) {
					$decoder->getElementEndTag();	            	
                	break;
	            }
			}  
        }
        
        // compatibility mode - get folderid from the state directory
        if (!isset($collection["collectionid"])) {
        	$collection["collectionid"] = _getFolderID($devid, $collection["class"]);
        }
        
        // compatibility mode - set default conflict behavior if no conflict resolution algorithm is set (OVERWRITE_PIM)
        if (!isset($collection["conflict"])) {
        	$collection["conflict"] = 1;
        }
                
        // Get our sync state for this collection
        $collection["syncstate"] = $statemachine->getSyncState($collection["synckey"]);
        if($decoder->getElementStartTag(SYNC_PERFORM)) {

            // Configure importer with last state
            $importer = $backend->GetContentsImporter($collection["collectionid"]);
            $importer->Config($collection["syncstate"], $collection["conflict"]);

            $nchanges = 0;
            while(1) {
                $element = $decoder->getElement(); // MODIFY or REMOVE or ADD or FETCH
                
                if($element[EN_TYPE] != EN_TYPE_STARTTAG) {
                    $decoder->ungetElement($element);
                    break;
                }
                    
                $nchanges++;

                if($decoder->getElementStartTag(SYNC_SERVERENTRYID)) {
                    $serverid = $decoder->getElementContent();
                
                    if(!$decoder->getElementEndTag()) // end serverid
                        return false;
                } else {
                    $serverid = false;
                }

                if($decoder->getElementStartTag(SYNC_CLIENTENTRYID)) {
                    $clientid = $decoder->getElementContent();
                
                    if(!$decoder->getElementEndTag()) // end clientid
                        return false;
                } else {
                    $clientid = false;
                }

                // Get application data if available
                if($decoder->getElementStartTag(SYNC_DATA)) {
                    switch($collection["class"]) {
                    case "Email":
                        $appdata = new SyncMail();
                        $appdata->decode($decoder);
                        break;
                    case "Contacts":
                        $appdata = new SyncContact($protocolversion);
                        $appdata->decode($decoder);
                        break;
                    case "Calendar":
                        $appdata = new SyncAppointment();
                        $appdata->decode($decoder);
                        break;
                    case "Tasks":
                        $appdata = new SyncTask();
                        $appdata->decode($decoder);
                        break;
                    }
                    if(!$decoder->getElementEndTag()) // end applicationdata
                        return false;
                    
                }                
                
                switch($element[EN_TAG]) {
                case SYNC_MODIFY:
                    if(isset($appdata)) {
                        if(isset($appdata->read)) // Currently, 'read' is only sent by the PDA when it is ONLY setting the read flag.
                            $importer->ImportMessageReadFlag($serverid, $appdata->read);
                        else
                            $importer->ImportMessageChange($serverid, $appdata);
                        $collection["importedchanges"] = true;
                    }
                    break;
                case SYNC_ADD:
                    if(isset($appdata)) {
                        $id = $importer->ImportMessageChange(false, $appdata);
                    
                        if($clientid && $id) {
                            $collection["clientids"][$clientid] = $id;
                            $collection["importedchanges"] = true;
                        }
                    }
                    break;
                case SYNC_REMOVE:
                    if(isset($collection["deletesasmoves"])) {
                        $folderid = $backend->GetWasteBasket();
                        
                        if($folderid) {
                            $importer->ImportMessageMove($serverid, $folderid);
                            $collection["importedchanges"] = true;
                            break;
                        }
                    }
                    
                    $importer->ImportMessageDeletion($serverid);
                    $collection["importedchanges"] = true;                    
                    break;
                case SYNC_FETCH:
                    array_push($collection["fetchids"], $serverid);
                    break;
                }
                
                if(!$decoder->getElementEndTag()) // end change/delete/move
                    return false;
            }

            debugLog("Processed $nchanges incoming changes");
            
            // Save the updated state, which is used for the exporter later
            $collection["syncstate"] = $importer->getState();

                    
            if(!$decoder->getElementEndTag()) // end commands
                return false;
        }
            
        if(!$decoder->getElementEndTag()) // end collection
            return false;
            
        array_push($collections, $collection);
    } 

    if(!$decoder->getElementEndTag()) // end collections
        return false;
        
    if(!$decoder->getElementEndTag()) // end sync
        return false;
        
    $encoder = new WBXMLEncoder($output, $zpushdtd);
    $encoder->startWBXML();

    $encoder->startTag(SYNC_SYNCHRONIZE);
    {
        $encoder->startTag(SYNC_FOLDERS);
        {
            foreach($collections as $collection) {
                // Get a new sync key to output to the client if any changes have been requested or have been sent
                if (isset($collection["importedchanges"]) || isset($collection["getchanges"]) || $collection["synckey"] == "0")
                    $collection["newsynckey"] = $statemachine->getNewSyncKey($collection["synckey"]);
                
                $encoder->startTag(SYNC_FOLDER);
                
                $encoder->startTag(SYNC_FOLDERTYPE);
                $encoder->content($collection["class"]);
                $encoder->endTag();
                
                $encoder->startTag(SYNC_SYNCKEY);
                
                if(isset($collection["newsynckey"]))
                    $encoder->content($collection["newsynckey"]);
                else
                    $encoder->content($collection["synckey"]);
                
                $encoder->endTag();
                
                $encoder->startTag(SYNC_FOLDERID);
                $encoder->content($collection["collectionid"]);
                $encoder->endTag();
                
                $encoder->startTag(SYNC_STATUS);
                $encoder->content(1);
                $encoder->endTag();
                
                // Output server IDs for new items we received from the PDA
                if(isset($collection["clientids"]) || count($collection["fetchids"]) > 0) {
                    $encoder->startTag(SYNC_REPLIES);
                    foreach($collection["clientids"] as $clientid => $serverid) {
                        $encoder->startTag(SYNC_ADD);
                        $encoder->startTag(SYNC_CLIENTENTRYID);
                        $encoder->content($clientid);
                        $encoder->endTag();
                        $encoder->startTag(SYNC_SERVERENTRYID);
                        $encoder->content($serverid);
                        $encoder->endTag();
                        $encoder->startTag(SYNC_STATUS);
                        $encoder->content(1);
                        $encoder->endTag();
                        $encoder->endTag();
                    }
                    foreach($collection["fetchids"] as $id) {
                        $data = $backend->Fetch($collection["collectionid"], $id);
                        if($data !== false) {
                            $encoder->startTag(SYNC_FETCH);
                            $encoder->startTag(SYNC_SERVERENTRYID);
                            $encoder->content($id);
                            $encoder->endTag();
                            $encoder->startTag(SYNC_STATUS);
                            $encoder->content(1);
                            $encoder->endTag();
                            $encoder->startTag(SYNC_DATA);
                            $data->encode($encoder);
                            $encoder->endTag();
                            $encoder->endTag();
                        } else {
                            debugLog("unable to fetch $id");
                        }
                    }
                    $encoder->endTag();
                }    
                
                if(isset($collection["getchanges"])) {
                    // Use the state from the importer, as changes may have already happened
                    $exporter = $backend->GetExporter($collection["collectionid"]);
                    
                    $filtertype = isset($collection["filtertype"]) ? $collection["filtertype"] : false;
                    $exporter->Config($importer, $collection["class"], $filtertype, $collection["syncstate"], 0, $collection["truncation"]);

                    $changecount = $exporter->GetChangeCount();

                    if($changecount > $collection["maxitems"]) {
                        $encoder->startTag(SYNC_MOREAVAILABLE, false, true);
                    }

                    // Output message changes per folder
                    $encoder->startTag(SYNC_PERFORM);

                    // Stream the changes to the PDA
                    $importer = new ImportContentsChangesStream($encoder, GetObjectClassFromFolderClass($collection["class"]));

                    $filtertype = isset($collection["filtertype"]) ? $collection["filtertype"] : 0;

                    $n = 0;
                    while(1) { 
                        $progress = $exporter->Synchronize();
                        if(!is_array($progress))
                            break;
                        $n++;
                        
                        if($n >= $collection["maxitems"])
                            break;
                            
                    }
                    $encoder->endTag();
                }

                $encoder->endTag();

                // Save the sync state for the next time
                if(isset($collection["newsynckey"])) {
                	if (isset($exporter) && $exporter)  	              
                    	$state = $exporter->GetState();
	
	                // nothing exported, but possible imported
                    else if (isset($importer) && $importer)  
                    	$state = $importer->GetState();
                    	
                    // if a new request without state information (hierarchy) save an empty state
                    else if ($collection["synckey"] == "0")
                    	$state = "";
                    
                    if (isset($state)) $statemachine->setSyncState($collection["newsynckey"], $state);
                    else debugLog("error saving " . $collection["newsynckey"] . " - no state information available");
                }
            }
        }
        $encoder->endTag();
    }
    $encoder->endTag();
            
    return true;
}

function HandleGetItemEstimate($backend, $protocolversion, $devid) {
    global $zpushdtd;
    global $input, $output;
    
    $collections = array();
    
    $decoder = new WBXMLDecoder($input, $zpushdtd);
    $encoder = new WBXMLEncoder($output, $zpushdtd);

    if(!$decoder->getElementStartTag(SYNC_GETITEMESTIMATE_GETITEMESTIMATE))
        return false;
    
    if(!$decoder->getElementStartTag(SYNC_GETITEMESTIMATE_FOLDERS))
        return false;
        
    while($decoder->getElementStartTag(SYNC_GETITEMESTIMATE_FOLDER)) {
        $collection = array();
        
        if(!$decoder->getElementStartTag(SYNC_GETITEMESTIMATE_FOLDERTYPE))
            return false;
            
        $class = $decoder->getElementContent();
        
        if(!$decoder->getElementEndTag())
            return false;
            
		if($decoder->getElementStartTag(SYNC_GETITEMESTIMATE_FOLDERID)) {
	        $collectionid = $decoder->getElementContent();
        
	        if(!$decoder->getElementEndTag())
	            return false;
		}
		            
        if(!$decoder->getElementStartTag(SYNC_FILTERTYPE))
            return false;
            
        $filtertype = $decoder->getElementContent();
        
        if(!$decoder->getElementEndTag())
            return false;

        if(!$decoder->getElementStartTag(SYNC_SYNCKEY))
            return false;
            
        $synckey = $decoder->getElementContent();
        
        if(!$decoder->getElementEndTag())
            return false;
        if(!$decoder->getElementEndTag())
            return false;
        
        // compatibility mode - get folderid from the state directory
        if (!isset($collectionid)) {
        	$collectionid = _getFolderID($devid, $class);
        }
            
        $collection = array();
        $collection["synckey"] = $synckey;
        $collection["class"] = $class;
        $collection["filtertype"] = $filtertype;
        $collection["collectionid"] = $collectionid;
        
        array_push($collections, $collection);
    }
    
    $encoder->startWBXML();
    
    $encoder->startTag(SYNC_GETITEMESTIMATE_GETITEMESTIMATE);
    {
        foreach($collections as $collection) {
            $encoder->startTag(SYNC_GETITEMESTIMATE_RESPONSE);
            {
                $encoder->startTag(SYNC_GETITEMESTIMATE_STATUS);
                $encoder->content(1);
                $encoder->endTag();
                
                $encoder->startTag(SYNC_GETITEMESTIMATE_FOLDER);
                {
                    $encoder->startTag(SYNC_GETITEMESTIMATE_FOLDERTYPE);
                    $encoder->content($collection["class"]);
                    $encoder->endTag();
                    
                    $encoder->startTag(SYNC_GETITEMESTIMATE_FOLDERID);
                    $encoder->content($collection["collectionid"]);
                    $encoder->endTag();
                    
                    $encoder->startTag(SYNC_GETITEMESTIMATE_ESTIMATE);
                    
                    $importer = new ImportContentsChangesMem();

                    $statemachine = new StateMachine();
                    $syncstate = $statemachine->getSyncState($collection["synckey"]);
                    
                    $exporter = $backend->GetExporter($collection["collectionid"]);
                    $exporter->Config($importer, $collection["class"], $collection["filtertype"], $syncstate, 0, 0);
                    
                    $encoder->content($exporter->GetChangeCount());
                    
                    $encoder->endTag();
                }
                $encoder->endTag();
            }
            $encoder->endTag();
        }
    }
    $encoder->endTag();
    
    return true;
}

function HandleGetAttachment($backend, $protocolversion) {
    $attname = $_GET["AttachmentName"];
    
    if(!isset($attname))
        return false;
        
    header("Content-Type: application/octet-stream");
    
    $backend->GetAttachmentData($attname);
    
    return true;
}

function HandlePing($backend, $devid) {
    global $zpushdtd, $input, $output;
    $timeout = 5;
    
    debugLog("Ping received");
    
    $decoder = new WBXMLDecoder($input, $zpushdtd);
    $encoder = new WBXMLEncoder($output, $zpushdtd);

    $collections = array();
    $lifetime = 0;

    // Get previous defaults if they exist
    $file = BASE_PATH . STATE_DIR . "/" . $devid;
    if (file_exists($file)) {
    	$ping = unserialize(file_get_contents($file));
	    $collections = $ping["collections"];
	    $lifetime = $ping["lifetime"];
    } 
            
    if($decoder->getElementStartTag(SYNC_PING_PING)) {
        debugLog("Ping init");
        if($decoder->getElementStartTag(SYNC_PING_LIFETIME)) {
            $lifetime = $decoder->getElementContent();
            $decoder->getElementEndTag();
        }

        if($decoder->getElementStartTag(SYNC_PING_FOLDERS)) {
            $collections = array();
       
            while($decoder->getElementStartTag(SYNC_PING_FOLDER)) {
                $collection = array();
                
                if($decoder->getElementStartTag(SYNC_PING_SERVERENTRYID)) {
                    $collection["serverid"] = $decoder->getElementContent();
                    $decoder->getElementEndTag();
                }
                if($decoder->getElementStartTag(SYNC_PING_FOLDERTYPE)) {
                    $collection["class"] = $decoder->getElementContent();
                    $decoder->getElementEndTag();
                }
                
                $decoder->getElementEndTag();

                // Create start state for this collection            
                $exporter = $backend->GetExporter($collection["serverid"]);
                $state = "";
                $importer = false;
                $exporter->Config($importer, false, false, $state, BACKEND_DISCARD_DATA, 0);
                while(is_array($exporter->Synchronize()));
                $state = $exporter->GetState();
                $collection["state"] = $state;
                array_push($collections, $collection);
            }
            
            if(!$decoder->getElementEndTag())
                return false;
        }
                
        if(!$decoder->getElementEndTag())
            return false;
    }
    
    $changes = array();
    $dataavailable = false;
    
    debugLog("Waiting for changes... (lifetime $lifetime)");
    // Wait for something to happen
    for($n=0;$n<$lifetime / $timeout; $n++ ) {
        if(count($collections) == 0) {
            $error = 1;
            break;
        }
            
        for($i=0;$i<count($collections);$i++) {
            $collection = $collections[$i];
            
            $exporter = $backend->GetExporter($collection["serverid"]);
            $state = $collection["state"];
            $importer = false;
            $ret = $exporter->Config($importer, false, false, $state, BACKEND_DISCARD_DATA, 0);

            // stop ping if exporter can not be configured (e.g. after Zarafa-server restart)
            if ($ret === false ) {
            	// force "ping" to stop
            	$n = $lifetime / $timeout;
            	debugLog("Ping error: Exporter can not be configured. Waiting 30 seconds before ping is retried.");
            	sleep(30);
            	break;
            }
            
            $changecount = $exporter->GetChangeCount();

            if($changecount > 0) {
                $dataavailable = true;
                $changes[$collection["serverid"]] = $changecount;
            }
            
            // Discard any data
            while(is_array($exporter->Synchronize()));
            
            // Record state for next Ping
            $collections[$i]["state"] = $exporter->GetState();
        }
        
        if($dataavailable) {
            debugLog("Found change");
            break;
        }
        
        sleep($timeout);
    }
            
    $encoder->StartWBXML();
    
    $encoder->startTag(SYNC_PING_PING);
    {
        $encoder->startTag(SYNC_PING_STATUS);
        if(isset($error))
            $encoder->content(3);
        else
            $encoder->content(count($changes) > 0 ? 2 : 1);
        $encoder->endTag();
        
        $encoder->startTag(SYNC_PING_FOLDERS);
        foreach($collections as $collection) {
            if(isset($changes[$collection["serverid"]])) {
                $encoder->startTag(SYNC_PING_FOLDER);
                $encoder->content($collection["serverid"]);
                $encoder->endTag();
            }
        }
        $encoder->endTag();
    }
    $encoder->endTag();

    // Save the ping request state for this device
    file_put_contents(BASE_PATH . "/" . STATE_DIR . "/" . $devid, serialize(array("lifetime" => $lifetime, "collections" => $collections)));
    
    return true;
}

function HandleSendMail($backend, $protocolversion) {
    // All that happens here is that we receive an rfc822 message on stdin
    // and just forward it to the backend. We provide no output except for
    // an OK http reply

    global $input;
    
    $rfc822 = readStream($input);
    
    return $backend->SendMail($rfc822);
}

function HandleSmartForward($backend, $protocolversion) {
    global $input;
    // SmartForward is a normal 'send' except that you should attach the
    // original message which is specified in the URL

    $rfc822 = readStream($input);

    if(isset($_GET["ItemId"]))   
        $orig = $_GET["ItemId"];
    else
        $orig = false;
        
    if(isset($_GET["CollectionId"]))
        $parent = $_GET["CollectionId"];
    else
        $parent = false; 

    return $backend->SendMail($rfc822, $orig, false, $parent);
}

function HandleSmartReply($backend, $protocolversion) {
    global $input;
    // Smart reply should add the original message to the end of the message body

    $rfc822 = readStream($input);

    if(isset($_GET["ItemId"]))
        $orig = $_GET["ItemId"];
    else
        $orig = false;

    if(isset($_GET["CollectionId"]))
        $parent = $_GET["CollectionId"];
    else
        $parent = false; 

    return $backend->SendMail($rfc822, false, $orig, $parent);
}

function HandleFolderCreate($backend, $protocolversion) {
    global $zpushdtd;
    global $input, $output;

    $decoder = new WBXMLDecoder($input, $zpushdtd);
    $encoder = new WBXMLEncoder($output, $zpushdtd);

    $el = $decoder->getElement();
    
    if($el[EN_TYPE] != EN_TYPE_STARTTAG)
        return false;
        
    $create = $update = $delete = false;
        
    if($el[EN_TAG] == SYNC_FOLDERHIERARCHY_FOLDERCREATE)
        $create = true;
    else if($el[EN_TAG] == SYNC_FOLDERHIERARCHY_FOLDERUPDATE)
        $update = true;
    else if($el[EN_TAG] == SYNC_FOLDERHIERARCHY_FOLDERDELETE)
        $delete = true;
        
    if(!$create && !$update && !$delete)
        return false;

    // SyncKey
    if(!$decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_SYNCKEY))
        return false;
    $synckey = $decoder->getElementContent();
    if(!$decoder->getElementEndTag())
        return false;

    // ServerID
    $serverid = false;
    if($decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_SERVERENTRYID)) {
        $serverid = $decoder->getElementContent();
        if(!$decoder->getElementEndTag())
            return false;
    }

    // Parent  
    $parentid = false;
    if($decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_PARENTID)) {
        $parentid = $decoder->getElementContent();
        if(!$decoder->getElementEndTag())
            return false;
    }

    // Displayname
    if(!$decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_DISPLAYNAME))
        return false;
    $displayname = $decoder->getElementContent();
    if(!$decoder->getElementEndTag())
        return false;

    // Type
    $type = false;
    if($decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_TYPE)) {
        $type = $decoder->getElementContent();
        if(!$decoder->getElementEndTag())
            return false;
    }

    if(!$decoder->getElementEndTag())
        return false;

    // Get state of hierarchy
    $statemachine = new StateMachine();
    $syncstate = $statemachine->getSyncState($synckey);
    $newsynckey = $statemachine->getNewSyncKey($synckey);

    // Configure importer with last state
    $importer = $backend->GetHierarchyImporter();
    $importer->Config($syncstate);
    
    // Send change
    $serverid = $importer->ImportFolderChange($serverid, $parentid, $displayname, $type);

    $encoder->startWBXML();
    
    $encoder->startTag(SYNC_FOLDERHIERARCHY_FOLDERCREATE);
    {
        {
            $encoder->startTag(SYNC_FOLDERHIERARCHY_STATUS);
            $encoder->content(1);
            $encoder->endTag();
            
            $encoder->startTag(SYNC_FOLDERHIERARCHY_SYNCKEY);
            $encoder->content($newsynckey);
            $encoder->endTag();
            
            $encoder->startTag(SYNC_FOLDERHIERARCHY_SERVERENTRYID);
            $encoder->content($serverid);
            $encoder->endTag();
        }    
        $encoder->endTag();
    }
    $encoder->endTag();

    // Save the sync state for the next time
    $statemachine->setSyncState($newsynckey, $importer->GetState());

    return true;
}

// Handle meetingresponse method
function HandleMeetingResponse($backend, $protocolversion) {
    global $zpushdtd;
    global $output, $input;
    
    $requests = Array();
    
    $decoder = new WBXMLDecoder($input, $zpushdtd);
    $encoder = new WBXMLEncoder($output, $zpushdtd);

    if(!$decoder->getElementStartTag(SYNC_MEETINGRESPONSE_MEETINGRESPONSE))
        return false;
  
    while($decoder->getElementStartTag(SYNC_MEETINGRESPONSE_REQUEST)) {
        $req = Array();
        
        if($decoder->getElementStartTag(SYNC_MEETINGRESPONSE_USERRESPONSE)) {
            $req["response"] = $decoder->getElementContent();
            if(!$decoder->getElementEndTag())
                return false;
        }

        if($decoder->getElementStartTag(SYNC_MEETINGRESPONSE_FOLDERID)) {
            $req["folderid"] = $decoder->getElementContent();
            if(!$decoder->getElementEndTag())
                return false;
        }

        if($decoder->getElementStartTag(SYNC_MEETINGRESPONSE_REQUESTID)) {
            $req["requestid"] = $decoder->getElementContent();
            if(!$decoder->getElementEndTag())
                return false;
        }
        
        if(!$decoder->getElementEndTag())
            return false;
            
        array_push($requests, $req);
    }
            
    if(!$decoder->getElementEndTag())
        return false;

    
    // Start output, simply the error code, plus the ID of the calendar item that was generated by the
    // accept of the meeting response
    
    $encoder->StartWBXML();
    
    $encoder->startTag(SYNC_MEETINGRESPONSE_MEETINGRESPONSE);

    foreach($requests as $req) {
        $calendarid = "";
        $ok = $backend->MeetingResponse($req["requestid"], $req["folderid"], $req["response"], $calendarid);
        $encoder->startTag(SYNC_MEETINGRESPONSE_RESULT);
            $encoder->startTag(SYNC_MEETINGRESPONSE_REQUESTID);
                $encoder->content($req["requestid"]);
            $encoder->endTag();
            
            $encoder->startTag(SYNC_MEETINGRESPONSE_STATUS);
                $encoder->content($ok ? 1 : 2);
            $encoder->endTag();
            
            if($ok) {
                $encoder->startTag(SYNC_MEETINGRESPONSE_CALENDARID);
                    $encoder->content($calendarid);
                $encoder->endTag();
            }
            
        $encoder->endTag();
    }
    
    $encoder->endTag();
    
    return true;
}


function HandleFolderUpdate($backend, $protocolversion) {
    return HandleFolderCreate($backend, $protocolversion);
}

function HandleRequest($backend, $cmd, $devid, $protocolversion) {
    switch($cmd) {
    case 'Sync':
        $status = HandleSync($backend, $protocolversion, $devid);
        break;
    case 'SendMail':
        $status = HandleSendMail($backend, $protocolversion);
        break;
    case 'SmartForward':
        $status = HandleSmartForward($backend, $protocolversion);
        break;
    case 'SmartReply':
        $status = HandleSmartReply($backend, $protocolversion);
        break;
    case 'GetAttachment':
        $status = HandleGetAttachment($backend, $protocolversion);
        break;
    case 'GetHierarchy':
        $status = HandleGetHierarchy($backend, $protocolversion, $devid);
        break;
    case 'CreateCollection':
        $status = HandleCreateCollection($backend, $protocolversion);
        break;
    case 'DeleteCollection':
        $status = HandleDeleteCollection($backend, $protocolversion);
        break;
    case 'MoveCollection':
        $status = HandleMoveCollection($backend, $protocolversion);
        break;
    case 'FolderSync':
        $status = HandleFolderSync($backend, $protocolversion);
        break;
    case 'FolderCreate':
        $status = HandleFolderCreate($backend, $protocolversion);
        break;
    case 'FolderDelete':
        $status = HandleFolderDelete($backend, $protocolversion);
        break;
    case 'FolderUpdate':
        $status = HandleFolderUpdate($backend, $protocolversion);
        break;
    case 'MoveItems':
        $status = HandleMoveItems($backend, $protocolversion);
        break;
    case 'GetItemEstimate':
        $status = HandleGetItemEstimate($backend, $protocolversion, $devid);
        break;
    case 'MeetingResponse':
        $status = HandleMeetingResponse($backend, $protocolversion);
        break;
    case 'Notify': // Used for sms-based notifications (pushmail)
        $status = HandleNotify($backend, $protocolversion);
        break;
    case 'Ping': // Used for http-based notifications (pushmail)
        $status = HandlePing($backend, $devid, $protocolversion);
        break;
    default:
    	debugLog("unknown command - not implemented");
    	$status = false;
    	break;
    }

    return $status;
}

function readStream(&$input) {
    $s = "";
    
    while(1) {
        $data = fread($input, 4096);
        if(strlen($data) == 0)
            break;
        $s .= $data;
    }
    
    return $s;
}

?>