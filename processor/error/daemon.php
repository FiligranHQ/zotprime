<?
if (file_exists('../config')) {
	include('../config');
}
if (file_exists('./config')) {
	include('./config');
}

set_include_path("../../include");
require("header.inc.php");
require("../../model/ProcessorDaemon.inc.php");

$daemon = new Zotero_Error_Processor_Daemon(!empty($daemonConfig) ? $daemonConfig : array());
$daemon->run();
?>
