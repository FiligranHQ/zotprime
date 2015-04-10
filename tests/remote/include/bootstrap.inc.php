<?php
require '../../vendor/autoload.php';
require 'include/config.inc.php';

require '../../model/Date.inc.php';
require '../../model/Utilities.inc.php';

class Z_Tests {
	public static $AWS;
}

//
// Set up AWS service factory
//
$awsConfig = [
	'region' => $config['awsRegion']
];
// IAM role authentication
if (empty($config['awsAccessKey'])) {
	$awsConfig['credentials.cache'] = new Guzzle\Cache\DoctrineCacheAdapter(
		new Doctrine\Common\Cache\FilesystemCache('work/cache')
	);
}
// Access key and secret
else {
	$awsConfig['key'] = $config['awsAccessKey'];
	$awsConfig['secret'] = $config['awsSecretKey'];
}
Z_Tests::$AWS = \Aws\Common\Aws::factory($awsConfig);
unset($awsConfig);

// Wipe data and create API key
require 'http.inc.php';
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
	echo $response->getBody();
	throw new Exception("Invalid test setup response");
}
$config['apiKey'] = $json->apiKey;
\Zotero\Tests\Config::update($config);

// Set up groups
require 'groups.inc.php';
