<?
namespace Zotero\Tests;

if (!class_exists('Zotero\Tests\Config')) {
	class Config {
		private static $instance;
		private static $config;
		
		protected function __construct() {
			self::$config = parse_ini_file('config.ini');
		}
		
		private static function getInstance() {
			if (!isset(self::$instance)) {
				self::$instance = new static();
			}
			return self::$instance;
		}
		
		public static function getConfig() {
			 $instance = self::getInstance();
			 return $instance::$config;
		}
		
		public static function update($config) {
			self::getInstance();
			
			foreach ($config as $key => $val) {
				if (!isset(self::$config[$key]) || self::$config[$key] !== $val) {
					self::$config[$key] = $val;
				}
			}
		}
	}
}

$config = Config::getConfig();
?>
