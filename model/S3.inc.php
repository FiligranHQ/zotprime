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

class Zotero_S3 {
	public static $defaultQuota = 100;
	public static $uploadQueueLimit = 10;
	public static $uploadQueueTimeout = 300;
	
	public static function requireLibrary() {
		require_once('S3Lib.inc.php');
	}
	
	public static function getDownloadDetails($item) {
		// TODO: get attachment link mode value from somewhere
		if (!$item->isAttachment() || !$item->isImportedAttachment()) {
			return false;
		}
		$sql = "SELECT storageFileID FROM storageFileItems WHERE itemID=?";
		$storageFileID = Zotero_DB::valueQuery($sql, $item->id, Zotero_Shards::getByLibraryID($item->libraryID));
		if (!$storageFileID) {
			return false;
		}
		
		$url = Zotero_API::getItemURI($item) . "/file";
		$info = self::getFileInfoByID($storageFileID);
		if ($info['zip']) {
			return false;
		}
		return array(
			'url' => $url,
			'filename' => $info['filename'],
			'size' => $info['size']
		);
	}
	
	public static function getDownloadURL($item, $ttl=false) {
		self::requireLibrary();
		S3::setAuth(Z_CONFIG::$S3_ACCESS_KEY, Z_CONFIG::$S3_SECRET_KEY);
		
		if (!$item->isAttachment()) {
			throw new Exception("Item $item->id is not an attachment");
		}
		
		$info = self::getLocalFileItemInfo($item);
		if (!$info) {
			return false;
		}
		
		// Create expiring URL on Amazon and return
		$url = S3::getAuthenticatedURL(
			Z_CONFIG::$S3_BUCKET,
			self::getPathPrefix($info['hash'], $info['zip']) . $info['filename'],
			$ttl,
			false,
			true
		);
		
		return $url;
	}
	
	public static function logDownload($item, $downloadUserID, $ipAddress) {
		$libraryID = $item->libraryID;
		$ownerUserID = Zotero_Libraries::getOwner($libraryID);
		
		$info = self::getLocalFileItemInfo($item);
		$storageFileID = $info['storageFileID'];
		$filename = $info['filename'];
		$size = $info['size'];
		
		$sql = "INSERT DELAYED INTO storageDownloadLog
				(ownerUserID, downloadUserID, ipAddress, storageFileID, filename, size)
				VALUES (?, ?, INET_ATON(?), ?, ?, ?)";
		Zotero_DB::query($sql, array($ownerUserID, $downloadUserID, $ipAddress, $storageFileID, $filename, $size));
	}
	
	
	public static function queueUpload($userID, $hash, $filename, $zip=false) {
		$uploadKey = md5(uniqid(rand(), true));
		
		$sql = "SELECT COUNT(*) FROM storageUploadQueue WHERE userID=?
				AND time > (NOW() - INTERVAL " . self::$uploadQueueTimeout . " SECOND)";
		$num = Zotero_DB::valueQuery($sql, $userID);
		if ($num > self::$uploadQueueLimit) {
			return false;
		}
		
		$sql = "INSERT INTO storageUploadQueue
				(uploadKey, userID, hash, filename, zip)
				VALUES (?, ?, ?, ?, ?)";
		Zotero_DB::query($sql, array($uploadKey, $userID, $hash, $filename, $zip ? 1 : 0));
		
		return $uploadKey;
	}
	
	
	public static function getUploadInfo($key) {
		$sql = "SELECT * FROM storageUploadQueue WHERE uploadKey=?";
		return Zotero_DB::rowQuery($sql, $key);
	}
	
	
	public static function logUpload($item, $key, $ipAddress) {
		$libraryID = $item->libraryID;
		$ownerUserID = Zotero_Libraries::getOwner($libraryID);
		
		$info = self::getUploadInfo($key);
		if (!$info) {
			throw new Exception("Upload key '$key' not found in queue");
		}
		
		$uploadUserID = $info['userID'];
		
		$info = self::getLocalFileItemInfo($item);
		$storageFileID = $info['storageFileID'];
		$filename = $info['filename'];
		$size = $info['size'];
		
		$sql = "DELETE FROM storageUploadQueue WHERE uploadKey=?";
		Zotero_DB::query($sql, $key);
		
		$sql = "INSERT DELAYED INTO storageUploadLog
				(ownerUserID, uploadUserID, ipAddress, storageFileID, filename, size)
				VALUES (?, ?, INET_ATON(?), ?, ?, ?)";
		Zotero_DB::query($sql, array($ownerUserID, $uploadUserID, $ipAddress, $storageFileID, $filename, $size));
	}
	
	
	public static function getUploadURL() {
		return "https://" . Z_CONFIG::$S3_BUCKET . ".s3.amazonaws.com/";
	}
	
	
	public static function getFileByHash($hash, $zip) {
		$sql = "SELECT storageFileID FROM storageFiles WHERE hash=? AND zip=?";
		return Zotero_DB::valueQuery($sql, array($hash, (int) $zip));
	}
	
	public static function getFileInfoByID($storageFileID) {
		$sql = "SELECT * FROM storageFiles WHERE storageFileID=?";
		return Zotero_DB::rowQuery($sql, $storageFileID);
	}
	
	public static function getLocalFileInfo($hash, $filename, $zip) {
		$sql = "SELECT * FROM storageFiles WHERE hash=? AND filename=? AND zip=?";
		return Zotero_DB::rowQuery($sql, array($hash, $filename, (int) $zip));
	}
	
	public static function getRemoteFileInfo($hash, $filename, $zip) {
		self::requireLibrary();
		S3::setAuth(Z_CONFIG::$S3_ACCESS_KEY, Z_CONFIG::$S3_SECRET_KEY);
		
		$url = self::getPathPrefix($hash, $zip) . $filename;
		
		$info = S3::getObjectInfo(
			Z_CONFIG::$S3_BUCKET,
			$url,
			true
		);
		if (!$info) {
			return false;
		}
		return $info;
	}
	
	
	/**
	 * Get item-specific file info
	 */
	public static function getLocalFileItemInfo($item) {
		$sql = "SELECT * FROM " . Z_CONFIG::$SHARD_MASTER_DB . ".storageFiles
				JOIN storageFileItems USING (storageFileID)
				WHERE itemID=?";
		return Zotero_DB::rowQuery($sql, $item->id, Zotero_Shards::getByLibraryID($item->libraryID));
	}
	
	/**
	 * Get items associated with a unique file on S3
	 */
	public static function getFileItems($hash, $filename, $zip) {
		throw new Exception("Unimplemented"); // would need to work across shards
		
		$sql = "SELECT itemID FROM storageFiles JOIN storageFileItems USING (storageFileID)
				WHERE hash=? AND filename=? AND zip=?";
		$itemIDs = Zotero_DB::columnQuery($sql, array($hash, $filename, (int) $zip));
		if (!$itemIDs) {
			return array();
		}
		return $itemIDs;
	}
	
	
	public static function addFile($hash, $filename, $size, $zip) {
		$sql = "INSERT INTO storageFiles (hash, filename, size, zip) VALUES (?,?,?,?)";
		$storageFileID = Zotero_DB::query($sql, array($hash, $filename, $size, (int) $zip));
		return $storageFileID;
	}
	
	
	public static function updateFileItemInfo($item, $storageFileID, $mtime) {
		$sql = "INSERT INTO storageFileItems (storageFileID, itemID, mtime) VALUES (?,?,?)
				ON DUPLICATE KEY UPDATE storageFileID=?, mtime=?";
		Zotero_DB::query(
			$sql,
			array($storageFileID, $item->id, $mtime, $storageFileID, $mtime),
			Zotero_Shards::getByLibraryID($item->libraryID)
		);
	}
	
	/*public static function getUploadAuthorization($item, $date, $contentMD5='') {
		$method = "PUT";
		
		// TODO: validate library, key, and filename
		
		// make sure upload is allowed here?
		
		if (!$item->isAttachment()) {
			throw new Exception("Item $item->id is not an attachment");
		}
		$linkMode = $item->attachmentLinkMode;
		switch ($linkMode) {
			// TODO: get these constants from somewhere
			case 0:
			case 1:
				break;
			
			default:
				throw new Exception("Attachment with link mode $linkMode cannot be uploaded");
		}
		$filename = substr($item->attachmentPath, 8);
		
		$resource = "/$item->libraryID/$item->key/$filename";
		
		$contentType = $item->attachmentMIMEType;
		
		$stringToSign = "$method\n$contentMD5\n$contentType\n$date\n$resource";
		
		return "AWS " . Z_CONFIG::$S3_ACCESS_KEY . ":" . self::getHash($stringToSign);
	}*/
	
	
	public static function getPathPrefix($hash, $zip=false) {
		return "$hash/" . ($zip ? "c/" : '');
	}
	
	
	public static function generateUploadPOSTParams($item, $md5, $filename, $fileSize, $zip) {
		if (strlen($md5) != 32) {
			throw new Exception("Invalid MD5 hash '$md5'");
		}
		
		$contentMD5 = '';
		for($i = 0; $i < strlen($md5); $i += 2)
			$contentMD5 .= chr(hexdec(substr($md5, $i, 2)));
		$contentMD5 = base64_encode($contentMD5);
		
		if (!$item->isAttachment()) {
			throw new Exception("Item $item->id is not an attachment");
		}
		$linkMode = $item->attachmentLinkMode;
		switch ($linkMode) {
			// TODO: get these constants from somewhere
			case 0:
			case 1:
				break;
			
			default:
				throw new Exception("Attachment with link mode $linkMode cannot be uploaded");
		}
		
		$lifetime = 3600;
		
		$path = self::getPathPrefix($md5, $zip);
		
		if ($zip) {
			$contentType = "application/octet-stream";
		}
		else {
			$contentType = $item->attachmentMIMEType;
		}
		
		$metaHeaders = array();
		$requestHeaders = array(
			'Content-Type' => $contentType,
			'Content-MD5' => $contentMD5,
			//'Content-Disposition' => 'attachment; filename="' . $filename . '"'
		);
		
		self::requireLibrary();
		S3::setAuth(Z_CONFIG::$S3_ACCESS_KEY, Z_CONFIG::$S3_SECRET_KEY);
		$params = S3::getHttpUploadPostParams(
			Z_CONFIG::$S3_BUCKET,
			$path,
			S3::ACL_PRIVATE,
			$lifetime,
			$fileSize + 262144, // an extra 256KB that may or may not be necessary
			201,
			$metaHeaders,
			$requestHeaders,
			$filename
		);
		return $params;
	}
	
	
	public static function duplicateFile($storageFileID, $newName, $zip) {
		self::requireLibrary();
		
		if (!$newName) {
			throw new Exception("New name not provided");
		}
		
		$info = self::getFileInfoByID($storageFileID);
		if (!$info) {
			throw new Exception("File $storageFileID not found");
		}
		
		S3::setAuth(Z_CONFIG::$S3_ACCESS_KEY, Z_CONFIG::$S3_SECRET_KEY);
		$success = S3::copyObject(
			Z_CONFIG::$S3_BUCKET,
			self::getPathPrefix($info['hash'], $info['zip']) . $info['filename'],
			Z_CONFIG::$S3_BUCKET,
			self::getPathPrefix($info['hash'], $zip) . $newName
		);
		if (!$success) {
			return false;
		}
		
		$storageFileID = self::addFile($info['hash'], $newName, $info['size'], $zip);
		return $storageFileID;
	}
	
	
	public static function purgeUnusedFiles() {
		self::requireLibrary();
		
		// Get all used files and files that were last deleted more than a month ago
		$sql = "SELECT MD5(CONCAT(hash, filename, zip)) AS file FROM storageFiles
					JOIN storageFileItems USING (storageFileID)
				UNION
				SELECT MD5(CONCAT(hash, filename, zip)) AS file FROM storageFiles
					WHERE lastDeleted > NOW() - INTERVAL 1 MONTH";
		$files = Zotero_DB::columnQuery($sql);
		
		S3::setAuth(Z_CONFIG::$S3_ACCESS_KEY, Z_CONFIG::$S3_SECRET_KEY);
		$s3Files = S3::getBucket(Z_CONFIG::$S3_BUCKET);
		
		$toPurge = array();
		
		foreach ($s3Files as $s3File) {
			preg_match('/^([0-9a-g]{32})\/(c\/)?(.+)$/', $s3File['name'], $matches);
			if (!$matches) {
				throw new Exception("Invalid filename '" . $s3File['name'] . "'");
			}
			
			$zip = $matches[2] ? '1' : '0';
			
			// Compressed file
			$hash = md5($matches[1] . $matches[3] . $zip);
			
			if (!in_array($hash, $files)) {
				$toPurge[] = array(
					'hash' => $matches[1],
					'filename' => $matches[3],
					'zip' => $zip
				);
			}
		}
		
		Zotero_DB::beginTransaction();
		
		foreach ($toPurge as $info) {
			S3::deleteObject(
				Z_CONFIG::$S3_BUCKET,
				self::getPathPrefix($info['hash'], $info['zip']) . $info['filename']
			);
			
			$sql = "DELETE FROM storageFiles WHERE hash=? AND filename=? AND zip=?";
			Zotero_DB::query($sql, array($info['hash'], $info['filename'], $info['zip']));
		}
		
		Zotero_DB::commit();
		
		return sizeOf($toPurge);
	}
	
	
	public static function getUserValues($userID) {
		$sql = "SELECT quota, UNIX_TIMESTAMP(expiration) AS expiration FROM storageAccounts WHERE userID=?";
		return Zotero_DB::rowQuery($sql, $userID);
	}
	
	public static function setUserValues($userID, $quota, $expiration) {
		$cacheKey = "userStorageQuota_" . $userID;
		Z_Core::$MC->delete($cacheKey);
		
		if ($expiration == 0 && $quota == 0) {
			$sql = "DELETE FROM storageAccounts WHERE userID=?";
			Zotero_DB::query($sql, $userID);
			return;
		}
		
		$usage = self::getUserUsage($userID);
		if ($quota < $usage['total']) {
			throw new Exception("Cannot set quota below current usage", Z_ERROR_GROUP_QUOTA_SET_BELOW_USAGE);
		}
		
		if ($expiration) {
			$sql = "INSERT INTO storageAccounts (userID, quota, expiration) VALUES (?,?,FROM_UNIXTIME(?))
					ON DUPLICATE KEY UPDATE quota=?, expiration=FROM_UNIXTIME(?)";
			Zotero_DB::query($sql, array($userID, $quota, $expiration, $quota, $expiration));
		}
		else {
			$sql = "INSERT INTO storageAccounts (userID, quota, expiration) VALUES (?,?,NULL)
					ON DUPLICATE KEY UPDATE quota=?, expiration=NULL";
			Zotero_DB::query($sql, array($userID, $quota, $quota));
		}
	}
	
	public static function getInstitutionalUserQuota($userID) {
		// TODO: config
		$databaseName = "zotero_www";
		if (Z_ENV_TESTING_SITE) {
			$databaseName .= "_test";
		}
		
		// Get maximum institutional quota by e-mail domain
		$sql = "SELECT IFNULL(MAX(storageQuota), 0) FROM $databaseName.users_email
				JOIN $databaseName.storage_institutions ON (SUBSTRING_INDEX(email, '@', -1)=domain)
				WHERE userID=?";
		$institutionalDomainQuota = Zotero_DB::valueQuery($sql, $userID);
		
		// Get maximum institutional quota by e-mail address
		$sql = "SELECT IFNULL(MAX(storageQuota), 0) FROM $databaseName.users_email
				JOIN $databaseName.storage_institution_email USING (email)
				JOIN $databaseName.storage_institutions USING (institutionID)
				WHERE userID=?";
		$institutionalEmailQuota = Zotero_DB::valueQuery($sql, $userID);
		
		$quota = max($institutionalDomainQuota, $institutionalEmailQuota);
		return $quota ? $quota : false;
	}
	
	public static function getEffectiveUserQuota($userID) {
		$cacheKey = "userStorageQuota_" . $userID;
		
		$quota = Z_Core::$MC->get($cacheKey);
		if ($quota) {
			return $quota;
		}
		
		$personalQuota = self::getUserValues($userID);
		$personalQuota = $personalQuota ? $personalQuota['quota'] : 0;
		
		$instQuota = self::getInstitutionalUserQuota($userID);
		if (!$instQuota) {
			$instQuota = 0;
		}
		
		$quota = max($personalQuota, $instQuota);
		$quota = $quota ? $quota : self::$defaultQuota;
		
		Z_Core::$MC->set($cacheKey, $quota, 60);
		
		return $quota;
	}
	
	public static function getUserUsage($userID) {
		$usage = array();
		
		$masterDB = Z_CONFIG::$SHARD_MASTER_DB;
		
		$sql = "SELECT SUM(size) AS bytes FROM $masterDB.storageFiles ST
				JOIN storageFileItems STI USING (storageFileID)
				JOIN items USING (itemID)
				JOIN $masterDB.users USING (libraryID)
				WHERE userID=?";
		$libraryBytes = Zotero_DB::valueQuery($sql, $userID, Zotero_Shards::getByUserID($userID));
		$usage['library'] = round($libraryBytes / 1024 / 1024, 1);
		
		$shardIDs = Zotero_Groups::getUserGroupShards($userID);
		
		$groupBytes = 0;
		$usage['groups'] = array();
		foreach ($shardIDs as $shardID) {
			$sql = "SELECT G.groupID, SUM(size) AS `bytes` FROM $masterDB.storageFiles ST
					JOIN storageFileItems STI USING (storageFileID)
					JOIN items I USING (itemID)
					JOIN $masterDB.groups G USING (libraryID)
					JOIN $masterDB.groupUsers GU ON (G.groupID=GU.groupID AND role='owner')
					WHERE userID=? GROUP BY groupID WITH ROLLUP";
			$groups = Zotero_DB::query($sql, $userID, $shardID);
			if ($groups) {
				foreach ($groups as $group) {
					if ($group['groupID']) {
						$usage['groups'][] = array('id' => $group['groupID'], 'usage' => round($group['bytes'] / 1024 / 1024, 1));
					}
					// ROLLUP row
					else {
						$groupBytes += $group['bytes'];
					}
				}
			}
		}
		
		$usage['total'] = round(($libraryBytes + $groupBytes) / 1024 / 1024, 1);
		return $usage;
	}
	
	
	/*private static function getHash($stringToSign) {
		return base64_encode(hash_hmac('sha1', $stringToSign, Z_CONFIG::$S3_SECRET_KEY, true));
	}*/
}
?>
