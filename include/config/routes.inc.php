<?
require('mvc/Router.inc.php');
$router = new Router;
$router->keywords = array('controller', 'action');

// Add custom routes here
// Set controller to 404 to block access to an action via a particular URL

// Sync
if ($_SERVER['HTTP_HOST'] == Z_CONFIG::$SYNC_DOMAIN) {
	$router->connect('/:action/*', array('controller' => 'Sync'));
}
// API
else {
	$router->connect('/', array('controller' => 'Api', 'action' => 'noop', 'extra' => array('allowHTTP' => true)));
	
	// Groups
	$router->connect('/groups/:groupID', array('controller' => 'Api', 'action' => 'groups'));
	$router->connect('/groups/:scopeObjectID/users/:id', array('controller' => 'Api', 'action' => 'groupUsers'));
	
	// Top level items
	$router->connect('/users/:userID/:action/top', array('controller' => 'Api', 'extra' => array('subset' => 'top')));
	$router->connect('/groups/:groupID/:action/top', array('controller' => 'Api', 'extra' => array('subset' => 'top')));
	
	// Attachment files
	$router->connect('/users/:userID/laststoragesync', array('controller' => 'Api', 'action' => 'laststoragesync', 'extra' => array('auth' => true)));
	$router->connect('/groups/:groupID/laststoragesync', array('controller' => 'Api', 'action' => 'laststoragesync', 'extra' => array('auth' => true)));
	$router->connect('/users/:userID/storageadmin', array('controller' => 'Api', 'action' => 'storageadmin'));
	$router->connect('/storagepurge', array('controller' => 'Api', 'action' => 'storagepurge'));
	$router->connect('/users/:userID/removestoragefiles', array('controller' => 'Api', 'action' => 'removestoragefiles', 'extra' => array('allowHTTP' => true)));
	$router->connect('/users/:userID/items/:key/file', array('controller' => 'Api', 'action' => 'items', 'extra' => array('allowHTTP' => true, 'file' => true)));
	$router->connect('/users/:userID/items/:key/file/view', array('controller' => 'Api', 'action' => 'items', 'extra' => array('allowHTTP' => true, 'file' => true, 'view' => true)));
	$router->connect('/groups/:groupID/items/:key/file', array('controller' => 'Api', 'action' => 'items', 'extra' => array('allowHTTP' => true, 'file' => true)));
	$router->connect('/groups/:groupID/items/:key/file/view', array('controller' => 'Api', 'action' => 'items', 'extra' => array('allowHTTP' => true, 'file' => true, 'view' => true)));
	
	// May be necessary for tag scope
	//$router->connect('/users/:userID/:scopeObject/:scopeObjectKey/items/top', array('controller' => 'Api', 'action' => 'items', 'extra' => array('subset' => 'top')));
	
	// All deleted items
	$router->connect('/users/:userID/items/trash', array('controller' => 'Api', 'action' => 'items', 'extra' => array('subset' => 'trash')));
	$router->connect('/groups/:groupID/items/trash', array('controller' => 'Api', 'action' => 'items', 'extra' => array('subset' => 'trash')));
	
	// Subcollections, single and multiple
	$router->connect('/users/:userID/collections/:scopeObjectKey/collections/:key', array('controller' => 'Api', 'action' => 'collections', 'extra' => array('scopeObject' => 'collections')));
	$router->connect('/groups/:groupID/collections/:scopeObjectKey/collections/:key', array('controller' => 'Api', 'action' => 'collections', 'extra' => array('scopeObject' => 'collections')));
	
	// Deleted items in a collection
	$router->connect('/users/:userID/:scopeObject/:scopeObjectKey/items/trash', array('controller' => 'Api', 'action' => 'items', 'extra' => array('subset' => 'trash')));
	
	// Tags, which have names instead of ids
	$router->connect('/users/:userID/tags/:scopeObjectName/items/:name/:subset', array('controller' => 'Api', 'action' => 'items', 'extra' => array('scopeObject' => 'tags')));
	$router->connect('/groups/:groupID/tags/:scopeObjectName/items/:name/:subset', array('controller' => 'Api', 'action' => 'items', 'extra' => array('scopeObject' => 'tags')));
	$router->connect('/users/:userID/tags/:name/:subset', array('controller' => 'Api', 'action' => 'tags'));
	$router->connect('/groups/:groupID/tags/:name/:subset', array('controller' => 'Api', 'action' => 'tags'));
	
	// Tags within something else
	$router->connect('/users/:userID/:scopeObject/:scopeObjectKey/tags/:key/:subset', array('controller' => 'Api', 'action' => 'tags'));
	$router->connect('/groups/:groupID/:scopeObject/:scopeObjectKey/tags/:key/:subset', array('controller' => 'Api', 'action' => 'tags'));
	
	// Items within something else
	$router->connect('/users/:userID/:scopeObject/:scopeObjectKey/items/:key/:subset', array('controller' => 'Api', 'action' => 'items'));
	$router->connect('/groups/:groupID/:scopeObject/:scopeObjectKey/items/:key/:subset', array('controller' => 'Api', 'action' => 'items'));
	
	$router->connect('/users/:userID/keys/:name', array('controller' => 'Api', 'action' => 'keys'));
	
	// Other top-level URLs, with an optional key and subset
	$router->connect('/users/:userID/:action/:key/:subset', array('controller' => 'Api'));
	$router->connect('/groups/:groupID/:action/:key/:subset', array('controller' => 'Api'));
	
	$router->connect('/itemTypes', array('controller' => 'Api', 'action' => 'mappings', 'extra' => array('subset' => 'itemTypes')));
	$router->connect('/itemTypeFields', array('controller' => 'Api', 'action' => 'mappings', 'extra' => array('subset' => 'itemTypeFields')));
	$router->connect('/itemFields', array('controller' => 'Api', 'action' => 'mappings', 'extra' => array('subset' => 'itemFields')));
	$router->connect('/itemTypeCreatorTypes', array('controller' => 'Api', 'action' => 'mappings', 'extra' => array('subset' => 'itemTypeCreatorTypes')));
	$router->connect('/creatorFields', array('controller' => 'Api', 'action' => 'mappings', 'extra' => array('subset' => 'creatorFields')));
	$router->connect('/items/new', array('controller' => 'Api', 'action' => 'newItem'));
	
	$router->connect('/test/setup', array('controller' => 'Api', 'action' => 'testSetup'));
}

//echo "<pre>";var_dump($router->parse(Z_ENV_SELF));echo "</pre>";

return $router->parse(Z_ENV_SELF);
?>
