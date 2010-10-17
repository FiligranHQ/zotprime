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

define('Z_ENV_START_TIME', microtime(true));

mb_language('uni');
mb_internal_encoding('UTF-8');
date_default_timezone_set('UTC');

function __autoload($className) {
	// Get "Zotero_" classes from model directory
	if (strpos($className, 'Zotero_') === 0) {
		$fileName = str_replace('Zotero_', '', $className) . '.inc.php';
		
		if (strpos($fileName, 'AuthenticationPlugin_') === 0) {
			$fileName = str_replace('AuthenticationPlugin_', '', $fileName);
			$auth = true;
		}
		else {
			$auth = false;
		}
		
		$path = Z_ENV_BASE_PATH . 'model/';
		if ($auth) {
			$path .= 'auth/';
		}
		$path .= $fileName;
		require_once $path;
		return;
	}
	
	// Get everything else from include path
	
	// Strip "Z_" namespace
	if (strpos($className, 'Z_') === 0) {
		$className = str_replace('Z_', '', $className);
	}
	
	require_once $className . '.inc.php';
}

// Read in configuration variables
require('config/config.inc.php');

if (Z_Core::isCommandLine()) {
	if (empty(Z_CONFIG::$CLI_DOCUMENT_ROOT)) {
		throw new Exception ("CLI defaults not set");
	}
	
	$_SERVER['DOCUMENT_ROOT'] = Z_CONFIG::$CLI_DOCUMENT_ROOT;
	$_SERVER['SERVER_NAME'] = Z_CONFIG::$CLI_SERVER_NAME;
	$_SERVER['SERVER_PORT'] = Z_CONFIG::$CLI_SERVER_PORT;
	$_SERVER['REQUEST_URI'] = "/";
	$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] . ":" . $_SERVER['SERVER_PORT'];
}
else {
	// Turn on output buffering
	ob_start();
}

// Absolute base filesystem path
define('Z_ENV_BASE_PATH', substr($_SERVER['DOCUMENT_ROOT'], 0, strrpos($_SERVER['DOCUMENT_ROOT'], '/')) . '/');

// Environmental variables that may change based on where the app is running
define('Z_ENV_CONTROLLER_PATH', Z_ENV_BASE_PATH . 'controllers/');
define('Z_ENV_MODEL_PATH', Z_ENV_BASE_PATH . 'model/');
define('Z_ENV_TMP_PATH', Z_ENV_BASE_PATH . 'tmp/');

if (!is_writable(Z_ENV_TMP_PATH)) {
	throw new Exception("Temp directory is not writable");
}

require('HTMLPurifier/HTMLPurifier.standalone.php');
$c = HTMLPurifier_Config::createDefault();
$c->set('HTML.Doctype', 'XHTML 1.0 Strict');
$c->set('Cache.SerializerPath', Z_ENV_TMP_PATH);
$HTMLPurifier = new HTMLPurifier($c);

// Check if on testing port and set testing mode params if so
if (Z_CONFIG::$TESTING_SITE) {
	define('Z_ENV_TESTING_SITE', true);
	
	// Display errors on testing site
	ini_set("display_errors", "1");
	//error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE | E_STRICT);
	error_reporting(-1);
	
	define('Z_ENV_DEV_SITE', !empty(Z_CONFIG::$DEV_SITE));
}
else {
	define('Z_ENV_TESTING_SITE', false);
	define('Z_ENV_DEV_SITE', false);
	ini_set("display_errors", "0");
}

// Ignore internal Apache OPTIONS request
if ($_SERVER['REQUEST_URI'] == '*') {
	//error_log("Ignoring OPTIONS request");
	exit;
}

// Get canonical URL without extension and query string
preg_match("/[^?\.]+/", $_SERVER['REQUEST_URI'], $matches);
define('Z_ENV_SELF', $matches[0]);

// Load in core functions

require('DB.inc.php');
require('Shards.inc.php');
require('config/dbconnect.inc.php');

// Mongo
require('Mongo.inc.php');
Z_Core::$Mongo = new Z_Mongo(
	"mongodb://" . implode(',', Z_CONFIG::$MONGO_SERVERS),
	array(
		"connect" => false,
		"persist" => ""
	),
	Z_CONFIG::$MONGO_DB
);

// Memcached
require('Memcached.inc.php');
if (isset(Z_CONFIG::$MEMCACHED_SERVER_NAME_PREFIX_MAP[$_SERVER['SERVER_NAME']])) {
	$prefix = Z_CONFIG::$MEMCACHED_SERVER_NAME_PREFIX_MAP[$_SERVER['SERVER_NAME']];
}
else {
	$prefix = $_SERVER['SERVER_NAME'];
}
Z_Core::$MC = new Z_MemcachedClientLocal(
	$prefix,
	array(
		'disabled' => !Z_CONFIG::$MEMCACHED_ENABLED,
		'servers' => Z_CONFIG::$MEMCACHED_SERVERS
	)
);

// Solr
require('Solr.inc.php');
$parts = explode(":", Z_CONFIG::$SOLR_SERVER);
Z_Core::$Solr = new Z_Solr(
	array(
		'hostname' => $parts[0],
		'login'    => "",
		'password' => "",
		'port'     => !empty($parts[1]) ? $parts[1] : 8983,
	)
);

require('interfaces/IAuthenticationPlugin.inc.php');

require('log.inc.php');

// Load in functions
require('functions/string.inc.php');
require('functions/array.inc.php');
?>
