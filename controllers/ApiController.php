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

class ApiController extends Controller {
	private $writeTokenCacheTime = 43200; // 12 hours
	
	private $profile = false;
	private $profileShard = 0;
	private $timeLogThreshold = 5;
	
	protected $method;
	protected $uri;
	protected $queryParams = array();
	protected $ifUnmodifiedSince;
	protected $body;
	protected $apiKey;
	protected $responseXML;
	protected $responseCode = 200;
	protected $userID; // request user
	protected $permissions;
	protected $objectUserID; // userID of object owner
	protected $objectGroupID; // groupID of object owner
	protected $objectLibraryID; // libraryID of object owner
	protected $scopeObject;
	protected $scopeObjectID;
	protected $scopeObjectKey;
	protected $scopeObjectName;
	protected $objectID;
	protected $objectKey;
	protected $objectName;
	protected $subset;
	protected $singleObject;
	protected $fileMode;
	protected $fileView;
	protected $httpAuth = false;
	protected $cookieAuth = false;
	protected $libraryVersion;
	
	private $startTime = false;
	private $timeLogged = false;
	
	
	public function init($extra) {
		if (!Z_CONFIG::$API_ENABLED) {
			$this->e503(Z_CONFIG::$MAINTENANCE_MESSAGE);
		}
		
		set_exception_handler(array($this, 'handleException'));
		set_error_handler(array($this, 'handleError'), E_USER_ERROR | E_RECOVERABLE_ERROR);
		require_once('../model/Error.inc.php');
		
		$this->startTime = microtime(true);
		register_shutdown_function(array($this, 'checkDBTransactionState'));
		register_shutdown_function(array($this, 'logTotalRequestTime'));
		register_shutdown_function(array($this, 'checkForFatalError'));
		$this->method = $_SERVER['REQUEST_METHOD'];
		
		if (!in_array($this->method, array('HEAD', 'GET', 'PUT', 'POST', 'DELETE', 'PATCH'))) {
			$this->e501();
		}
		
		StatsD::increment("api.request.method." . strtolower($this->method), 0.25);
		
		// There doesn't seem to be a way for PHP to start processing the request
		// before the entire body is sent, so an Expect: 100 Continue will,
		// depending on the client, either fail or cause a delay while the client
		// waits for the 100 response. To make this explicit, we return an error.
		if (!empty($_SERVER['HTTP_EXPECT'])) {
			header("HTTP/1.1 417 Expectation Failed");
			die("Expect header is not supported");
		}
		
		if (in_array($this->method, array('POST', 'PUT', 'PATCH'))) {
			$this->ifUnmodifiedSince =
				isset($_SERVER['HTTP_IF_UNMODIFIED_SINCE'])
					? strtotime($_SERVER['HTTP_IF_UNMODIFIED_SINCE']) : false;
			
			$this->body = file_get_contents("php://input");
			if ($this->body == ""
					&& !in_array($this->action, array(
						'clear',
						'laststoragesync',
						'removestoragefiles'))) {
				$this->e400("$this->method data not provided");
			}
		}
		
		if ($this->profile) {
			Zotero_DB::profileStart($this->profileShard);
		}
		
		// If HTTP Basic Auth credentials provided, authenticate
		if (isset($_SERVER['PHP_AUTH_USER'])) {
			$username = $_SERVER['PHP_AUTH_USER'];
			$password = $_SERVER['PHP_AUTH_PW'];
			
			if ($username == Z_CONFIG::$API_SUPER_USERNAME
					&& $password == Z_CONFIG::$API_SUPER_PASSWORD) {
				$this->userID = 0;
				$this->permissions = new Zotero_Permissions;
				$this->permissions->setSuper();
			}
			
			// Allow HTTP Auth for file access
			else if (!empty($extra['allowHTTP']) || !empty($extra['auth'])) {
				$userID = Zotero_Users::authenticate(
					'password',
					array('username' => $username, 'password' => $password)
				);
				if (!$userID) {
					$this->e401('Invalid login');
				}
				$this->httpAuth = true;
				$this->userID = $userID;
				$this->grantUserPermissions($userID);
			}
		}
		
		if (!isset($this->userID)) {
			if (isset($_GET['key'])) {
				$keyObj = Zotero_Keys::authenticate($_GET['key']);
				if (!$keyObj) {
					$this->e403('Invalid key');
				}
				$this->apiKey = $_GET['key'];
				$this->userID = $keyObj->userID;
				$this->permissions = $keyObj->getPermissions();
				
				// Check X-Zotero-Write-Token if it exists to make sure
				// this isn't a duplicate request
				if ($this->method == 'POST' || $this->method == 'PUT') {
					if ($cacheKey = $this->getWriteTokenCacheKey()) {
						if (Z_Core::$MC->get($cacheKey)) {
							$this->e412("Write token already used");
						}
					}
				}
			}
			// Website cookie authentication
			else if (!empty($_COOKIE) && !empty($_GET['session']) &&
					($this->userID = Zotero_Users::getUserIDFromSession($_COOKIE, $_GET['session']))) {
				$this->grantUserPermissions($this->userID);
				$this->cookieAuth = true;
			}
			// No credentials provided
			else {
				if (!empty($_GET['auth']) || !empty($extra['auth'])) {
					$this->e401();
				}
				
				// Explicit auth request or not a GET request
				if ($this->method != "GET") {
					$this->e403('You must specify a key to access the Zotero API.');
				}
				
				// Anonymous request
				$this->permissions = new Zotero_Permissions;
				$this->permissions->setAnonymous();
			}
		}
		
		$this->uri = Z_CONFIG::$API_BASE_URI . substr($_SERVER["REQUEST_URI"], 1);
		
		// Get object user
		if (isset($this->objectUserID)) {
			if (!$this->objectUserID) {
				$this->e400("Invalid user ID", Z_ERROR_INVALID_INPUT);
			}
			
			try {
				$this->objectLibraryID = Zotero_Users::getLibraryIDFromUserID($this->objectUserID);
			}
			catch (Exception $e) {
				if ($e->getCode() == Z_ERROR_USER_NOT_FOUND) {
					try {
						Zotero_Users::addFromWWW($this->objectUserID);
					}
					catch (Exception $e) {
						if ($e->getCode() == Z_ERROR_USER_NOT_FOUND) {
							$this->e404();
						}
						throw ($e);
					}
					$this->objectLibraryID = Zotero_Users::getLibraryIDFromUserID($this->objectUserID);
				}
				else {
					throw ($e);
				}
			}
			
			// Make sure user isn't banned
			if (!Zotero_Users::isValidUser($this->objectUserID)) {
				$this->e404();
			}
		}
		// Get object group
		else if (isset($this->objectGroupID)) {
			if (!$this->objectGroupID) {
				$this->e400("Invalid group ID", Z_ERROR_INVALID_INPUT);
			}
			// Make sure group exists
			$group = Zotero_Groups::get($this->objectGroupID);
			if (!$group) {
				$this->e404();
			}
			// Don't show groups owned by banned users
			if (!Zotero_Users::isValidUser($group->ownerUserID)) {
				$this->e404();
			}
			$this->objectLibraryID = Zotero_Groups::getLibraryIDFromGroupID($this->objectGroupID);
		}
		
		
		// Return 409 if target library is locked
		switch ($this->method) {
			case 'POST':
			case 'PUT':
			case 'DELETE':
				switch ($this->action) {
					// Library lock doesn't matter for some admin requests
					case 'keys':
					case 'storageadmin':
						break;
					
					default:
						if ($this->objectLibraryID && Zotero_Libraries::isLocked($this->objectLibraryID)) {
							$this->e409("Target library is locked");
						}
						break;
				}
		}
		
		$this->scopeObject = !empty($extra['scopeObject']) ? $extra['scopeObject'] : $this->scopeObject;
		$this->subset = !empty($extra['subset']) ? $extra['subset'] : $this->subset;
		
		$this->fileMode = !empty($extra['file'])
							? (!empty($_GET['info']) ? 'info' : 'download')
							: false;
		$this->fileView = !empty($extra['view']);
		
		$this->singleObject = $this->objectKey && !$this->subset;
		
		$this->checkLibraryIfModifiedSinceVersion($this->action);
		
		$this->queryParams = Zotero_API::parseQueryParams(
			$_SERVER['QUERY_STRING'],
			$this->action,
			$this->singleObject,
			!empty($_SERVER['HTTP_THE_FUTURE_IS_NOW'])
		);
	}
	
	
	public function index() {
		$this->e400("Invalid Request");
	}
	
	
	public function noop() {
		echo "Nothing to see here.";
		exit;
	}
	
	
	/**
	 * Used for integration tests
	 *
	 * Valid only on testing site
	 */
	public function clear() {
		if (!$this->permissions->isSuper()) {
			$this->e404();
		}
		
		if (!Z_ENV_TESTING_SITE) {
			$this->e404();
		}
		
		$this->allowMethods(array('POST'));
		
		Zotero_Libraries::clearAllData($this->objectLibraryID);
		
		$this->e204();
	}
	
	
	/**
	 * Used for integration tests
	 *
	 * Valid only on testing site
	 */
	public function testSetup() {
		if (!$this->permissions->isSuper()) {
			$this->e404();
		}
		
		if (!Z_ENV_TESTING_SITE) {
			$this->e404();
		}
		
		if (empty($_GET['u'])) {
			throw new Exception("User not provided (e.g., ?u=1)");
		}
		$userID = $_GET['u'];
		
		// Clear keys
		$keys = Zotero_Keys::getUserKeys($userID);
		foreach ($keys as $keyObj) {
			$keyObj->erase();
		}
		$keys = Zotero_Keys::getUserKeys($userID);
		if ($keys) {
			throw new Exception("Keys still exist");
		}
		
		// Clear data
		Zotero_Users::clearAllData($userID);
		
		$this->responseXML = new SimpleXMLElement("<ok/>");
		$this->end();
	}
	
	
	//
	// Protected methods
	//
	protected function getFeedNamePrefix($libraryID=false) {
		$prefix = "Zotero / ";
		if ($libraryID) {
			$type = Zotero_Libraries::getType($this->objectLibraryID);
		}
		else {
			$type = false;
		}
		switch ($type) {
			case "user":
				$title = $prefix . Zotero_Libraries::getName($this->objectLibraryID);
				break;
			
			case "group":
				$title = $prefix . "" . Zotero_Libraries::getName($this->objectLibraryID) . " Group";
				break;
			
			default:
				return $prefix;
		}
		return $title . " / ";
	}
	
	
	/**
	 * Verify the HTTP method
	 */
	protected function allowMethods($methods, $message="Method not allowed") {
		if (!in_array($this->method, $methods)) {
			header("Allow: " . implode(", ", $methods));
			$this->e405($message);
		}
	}
	
	
	protected function isWriteMethod() {
		return in_array($this->method, array('POST', 'PUT', 'PATCH', 'DELETE'));
	}
	
	
	/**
	 * For single-object requests for some actions, require
	 * Zotero-If-Unmodified-Since-Version (or the deprecated If-Match)
	 * and make sure the object hasn't been modified
	 */
	protected function checkObjectIfUnmodifiedSinceVersion($object, $required=false) {
		$objectType = Zotero_Utilities::getObjectTypeFromObject($object);
		if (!in_array($objectType, array('item', 'collection', 'search'))) {
			throw new Exception("Invalid object type");
		}
		
		if (Z_CONFIG::$TESTING_SITE && !empty($_GET['skipetag'])) {
			return true;
		}
		
		// If-Match (deprecated)
		if ($this->queryParams['apiVersion'] < 2) {
			if (empty($_SERVER['HTTP_IF_MATCH'])) {
				if ($required) {
					$this->e428("If-Match must be provided for write requests");
				}
				else {
					return false;
				}
			}
			
			if (!preg_match('/^"?([a-f0-9]{32})"?$/', $_SERVER['HTTP_IF_MATCH'], $matches)) {
				$this->e400("Invalid ETag in If-Match header");
			}
			
			if ($object->etag != $matches[1]) {
				$this->e412("ETag does not match current version of $objectType");
			}
		}
		// Zotero-If-Unmodified-Since-Version
		else {
			if (empty($_SERVER['HTTP_ZOTERO_IF_UNMODIFIED_SINCE_VERSION'])) {
				if ($required) {
					$this->e428("Zotero-If-Unmodified-Since must be provided for write requests");
				}
				else {
					return false;
				}
			}
			$version = $_SERVER['HTTP_ZOTERO_IF_UNMODIFIED_SINCE_VERSION'];
			
			if (!is_numeric($version)) {
				$this->e400("Invalid Zotero-If-Unmodified-Since-Version value");
			}
			
			// Zotero_Item requires 'itemVersion'
			$prop = $objectType == 'item' ? 'itemVersion' : 'version';
			
			if ($object->$prop != $version) {
				$this->libraryVersion = $object->$prop;
				$this->e412(ucwords($objectType)
					. " has been modified since specified version "
					. "(expected $version, found " . $object->$prop . ")");
			}
		}
		return true;
	}
	
	
	/**
	 * For multi-object requests for some actions, require
	 * Zotero-If-Unmodified-Since-Version and make sure the library
	 * hasn't been modified
	 *
	 * @param boolean $required Return 428 if header is missing
	 * @return boolean True if library version was checked, false if not
	 */
	protected function checkLibraryIfUnmodifiedSinceVersion($required=false) {
		if (Z_CONFIG::$TESTING_SITE && !empty($_GET['skipetag'])) {
			continue;
		}
		
		if (empty($_SERVER['HTTP_ZOTERO_IF_UNMODIFIED_SINCE_VERSION'])) {
			if ($required) {
				$this->e428("Zotero-If-Unmodified-Since-Version not provided");
			}
			return false;
		}
		
		$version = $_SERVER['HTTP_ZOTERO_IF_UNMODIFIED_SINCE_VERSION'];
		
		if (!is_numeric($version)) {
			$this->e400("Invalid Zotero-If-Unmodified-Since-Version value");
		}
		
		$libraryVersion = Zotero_Libraries::getVersion($this->objectLibraryID);
		if ($libraryVersion > $version) {
			$this->e412("Library has been modified since specified version");
		}
		return true;
	}
	
	
	/**
	 * For multi-object requests for some actions, return 304 Not Modified
	 * if the library hasn't been updated since Zotero-If-Modified-Since-Version
	 */
	protected function checkLibraryIfModifiedSinceVersion($action) {
		if (!$this->singleObject
				&& in_array($action, array("items", "collections", "searches"))
				&& !empty($_SERVER['HTTP_ZOTERO_IF_MODIFIED_SINCE_VERSION'])
				&& !$this->isWriteMethod()
				&& $this->permissions->canAccess($this->objectLibraryID)
				&& Zotero_Libraries::getVersion($this->objectLibraryID)
					<= $_SERVER['HTTP_ZOTERO_IF_MODIFIED_SINCE_VERSION']) {
			$this->e304("Library has not been modified");
		}
	}
	
	
	protected function requireContentType($contentType) {
		if ($_SERVER['CONTENT_TYPE'] != $contentType) {
			throw new Exception("Content-Type must be '$contentType'", Z_ERROR_INVALID_INPUT);
		}
	}
	
	/**
	 * For HTTP Auth and session-based auth, generate blanket user permissions
	 * manually, since there's no key object
	 */
	protected function grantUserPermissions($userID) {
		$this->permissions = new Zotero_Permissions($userID);
		$libraryID = Zotero_Users::getLibraryIDFromUserID($userID);
		
		// Grant user permissions on own library and all groups
		$this->permissions->setPermission($libraryID, 'library', true);
		$this->permissions->setPermission($libraryID, 'files', true);
		$this->permissions->setPermission($libraryID, 'notes', true);
		$this->permissions->setPermission($libraryID, 'write', true);
		$this->permissions->setPermission(0, 'library', true);
		$this->permissions->setPermission(0, 'write', true);
	}
	
	
	/**
	 * Get a token to pass to the translation server to retain state for multi-item saves
	 */
	protected function getTranslationToken() {
		if (!$this->cookieAuth) {
			return false;
		}
		return md5($this->userID . $_GET['session']);
	}
	
	
	protected function getWriteTokenCacheKey() {
		if (empty($_SERVER['HTTP_X_ZOTERO_WRITE_TOKEN'])) {
			return false;
		}
		if (strlen($_SERVER['HTTP_X_ZOTERO_WRITE_TOKEN']) < 5 || strlen($_SERVER['HTTP_X_ZOTERO_WRITE_TOKEN']) > 32) {
			$this->e400("Write token must be 5-32 characters in length");
		}
		if (!$this->apiKey) {
			$this->e400("Write token cannot be used without an API key");
		}
		return "writeToken_" . md5($this->apiKey . "_" . $_SERVER['HTTP_X_ZOTERO_WRITE_TOKEN']);
	}
	
	
	/**
	 * Handler for HTTP shortcut functions (e404(), e500())
	 */
	public function __call($name, $arguments) {
		if (!preg_match("/^e([1-5])([0-9]{2})$/", $name, $matches)) {
			throw new Exception("Invalid function $name");
		}
		
		$this->responseCode = (int) ($matches[1] . $matches[2]);
		
		// On 4xx or 5xx errors, rollback all open transactions
		// and don't send Zotero-Last-Modified-Version
		if ($matches[1] == "4" || $matches[1] == "5") {
			$this->libraryVersion = null;
			Zotero_DB::rollback(true);
		}
		
		if (isset($arguments[0])) {
			echo htmlspecialchars($arguments[0]);
		}
		else {
			// Default messages for some codes
			switch ($this->responseCode) {
				case 401:
					echo "Access denied";
					break;
				
				case 403:
					echo "Forbidden";
					break;
				
				case 404:
					echo "Not found";
					break;
				
				case 405:
					echo "Method not allowed";
					break;
				
				case 429:
					echo "Too many requests";
					break;
				
				case 500:
					echo "An error occurred";
					break;
				
				case 501:
					echo "Method is not implemented";
					break;
				
				case 503:
					echo "Service unavailable";
					break;
			}
		}
		
		$this->end();
	}
	
	
	protected function redirect($url, $httpCode=302) {
		if (!in_array($httpCode, array(301, 302, 303))) {
			throw new Exception("Invalid redirect code");
		}
		
		$this->libraryVersion = null;
		$this->responseXML = null;
		
		$this->responseCode = $httpCode;
		header("Location: " . $url, false, $httpCode);
		$this->end();
	}
	
	
	protected function end() {
		if ($this->profile) {
			Zotero_DB::profileEnd($this->profileShard, false);
		}
		
		switch ($this->responseCode) {
			case 200:
				break;
			
			case 301:
			case 302:
			case 303:
				// Handled in $this->redirect()
				break;
			
			case 401:
				header('WWW-Authenticate: Basic realm="Zotero API"');
				header('HTTP/1.1 401 Unauthorized');
				break;
			
			// PHP completes these automatically
			case 201:
			case 204:
			case 300:
			case 304:
			case 400:
			case 403:
			case 404:
			case 405:
			case 409:
			case 412:
			case 413:
			case 422:
			case 500:
			case 501:
			case 503:
				header("HTTP/1.1 " . $this->responseCode);
				break;
			
			case 428:
				header("HTTP/1.1 428 Precondition Required");
				break;
			
			case 429:
				header("HTTP/1.1 429 Too Many Requests");
				break;
			
			default:
				throw new Exception("Unsupported response code " . $this->responseCode);
		}
		
		if ($this->libraryVersion && $this->queryParams['apiVersion'] >= 2) {
			header("Zotero-Last-Modified-Version: " . $this->libraryVersion);
		}
		
		if ($this->responseXML instanceof SimpleXMLElement) {
			if (!$this->responseCode) {
				$updated = (string) $this->responseXML->updated;
				if ($updated) {
					$updated = strtotime($updated);
					
					$ifModifiedSince = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? $_SERVER['HTTP_IF_MODIFIED_SINCE'] : false;
					$ifModifiedSince = strtotime($ifModifiedSince);
					if ($ifModifiedSince >= $updated) {
						header('HTTP/1.1 304 Not Modified');
						exit;
					}
					
					$lastModified = substr(date('r', $updated), 0, -5) . "GMT";
					header("Last-Modified: $lastModified");
				}
			}
			
			$xmlstr = $this->responseXML->asXML();
			
			$doc = new DOMDocument('1.0');
			
			$doc->loadXML($xmlstr);
			$doc->formatOutput = true;
			
			if ($this->queryParams['pprint']) {
				$ppdoc = new DOMDocument('1.0');
				// Zero-width spaces to push <feed> beyond Firefox's
				// feed auto-detection boundary
				$comment = $ppdoc->createComment("​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​​");
				$ppdoc->appendChild($comment);
				$ppdoc->formatOutput = true;
				$rootElem = $doc->firstChild;
				$importedNode = $ppdoc->importNode($rootElem, true);
				$ppdoc->appendChild($importedNode);
				$doc = $ppdoc;
			}
			
			$xmlstr = $doc->saveXML();
			
			if ($this->queryParams['pprint']) {
				header("Content-Type: text/xml");
			}
			else {
				header("Content-Type: application/atom+xml");
			}
			
			echo $xmlstr;
		}
		
		$this->logRequestTime();
		echo ob_get_clean();
		exit;
	}
	
	
	protected function currentRequestTime() {
		return round(microtime(true) - $this->startTime, 2);
	}
	
	
	protected function logRequestTime($point=false) {
		if ($this->timeLogged) {
			return;
		}
		$time = $this->currentRequestTime();
		if ($time > $this->timeLogThreshold) {
			$this->timeLogged = true;
			error_log(
				"Slow API request " . ($point ? " at point " . $point : "") . ": "
				. $time . " sec for "
				. $_SERVER['REQUEST_METHOD'] . " " . $_SERVER['REQUEST_URI']
			);
		}
	}
	
	
	protected function jsonDecode($json) {
		$obj = json_decode($json);
		
		switch(json_last_error()) {
			case JSON_ERROR_DEPTH:
				$error = 'Maximum stack depth exceeded';
				break;
				
			case JSON_ERROR_CTRL_CHAR:
				$error = 'Unexpected control character found';
				break;
			case JSON_ERROR_SYNTAX:
				$error = 'Syntax error, malformed JSON';
				break;
			
			case JSON_ERROR_NONE:
			default:
				$error = '';
        }
        
        if (!empty($error)) {
            throw new Exception("JSON Error: $error", Z_ERROR_INVALID_INPUT);
        }
        
        return $obj;
    }
	
	
	public function handleException(Exception $e) {
		$error = Zotero_Errors::parseException($e);
		
		if (!empty($error['log'])) {
			$id = substr(md5(uniqid(rand(), true)), 0, 10);
			$str = date("D M j G:i:s T Y") . "  \n";
			$str .= "IP address: " . $_SERVER['REMOTE_ADDR'] . "  \n";
			if (isset($_SERVER['HTTP_X_ZOTERO_VERSION'])) {
				$str .= "Version: " . $_SERVER['HTTP_X_ZOTERO_VERSION'] . "  \n";
			}
			$str .= $_SERVER['REQUEST_URI'] . "  \n";
			$str .= $error['exception'] . "  \n";
			// Show request body unless it's too big
			if ($error['code'] != 413) {
				$str .= $this->body;
			}
			
			if (!Z_ENV_TESTING_SITE) {
				file_put_contents(Z_CONFIG::$API_ERROR_PATH . $id, $str);
			}
			
			error_log($str);
		}
		
		if ($error['code'] != '500') {
			$errFunc = "e" . $error['code'];
			$this->$errFunc($error['message']);
		}
		
		// On testing site, display unexpected error messages
		if (Z_ENV_TESTING_SITE) {
			$this->e500($str);
		}
		
		$this->e500();
	}
	
	public function handleError($no, $str, $file, $line) {
		$e = new ErrorException($str, $no, 0, $file, $line);
		$this->handleException($e);
	}
	
	
	public function checkDBTransactionState() {
		if (Zotero_DB::transactionInProgress()) {
			error_log("Transaction still in progress at request end! "
				. "[" . $this->method . " " . $_SERVER['REQUEST_URI'] . "]");
		}
	}
	
	
	public function checkForFatalError() {
		$lastError = error_get_last();
		if (!empty($lastError) && $lastError['type'] == E_ERROR) {
			header('Status: 500 Internal Server Error');
			header('HTTP/1.0 500 Internal Server Error');
		}
	}
	
	
	public function logTotalRequestTime() {
		StatsD::timing("api.request.total", (microtime(true) - $this->startTime) * 1000, 0.25);
	}
}
?>
