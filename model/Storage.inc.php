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

class Zotero_Storage {
	public static $defaultQuota = 300;
	public static $uploadQueueLimit = 10;
	public static $uploadQueueTimeout = 300;
	private static $s3PresignedRequestTTL = 3600;
	
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
		
		$url = Zotero_API::getItemURI($item) . "/file/view";
		$info = self::getFileInfoByID($storageFileID);
		if ($info['zip']) {
			return array(
				'url' => $url
			);
		}
		else {
			return array(
				'url' => $url,
				'filename' => $info['filename'],
				'size' => $info['size']
			);
		}
	}
	
	public static function getDownloadURL(Zotero_Item $item, $ttl=60) {
		if (!$item->isAttachment()) {
			throw new Exception("Item $item->id is not an attachment");
		}
		
		$info = self::getLocalFileItemInfo($item);
		if (!$info) {
			return false;
		}
		
		$contentType = $item->attachmentMIMEType;
		$charset = $item->attachmentCharset;
		if ($charset) {
			// TEMP: Make sure charset is printable ASCII
			$charset = preg_replace('/[^A-Za-z0-9\-]/', '', $charset);
			$contentType .= "; charset=$charset";
		}
		
		$s3Client = Z_Core::$AWS->createS3();
		try {
			$key = $info['hash'];
			$s3Client->headObject([
				'Bucket' => Z_CONFIG::$S3_BUCKET,
				'Key' => $info['hash']
			]);
		}
		catch (\Aws\S3\Exception\S3Exception $e) {
			// Supposed to be NoSuchKey according to
			// http://docs.aws.amazon.com/aws-sdk-php/v3/api/api-s3-2006-03-01.html#headobject,
			// but returning NotFound
			if ($e->getAwsErrorCode() == 'NoSuchKey' || $e->getAwsErrorCode() == 'NotFound') {
				// Try legacy key format, with zip flag and filename
				try {
					$key = self::getPathPrefix($info['hash'], $info['zip']) . $info['filename'];
					$s3Client->headObject([
						'Bucket' => Z_CONFIG::$S3_BUCKET,
						'Key' => $key
					]);
				}
				catch (\Aws\S3\Exception\S3Exception $e) {
					if ($e->getAwsErrorCode() == 'NoSuchKey' || $e->getAwsErrorCode() == 'NotFound') {
						return false;
					}
					throw $e;
				}
			}
			else {
				throw $e;
			}
		}
		
		$cmd = $s3Client->getCommand('GetObject', [
			'Bucket' => Z_CONFIG::$S3_BUCKET,
			'Key' => $key,
			'ResponseContentType' => $contentType
		]);
		return (string) $s3Client->createPresignedRequest($cmd, "+$ttl seconds")->getUri();
	}
	
	
	public static function downloadFile(array $localFileItemInfo, $savePath, $filename=false) {
		if (!file_exists($savePath)) {
			throw new Exception("Path '$savePath' does not exist");
		}
		
		if (!is_dir($savePath)) {
			throw new Exception("'$savePath' is not a directory");
		}
		
		$s3Client = Z_Core::$AWS->createS3();
		try {
			return $s3Client->getObject([
				'Bucket' => Z_CONFIG::$S3_BUCKET,
				'Key' => $localFileItemInfo['hash'],
				'SaveAs' => $savePath . "/" . ($filename ? $filename : $localFileItemInfo['filename'])
			]);
		}
		catch (\Aws\S3\Exception\S3Exception $e) {
			if ($e->getAwsErrorCode() == 'NoSuchKey') {
				// Try legacy key format, with zip flag and filename
				try {
					return $s3Client->getObject([
						'Bucket' => Z_CONFIG::$S3_BUCKET,
						'Key' => self::getPathPrefix($localFileItemInfo['hash'], $localFileItemInfo['zip'])
							. $localFileItemInfo['filename'],
						'SaveAs' => $savePath . "/" . ($filename ? $filename : $localFileItemInfo['filename'])
					]);
				}
				catch (\Aws\S3\Exception\S3Exception $e) {
					if ($e->getAwsErrorCode() == 'NoSuchKey') {
						return false;
					}
					throw $e;
				}
			}
			else {
				throw $e;
			}
		}
	}
	
	public static function logDownload($item, $downloadUserID, $ipAddress) {
		$libraryID = $item->libraryID;
		$ownerUserID = Zotero_Libraries::getOwner($libraryID);
		
		$info = self::getLocalFileItemInfo($item);
		$storageFileID = $info['storageFileID'];
		$filename = $info['filename'];
		$size = $info['size'];
		
		$sql = "INSERT INTO storageDownloadLog
				(ownerUserID, downloadUserID, ipAddress, storageFileID, filename, size)
				VALUES (?, ?, INET_ATON(?), ?, ?, ?)";
		Zotero_DB::query($sql, array($ownerUserID, $downloadUserID, $ipAddress, $storageFileID, $filename, $size));
	}
	
	
	public static function uploadFile(Zotero_StorageFileInfo $info, $file, $contentType) {
		if (!file_exists($file)) {
			throw new Exception("File '$file' does not exist");
		}
		
		$s3Client = Z_Core::$AWS->createS3();
		$s3Client->putObject([
			'SourceFile' => $file,
			'Bucket' => Z_CONFIG::$S3_BUCKET,
			'Key' => $info->hash,
			'ACL' => 'private'
		]);
		
		return self::addFile($info);
	}
	
	
	public static function queueUpload($userID, Zotero_StorageFileInfo $info) {
		$uploadKey = md5(uniqid(rand(), true));
		
		$sql = "SELECT COUNT(*) FROM storageUploadQueue WHERE userID=?
				AND time > (NOW() - INTERVAL " . self::$uploadQueueTimeout . " SECOND)";
		$num = Zotero_DB::valueQuery($sql, $userID);
		if ($num > self::$uploadQueueLimit) {
			Z_Core::logError("Too many queued uploads ($num) for user $userID");
			return false;
		}
		
		$sql = "INSERT INTO storageUploadQueue "
			. "(uploadKey, userID, hash, filename, zip, itemHash, itemFilename, "
			. "size, mtime, contentType, charset) "
			. "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
		Zotero_DB::query(
			$sql,
			array(
				$uploadKey,
				$userID,
				$info->hash,
				$info->filename,
				$info->zip ? 1 : 0,
				!empty($info->itemHash) ? $info->itemHash : "",
				!empty($info->itemFilename) ? $info->itemFilename : "",
				$info->size,
				$info->mtime,
				$info->contentType,
				$info->charset
			)
		);
		
		return $uploadKey;
	}
	
	
	public static function getUploadInfo($key) {
		$sql = "SELECT * FROM storageUploadQueue WHERE uploadKey=?";
		$row = Zotero_DB::rowQuery($sql, $key);
		if (!$row) {
			return false;
		}
		
		$info = new Zotero_StorageFileInfo;
		foreach ($row as $key => $val) {
			$info->$key = $val;
		}
		return $info;
	}
	
	
	public static function logUpload($uploadUserID, $item, $key, $ipAddress) {
		$libraryID = $item->libraryID;
		$ownerUserID = Zotero_Libraries::getOwner($libraryID);
		
		$info = self::getUploadInfo($key);
		if (!$info) {
			throw new Exception("Upload key '$key' not found in queue");
		}
		
		$info = self::getLocalFileItemInfo($item);
		$storageFileID = $info['storageFileID'];
		$filename = $info['filename'];
		$size = $info['size'];
		
		$sql = "DELETE FROM storageUploadQueue WHERE uploadKey=?";
		Zotero_DB::query($sql, $key);
		
		$sql = "INSERT INTO storageUploadLog
				(ownerUserID, uploadUserID, ipAddress, storageFileID, filename, size)
				VALUES (?, ?, INET_ATON(?), ?, ?, ?)";
		Zotero_DB::query($sql, array($ownerUserID, $uploadUserID, $ipAddress, $storageFileID, $filename, $size));
	}
	
	
	public static function getUploadBaseURL() {
		return "https://" . Z_CONFIG::$S3_BUCKET . ".s3.amazonaws.com/";
	}
	
	
	public static function patchFile($item, $info, $algorithm, $patch) {
		switch ($algorithm) {
			case 'bsdiff':
			case 'xdelta':
			case 'vcdiff':
				break;
				
			case 'xdiff':
				if (!function_exists('xdiff_file_patch_binary')) {
					throw new Exception("=xdiff not available");
				}
				break;
				
			default:
				throw new Exception("Invalid algorithm '$algorithm'", Z_ERROR_INVALID_INPUT);
		}
		
		$originalInfo = Zotero_Storage::getLocalFileItemInfo($item);
		
		$basePath = "/tmp/zfsupload/";
		$path = $basePath . $info->hash . "_" . uniqid() . "/";
		mkdir($path, 0777, true);
		
		$cleanup = function () use ($basePath, $path) {
			unlink("original");
			unlink("patch");
			unlink("new");
			chdir($basePath);
			rmdir($path);
		};
		
		$e = null;
		try {
			// Download file from S3 to temp directory
			if (!Zotero_Storage::downloadFile($originalInfo, $path, "original")) {
				throw new Exception("Error downloading original file");
			}
			chdir($path);
			
			// Save body to temp file
			file_put_contents("patch", $patch);
			
			// Patch file
			switch ($algorithm) {
				case 'bsdiff':
					exec('bspatch original new patch 2>&1', $output, $ret);
					if ($ret) {
						throw new Exception("Error applying patch ($ret): " . implode("\n", $output));
					}
					if (!file_exists("new")) {
						throw new Exception("Error applying patch ($ret)");
					}
					break;
				
				case 'xdelta':
				case 'vcdiff':
					exec('xdelta3 -d -s original patch new 2>&1', $output, $ret);
					if ($ret) {
						if ($ret == 2) {
							throw new Exception("Invalid delta", Z_ERROR_INVALID_INPUT);
						}
						throw new Exception("Error applying patch ($ret): " . implode("\n", $output));
					}
					if (!file_exists("new")) {
						throw new Exception("Error applying patch ($ret)");
					}
					break;
				
				case 'xdiff':
					$ret = xdiff_file_patch_binary("original", "patch", "new");
					if (!$ret) {
						throw new Exception("Error applying patch");
					}
					break;
			}
			
			// Check MD5 hash
			if (md5_file("new") != $info->hash) {
				$cleanup();
				throw new HTTPException("Patched file does not match hash", 409);
			}
			
			// Check file size
			if (filesize("new") != $info->size) {
				$cleanup();
				throw new HTTPException("Patched file size does not match "
						. "(" . filesize("new") . " != {$info->size})", 409);
			}
			
			// If ZIP, make sure it's a ZIP
			if ($info->zip && file_get_contents("new", false, null, 0, 4) != "PK" . chr(03) . chr(04)) {
				$cleanup();
				throw new HTTPException("Patched file is not a ZIP file", 409);
			}
			
			// Upload to S3
			$t = $info->contentType . (($info->contentType && $info->charset) ? "; charset={$info->charset}" : "");
			$storageFileID = Zotero_Storage::uploadFile($info, "new", $t);
		}
		catch (Exception $e) {
			//$cleanup();
			throw ($e);
		}
		
		return $storageFileID;
	}
	
	public static function duplicateFile($storageFileID, $newName, $zip, $contentType=null) {
		if (strlen($newName) == 0) {
			throw new Exception("New name not provided");
		}
		
		$localInfo = self::getFileInfoByID($storageFileID);
		if (!$localInfo) {
			throw new Exception("File $storageFileID not found");
		}
		
		$s3Client = Z_Core::$AWS->createS3();
		try {
			$s3Client->headObject([
				'Bucket' => Z_CONFIG::$S3_BUCKET,
				'Key' => $localInfo['hash']
			]);
		}
		// If file doesn't already exist named with just hash, copy it over
		catch (\Aws\S3\Exception\S3Exception $e) {
			if ($e->getAwsErrorCode() == 'NoSuchKey' || $e->getAwsErrorCode() == 'NotFound') {
				try {
					$s3Client->copyObject([
						'Bucket' => Z_CONFIG::$S3_BUCKET,
						'CopySource' => Z_CONFIG::$S3_BUCKET . '/'
							. urlencode(self::getPathPrefix($localInfo['hash'], $localInfo['zip'])
								. $localInfo['filename']),
						'Key' => $localInfo['hash'],
						'ACL' => 'private'
					]);
				}
				catch (\Aws\S3\Exception\S3Exception $e) {
					if ($e->getAwsErrorCode() == 'NoSuchKey') {
						return false;
					}
					else {
						throw $e;
					}
				}
			}
			else {
				throw $e;
			}
		}
		
		$info = new Zotero_StorageFileInfo;
		foreach ($localInfo as $key => $val) {
			$info->$key = $val;
		}
		$info->filename = $newName;
		return self::addFile($info);
	}
	
	
	public static function getFileByHash($hash, $zip) {
		$sql = "SELECT storageFileID FROM storageFiles WHERE hash=? AND zip=? LIMIT 1";
		return Zotero_DB::valueQuery($sql, array($hash, (int) $zip));
	}
	
	public static function getFileInfoByID($storageFileID) {
		$sql = "SELECT * FROM storageFiles WHERE storageFileID=?";
		return Zotero_DB::rowQuery($sql, $storageFileID);
	}
	
	public static function getLocalFileInfo(Zotero_StorageFileInfo $info) {
		$sql = "SELECT * FROM storageFiles WHERE hash=? AND filename=? AND zip=?";
		return Zotero_DB::rowQuery($sql, array($info->hash, $info->filename, (int) $info->zip));
	}
	
	public static function getRemoteFileInfo(Zotero_StorageFileInfo $info) {
		$s3Client = Z_Core::$AWS->createS3();
		try {
			$result = $s3Client->headObject([
				'Bucket' => Z_CONFIG::$S3_BUCKET,
				'Key' => $info->hash
			]);
		}
		catch (\Aws\S3\Exception\S3Exception $e) {
			if ($e->getAwsErrorCode() == 'NoSuchKey' || $e->getAwsErrorCode() == 'NotFound') {
				// Try legacy key format, with zip flag and filename
				try {
					$result = $s3Client->headObject([
						'Bucket' => Z_CONFIG::$S3_BUCKET,
						'Key' => self::getPathPrefix($info->hash, $info->zip) . $info->filename
					]);
				}
				catch (\Aws\S3\Exception\S3Exception $e) {
					if ($e->getAwsErrorCode() == 'NoSuchKey' || $e->getAwsErrorCode() == 'NotFound') {
						return false;
					}
					throw $e;
				}
			}
			else {
				throw $e;
			}
		}
		
		$storageFileInfo = new Zotero_StorageFileInfo;
		$storageFileInfo->size = $result['ContentLength'];
		
		return $storageFileInfo;
	}
	
	
	/**
	 * Get item-specific file info
	 */
	public static function getLocalFileItemInfo($item) {
		$sql = "SELECT * FROM storageFileItems WHERE itemID=?";
		$info = Zotero_DB::rowQuery($sql, $item->id, Zotero_Shards::getByLibraryID($item->libraryID));
		if (!$info) {
			return false;
		}
		$moreInfo = self::getFileInfoByID($info['storageFileID']);
		if (!$moreInfo) {
			error_log("WARNING: storageFileID {$info['storageFileID']} not found in storageFiles "
				. "for item $item->libraryKey");
			return false;
		}
		return array_merge($moreInfo, $info);
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
	
	
	public static function addFile(Zotero_StorageFileInfo $info) {
		$sql = "INSERT INTO storageFiles (hash, filename, size, zip) VALUES (?,?,?,?)";
		return Zotero_DB::query($sql, array($info->hash, $info->filename, $info->size, (int) $info->zip));
	}
	
	
	public static function updateFileItemInfo($item, $storageFileID, Zotero_StorageFileInfo $info, $client=false) {
		if (!$item->isImportedAttachment()) {
			throw new Exception("Cannot add storage file for linked file/URL");
		}
		
		Zotero_DB::beginTransaction();
		
		if (!$client) {
			Zotero_Libraries::updateVersionAndTimestamp($item->libraryID);
		}
		
		self::updateLastAdded($storageFileID);
		
		// Note: We set the size on the shard so that usage queries are instantaneous
		$sql = "INSERT INTO storageFileItems (storageFileID, itemID, mtime, size) VALUES (?,?,?,?)
				ON DUPLICATE KEY UPDATE storageFileID=?, mtime=?, size=?";
		Zotero_DB::query(
			$sql,
			array($storageFileID, $item->id, $info->mtime, $info->size, $storageFileID, $info->mtime, $info->size),
			Zotero_Shards::getByLibraryID($item->libraryID)
		);
		
		// 4.0 client doesn't set filename for ZIP files
		if (!$info->zip || !empty($info->itemFilename)) {
			$item->attachmentFilename = !empty($info->itemFilename) ? $info->itemFilename : $info->filename;
		}
		$item->attachmentStorageHash = !empty($info->itemHash) ? $info->itemHash : $info->hash;
		$item->attachmentStorageModTime = $info->mtime;
		// contentType and charset may not have been included in the
		// upload authorization, in which case we shouldn't overwrite
		// any values that may already be set on the attachment
		if (isset($info->contentType)) {
			$item->attachmentMIMEType = $info->contentType;
		}
		if (isset($info->charset)) {
			$item->attachmentCharset = $info->charset;
		}
		$item->save();
		
		Zotero_DB::commit();
	}
	
	
	public static function getPathPrefix($hash, $zip=false) {
		return "$hash/" . ($zip ? "c/" : '');
	}
	
	
	public static function getUploadPOSTData($item, Zotero_StorageFileInfo $info) {
		$params = self::generateUploadPOSTParams($item, $info);
		$boundary = "---------------------------" . md5(uniqid());
		
		// Prefix
		$prefix = "";
		foreach ($params as $key => $val) {
			$prefix .= "--$boundary\r\n"
				. "Content-Disposition: form-data; name=\"$key\"\r\n\r\n"
				. $val . "\r\n";
		}
		$prefix .= "--$boundary\r\nContent-Disposition: form-data; name=\"file\"\r\n\r\n";
		
		// Suffix
		$suffix = "\r\n--$boundary--";
		
		return array(
			'url' => self::getUploadBaseURL(),
			'contentType' => "multipart/form-data; boundary=$boundary",
			'prefix' => $prefix,
			'suffix' => $suffix
		);
	}
	
	
	public static function generateUploadPOSTParams($item, Zotero_StorageFileInfo $info, $useItemContentType=false) {
		if (strlen($info->hash) != 32) {
			throw new Exception("Invalid MD5 hash '{$info->hash}'");
		}
		
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
		$successStatus = 201;
		
		$contentMD5 = '';
		for ($i = 0; $i < strlen($info->hash); $i += 2) {
			$contentMD5 .= chr(hexdec(substr($info->hash, $i, 2)));
		}
		$contentMD5 = base64_encode($contentMD5);
		
		// SDKv3 doesn't support Sig4 signing in PostObject yet
		// https://github.com/aws/aws-sdk-php/issues/586
		/*
		$formInputs = [];
		$s3Client = Z_Core::$AWS->createS3();
		$credentials = $s3Client->getCredentials()->wait();
		$accessKey = $credentials->getAccessKeyId();
		$securityToken = $credentials->getSecurityToken();
		error_log("ACCESS KEY IS $accessKey");
		error_log("ACCESS KEY IS $securityToken");
		$region = $s3Client->getRegion();
		$date = gmdate('Ymd\THis\Z');
		$shortDate = gmdate('Ymd');
		$policy = [
			'expiration' => gmdate('c', time() + 3600),
			'conditions' => [
				'acl' => 'private',
				'bucket' => Z_CONFIG::$S3_BUCKET,
				'success_action_status' => $successStatus,
				'key' => $info->hash,
				'Content-MD5' => $contentMD5,
				'x-amz-algorithm' => 'AWS4-HMAC-SHA256',
				'x-amz-date' => $date,
				'x-amz-credential' => "$accessKey/$shortDate/$region/s3/aws4_request",
				'x-amz-security-token' => $securityToken
			]
			
		];
        $postObject = new Aws\S3\PostObject(
			$s3Client,
			Z_CONFIG::$S3_BUCKET,
			$formInputs,
			$policy
		);
		return $postObject->getFormInputs();
		*/
		
		$s3Client = Z_Core::$AWS->createS3();
		$credentials = $s3Client->getCredentials()->wait();
		$accessKey = $credentials->getAccessKeyId();
		$secretKey = $credentials->getSecretKey();
		$securityToken = $credentials->getSecurityToken();
		$region = $s3Client->getRegion();
		$algorithm = "AWS4-HMAC-SHA256";
		$service = "s3";
		$date = gmdate('Ymd\THis\Z');
		$shortDate = gmdate('Ymd');
		$requestType = "aws4_request";
		$successStatus = '201';
		
		$scope = [
			$accessKey,
			$shortDate,
			$region,
			$service,
			$requestType
		];
		$credentials = implode('/', $scope);
		
		$policy = [
			'expiration' => gmdate(
				'Y-m-d\TG:i:s\Z', strtotime('+' . self::$s3PresignedRequestTTL . ' seconds')
			),
			'conditions' => [
				['bucket' => Z_CONFIG::$S3_BUCKET],
				['key' => $info->hash],
				['acl' => 'private'],
				['Content-MD5' => $contentMD5],
				['success_action_status' => $successStatus],
				['x-amz-credential' => $credentials],
				['x-amz-algorithm' => $algorithm],
				['x-amz-date' => $date],
				['x-amz-security-token' => $securityToken]
			]
        ];
        $base64Policy = base64_encode(json_encode($policy));
        
        // Signing Keys
        $dateKey = hash_hmac('sha256', $shortDate, 'AWS4' . $secretKey, true);
        $dateRegionKey = hash_hmac('sha256', $region, $dateKey, true);
        $dateRegionServiceKey = hash_hmac('sha256', $service, $dateRegionKey, true);
        $signingKey = hash_hmac('sha256', $requestType, $dateRegionServiceKey, true);
        
        // Signature
        $signature = hash_hmac('sha256', $base64Policy, $signingKey);
        
        return [
			"key" => $info->hash,
			"acl"  => 'private',
			'Content-MD5' => $contentMD5,
			"success_action_status"  => $successStatus,
			"policy"  => $base64Policy,
			"x-amz-algorithm"  => $algorithm,
			"x-amz-credential"  => $credentials,
			"x-amz-date"  => $date,
			"x-amz-signature" => $signature,
			// Necessary for IAM/STS
			"x-amz-security-token" => $securityToken
        ];
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
		
		// If changing quota, make sure it's not less than current usage
		$current = self::getUserValues($userID);
		$usage = self::getUserUsage($userID);
		if ($current['quota'] != $quota && $quota < $usage['total']) {
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
		$dev = Z_ENV_TESTING_SITE ? "_test" : "";
		$databaseName = "zotero_www{$dev}";
		
		// Get maximum institutional quota by e-mail domain
		$sql = "SELECT IFNULL(MAX(storageQuota), 0) FROM $databaseName.users_email
				JOIN $databaseName.storage_institutions
				ON (SUBSTR(email, LENGTH(domain) * -1)=domain AND domain!='')
				WHERE userID=?";
		try {
			$institutionalDomainQuota = Zotero_WWW_DB_2::valueQuery($sql, $userID);
		}
		catch (Exception $e) {
			Z_Core::logError("WARNING: $e -- retrying on primary");
			$institutionalDomainQuota = Zotero_WWW_DB_1::valueQuery($sql, $userID);
		}
		
		// Get maximum institutional quota by e-mail address
		$sql = "SELECT IFNULL(MAX(storageQuota), 0) FROM $databaseName.users_email
				JOIN $databaseName.storage_institution_email USING (email)
				JOIN $databaseName.storage_institutions USING (institutionID)
				WHERE userID=?";
		try {
			$institutionalEmailQuota = Zotero_WWW_DB_2::valueQuery($sql, $userID);
		}
		catch (Exception $e) {
			Z_Core::logError("WARNING: $e -- retrying on primary");
			$institutionalEmailQuota = Zotero_WWW_DB_1::valueQuery($sql, $userID);
		}
		
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
		if ($personalQuota && $personalQuota['expiration'] < time()) {
			$personalQuota = false;
		}
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
		
		$libraryID = Zotero_Users::getLibraryIDFromUserID($userID);
		
		$sql = "SELECT SUM(size) AS bytes FROM storageFileItems
				JOIN items USING (itemID) WHERE libraryID=?";
		$libraryBytes = Zotero_DB::valueQuery($sql, $libraryID, Zotero_Shards::getByLibraryID($libraryID));
		$usage['library'] = round($libraryBytes / 1024 / 1024, 1);
		
		$groupBytes = 0;
		$usage['groups'] = array();
		
		$ownedLibraries = Zotero_Groups::getUserOwnedGroupLibraries($userID);
		if ($ownedLibraries) {
			$shardIDs = Zotero_Groups::getUserGroupShards($userID);
			
			foreach ($shardIDs as $shardID) {
				$sql = "SELECT libraryID, SUM(size) AS `bytes` FROM storageFileItems
						JOIN items I USING (itemID)
						WHERE libraryID IN
						(" . implode(', ', array_fill(0, sizeOf($ownedLibraries), '?')) . ")
						GROUP BY libraryID WITH ROLLUP";
				$libraries = Zotero_DB::query($sql, $ownedLibraries, $shardID);
				if ($libraries) {
					foreach ($libraries as $library) {
						if ($library['libraryID']) {
							$usage['groups'][] = array(
								'id' => Zotero_Groups::getGroupIDFromLibraryID($library['libraryID']),
								'usage' => round($library['bytes'] / 1024 / 1024, 1)
							);
						}
						// ROLLUP row
						else {
							$groupBytes += $library['bytes'];
						}
					}
				}
			}
		}
		
		$usage['total'] = round(($libraryBytes + $groupBytes) / 1024 / 1024, 1);
		return $usage;
	}
	
	
	private static function updateLastAdded($storageFileID) {
		$sql = "UPDATE storageFiles SET lastAdded=NOW() WHERE storageFileID=?";
		Zotero_DB::query($sql, $storageFileID);
	}
	
	
	private static function getHash($stringToSign) {
		return base64_encode(hash_hmac('sha1', $stringToSign, Z_CONFIG::$AWS_SECRET_KEY, true));
	}
}
?>
