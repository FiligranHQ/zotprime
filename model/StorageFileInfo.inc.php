<?
class Zotero_StorageFileInfo {
	public $hash;
	public $filename;
	public $mtime;
	public $size;
	public $contentType;
	public $charset;
	public $zip = false;
	public $itemHash;
	public $itemFilename;
	
	public function toJSON() {
		return json_encode(get_object_vars($this));
	}
}
