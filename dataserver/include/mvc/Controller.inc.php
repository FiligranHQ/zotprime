<?php
class Controller {
	protected $name;
	protected $action;
	
	
	public function __construct($controllerName, $action, $params) {
		$this->name = $controllerName;
		$this->action = $action;
		
		foreach ($params as $key => $val) {
			switch ($key) {
			case 'controller':
			case 'action':
			case 'extra':
				break;
			
			default:
				$this->$key = $val;
			}
		}
	}
}
