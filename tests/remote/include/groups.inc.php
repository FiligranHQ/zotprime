<?php
//
// Check for existing groups, make sure they have the right permissions,
// and delete any others
//
require_once __DIR__ . '/api3.inc.php';
$response = API3::superGet(
	"users/" . $config['userID'] . "/groups"
);
$groups = API3::getJSONFromResponse($response);
$config['ownedPublicGroupID'] = false;
$config['ownedPublicNoAnonymousGroupID'] = false;
$toDelete = [];
foreach ($groups as $group) {
	$data = $group['data'];
	$id = $data['id'];
	$type = $data['type'];
	$owner = $data['owner'];
	$libraryReading = $data['libraryReading'];
	
	if ($type == 'Private') {
		continue;
	}
	
	if (!$config['ownedPublicGroupID']
			&& $type == 'PublicOpen'
			&& $owner == $config['userID']
			&& $libraryReading == 'all') {
		$config['ownedPublicGroupID'] = $id;
	}
	else if (!$config['ownedPublicNoAnonymousGroupID']
			&& $type == 'PublicClosed'
			&& $owner == $config['userID']
			&& $libraryReading == 'members') {
		$config['ownedPublicNoAnonymousGroupID'] = $id;
	}
	else {
		$toDelete[] = $id;
	}
}

if (!$config['ownedPublicGroupID']) {
	$config['ownedPublicGroupID'] = API3::createGroup([
		'owner' => $config['userID'],
		'type' => 'PublicOpen',
		'libraryReading' => 'all'
	]);
}
if (!$config['ownedPublicNoAnonymousGroupID']) {
	$config['ownedPublicNoAnonymousGroupID'] = API3::createGroup([
		'owner' => $config['userID'],
		'type' => 'PublicClosed',
		'libraryReading' => 'members'
	]);
}
foreach ($toDelete as $groupID) {
	API3::deleteGroup($groupID);
}

$config['numOwnedGroups'] = 3;
$config['numPublicGroups'] = 2;

foreach ($groups as $group) {
	API3::groupClear($group['id']);
}

\Zotero\Tests\Config::update($config);

unset($response);
unset($groups);
unset($toDelete);
