<?php
class HTTPException extends Exception {
	public function __construct($message, $code = 0, Exception $previous = null) {
		if ($code < 400 || $code > 600) {
			error_log("Invalid HTTP response code $code creating HTTPException -- using 500");
			$code = 500;
		}
		parent::__construct($message, $code, $previous);
	}
}
