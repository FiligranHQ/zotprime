<?
class Z_Solr {
	public $client;
	
	public function __construct($options) {
		$this->client = new SolrClient($options);
	}
	
	public static function addDocument() {
		
	}
}
?>
