<?php
require '../../vendor/autoload.php';
require_once 'include/config.inc.php';

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
