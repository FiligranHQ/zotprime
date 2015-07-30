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
	private $validAPIVersions = array(9);
	private $sessionLifetime = 3600;
	
	private $profile = false;
	private $profileShard = 0;
	
	private $apiVersion;
	private $sessionID = null;
	private $userID = null;
	private $userLibraryID = null;
	private $ipAddress = null;
	private $updateKey = null;
	private $responseXML = null;
	
	private $startTime = false;
	private $timeLogged = false;
	
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
	
	public function init($extra) {
		require_once('../model/Error.inc.php');
		
		if ($this->profile) {
			Zotero_DB::profileStart($this->profileShard);
		}
		$this->startTime = microtime(true);
		
		// Inflate gzipped data
		if (!empty($_SERVER['HTTP_CONTENT_ENCODING']) && $_SERVER['HTTP_CONTENT_ENCODING'] == 'gzip') {
			$gzdata = file_get_contents('php://input');
			
			// Firefox 12 and above include the standard gzip header,
			// which needs to be stripped
			if (substr($gzdata, 0, 3) == (chr(31) . chr(139) . chr(8))) { // 1F 8B 08
				$gzdata = substr($gzdata, 10);
			}
			
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
			if ($this->action == 'index') {
				echo "Nothing to see here.";
				exit;
			}
			
			$this->error(400, 'NO_API_VERSION', "API version not specified");
		}
		
		$upgradeMessage = "Due to improvements made to sync functionality, you must upgrade to Zotero 3.0 or later from zotero.org to sync your Zotero library.";
		
		if (isset($_SERVER['HTTP_X_ZOTERO_VERSION'])) {
			require_once('../model/ToolkitVersionComparator.inc.php');
			
			// Avoid infinite loop due to client bug
			if ($_SERVER['HTTP_X_ZOTERO_VERSION'] == "2.0b6") {
				die ("Please upgrade to the latest version of Zotero from zotero.org.");
			}
			else if (ToolkitVersionComparator::compare($_SERVER['HTTP_X_ZOTERO_VERSION'], "3.0") < 0) {
				$this->error(400, 'UPGRADE_REQUIRED', $upgradeMessage);
			}
			else if (isset($_SERVER['HTTP_USER_AGENT'])
					&& (strpos($_SERVER['HTTP_USER_AGENT'], "Firefox/17") !== false
						|| strpos($_SERVER['HTTP_USER_AGENT'], "Firefox/18") !== false
						|| strpos($_SERVER['HTTP_USER_AGENT'], "Firefox/19") !== false
						|| strpos($_SERVER['HTTP_USER_AGENT'], "Firefox/20") !== false
						|| strpos($_SERVER['HTTP_USER_AGENT'], "Firefox/21") !== false
						|| strpos($_SERVER['HTTP_USER_AGENT'], "Firefox/22") !== false)
					&& ToolkitVersionComparator::compare($_SERVER['HTTP_X_ZOTERO_VERSION'], "3.0.9") < 0) {
				$this->error(400, 'UPGRADE_REQUIRED', "Your version of Zotero is not compatible with Firefox 17 or later. Please upgrade to the latest version of Zotero from zotero.org.");
			}
		}
		
		if (!in_array($_REQUEST['version'], $this->validAPIVersions)) {
			if ($_REQUEST['version'] < 9) {
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
			StatsD::increment("sync.login.failure");
			if (isset($_SERVER['HTTP_X_ZOTERO_VERSION']) && $_SERVER['HTTP_X_ZOTERO_VERSION'] == "2.0b6") {
				die ("Username/password not accepted");
			}
			$this->error(403, 'INVALID_LOGIN', "Username/password not accepted");
		}
		
		StatsD::increment("sync.login.success");
		
		$sessionID = md5($userID . uniqid(rand(), true) . $password);
		$ip = IPAddress::getIP();
		
		$sql = "INSERT INTO sessions (sessionID, userID, ipAddress)
					VALUES (?,?,INET_ATON(?))";
		Zotero_DB::query($sql, array($sessionID, $userID, $ip));
		
		Z_Core::$MC->set(
			"syncSession_$sessionID",
			array(
				'sessionID' => $sessionID,
				'userID' => $userID
			),
			// See note in sessionCheck()
			$this->sessionLifetime - 600
		);
		
		$this->responseXML->sessionID = $sessionID;
		$this->end();
	}
	
	
	public function logout() {
		Zotero_DB::beginTransaction();
		
		$this->sessionCheck();
		
		$sql = "DELETE FROM sessions WHERE sessionID=?";
		Zotero_DB::query($sql, $this->sessionID);
		
		Z_Core::$MC->delete("syncSession_" . $this->sessionID);
		
		Zotero_DB::commit();
		
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
			$lastsync = (int) $_REQUEST['lastsync'];
		}
		else {
			$this->error(400, 'INVALID_LAST_SYNC_TIME', 'Last sync time is invalid');
		}
		
		$this->sessionCheck();
		
		if (isset($_SERVER['HTTP_X_ZOTERO_VERSION'])) {
			require_once('../model/ToolkitVersionComparator.inc.php');
			
			if (ToolkitVersionComparator::compare($_SERVER['HTTP_X_ZOTERO_VERSION'], "2.0.4") < 0) {
				$futureUsers = Z_Core::$MC->get('futureUsers');
				if (!$futureUsers) {
					$futureUsers = Zotero_DB::columnQuery("SELECT userID FROM futureUsers");
					Z_Core::$MC->set('futureUsers', $futureUsers, 1800);
				}
				
				if (in_array($this->userID, $futureUsers)) {
					Z_Core::logError("Blocking sync for future user " . $this->userID . " with version " . $_SERVER['HTTP_X_ZOTERO_VERSION']);
					$upgradeMessage = "Due to improvements made to sync functionality, you must upgrade to Zotero 2.0.6 or later (via Firefox's Tools menu -> Add-ons -> Extensions -> Find Updates or from zotero.org) to continue syncing your Zotero library.";
					$this->error(400, 'UPGRADE_REQUIRED', $upgradeMessage);
				}
			}
		}
		
		$doc = new DOMDocument();
		$domResponse = dom_import_simplexml($this->responseXML);
		$domResponse = $doc->importNode($domResponse, true);
		$doc->appendChild($domResponse);
		
		try {
			$result = Zotero_Sync::getSessionDownloadResult($this->sessionID);
		}
		catch (Exception $e) {
			$this->handleUpdatedError($e);
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
			
			$queue = true;
			if (Z_ENV_TESTING_SITE && !empty($_GET['noqueue'])) {
				$queue = false;
			}
			
			// TEMP
			$cacheKeyExtra = (!empty($_POST['ft']) ? json_encode($_POST['ft']) : "")
				. (!empty($_POST['ftkeys']) ? json_encode($_POST['ftkeys']) : "");
			
			// If we have a cached response, return that
			try {
				$startedTimestamp = microtime(true);
				$cached = Zotero_Sync::getCachedDownload($this->userID, $lastsync, $this->apiVersion, $cacheKeyExtra);
				
				// Not locked, so clear wait index
				$this->clearWaitTime($this->sessionID);
				
				if ($cached) {
					$this->responseXML = simplexml_load_string($cached, "SimpleXMLElement", LIBXML_COMPACT | LIBXML_PARSEHUGE);
					// TEMP
					if (!$this->responseXML) {
						error_log("Invalid cached XML data -- stripping control characters");
						// Strip control characters in XML data
						$cached = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $cached);
						$this->responseXML = simplexml_load_string($cached, "SimpleXMLElement", LIBXML_COMPACT | LIBXML_PARSEHUGE);
					}
					
					$duration = round((float) microtime(true) - $startedTimestamp, 2);
					Zotero_Sync::logDownload(
						$this->userID,
						round($lastsync),
						strlen($cached),
						$this->ipAddress ? $this->ipAddress : 0,
						0,
						$duration,
						$duration,
						(int) !$this->responseXML
					);
					
					StatsD::increment("sync.process.download.cache.hit");
					
					if (!$this->responseXML) {
						$msg = "Error parsing cached XML for user " . $this->userID;
						error_log($msg);
						$this->handleUpdatedError(new Exception($msg));
					}
					
					$this->end();
				}
			}
			catch (Exception $e) {
				$msg = $e->getMessage();
				if (strpos($msg, "Too many connections") !== false) {
					$msg = "'Too many connections' from MySQL";
				}
				else {
					$msg = "'$msg'";
				}
				Z_Core::logError("Warning: $msg getting cached download");
				StatsD::increment("sync.process.download.cache.error");
			}
			
			try {
				$num = Zotero_Items::countUpdated($this->userID, $lastsync, 5);
			}
			catch (Exception $e) {
				// We can get a MySQL lock timeout here if the upload starts
				// after the write lock check above but before we get here
				$this->handleUpdatedError($e);
			}
			
			// If nothing updated, or if just a few objects and processing is enabled, process synchronously
			if ($num == 0 || ($num < 5 && Z_CONFIG::$PROCESSORS_ENABLED)) {
				$queue = false;
			}
			
			$params = [];
			if (isset($_POST['ft'])) $params['ft'] = $_POST['ft'];
			if (isset($_POST['ftkeys'])) {
				$queue = true;
				$params['ftkeys'] = $_POST['ftkeys'];
			}
			
			if ($queue) {
				Zotero_Sync::queueDownload($this->userID, $this->sessionID, $lastsync, $this->apiVersion, $num, $params);
				
				try {
					Zotero_Processors::notifyProcessors('download');
				}
				catch (Exception $e) {
					Z_Core::logError($e);
				}
				
				$locked = $this->responseXML->addChild('locked');
				$locked['wait'] = 1000;
			}
			else {
				try {
					Zotero_Sync::processDownload($this->userID, $lastsync, $doc, $params);
					$this->responseXML = simplexml_import_dom($doc);
					
					StatsD::increment("sync.process.download.immediate.success");
				}
				catch (Exception $e) {
					StatsD::increment("sync.process.download.immediate.error");
					
					$this->handleUpdatedError($e);
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
		
		if ($_REQUEST['updateKey'] != Zotero_Users::getUpdateKey($this->userID)) {
			$this->e409("Server data has changed since last retrieval");
		}
		
		// TODO: change to POST
		if (empty($_REQUEST['data'])) {
			$this->error(400, 'MISSING_UPLOAD_DATA', 'Uploaded data not provided');
		}
		
		$xmldata =& $_REQUEST['data'];
		
		try {
			$doc = new DOMDocument();
			$doc->loadXML($xmldata, LIBXML_PARSEHUGE);
			
			// For huge uploads, make sure notes aren't bigger than SimpleXML can parse
			if (strlen($xmldata) > 7000000) {
				$xpath = new DOMXPath($doc);
				$results = $xpath->query('/data/items/item/note[string-length(text()) > ' . Zotero_Notes::$MAX_NOTE_LENGTH . ']');
				if ($results->length) {
					$noteElem = $results->item(0);
					$text = $noteElem->textContent;
					$libraryID = $noteElem->parentNode->getAttribute('libraryID');
					$key = $noteElem->parentNode->getAttribute('key');
					
					// UTF-8 &nbsp; (0xC2 0xA0) isn't trimmed by default
					$whitespace = chr(0x20) . chr(0x09) . chr(0x0A) . chr(0x0D)
					. chr(0x00) . chr(0x0B) . chr(0xC2) . chr(0xA0);
					$excerpt = iconv(
						"UTF-8",
						"UTF-8//IGNORE",
						Zotero_Notes::noteToTitle(trim($text), true)
					);
					$excerpt = trim($excerpt, $whitespace);
					// If tag-stripped version is empty, just return raw HTML
					if ($excerpt == '') {
						$excerpt = iconv(
							"UTF-8",
							"UTF-8//IGNORE",
							preg_replace(
								'/\s+/',
								' ',
								mb_substr(trim($text), 0, Zotero_Notes::$MAX_TITLE_LENGTH)
								)
							);
						$excerpt = html_entity_decode($excerpt);
						$excerpt = trim($excerpt, $whitespace);
					}
					
					$msg = "=Note '" . $excerpt . "...' too long";
					if ($key) {
						$msg .= " for item '" . $libraryID . "/" . $key . "'";
					}
					throw new Exception($msg, Z_ERROR_NOTE_TOO_LONG);
				}
			}
		}
		catch (Exception $e) {
			$this->handleUploadError($e, $xmldata);
		}
		
		function relaxNGErrorHandler($errno, $errstr) {
			//Z_Core::logError($errstr);
		}
		set_error_handler('relaxNGErrorHandler');
		set_time_limit(60);
		if (!$doc->relaxNGValidate(Z_ENV_MODEL_PATH . 'relax-ng/upload.rng')) {
			$id = substr(md5(uniqid(rand(), true)), 0, 10);
			$str = date("D M j G:i:s T Y") . "\n";
			$str .= "IP address: " . $_SERVER['REMOTE_ADDR'] . "\n";
			if (isset($_SERVER['HTTP_X_ZOTERO_VERSION'])) {
				$str .= "Version: " . $_SERVER['HTTP_X_ZOTERO_VERSION'] . "\n";
			}
			$str .= "Error: RELAX NG validation failed\n\n";
			$str .= $xmldata;
			if (!file_put_contents(Z_CONFIG::$SYNC_ERROR_PATH . $id, $str)) {
				error_log("Unable to save error report to " . Z_CONFIG::$SYNC_ERROR_PATH . $id);
			}
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
				$affectedLibraries = Zotero_Sync::parseAffectedLibraries($xmldata);
				// Relations-only uploads don't have affected libraries
				if (!$affectedLibraries) {
					$affectedLibraries = array(Zotero_Users::getLibraryIDFromUserID($this->userID));
				}
				Zotero_Sync::queueUpload($this->userID, $this->sessionID, $xmldata, $affectedLibraries);
				
				try {
					Zotero_Processors::notifyProcessors('upload');
					Zotero_Processors::notifyProcessors('error');
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
			$this->handleUploadError($e, $xmldata);
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
			$this->handleUploadError($result['exception'], $result['xmldata']);
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
		
		StatsD::increment("sync.clear");
		
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
			$this->error($this->apiVersion >= 9 ? 403 : 500, 'INVALID_SESSION_ID', "Invalid session ID");
		}
		
		$sessionID = $_REQUEST['sessionid'];
		
		$session = Z_Core::$MC->get("syncSession_$sessionID");
		$userID = $session ? $session['userID'] : null;
		// TEMP: can switch to just $session
		$ipAddress = isset($session['ipAddress']) ? $session['ipAddress'] : null;
		if (!$userID) {
			$sql = "SELECT userid, (UNIX_TIMESTAMP(NOW())-UNIX_TIMESTAMP(timestamp)) AS age,
					INET_NTOA(ipAddress) AS ipAddress FROM sessions WHERE sessionID=?";
			$session = Zotero_DB::rowQuery($sql, $sessionID);
			
			if (!$session) {
				$this->error($this->apiVersion >= 9 ? 403 : 500, 'INVALID_SESSION_ID', "Invalid session ID");
			}
			
			if ($session['age'] > $this->sessionLifetime) {
				$this->error($this->apiVersion >= 9 ? 403 : 500, 'SESSION_TIMED_OUT', "Session timed out");
			}
			
			$userID = $session['userid'];
			$ipAddress = $session['ipAddress'];
		}
		
		$updated = Z_Core::$MC->set(
			"syncSession_$sessionID",
			array(
				'sessionID' => $sessionID,
				'userID' => $userID,
				'ipAddress' => $ipAddress
			),
			// Store in memcached for 10 minutes less than session timeout,
			// since we update the DB at a minimum of every 20 minutes
			// and a memory-only session could cause FK errors
			$this->sessionLifetime - 1200
		);
		
		// Every 20 minutes, update the timestamp in the DB
		if (!Z_Core::$MC->get("syncSession_" . $sessionID . "_dbUpdated")) {
			$sql = "UPDATE sessions SET timestamp=NOW() WHERE sessionID=?";
			Zotero_DB::query($sql, $sessionID);
			
			Z_Core::$MC->set("syncSession_" . $sessionID . "_dbUpdated", true, 1200);
		}
		
		$this->sessionID = $sessionID;
		$this->userID = $userID;
		$this->userLibraryID = Zotero_Users::getLibraryIDFromUserID($userID);
		$this->ipAddress = $ipAddress;
	}
	
	
	private function getWaitTime($sessionID) {
		$cacheKey = 'syncWaitIndex_' . $sessionID;
		$index = Z_Core::$MC->get($cacheKey);
		if ($index === false) {
			Z_Core::$MC->add($cacheKey, 1);
			$index = 0;
		}
		
		if ($index == 0) {
			$wait = 2;
		}
		else if ($index < 5) {
			$wait = 5;
		}
		else if ($index < 9) {
			$wait = 25;
		}
		else if ($index < 13) {
			$wait = 45;
		}
		else if ($index < 23) {
			$wait = 70;
		}
		else {
			$wait = 130;
		}
		
		Z_Core::$MC->increment($cacheKey);
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
	
	
	private function handleUpdatedError(Exception $e) {
		if ($this->responseXML) {
			unset($this->responseXML->updated);
		}
		else {
			$this->responseXML = Zotero_Sync::getResponseXML($this->apiVersion);
		}
		
		$msg = $e->getMessage();
		
		//if (strpos($msg, "Can't connect to MySQL server on") !== false) {
		//	$this->error(503, 'SERVER_ERROR', "Syncing is currently unavailable for some users due to a server issue. We're working to restore service as soon as possible. Our apologies for the inconvenience.");
		//}
		
		if (strpos($msg, "Lock wait timeout exceeded; try restarting transaction") !== false
				|| strpos($msg, "Deadlock found when trying to get lock; try restarting transaction") !== false
				|| strpos($msg, "Too many connections") !== false
				|| strpos($msg, "Can't connect to MySQL server") !==false
				|| strpos($msg, " is down") !==false
				|| $e->getCode() == Z_ERROR_SHARD_UNAVAILABLE) {
			$waitTime = $this->getWaitTime($this->sessionID);
			Z_Core::logError("WARNING: $msg -- sending sync wait ($waitTime)");
			$locked = $this->responseXML->addChild('locked');
			$locked['wait'] = $waitTime;
			$this->end();
		}
		
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
			$str .= "Error: " . $e;
			$str .= $this->responseXML->saveXML();
			if (!file_put_contents(Z_CONFIG::$SYNC_ERROR_PATH . $id, $str)) {
				error_log("Unable to save error report to " . Z_CONFIG::$SYNC_ERROR_PATH . $id);
			}
			
			$this->error(500, 'INVALID_OUTPUT', "Invalid response from server (Report ID: $id)");
		}
	}
	
	
	private function handleUploadError(Exception $e, $xmldata) {
		$msg = $e->getMessage();
		if ($msg[0] == '=') {
			$msg = substr($msg, 1);
			$explicit = true;
			
			// TODO: more specific error messages
		}
		else {
			$explicit = false;
		}
		
		switch ($e->getCode()) {
			case Z_ERROR_TAG_TOO_LONG:
			case Z_ERROR_COLLECTION_TOO_LONG:
				break;
			
			default:
				Z_Core::logError($msg);
		}
		
		if (!$explicit && Z_ENV_TESTING_SITE) {
			switch ($e->getCode()) {
				case Z_ERROR_COLLECTION_NOT_FOUND:
				case Z_ERROR_CREATOR_NOT_FOUND:
				case Z_ERROR_ITEM_NOT_FOUND:
				case Z_ERROR_TAG_TOO_LONG:
				case Z_ERROR_LIBRARY_ACCESS_DENIED:
				case Z_ERROR_TAG_LINKED_ITEM_NOT_FOUND:
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
			switch ($e->getCode()) {
				// Don't log uploaded data for some errors
				case Z_ERROR_TAG_TOO_LONG:
				case Z_ERROR_FIELD_TOO_LONG:
				case Z_ERROR_NOTE_TOO_LONG:
				case Z_ERROR_COLLECTION_TOO_LONG:
					break;
				
				default:
					$str .= "\n\n" . $xmldata;
			}
			if (!file_put_contents(Z_CONFIG::$SYNC_ERROR_PATH . $id, $str)) {
				error_log("Unable to save error report to " . Z_CONFIG::$SYNC_ERROR_PATH . $id);
			}
		}
		
		Zotero_DB::rollback(true);
		
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
				error_log($e);
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
			
			case Z_ERROR_NOTE_TOO_LONG:
				preg_match("/Note '(.+)' too long(?: for item '(.+)\/(.+)')?/s", $msg, $matches);
				if ($matches) {
					$name = $matches[1];
					$libraryID = false;
					if (isset($matches[2])) {
						$libraryID = (int) $matches[2];
						$itemKey = $matches[3];
						if (Zotero_Libraries::getType($libraryID) == 'group') {
							$groupID = Zotero_Groups::getGroupIDFromLibraryID($libraryID);
							$group = Zotero_Groups::get($groupID);
							$libraryName = $group->name;
						}
						else {
							$libraryName = false;
						}
					}
					else {
						$itemKey = '';
					}
					$showNoteKey = false;
					if (isset($_SERVER['HTTP_X_ZOTERO_VERSION'])) {
						require_once('../model/ToolkitVersionComparator.inc.php');
						$showNoteKey = ToolkitVersionComparator::compare($_SERVER['HTTP_X_ZOTERO_VERSION'], "4.0.27") < 0;
					}
					if ($showNoteKey) {
						$this->error(400, "ERROR_PROCESSING_UPLOAD_DATA",
							"The note '" . mb_substr($name, 0, 50) . "…' in "
							. ($libraryName === false
								? "your library "
								: "the group '$libraryName' ")
							. "is too long to sync to zotero.org.\n\n"
							. "Search for the excerpt above or copy and paste "
							. "'$itemKey' into the Zotero search bar. "
							. "Shorten the note, or delete it and empty the Zotero "
							. "trash, and then try syncing again."
						);
					}
					else {
						$this->error(400, "NOTE_TOO_LONG",
							"The note '" . mb_substr($name, 0, 50) . "…' in "
							. ($libraryName === false
								? "your library "
								: "the group '$libraryName' ")
							. "is too long to sync to zotero.org.\n\n"
							. "Shorten the note, or delete it and empty the Zotero "
							. "trash, and then try syncing again.",
							[],
							$libraryID ? ["item" => $libraryID . "/" . $itemKey] : []
						);
					}
				}
				break;
			
			case Z_ERROR_FIELD_TOO_LONG:
				preg_match("/(.+) field value '(.+)\.\.\.' too long(?: for item '(.+)')?/s", $msg, $matches);
				if ($matches) {
					$fieldName = $matches[1];
					$value = $matches[2];
					if (isset($matches[3])) {
						$parts = explode("/", $matches[3]);
						$libraryID = (int) $parts[0];
						$itemKey = $parts[1];
						if (Zotero_Libraries::getType($libraryID) == 'group') {
							$groupID = Zotero_Groups::getGroupIDFromLibraryID($libraryID);
							$group = Zotero_Groups::get($groupID);
							$libraryName = "the group '" . $group->name . "'";
						}
						else {
							$libraryName = "your personal library";
						}
					}
					else {
						$libraryName = "one of your libraries";
						$itemKey = false;
					}
					$this->error(400, "ERROR_PROCESSING_UPLOAD_DATA",
						"The $fieldName field value '{$value}…' in $libraryName is "
						. "too long to sync to zotero.org.\n\n"
						. "Search for the excerpt above "
						. ($itemKey === false ? "using " : "or copy and paste "
						. "'$itemKey' into ") . "the Zotero search bar. "
						. "Shorten the field, or delete the item and empty the "
						. "Zotero trash, and then try syncing again."
					);
				}
				break;
			
			case Z_ERROR_ARRAY_SIZE_MISMATCH:
				$this->error(400, 'DATABASE_TOO_LARGE',
					"Databases of this size cannot yet be synced. Please check back soon. (Report ID: $id)");
				break;
			
			case Z_ERROR_TAG_LINKED_ITEM_NOT_FOUND:
				$this->error(400, 'WRONG_LIBRARY_TAG_ITEM',
					"Error processing uploaded data (Report ID: $id)");
				break;
			
			case Z_ERROR_SHARD_READ_ONLY:
			case Z_ERROR_SHARD_UNAVAILABLE:
				$this->error(503, 'SERVER_ERROR', Z_CONFIG::$MAINTENANCE_MESSAGE);
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
		
		if (preg_match("/Incorrect datetime value: '([0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2})' "
				. "for column 'date(Added|Modified)'/", $msg, $matches)) {
			
			if (isset($_SERVER['HTTP_X_ZOTERO_VERSION'])) {
				require_once('../model/ToolkitVersionComparator.inc.php');
				
				if (ToolkitVersionComparator::compare($_SERVER['HTTP_X_ZOTERO_VERSION'], "2.1rc1") < 0) {
					$msg = "Invalid timestamp '{$matches[1]}' in uploaded data. Upgrade to Zotero 2.1rc1 when available to fix automatically.";
				}
				else {
					$msg = "Invalid timestamp '{$matches[1]}' in uploaded data. Sync again to correct automatically.";
				}
			}
			else {
				$msg = "Invalid timestamp '{$matches[1]}' in uploaded data. Upgrade to Zotero 2.1rc1 when available to fix automatically.";
			}
			
			$this->error(400, 'INVALID_TIMESTAMP', $msg);
		}
		
		$this->error(500, 'ERROR_PROCESSING_UPLOAD_DATA',
			$explicit
				? $msg
				: "Error processing uploaded data (Report ID: $id)"
		);
	}
	
	
	private function end() {
		if ($this->profile) {
			Zotero_DB::profileEnd($this->profileShard, false);
		}
		
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
		
		$this->logRequestTime();
		Z_Core::exitClean();
	}
	
	
	private function currentRequestTime() {
		return round(microtime(true) - $this->startTime, 2);
	}
	
	
	private function logRequestTime($point=false) {
		if ($this->timeLogged) {
			return;
		}
		$time = $this->currentRequestTime();
		if ($time > 5) {
			$this->timeLogged = true;
			error_log(
				"Slow request " . ($point ? "at point " . $point : "") . ": "
				. $time . " sec for "
				. $_SERVER['REQUEST_METHOD'] . " " . $_SERVER['REQUEST_URI']
			);
		}
	}
	
	
	private function e409($message) {
		header("HTTP/1.1 409 Conflict");
		die($message);
	}
}
?>
