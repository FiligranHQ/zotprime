<?php
class Router {
	private $routes = array();
	
	public function map($path, $params=array()) {
		if ($path[0] != "/") {
			throw new Exception("Path must begin with /");
		}
		$this->routes[$path] = array(
			"parts" => explode("/", trim($path, "/")),
			"params" => $params
		);
	}
	
	
	public function match($url) {
		$path = parse_url($url, PHP_URL_PATH);
		$pathParts = explode("/", str_replace("//", "/", trim($path, "/")));
		
		if ($url == '/') {
			return $this->routes['/']['params'];
		}
		unset($this->routes['/']);
		
		//var_dump("path is $path");
		//var_dump($pathParts);
		
		//var_dump("\n\n\n");
		
		foreach ($this->routes as $path => $route) {
			//ar_dump('==========');
			//ar_dump("path: " . $path);
			//ar_dump("route:");
			//ar_dump($route);
			
			for ($i = 0, $len = sizeOf($route['parts']); $i < $len; $i++) {
				$routePart = $route['parts'][$i];
				
				//var_dump("Route part $routePart");
				//if (isset($pathParts[$i])) {
				//	var_dump("Path part $pathParts[$i]");
				//}
				//else {
				//	var_dump("No path part");
				//}
				
				// If route part is a placeholder, sub in the path part for the
				// given variable, or false if not present
				if (strpos($routePart, ":") !== false) {
					$p = explode(":", $routePart);
					
					$part = isset($pathParts[$i]) ? $pathParts[$i] : false;
					
					if ($p[0]) {
						switch ($p[0]) {
						case 'i':
							if (!is_numeric($part)) {
								continue 2;
							}
							$part = (int) $part;
							break;
							
						default:
							throw new Exception("Invalid route type '{$p[0]}'");
						}
					}
					else {
						$part = urldecode($part);
					}
					
					$route['params'][$p[1]] = $part;
				}
				// The path doesn't match this component
				else if (!isset($pathParts[$i]) || $pathParts[$i] != $routePart) {
					continue 2;
				}
			}
			
			// Route doesn't cover the whole path
			if (isset($pathParts[$i])) {
				continue;
			}
			
			return $route['params'];
		}
		
		return false;
	}
}