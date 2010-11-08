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

$routes = require('config/routes.inc.php');

if (!$routes) {
	header("HTTP/1.0 404 Not Found");
	include('errors/404.php');
	return;
}

// Parse variables from router
$controllerName = Z_String::under2camel($routes['controller']);
$action = !empty($routes['action']) ? $routes['action'] : 'index';
$pass = !empty($routes['pass']) ? $routes['pass'] : array();

$settings['directory'] = !empty($routes['directory']) ? $routes['directory'] : false;

$suffix = '';

$extra = !empty($routes['extra']) ? $routes['extra'] : array();

// Attempt to load controller
$controllerFile = Z_ENV_CONTROLLER_PATH . $settings['directory'] . '/' . $controllerName . $suffix . 'Controller.php';
Z_Core::debug("Attempting to include controller $controllerFile");

if ($controllerName != 404 && file_exists($controllerFile)) {
	require('mvc/Controller.inc.php');
	require($controllerFile);
	$controllerClass = $controllerName . $suffix . 'Controller';
	$controller = new $controllerClass($action, $settings, $extra);
	
	$controller->init($controllerName, $action, $settings, $extra);
	
	// Make sure the action hasn't changed due to POST vars
	if ($action!=$controller->getAction()) {
		// Prepend original action to pass parameters and reset action
		array_unshift($pass, $action);
		$action = $controller->getAction();
	}
	
	if (method_exists($controllerClass, $action)) {
		call_user_func_array(array($controller, $action), $pass);
		Z_Core::exitClean();
	}
	else {
		trigger_error("Action '$action' not found in $controllerFile");
	}
}

// If controller not found, load error document
header("HTTP/1.0 404 Not Found");
include('errors/404.php');
?>
