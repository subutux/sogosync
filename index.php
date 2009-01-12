<?php
/***********************************************
* File      :   index.php
* Project   :   Z-Push
* Descr     :   This is the entry point 
*				through which all requests
*				are called.
*
* Created   :   01.10.2007
*
*  Zarafa Deutschland GmbH, www.zarafaserver.de
* This file is distributed under GPL v2.
* Consult LICENSE file for details
************************************************/

ob_start(false, 1048576);

include_once('zpushdefs.php');
include_once("config.php");
include_once("proto.php");
include_once("request.php");
include_once("debug.php");
include_once("compat.php");
include_once("version.php");

// Attempt to set maximum execution time
ini_set('max_execution_time', SCRIPT_TIMEOUT);
set_time_limit(SCRIPT_TIMEOUT);

debugLog("Start");
debugLog("Z-Push version: $zpush_version");
debugLog("Client IP: ". $_SERVER['REMOTE_ADDR']);

$input = fopen("php://input", "r");
$output = fopen("php://output", "w+");

// The script must always be called with authorisation info
if(!isset($_SERVER['PHP_AUTH_PW'])) {
    header("WWW-Authenticate: Basic realm=\"ZPush\"");
    header("HTTP/1.0 401 Unauthorized");
    print("Access denied. Please send authorisation information");
	debugLog("Access denied: no password sent.");
    return;
}

// split username & domain if received as one
$pos = strrpos($_SERVER['PHP_AUTH_USER'], '\\');
if($pos === false){
    $auth_user = $_SERVER['PHP_AUTH_USER'];
    $auth_domain = '';
}else{
    $auth_domain = substr($_SERVER['PHP_AUTH_USER'],0,$pos);
    $auth_user = substr($_SERVER['PHP_AUTH_USER'],$pos+1);
}
$auth_pw = $_SERVER['PHP_AUTH_PW'];

// Parse the standard GET parameters        
if(isset($_GET["Cmd"]))
    $cmd = $_GET["Cmd"];
if(isset($_GET["User"]))
    $user = $_GET["User"];
if(isset($_GET["DeviceId"]))
    $devid = $_GET["DeviceId"];	
if(isset($_GET["DeviceType"]))
    $devtype = $_GET["DeviceType"];

// The GET parameters are required
if($_SERVER["REQUEST_METHOD"] == "POST") {
    if(!isset($user) || !isset($devid) || !isset($devtype)) {
        print("Your device requested the Z-Push URL without the required GET parameters");
        return;
    }
}

// Get the request headers so we can see the versions
$requestheaders = apache_request_headers();
if(isset($requestheaders["MS-ASProtocolVersion"])) {
    global $protocolversion;

    $protocolversion = $requestheaders["MS-ASProtocolVersion"];
    debugLog("Client supports version " . $protocolversion);
} else {
    global $protocolversion;

    $protocolversion = "1.0";
}

// Load our backend driver
$backend_dir = opendir(BASE_PATH . "/backend");
while($entry = readdir($backend_dir)) {
    if(substr($entry,0,1) == "." || substr($entry,-3) != "php")
        continue;

    if (!function_exists("mapi_logon") && ($entry == "ics.php")) 
        continue;
        
    include_once(BASE_PATH . "/backend/" . $entry);
}

// Initialize our backend
$backend = new $BACKEND_PROVIDER();

if($backend->Logon($auth_user, $auth_domain, $auth_pw) == false) {
    header("HTTP/1.0 401 Unauthorized");
    header("WWW-Authenticate: Basic realm=\"ZPush\"");
    print("Access denied. Username or password incorrect.");
    debugLog("Access denied: backend logon failed.");
    return;
}

// $user is usually the same as the PHP_AUTH_USER. This allows you to sync the 'john' account if you
// have sufficient privileges as user 'joe'.
if($backend->Setup($user, $devid, $protocolversion) == false) {
    header("HTTP/1.0 401 Unauthorized");
    header("WWW-Authenticate: Basic realm=\"ZPush\"");
    print("Access denied or user '$user' unknown.");
    debugLog("Access denied: backend setup failed.");
    return;
}

// Do the actual request
switch($_SERVER["REQUEST_METHOD"]) {
    case 'OPTIONS':
        header("MS-Server-ActiveSync: 6.5.7638.1");
        header("MS-ASProtocolVersions: 1.0,2.0,2.1,2.5");
        header("MS-ASProtocolCommands: Sync,SendMail,SmartForward,SmartReply,GetAttachment,GetHierarchy,CreateCollection,DeleteCollection,MoveCollection,FolderSync,FolderCreate,FolderDelete,FolderUpdate,MoveItems,GetItemEstimate,MeetingResponse,ResolveRecipipents,ValidateCert,Provision,Search,Ping");
        break;
    case 'POST':
        header("MS-Server-ActiveSync: 6.5.7638.1");
        debugLog("POST cmd: $cmd");
        // Do the actual request
        if(!HandleRequest($backend, $cmd, $devid, $protocolversion)) {
            // Request failed. Try to output some kind of error information. We can only do this if
            // output had not started yet. If it has started already, we can't show the user the error, and
            // the device will give its own (useless) error message.
            if(!headers_sent()) {
                header("Content-type: text/html");
                print("<BODY>\n");
                print("<h3>Error</h3><p>\n");
                print("There was a problem processing the <i>$cmd</i> command from your PDA.\n");
                print("<p>Here is the debug output:<p><pre>\n");
                print(getDebugInfo());
                print("</pre>\n");
                print("</BODY>\n");
            }
        }
        break;
    case 'GET':
        header("Content-type: text/html");
        print("<BODY>\n");
        print("<h3>GET not supported</h3><p>\n");
        print("This is the z-push location and can only be accessed by Microsoft ActiveSync-capable devices.");
        print("</BODY>\n");
        break;
}


$len = ob_get_length();
$data = ob_get_contents();

ob_end_clean();

// Unfortunately, even though zpush can stream the data to the client
// with a chunked encoding, using chunked encoding also breaks the progress bar
// on the PDA. So we de-chunk here and just output a content-length header and
// send it as a 'normal' packet. If the output packet exceeds 1MB (see ob_start)
// then it will be sent as a chunked packet anyway because PHP will have to flush
// the buffer.

header("Content-Length: $len");
print $data;

// destruct backend after all data is on the stream
$backend->Logoff();

debugLog("end");
debugLog("--------");
?>