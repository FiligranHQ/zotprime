<?
require('mvc/Router.inc.php');
$router = new Router();

// Set controller to 404 to block access to an action via a particular URL

// Sync
if ($_SERVER['HTTP_HOST'] == Z_CONFIG::$SYNC_DOMAIN) {
	$router->map('/', array('controller' => 'Sync', 'action' => 'index'));
	$router->map('/:action', array('controller' => 'Sync'));
}
// API
else {
	$router->map('/', array('controller' => 'Api', 'action' => 'noop', 'extra' => array('allowHTTP' => true)));
	
	// Groups
	$router->map('/groups/i:objectGroupID', array('controller' => 'Groups'));
	$router->map('/groups/i:scopeObjectID/users/i:objectID', array('controller' => 'Groups', 'action' => 'groupUsers'));
	
	// Top-level objects
	$router->map('/users/i:objectUserID/publications/items/top', ['controller' => 'Items', 'extra' => ['subset' => 'top', 'publications' => true]]);
	$router->map('/users/i:objectUserID/:controller/top', array('extra' => array('subset' => 'top')));
	$router->map('/groups/i:objectGroupID/:controller/top', array('extra' => array('subset' => 'top')));
	
	// Attachment files
	$router->map('/users/i:objectUserID/laststoragesync', array('controller' => 'Storage', 'action' => 'laststoragesync', 'extra' => array('auth' => true)));
	$router->map('/groups/i:objectGroupID/laststoragesync', array('controller' => 'Storage', 'action' => 'laststoragesync', 'extra' => array('auth' => true)));
	$router->map('/users/i:objectUserID/storageadmin', array('controller' => 'Storage', 'action' => 'storageadmin'));
	$router->map('/storagepurge', array('controller' => 'Storage', 'action' => 'storagepurge'));
	$router->map('/users/i:objectUserID/removestoragefiles', array('controller' => 'Storage', 'action' => 'removestoragefiles', 'extra' => array('allowHTTP' => true)));
	$router->map('/users/i:objectUserID/items/:objectKey/file', array('controller' => 'Items', 'extra' => array('allowHTTP' => true, 'file' => true)));
	$router->map('/users/i:objectUserID/items/:objectKey/file/view', array('controller' => 'Items', 'extra' => array('allowHTTP' => true, 'file' => true, 'view' => true)));
	$router->map('/users/i:objectUserID/publications/items/:objectKey/file', ['controller' => 'Items', 'extra' => ['allowHTTP' => true, 'file' => true, 'publications' => true]]);
	$router->map('/users/i:objectUserID/publications/items/:objectKey/file/view', ['controller' => 'Items', 'extra' => ['allowHTTP' => true, 'file' => true, 'view' => true, 'publications' => true]]);
	$router->map('/groups/i:objectGroupID/items/:objectKey/file', array('controller' => 'Items', 'extra' => array('allowHTTP' => true, 'file' => true)));
	$router->map('/groups/i:objectGroupID/items/:objectKey/file/view', array('controller' => 'Items', 'extra' => array('allowHTTP' => true, 'file' => true, 'view' => true)));
	
	// Full-text content
	$router->map('/users/i:objectUserID/items/:objectKey/fulltext', array('controller' => 'FullText', 'action' => 'itemContent'));
	//$router->map('/users/i:objectUserID/publications/items/:objectKey/fulltext', ['controller' => 'FullText', 'action' => 'itemContent', 'extra' => ['publications' => true]]);
	$router->map('/groups/i:objectGroupID/items/:objectKey/fulltext', array('controller' => 'FullText', 'action' => 'itemContent'));
	$router->map('/users/i:objectUserID/fulltext', array('controller' => 'FullText', 'action' => 'fulltext'));
	//$router->map('/users/i:objectUserID/publications/fulltext', ['controller' => 'FullText', 'action' => 'fulltext', 'extra' => ['publications' => true]]);
	$router->map('/groups/i:objectGroupID/fulltext', array('controller' => 'FullText', 'action' => 'fulltext'));
	
	// All trashed items
	$router->map('/users/i:objectUserID/items/trash', array('controller' => 'Items', 'extra' => array('subset' => 'trash')));
	$router->map('/groups/i:objectGroupID/items/trash', array('controller' => 'Items', 'extra' => array('subset' => 'trash')));
	
	// Subcollections, single and multiple
	$router->map('/users/i:objectUserID/collections/:scopeObjectKey/collections/:objectKey', array('controller' => 'Collections', 'extra' => array('scopeObject' => 'collections')));
	$router->map('/groups/i:objectGroupID/collections/:scopeObjectKey/collections/:objectKey', array('controller' => 'Collections','extra' => array('scopeObject' => 'collections')));
	
	// Deleted items in a collection
	$router->map('/users/i:objectUserID/:scopeObject/:scopeObjectKey/items/trash', array('controller' => 'Items', 'extra' => array('subset' => 'trash')));
	
	// Tags, which have names instead of ids
	$router->map('/users/i:objectUserID/tags/:scopeObjectName/items/:objectName/:subset', array('controller' => 'Items', 'extra' => array('scopeObject' => 'tags')));
	$router->map('/groups/i:objectGroupID/tags/:scopeObjectName/items/:objectName/:subset', array('controller' => 'Items', 'extra' => array('scopeObject' => 'tags')));
	$router->map('/users/i:objectUserID/tags/:objectName/:subset', array('controller' => 'Tags'));
	//$router->map('/users/i:objectUserID/publications/tags/:objectName/:subset', ['controller' => 'Tags', 'extra' => ['publications' => true]]);
	$router->map('/groups/i:objectGroupID/tags/:objectName/:subset', array('controller' => 'Tags'));
	
	// Tags within something else
	//$router->map('/users/i:objectUserID/publications/items/:scopeObjectKey/tags/:objectKey/:subset', ['controller' => 'Tags', 'extra' => ['publications']]);
	$router->map('/users/i:objectUserID/:scopeObject/:scopeObjectKey/tags/:objectKey/:subset', array('controller' => 'Tags'));
	$router->map('/groups/i:objectGroupID/:scopeObject/:scopeObjectKey/tags/:objectKey/:subset', array('controller' => 'Tags'));
	
	// Top-level items within something else
	$router->map('/users/i:objectUserID/:scopeObject/:scopeObjectKey/items/top', array('controller' => 'Items', 'extra' => array('subset' => 'top')));
	$router->map('/groups/i:objectGroupID/:scopeObject/:scopeObjectKey/items/top', array('controller' => 'Items', 'extra' => array('subset' => 'top')));
	
	// Items within something else
	$router->map('/users/i:objectUserID/:scopeObject/:scopeObjectKey/items/:objectKey/:subset', array('controller' => 'Items'));
	$router->map('/groups/i:objectGroupID/:scopeObject/:scopeObjectKey/items/:objectKey/:subset', array('controller' => 'Items'));
	
	// User API keys
	$router->map('/keys/:objectName', array('controller' => 'Keys'));
	$router->map('/users/i:objectUserID/keys/:objectName', array('controller' => 'Keys'));
	
	// User/library settings
	$router->map('/users/i:objectUserID/settings/:objectKey', array('controller' => 'settings'));
	$router->map('/groups/i:objectGroupID/settings/:objectKey', array('controller' => 'settings'));
	
	// Clear (for testing)
	$router->map('/users/i:objectUserID/clear', array('controller' => 'Api', 'action' => 'clear'));
	$router->map('/groups/i:objectGroupID/clear', array('controller' => 'Api', 'action' => 'clear'));
	
	// My Publications items
	$router->map('/users/i:objectUserID/publications/settings', ['controller' => 'settings', 'extra' => ['publications' => true]]); // TEMP
	$router->map('/users/i:objectUserID/publications/deleted', ['controller' => 'deleted', 'extra' => ['publications' => true]]); // TEMP
	$router->map('/users/i:objectUserID/publications/items/:objectKey/children', ['controller' => 'Items', 'extra' => ['publications' => true, 'subset' => 'children']]);
	$router->map('/users/i:objectUserID/publications/items/:objectKey', ['controller' => 'Items', 'extra' => ['publications' => true]]);
	
	// Other top-level URLs, with an optional key and subset
	$router->map('/users/i:objectUserID/:controller/:objectKey/:subset');
	$router->map('/groups/i:objectGroupID/:controller/:objectKey/:subset');
	
	$router->map('/itemTypes', array('controller' => 'Mappings', 'extra' => array('subset' => 'itemTypes')));
	$router->map('/itemTypeFields', array('controller' => 'Mappings', 'extra' => array('subset' => 'itemTypeFields')));
	$router->map('/itemFields', array('controller' => 'Mappings', 'extra' => array('subset' => 'itemFields')));
	$router->map('/itemTypeCreatorTypes', array('controller' => 'Mappings', 'extra' => array('subset' => 'itemTypeCreatorTypes')));
	$router->map('/creatorFields', array('controller' => 'Mappings', 'extra' => array('subset' => 'creatorFields')));
	$router->map('/items/new', array('controller' => 'Mappings', 'action' => 'newItem'));
	
	$router->map('/test/setup', array('controller' => 'Api', 'action' => 'testSetup'));
}

return $router->match($_SERVER['REQUEST_URI']);
