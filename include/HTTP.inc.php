<?
class Z_HTTP {
	public static function e204() {
		header('HTTP/1.1 204 No Content');
		die();
	}
	
	public static function e300() {
		header('HTTP/1.1 300 Multiple Choices');
		die();
	}
	
	public static function e400($message="Invalid request") {
		header('HTTP/1.1 400 Bad Request');
		die(htmlspecialchars($message));
	}
	
	
	public static function e401($message="Access denied") {
		header('WWW-Authenticate: Basic realm="Zotero API"');
		header('HTTP/1.1 401 Unauthorized');
		die(htmlspecialchars($message));
	}
	
	
	public static function e403($message="Forbidden") {
		header('HTTP/1.1 403 Forbidden');
		die(htmlspecialchars($message));
	}
	
	
	public static function e404($message="Not found") {
		header("HTTP/1.1 404 Not Found");
		die(htmlspecialchars($message));
	}
	
	
	public static function e409($message) {
		header("HTTP/1.1 409 Conflict");
		die(htmlspecialchars($message));
	}
	
	
	public static function e412($message=false) {
		header("HTTP/1.1 412 Precondition Failed");
		die(htmlspecialchars($message));
	}
	
	
	public static function e413($message=false) {
		header("HTTP/1.1 413 Request Entity Too Large");
		die(htmlspecialchars($message));
	}
	
	
	public static function e420($message="Rate Limited") {
		header("HTTP/1.1 420 Rate Limited");
		die(htmlspecialchars($message));
	}
	
	
	public static function e422($message=false) {
		header("HTTP/1.1 422 Unprocessable Entity");
		die(htmlspecialchars($message));
	}
	
	
	public static function e500($message="An error occurred") {
		header("HTTP/1.1 500 Internal Server Error");
		die(htmlspecialchars($message));
	}
	
	
	public static function e501($message="An error occurred") {
		header("HTTP/1.1 501 Not Implemented");
		die(htmlspecialchars($message));
	}
	
	
	public static function e503($message="Service unavailable") {
		header("HTTP/1.1 503 Service Unavailable");
		die(htmlspecialchars($message));
	}
}
