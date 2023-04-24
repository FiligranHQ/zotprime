<?
set_include_path(get_include_path() . PATH_SEPARATOR . "../../include");
require_once("header.inc.php");

if (!Z_ENV_TESTING_SITE) {
	throw new Exception("Tests can be run only on testing site");
}
