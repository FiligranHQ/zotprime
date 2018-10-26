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

function zotero_autoload($className) {
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
	
	// Get \Zotero-namespaced files from model directory
	if (strpos($className, 'Zotero\\') === 0) {
		$parts = explode('\\', $className);
		require Z_ENV_BASE_PATH . 'model/' . end($parts) . '.inc.php';
		return;
	}
	
	// Get everything else from include path
	
	switch ($className) {
	case 'HTTPException':
		require_once $className . '.inc.php';
		return;
	}
	
	// Strip "Z_" namespace
	if (strpos($className, 'Z_') === 0) {
		$className = str_replace('Z_', '', $className);
		require_once $className . '.inc.php';
		return;
	}
	
	// Elastica
	if (strpos($className, 'Elastica\\') === 0) {
		$className = str_replace('\\', '/', $className);
		require_once 'Elastica/lib/' . $className . '.php';
		return;
	}
}

spl_autoload_register('zotero_autoload');

// Read in configuration variables
require('config/config.inc.php');

if (Z_Core::isCommandLine()) {
	$_SERVER['DOCUMENT_ROOT'] = realpath(dirname(dirname(__FILE__))) . '/';
	$_SERVER['SERVER_NAME'] = Z_CONFIG::$SYNC_DOMAIN;
	$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'];
	$_SERVER['REQUEST_URI'] = "/";
}
else {
	// Allow a URI pattern to reproxy the request via Perlbal
	if (!empty(Z_CONFIG::$REPROXY_MAP)) {
		foreach (Z_CONFIG::$REPROXY_MAP as $prefix=>$servers) {
			if (preg_match("'$prefix'", $_SERVER['REQUEST_URI'])) {
				foreach ($servers as &$server) {
					$server .= $_SERVER['REQUEST_URI'];
				}
				header("X-REPROXY-URL: " . implode(" ", $servers));
				exit;
			}
		}
	}
	
	// Allow a URI prefix to override the domain
	// e.g., to treat zotero.org/api/users as api.zotero.org/users
	if (!empty(Z_CONFIG::$URI_PREFIX_DOMAIN_MAP)) {
		foreach (Z_CONFIG::$URI_PREFIX_DOMAIN_MAP as $prefix=>$domain) {
			if (preg_match("%^$prefix(.*)%", $_SERVER['REQUEST_URI'], $matches)) {
				$_SERVER['SERVER_NAME'] = $domain;
				$_SERVER['HTTP_HOST'] = $domain;
				// Make sure there's a leading slash
				if (substr($matches[1], 0, 1) != '/') {
					$matches[1] = '/' . $matches[1];
				}
				$_SERVER['REQUEST_URI'] = $matches[1];
				break;
			}
		}
	}
	
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
	throw new Exception("Temp directory '" . Z_ENV_TMP_PATH . "' is not writable");
}

// Allow per-machine config overrides
if (file_exists(Z_ENV_BASE_PATH . 'include/config/custom.inc.php')) {
	require('config/custom.inc.php');
}

// Composer autoloads
require Z_ENV_BASE_PATH . 'vendor/autoload.php';

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
preg_match("/[^?]+/", $_SERVER['REQUEST_URI'], $matches);
define('Z_ENV_SELF', $matches[0]);

// Load in core functions

require('DB.inc.php');
require('IPAddress.inc.php');
require('Shards.inc.php');
require('config/dbconnect.inc.php');

require('StatsD.inc.php');

// Use DB read replicas for GET requests
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] == 'GET') {
	Zotero_DB::readOnly(true);
}

// Database callbacks
Zotero_DB::addCallback("begin", array("Zotero_Notifier", "begin"));
Zotero_DB::addCallback("commit", array("Zotero_Notifier", "commit"));
Zotero_DB::addCallback("callback", array("Zotero_Notifier", "reset"));
Zotero_NotifierObserver::init();

// Memcached
require('Memcached.inc.php');
Z_Core::$MC = new Z_MemcachedClientLocal(
	Z_CONFIG::$SYNC_DOMAIN,
	array(
		'disabled' => !Z_CONFIG::$MEMCACHED_ENABLED,
		'servers' => Z_CONFIG::$MEMCACHED_SERVERS
	)
);
Zotero_DB::addCallback("begin", array(Z_Core::$MC, "begin"));
Zotero_DB::addCallback("commit", array(Z_Core::$MC, "commit"));
Zotero_DB::addCallback("reset", array(Z_Core::$MC, "reset"));

//
// Set up AWS service factory
//
$awsConfig = [
	'region' => !empty(Z_CONFIG::$AWS_REGION) ? Z_CONFIG::$AWS_REGION : 'us-east-1',
	'version' => 'latest',
	'signature' => 'v4',
	'endpoint' => 'http://' . Z_CONFIG::$S3_ENDPOINT,
	'scheme' => 'http',
	'http' => [
		'timeout' => 3
	],
	'retries' => 2
];

// IAM role authentication
if (empty(Z_CONFIG::$AWS_ACCESS_KEY)) {
	// If APC cache is available, use that to cache temporary credentials
	if (function_exists('apc_store')) {
		$cache = new \Doctrine\Common\Cache\ApcCache();
	}
	// Otherwise use temp dir
	else {
		$cache = new \Doctrine\Common\Cache\FilesystemCache(Z_ENV_BASE_PATH . 'tmp/cache');
	}
	$awsConfig['credentials'] = new \Aws\DoctrineCacheAdapter($cache);
}
// Access key and secret
else {
	$awsConfig['credentials'] = [
		'key' => Z_CONFIG::$AWS_ACCESS_KEY,
		'secret' => Z_CONFIG::$AWS_SECRET_KEY
	];
}
Z_Core::$AWS = new Aws\Sdk($awsConfig);
unset($awsConfig);

// Elastica
$searchHosts = array_map(function ($hostAndPort) {
	preg_match('/^([^:]+)(:[0-9]+)?$/', $hostAndPort, $matches);
	return [
		'host' => $matches[1],
		'port' => isset($matches[2]) ? $matches[2] : 9200
	];
}, Z_CONFIG::$SEARCH_HOSTS);
shuffle($searchHosts);
Z_Core::$Elastica = new \Elastica\Client([
	'connections' => $searchHosts
]);

require('interfaces/IAuthenticationPlugin.inc.php');

require('log.inc.php');
Z_Core::$debug = !empty(Z_CONFIG::$DEBUG_LOG);

// Load in functions
require('functions/string.inc.php');
require('functions/array.inc.php');
?>
