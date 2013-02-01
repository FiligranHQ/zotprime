<?php
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

$params = require('config/routes.inc.php');

if (!$params || !isset($params['controller']) || $params['controller'] == 404) {
	header("HTTP/1.0 404 Not Found");
	include('errors/404.php');
	return;
}

// Parse variables from router
$controllerName = ucwords($params['controller']);
$action = !empty($params['action']) ? $params['action'] : lcfirst($controllerName);
$directory = !empty($params['directory']) ? $params['directory'] . '/' : "";
$extra = !empty($params['extra']) ? $params['extra'] : array();

// Attempt to load controller
$controllerFile = Z_ENV_CONTROLLER_PATH . $directory . $controllerName . 'Controller.php';
Z_Core::debug("URI is " . Z_ENV_SELF);
Z_Core::debug("Controller is $controllerFile");

if (file_exists($controllerFile)) {
	require('mvc/Controller.inc.php');
	require($controllerFile);
	$controllerClass = $controllerName . 'Controller';
	$controller = new $controllerClass($controllerName, $action, $params);
	$controller->init($extra);
	
	if (method_exists($controllerClass, $action)) {
		call_user_func(array($controller, $action));
		Z_Core::exitClean();
	}
	else {
		throw new Exception("Action '$action' not found in $controllerFile");
	}
}

// If controller not found, load error document
header("HTTP/1.0 404 Not Found");
include('errors/404.php');
