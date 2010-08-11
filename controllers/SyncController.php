<?
/*
    ***** BEGIN LICENSE BLOCK *****
    
    This file is part of the Zotero Data Server.
    
    Copyright © 2010 Center for History and New Media
                     George Mason University, Fairfax, Virginia, USA
                     http://zotero.org
    
    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU Affero General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.
    
    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU Affero General Public License for more details.
    
    You should have received a copy of the GNU Affero General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.
    
    ***** END LICENSE BLOCK *****
*/

class SyncController extends Controller {
	private $validAPIVersions = array(8);
	private $sessionLifetime = 3600;
	
	private $apiVersion;
	private $sessionID = null;
	private $userID = null;
	private $userLibraryID = null;
	private $updateKey = null;
	private $responseXML = null;
	
	public function __get($field) {
		switch ($field) {
			case 'apiVersion':
			case 'userID':
			case 'userLibraryID':
			case 'updateKey':
				return $this->$field;
				
			default:
				trigger_error("Invalid field '$field'", E_USER_ERROR);
		}
	}
	
	public function __construct($action, $settings, $extra) {
		require_once('../model/Error.inc.php');
		
		// Run session garbage collection every so often
		if (Z_Core::probability(1000)) {
			$this->sessionGC();
		}
		
		// Inflate gzipped data
		if (!empty($_GET['gzip'])) {
			$gzdata = file_get_contents('php://input');
			$data = gzinflate($gzdata);
			parse_str($data, $_POST);
			foreach ($_POST as $key=>$val) {
				$_REQUEST[$key] = $val;
			}
		}
		
		$this->responseXML = Zotero_Sync::getResponseXML();
		
		//if (!Z_CONFIG::$SYNC_ENABLED && $_SERVER["REMOTE_ADDR"] != '') {
		if (!Z_CONFIG::$SYNC_ENABLED) {
			$this->error(503, 'SERVER_ERROR', Z_CONFIG::$MAINTENANCE_MESSAGE);
		}
		
		if (empty($_REQUEST['version'])) {
			if ($action == 'index') {
				header("HTTP/1.1 400 Bad Request");
				echo "Nothing to see here.";
				exit;
			}
			
			$this->error(400, 'NO_API_VERSION', "API version not specified");
		}
		
		$upgradeMessage = "Due to improvements made to sync functionality, you must upgrade to Zotero 2.0 or later (via Firefox's Tools menu -> Add-ons -> Extensions -> Find Updates or from zotero.org) to sync your Zotero library.";
		
		if (isset($_SERVER['HTTP_X_ZOTERO_VERSION'])) {
			require_once('../model/ToolkitVersionComparator.inc.php');
			
			if ($_SERVER['HTTP_X_ZOTERO_VERSION'] == "2.0b6") {
				die ("Please upgrade to Zotero 2.0 via Tools -> Add-ons -> Extensions -> Find Updates or from zotero.org.");
			}
			else if (preg_match("/2.0b[0-9].SVN/", $_SERVER['HTTP_X_ZOTERO_VERSION'])) {
				// Can't use version for SVN builds
			}
			else if (ToolkitVersionComparator::compare($_SERVER['HTTP_X_ZOTERO_VERSION'], "2.0rc.r5716") < 0) {
				$this->error(400, 'UPGRADE_REQUIRED', $upgradeMessage);
			}
		}
		
		if (!in_array($_REQUEST['version'], $this->validAPIVersions)) {
			if ($_REQUEST['version'] < 8) {
				$this->error(400, 'UPGRADE_REQUIRED', $upgradeMessage);
			}
			$this->error(400, 'INVALID_API_VERSION', "Invalid request API version '{$_REQUEST['version']}'");
		}
		
		$this->apiVersion = (int) $_REQUEST['version'];
		$this->responseXML['version'] = $this->apiVersion;
	}
	
	
	public function login() {
		// TODO: Change to POST only
		
		if (empty($_REQUEST['username'])) {
			$this->error(403, 'NO_USER_NAME', "Username not provided");
		}
		else if (empty($_REQUEST['password'])) {
			$this->error(403, 'NO_PASSWORD', "Password not provided");
		}
		
		$username = $_REQUEST['username'];
		$password = $_REQUEST['password'];
		
		$authData = array('username' => $username, 'password' => $password);
		
		$userID = Zotero_Users::authenticate('password', $authData);
		if (!$userID) {
			$userID = Zotero_Users::authenticate('http', $authData);
			if (!$userID) {                                                                   
				// TODO: if GMU unavailable, return this
				//$this->error(503, 'SERVICE_UNAVAILABLE', "Cannot authenticate login");
				
				if (isset($_SERVER['HTTP_X_ZOTERO_VERSION']) && $_SERVER['HTTP_X_ZOTERO_VERSION'] == "2.0b6") {
					die ("Username/password not accepted");
				}
				$this->error(403, 'INVALID_LOGIN', "Username/password not accepted");
			}
		}
		
		$sessionID = md5($userID . uniqid(rand(), true) . $password);
		$ip = IPAddress::getIP();
		
		$sql = "INSERT INTO sessions (sessionID, userID, ipAddress)
					VALUES (?,?,INET_ATON(?))";
		Z_SessionsDB::query($sql, array($sessionID, $userID, $ip));
		
		Z_Core::$MC->set(
			"syncSession_$sessionID",
			array(
				'sessionID' => $sessionID,
				'userID' => $userID
			),
			$this->sessionLifetime
		);
		
		$this->responseXML->sessionID = $sessionID;
		$this->end();
	}
	
	
	public function logout() {
		Z_SessionsDB::beginTransaction();
		
		$this->sessionCheck();
		
		$sql = "DELETE FROM sessions WHERE sessionID=?";
		Z_SessionsDB::query($sql, $this->sessionID);
		
		Z_Core::$MC->delete("syncSession_" . $this->sessionID);
		
		Z_SessionsDB::commit();
		
		$this->responseXML->addChild('loggedout');
		$this->end();
	}
	
	
	/**
	 * Now a noop -- to be removed
	 */
	public function unlock() {
		$this->responseXML->addChild('unlocked');
		$this->end();
	}
	
	
	public function index() {
		$this->end();
	}
	
	
	public function updated() {
		if (empty($_REQUEST['lastsync'])) {
			$this->error(400, 'NO_LAST_SYNC_TIME', 'Last sync time not provided');
		}
		
		$lastsync = false;
		if (is_numeric($_REQUEST['lastsync'])) {
			$lastsync = $_REQUEST['lastsync'];
		}
		else {
			$this->error(400, 'INVALID_LAST_SYNC_TIME', 'Last sync time is invalid');
		}
		
		$this->sessionCheck();
		
		$doc = new DOMDocument();
		$domResponse = dom_import_simplexml($this->responseXML);
		$domResponse = $doc->importNode($domResponse, true);
		$doc->appendChild($domResponse);
		
		try {
			$result = Zotero_Sync::getSessionDownloadResult($this->sessionID);
		}
		catch (Exception $e) {
			$this->clearWaitTime($this->sessionID);
			unset($this->responseXML->updated);
			
			if (Z_ENV_TESTING_SITE) {
				throw ($e);
			}
			else {
				$id = substr(md5(uniqid(rand(), true)), 0, 10);
				$str = date("D M j G:i:s T Y") . "\n";
				$str .= "IP address: " . $_SERVER['REMOTE_ADDR'] . "\n";
				if (isset($_SERVER['HTTP_X_ZOTERO_VERSION'])) {
					$str .= "Version: " . $_SERVER['HTTP_X_ZOTERO_VERSION'] . "\n";
				}
				$str .= $doc->saveXML();
				file_put_contents(Z_CONFIG::$SYNC_ERROR_PATH . $id, $str);
				$this->error(500, 'INVALID_OUTPUT', "Invalid output from server (Report ID: $id)");
			}
		}
		
		// XML response
		if (is_string($result)) {
			$this->clearWaitTime($this->sessionID);
			$this->responseXML = new SimpleXMLElement($result);
			$this->end();
		}
		
		// Queued
		if ($result === false) {
			$queued = $this->responseXML->addChild('locked');
			$queued['wait'] = $this->getWaitTime($this->sessionID);
			$this->end();
		}
		
		// Not queued
		if ($result == -1) {
			// See if we're locked
			Zotero_DB::beginTransaction();
			if (Zotero_Sync::userIsWriteLocked($this->userID)
					// If client knows it will be uploading, check for read lock as well
					|| (!empty($_REQUEST['upload']) && Zotero_Sync::userIsReadLocked($this->userID))) {
				Zotero_DB::commit();
				$locked = $this->responseXML->addChild('locked');
				$locked['wait'] = $this->getWaitTime($this->sessionID);
				$this->end();
			}
			Zotero_DB::commit();
			
			// Not locked, so clear wait index
			$this->clearWaitTime($this->sessionID);
			
			$queue = true;
			if (Z_ENV_TESTING_SITE && !empty($_GET['noqueue'])) {
				$queue = false;
			}
			$numUpdated = Zotero_DataObjects::countUpdated($this->userID, $lastsync, true);
			if ($numUpdated < 5) {
				$queue = false;
			}
			if ($queue) {
				//$numUpdated = Zotero_DataObjects::countUpdated($this->userID, $lastsync, true);
				Zotero_Sync::queueDownload($this->userID, $this->sessionID, $lastsync, $this->apiVersion, $numUpdated);
				
				try {
					Zotero_Sync::notifyDownloadProcessor();
				}
				catch (Exception $e) {
					Z_Core::logError($e);
				}
				
				$locked = $this->responseXML->addChild('locked');
				$locked['wait'] = 1;
			}
			else {
				try {
					Zotero_Sync::processDownload($this->userID, $lastsync, $doc);
					$this->responseXML = simplexml_import_dom($doc);
				}
				catch (Exception $e) {
					unset($this->responseXML->updated);
					
					if (Z_ENV_TESTING_SITE) {
						throw ($e);
					}
					else {
						$id = substr(md5(uniqid(rand(), true)), 0, 10);
						$str = date("D M j G:i:s T Y") . "\n";
						$str .= "IP address: " . $_SERVER['REMOTE_ADDR'] . "\n";
						if (isset($_SERVER['HTTP_X_ZOTERO_VERSION'])) {
							$str .= "Version: " . $_SERVER['HTTP_X_ZOTERO_VERSION'] . "\n";
						}
						$str .= $doc->saveXML();
						file_put_contents(Z_CONFIG::$SYNC_ERROR_PATH . $id, $str);
						$this->error(500, 'INVALID_OUTPUT', "Invalid output from server (Report ID: $id)");
					}
				}
			}
			
			$this->end();
		}
		
		throw new Exception("Unexpected session result $result");
	}
	
	
	public function noop() {}
	
	
	/**
	 * Handle uploaded data, overwriting existing data
	 */
	public function upload() {
		$this->sessionCheck();
		
		// Another session is either queued or writing — upload data won't be valid,
		// so client should wait and return to /updated with 'upload' flag
		Zotero_DB::beginTransaction();
		if (Zotero_Sync::userIsReadLocked($this->userID) || Zotero_Sync::userIsWriteLocked($this->userID)) {
			Zotero_DB::commit();
			$locked = $this->responseXML->addChild('locked');
			$locked['wait'] = $this->getWaitTime($this->sessionID);
			$this->end();
		}
		Zotero_DB::commit();
		$this->clearWaitTime($this->sessionID);
		
		if (empty($_REQUEST['updateKey'])) {
			$this->error(400, 'INVALID_UPLOAD_DATA', 'Update key not provided');
		}
		
		if ($_REQUEST['updateKey'] != Zotero_Sync::getUpdateKey($this->userID)) {
			$this->e409("Server data has changed since last retrieval");
		}
		
		// TODO: change to POST
		if (empty($_REQUEST['data'])) {
			$this->error(400, 'MISSING_UPLOAD_DATA', 'Uploaded data not provided');
		}
		
		$xmldata =& $_REQUEST['data'];
		
		$doc = new DOMDocument();
		$doc->loadXML($xmldata);
		
		function relaxNGErrorHandler($errno, $errstr) {
			//var_dump($errstr);
		}
		set_error_handler('relaxNGErrorHandler');
		if (!$doc->relaxNGValidate(Z_ENV_MODEL_PATH . 'relax-ng/upload.rng')) {
			$id = substr(md5(uniqid(rand(), true)), 0, 10);
			$str = date("D M j G:i:s T Y") . "\n";
			$str .= "IP address: " . $_SERVER['REMOTE_ADDR'] . "\n";
			if (isset($_SERVER['HTTP_X_ZOTERO_VERSION'])) {
				$str .= "Version: " . $_SERVER['HTTP_X_ZOTERO_VERSION'] . "\n";
			}
			$str .= $xmldata;
			file_put_contents(Z_CONFIG::$SYNC_ERROR_PATH . $id, $str);
			$this->error(500, 'INVALID_UPLOAD_DATA', "Uploaded data not well-formed (Report ID: $id)");
		}
		restore_error_handler();
		
		try {
			$xml = simplexml_import_dom($doc);
			
			$queue = true;
			if (Z_ENV_TESTING_SITE && !empty($_GET['noqueue'])) {
				$queue = false;
			}
			if ($queue) {
				$affectedLibraries = Zotero_Sync::parseAffectedLibraries($xml);
				// Relations-only uploads don't have affected libraries
				if (!$affectedLibraries) {
					$affectedLibraries = array(Zotero_Users::getLibraryIDFromUserID($this->userID));
				}
				Zotero_Sync::queueUpload($this->userID, $this->sessionID, $xmldata, $affectedLibraries);
				
				try {
					Zotero_Sync::notifyUploadProcessor();
					Zotero_Sync::notifyErrorProcessor();
					usleep(750000);
				}
				catch (Exception $e) {
					Z_Core::logError($e);
				}
				
				// Give processor a chance to finish while we're still here
				$this->uploadstatus();
			}
			else {
				set_time_limit(210);
				$timestamp = Zotero_Sync::processUpload($this->userID, $xml);
				
				$this->responseXML['timestamp'] = $timestamp;
				$this->responseXML->addChild('uploaded');
				
				$this->end();
			}
		}
		catch (Exception $e) {
			$this->handleSyncError($e, $xmldata);
		}
	}
	
	
	public function uploadstatus() {
		$this->sessionCheck();
		
		$result = Zotero_Sync::getSessionUploadResult($this->sessionID);
		
		if ($result === false) {
			$queued = $this->responseXML->addChild('queued');
			$queued['wait'] = $this->getWaitTime($this->sessionID);
			$this->end();
		}
		$this->clearWaitTime($this->sessionID);
		
		if (!isset($result['exception'])) {
			$this->responseXML['timestamp'] = $result['timestamp'];
			$this->responseXML->addChild('uploaded');
			$this->end();
		}
		
		if (is_array($result)) {
			$this->handleSyncError($result['exception'], $result['xmldata']);
		}
		
		throw new Exception("Unexpected session result $result");
	}
	
	
	public function items() {
		$this->sessionCheck();
		$this->exitClean();
	}
	
	
	
	public function clear() {
		$this->sessionCheck();
		
		if (Zotero_Sync::userIsReadLocked($this->userID) || 
				Zotero_Sync::userIsWriteLocked($this->userID)) {
			$message = "You cannot reset server data while one of your libraries "
				. "is locked for syncing. Please wait for all related syncs to complete.";
			$this->error(400, 'SYNC_LOCKED', $message);
		}
		
		Zotero_Users::clearAllData($this->userID);
		$this->responseXML->addChild('cleared');
		$this->end();
	}
	
	
	//
	// Private methods
	//
	
	/**
	 * Make sure we have a valid session
	 */
	private function sessionCheck() {
		if (empty($_REQUEST['sessionid'])) {
			$this->error(403, 'NO_SESSION_ID', "Session ID not provided");
		}
		
		if (!preg_match('/^[a-f0-9]{32}$/', $_REQUEST['sessionid'])) {
			$this->error(500, 'INVALID_SESSION_ID', "Invalid session ID");
		}
		
		$sessionID = $_REQUEST['sessionid'];
		
		$session = Z_Core::$MC->get("syncSession_$sessionID");
		$userID = $session ? $session['userID'] : null;
		if (!$userID) {
			$sql = "SELECT userid, (UNIX_TIMESTAMP(NOW())-UNIX_TIMESTAMP(timestamp)) AS age
					FROM sessions WHERE sessionID=?";
			$session = Z_SessionsDB::rowQuery($sql, $sessionID);
			
			if (!$session) {
				$this->error(500, 'INVALID_SESSION_ID', "Invalid session ID");
			}
			
			if ($session['age'] > $this->sessionLifetime) {
				$this->error(500, 'SESSION_TIMED_OUT', "Session timed out");
			}
			
			$userID = $session['userid'];
		}
		
		$updated = Z_Core::$MC->set(
			"syncSession_$sessionID",
			array(
				'sessionID' => $sessionID,
				'userID' => $userID
			),
			$this->sessionLifetime
		);
		
		if (Z_Core::probability(4)) {
			$sql = "UPDATE sessions SET timestamp=NOW() WHERE sessionID=?";
			Z_SessionsDB::query($sql, $sessionID);
		}
		
		$this->sessionID = $sessionID;
		$this->userID = $userID;
		$this->userLibraryID = Zotero_Users::getLibraryIDFromUserID($userID);
	}
	
	
	private function getWaitTime($sessionID) {
		$index = Z_Core::$MC->get('syncWaitIndex_' . $sessionID);
		if ($index === false) {
			Z_Core::$MC->add('syncWaitIndex_' . $sessionID, 0);
			$index = 0;
		}
		
		if ($index == 0) {
			$wait = 2;
		}
		else if ($index < 5) {
			$wait = 5;
		}
		else if ($index < 11) {
			$wait = 10;
		}
		else if ($index < 23) {
			$wait = 25;
		}
		else if ($index < 33) {
			$wait = 60;
		}
		else {
			$wait = 120;
		}
		
		Z_Core::$MC->increment('syncWaitIndex_' . $sessionID);
		return $wait * 1000;
	}
	
	
	private function clearWaitTime($sessionID) {
		$index = Z_Core::$MC->delete('syncWaitIndex_' . $sessionID);
	}
	
	
	private function throttle($seconds=300) {
		$throttle = $this->responseXML->addChild('throttle');
		$throttle['delay'] = $seconds;
		$this->end();
	}
	
	
	private function handleSyncError(Exception $e, $xmldata) {
		$msg = $e->getMessage();
		if ($msg[0] == '=') {
			$msg = substr($msg, 1);
			$explicit = true;
			
			// TODO: more specific error messages
		}
		else {
			$explicit = false;
		}
		
		Z_Core::logError($msg);
		
		if (true || !$explicit) {
			if (Z_ENV_TESTING_SITE) {
				switch ($e->getCode()) {
					case Z_ERROR_COLLECTION_NOT_FOUND:
					case Z_ERROR_CREATOR_NOT_FOUND:
					case Z_ERROR_ITEM_NOT_FOUND:
					case Z_ERROR_TAG_TOO_LONG:
					case Z_ERROR_LIBRARY_ACCESS_DENIED:
						break;
					
					default:
						throw ($e);
				}
				
				$id = 'N/A';
			}
			else {
				$id = substr(md5(uniqid(rand(), true)), 0, 8);
				$str = date("D M j G:i:s T Y") . "\n";
				$str .= "IP address: " . $_SERVER['REMOTE_ADDR'] . "\n";
				if (isset($_SERVER['HTTP_X_ZOTERO_VERSION'])) {
					$str .= "Version: " . $_SERVER['HTTP_X_ZOTERO_VERSION'] . "\n";
				}
				$str .= $e;
				$str .= "\n\n";
				$str .= $xmldata;
				file_put_contents(Z_CONFIG::$SYNC_ERROR_PATH . $id, $str);
			}
		}
		
		Zotero_DB::rollback();
		
		switch ($e->getCode()) {
			case Z_ERROR_LIBRARY_ACCESS_DENIED:
				preg_match('/[Ll]ibrary ([0-9]+)/', $e->getMessage(), $matches);
				$libraryID = $matches ? $matches[1] : null;
				
				$this->error(400, 'LIBRARY_ACCESS_DENIED',
					"Cannot make changes to library (Report ID: $id)",
					array('libraryID' => $libraryID)
				);
				break;
				
			case Z_ERROR_ITEM_NOT_FOUND:
			case Z_ERROR_COLLECTION_NOT_FOUND:
			case Z_ERROR_CREATOR_NOT_FOUND:
				$this->error(500, "FULL_SYNC_REQUIRED",
					"Please perform a full sync in the Sync->Reset pane of the Zotero preferences. (Report ID: $id)"
				);
				break;
			
			case Z_ERROR_TAG_TOO_LONG:
				$message = $e->getMessage();
				preg_match("/Tag '(.+)' too long/s", $message, $matches);
				if ($matches) {
					$name = $matches[1];
					$this->error(400, "TAG_TOO_LONG",
						"Tag '" . mb_substr($name, 0, 50) . "…' too long",
						array(),
						array("tag" => $name)
					);
				}
				break;
			
			case Z_ERROR_COLLECTION_TOO_LONG:
				$message = $e->getMessage();
				preg_match("/Collection '(.+)' too long/s", $message, $matches);
				if ($matches) {
					$name = $matches[1];
					$this->error(400, "COLLECTION_TOO_LONG",
						"Collection '" . mb_substr($name, 0, 50) . "…' too long",
						array(),
						array("collection" => $name)
					);
				}
				break;
			
			case Z_ERROR_ARRAY_SIZE_MISMATCH:
				$this->error(400, 'DATABASE_TOO_LARGE',
					"Databases of this size cannot yet be synced. Please check back soon. (Report ID: $id)");
				break;
		}
		
		if (strpos($msg, "Lock wait timeout exceeded; try restarting transaction") !== false
			|| strpos($msg, "MySQL error: Deadlock found when trying to get lock; try restarting transaction") !== false) {
			$this->error(500, 'TIMEOUT',
				"Sync upload timed out. Please try again in a few minutes. (Report ID: $id)");
		}
		
		if (strpos($msg, "Data too long for column 'xmldata'") !== false) {
			$this->error(400, 'DATABASE_TOO_LARGE',
				"Databases of this size cannot yet be synced. Please check back soon. (Report ID: $id)");
		}
		
		// On certain messages, send 400 to prevent auto-retry
		if (strpos($msg, " too long") !== false
				|| strpos($msg, "First and last name are empty") !== false) {
			$this->error(400, 'ERROR_PROCESSING_UPLOAD_DATA',
				$explicit
					? $msg
					: "Error processing uploaded data (Report ID: $id)"
			);
		}
		
		$this->error(500, 'ERROR_PROCESSING_UPLOAD_DATA',
			$explicit
				? $msg
				: "Error processing uploaded data (Report ID: $id)"
		);
	}
	
	
	private function end() {
		header("Content-Type: text/xml");
		$xmlstr = $this->responseXML->asXML();
		echo $xmlstr;
		Z_Core::exitClean();
	}
	
	
	private function error($httpCode=500, $code, $message=false, $attributes=array(), $elements=array()) {
		header("Content-Type: text/xml");
		
		if ($httpCode) {
			header("HTTP/1.1 " . $httpCode);
		}
		
		$this->responseXML->error['code'] = $code;
		if ($message) {
			$this->responseXML->error = $message;
		}
		foreach ($attributes as $attr=>$val) {
			$this->responseXML->error[$attr] = $val;
		}
		foreach ($elements as $name=>$val) {
			$this->responseXML->$name = $val;
		}
		
		$xmlstr = $this->responseXML->asXML();
		
		// Strip XML declaration, since it will be added automatically when in XML mode
		$xmlstr = preg_replace("/<\?xml.+?>\n/", '', $xmlstr);
		
		echo $xmlstr;
		Z_Core::exitClean();
	}
	
	
	private function e409($message) {
		header("HTTP/1.1 409 Conflict");
		die($message);
	}
	
	
	private function sessionGC() {
		$sql = "SELECT sessionID FROM sessions
				WHERE (UNIX_TIMESTAMP() - UNIX_TIMESTAMP(timestamp)) > ?";
		$sessionIDs = Z_SessionsDB::columnQuery($sql, $this->sessionLifetime);
		if ($sessionIDs) {
			$cacheKeys = array();
			foreach ($sessionIDs as $sessionID) {
				$cacheKeys[] = 'syncSession_' . $sessionID;
			}
			$existingSessions = Z_Core::$MC->get($cacheKeys);
			$existingSessionIDs = array();
			if (!$existingSessions) {
				return;
			}
			foreach ($existingSessions as $session) {
				$existingSessionIDs[] = $session['sessionID'];
			}
			// Get all ids in $sessionIDs that aren't in $existingSessionIDs
			$sessionIDs = array_diff($sessionIDs, $existingSessionIDs);
			if (!$sessionIDs) {
				return;
			}
			$sessionIDs = array_values($sessionIDs);
			$sql = "DELETE FROM sessions WHERE sessionID IN ("
				. implode(', ', array_fill(0, sizeOf($sessionIDs), '?')) . ")";
			Z_SessionsDB::query($sql, $sessionIDs);
		}
	}
}
?>
