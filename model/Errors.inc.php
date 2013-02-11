<?php
/*
    ***** BEGIN LICENSE BLOCK *****
    
    This file is part of the Zotero Data Server.
    
    Copyright © 2013 Center for History and New Media
                     George Mason University, Fairfax, Virginia, USA
                     http://zotero.org
    
    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU Affero General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.
    
    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU Affero General Public License for more details.
    
    You should have received a copy of the GNU Affero General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.
    
    ***** END LICENSE BLOCK *****
*/

class Zotero_Errors {
	/**
	 * Extract from exception an error message, HTTP response code, and whether
	 * the exception should be logged
	 */
	public static function parseException(Exception $e) {
		$error = array(
			'exception' => $e
		);
		
		$msg = $e->getMessage();
		if ($msg[0] == '=') {
			$msg = substr($msg, 1);
			$explicit = true;
		}
		else {
			$explicit = false;
		}
		$error['message'] = $msg;
		
		$errorCode = $e->getCode();
		switch ($errorCode) {
			case Z_ERROR_INVALID_INPUT:
			case Z_ERROR_NOTE_TOO_LONG:
			case Z_ERROR_FIELD_TOO_LONG:
			case Z_ERROR_CREATOR_TOO_LONG:
			case Z_ERROR_COLLECTION_TOO_LONG:
			case Z_ERROR_CITESERVER_INVALID_STYLE:
				$error['code'] = 400;
				$error['log'] = true;
				break;
			
			// 404?
			case Z_ERROR_TAG_NOT_FOUND:
				$error['code'] = 400;
				break;
			
			case Z_ERROR_UPLOAD_TOO_LARGE:
				$error['code'] = 413;
				$error['log'] = true;
				break;
			
			case Z_ERROR_SHARD_READ_ONLY:
			case Z_ERROR_SHARD_UNAVAILABLE:
				$error['code'] = 503;
				$error['message'] = Z_CONFIG::$MAINTENANCE_MESSAGE;
				$error['log'] = true;
				break;
			
			default:
				$error['code'] = 500;
				if (Z_ENV_TESTING_SITE) {
					$error['message'] = $e;
				}
				else {
					$error['message'] = "An error occurred";
				}
				$error['log'] = true;
		}
		
		if ($e instanceof HTTPException) {
			$error['code'] = $e->getCode();
		}
		
		return $error;
	}
}
