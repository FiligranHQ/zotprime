<?
class Controller {
	protected $action;
	protected $routeVariables = array();
	
	private $name;
	private $controllerPathname;
	private $directory;
	
	
	function __get($key) {
		return isset($this->routeVariables[$key]) ? $this->routeVariables[$key] : null;
	}
	
	public function init($name, $action, $settings=array(), $extra=array()) {
		$this->name = $name;
		$this->controllerPathname = Z_String::camel2under($name);
		
		//
		// Handle settings set by router
		//
		if (!empty($settings['directory'])) {
			$this->directory = $settings['directory'] . '/';
		}
		
		foreach ($extra as $key=>$val) {
			$this->routeVariables[$key] = $val;
		}
		
		$this->action = $action;
	}
	
	public function getAction(){
		return $this->action;
	}
}
?>
