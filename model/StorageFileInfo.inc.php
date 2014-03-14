<?
class Zotero_StorageFileInfo {
	public $hash;
	public $filename;
	public $mtime;
	public $size;
	public $contentType;
	public $charset;
	public $zip = false;
	
	
	/**
	 * @param string $key The S3 key of the file
	 */
	public function parseFromS3Key($key) {
		$parts = explode('/', $key);
		if (sizeOf($parts) == 1) {
			throw new Exception("S3 key '$key' is not a storage key");
		}
		$this->hash = $parts[0];
		if (sizeOf($parts) == 3 && $parts[1] == 'c') {
			$this->filename = $parts[2];
			$this->zip = true;
		}
		else {
			$this->filename = $parts[1];
			$this->zip = false;
		}
	}
}
