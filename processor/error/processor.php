<?
error_reporting(E_ALL | E_STRICT);
set_time_limit(900);

if (file_exists('../config')) {
	include('../config');
}
if (file_exists('./config')) {
	include('./config');
}

set_include_path("../../include");
require("header.inc.php");
require('../../model/Error.inc.php');
require('../../model/Processor.inc.php');

$id = isset($argv[1]) ? $argv[1] : null;
$processor = new Zotero_Error_Processor();
$processor->run($id);
?>
