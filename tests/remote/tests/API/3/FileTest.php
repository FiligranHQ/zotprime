<?
/*
    ***** BEGIN LICENSE BLOCK *****
    
    This file is part of the Zotero Data Server.
    
    Copyright © 2012 Center for History and New Media
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

namespace APIv3;
use API3 as API, HTTP, SimpleXMLElement, Sync, Z_Tests;
require_once 'APITests.inc.php';
require_once 'include/bootstrap.inc.php';

class FileTests extends APITests {
	private static $toDelete = array();
	
	public static function setUpBeforeClass() {
		parent::setUpBeforeClass();
		API::userClear(self::$config['userID']);
	}
	
	public function setUp() {
		parent::setUp();
		
		// Delete work files
		$delete = array("file", "old", "new", "patch");
		foreach ($delete as $file) {
			if (file_exists("work/$file")) {
				unlink("work/$file");
			}
		}
		clearstatcache();
	}
	
	public static function tearDownAfterClass() {
		parent::tearDownAfterClass();
		
		$s3Client = Z_Tests::$AWS->createS3();
		
		foreach (self::$toDelete as $file) {
			try {
				$s3Client->deleteObject([
					'Bucket' => self::$config['s3Bucket'],
					'Key' => $file
				]);
			}
			catch (\Aws\S3\Exception\S3Exception $e) {
				if ($e->getAwsErrorCode() == 'NoSuchKey') {
					echo "\n$file not found on S3 to delete\n";
				}
				else {
					throw $e;
				}
			}
		}
	}
	
	
	public function testNewEmptyImportedFileAttachmentItem() {
		return API::createAttachmentItem("imported_file", [], false, $this, 'key');
	}
	
	
	/**
	 * Test errors getting file upload authorization via form data
	 *
	 * @depends testNewEmptyImportedFileAttachmentItem
	 */
	public function testAddFileFormDataAuthorizationErrors($parentKey) {
		$fileContents = self::getRandomUnicodeString();
		$hash = md5($fileContents);
		$mtime = time() * 1000;
		$size = strlen($fileContents);
		$filename = "test_" . $fileContents;
		
		$fileParams = array(
			"md5" => $hash,
			"filename" => $filename,
			"filesize" => $size,
			"mtime" => $mtime,
			"contentType" => "text/plain",
			"charset" => "utf-8"
		);
		
		// Check required params
		foreach (array("md5", "filename", "filesize", "mtime") as $exclude) {
			$response = API::userPost(
				self::$config['userID'],
				"items/$parentKey/file",
				$this->implodeParams($fileParams, array($exclude)),
				array(
					"Content-Type: application/x-www-form-urlencoded",
					"If-None-Match: *"
				)
			);
			$this->assert400($response);
		}
		
		// Seconds-based mtime
		$fileParams2 = $fileParams;
		$fileParams2['mtime'] = round($mtime / 1000);
		$response = API::userPost(
			self::$config['userID'],
			"items/$parentKey/file",
			$this->implodeParams($fileParams2),
			array(
				"Content-Type: application/x-www-form-urlencoded",
				"If-None-Match: *"
			)
		);
		// TODO: Enable this test when the dataserver enforces it
		//$this->assert400($response);
		//$this->assertEquals('mtime must be specified in milliseconds', $response->getBody());
		
		$fileParams = $this->implodeParams($fileParams);
		
		// Invalid If-Match
		$response = API::userPost(
			self::$config['userID'],
			"items/$parentKey/file",
			$fileParams,
			array(
				"Content-Type: application/x-www-form-urlencoded",
				"If-Match: " . md5("invalidETag")
			)
		);
		$this->assert412($response);
		
		// Missing If-None-Match
		$response = API::userPost(
			self::$config['userID'],
			"items/$parentKey/file",
			$fileParams,
			array(
				"Content-Type: application/x-www-form-urlencoded"
			)
		);
		$this->assert428($response);
		
		// Invalid If-None-Match
		$response = API::userPost(
			self::$config['userID'],
			"items/$parentKey/file",
			$fileParams,
			array(
				"Content-Type: application/x-www-form-urlencoded",
				"If-None-Match: invalidETag"
			)
		);
		$this->assert400($response);
	}
	
	
	public function testAddFileFormDataFull() {
		$parentKey = API::createItem("book", false, $this, 'key');
		
		$json = API::createAttachmentItem("imported_file", [], $parentKey, $this, 'json');
		$attachmentKey = $json['key'];
		$originalVersion = $json['version'];
		
		$file = "work/file";
		$fileContents = self::getRandomUnicodeString();
		file_put_contents($file, $fileContents);
		$hash = md5_file($file);
		$filename = "test_" . $fileContents;
		$mtime = filemtime($file) * 1000;
		$size = filesize($file);
		$contentType = "text/plain";
		$charset = "utf-8";
		
		// Get upload authorization
		$response = API::userPost(
			self::$config['userID'],
			"items/$attachmentKey/file",
			$this->implodeParams(array(
				"md5" => $hash,
				"filename" => $filename,
				"filesize" => $size,
				"mtime" => $mtime,
				"contentType" => $contentType,
				"charset" => $charset
			)),
			array(
				"Content-Type: application/x-www-form-urlencoded",
				"If-None-Match: *"
			)
		);
		$this->assert200($response);
		$this->assertContentType("application/json", $response);
		$json = json_decode($response->getBody());
		$this->assertNotNull($json);
		
		self::$toDelete[] = "$hash";
		
		// Upload wrong contents to S3
		$response = HTTP::post(
			$json->url,
			$json->prefix . $fileContents . "INVALID" . $json->suffix,
			[
				"Content-Type: " . $json->contentType
			]
		);
		$this->assert400($response);
		$this->assertContains(
			"The Content-MD5 you specified did not match what we received.", $response->getBody()
		);
		
		// Upload to S3
		$response = HTTP::post(
			$json->url,
			$json->prefix . $fileContents . $json->suffix,
			[
				"Content-Type: " . $json->contentType
			]
		);
		$this->assert201($response);
		
		//
		// Register upload
		//
		
		// No If-None-Match
		$response = API::userPost(
			self::$config['userID'],
			"items/$attachmentKey/file",
			"upload=" . $json->uploadKey,
			array(
				"Content-Type: application/x-www-form-urlencoded"
			)
		);
		$this->assert428($response);
		
		// Invalid upload key
		$response = API::userPost(
			self::$config['userID'],
			"items/$attachmentKey/file",
			"upload=invalidUploadKey",
			array(
				"Content-Type: application/x-www-form-urlencoded",
				"If-None-Match: *"
			)
		);
		$this->assert400($response);
		
		$response = API::userPost(
			self::$config['userID'],
			"items/$attachmentKey/file",
			"upload=" . $json->uploadKey,
			array(
				"Content-Type: application/x-www-form-urlencoded",
				"If-None-Match: *"
			)
		);
		$this->assert204($response);
		
		// Verify attachment item metadata
		$response = API::userGet(
			self::$config['userID'],
			"items/$attachmentKey"
		);
		$json = API::getJSONFromResponse($response)['data'];
		
		$this->assertEquals($hash, $json['md5']);
		$this->assertEquals($filename, $json['filename']);
		$this->assertEquals($mtime, $json['mtime']);
		$this->assertEquals($contentType, $json['contentType']);
		$this->assertEquals($charset, $json['charset']);
		
		return array(
			"key" => $attachmentKey,
			"json" => $json,
			"size" => $size
		);
	}
	
	
	public function testAddFileFormDataFullParams() {
		$json = API::createAttachmentItem("imported_file", [], false, $this, 'jsonData');
		$attachmentKey = $json['key'];
		
		// Get serverDateModified
		$serverDateModified = $json['dateAdded'];
		sleep(1);
		
		$originalVersion = $json['version'];
		
		// Get a sync timestamp from before the file is updated
		require_once 'include/sync.inc.php';
		$sessionID = Sync::login();
		$xml = Sync::updated($sessionID);
		$lastsync = (int) $xml['timestamp'];
		Sync::logout($sessionID);
		
		$file = "work/file";
		$fileContents = self::getRandomUnicodeString();
		file_put_contents($file, $fileContents);
		$hash = md5_file($file);
		$filename = "test_" . $fileContents;
		$mtime = filemtime($file) * 1000;
		$size = filesize($file);
		$contentType = "text/plain";
		$charset = "utf-8";
		
		// Get upload authorization
		$response = API::userPost(
			self::$config['userID'],
			"items/$attachmentKey/file",
			$this->implodeParams(array(
				"md5" => $hash,
				"filename" => $filename,
				"filesize" => $size,
				"mtime" => $mtime,
				"contentType" => $contentType,
				"charset" => $charset,
				"params" => 1
			)),
			array(
				"Content-Type: application/x-www-form-urlencoded",
				"If-None-Match: *"
			)
		);
		$this->assert200($response);
		$this->assertContentType("application/json", $response);
		$json = json_decode($response->getBody());
		$this->assertNotNull($json);
		
		self::$toDelete[] = "$hash";
		
		// Generate form-data -- taken from S3::getUploadPostData()
		$boundary = "---------------------------" . md5(uniqid());
		$prefix = "";
		foreach ($json->params as $key => $val) {
			$prefix .= "--$boundary\r\n"
				. "Content-Disposition: form-data; name=\"$key\"\r\n\r\n"
				. $val . "\r\n";
		}
		$prefix .= "--$boundary\r\nContent-Disposition: form-data; name=\"file\"\r\n\r\n";
		$suffix = "\r\n--$boundary--";
		
		// Upload to S3
		$response = HTTP::post(
			$json->url,
			$prefix . $fileContents . $suffix,
			array(
				"Content-Type: multipart/form-data; boundary=$boundary"
			)
		);
		$this->assert201($response);
		
		//
		// Register upload
		//
		$response = API::userPost(
			self::$config['userID'],
			"items/$attachmentKey/file",
			"upload=" . $json->uploadKey,
			array(
				"Content-Type: application/x-www-form-urlencoded",
				"If-None-Match: *"
			)
		);
		$this->assert204($response);
		
		// Verify attachment item metadata
		$response = API::userGet(
			self::$config['userID'],
			"items/$attachmentKey"
		);
		$json = API::getJSONFromResponse($response)['data'];
		
		$this->assertEquals($hash, $json['md5']);
		$this->assertEquals($filename, $json['filename']);
		$this->assertEquals($mtime, $json['mtime']);
		$this->assertEquals($contentType, $json['contentType']);
		$this->assertEquals($charset, $json['charset']);
		
		// Make sure version has changed
		$this->assertNotEquals($originalVersion, $json['version']);
		
		// Make sure new attachment is passed via sync
		$sessionID = Sync::login();
		$xml = Sync::updated($sessionID, $lastsync);
		Sync::logout($sessionID);
		$this->assertGreaterThan(0, $xml->updated[0]->count());
	}
	
	
	/**
	 * @depends testAddFileFormDataFull
	 */
	public function testAddFileExisting($addFileData) {
		$key = $addFileData['key'];
		$json = $addFileData['json'];
		$md5 = $json['md5'];
		$size = $addFileData['size'];
		
		// Get upload authorization
		$response = API::userPost(
			self::$config['userID'],
			"items/$key/file",
			$this->implodeParams(array(
				"md5" => $json['md5'],
				"filename" => $json['filename'],
				"filesize" => $size,
				"mtime" => $json['mtime'],
				"contentType" => $json['contentType'],
				"charset" => $json['charset']
			)),
			array(
				"Content-Type: application/x-www-form-urlencoded",
				"If-Match: " . $json['md5']
			)
		);
		$this->assert200($response);
		$postJSON = json_decode($response->getBody());
		$this->assertNotNull($postJSON);
		$this->assertEquals(1, $postJSON->exists);
		
		// Get upload authorization for existing file with different filename
		$response = API::userPost(
			self::$config['userID'],
			"items/$key/file",
			$this->implodeParams(array(
				"md5" => $json['md5'],
				"filename" => $json['filename'] . '等', // Unicode 1.1 character, to test signature generation
				"filesize" => $size,
				"mtime" => $json['mtime'],
				"contentType" => $json['contentType'],
				"charset" => $json['charset']
			)),
			array(
				"Content-Type: application/x-www-form-urlencoded",
				"If-Match: " . $json['md5']
			)
		);
		$this->assert200($response);
		$postJSON = json_decode($response->getBody());
		$this->assertNotNull($postJSON);
		$this->assertEquals(1, $postJSON->exists);
		
		return array(
			"key" => $key,
			"md5" => $md5,
			"filename" => $json['filename'] . '等'
		);
	}
	
	
	/**
	 * @depends testAddFileExisting
	 */
	public function testGetFile($addFileData) {
		$key = $addFileData['key'];
		$md5 = $addFileData['md5'];
		$filename = $addFileData['filename'];
		
		// Get in view mode
		$response = API::userGet(
			self::$config['userID'],
			"items/$key/file/view"
		);
		$this->assert302($response);
		$location = $response->getHeader("Location");
		$this->assertRegExp('/^https:\/\/[^\/]+\/[0-9]+\//', $location);
		$filenameEncoded = rawurlencode($filename);
		$this->assertEquals($filenameEncoded, substr($location, -1 * strlen($filenameEncoded)));
		
		// Get from view mode
		$response = HTTP::get($location);
		$this->assert200($response);
		$this->assertEquals($md5, md5($response->getBody()));
		
		// Get in download mode
		$response = API::userGet(
			self::$config['userID'],
			"items/$key/file"
		);
		$this->assert302($response);
		$location = $response->getHeader("Location");
		
		// Get from S3
		$response = HTTP::get($location);
		$this->assert200($response);
		$this->assertEquals($md5, md5($response->getBody()));
		
		return array(
			"key" => $key, 
			"response" => $response
		);
	}
	
	
	/**
	 * @depends testGetFile
	 */
	public function testAddFilePartial($getFileData) {
		// Get serverDateModified
		$response = API::userGet(
			self::$config['userID'],
			"items/{$getFileData['key']}"
		);
		$json = API::getJSONFromResponse($response)['data'];
		$serverDateModified = $json['dateModified'];
		sleep(1);
		
		$originalVersion = $json['version'];
		
		// Get a sync timestamp from before the file is updated
		require_once 'include/sync.inc.php';
		$sessionID = Sync::login();
		$xml = Sync::updated($sessionID);
		$lastsync = (int) $xml['timestamp'];
		Sync::logout($sessionID);
		
		$oldFilename = "work/old";
		$fileContents = $getFileData['response']->getBody();
		file_put_contents($oldFilename, $fileContents);
		
		$newFilename = "work/new";
		$patchFilename = "work/patch";
		
		$algorithms = array(
			"bsdiff" => "bsdiff "
				. escapeshellarg($oldFilename) . " "
				. escapeshellarg($newFilename) . " "
				. escapeshellarg($patchFilename),
			"xdelta" => "xdelta3 -f -e -9 -S djw -s "
				. escapeshellarg($oldFilename) . " "
				. escapeshellarg($newFilename) . " "
				. escapeshellarg($patchFilename),
			"vcdiff" => "vcdiff encode "
				. "-dictionary " . escapeshellarg($oldFilename) . " "
				. " -target " . escapeshellarg($newFilename) . " "
				. " -delta " . escapeshellarg($patchFilename)
		);
		
		foreach ($algorithms as $algo => $cmd) {
			clearstatcache();
			
			// Create random contents
			file_put_contents($newFilename, uniqid(self::getRandomUnicodeString(), true));
			$newHash = md5_file($newFilename);
			
			// Get upload authorization
			$fileParams = array(
				"md5" => $newHash,
				"filename" => "test_" . $fileContents,
				"filesize" => filesize($newFilename),
				"mtime" => filemtime($newFilename) * 1000,
				"contentType" => "text/plain",
				"charset" => "utf-8"
			);
			$response = API::userPost(
				self::$config['userID'],
				"items/{$getFileData['key']}/file",
				$this->implodeParams($fileParams),
				array(
					"Content-Type: application/x-www-form-urlencoded",
					"If-Match: " . md5_file($oldFilename)
				)
			);
			$this->assert200($response);
			$json = json_decode($response->getBody());
			$this->assertNotNull($json);
			
			exec($cmd, $output, $ret);
			if ($ret != 0) {
				echo "Warning: Error running $algo -- skipping file upload test\n";
				continue;
			}
			
			$patch = file_get_contents($patchFilename);
			$this->assertNotEquals("", $patch);
			
			self::$toDelete[] = "$newHash";
			
			// Upload patch file
			$response = API::userPatch(
				self::$config['userID'],
				"items/{$getFileData['key']}/file?algorithm=$algo&upload=" . $json->uploadKey,
				$patch,
				array(
					"If-Match: " . md5_file($oldFilename)
				)
			);
			$this->assert204($response);
			
			unlink($patchFilename);
			rename($newFilename, $oldFilename);
			
			// Verify attachment item metadata
			$response = API::userGet(
				self::$config['userID'],
				"items/{$getFileData['key']}"
			);
			$json = API::getJSONFromResponse($response)['data'];
			$this->assertEquals($fileParams['md5'], $json['md5']);
			$this->assertEquals($fileParams['mtime'], $json['mtime']);
			$this->assertEquals($fileParams['contentType'], $json['contentType']);
			$this->assertEquals($fileParams['charset'], $json['charset']);
			
			// Make sure version has changed
			$this->assertNotEquals($originalVersion, $json['version']);
			
			// Make sure new attachment is passed via sync
			$sessionID = Sync::login();
			$xml = Sync::updated($sessionID, $lastsync);
			Sync::logout($sessionID);
			$this->assertGreaterThan(0, $xml->updated[0]->count());
			
			// Verify file on S3
			$response = API::userGet(
				self::$config['userID'],
				"items/{$getFileData['key']}/file"
			);
			$this->assert302($response);
			$location = $response->getHeader("Location");
			
			$response = HTTP::get($location);
			$this->assert200($response);
			$this->assertEquals($fileParams['md5'], md5($response->getBody()));
			$t = $fileParams['contentType'];
			$this->assertEquals(
				$t . (($t && $fileParams['charset']) ? "; charset={$fileParams['charset']}" : ""),
				$response->getHeader("Content-Type")
			);
		}
	}
	
	
	public function testExistingFileWithOldStyleFilename() {
		$fileContents = self::getRandomUnicodeString();
		$hash = md5($fileContents);
		$filename = 'test.txt';
		$size = strlen($fileContents);
		
		$parentKey = API::createItem("book", false, $this, 'key');
		$json = API::createAttachmentItem("imported_file", [], $parentKey, $this, 'jsonData');
		$key = $json['key'];
		$originalVersion = $json['version'];
		$mtime = time() * 1000;
		$contentType = 'text/plain';
		$charset = 'utf-8';
		
		// Get upload authorization
		$response = API::userPost(
			self::$config['userID'],
			"items/$key/file",
			$this->implodeParams(array(
				"md5" => $hash,
				"filename" => $filename,
				"filesize" => $size,
				"mtime" => $mtime,
				"contentType" => $contentType,
				"charset" => $charset
			)),
			array(
				"Content-Type: application/x-www-form-urlencoded",
				"If-None-Match: *"
			)
		);
		$this->assert200($response);
		$this->assertContentType("application/json", $response);
		$json = json_decode($response->getBody());
		$this->assertNotNull($json);
		
		// Upload to old-style location
		self::$toDelete[] = "$hash/$filename";
		self::$toDelete[] = "$hash";
		$s3Client = Z_Tests::$AWS->createS3();
		$s3Client->putObject([
			'Bucket' => self::$config['s3Bucket'],
			'Key' => $hash . '/' . $filename,
			'Body' => $fileContents
		]);
		
		// Register upload
		$response = API::userPost(
			self::$config['userID'],
			"items/$key/file",
			"upload=" . $json->uploadKey,
			array(
				"Content-Type: application/x-www-form-urlencoded",
				"If-None-Match: *"
			)
		);
		$this->assert204($response);
		
		// The file should be accessible on the item at the old-style location
		$response = API::userGet(
			self::$config['userID'],
			"items/$key/file"
		);
		$this->assert302($response);
		$location = $response->getHeader("Location");
		
		$this->assertEquals(1, preg_match('"^https://'
			// bucket.s3.amazonaws.com or s3.amazonaws.com/bucket
			. '(?:[^/]+|.+' . self::$config['s3Bucket'] . ')'
			. '/([a-f0-9]{32})/' . $filename . '\?"', $location, $matches));
		$this->assertEquals($hash, $matches[1]);
		
		// Get upload authorization for the same file and filename on another item, which should
		// result in 'exists', even though we uploaded to the old-style location
		$parentKey = API::createItem("book", false, $this, 'key');
		$json = API::createAttachmentItem("imported_file", [], $parentKey, $this, 'jsonData');
		$key = $json['key'];
		$response = API::userPost(
			self::$config['userID'],
			"items/$key/file",
			$this->implodeParams(array(
				"md5" => $hash,
				"filename" => $filename,
				"filesize" => $size,
				"mtime" => $mtime,
				"contentType" => $contentType,
				"charset" => $charset
			)),
			array(
				"Content-Type: application/x-www-form-urlencoded",
				"If-None-Match: *"
			)
		);
		$this->assert200($response);
		$postJSON = json_decode($response->getBody());
		$this->assertNotNull($postJSON);
		$this->assertEquals(1, $postJSON->exists);
		
		// Get in download mode
		$response = API::userGet(
			self::$config['userID'],
			"items/$key/file"
		);
		$this->assert302($response);
		$location = $response->getHeader("Location");
		$this->assertEquals(1, preg_match('"^https://'
			// bucket.s3.amazonaws.com or s3.amazonaws.com/bucket
			. '(?:[^/]+|.+' . self::$config['s3Bucket'] . ')'
			. '/([a-f0-9]{32})/' . $filename . '\?"', $location, $matches));
		$this->assertEquals($hash, $matches[1]);
		
		// Get from S3
		$response = HTTP::get($location);
		$this->assert200($response);
		$this->assertEquals($fileContents, $response->getBody());
		$this->assertEquals($contentType . '; charset=' . $charset, $response->getHeader('Content-Type'));
		
		// Get upload authorization for the same file and different filename on another item,
		// which should result in 'exists' and a copy of the file to the hash-only location
		$parentKey = API::createItem("book", false, $this, 'key');
		$json = API::createAttachmentItem("imported_file", [], $parentKey, $this, 'jsonData');
		$key = $json['key'];
		// Also use a different content type
		$contentType = 'application/x-custom';
		$response = API::userPost(
			self::$config['userID'],
			"items/$key/file",
			$this->implodeParams(array(
				"md5" => $hash,
				"filename" => "test2.txt",
				"filesize" => $size,
				"mtime" => $mtime,
				"contentType" => $contentType
			)),
			array(
				"Content-Type: application/x-www-form-urlencoded",
				"If-None-Match: *"
			)
		);
		$this->assert200($response);
		$postJSON = json_decode($response->getBody());
		$this->assertNotNull($postJSON);
		$this->assertEquals(1, $postJSON->exists);
		
		// Get in download mode
		$response = API::userGet(
			self::$config['userID'],
			"items/$key/file"
		);
		$this->assert302($response);
		$location = $response->getHeader("Location");
		$this->assertEquals(1, preg_match('"^https://'
			// bucket.s3.amazonaws.com or s3.amazonaws.com/bucket
			. '(?:[^/]+|.+' . self::$config['s3Bucket'] . ')'
			. '/([a-f0-9]{32})\?"', $location, $matches));
		$this->assertEquals($hash, $matches[1]);
		
		// Get from S3
		$response = HTTP::get($location);
		$this->assert200($response);
		$this->assertEquals($fileContents, $response->getBody());
		$this->assertEquals($contentType, $response->getHeader('Content-Type'));
	}
	
	
	public function testAddFileClientV4() {
		API::userClear(self::$config['userID']);
		
		$fileContentType = "text/html";
		$fileCharset = "utf-8";
		
		$auth = array(
			'username' => self::$config['username'],
			'password' => self::$config['password']
		);
		
		// Get last storage sync
		$response = API::userGet(
			self::$config['userID'],
			"laststoragesync?auth=1",
			array(),
			$auth
		);
		$this->assert404($response);
		
		$json = API::createAttachmentItem("imported_file", [], false, $this, 'jsonData');
		$originalVersion = $json['version'];
		$json['contentType'] = $fileContentType;
		$json['charset'] = $fileCharset;
		
		$response = API::userPut(
			self::$config['userID'],
			"items/{$json['key']}",
			json_encode($json),
			array("Content-Type: application/json")
		);
		$this->assert204($response);
		$originalVersion = $response->getHeader("Last-Modified-Version");
		
		// Get a sync timestamp from before the file is updated
		sleep(1);
		require_once 'include/sync.inc.php';
		$sessionID = Sync::login();
		$xml = Sync::updated($sessionID);
		$lastsync = (int) $xml['timestamp'];
		Sync::logout($sessionID);
		
		// Get file info
		$response = API::userGet(
			self::$config['userID'],
			"items/{$json['key']}/file?auth=1&iskey=1&version=1&info=1",
			array(),
			$auth
		);
		$this->assert404($response);
		
		$file = "work/file";
		$fileContents = self::getRandomUnicodeString();
		file_put_contents($file, $fileContents);
		$hash = md5_file($file);
		$filename = "test_" . $fileContents;
		$mtime = filemtime($file) * 1000;
		$size = filesize($file);
		
		// Get upload authorization
		$response = API::userPost(
			self::$config['userID'],
			"items/{$json['key']}/file?auth=1&iskey=1&version=1",
			$this->implodeParams(array(
				"md5" => $hash,
				"filename" => $filename,
				"filesize" => $size,
				"mtime" => $mtime
			)),
			array(
				"Content-Type: application/x-www-form-urlencoded"
			),
			$auth
		);
		$this->assert200($response);
		$this->assertContentType("application/xml", $response);
		$xml = new SimpleXMLElement($response->getBody());
		
		self::$toDelete[] = "$hash";
		
		$boundary = "---------------------------" . rand();
		$postData = "";
		foreach ($xml->params->children() as $key => $val) {
			$postData .= "--" . $boundary . "\r\nContent-Disposition: form-data; "
				. "name=\"$key\"\r\n\r\n$val\r\n";
		}
		$postData .= "--" . $boundary . "\r\nContent-Disposition: form-data; "
				. "name=\"file\"\r\n\r\n" . $fileContents . "\r\n";
		$postData .= "--" . $boundary . "--";
		
		// Upload to S3
		$response = HTTP::post(
			(string) $xml->url,
			$postData,
			array(
				"Content-Type: multipart/form-data; boundary=" . $boundary
			)
		);
		$this->assert201($response);
		
		//
		// Register upload
		//
		
		// Invalid upload key
		$response = API::userPost(
			self::$config['userID'],
			"items/{$json['key']}/file?auth=1&iskey=1&version=1",
			"update=invalidUploadKey&mtime=" . $mtime,
			array(
				"Content-Type: application/x-www-form-urlencoded"
			),
			$auth
		);
		$this->assert400($response);
		
		// No mtime
		$response = API::userPost(
			self::$config['userID'],
			"items/{$json['key']}/file?auth=1&iskey=1&version=1",
			"update=" . $xml->key,
			array(
				"Content-Type: application/x-www-form-urlencoded"
			),
			$auth
		);
		$this->assert500($response);
		
		$response = API::userPost(
			self::$config['userID'],
			"items/{$json['key']}/file?auth=1&iskey=1&version=1",
			"update=" . $xml->key . "&mtime=" . $mtime,
			array(
				"Content-Type: application/x-www-form-urlencoded"
			),
			$auth
		);
		$this->assert204($response);
		
		// Verify attachment item metadata
		$response = API::userGet(
			self::$config['userID'],
			"items/{$json['key']}"
		);
		$json = API::getJSONFromResponse($response)['data'];
		
		$this->assertEquals($hash, $json['md5']);
		$this->assertEquals($filename, $json['filename']);
		$this->assertEquals($mtime, $json['mtime']);
		
		// Make sure attachment item wasn't updated (or else the client
		// will get a conflict when it tries to update the metadata)
		$sessionID = Sync::login();
		$xml = Sync::updated($sessionID, $lastsync);
		Sync::logout($sessionID);
		$this->assertEquals(0, $xml->updated[0]->count());
		
		$response = API::userGet(
			self::$config['userID'],
			"laststoragesync?auth=1",
			array(),
			array(
				'username' => self::$config['username'],
				'password' => self::$config['password']
			)
		);
		$this->assert200($response);
		$mtime = $response->getBody();
		$this->assertRegExp('/^[0-9]{10}$/', $mtime);
		
		// File exists
		$response = API::userPost(
			self::$config['userID'],
			"items/{$json['key']}/file?auth=1&iskey=1&version=1",
			$this->implodeParams(array(
				"md5" => $hash,
				"filename" => $filename,
				"filesize" => $size,
				"mtime" => $mtime + 1000
			)),
			array(
				"Content-Type: application/x-www-form-urlencoded"
			),
			$auth
		);
		$this->assert200($response);
		$this->assertContentType("application/xml", $response);
		$this->assertEquals("<exists/>", $response->getBody());
		
		// File exists with different filename
		$response = API::userPost(
			self::$config['userID'],
			"items/{$json['key']}/file?auth=1&iskey=1&version=1",
			$this->implodeParams(array(
				"md5" => $hash,
				"filename" => $filename . '等', // Unicode 1.1 character, to test signature generation
				"filesize" => $size,
				"mtime" => $mtime + 1000
			)),
			array(
				"Content-Type: application/x-www-form-urlencoded"
			),
			$auth
		);
		$this->assert200($response);
		$this->assertContentType("application/xml", $response);
		$this->assertEquals("<exists/>", $response->getBody());
		
		// Make sure attachment item still wasn't updated
		$sessionID = Sync::login();
		$xml = Sync::updated($sessionID, $lastsync);
		$this->assertEquals(0, $xml->updated[0]->count());
		
		// Get attachment
		$xml = Sync::updated($sessionID, 2);
		$this->assertEquals(1, $xml->updated[0]->items->count());
		$itemXML = $xml->xpath("//updated/items/item[@key='" . $json['key'] . "']")[0];
		$this->assertEquals($fileContentType, (string) $itemXML['mimeType']);
		$this->assertEquals($fileCharset, (string) $itemXML['charset']);
		$this->assertEquals($hash, (string) $itemXML['storageHash']);
		$this->assertEquals($mtime + 1000, (string) $itemXML['storageModTime']);
		
		Sync::logout($sessionID);
	}
	
	
	public function testAddFileClientV4Zip() {
		API::userClear(self::$config['userID']);
		
		$auth = array(
			'username' => self::$config['username'],
			'password' => self::$config['password']
		);
		
		// Get last storage sync
		$response = API::userGet(
			self::$config['userID'],
			"laststoragesync?auth=1",
			array(),
			$auth
		);
		$this->assert404($response);
		
		$json = API::createItem("book", false, $this, 'jsonData');
		$key = $json['key'];
		
		$fileContentType = "text/html";
		$fileCharset = "UTF-8";
		$fileFilename = "file.html";
		$fileModtime = time();
		
		$json = API::createAttachmentItem("imported_url", [], $key, $this, 'jsonData');
		$key = $json['key'];
		$version = $json['version'];
		$json['contentType'] = $fileContentType;
		$json['charset'] = $fileCharset;
		$json['filename'] = $fileFilename;
		
		$response = API::userPut(
			self::$config['userID'],
			"items/$key",
			json_encode($json),
			array(
				"Content-Type: application/json"
			)
		);
		$this->assert204($response);
		
		// Get a sync timestamp from before the file is updated
		sleep(1);
		require_once 'include/sync.inc.php';
		$sessionID = Sync::login();
		$xml = Sync::updated($sessionID);
		$lastsync = (int) $xml['timestamp'];
		Sync::logout($sessionID);
		
		// Get file info
		$response = API::userGet(
			self::$config['userID'],
			"items/{$json['key']}/file?auth=1&iskey=1&version=1&info=1",
			array(),
			$auth
		);
		$this->assert404($response);
		
		$zip = new \ZipArchive();
		$file = "work/$key.zip";
		
		if ($zip->open($file, \ZIPARCHIVE::CREATE) !== TRUE) {
			throw new Exception("Cannot open ZIP file");
		}
		
		$zip->addFromString($fileFilename, self::getRandomUnicodeString());
		$zip->addFromString("file.css", self::getRandomUnicodeString());
		$zip->close();
		
		$hash = md5_file($file);
		$filename = $key . ".zip";
		$size = filesize($file);
		$fileContents = file_get_contents($file);
		
		// Get upload authorization
		$response = API::userPost(
			self::$config['userID'],
			"items/{$json['key']}/file?auth=1&iskey=1&version=1",
			$this->implodeParams(array(
				"md5" => $hash,
				"filename" => $filename,
				"filesize" => $size,
				"mtime" => $fileModtime,
				"zip" => 1
			)),
			array(
				"Content-Type: application/x-www-form-urlencoded"
			),
			$auth
		);
		$this->assert200($response);
		$this->assertContentType("application/xml", $response);
		$xml = new SimpleXMLElement($response->getBody());
		
		self::$toDelete[] = "$hash";
		
		$boundary = "---------------------------" . rand();
		$postData = "";
		foreach ($xml->params->children() as $key => $val) {
			$postData .= "--" . $boundary . "\r\nContent-Disposition: form-data; "
				. "name=\"$key\"\r\n\r\n$val\r\n";
		}
		$postData .= "--" . $boundary . "\r\nContent-Disposition: form-data; "
				. "name=\"file\"\r\n\r\n" . $fileContents . "\r\n";
		$postData .= "--" . $boundary . "--";
		
		// Upload to S3
		$response = HTTP::post(
			(string) $xml->url,
			$postData,
			array(
				"Content-Type: multipart/form-data; boundary=" . $boundary
			)
		);
		$this->assert201($response);
		
		//
		// Register upload
		//
		$response = API::userPost(
			self::$config['userID'],
			"items/{$json['key']}/file?auth=1&iskey=1&version=1",
			"update=" . $xml->key . "&mtime=" . $fileModtime,
			array(
				"Content-Type: application/x-www-form-urlencoded"
			),
			$auth
		);
		$this->assert204($response);
		
		// Verify attachment item metadata
		$response = API::userGet(
			self::$config['userID'],
			"items/{$json['key']}"
		);
		$json = API::getJSONFromResponse($response)['data'];
		
		$this->assertEquals($hash, $json['md5']);
		$this->assertEquals($fileFilename, $json['filename']);
		$this->assertEquals($fileModtime, $json['mtime']);
		
		// Make sure attachment item wasn't updated (or else the client
		// will get a conflict when it tries to update the metadata)
		$sessionID = Sync::login();
		$xml = Sync::updated($sessionID, $lastsync);
		Sync::logout($sessionID);
		$this->assertEquals(0, $xml->updated[0]->count());
		
		$response = API::userGet(
			self::$config['userID'],
			"laststoragesync?auth=1",
			array(),
			array(
				'username' => self::$config['username'],
				'password' => self::$config['password']
			)
		);
		$this->assert200($response);
		$mtime = $response->getBody();
		$this->assertRegExp('/^[0-9]{10}$/', $mtime);
		
		// File exists
		$response = API::userPost(
			self::$config['userID'],
			"items/{$json['key']}/file?auth=1&iskey=1&version=1",
			$this->implodeParams(array(
				"md5" => $hash,
				"filename" => $filename,
				"filesize" => $size,
				"mtime" => $fileModtime + 1000,
				"zip" => 1
			)),
			array(
				"Content-Type: application/x-www-form-urlencoded"
			),
			$auth
		);
		$this->assert200($response);
		$this->assertContentType("application/xml", $response);
		$this->assertEquals("<exists/>", $response->getBody());
		
		// Make sure attachment item still wasn't updated
		$sessionID = Sync::login();
		$xml = Sync::updated($sessionID, $lastsync);
		Sync::logout($sessionID);
		$this->assertEquals(0, $xml->updated[0]->count());
	}
	
	
	public function testAddFileClientV5() {
		API::userClear(self::$config['userID']);
		
		$file = "work/file";
		$fileContents = self::getRandomUnicodeString();
		$contentType = "text/html";
		$charset = "utf-8";
		file_put_contents($file, $fileContents);
		$hash = md5_file($file);
		$filename = "test_" . $fileContents;
		$mtime = filemtime($file) * 1000;
		$size = filesize($file);
		
		// Get last storage sync
		$response = API::userGet(
			self::$config['userID'],
			"laststoragesync"
		);
		$this->assert404($response);
		
		$json = API::createAttachmentItem("imported_file", [
			'contentType' => $contentType,
			'charset' => $charset
		], false, $this, 'jsonData');
		$key = $json['key'];
		$originalVersion = $json['version'];
		
		// Get a sync timestamp from before the file is updated
		sleep(1);
		require_once 'include/sync.inc.php';
		$sessionID = Sync::login();
		$xml = Sync::updated($sessionID);
		$lastsync = (int) $xml['timestamp'];
		Sync::logout($sessionID);
		
		// File shouldn't exist
		$response = API::userGet(
			self::$config['userID'],
			"items/$key/file"
		);
		$this->assert404($response);
		
		//
		// Get upload authorization
		//
		
		// Require If-Match/If-None-Match
		$response = API::userPost(
			self::$config['userID'],
			"items/$key/file",
			$this->implodeParams([
				"md5" => $hash,
				"mtime" => $mtime,
				"filename" => $filename,
				"filesize" => $size
			]),
			[
				"Content-Type: application/x-www-form-urlencoded"
			]
		);
		$this->assert428($response, "If-Match/If-None-Match header not provided");
		
		// Get authorization
		$response = API::userPost(
			self::$config['userID'],
			"items/$key/file",
			$this->implodeParams([
				"md5" => $hash,
				"mtime" => $mtime,
				"filename" => $filename,
				"filesize" => $size
			]),
			[
				"Content-Type: application/x-www-form-urlencoded",
				"If-None-Match: *"
			]
		);
		$this->assert200($response);
		$json = API::getJSONFromResponse($response);
		
		self::$toDelete[] = "$hash";
		
		//
		// Upload to S3
		//
		$response = HTTP::post(
			$json['url'],
			$json['prefix'] . $fileContents . $json['suffix'],
			[
				"Content-Type: {$json['contentType']}"
			]
		);
		$this->assert201($response);
		
		//
		// Register upload
		//
		
		// Require If-Match/If-None-Match
		$response = API::userPost(
			self::$config['userID'],
			"items/$key/file",
			"upload=" . $json['uploadKey'],
			[
				"Content-Type: application/x-www-form-urlencoded"
			]
		);
		$this->assert428($response, "If-Match/If-None-Match header not provided");
		
		// Invalid upload key
		$response = API::userPost(
			self::$config['userID'],
			"items/$key/file",
			"upload=invalidUploadKey",
			[
				"Content-Type: application/x-www-form-urlencoded",
				"If-None-Match: *"
			]
		);
		$this->assert400($response);
		
		// If-Match shouldn't match unregistered file
		$response = API::userPost(
			self::$config['userID'],
			"items/$key/file",
			"upload=" . $json['uploadKey'],
			[
				"Content-Type: application/x-www-form-urlencoded",
				"If-Match: $hash"
			]
		);
		$this->assert412($response);
		$this->assertNull($response->getHeader("Last-Modified-Version"));
		
		// Successful registration
		$response = API::userPost(
			self::$config['userID'],
			"items/$key/file",
			"upload=" . $json['uploadKey'],
			[
				"Content-Type: application/x-www-form-urlencoded",
				"If-None-Match: *"
			]
		);
		$this->assert204($response);
		$newVersion = $response->getHeader('Last-Modified-Version');
		$this->assertGreaterThan($originalVersion, $newVersion);
		
		// Verify attachment item metadata
		$response = API::userGet(
			self::$config['userID'],
			"items/$key"
		);
		$json = API::getJSONFromResponse($response)['data'];
		$this->assertEquals($hash, $json['md5']);
		$this->assertEquals($mtime, $json['mtime']);
		$this->assertEquals($filename, $json['filename']);
		$this->assertEquals($contentType, $json['contentType']);
		$this->assertEquals($charset, $json['charset']);
		
		$response = API::userGet(
			self::$config['userID'],
			"laststoragesync"
		);
		$this->assert200($response);
		$this->assertRegExp('/^[0-9]{10}$/', $response->getBody());
		
		//
		// Update file
		//
		
		// Conflict for If-None-Match when file exists
		$response = API::userPost(
			self::$config['userID'],
			"items/$key/file",
			$this->implodeParams([
				"md5" => $hash,
				"mtime" => $mtime + 1000,
				"filename" => $filename,
				"filesize" => $size
			]),
			[
				"Content-Type: application/x-www-form-urlencoded",
				"If-None-Match: *"
			]
		);
		$this->assert412($response, "If-None-Match: * set but file exists");
		$this->assertNotNull($response->getHeader("Last-Modified-Version"));
		
		// Conflict for If-Match when existing file differs
		$response = API::userPost(
			self::$config['userID'],
			"items/$key/file",
			$this->implodeParams([
				"md5" => $hash,
				"mtime" => $mtime + 1000,
				"filename" => $filename,
				"filesize" => $size
			]),
			[
				"Content-Type: application/x-www-form-urlencoded",
				"If-Match: " . md5("invalid")
			]
		);
		$this->assert412($response, "ETag does not match current version of file");
		$this->assertNotNull($response->getHeader("Last-Modified-Version"));
		
		// File exists
		$response = API::userPost(
			self::$config['userID'],
			"items/$key/file",
			$this->implodeParams([
				"md5" => $hash,
				"mtime" => $mtime + 1000,
				"filename" => $filename,
				"filesize" => $size
			]),
			[
				"Content-Type: application/x-www-form-urlencoded",
				"If-Match: $hash"
			]
		);
		$this->assert200($response);
		$json = API::getJSONFromResponse($response);
		$this->assertArrayHasKey("exists", $json);
		$version = $response->getHeader("Last-Modified-Version");
		$this->assertGreaterThan($newVersion, $version);
		$newVersion = $version;
		
		// File exists with different filename
		$response = API::userPost(
			self::$config['userID'],
			"items/$key/file",
			$this->implodeParams([
				"md5" => $hash,
				"mtime" => $mtime + 1000,
				"filename" => $filename . '等', // Unicode 1.1 character, to test signature generation
				"filesize" => $size,
				"contentType" => $contentType,
				"charset" => $charset
			]),
			[
				"Content-Type: application/x-www-form-urlencoded",
				"If-Match: $hash"
			]
		);
		$this->assert200($response);
		$json = API::getJSONFromResponse($response);
		$this->assertArrayHasKey("exists", $json);
		$version = $response->getHeader("Last-Modified-Version");
		$this->assertGreaterThan($newVersion, $version);
		
		// Get attachment via classic sync
		$sessionID = Sync::login();
		$xml = Sync::updated($sessionID, 2);
		$this->assertEquals(1, $xml->updated[0]->items->count());
		$itemXML = $xml->xpath("//updated/items/item[@key='$key']")[0];
		$this->assertEquals($contentType, (string) $itemXML['mimeType']);
		$this->assertEquals($charset, (string) $itemXML['charset']);
		$this->assertEquals($hash, (string) $itemXML['storageHash']);
		$this->assertEquals($mtime + 1000, (string) $itemXML['storageModTime']);
		Sync::logout($sessionID);
	}
	
	
	public function testAddFileClientV5Zip() {
		API::userClear(self::$config['userID']);
		
		$fileContents = self::getRandomUnicodeString();
		$contentType = "text/html";
		$charset = "utf-8";
		$filename = "file.html";
		$mtime = time();
		$hash = md5($fileContents);
		
		
		// Get last storage sync
		$response = API::userGet(
			self::$config['userID'],
			"laststoragesync"
		);
		$this->assert404($response);
		
		$json = API::createItem("book", false, $this, 'jsonData');
		$key = $json['key'];
		
		$json = API::createAttachmentItem("imported_url", [
			'contentType' => $contentType,
			'charset' => $charset
		], $key, $this, 'jsonData');
		$key = $json['key'];
		$originalVersion = $json['version'];
		
		// Create ZIP file
		$zip = new \ZipArchive();
		$file = "work/$key.zip";
		if ($zip->open($file, \ZIPARCHIVE::CREATE) !== TRUE) {
			throw new Exception("Cannot open ZIP file");
		}
		$zip->addFromString($filename, $fileContents);
		$zip->addFromString("file.css", self::getRandomUnicodeString());
		$zip->close();
		$zipHash = md5_file($file);
		$zipFilename = $key . ".zip";
		$zipSize = filesize($file);
		$zipFileContents = file_get_contents($file);
		
		// Get a sync timestamp from before the file is updated
		sleep(1);
		require_once 'include/sync.inc.php';
		$sessionID = Sync::login();
		$xml = Sync::updated($sessionID);
		$lastsync = (int) $xml['timestamp'];
		Sync::logout($sessionID);
		
		//
		// Get upload authorization
		//
		$response = API::userPost(
			self::$config['userID'],
			"items/$key/file",
			$this->implodeParams([
				"md5" => $hash,
				"mtime" => $mtime,
				"filename" => $filename,
				"filesize" => $zipSize,
				"zipMD5" => $zipHash,
				"zipFilename" => $zipFilename
			]),
			[
				"Content-Type: application/x-www-form-urlencoded",
				"If-None-Match: *"
			]
		);
		$this->assert200($response);
		$json = API::getJSONFromResponse($response);
		
		self::$toDelete[] = "$zipHash";
		
		// Upload to S3
		$response = HTTP::post(
			$json['url'],
			$json['prefix'] . $zipFileContents . $json['suffix'],
			[
				"Content-Type: {$json['contentType']}"
			]
		);
		$this->assert201($response);
		
		//
		// Register upload
		//
		
		// If-Match with file hash shouldn't match unregistered file
		$response = API::userPost(
			self::$config['userID'],
			"items/$key/file",
			"upload=" . $json['uploadKey'],
			[
				"Content-Type: application/x-www-form-urlencoded",
				"If-Match: $hash"
			]
		);
		$this->assert412($response);
		
		// If-Match with ZIP hash shouldn't match unregistered file
		$response = API::userPost(
			self::$config['userID'],
			"items/$key/file",
			"upload=" . $json['uploadKey'],
			[
				"Content-Type: application/x-www-form-urlencoded",
				"If-Match: $zipHash"
			]
		);
		$this->assert412($response);
		
		$response = API::userPost(
			self::$config['userID'],
			"items/$key/file",
			"upload=" . $json['uploadKey'],
			[
				"Content-Type: application/x-www-form-urlencoded",
				"If-None-Match: *"
			]
		);
		$this->assert204($response);
		$newVersion = $response->getHeader("Last-Modified-Version");
		
		// Verify attachment item metadata
		$response = API::userGet(
			self::$config['userID'],
			"items/$key"
		);
		$json = API::getJSONFromResponse($response)['data'];
		$this->assertEquals($hash, $json['md5']);
		$this->assertEquals($mtime, $json['mtime']);
		$this->assertEquals($filename, $json['filename']);
		$this->assertEquals($contentType, $json['contentType']);
		$this->assertEquals($charset, $json['charset']);
		
		$response = API::userGet(
			self::$config['userID'],
			"laststoragesync"
		);
		$this->assert200($response);
		$this->assertRegExp('/^[0-9]{10}$/', $response->getBody());
		
		// File exists
		$response = API::userPost(
			self::$config['userID'],
			"items/$key/file",
			$this->implodeParams([
				"md5" => $hash,
				"mtime" => $mtime + 1000,
				"filename" => $filename,
				"filesize" => $zipSize,
				"zip" => 1,
				"zipMD5" => $zipHash,
				"zipFilename" => $zipFilename
			]),
			[
				"Content-Type: application/x-www-form-urlencoded",
				"If-Match: $hash"
			]
		);
		$this->assert200($response);
		$json = API::getJSONFromResponse($response);
		$this->assertArrayHasKey("exists", $json);
		$version = $response->getHeader("Last-Modified-Version");
		$this->assertGreaterThan($newVersion, $version);
		
		// Get attachment via classic sync
		$sessionID = Sync::login();
		$xml = Sync::updated($sessionID, 2);
		$this->assertEquals(1, $xml->updated[0]->items->count());
		$itemXML = $xml->xpath("//updated/items/item[@key='$key']")[0];
		$this->assertEquals($contentType, (string) $itemXML['mimeType']);
		$this->assertEquals($charset, (string) $itemXML['charset']);
		$this->assertEquals($hash, (string) $itemXML['storageHash']);
		$this->assertEquals($mtime + 1000, (string) $itemXML['storageModTime']);
		Sync::logout($sessionID);
	}
	
	
	public function testClientV5ShouldReturn404GettingAuthorizationForMissingFile() {
		// Get authorization
		$response = API::userPost(
			self::$config['userID'],
			"items/UP24VFQR/file",
			$this->implodeParams([
				"md5" => md5('qzpqBjLddCc6UhfX'),
				"mtime" => 1477002989206,
				"filename" => 'test.pdf',
				"filesize" => 12345
			]),
			[
				"Content-Type: application/x-www-form-urlencoded",
				"If-None-Match: *"
			]
		);
		$this->assert404($response);
	}
	
	
	public function testAddFileLinkedAttachment() {
		$key = API::createAttachmentItem("linked_file", [], false, $this, 'key');
		
		$file = "work/file";
		$fileContents = self::getRandomUnicodeString();
		file_put_contents($file, $fileContents);
		$hash = md5_file($file);
		$filename = "test_" . $fileContents;
		$mtime = filemtime($file) * 1000;
		$size = filesize($file);
		$contentType = "text/plain";
		$charset = "utf-8";
		
		// Get upload authorization
		$response = API::userPost(
			self::$config['userID'],
			"items/$key/file",
			$this->implodeParams(array(
				"md5" => $hash,
				"filename" => $filename,
				"filesize" => $size,
				"mtime" => $mtime,
				"contentType" => $contentType,
				"charset" => $charset
			)),
			array(
				"Content-Type: application/x-www-form-urlencoded",
				"If-None-Match: *"
			)
		);
		$this->assert400($response);
	}
	
	
	// TODO: Reject for keys not owned by user, even if public library
	public function testLastStorageSyncNoAuthorization() {
		API::useAPIKey(false);
		$response = API::userGet(
			self::$config['userID'],
			"laststoragesync"
		);
		$this->assert401($response);
	}
	
	
	private function implodeParams($params, $exclude=array()) {
		$parts = array();
		foreach ($params as $key => $val) {
			if (in_array($key, $exclude)) {
				continue;
			}
			$parts[] = $key . "=" . urlencode($val);
		}
		return implode("&", $parts);
	}
	
	
	private function getRandomUnicodeString() {
		return "Âéìøü 这是一个测试。 " . uniqid();
	}
}
