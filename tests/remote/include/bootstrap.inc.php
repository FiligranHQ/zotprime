<?php
require '../../vendor/autoload.php';
require 'include/config.inc.php';

mb_language('uni');
mb_internal_encoding('UTF-8');
date_default_timezone_set('UTC');
require '../../model/Date.inc.php';
require '../../model/Utilities.inc.php';

class Z_Tests {
	public static $AWS;
}

//
// Set up AWS service factory
//
$awsConfig = [
	'region' => $config['awsRegion'],
	'version' => 'latest'
];
//  Access key and secret (otherwise uses IAM role authentication)
if (!empty($config['awsAccessKey'])) {
	$awsConfig['credentials'] = [
		'key' => $config['awsAccessKey'],
		'secret' => $config['awsSecretKey']
	];
}
Z_Tests::$AWS = new Aws\Sdk($awsConfig);
unset($awsConfig);

// Wipe data and create API key
require_once 'http.inc.php';
$response = HTTP::post(
	$config['apiURLPrefix'] . "test/setup?u=" . $config['userID'],
	" ",
	[],
	[
		"username" => $config['rootUsername'],
		"password" => $config['rootPassword']
	]
);
$json = json_decode($response->getBody());
if (!$json) {
	echo $response->getStatus() . "\n\n";
	echo $response->getBody();
	throw new Exception("Invalid test setup response");
}
$config['apiKey'] = $json->apiKey;
\Zotero\Tests\Config::update($config);

// Set up groups
require 'groups.inc.php';
