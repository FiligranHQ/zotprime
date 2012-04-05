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

require_once 'APITests.inc.php';
require_once 'include/api.inc.php';
require_once '../../model/S3Lib.inc.php';

class FileTests extends APITests {
	private static $toDelete = array();
	
	public static function setUpBeforeClass() {
		parent::setUpBeforeClass();
		
		S3::setAuth(self::$config['s3AccessKey'], self::$config['s3SecretKey']);
		
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
		
		foreach (self::$toDelete as $file) {
			$deleted = S3::deleteObject(self::$config['s3Bucket'], $file);
			if (!$deleted) {
				echo "\n$file not found on S3 to delete\n";
			}
		}
	}
	
	
	public function testNewEmptyImportedFileAttachmentItem() {
		$xml = API::createAttachmentItem("imported_file", false, $this);
		return API::parseDataFromItemEntry($xml);
	}
	
	
	/**
	 * @depends testNewEmptyImportedFileAttachmentItem
	 */
	public function testAddFileAuthorizationErrors($data) {
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
				"items/{$data['key']}/file?key=" . self::$config['apiKey'],
				$this->implodeParams($fileParams, array($exclude)),
				array(
					"Content-Type: application/x-www-form-urlencoded",
					"If-None-Match: *"
				)
			);
			$this->assert400($response);
		}
		
		$fileParams = $this->implodeParams($fileParams);
		
		// Invalid If-Match
		$response = API::userPost(
			self::$config['userID'],
			"items/{$data['key']}/file?key=" . self::$config['apiKey'],
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
			"items/{$data['key']}/file?key=" . self::$config['apiKey'],
			$fileParams,
			array(
				"Content-Type: application/x-www-form-urlencoded"
			)
		);
		$this->assert428($response);
		
		// Invalid If-None-Match
		$response = API::userPost(
			self::$config['userID'],
			"items/{$data['key']}/file?key=" . self::$config['apiKey'],
			$fileParams,
			array(
				"Content-Type: application/x-www-form-urlencoded",
				"If-None-Match: invalidETag"
			)
		);
		$this->assert400($response);
	}
	
	
	public function testAddFileFull() {
		$xml = API::createAttachmentItem("imported_file", false, $this);
		$data = API::parseDataFromItemEntry($xml);
		
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
			"items/{$data['key']}/file?key=" . self::$config['apiKey'],
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
		
		self::$toDelete[] = "$hash/$filename";
		
		// Upload to S3
		$response = HTTP::post(
			$json->url,
			$json->prefix . $fileContents . $json->suffix,
			array(
				"Content-Type: " . $json->contentType
			)
		);
		$this->assert201($response);
		
		//
		// Register upload
		//
		
		// No If-None-Match
		$response = API::userPost(
			self::$config['userID'],
			"items/{$data['key']}/file?key=" . self::$config['apiKey'],
			"upload=" . $json->uploadKey,
			array(
				"Content-Type: application/x-www-form-urlencoded"
			)
		);
		$this->assert428($response);
		
		// Invalid upload key
		$response = API::userPost(
			self::$config['userID'],
			"items/{$data['key']}/file?key=" . self::$config['apiKey'],
			"upload=invalidUploadKey",
			array(
				"Content-Type: application/x-www-form-urlencoded",
				"If-None-Match: *"
			)
		);
		$this->assert400($response);
		
		$response = API::userPost(
			self::$config['userID'],
			"items/{$data['key']}/file?key=" . self::$config['apiKey'],
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
			"items/{$data['key']}?key=" . self::$config['apiKey'] . "&content=json"
		);
		$xml = API::getXMLFromResponse($response);
		$json = json_decode(array_shift($xml->xpath('/atom:entry/atom:content')));
		
		$this->assertEquals($hash, $json->md5);
		$this->assertEquals($filename, $json->filename);
		$this->assertEquals($mtime, $json->mtime);
		$this->assertEquals($contentType, $json->contentType);
		$this->assertEquals($charset, $json->charset);
		
		return array(
			"key" => $data['key'],
			"json" => $json,
			"size" => $size
		);
	}
	
	
	public function testAddFileFullParams() {
		$xml = API::createAttachmentItem("imported_file", false, $this);
		$data = API::parseDataFromItemEntry($xml);
		
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
			"items/{$data['key']}/file?key=" . self::$config['apiKey'],
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
		
		self::$toDelete[] = "$hash/$filename";
		
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
			"items/{$data['key']}/file?key=" . self::$config['apiKey'],
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
			"items/{$data['key']}?key=" . self::$config['apiKey'] . "&content=json"
		);
		$xml = API::getXMLFromResponse($response);
		$json = json_decode(array_shift($xml->xpath('/atom:entry/atom:content')));
		
		$this->assertEquals($hash, $json->md5);
		$this->assertEquals($filename, $json->filename);
		$this->assertEquals($mtime, $json->mtime);
		$this->assertEquals($contentType, $json->contentType);
		$this->assertEquals($charset, $json->charset);
	}
	
	
	/**
	 * @depends testAddFileFull
	 */
	public function testAddFileExisting($addFileData) {
		$key = $addFileData['key'];
		$json = $addFileData['json'];
		$md5 = $json->md5;
		$size = $addFileData['size'];
		
		// Get upload authorization
		$response = API::userPost(
			self::$config['userID'],
			"items/$key/file?key=" . self::$config['apiKey'],
			$this->implodeParams(array(
				"md5" => $json->md5,
				"filename" => $json->filename,
				"filesize" => $size,
				"mtime" => $json->mtime,
				"contentType" => $json->contentType,
				"charset" => $json->charset
			)),
			array(
				"Content-Type: application/x-www-form-urlencoded",
				"If-Match: " . $json->md5
			)
		);
		$this->assert200($response);
		$json = json_decode($response->getBody());
		$this->assertNotNull($json);
		$this->assertEquals(1, $json->exists);
		
		return array(
			"key" => $key,
			"md5" => $md5
		);
	}
	
	
	/**
	 * @depends testAddFileExisting
	 */
	public function testGetFile($addFileData) {
		$key = $addFileData['key'];
		$md5 = $addFileData['md5'];
		
		$response = API::userGet(
			self::$config['userID'],
			"items/$key/file?key=" . self::$config['apiKey']
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
				"items/{$getFileData['key']}/file?key=" . self::$config['apiKey'],
				$this->implodeParams($fileParams),
				array(
					"Content-Type: application/x-www-form-urlencoded",
					"If-Match: " . md5_file($oldFilename)
				)
			);
			$this->assert200($response);
			$json = json_decode($response->getBody());
			$this->assertNotNull($json);
			
			exec($cmd);
			
			$patch = file_get_contents($patchFilename);
			$this->assertNotEquals("", $patch);
			
			self::$toDelete[] = "$newHash/test_$newHash";
			
			// Upload patch file
			$response = API::userPatch(
				self::$config['userID'],
				"items/{$getFileData['key']}/file?key=" . self::$config['apiKey']
					. "&algorithm=$algo&upload=" . $json->uploadKey,
				$patch,
				array(
					"If-Match: " . md5_file($oldFilename)
				)
			);
			$this->assert204($response);
			
			// Verify attachment item metadata
			$response = API::userGet(
				self::$config['userID'],
				"items/{$getFileData['key']}?key=" . self::$config['apiKey'] . "&content=json"
			);
			$xml = API::getXMLFromResponse($response);
			$json = json_decode(array_shift($xml->xpath('/atom:entry/atom:content')));
			
			$this->assertEquals($fileParams['md5'], $json->md5);
			$this->assertEquals($fileParams['mtime'], $json->mtime);
			$this->assertEquals($fileParams['contentType'], $json->contentType);
			$this->assertEquals($fileParams['charset'], $json->charset);
			
			unlink($patchFilename);
			rename($newFilename, $oldFilename);
		}
	}
	
	
	public function testAddFileClient() {
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
		
		$xml = API::createAttachmentItem("imported_file", false, $this);
		$data = API::parseDataFromItemEntry($xml);
		
		// Get file info
		$response = API::userGet(
			self::$config['userID'],
			"items/{$data['key']}/file?auth=1&iskey=1&version=1&info=1",
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
			"items/{$data['key']}/file?auth=1&iskey=1&version=1",
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
		
		self::$toDelete[] = "$hash/$filename";
		
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
			"items/{$data['key']}/file?auth=1&iskey=1&version=1",
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
			"items/{$data['key']}/file?auth=1&iskey=1&version=1",
			"update=" . $xml->key,
			array(
				"Content-Type: application/x-www-form-urlencoded"
			),
			$auth
		);
		$this->assert500($response);
		
		$response = API::userPost(
			self::$config['userID'],
			"items/{$data['key']}/file?auth=1&iskey=1&version=1",
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
			"items/{$data['key']}?key=" . self::$config['apiKey'] . "&content=json"
		);
		$xml = API::getXMLFromResponse($response);
		$json = json_decode(array_shift($xml->xpath('/atom:entry/atom:content')));
		
		$this->assertEquals($hash, $json->md5);
		$this->assertEquals($filename, $json->filename);
		$this->assertEquals($mtime, $json->mtime);
		
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
			"items/{$data['key']}/file?auth=1&iskey=1&version=1",
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
	}
	
	
	public function testAddFileLinkedAttachment() {
		$xml = API::createAttachmentItem("linked_file", false, $this);
		$data = API::parseDataFromItemEntry($xml);
		
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
			"items/{$data['key']}/file?key=" . self::$config['apiKey'],
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
