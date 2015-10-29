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
	protected $writeTokenCacheTime = 43200; // 12 hours
	
	private $profile = false;
	private $timeLogThreshold = 5;
	
	protected $apiVersion;
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
	protected $headers = [];
	
	private $startTime = false;
	private $timeLogged = false;
	
	
	public function init($extra) {
		$this->startTime = microtime(true);
		
		if (!Z_CONFIG::$API_ENABLED) {
			$this->e503(Z_CONFIG::$MAINTENANCE_MESSAGE);
		}
		
		set_exception_handler(array($this, 'handleException'));
		// TODO: Throw error on some notices but allow DB/Memcached/etc. failures?
		//set_error_handler(array($this, 'handleError'), E_ALL | E_USER_ERROR | E_RECOVERABLE_ERROR);
		set_error_handler(array($this, 'handleError'), E_USER_ERROR | E_RECOVERABLE_ERROR);
		require_once('../model/Error.inc.php');
		
		// On testing sites, include notifications in headers
		if (Z_CONFIG::$TESTING_SITE) {
			Zotero_NotifierObserver::addMessageReceiver(function ($topic, $msg) {
				$header = "Zotero-Debug-Notifications";
				if (!empty($this->headers[$header])) {
					$notifications = json_decode(base64_decode($this->headers[$header]));
				}
				else {
					$notifications = [];
				}
				$notifications[] = $msg;
				$this->headers[$header] = base64_encode(json_encode($notifications));
			});
		}
		
		register_shutdown_function(array($this, 'checkDBTransactionState'));
		register_shutdown_function(array($this, 'logTotalRequestTime'));
		register_shutdown_function(array($this, 'checkForFatalError'));
		register_shutdown_function(array($this, 'addHeaders'));
		$this->method = $_SERVER['REQUEST_METHOD'];
		
		if (!in_array($this->method, array('HEAD', 'OPTIONS', 'GET', 'PUT', 'POST', 'DELETE', 'PATCH'))) {
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
		
		// CORS
		if (isset($_SERVER['HTTP_ORIGIN'])) {
			header("Access-Control-Allow-Origin: *");
			header("Access-Control-Allow-Methods: HEAD, GET, POST, PUT, PATCH, DELETE");
			header("Access-Control-Allow-Headers: Content-Type, If-Match, If-None-Match, If-Modified-Since-Version, If-Unmodified-Since-Version, Zotero-API-Version, Zotero-Write-Token");
			header("Access-Control-Expose-Headers: Backoff, ETag, Last-Modified-Version, Link, Retry-After, Total-Results, Zotero-API-Version");
		}
		
		if ($this->method == 'OPTIONS') {
			$this->end();
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
						'removestoragefiles',
						'itemContent'))) {
				$this->e400("$this->method data not provided");
			}
		}
		
		if ($this->profile) {
			Zotero_DB::profileStart();
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
			$key = false;
			// Allow Zotero-API-Key header
			if (!empty($_SERVER['HTTP_ZOTERO_API_KEY'])) {
				$key = $_SERVER['HTTP_ZOTERO_API_KEY'];
			}
			// Allow ?key=<apikey>
			if (isset($_GET['key'])) {
				if (!$key) {
					$key = $_GET['key'];
				}
				else if ($_GET['key'] !== $key) {
					$this->e400("Zotero-API-Key header and 'key' parameter differ");
				}
			}
			// If neither of the above passed, allow "Authorization: Bearer <apikey>"
			//
			// Apache/mod_php doesn't seem to make Authorization available for auth schemes
			// other than Basic/Digest, so use an Apache-specific method to get the header
			if (!$key && function_exists('apache_request_headers')) {
				$headers = apache_request_headers();
				if (isset($headers['Authorization'])) {
					// Look for "Authorization: Bearer" from OAuth 2.0, and ignore everything else
					if (preg_match('/^bearer/i', $headers['Authorization'], $matches)) {
						if (preg_match('/^bearer +([a-z0-9]+)$/i', $headers['Authorization'], $matches)) {
							$key = $matches[1];
						}
						else {
							$this->e400("Invalid Authorization header format");
						}
					}
				}
			}
			if ($key) {
				$keyObj = Zotero_Keys::authenticate($key);
				if (!$keyObj) {
					$this->e403('Invalid key');
				}
				$this->apiKey = $key;
				$this->userID = $keyObj->userID;
				$this->permissions = $keyObj->getPermissions();
				
				// Check Zotero-Write-Token if it exists to make sure
				// this isn't a duplicate request
				if ($this->isWriteMethod()) {
					if ($cacheKey = $this->getWriteTokenCacheKey()) {
						if (Z_Core::$MC->get($cacheKey)) {
							$this->e412("Write token already used");
						}
					}
				}
			}
			// Website cookie authentication
			//
			// For CSRF protection, session cookie has to be passed in the 'session' parameter,
			// which JS code on other sites can't do because it can't access the website cookie.
			else if (!empty($_GET['session']) &&
					($this->userID = Zotero_Users::getUserIDFromSessionID($_GET['session']))) {
				// Users who haven't synced may not exist in our DB
				if (!Zotero_Users::exists($this->userID)) {
					Zotero_Users::add($this->userID);
				}
				$this->grantUserPermissions($this->userID);
				$this->cookieAuth = true;
			}
			// No credentials provided
			else {
				if (!empty($_GET['auth']) || !empty($extra['auth'])) {
					$this->e401();
				}
				
				// Explicit auth request or not a GET request
				//
				// /users/<id>/keys is an exception, since the key is embedded in the URL
				if ($this->method != "GET" && $this->action != 'keys') {
					$this->e403('An API key is required for write requests.');
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
							$this->e404("User $this->objectUserID not found");
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
		
		$apiVersion = !empty($_SERVER['HTTP_ZOTERO_API_VERSION'])
			? (int) $_SERVER['HTTP_ZOTERO_API_VERSION']
			: false;
		// Serve v1 to ZotPad 1.x, at Mikko's request
		if (!$apiVersion && !empty($_SERVER['HTTP_USER_AGENT'])
				&& strpos($_SERVER['HTTP_USER_AGENT'], 'ZotPad 1') === 0) {
			$apiVersion = 1;
		}
		
		// For publications URLs (e.g., /users/:userID/publications/items), swap in
		// objectLibraryID of user's publications library
		if (!empty($extra['publications'])) {
			// Query parameters not yet parsed, so check version parameter
			if (($apiVersion && $apiVersion < 3)
					|| (!empty($_REQUEST['v']) && $_REQUEST['v'] < 3)
					|| (!empty($_REQUEST['version']) && $_REQUEST['version'] == 1)) {
				$this->e404();
			}
			$userLibraryID = $this->objectLibraryID;
			$this->objectLibraryID = Zotero_Users::getLibraryIDFromUserID(
				$this->objectUserID, 'publications'
			);
			// If one doesn't exist, for write requests create a library if the key
			// has write permission to the user library. For read requests, just
			// return a 404.
			if (!$this->objectLibraryID) {
				if ($this->isWriteMethod()) {
					if (!$this->permissions->canAccess($userLibraryID)
							|| !$this->permissions->canWrite($userLibraryID)) {
						$this->e403();
					}
					$this->objectLibraryID = Zotero_Publications::add($this->objectUserID);
				}
				else {
					$this->objectLibraryID = 0;
				}
			}
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
		
		// If Accept header includes application/atom+xml, send Atom, as long as there's no 'format'
		$atomAccepted = false;
		if (!empty($_SERVER['HTTP_ACCEPT'])) {
			$accept = preg_split('/\s*,\s*/', $_SERVER['HTTP_ACCEPT']);
			$atomAccepted = in_array('application/atom+xml', $accept);
		}
		
		$this->queryParams = Zotero_API::parseQueryParams(
			$_SERVER['QUERY_STRING'],
			$this->action,
			$this->singleObject,
			$apiVersion,
			$atomAccepted
		);
		
		$this->apiVersion = $version = $this->queryParams['v'];
		
		header("Zotero-API-Version: " . $version);
		StatsD::increment("api.request.version.v" . $version, 0.25);
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
		
		$this->allowMethods(['POST']);
		
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
		// Create new key
		$keyObj = new Zotero_Key;
		$keyObj->userID = $userID;
		$keyObj->name = "Tests Key";
		$libraryID = Zotero_Users::getLibraryIDFromUserID($userID);
		$keyObj->setPermission($libraryID, 'library', true);
		$keyObj->setPermission($libraryID, 'notes', true);
		$keyObj->setPermission($libraryID, 'write', true);
		$keyObj->setPermission(0, 'group', true);
		$keyObj->setPermission(0, 'write', true);
		$keyObj->save();
		$key = $keyObj->key;
		
		Zotero_DB::beginTransaction();
		
		// Clear data
		Zotero_Users::clearAllData($userID);
		
		// Delete publications library, so we can test auto-creating it
		$publicationsLibraryID = Zotero_Users::getLibraryIDFromUserID($userID, 'publications');
		if ($publicationsLibraryID) {
			// Delete user publications shard library
			$sql = "DELETE FROM shardLibraries WHERE libraryID=?";
			Zotero_DB::query($sql, $publicationsLibraryID, Zotero_Shards::getByUserID($userID));
			
			// Delete user publications library
			$sql = "DELETE FROM libraries WHERE libraryID=?";
			Zotero_DB::query($sql, $publicationsLibraryID);
			
			Z_Core::$MC->delete('userPublicationsLibraryID_' . $userID);
			Z_Core::$MC->delete('libraryUserID_' . $publicationsLibraryID);
		}
		Zotero_DB::commit();
		
		echo json_encode([
			"apiKey" => $key
		]);
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
	
	
	protected function handleObjectWrite($objectType, $obj=null) {
		if (!is_object($obj) && !is_null($obj)) {
			throw new Exception('$obj must be a data object or null');
		}
		
		$objectTypePlural = \Zotero\DataObjectUtilities::getObjectTypePlural($objectType);
		$objectsClassName = "Zotero_" . ucwords($objectTypePlural);
		
		$json = !empty($this->body) ? $this->jsonDecode($this->body) : false;
		$objectVersionValidated = $this->checkSingleObjectWriteVersion($objectType, $obj, $json);
		
		$this->libraryVersion = Zotero_Libraries::getUpdatedVersion($this->objectLibraryID);
		
		// Update item
		if ($this->method == 'PUT' || $this->method == 'PATCH') {
			if ($this->apiVersion < 2) {
				$this->allowMethods(['PUT']);
			}
			
			if (!$obj) {
				$className = "Zotero_" . ucwords($objectType);
				$obj = new $className;
				$obj->libraryID = $this->objectLibraryID;
				$obj->key = $this->objectKey;
			}
			if ($objectType == 'item') {
				$changed = Zotero_Items::updateFromJSON(
					$obj,
					$json,
					null,
					$this->queryParams,
					$this->userID,
					$objectVersionValidated ? 0 : 2,
					$this->method == 'PATCH'
				);
			}
			else {
				$changed = $objectsClassName::updateFromJSON(
					$obj,
					$json,
					$this->queryParams,
					$this->userID,
					$objectVersionValidated ? 0 : 2,
					$this->method == 'PATCH'
				);
			}
			
			// If not updated, return the original library version
			if (!$changed) {
				$this->libraryVersion = Zotero_Libraries::getOriginalVersion(
					$this->objectLibraryID
				);
			}
			
			if ($cacheKey = $this->getWriteTokenCacheKey()) {
				Z_Core::$MC->set($cacheKey, true, $this->writeTokenCacheTime);
			}
		}
		// Delete item
		else if ($this->method == 'DELETE') {
			$objectsClassName::delete($this->objectLibraryID, $this->objectKey);
		}
		else {
			throw new Exception("Unexpected method $this->method");
		}
		
		if ($this->apiVersion >= 2 || $this->method == 'DELETE') {
			$this->e204();
		}
		
		return $obj;
	}
	
	
	
	/**
	 * For single-object requests for some actions, require If-Unmodified-Since-Version, the
	 * deprecated If-Match, or a JSON version property, and make sure the object hasn't been
	 * modified
	 *
	 * @param {String} $objectType
	 * @param {Zotero_DataObject}
	 * @return {Boolean} - True if the object has been cleared for writing, or false if the JSON
	 *    version property still needs to pass
	 */
	protected function checkSingleObjectWriteVersion($objectType, $obj=null, $json=false) {
		if (!is_object($obj) && !is_null($obj)) {
			throw new Exception('$obj must be a data object or null');
		}
		
		// In versions below 3, no writes to missing objects
		if (!$obj && $this->apiVersion < 3) {
			$this->e404(ucwords($objectType) . " not found");
		}
		
		if (!in_array($objectType, array('item', 'collection', 'search', 'setting'))) {
			throw new Exception("Invalid object type");
		}
		
		if (Z_CONFIG::$TESTING_SITE && !empty($_GET['skipetag'])) {
			return true;
		}
		
		// If-Match (deprecated)
		if ($this->apiVersion < 2) {
			if (empty($_SERVER['HTTP_IF_MATCH'])) {
				if ($this->method == 'DELETE') {
					$this->e428("If-Match must be provided for delete requests");
				}
				else {
					return false;
				}
			}
			
			if (!preg_match('/^"?([a-f0-9]{32})"?$/', $_SERVER['HTTP_IF_MATCH'], $matches)) {
				$this->e400("Invalid ETag in If-Match header");
			}
			
			if ($obj->etag != $matches[1]) {
				$this->e412("ETag does not match current version of $objectType");
			}
			
			return true;
		}
		
		// Get version from If-Unmodified-Since-Version header
		$headerVersion = isset($_SERVER['HTTP_IF_UNMODIFIED_SINCE_VERSION'])
			? $_SERVER['HTTP_IF_UNMODIFIED_SINCE_VERSION'] : false;
		
		// Get version from JSON 'version' property
		if ($json) {
			$json = Zotero_API::extractEditableJSON($json);
			
			if ($this->apiVersion >= 3) {
				$versionProp = 'version';
			}
			else {
				$versionProp = $objectType == 'setting' ? 'version' : $objectType . "Version";
			}
			$propVersion = isset($json->$versionProp) ? $json->$versionProp : false;
		}
		else {
			$propVersion = false;
		}
		
		if ($this->method == 'DELETE' && $headerVersion === false) {
			$this->e428("If-Unmodified-Since-Version must be provided for delete requests");
		}
		
		if ($headerVersion !== false) {
			if (!is_numeric($headerVersion)) {
				$this->e400("Invalid If-Unmodified-Since-Version value '$headerVersion'");
			}
			$headerVersion = (int) $headerVersion;
		}
		if ($propVersion !== false) {
			if (!is_numeric($propVersion)) {
				$this->e400("Invalid JSON 'version' property value '$propVersion'");
			}
			$propVersion = (int) $propVersion;
		}
		
		// If both header and property given, they have to match
		if ($headerVersion !== false && $propVersion !== false && $headerVersion !== $propVersion) {
			$this->e400("If-Unmodified-Since-Version value does not match JSON '$versionProp' property "
				. "($headerVersion != $propVersion)");
		}
		
		$version = $headerVersion !== false ? $headerVersion : $propVersion;
		
		// If object doesn't exist, version has to be 0
		if (!$obj) {
			if ($version !== 0) {
				$this->e404(ucwords($objectType) . " doesn't exist (to create, use version 0)");
			}
			return true;
		}
		
		if ($version === false) {
			throw new HTTPException("Either If-Unmodified-Since-Version or object version "
				. "property must be provided for key-based writes", 428
			);
		}
		
		if ($obj->version !== $version) {
			$this->libraryVersion = $obj->version;
			$this->e412(ucwords($objectType) . " has been modified since specified version "
				. "(expected $version, found " . $obj->version . ")");
		}
		return true;
	}
	
	
	/**
	 * For multi-object requests for some actions, require
	 * If-Unmodified-Since-Version and make sure the library
	 * hasn't been modified
	 *
	 * @param boolean $required Return 428 if header is missing
	 * @return boolean True if library version was checked, false if not
	 */
	protected function checkLibraryIfUnmodifiedSinceVersion($required=false) {
		if (Z_CONFIG::$TESTING_SITE && !empty($_GET['skipetag'])) {
			continue;
		}
		
		if (!isset($_SERVER['HTTP_IF_UNMODIFIED_SINCE_VERSION'])) {
			if ($required) {
				$this->e428("If-Unmodified-Since-Version not provided");
			}
			return false;
		}
		
		$version = $_SERVER['HTTP_IF_UNMODIFIED_SINCE_VERSION'];
		
		if (!is_numeric($version)) {
			$this->e400("Invalid If-Unmodified-Since-Version value");
		}
		
		$libraryVersion = Zotero_Libraries::getVersion($this->objectLibraryID);
		if ($libraryVersion > $version) {
			$this->e412("Library has been modified since specified version "
				. "(expected $version, found $libraryVersion)");
		}
		return true;
	}
	
	
	/**
	 * For multi-object requests for some actions, return 304 Not Modified
	 * if the library hasn't been updated since If-Modified-Since-Version
	 */
	protected function checkLibraryIfModifiedSinceVersion($action) {
		if (!$this->singleObject
				&& in_array(
					$action, ["items", "collections", "searches", "settings", "tags"]
				)
				&& isset($_SERVER['HTTP_IF_MODIFIED_SINCE_VERSION'])
				&& !$this->isWriteMethod()
				&& $this->permissions->canAccess($this->objectLibraryID)
				&& Zotero_Libraries::getVersion($this->objectLibraryID)
					<= $_SERVER['HTTP_IF_MODIFIED_SINCE_VERSION']) {
			$this->e304();
		}
	}
	
	
	protected function requireContentType($contentType) {
		if (empty($_SERVER['CONTENT_TYPE']) || $_SERVER['CONTENT_TYPE'] != $contentType) {
			throw new Exception("Content-Type must be $contentType", Z_ERROR_INVALID_INPUT);
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
	
	
	protected function getWriteTokenCacheKey() {
		if (empty($_SERVER['HTTP_ZOTERO_WRITE_TOKEN'])) {
			return false;
		}
		if (strlen($_SERVER['HTTP_ZOTERO_WRITE_TOKEN']) < 5 || strlen($_SERVER['HTTP_ZOTERO_WRITE_TOKEN']) > 32) {
			$this->e400("Write token must be 5-32 characters in length");
		}
		if (!$this->apiKey) {
			$this->e400("Write token cannot be used without an API key");
		}
		return "writeToken_" . md5($this->apiKey . "_" . $_SERVER['HTTP_ZOTERO_WRITE_TOKEN']);
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
		// and don't send Last-Modified-Version
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
			Zotero_DB::profileEnd($this->objectLibraryID, true);
		}
		
		switch ($this->responseCode) {
			case 200:
				// Output a Content-Type header for the given format
				// Note that this overrides any Content-Type set elsewhere
				if (isset($this->queryParams['format'])) {
					Zotero_API::outputContentType($this->queryParams['format']);
				}
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
		
		if (isset($this->libraryVersion)) {
			if ($this->apiVersion >= 2) {
				header("Last-Modified-Version: " . $this->libraryVersion);
			}
			
			// Send notification if library has changed
			if ($this->isWriteMethod()) {
				if ($this->libraryVersion >
						Zotero_Libraries::getOriginalVersion($this->objectLibraryID)) {
					Zotero_Notifier::trigger('modify', 'library', $this->objectLibraryID);
				}
			}
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
			
			// TEMP: Strip control characters
			$xmlstr = Zotero_Utilities::cleanString($xmlstr, true);
			
			$doc = new DOMDocument('1.0');
			$doc->loadXML($xmlstr);
			$doc->formatOutput = true;
			
			echo $doc->saveXML();
		}
		
		$this->logRequestTime();
		self::addHeaders();
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
			
			$shardHostStr = "";
			if (!empty($this->objectLibraryID)) {
				$shardID = Zotero_Shards::getByLibraryID($this->objectLibraryID);
				$shardInfo = Zotero_Shards::getShardInfo($shardID);
				$shardHostStr = " with shard host " . $shardInfo['shardHostID'];
			}
			
			error_log(
				"Slow API request" . ($point ? " at point " . $point : "")
				. $shardHostStr . ": "
				. $time . " sec for "
				. $_SERVER['REQUEST_METHOD'] . " " . $_SERVER['REQUEST_URI']
			);
		}
	}
	
	
	protected function jsonDecode($json) {
		$obj = json_decode($json);
		Zotero_Utilities::cleanStringRecursive($obj);
		$this->checkJSONError();
        return $obj;
    }
	
    
    protected function checkJSONError() {
    	switch (json_last_error()) {
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
			$str .= $_SERVER['REQUEST_METHOD'] . " " . $_SERVER['REQUEST_URI'] . "  \n";
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
	
	public function addHeaders() {
		foreach ($this->headers as $header => $value) {
			header("$header: $value");
		}
		$this->headers = [];
	}
	
	public function checkForFatalError() {
		$lastError = error_get_last();
		if (!empty($lastError) && $lastError['type'] == E_ERROR) {
			header('Status: 500 Internal Server Error');
			header('HTTP/1.0 500 Internal Server Error');
		}
	}
	
	
	public function logTotalRequestTime() {
		if (!Z_CONFIG::$STATSD_ENABLED) {
			return;
		}
		
		try {
			if (!empty($this->objectLibraryID)) {
				$shardID = Zotero_Shards::getByLibraryID($this->objectLibraryID);
				$shardInfo = Zotero_Shards::getShardInfo($shardID);
				$shardHostID = (int) $shardInfo['shardHostID'];
				StatsD::timing(
					"api.request.total_by_shard.$shardHostID",
					(microtime(true) - $this->startTime) * 1000,
					0.25
				);
			}
		}
		catch (Exception $e) {
			error_log("WARNING: " . $e);
		}
		
		StatsD::timing("api.memcached", Z_Core::$MC->requestTime * 1000, 0.25);
		StatsD::timing("api.request.total", (microtime(true) - $this->startTime) * 1000, 0.25);
	}
}
?>
