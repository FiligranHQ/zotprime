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
	private $validAPIVersions = array(1);
	private $writeTokenCacheTime = 43200; // 12 hours
	
	private $profile = false;
	private $profileShard = 0;
	private $timeLogThreshold = 5;
	
	private $method;
	private $uri;
	private $queryParams = array();
	private $ifUnmodifiedSince;
	private $body;
	private $apiKey;
	private $responseXML;
	private $responseCode;
	private $userID; // request user
	private $permissions;
	private $objectUserID; // userID of object owner
	private $objectGroupID; // groupID of object owner
	private $objectLibraryID; // libraryID of object owner
	private $scopeObject;
	private $scopeObjectID;
	private $scopeObjectKey;
	private $scopeObjectName;
	private $objectID;
	private $objectKey;
	private $objectName;
	private $subset;
	private $fileMode;
	private $fileView;
	private $httpAuth = false;
	private $cookieAuth = false;
	
	private $startTime = false;
	private $timeLogged = false;
	
	
	public function __construct($action, $settings, $extra) {
		if (!Z_CONFIG::$API_ENABLED) {
			$this->e503(Z_CONFIG::$MAINTENANCE_MESSAGE);
		}
		
		set_exception_handler(array($this, 'handleException'));
		set_error_handler(array($this, 'handleError'), E_USER_ERROR);
		require_once('../model/Error.inc.php');
		
		$this->startTime = microtime(true);
		register_shutdown_function(array($this, 'onShutdown'));
		$this->method = $_SERVER['REQUEST_METHOD'];
		
		/*if (isset($_SERVER['REMOTE_ADDR'])
				&& in_array($_SERVER['REMOTE_ADDR'], array(''))) {
			header("HTTP/1.1 429 Rate Limited");
			die("Too many requests");
		}*/
		
		if (!in_array($this->method, array('HEAD', 'GET', 'PUT', 'POST', 'DELETE', 'PATCH'))) {
			header("HTTP/1.1 501 Not Implemented");
			die("Method is not implemented");
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
			else if (!empty($extra['allowHTTP'])) {
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
				if (!empty($_GET['auth'])) {
					$this->e401();
				}
				
				// Always challenge a 'me' request
				if (!empty($extra['userID']) && $extra['userID'] == 'me') {
					$this->e403('You must specify a key when making a /me request.');
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
		
		// Validate the API version
		if (isset($_REQUEST['version']) && !in_array($_REQUEST['version'], $this->validAPIVersions)) {
			$this->e400("Invalid request API version '{$_REQUEST['version']}'");
		}
		
		$this->uri = Z_CONFIG::$API_BASE_URI . substr($_SERVER["REQUEST_URI"], 1);
		
		// Get object user
		if (!empty($extra['userID'])) {
			// Possibly temporary shortcut for viewing one's own data via HTTP Auth
			if ($extra['userID'] == 'me') {
				$objectUserID = $this->userID;
			}
			else {
				$objectUserID = (int) $extra['userID'];
				if (!$objectUserID) {
					$this->e400("Invalid user ID", Z_ERROR_INVALID_INPUT);
				}
			}
			$this->objectUserID = $objectUserID;
			
			try {
				$this->objectLibraryID = Zotero_Users::getLibraryIDFromUserID($objectUserID);
			}
			catch (Exception $e) {
				if ($e->getCode() == Z_ERROR_USER_NOT_FOUND) {
					try {
						Zotero_Users::addFromWWW($objectUserID);
					}
					catch (Exception $e) {
						if ($e->getCode() == Z_ERROR_USER_NOT_FOUND) {
							$this->e404();
						}
						throw ($e);
					}
					$this->objectLibraryID = Zotero_Users::getLibraryIDFromUserID($objectUserID);
				}
				else {
					throw ($e);
				}
			}
			
			// Make sure user isn't banned
			if (!Zotero_Users::isValidUser($objectUserID)) {
				$this->e404();
			}
		}
		// Get object group
		else if (!empty($extra['groupID'])) {
			$objectGroupID = (int) $extra['groupID'];
			if (!$objectGroupID) {
				$this->e400("Invalid group ID", Z_ERROR_INVALID_INPUT);
			}
			// Make sure group exists
			$group = Zotero_Groups::get($objectGroupID);
			if (!$group) {
				$this->e404();
			}
			// Don't show groups owned by banned users
			if (!Zotero_Users::isValidUser($group->ownerUserID)) {
				$this->e404();
			}
			$this->objectGroupID = $objectGroupID;
			$this->objectLibraryID = Zotero_Groups::getLibraryIDFromGroupID($objectGroupID);
		}
		
		
		// Return 409 if target library is locked
		switch ($this->method) {
			case 'POST':
			case 'PUT':
			case 'DELETE':
				switch ($action) {
					// Library lock doesn't matter for some admin requests
					case 'storageadmin':
						break;
					
					default:
						if ($this->objectLibraryID && Zotero_Libraries::isLocked($this->objectLibraryID)) {
							$this->e409("Target library is locked");
						}
						break;
				}
		}
		
		$this->scopeObject = !empty($extra['scopeObject']) ? $extra['scopeObject'] : null;
		$this->scopeObjectID = !empty($extra['scopeObjectID']) ? (int) $extra['scopeObjectID'] : null;
		
		if (!empty($extra['scopeObjectKey'])) {
			// Handle incoming requests using ids instead of keys
			//  - Keys might be all numeric, so only do this if length != 8
			if (preg_match('/^[0-9]+$/', $extra['scopeObjectKey']) && strlen($extra['scopeObjectKey']) != 8) {
				$this->scopeObjectID = (int) $extra['scopeObjectKey'];
			}
			else if (preg_match('/[A-Z0-9]{8}/', $extra['scopeObjectKey'])) {
				$this->scopeObjectKey = $extra['scopeObjectKey'];
			}
		}
		
		$this->scopeObjectName = !empty($extra['scopeObjectName']) ? urldecode($extra['scopeObjectName']) : null;
		
		// Can be either id or key
		if (!empty($extra['key'])) {
			if (!empty($_GET['iskey'])) {
				$this->objectKey = $extra['key'];
			}
			else if (preg_match('/^[0-9]+$/', $extra['key'])) {
				$this->objectID = (int) $extra['key'];
			}
			else if (preg_match('/^[A-Z0-9]{8}$/', $extra['key'])) {
				$this->objectKey = $extra['key'];
			}
			else if ($extra['key'] != 'top') {
				Z_Core::logError("=============");
				Z_Core::logError("Invalid key");
				Z_Core::logError($extra['key']);
			}
		}
		
		if (!empty($extra['id'])) {
			$this->objectID = (int) $extra['id'];
		}
		$this->objectName = !empty($extra['name']) ? urldecode($extra['name']) : null;
		$this->subset = !empty($extra['subset']) ? $extra['subset'] : null;
		$this->fileMode = !empty($extra['file'])
							? (!empty($_GET['info']) ? 'info' : 'download')
							: false;
		$this->fileView = !empty($extra['view']);
		
		$singleObject = ($this->objectID || $this->objectKey) && !$this->subset;
		$this->queryParams = Zotero_API::parseQueryParams($_SERVER['QUERY_STRING'], $action, $singleObject);
	}
	
	
	public function __call($name, $arguments) {
		if (preg_match("/^e[1-5][0-9]{2}$/", $name)) {
			if (isset($arguments[0])) {
				Z_HTTP::$name($arguments[0]);
			}
			else {
				Z_HTTP::$name();
			}
		}
		throw new Exception("Invalid function $name");
	}
	
	
	public function index() {
		$this->e400("Invalid Request");
	}
	
	
	public function items() {
		if (($this->method == 'POST' || $this->method == 'PUT') && !$this->body) {
			$this->e400("$this->method data not provided");
		}
		
		$itemIDs = array();
		$responseItems = array();
		$responseKeys = array();
		$totalResults = null;
		
		//
		// Single item
		//
		if (($this->objectID || $this->objectKey) && !$this->subset) {
			if ($this->fileMode) {
				if ($this->fileView) {
					$this->allowMethods(array('GET', 'HEAD', 'POST'));
				}
				else {
					$this->allowMethods(array('GET', 'PUT', 'POST', 'HEAD', 'PATCH'));
				}
			}
			else {
				$this->allowMethods(array('GET', 'PUT', 'DELETE'));
			}
			
			// Check for general library access
			if (!$this->permissions->canAccess($this->objectLibraryID)) {
				//var_dump($this->objectLibraryID);
				//var_dump($this->permissions);
				$this->e403();
			}
			
			if ($this->objectKey) {
				$item = Zotero_Items::getByLibraryAndKey($this->objectLibraryID, $this->objectKey);
			}
			else {
				try {
					$item = Zotero_Items::get($this->objectLibraryID, $this->objectID);
				}
				catch (Exception $e) {
					if ($e->getCode() == Z_ERROR_OBJECT_LIBRARY_MISMATCH) {
						$item = false;
					}
					else {
						throw ($e);
					}
				}
			}
			
			if (!$item) {
				// Possibly temporary workaround to block unnecessary full syncs
				if ($this->fileMode && $this->method == 'POST') {
					// If > 2 requests for missing file, trigger a full sync via 404
					$cacheKey = "apiMissingFile_" . $this->objectLibraryID . "_"
						. ($this->objectKey ? $this->objectKey : $this->objectID);
					$set = Z_Core::$MC->get($cacheKey);
					if (!$set) {
						Z_Core::$MC->set($cacheKey, 1, 86400);
					}
					else if ($set < 2) {
						Z_Core::$MC->increment($cacheKey);
					}
					else {
						Z_Core::$MC->delete($cacheKey);
						$this->e404("A file sync error occurred. Please sync again.");
					}
					$this->e500("A file sync error occurred. Please sync again.");
				}
				// If we have an id, make sure this isn't really an all-numeric key
				if ($this->objectID && strlen($this->objectID) == 8 && preg_match('/[0-9]{8}/', $this->objectID)) {
					$item = Zotero_Items::getByLibraryAndKey($this->objectLibraryID, $this->objectID);
					if ($item) {
						$this->objectKey = $this->objectID;
						unset($this->objectID);
					}
				}
				if (!$item) {
					$this->e404("Item does not exist");
				}
			}
			
			if ($item->isNote() && !$this->permissions->canAccess($this->objectLibraryID, 'notes')) {
				$this->e403();
			}
			
			// Make sure URL libraryID matches item libraryID
			if ($this->objectLibraryID != $item->libraryID) {
				$this->e404("Item does not exist");
			}
			
			// File access mode
			if ($this->fileMode) {
				$this->_handleFileRequest($item);
			}
			
			// If id, redirect to key URL
			if ($this->objectID) {
				$this->allowMethods(array('GET'));
				
				$qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
				header("Location: " . Zotero_API::getItemURI($item) . $qs);
				exit;
			}
			
			if ($this->scopeObject) {
				switch ($this->scopeObject) {
					// Remove item from collection
					case 'collections':
						$this->allowMethods(array('DELETE'));
						
						if (!$this->permissions->canWrite($this->objectLibraryID)) {
							$this->e403("Write access denied");
						}
						
						$collection = Zotero_Collections::getByLibraryAndKey($this->objectLibraryID, $this->scopeObjectKey);
						if (!$collection) {
							$this->e404("Collection not found");
						}
						
						if (!$collection->hasItem($item->id)) {
							$this->e404("Item not found in collection");
						}
						
						Zotero_DB::beginTransaction();
						
						$timestamp = Zotero_Libraries::updateTimestamps($this->objectLibraryID);
						Zotero_DB::registerTransactionTimestamp($timestamp);
						
						$collection->removeItem($item->id);
						
						Zotero_DB::commit();
						
						$this->e204();
					
					default:
						$this->e400();
				}
			}
			
			if ($this->method == 'PUT' || $this->method == 'DELETE') {
				if (!$this->permissions->canWrite($this->objectLibraryID)) {
					$this->e403("Write access denied");
				}
				
				if (!Z_CONFIG::$TESTING_SITE || empty($_GET['skipetag'])) {
					if (empty($_SERVER['HTTP_IF_MATCH'])) {
						$this->e400("If-Match header not provided");
					}
					
					if (!preg_match('/^"?([a-f0-9]{32})"?$/', $_SERVER['HTTP_IF_MATCH'], $matches)) {
						$this->e400("Invalid ETag in If-Match header");
					}
					
					if ($item->etag != $matches[1]) {
						$this->e412("ETag does not match current version of item");
					}
				}
				
				// Update existing item
				if ($this->method == 'PUT') {
					$obj = $this->jsonDecode($this->body);
					Zotero_Items::updateFromJSON($item, $obj, false, null, $this->userID);
					$this->queryParams['format'] = 'atom';
					$this->queryParams['content'] = array('json');
					
					if ($cacheKey = $this->getWriteTokenCacheKey()) {
						Z_Core::$MC->set($cacheKey, true, $this->writeTokenCacheTime);
					}
				}
				
				// Delete existing item
				else {
					Zotero_Items::delete($this->objectLibraryID, $this->objectKey, true);
					
					try {
						Zotero_Processors::notifyProcessors('index');
					}
					catch (Exception $e) {
						Z_Core::logError($e);
					}
					
					$this->e204();
				}
			}
			
			//header("Zotero-Timestamp: " . strtotime($item->serverDateModified) * 1000);
			
			// Display item
			switch ($this->queryParams['format']) {
				case 'atom':
					$this->responseXML = Zotero_Items::convertItemToAtom(
						$item, $this->queryParams, $this->permissions
					);
					break;
			
				case 'bib':
					echo Zotero_Cite::getBibliographyFromCitationServer(array($item), $this->queryParams);
					exit;
				
				case 'csljson':
					$json = Zotero_Cite::getJSONFromItems(array($item), true);
					if ($this->queryParams['pprint']) {
						header("Content-Type: text/plain");
						$json = Zotero_Utilities::json_encode_pretty($json);
					}
					else {
						header("Content-Type: application/vnd.citationstyles.csl+json");
						$json = json_encode($json);
					}
					echo $json;
					exit;
				
				default:
					$export = Zotero_Translate::doExport(array($item), $this->queryParams['format']);
					if ($this->queryParams['pprint']) {
						header("Content-Type: text/plain");
					}
					else {
						header("Content-Type: " . $export['mimeType']);
					}
					echo $export['body'];
					exit;
			}
		}
		
		//
		// Multiple items
		//
		else {
			$this->allowMethods(array('GET', 'POST'));
			
			if (!$this->permissions->canAccess($this->objectLibraryID)) {
				$this->e403();
			}
			
			$includeTrashed = false;
			$formatAsKeys = $this->queryParams['format'] == 'keys';
			
			if ($this->scopeObject) {
				$this->allowMethods(array('GET', 'POST'));
				
				// If id, redirect to key URL
				if ($this->scopeObjectID) {
					$this->allowMethods(array('GET'));
					if (!in_array($this->scopeObject, array("collections", "tags"))) {
						$this->e400();
					}
					$className = 'Zotero_' . ucwords($this->scopeObject);
					$obj = call_user_func(array($className, 'get'), $this->objectLibraryID, $this->scopeObjectID);
					if (!$obj) {
						$this->e404("Scope " . substr($this->scopeObject, 0, -1) . " not found");
					}
					$base = call_user_func(array('Zotero_API', 'get' . substr(ucwords($this->scopeObject), 0, -1) . 'URI'), $obj);
					$qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
					header("Location: " . $base . "/items" . $qs);
					exit;
				}
				
				switch ($this->scopeObject) {
					case 'collections':
						$collection = Zotero_Collections::getByLibraryAndKey($this->objectLibraryID, $this->scopeObjectKey);
						if (!$collection) {
							$this->e404("Collection not found");
						}
						
						// Add items to collection
						if ($this->method == 'POST') {
							if (!$this->permissions->canWrite($this->objectLibraryID)) {
								$this->e403("Write access denied");
							}
							
							Zotero_DB::beginTransaction();
							
							$timestamp = Zotero_Libraries::updateTimestamps($this->objectLibraryID);
							Zotero_DB::registerTransactionTimestamp($timestamp);
							
							$itemKeys = explode(' ', $this->body);
							$itemIDs = array();
							foreach ($itemKeys as $key) {
								try {
									$item = Zotero_Items::getByLibraryAndKey($this->objectLibraryID, $key);
								}
								catch (Exception $e) {
									if ($e->getCode() == Z_ERROR_OBJECT_LIBRARY_MISMATCH) {
										$item = false;
									}
									else {
										throw ($e);
									}
								}
								
								if (!$item) {
									throw new Exception("Item '$key' not found in library", Z_ERROR_INVALID_INPUT);
								}
								
								if ($item->getSource()) {
									throw new Exception("Child items cannot be added to collections directly", Z_ERROR_INVALID_INPUT);
								}
								$itemIDs[] = $item->id;
							}
							$collection->addItems($itemIDs);
							
							Zotero_DB::commit();
							
							$this->e204();
						}
						
						$title = "Items in Collection ‘" . $collection->name . "’";
						$itemIDs = $collection->getChildItems();
						break;
					
					case 'tags':
						$this->allowMethods(array('GET'));
						
						$tagIDs = Zotero_Tags::getIDs($this->objectLibraryID, $this->scopeObjectName);
						if (!$tagIDs) {
							$this->e404("Tag not found");
						}
						
						$itemIDs = array();
						$title = '';
						foreach ($tagIDs as $tagID) {
							$tag = new Zotero_Tag;
							$tag->libraryID = $this->objectLibraryID;
							$tag->id = $tagID;
							// Use a real tag name, in case case differs
							if (!$title) {
								$title = "Items of Tag ‘" . $tag->name . "’";
							}
							$itemIDs = array_merge($itemIDs, $tag->getLinkedItems(true));
						}
						$itemIDs = array_unique($itemIDs);
						
						break;
					
					default:
						throw new Exception("Invalid items scope object '$this->scopeObject'");
				}
			}
			else {
				// Top-level items
				if ($this->subset == 'top') {
					$this->allowMethods(array('GET'));
					
					$title = "Top-Level Items";
					$results = Zotero_Items::search($this->objectLibraryID, true, $this->queryParams);
				}
				else if ($this->subset == 'trash') {
					$this->allowMethods(array('GET'));
					
					$title = "Deleted Items";
					$itemIDs = Zotero_Items::getDeleted($this->objectLibraryID, true);
					$includeTrashed = true;
				}
				else if ($this->subset == 'children') {
					// If we have an id, make sure this isn't really an all-numeric key
					if ($this->objectID && strlen($this->objectID) == 8 && preg_match('/[0-9]{8}/', $this->objectID)) {
						$item = Zotero_Items::getByLibraryAndKey($this->objectLibraryID, $this->objectID);
						if ($item) {
							$this->objectKey = $this->objectID;
							unset($this->objectID);
						}
					}
					
					// If id, redirect to key URL
					if ($this->objectID) {
						$this->allowMethods(array('GET'));
						
						$item = Zotero_Items::get($this->objectLibraryID, $this->objectID);
						if (!$item) {
							$this->e404("Item not found");
						}
						$qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
						header("Location: " . Zotero_API::getItemURI($item) . '/children' . $qs);
						exit;
					}
					
					$item = Zotero_Items::getByLibraryAndKey($this->objectLibraryID, $this->objectKey);
					if (!$item) {
						$this->e404("Item not found");
					}
					
					// Create new child items
					if ($this->method == 'POST') {
						if (!$this->permissions->canWrite($this->objectLibraryID)) {
							$this->e403("Write access denied");
						}
						
						$obj = $this->jsonDecode($this->body);
						$keys = Zotero_Items::addFromJSON($obj, $this->objectLibraryID, $item, $this->userID);
						
						if ($cacheKey = $this->getWriteTokenCacheKey()) {
							Z_Core::$MC->set($cacheKey, true, $this->writeTokenCacheTime);
						}
						
						$uri = Zotero_API::getItemURI($item) . "/children";
						$queryString = "itemKey="
								. urlencode(implode(",", $keys))
								. "&content=json&order=itemKeyList&sort=asc";
						if ($this->apiKey) {
							$queryString .= "&key=" . $this->apiKey;
						}
						$uri .= "?" . $queryString;
						
						$this->responseCode = 201;
						$this->queryParams = Zotero_API::parseQueryParams($queryString, $this->action, false);
					}
					
					// Display items
					$title = "Child Items of ‘" . $item->getDisplayTitle() . "’";
					$notes = $item->getNotes();
					$attachments = $item->getAttachments();
					$itemIDs = array_merge($notes, $attachments);
				}
				// All items
				else {
					// Create new items
					if ($this->method == 'POST') {
						if (!$this->permissions->canWrite($this->objectLibraryID)) {
							$this->e403("Write access denied");
						}
						
						$obj = $this->jsonDecode($this->body);
						if (isset($obj->url)) {
							$response = Zotero_Items::addFromURL($obj, $this->objectLibraryID, $this->userID, $this->getTranslationToken());
							if ($response instanceof stdClass) {
								header("Content-Type: application/json");
								echo json_encode($response->select);
								$this->e300();
							}
							else if (is_int($response)) {
								switch ($response) {
									case 501:
										$this->e501("No translators found for URL");
										break;
									
									default:
										$this->e500("Error translating URL");
								}
							}
							else {
								$keys = $response;
							}
						}
						else {
							$keys = Zotero_Items::addFromJSON($obj, $this->objectLibraryID, null, $this->userID);
						}
						
						if (!$keys) {
							throw new Exception("No items added");
						}
						
						if ($cacheKey = $this->getWriteTokenCacheKey()) {
							Z_Core::$MC->set($cacheKey, true, $this->writeTokenCacheTime);
						}
						
						$uri = Zotero_API::getItemsURI($this->objectLibraryID);
						$queryString = "itemKey="
								. urlencode(implode(",", $keys))
								. "&content=json&order=itemKeyList&sort=asc";
						if ($this->apiKey) {
							$queryString .= "&key=" . $this->apiKey;
						}
						$uri .= "?" . $queryString;
						
						$this->responseCode = 201;
						$this->queryParams = Zotero_API::parseQueryParams($queryString, $this->action, false);
					}
					
					$title = "Items";
					$results = Zotero_Items::search($this->objectLibraryID, false, $this->queryParams);
				}
				
				if (!empty($results)) {
					if ($formatAsKeys) {
						$responseKeys = $results['keys'];
					}
					else {
						$responseItems = $results['items'];
					}
					$totalResults = $results['total'];
				}
			}
			
			if ($this->queryParams['format'] == 'bib') {
				if (($itemIDs ? sizeOf($itemIDs) : $results['total']) > Zotero_API::$maxBibliographyItems) {
					$this->e413("Cannot generate bibliography with more than " . Zotero_API::$maxBibliographyItems . " items");
				}
			}
			
			if ($itemIDs) {
				$this->queryParams['itemIDs'] = $itemIDs;
				$results = Zotero_Items::search($this->objectLibraryID, false, $this->queryParams, $includeTrashed);
				
				if ($formatAsKeys) {
					$responseKeys = $results['keys'];
				}
				else {
					$responseItems = $results['items'];
				}
				$totalResults = $results['total'];
			}
			else if (!isset($results)) {
				if ($formatAsKeys) {
					$responseKeys = array();
				}
				else {
					$responseItems = array();
				}
				$totalResults = 0;
			}
			
			// Remove notes if not user and not public
			for ($i=0; $i<sizeOf($responseItems); $i++) {
				if ($responseItems[$i]->isNote() && !$this->permissions->canAccess($responseItems[$i]->libraryID, 'notes')) {
					array_splice($responseItems, $i, 1);
					$totalResults--;
					$i--;
				}
			}
			
			switch ($this->queryParams['format']) {
				case 'atom':
					$t = microtime(true);
					$this->responseXML = Zotero_Atom::createAtomFeed(
						$this->getFeedNamePrefix($this->objectLibraryID) . $title,
						$this->uri,
						$responseItems,
						$totalResults,
						$this->queryParams,
						$this->permissions
					);
					StatsD::timing("api.items.multiple.createAtomFeed." . implode("-", $this->queryParams['content']), (microtime(true) - $t) * 1000);
					break;
				
				case 'bib':
					echo Zotero_Cite::getBibliographyFromCitationServer($responseItems, $this->queryParams);
					break;
				
				case 'csljson':
					if ($this->queryParams['pprint']) {
						header("Content-Type: text/plain");
					}
					else {
						header("Content-Type: application/vnd.citationstyles.csl+json");
					}
					$json = Zotero_Cite::getJSONFromItems($responseItems, true);
					echo Zotero_Utilities::formatJSON($json, $this->queryParams['pprint']);
					break;
				
				case 'keys':
					if (!$formatAsKeys) {
						$responseKeys = array();
						foreach ($responseItems as $item) {
							$responseKeys[] = $item->key;
						}
					}
					
					header("Content-Type: text/plain");
					echo implode("\n", $responseKeys) . "\n";
					exit;
				
				default:
					$export = Zotero_Translate::doExport($responseItems, $this->queryParams['format']);
					if ($this->queryParams['pprint']) {
						header("Content-Type: text/plain");
					}
					else {
						header("Content-Type: " . $export['mimeType']);
					}
					echo $export['body'];
			}
		}
		
		$this->end();
	}
	
	
	//
	// Storage-related
	//
	
	public function laststoragesync() {
		if (!$this->httpAuth) {
			$this->e403();
		}
		
		$this->allowMethods(array('GET', 'POST'));
		
		if ($this->method == 'POST') {
			//Zotero_Users::setLastStorageSync($this->userID);
		}
		
		$lastSync = Zotero_Users::getLastStorageSync($this->userID);
		if (!$lastSync) {
			$this->e404();
		}
		
		echo $lastSync;
		exit;
	}
	
	
	public function removestoragefiles() {
		if (!$this->permissions->isSuper() && !$this->httpAuth) {
			$this->e403();
		}
		
		$this->allowMethods(array('POST'));
		$sql = "DELETE SFI FROM storageFileItems SFI JOIN items USING (itemID) WHERE libraryID=?";
		Zotero_DB::query($sql, $this->objectLibraryID, Zotero_Shards::getByLibraryID($this->objectLibraryID));
		header("HTTP/1.1 204 No Content");
		exit;
	}
	
	
	/**
	 * Handle S3 request
	 *
	 * Permission-checking provided by items()
	 */
	private function _handleFileRequest($item) {
		if (!$this->permissions->canAccess($this->objectLibraryID, 'files')) {
			$this->e403();
		}
		
		$this->allowMethods(array('HEAD', 'GET', 'POST', 'PATCH'));
		
		if (!$item->isAttachment()) {
			$this->e400("Item is not an attachment");
		}
		
		// File info for client sync
		//
		// Use of HEAD method is deprecated after 2.0.8/2.1b1 due to
		// compatibility problems with proxies and security software
		if ($this->method == 'HEAD' || ($this->method == 'GET' && $this->fileMode == 'info')) {
			$info = Zotero_S3::getLocalFileItemInfo($item);
			if (!$info) {
				$this->e404();
			}
			/*
			header("Last-Modified: " . gmdate('r', $info['uploaded']));
			header("Content-Type: " . $info['type']);
			*/
			header("Content-Length: " . $info['size']);
			header("ETag: " . $info['hash']);
			header("X-Zotero-Filename: " . $info['filename']);
			header("X-Zotero-Modification-Time: " . $info['mtime']);
			header("X-Zotero-Compressed: " . ($info['zip'] ? 'Yes' : 'No'));
			header_remove("X-Powered-By");
		}
		
		// File download/viewing
		//
		// TEMP: allow POST for snapshot viewing until using session auth
		else if ($this->method == 'GET' || ($this->method == 'POST' && $this->fileView)) {
			if ($this->fileView) {
				$info = Zotero_S3::getLocalFileItemInfo($item);
				if (!$info) {
					$this->e404();
				}
				// For zip files, redirect to files domain
				if ($info['zip']) {
					$url = Zotero_Attachments::getTemporaryURL($item, !empty($_GET['int']));
					if (!$url) {
						$this->e500();
					}
					header("Location: $url");
					exit;
				}
			}
			
			// For single files, redirect to S3
			$url = Zotero_S3::getDownloadURL($item, 60);
			if (!$url) {
				$this->e404();
			}
			Zotero_S3::logDownload(
				$item,
				// TODO: support anonymous download if necessary
				$this->userID,
				IPAddress::getIP()
			);
			header("Location: $url");
			exit;
		}
		
		else if ($this->method == 'POST' || $this->method == 'PATCH') {
			if (!$item->isImportedAttachment()) {
				$this->e400("Cannot upload file for linked file/URL attachment item");
			}
			
			$libraryID = $item->libraryID;
			$type = Zotero_Libraries::getType($libraryID);
			if ($type == 'group') {
				$groupID = Zotero_Groups::getGroupIDFromLibraryID($libraryID);
				$group = Zotero_Groups::get($groupID);
				if (!$group->userCanEditFiles($this->userID)) {
					$this->e403("You do not have file editing access");
				}
			}
			else {
				$group = null;
			}
			
			// If not the client, require If-Match or If-None-Match
			if (!$this->httpAuth) {
				if (empty($_SERVER['HTTP_IF_MATCH']) && empty($_SERVER['HTTP_IF_NONE_MATCH'])) {
					$this->e428("If-Match/If-None-Match header not provided");
				}
				
				if (!empty($_SERVER['HTTP_IF_MATCH'])) {
					if (!preg_match('/^"?([a-f0-9]{32})"?$/', $_SERVER['HTTP_IF_MATCH'], $matches)) {
						$this->e400("Invalid ETag in If-Match header");
					}
					
					if (!$item->attachmentStorageHash) {
						$info = Zotero_S3::getLocalFileItemInfo($item);
						$this->e412("ETag set but file does not exist");
					}
					
					if ($item->attachmentStorageHash != $matches[1]) {
						$this->e412("ETag does not match current version of file");
					}
				}
				else {
					if ($_SERVER['HTTP_IF_NONE_MATCH'] != "*") {
						$this->e400("Invalid value for If-None-Match header");
					}
					
					if ($this->attachmentStorageHash) {
						$this->e412("If-None-Match: * set but file exists");
					}
				}
			}
			
			//
			// Upload authorization
			//
			if (!isset($_POST['update']) && !isset($_REQUEST['upload'])) {
				$info = new Zotero_StorageFileInfo;
				
				// Validate upload metadata
				if (empty($_REQUEST['md5'])) {
					$this->e400('MD5 hash not provided');
				}
				$info->hash = $_REQUEST['md5'];
				if (!preg_match('/[abcdefg0-9]{32}/', $info->hash)) {
					$this->e400('Invalid MD5 hash');
				}
				
				if (empty($_REQUEST['mtime'])) {
					$this->e400('File modification time not provided');
				}
				$info->mtime = $_REQUEST['mtime'];
				
				if (!isset($_REQUEST['filename']) || $_REQUEST['filename'] === "") {
					$this->e400('File name not provided');
				}
				$info->filename = $_REQUEST['filename'];
				
				if (!isset($_REQUEST['filesize'])) {
					$this->e400('File size not provided');
				}
				$info->size = $_REQUEST['filesize'];
				if (!is_numeric($info->size)) {
					$this->e400("Invalid file size");
				}
				
				$info->contentType = isset($_REQUEST['contentType']) ? $_REQUEST['contentType'] : "";
				if (!preg_match("/^[a-zA-Z0-9\-\/]+$/", $info->contentType)) {
					$info->contentType = "";
				}
				
				$info->charset = isset($_REQUEST['charset']) ? $_REQUEST['charset'] : "";
				if (!preg_match("/^[a-zA-Z0-9\-]+$/", $info->charset)) {
					$info->charset = "";
				}
				
				$contentTypeHeader = $info->contentType . (($info->contentType && $info->charset) ? "; charset=" . $info->charset : "");
				
				$info->zip = !empty($_REQUEST['zip']);
				
				// Reject file if it would put account over quota
				if ($group) {
					$quota = Zotero_S3::getEffectiveUserQuota($group->ownerUserID);
					$usage = Zotero_S3::getUserUsage($group->ownerUserID);
				}
				else {
					$quota = Zotero_S3::getEffectiveUserQuota($this->objectUserID);
					$usage = Zotero_S3::getUserUsage($this->objectUserID);
				}
				$total = $usage['total'];
				$fileSizeMB = round($info->size / 1024 / 1024, 1);
				if ($total + $fileSizeMB > $quota) {
					$this->e413("File would exceed quota ($total + $fileSizeMB > $quota)");
				}
				
				Zotero_DB::query("SET TRANSACTION ISOLATION LEVEL SERIALIZABLE");
				Zotero_DB::beginTransaction();
				
				// See if file exists with this filename
				$localInfo = Zotero_S3::getLocalFileInfo($info);
				if ($localInfo) {
					$storageFileID = $localInfo['storageFileID'];
					
					// Verify file size
					if ($localInfo['size'] != $info->size) {
						throw new Exception(
							"Specified file size incorrect for existing file "
								. $info->hash . "/" . $info->filename
								. " ({$localInfo['size']} != {$info->size})"
						);
					}
				}
				// If not found, see if there's a copy with a different name
				else {
					$oldStorageFileID = Zotero_S3::getFileByHash($info->hash, $info->zip);
					if ($oldStorageFileID) {
						// Verify file size
						$localInfo = Zotero_S3::getFileInfoByID($oldStorageFileID);
						if ($localInfo['size'] != $info->size) {
							throw new Exception(
								"Specified file size incorrect for duplicated file "
								. $info->hash . "/" . $info->filename
								. " ({$localInfo['size']} != {$info->size})"
							);
						}
						
						// Create new file on S3 with new name
						$storageFileID = Zotero_S3::duplicateFile(
							$oldStorageFileID,
							$info->filename,
							$info->zip,
							$contentTypeHeader
						);
						if (!$storageFileID) {
							$this->e500("File duplication failed");
						}
					}
				}
				
				// If we already have a file, add/update storageFileItems row and stop
				if (!empty($storageFileID)) {
					Zotero_S3::updateFileItemInfo($item, $storageFileID, $info, $this->httpAuth);
					Zotero_DB::commit();
					
					if ($this->httpAuth) {
						header('Content-Type: application/xml');
						echo "<exists/>";
					}
					else {
						header('Content-Type: application/json');
						echo json_encode(array('exists' => 1));
					}
					exit;
				}
				
				Zotero_DB::commit();
				
				// Add request to upload queue
				$uploadKey = Zotero_S3::queueUpload($this->userID, $info);
				// User over queue limit
				if (!$uploadKey) {
					header('Retry-After: ' . Zotero_S3::$uploadQueueTimeout);
					if ($this->httpAuth) {
						$this->e413("Too many queued uploads");
					}
					else {
						$this->e429("Too many queued uploads");
					}
				}
				
				// Output XML for client requests (which use HTTP Auth)
				if ($this->httpAuth) {
					$params = Zotero_S3::generateUploadPOSTParams($item, $info, true);
					
					header('Content-Type: application/xml');
					$xml = new SimpleXMLElement('<upload/>');
					$xml->url = Zotero_S3::getUploadBaseURL();
					$xml->key = $uploadKey;
					foreach ($params as $key=>$val) {
						$xml->params->$key = $val;
					}
					echo $xml->asXML();
				}
				// Output JSON for API requests
				else {
					if (!empty($_REQUEST['params']) && $_REQUEST['params'] == "1") {
						$params = array(
							"url" => Zotero_S3::getUploadBaseURL(),
							"params" => array()
						);
						foreach (Zotero_S3::generateUploadPOSTParams($item, $info) as $key=>$val) {
							$params['params'][$key] = $val;
						}
					}
					else {
						$params = Zotero_S3::getUploadPOSTData($item, $info);
					}
					
					$params['uploadKey'] = $uploadKey;
					
					header('Content-Type: application/json');
					echo json_encode($params);
				}
				exit;
			}
			
			//
			// API partial upload and post-upload file registration
			//
			if (isset($_REQUEST['upload'])) {
				$uploadKey = $_REQUEST['upload'];
				
				if (!$uploadKey) {
					$this->e400("Upload key not provided");
				}
				
				$info = Zotero_S3::getUploadInfo($uploadKey);
				if (!$info) {
					$this->e400("Upload key not found");
				}
				
				// Partial upload
				if ($this->method == 'PATCH') {
					if (empty($_REQUEST['algorithm'])) {
						throw new Exception("Algorithm not specified", Z_ERROR_INVALID_INPUT);
					}
					
					$storageFileID = Zotero_S3::patchFile($item, $info, $_REQUEST['algorithm'], $this->body);
				}
				// Full upload
				else {
					$remoteInfo = Zotero_S3::getRemoteFileInfo($info);
					if (!$remoteInfo) {
						error_log("Remote file {$info->hash}/{$info->filename} not found");
						$this->e400("Remote file not found");
					}
					if ($remoteInfo['size'] != $info->size) {
						error_log("Uploaded file size does not match ({$remoteInfo['size']} != {$info->size}) for file {$info->hash}/{$info->filename}");
					}
				}
				
				// Set an automatic shared lock in getLocalFileInfo() to prevent
				// two simultaneous transactions from adding a file
				Zotero_DB::query("SET TRANSACTION ISOLATION LEVEL SERIALIZABLE");
				Zotero_DB::beginTransaction();
				
				if (!isset($storageFileID)) {
					// Check if file already exists, which can happen if two identical
					// files are uploaded simultaneously
					$fileInfo = Zotero_S3::getLocalFileInfo($info);
					if ($fileInfo) {
						$storageFileID = $fileInfo['storageFileID'];
					}
					// If file doesn't exist, add it
					else {
						$storageFileID = Zotero_S3::addFile($info);
					}
				}
				Zotero_S3::updateFileItemInfo($item, $storageFileID, $info);
				
				Zotero_S3::logUpload($this->userID, $item, $uploadKey, IPAddress::getIP());
				
				Zotero_DB::commit();
				
				header("HTTP/1.1 204 No Content");
				exit;
			}
			
			
			//
			// Client post-upload file registration
			//
			if (isset($_POST['update'])) {
				$this->allowMethods(array('POST'));
				
				if (empty($_POST['mtime'])) {
					throw new Exception('File modification time not provided');
				}
				
				$uploadKey = $_POST['update'];
				
				$info = Zotero_S3::getUploadInfo($uploadKey);
				if (!$info) {
					$this->e400("Upload key not found");
				}
				
				$remoteInfo = Zotero_S3::getRemoteFileInfo($info);
				if (!$remoteInfo) {
					$this->e400("Remote file not found");
				}
				if (!isset($info->size)) {
					throw new Exception("Size information not available");
				}
				
				$info->mtime = $_POST['mtime'];
				
				// Set an automatic shared lock in getLocalFileInfo() to prevent
				// two simultaneous transactions from adding a file
				Zotero_DB::query("SET TRANSACTION ISOLATION LEVEL SERIALIZABLE");
				Zotero_DB::beginTransaction();
				
				// Check if file already exists, which can happen if two identical
				// files are uploaded simultaneously
				$fileInfo = Zotero_S3::getLocalFileInfo($info);
				if ($fileInfo) {
					$storageFileID = $fileInfo['storageFileID'];
				}
				else {
					$storageFileID = Zotero_S3::addFile($info);
				}
				
				Zotero_S3::updateFileItemInfo($item, $storageFileID, $info, true);
				
				Zotero_S3::logUpload($this->userID, $item, $uploadKey, IPAddress::getIP());
				
				Zotero_DB::commit();
				
				header("HTTP/1.1 204 No Content");
				exit;
			}
		}
		exit;
	}
	
	
	public function storageadmin() {
		if (!$this->permissions->isSuper()) {
			$this->e404();
		}
		
		$this->allowMethods(array('GET', 'POST'));
		
		Zotero_DB::beginTransaction();
		
		if ($this->method == 'POST') {
			if (!isset($_POST['quota'])) {
				$this->e400("Quota not provided");
			}
			if (!isset($_POST['expiration'])) {
				$this->e400("Expiration not provided");
			}
			if (!is_numeric($_POST['quota']) || $_POST['quota'] < 0) {
				$this->e400("Invalid quota");
			}
			if (!is_numeric($_POST['expiration'])) {
				$this->e400("Invalid expiration");
			}
			$halfHourAgo = strtotime("-30 minutes");
			if ($_POST['expiration'] != 0 && $_POST['expiration'] < $halfHourAgo) {
				$this->e400("Expiration is in the past");
			}
			
			try {
				Zotero_S3::setUserValues($this->objectUserID, $_POST['quota'], $_POST['expiration']);
			}
			catch (Exception $e) {
				if ($e->getCode() == Z_ERROR_GROUP_QUOTA_SET_BELOW_USAGE) {
					$this->e409("Cannot set quota below current usage");
				}
				$this->handleException($e);
			}
		}
		
		// GET request
		$xml = new SimpleXMLElement('<storage/>');
		$quota = Zotero_S3::getEffectiveUserQuota($this->objectUserID);
		$xml->quota = $quota;
		$instQuota = Zotero_S3::getInstitutionalUserQuota($this->objectUserID);
		// If personal quota is in effect
		if (!$instQuota || $quota > $instQuota) {
			$values = Zotero_S3::getUserValues($this->objectUserID);
			if ($values) {
				$xml->expiration = (int) $values['expiration'];
			}
		}
		$usage = Zotero_S3::getUserUsage($this->objectUserID);
		$xml->usage->total = $usage['total'];
		$xml->usage->library = $usage['library'];
		
		foreach ($usage['groups'] as $group) {
			if (!isset($group['id'])) {
				throw new Exception("Group id isn't set");
			}
			if (!isset($group['usage'])) {
				throw new Exception("Group usage isn't set");
			}
			$xmlGroup = $xml->usage->addChild('group', $group['usage']);
			$xmlGroup['id'] = $group['id'];
		}
		
		Zotero_DB::commit();
		
		header('application/xml');
		echo $xml->asXML();
		exit;
	}
	
	
	public function storagetransferbucket() {
		// DISABLED
		$this->e404();
		
		if (!$this->permissions->isSuper()) {
			$this->e404();
		}
		
		$this->allowMethods(array('POST'));
		
		Zotero_S3::transferBucket('zoterofilestorage', 'zoterofilestoragetest');
		exit;
	}
	
	
	public function collections() {
		if (($this->method == 'POST' || $this->method == 'PUT') && !$this->body) {
			$this->e400("$this->method data not provided");
		}
		
		$collections = array();
		
		// Single collection
		if (($this->objectID || $this->objectKey) && $this->subset != 'collections') {
			$this->allowMethods(array('GET', 'PUT', 'DELETE'));
			
			// If id, redirect to key URL
			if ($this->objectID) {
				$this->allowMethods(array('GET'));
				$collection = Zotero_Collections::get($this->objectLibraryID, $this->objectID);
				if (!$collection) {
					$this->e404("Collection not found");
				}
				$qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
				header("Location: " . Zotero_API::getCollectionURI($collection) . $qs);
				exit;
			}
			
			$collection = Zotero_Collections::getByLibraryAndKey($this->objectLibraryID, $this->objectKey);
			if (!$collection) {
				$this->e404("Collection not found");
			}
			
			// In single-collection mode, require public pref to be enabled
			if (!$this->permissions->canAccess($this->objectLibraryID)) {
				$this->e403();
			}
			
			if ($this->method == 'PUT' || $this->method == 'DELETE') {
				if (!$this->permissions->canWrite($this->objectLibraryID)) {
					$this->e403("Write access denied");
				}
				
				if (!Z_CONFIG::$TESTING_SITE || empty($_GET['skipetag'])) {
					if (empty($_SERVER['HTTP_IF_MATCH'])) {
						$this->e400("If-Match header not provided");
					}
					
					if (!preg_match('/^"?([a-f0-9]{32})"?$/', $_SERVER['HTTP_IF_MATCH'], $matches)) {
						$this->e400("Invalid ETag in If-Match header");
					}
					
					if ($collection->etag != $matches[1]) {
						$this->e412("ETag does not match current version of collection");
					}
				}
				
				if ($this->method == 'PUT') {
					$obj = $this->jsonDecode($this->body);
					Zotero_Collections::updateFromJSON($collection, $obj);
					$this->queryParams['format'] = 'atom';
					$this->queryParams['content'] = array('json');
					
					if ($cacheKey = $this->getWriteTokenCacheKey()) {
						Z_Core::$MC->set($cacheKey, true, $this->writeTokenCacheTime);
					}
				}
				
				// Delete
				else {
					Zotero_Collections::delete($this->objectLibraryID, $this->objectKey, true);
					$this->e204();
				}
			}
			
			$this->responseXML = Zotero_Collections::convertCollectionToAtom(
				$collection, $this->queryParams['content']
			);
		}
		// All collections
		else {
			$this->allowMethods(array('GET', 'POST'));
			
			if (!$this->permissions->canAccess($this->objectLibraryID)) {
				$this->e403();
			}
			
			if ($this->scopeObject) {
				$this->allowMethods(array('GET'));
				
				switch ($this->scopeObject) {
					case 'collections':
						// If id, redirect to key URL
						if ($this->scopeObjectID) {
							$collection = Zotero_Collections::get($this->objectLibraryID, $this->scopeObjectID);
							if (!$collection) {
								$this->e404("Collection not found");
							}
							$qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
							header("Location: " . Zotero_API::getCollectionURI($collection) . $qs);
							exit;
						}
						
						$collection = Zotero_Collections::getByLibraryAndKey($this->objectLibraryID, $this->scopeObjectKey);
						if (!$collection) {
							$this->e404("Collection not found");
						}
						$title = "Child Collections of ‘$collection->name'’";
						$collectionIDs = $collection->getChildCollections();
						break;
					
					default:
						throw new Exception("Invalid collections scope object '$this->scopeObject'");
				}
			}
			else {
				// Top-level items
				if ($this->subset == 'top') {
					$this->allowMethods(array('GET'));
					
					$title = "Top-Level Collections";
					$results = Zotero_Collections::getAllAdvanced($this->objectLibraryID, true, $this->queryParams);
				}
				else {
					// Create a collection
					if ($this->method == 'POST') {
						if (!$this->permissions->canWrite($this->objectLibraryID)) {
							$this->e403("Write access denied");
						}
						
						$obj = $this->jsonDecode($this->body);
						$collection = Zotero_Collections::addFromJSON($obj, $this->objectLibraryID);
						
						if ($cacheKey = $this->getWriteTokenCacheKey()) {
							Z_Core::$MC->set($cacheKey, true, $this->writeTokenCacheTime);
						}
						
						$uri = Zotero_API::getCollectionURI($collection);
						$queryString = "content=json";
						if ($this->apiKey) {
							$queryString .= "&key=" . $this->apiKey;
						}
						$uri .= "?" . $queryString;
						
						$this->queryParams = Zotero_API::parseQueryParams($queryString, $this->action, true);
					}
					
					$title = "Collections";
					$results = Zotero_Collections::getAllAdvanced($this->objectLibraryID, false, $this->queryParams);
				}
				
				$collections = $results['collections'];
				$totalResults = $results['total'];
			}
			
			if (!empty($collectionIDs)) {
				foreach ($collectionIDs as $collectionID) {
					$collections[] = Zotero_Collections::get($this->objectLibraryID, $collectionID);
				}
				
				// Fake sorting and limiting
				$totalResults = sizeOf($collections);
				$key = $this->queryParams['order'];
				if ($key == 'title') {
					$key = 'name';
				}
				$dir = $this->queryParams['sort'];
				usort($collections, function ($a, $b) use ($key, $dir) {
					$dir = $dir == "asc" ? 1 : -1;
					if ($a->$key == $b->$key) {
						return 0;
					}
					else {
						return ($a->$key > $b->$key) ? $dir : ($dir * -1);
					}
				});
				$collections = array_slice(
					$collections,
					$this->queryParams['start'],
					$this->queryParams['limit']
				);
			}
			
			$this->responseXML = Zotero_Atom::createAtomFeed(
				$this->getFeedNamePrefix($this->objectLibraryID) . $title,
				$this->uri,
				$collections,
				$totalResults,
				$this->queryParams,
				$this->permissions
			);
		}
		
		$this->end();
	}
	
	
	public function tags() {
		$this->allowMethods(array('GET'));
		
		if (!$this->permissions->canAccess($this->objectLibraryID)) {
			$this->e403();
		}
		
		$tags = array();
		$totalResults = 0;
		$name = $this->objectName;
		$fixedValues = array();
		
		// Set of tags matching name
		if ($name && $this->subset != 'tags') {
			$tagIDs = Zotero_Tags::getIDs($this->objectLibraryID, $name);
			if (!$tagIDs) {
				$this->e404();
			}
			
			$title = "Tags matching ‘" . $name . "’";
		}
		// All tags
		else {
			if ($this->scopeObject) {
				// If id, redirect to key URL
				if ($this->scopeObjectID) {
					if (!in_array($this->scopeObject, array("collections", "items"))) {
						$this->e400();
					}
					$className = 'Zotero_' . ucwords($this->scopeObject);
					$obj = call_user_func(array($className, 'get'), $this->objectLibraryID, $this->scopeObjectID);
					if (!$obj) {
						$this->e404("Scope " . substr($this->scopeObject, 0, -1) . " not found");
					}
					$base = call_user_func(array('Zotero_API', 'get' . substr(ucwords($this->scopeObject), 0, -1) . 'URI'), $obj);
					$qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
					header("Location: " . $base . "/tags" . $qs);
					exit;
				}
				
				switch ($this->scopeObject) {
					case 'collections':
						$collection = Zotero_Collections::getByLibraryAndKey($this->objectLibraryID, $this->scopeObjectKey);
						if (!$collection) {
							$this->e404();
						}
						$title =  "Tags in Collection ‘" . $collection->name . "’";
						$counts = $collection->getTagItemCounts();
						$tagIDs = array();
						if ($counts) {
							foreach ($counts as $tagID=>$count) {
								$tagIDs[] = $tagID;
								$fixedValues[$tagID] = array(
									'numItems' => $count
								);
							}
						}
						break;
						
					case 'items':
						$item = Zotero_Items::getByLibraryAndKey($this->objectLibraryID, $this->scopeObjectKey);
						if (!$item) {
							$this->e404();
						}
						$title = "Tags of '" . $item->getDisplayTitle() . "'";
						$tagIDs = $item->getTags(true);
						break;
					
					default:
						throw new Exception("Invalid tags scope object '$this->scopeObject'");
				}
			}
			else {
				$title = "Tags";
				$results = Zotero_Tags::getAllAdvanced($this->objectLibraryID, $this->queryParams);
				$tags = $results['objects'];
				$totalResults = $results['total'];
			}
		}
		
		if (!empty($tagIDs)) {
			foreach ($tagIDs as $tagID) {
				$tags[] = Zotero_Tags::get($this->objectLibraryID, $tagID);
			}
			
			// Fake sorting and limiting
			$totalResults = sizeOf($tags);
			$key = $this->queryParams['order'];
			// 'title' order means 'name' for tags
			if ($key == 'title') {
				$key = 'name';
			}
			$dir = $this->queryParams['sort'];
			$cmp = create_function(
				'$a, $b',
				'$dir = "'.$dir.'" == "asc" ? 1 : -1;
				if ($a->'.$key.' == $b->'.$key.') {
					return 0;
				}
				else {
					return ($a->'.$key.' > $b->'.$key.') ? $dir : ($dir * -1);}');
			usort($tags, $cmp);
			$tags = array_slice(
				$tags,
				$this->queryParams['start'],
				$this->queryParams['limit']
			);
		}
		
		$this->responseXML = Zotero_Atom::createAtomFeed(
			$this->getFeedNamePrefix($this->objectLibraryID) . $title,
			$this->uri,
			$tags,
			$totalResults,
			$this->queryParams,
			$this->permissions,
			$fixedValues
		);
		
		$this->end();
	}
	
	
	public function groups() {
		if (($this->method == 'POST' || $this->method == 'PUT') && !$this->body) {
			$this->e400("$this->method data not provided");
		}
		
		$groupID = $this->groupID;
		
		//
		// Add a group
		//
		if ($this->method == 'POST') {
			if (!$this->permissions->isSuper()) {
				$this->e403();
			}
			
			if ($groupID) {
				$this->e400("POST requests cannot end with a groupID (did you mean PUT?)");
			}
			
			try {
				$group = @new SimpleXMLElement($this->body);
			}
			catch (Exception $e) {
				$this->e400("$this->method data is not valid XML");
			}
			
			if ((int) $group['id']) {
				$this->e400("POST requests cannot contain a groupID in '" . $this->body . "'");
			}
			
			$fields = $this->getFieldsFromGroupXML($group);
			
			Zotero_DB::beginTransaction();
			
			try {
				$group = new Zotero_Group;
				foreach ($fields as $field=>$val) {
					$group->$field = $val;
				}
				$group->save();
			}
			catch (Exception $e) {
				if (strpos($e->getMessage(), "Invalid") === 0) {
					$this->e400($e->getMessage() . " in " . $this->body . "'");
				}
				
				switch ($e->getCode()) {
					case Z_ERROR_GROUP_NAME_UNAVAILABLE:
						$this->e400($e->getMessage());
					
					default:
						$this->handleException($e);
				}
			}
			
			$this->responseXML = $group->toAtom(array('full'), $this->queryParams);
			
			Zotero_DB::commit();
			
			$url = Zotero_Atom::getGroupURI($group);
			header("Location: " . $url, false, 201);
			
			$this->end();
		}
		
		//
		// Update a group
		//
		if ($this->method == 'PUT') {
			if (!$this->permissions->isSuper()) {
				$this->e403();
			}
			
			if (!$groupID) {
				$this->e400("PUT requests must end with a groupID (did you mean POST?)");
			}
			
			try {
				$group = @new SimpleXMLElement($this->body);
			}
			catch (Exception $e) {
				$this->e400("$this->method data is not valid XML");
			}
			
			$fields = $this->getFieldsFromGroupXML($group);
			
			// Group id is optional, but, if it's there, make sure it matches
			$id = (string) $group['id'];
			if ($id && $id != $groupID) {
				$this->e400("Group ID $id does not match group ID $groupID from URI");
			}
			
			Zotero_DB::beginTransaction();
			
			try {
				$group = Zotero_Groups::get($groupID);
				if (!$group) {
					$this->e404("Group $groupID does not exist");
				}
				foreach ($fields as $field=>$val) {
					$group->$field = $val;
				}
				
				if ($this->ifUnmodifiedSince
						&& strtotime($group->dateModified) > $this->ifUnmodifiedSince) {
					$this->e412();
				}
				
				$group->save();
			}
			catch (Exception $e) {
				if (strpos($e->getMessage(), "Invalid") === 0) {
					$this->e400($e->getMessage() . " in " . $this->body . "'");
				}
				else if ($e->getCode() == Z_ERROR_GROUP_DESCRIPTION_TOO_LONG) {
					$this->e400($e->getMessage());
				}
				$this->handleException($e);
			}
			
			$this->responseXML = $group->toAtom(array('full'), $this->queryParams);
			
			Zotero_DB::commit();
			
			$this->end();
		}
		
		
		//
		// Delete a group
		//
		if ($this->method == 'DELETE') {
			if (!$this->permissions->isSuper()) {
				$this->e403();
			}
			
			if (!$groupID) {
				$this->e400("DELETE requests must end with a groupID");
			}
			
			Zotero_DB::beginTransaction();
			
			$group = Zotero_Groups::get($groupID);
			if (!$group) {
				$this->e404("Group $groupID does not exist");
			}
			$group->erase();
			Zotero_DB::commit();
			
			header("HTTP/1.1 204 No Content");
			exit;
		}
		
		
		//
		// View one or more groups
		//
		
		// Single group
		if ($groupID) {
			$group = Zotero_Groups::get($groupID);
			if (!$this->permissions->canAccess($this->objectLibraryID)) {
				$this->e403();
			}
			if (!$group) {
				$this->e404("Group not found");
			}
			$this->responseXML = $group->toAtom($this->queryParams['content'], $this->queryParams);
		}
		// Multiple groups
		else {
			if ($this->objectUserID) {
				$title = Zotero_Users::getUsername($this->objectUserID) . "’s Groups";
			}
			else {
				// For now, only root can do unrestricted group searches
				if (!$this->permissions->isSuper()) {
					$this->e403();
				}
				
				$title = "Groups";
			}
			
			try {
				$results = Zotero_Groups::getAllAdvanced($this->objectUserID, $this->queryParams, $this->permissions);
			}
			catch (Exception $e) {
				switch ($e->getCode()) {
					case Z_ERROR_INVALID_GROUP_TYPE:
						$this->e400($e->getMessage());
				}
				throw ($e);
			}
			
			$groups = $results['groups'];
			$totalResults = $results['totalResults'];
			
			$this->responseXML = Zotero_Atom::createAtomFeed(
				$title,
				$this->uri,
				$groups,
				$totalResults,
				$this->queryParams,
				$this->permissions
			);
		}
		
		$this->end();
	}
	
	
	private function getFeedNamePrefix($libraryID=false) {
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
	
	
	private function getFieldsFromGroupXML(SimpleXMLElement $group) {
		$fields = array();
		$fields['ownerUserID'] = (int) $group['owner'];
		$fields['name'] = (string) $group['name'];
		$fields['type'] = (string) $group['type'];
		if (isset($group['libraryEnabled'])) {
			$fields['libraryEnabled'] = (bool) (int) $group['libraryEnabled'];
			
			if ($fields['libraryEnabled']) {
				$fields['libraryEditing'] = (string) $group['libraryEditing'];
				$fields['libraryReading'] = (string) $group['libraryReading'];
				$fields['fileEditing'] = (string) $group['fileEditing'];
			}
		}
		else {
			$this->e400("libraryEnabled not specified");
		}
		$fields['description'] = (string) $group->description;
		$fields['url'] = (string) $group->url;
		$fields['hasImage'] = (bool) (int) $group['hasImage'];
		
		return $fields;
	}
	
	
	
	public function groupUsers() {
		if (($this->method == 'POST' || $this->method == 'PUT') && !$this->body) {
			$this->e400("$this->method data not provided");
		}
		
		// For now, only allow root and user access
		if (!$this->permissions->isSuper()) {
			$this->e403();
		}
		
		$groupID = $this->scopeObjectID;
		$userID = $this->objectID;
		
		$group = Zotero_Groups::get($groupID);
		if (!$group) {
			$this->e404("Group $groupID does not exist");
		}
		
		// Add multiple users to group
		if ($this->method == 'POST') {
			if ($userID) {
				$this->e400("POST requests cannot end with a userID (did you mean PUT?)");
			}
			
			// Body can contain multiple <user> blocks, so stuff in root element
			try {
				$xml = @new SimpleXMLElement("<root>" . $this->body . "</root>");
			}
			catch (Exception $e) {
				$this->e400("$this->method data is not valid XML");
			}
			
			$addedUserIDs = array();
			
			Zotero_DB::beginTransaction();
			
			foreach ($xml->user as $user) {
				$id = (int) $user['id'];
				$role = (string) $user['role'];
				
				if (!$id) {
					$this->e400("User ID not provided in '" . $user->asXML() . "'");
				}
				
				if (!$role) {
					$this->e400("Role not provided in '" . $user->asXML() . "'");
				}
				
				try {
					$added = $group->addUser($id, $role);
				}
				catch (Exception $e) {
					if (strpos($e->getMessage(), "Invalid role") === 0) {
						$this->e400("Invalid role '$role' in " . $user->asXML() . "'");
					}
					$this->handleException($e);
				}
				
				if ($added) {
					$addedUserIDs[] = $id;
				}
			}
			
			// Response after adding
			$entries = array();
			foreach ($addedUserIDs as $addedUserID) {
				$entries[] = $group->memberToAtom($addedUserID);
			}
			
			$title = "Users added to group '$group->name'";
			$this->responseXML = Zotero_Atom::createAtomFeed(
				$title,
				$this->uri,
				$entries,
				null,
				$this->queryParams,
				$this->permissions
			);
			
			Zotero_DB::commit();
			
			$this->end();
		}
		
		// Add a single user to group
		if ($this->method == 'PUT') {
			if (!$userID) {
				$this->e400("PUT requests must end with a userID (did you mean POST?)");
			}
			
			try {
				$user = @new SimpleXMLElement($this->body);
			}
			catch (Exception $e) {
				$this->e400("$this->method data is not valid XML");
			}
			
			$id = (int) $user['id'];
			$role = (string) $user['role'];
			
			// User id is optional, but, if it's there, make sure it matches
			if ($id && $id != $userID) {
				$this->e400("User ID $id does not match user ID $userID from URI");
			}
			
			if (!$role) {
				$this->e400("Role not provided in '$this->body'");
			}
			
			Zotero_DB::beginTransaction();
			
			$changedUserIDs = array();
			
			try {
				if ($role == 'owner') {
					if ($userID != $group->ownerUserID) {
						$changedUserIDs[] = $group->ownerUserID;
						$group->ownerUserID = $userID;
						$group->save();
						$changedUserIDs[] = $userID;
					}
				}
				else {
					if ($group->hasUser($userID)) {
						try {
							$updated = $group->updateUser($userID, $role);
						}
						catch (Exception $e) {
							switch ($e->getCode()) {
								case Z_ERROR_CANNOT_DELETE_GROUP_OWNER:
									$this->e400($e->getMessage());
								
								default:
									$this->handleException($e);
							}
						}
						if ($updated) {
							$changedUsersIDs[] = $userID;
						}
					}
					else {
						$added = $group->addUser($userID, $role);
						if ($added) {
							$changedUserIDs[] = $userID;
						}
					}
				}
			}
			catch (Exception $e) {
				if (strpos($e->getMessage(), "Invalid role") === 0) {
					$this->e400("Invalid role '$role' in '$this->body'");
				}
				$this->handleException($e);
			}
			
			// Response after adding
			$entries = array();
			foreach ($changedUserIDs as $changedUserID) {
				$entries[] = $group->memberToAtom($changedUserID);
			}
			
			$title = "Users changed in group '$group->name'";
			$this->responseXML = Zotero_Atom::createAtomFeed(
				$title,
				$this->uri,
				$entries,
				null,
				$this->queryParams,
				$this->permissions
			);
			
			Zotero_DB::commit();
			
			$this->end();
		}
		
		
		if ($this->method == 'DELETE') {
			if (!$userID) {
				$this->e400("DELETE requests must end with a userID");
			}
			
			Zotero_DB::beginTransaction();
			
			try {
				$group->removeUser($userID);
			}
			catch (Exception $e) {
				switch ($e->getCode()) {
					case Z_ERROR_CANNOT_DELETE_GROUP_OWNER:
						$this->e400($e->getMessage());
					
					case Z_ERROR_USER_NOT_GROUP_MEMBER:
						$this->e404($e->getMessage());
					
					default:
						$this->handleException($e);
				}
			}
			
			Zotero_DB::commit();
			
			header("HTTP/1.1 204 No Content");
			exit;
		}
		
		// Single user
		if ($userID) {
			$this->responseXML = $group->memberToAtom($userID);
			$this->end();
		}
		
		// Multiple users
		$title = "Members of '$group->name'";
		
		$entries = array();
		$memberIDs = array_merge(
			array($group->ownerUserID),
			$group->getAdmins(),
			$group->getMembers()
		);
		foreach ($memberIDs as $userID) {
			$entries[] = $group->memberToAtom($userID);
		}
		$totalResults = sizeOf($entries);
		
		$this->responseXML = Zotero_Atom::createAtomFeed(
			$title,
			$this->uri,
			$entries,
			$totalResults,
			$this->queryParams,
			$this->permissions
		);
		
		$this->end();
	}
	
	
	
	public function keys() {
		if (($this->method == 'POST' || $this->method == 'PUT') && !$this->body) {
			$this->e400("$this->method data not provided");
		}
		
		$userID = $this->objectUserID;
		$key = $this->objectName;
		
		if ($this->method == 'GET') {
			// Single key
			if ($key) {
				$keyObj = Zotero_Keys::getByKey($key);
				if (!$keyObj || $keyObj->userID != $this->objectUserID) {
					$this->e404("Key not found");
				}
				
				$this->responseXML = $keyObj->toXML();
				
				// If not super-user, don't include name or recent IP addresses
				if (!$this->permissions->isSuper()) {
					unset($this->responseXML['dateAdded']);
					unset($this->responseXML['lastUsed']);
					unset($this->responseXML->name);
					unset($this->responseXML->recentIPs);
				}
			}
			
			// All of the user's keys
			else {
				if (!$this->permissions->isSuper()) {
					$this->e403();
				}
				
				$keyObjs = Zotero_Keys::getUserKeys($userID);
				$xml = new SimpleXMLElement('<keys/>');
				$domXML = dom_import_simplexml($xml);
				
				if ($keyObjs) {
					foreach ($keyObjs as $keyObj) {
						$keyXML = $keyObj->toXML();
						$domKeyXML = dom_import_simplexml($keyXML);
						$node = $domXML->ownerDocument->importNode($domKeyXML, true);
						$domXML->appendChild($node); 
					}
				}
				
				$this->responseXML = $xml;
			}
		}
		
		else {
			// Require super-user for modifications
			if (!$this->permissions->isSuper()) {
				$this->e403();
			}
			
			if ($this->method == 'POST') {
				if ($key) {
					$this->e400("POST requests cannot end with a key (did you mean PUT?)");
				}
				
				try {
					$key = @new SimpleXMLElement($this->body);
				}
				catch (Exception $e) {
					$this->e400("$this->method data is not valid XML");
				}
				
				if ((string) $key['key']) {
					$this->e400("POST requests cannot contain a key in '" . $this->body . "'");
				}
				
				$fields = $this->getFieldsFromKeyXML($key);
				
				Zotero_DB::beginTransaction();
				
				try {
					$keyObj = new Zotero_Key;
					$keyObj->userID = $userID;
					foreach ($fields as $field=>$val) {
						if ($field == 'access') {
							foreach ($val as $access) {
								$this->setKeyPermissions($keyObj, $access);
							}
						}
						else {
							$keyObj->$field = $val;
						}
					}
					$keyObj->save();
				}
				catch (Exception $e) {
					if ($e->getCode() == Z_ERROR_KEY_NAME_TOO_LONG) {
						$this->e400($e->getMessage());
					}
					$this->handleException($e);
				}
				
				$this->responseXML = $keyObj->toXML();
				
				Zotero_DB::commit();
				
				$url = Zotero_API::getKeyURI($keyObj);
				header("Location: " . $url, false, 201);
			}
			
			if ($this->method == 'PUT') {
				if (!$key) {
					$this->e400("PUT requests must end with a key (did you mean POST?)");
				}
				
				try {
					$keyXML = @new SimpleXMLElement($this->body);
				}
				catch (Exception $e) {
					$this->e400("$this->method data is not valid XML");
				}
				
				$fields = $this->getFieldsFromKeyXML($keyXML);
				
				// Key attribute is optional, but, if it's there, make sure it matches
				if (isset($fields['key']) && $fields['key'] != $key) {
					$this->e400("Key '{$fields['key']}' does not match key '$key' from URI");
				}
				
				Zotero_DB::beginTransaction();
				
				try {
					$keyObj = Zotero_Keys::getByKey($key);
					if (!$keyObj) {
						$this->e404("Key '$key' does not exist");
					}
					foreach ($fields as $field=>$val) {
						if ($field == 'access') {
							foreach ($val as $access) {
								$this->setKeyPermissions($keyObj, $access);
							}
						}
						else {
							$keyObj->$field = $val;
						}
					}
					$keyObj->save();
				}
				catch (Exception $e) {
					if ($e->getCode() == Z_ERROR_KEY_NAME_TOO_LONG) {
						$this->e400($e->getMessage());
					}
					$this->handleException($e);
				}
				
				$this->responseXML = $keyObj->toXML();
				
				Zotero_DB::commit();
			}
			
			if ($this->method == 'DELETE') {
				if (!$key) {
					$this->e400("DELETE requests must end with a key");
				}
				
				Zotero_DB::beginTransaction();
				
				$keyObj = Zotero_Keys::getByKey($key);
				if (!$keyObj) {
					$this->e404("Key '$key' does not exist");
				}
				$keyObj->erase();
				Zotero_DB::commit();
				
				header("HTTP/1.1 204 No Content");
				exit;
			}
		}
		
		header('Content-Type: application/xml');
		$xmlstr = $this->responseXML->asXML();
		
		$doc = new DOMDocument('1.0');
		
		$doc->loadXML($xmlstr);
		$doc->formatOutput = true;
		echo $doc->saveXML();
		exit;
	}
	
	
	private function getFieldsFromKeyXML(SimpleXMLElement $xml) {
		$fields = array();
		$fields['name'] = (string) $xml->name;
		$fields['access'] = array();
		foreach ($xml->access as $access) {
			$a = array();
			if (isset($access['group'])) {
				$a['group'] = $access['group'] == 'all' ? 0 : (int) $access['group'];
			}
			else {
				$a['library'] = (int) $access['library'];
				$a['notes'] = (int) $access['notes'];
			}
			$a['write'] = isset($access['write']) ? (bool) (int) $access['write'] : false;
			$fields['access'][] = $a;
		}
		return $fields;
	}
	
	
	private function setKeyPermissions($keyObj, $accessElement) {
		foreach ($accessElement as $accessField=>$accessVal) {
			// 'write' is handled below
			if ($accessField == 'write') {
				continue;
			}
			
			// Group library access (<access group="23456"/>)
			if ($accessField == 'group') {
				// Grant access to all groups
				if ($accessVal === 0) {
					$keyObj->setPermission(0, 'group', true);
					$keyObj->setPermission(0, 'write', $accessElement['write']);
				}
				else {
					$group = Zotero_Groups::get($accessVal);
					if (!$group) {
						$this->e400("Group not found");
					}
					if (!$group->hasUser($this->objectUserID)) {
						$this->e400("User $this->id is not a member of group $group->id");
					}
					$keyObj->setPermission($group->libraryID, 'library', true);
					$keyObj->setPermission($group->libraryID, 'write', $accessElement['write']);
				}
			}
			// Personal library access (<access library="1" notes="0"/>)
			else {
				$libraryID = Zotero_Users::getLibraryIDFromUserID($this->objectUserID);
				$keyObj->setPermission($libraryID, $accessField, $accessVal);
				$keyObj->setPermission($libraryID, 'write', $accessElement['write']);
			}
		}
	}
	
	
	/**
	 * JSON type/field data
	 */
	public function mappings() {
		if (!empty($_GET['locale']) && $_GET['locale'] != 'en-US') {
			$this->e400("Non-English locales are not yet supported");
		}
		
		$locale = empty($_GET['locale']) ? 'en-US' : $_GET['locale'];
		
		if ($this->subset == 'itemTypeFields') {
			if (empty($_GET['itemType'])) {
				$this->e400("'itemType' not provided");
			}
			
			$itemType = $_GET['itemType'];
			
			$itemTypeID = Zotero_ItemTypes::getID($itemType);
			if (!$itemTypeID) {
				$this->e400("Invalid item type '$itemType'");
			}
		}
		
		else if ($this->subset == 'itemTypeCreatorTypes') {
			if (empty($_GET['itemType'])) {
				$this->e400("'itemType' not provided");
			}
			
			$itemType = $_GET['itemType'];
			
			$itemTypeID = Zotero_ItemTypes::getID($itemType);
			if (!$itemTypeID) {
				$this->e400("Invalid item type '$itemType'");
			}
			
			// Notes and attachments don't have creators
			if ($itemType == 'note' || $itemType == 'attachment') {
				echo "[]";
				exit;
			}
		}
		
		// TODO: check If-Modified-Since and return 304 if not changed
		
		$cacheKey = $this->subset . "JSON";
		if (isset($itemTypeID)) {
			$cacheKey .= "_" . $itemTypeID;
		}
		$ttl = 60;
		if ($this->queryParams['pprint']) {
			$cacheKey .= "_pprint";
		}
		$json = Z_Core::$MC->get($cacheKey);
		if ($json) {
			if ($this->queryParams['pprint']) {
				header("Content-Type: text/plain");
			}
			else {
				header("Content-Type: application/json");
			}
			echo $json;
			exit;
		}
		
		switch ($this->subset) {
			case 'itemTypes':
				$rows = Zotero_ItemTypes::getAll($locale);
				$propName = 'itemType';
				break;
			
			case 'itemTypeFields':
				$fieldIDs = Zotero_ItemFields::getItemTypeFields($itemTypeID);
				$rows = array();
				foreach ($fieldIDs as $fieldID) {
					$fieldName = Zotero_ItemFields::getName($fieldID);
					$rows[] = array(
						'name' => $fieldName,
						'localized' => Zotero_ItemFields::getLocalizedString(
							$itemTypeID, $fieldName, $locale
						)
					);
				}
				$propName = 'field';
				break;
			
			case 'itemFields':
				$rows = Zotero_ItemFields::getAll($locale);
				$propName = 'field';
				break;
			
			case 'itemTypeCreatorTypes':
				$rows = Zotero_CreatorTypes::getTypesForItemType($itemTypeID, $locale);
				$propName = 'creatorType';
				break;
			
			case 'creatorFields':
				$rows = Zotero_Creators::getLocalizedFieldNames();
				$propName = 'field';
				break;
		}
		
		$json = array();
		foreach ($rows as $row) {
			$json[] = array(
				$propName => $row['name'],
				'localized' => $row['localized']
			);
		}
		
		if ($this->queryParams['pprint']) {
			header("Content-Type: text/plain");
			$json = Zotero_Utilities::json_encode_pretty($json);
			Z_Core::$MC->set($cacheKey, $json, $ttl);
		}
		else {
			header("Content-Type: application/json");
			$json = json_encode($json);
			Z_Core::$MC->set($cacheKey, $json, $ttl);
		}
		
		echo $json;
		exit;
	}
	
	
	public function newItem() {
		if (empty($_GET['itemType'])) {
			$this->e400("'itemType' not provided");
		}
		
		$itemType = $_GET['itemType'];
		if ($itemType == 'attachment') {
			if (empty($_GET['linkMode'])) {
				$this->e400("linkMode required for itemType=attachment");
			}
			
			$linkModeName = $_GET['linkMode'];
			
			try {
				$linkMode = Zotero_Attachments::linkModeNameToNumber(strtoupper($linkModeName));
			}
			catch (Exception $e) {
				$this->e400("Invalid linkMode '$linkModeName'");
			}
		}
		
		$itemTypeID = Zotero_ItemTypes::getID($itemType);
		if (!$itemTypeID) {
			$this->e400("Invalid item type '$itemType'");
		}
		
		// TODO: check If-Modified-Since and return 304 if not changed
		
		$cacheKey = "newItemJSON_" . $itemTypeID;
		if ($itemType == 'attachment') {
			$cacheKey .= "_" . $linkMode;
		}
		$ttl = 60;
		if ($this->queryParams['pprint']) {
			$cacheKey .= "_pprint";
		}
		$json = Z_Core::$MC->get($cacheKey);
		if ($json) {
			header("Content-Type: application/json");
			echo $json;
			exit;
		}
		
		// Generate template
		
		$json = array(
			'itemType' => $itemType
		);
		if ($itemType == 'attachment') {
			$json['linkMode'] = $linkModeName;
		}
		
		$fieldIDs = Zotero_ItemFields::getItemTypeFields($itemTypeID);
		$first = true;
		foreach ($fieldIDs as $fieldID) {
			$fieldName = Zotero_ItemFields::getName($fieldID);
			
			if ($itemType == 'attachment' && $fieldName == 'url' && !preg_match('/_url$/', $linkModeName)) {
				continue;
			}
			
			$json[$fieldName] = "";
			
			if ($first && $itemType != 'note' && $itemType != 'attachment') {
				$creatorTypeID = Zotero_CreatorTypes::getPrimaryIDForType($itemTypeID);
				$creatorTypeName = Zotero_CreatorTypes::getName($creatorTypeID);
				$json['creators'] = array(
					array(
						'creatorType' => $creatorTypeName,
						'firstName' => '',
						'lastName' => ''
					)
				);
				$first = false;
			}
		}
		
		if ($itemType == 'note' || $itemType == 'attachment') {
			$json['note'] = '';
		}
		
		$json['tags'] = array();
		
		if ($itemType != 'note' && $itemType != 'attachment') {
			$json['attachments'] = array();
			$json['notes'] = array();
		}
		
		if ($itemType == 'attachment') {
			$json['contentType'] = '';
			$json['charset'] = '';
			
			if (preg_match('/^imported_/', $linkModeName)) {
				$json['filename'] = '';
				$json['md5'] = null;
				$json['mtime'] = null;
				//$json['zip'] = false;
			}
		}
		
		header("Content-Type: application/json");
		
		if ($this->queryParams['pprint']) {
			$json = Zotero_Utilities::json_encode_pretty($json);
			Z_Core::$MC->set($cacheKey, $json, $ttl);
		}
		else {
			$json = json_encode($json);
			Z_Core::$MC->set($cacheKey, $json, $ttl);
		}
		
		echo $json;
		exit;
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
	// Private methods
	//
	/**
	 * Verify the HTTP method
	 */
	private function allowMethods($methods, $message=false) {
		if (!in_array($this->method, $methods)) {
			header("HTTP/1.1 405 Method Not Allowed");
			header("Allow: " . implode(", ", $methods));
			die($message ? $message : "Method not allowed");
		}
	}
	
	
	private function requireContentType($contentType) {
		if ($_SERVER['CONTENT_TYPE'] != $contentType) {
			throw new Exception("Content-Type must be '$contentType'", Z_ERROR_INVALID_INPUT);
		}
	}
	
	/**
	 * For HTTP Auth and session-based auth, generate blanket user permissions
	 * manually, since there's no key object
	 */
	private function grantUserPermissions($userID) {
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
	private function getTranslationToken() {
		if (!$this->cookieAuth) {
			return false;
		}
		return md5($this->userID . $_GET['session']);
	}
	
	
	private function getWriteTokenCacheKey() {
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
	
	
	private function end() {
		if ($this->profile) {
			Zotero_DB::profileEnd($this->profileShard, false);
		}
		
		if ($this->responseCode) {
			switch ($this->responseCode) {
				case 201:
					header("HTTP/1.1 201 Created");
					break;
				
				default:
					throw new Exception("Unsupported response code");
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
	
	
	private function currentRequestTime() {
		return round(microtime(true) - $this->startTime, 2);
	}
	
	
	private function logRequestTime($point=false) {
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
	
	
	private function jsonDecode($json) {
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
		switch ($e->getCode()) {
			case Z_ERROR_INVALID_INPUT:
			case Z_ERROR_CITESERVER_INVALID_STYLE:
				$msg = $e->getMessage();
				error_log($msg);
				$this->e400(htmlspecialchars($msg));
				break;
			
			case Z_ERROR_UPLOAD_TOO_LARGE:
				$msg = $e->getMessage();
				error_log($msg);
				$this->e413(htmlspecialchars($msg));
				break;
			
			// 404?
			case Z_ERROR_TAG_NOT_FOUND:
				$this->e400(htmlspecialchars($e->getMessage()));
		}
		
		$id = substr(md5(uniqid(rand(), true)), 0, 10);
		$str = date("D M j G:i:s T Y") . "  \n";
		$str .= "IP address: " . $_SERVER['REMOTE_ADDR'] . "  \n";
		if (isset($_SERVER['HTTP_X_ZOTERO_VERSION'])) {
			$str .= "Version: " . $_SERVER['HTTP_X_ZOTERO_VERSION'] . "  \n";
		}
		$str .= $_SERVER['REQUEST_URI'] . "  \n";
		$str .= $e . "  \n";
		$str .= $this->body;
		
		if (!Z_ENV_TESTING_SITE) {
			file_put_contents(Z_CONFIG::$API_ERROR_PATH . $id, $str);
		}
		
		error_log($str);
		
		switch ($e->getCode()) {
			case Z_ERROR_SHARD_READ_ONLY:
			case Z_ERROR_SHARD_UNAVAILABLE:
				$this->e503(Z_CONFIG::$MAINTENANCE_MESSAGE);
		}
		
		if (Z_ENV_TESTING_SITE) {
			$this->e500($str);
		}
		
		$this->e500();
	}
	
	public function handleError($no, $str, $file, $line) {
		$e = new ErrorException($str, $no, 0, $file, $line);
		$this->handleException($e);
	}
	
	public function onShutdown() {
		StatsD::timing("api.request.total", (microtime(true) - $this->startTime) * 1000, 0.25);
	}
}
?>
