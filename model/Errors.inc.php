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
	public static function parseException(Throwable $e) {
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
			case Z_ERROR_CITESERVER_INVALID_STYLE:
				$error['code'] = 400;
				$error['log'] = true;
				break;
			
			case Z_ERROR_FIELD_TOO_LONG:
				$error['code'] = 413;
				preg_match("/([A-Za-z ]+) field value '(.+)' too long/", $msg, $matches);
				if ($matches) {
					$name = $matches[1];
					$value = $matches[2];
					$error['data']['field'] = $name;
					$error['data']['value'] = $value;
				}
				$error['log'] = true;
				break;
			
			case Z_ERROR_CREATOR_TOO_LONG:
				$error['code'] = 413;
				preg_match("/Creator value '(.+)' too long/", $msg, $matches);
				if ($matches) {
					$name = $matches[1];
					// TODO: Replace with simpler message after client version 5.0.36,
					// which includes this locally
					$error['message'] = "The creator name ‘{$name}…’ is too long to sync. "
						. "Shorten the name and sync again.\n\n"
						. "If you receive this message repeatedly for items saved from a "
						. "particular site, you can report this issue in the Zotero Forums.";
					$error['data']['field'] = 'creator';
					$error['data']['value'] = $name;
				}
				$error['log'] = true;
				break;
				
			case Z_ERROR_COLLECTION_TOO_LONG:
				$error['code'] = 413;
				preg_match("/Collection name '(.+)' too long/", $msg, $matches);
				if ($matches) {
					$error['data']['value'] = $matches[1];
				}
				break;
				
			case Z_ERROR_NOTE_TOO_LONG:
				$error['code'] = 413;
				$error['log'] = true;
				break;
			
			case Z_ERROR_TAG_TOO_LONG:
				$error['code'] = 413;
				preg_match("/Tag '(.+)' too long/s", $msg, $matches);
				if ($matches) {
					$name = $matches[1];
					$error['message'] = "Tag '" . mb_substr($name, 0, 50) . "…' too long";
					$error['data']['tag'] = $name;
				}
				$error['log'] = true;
				break;
			
			case Z_ERROR_COLLECTION_NOT_FOUND:
			case Z_ERROR_ITEM_NOT_FOUND:
			case Z_ERROR_TAG_NOT_FOUND:
				if ($errorCode == Z_ERROR_COLLECTION_NOT_FOUND) {
					preg_match("/Collection \d+\/([^ ]+) doesn't exist/", $msg, $matches);
					if ($matches) {
						$error['code'] = 409;
						$error['message'] = "Collection {$matches[1]} not found";
						$error['data']['collection'] = $matches[1];
					}
					else {
						preg_match("/Parent collection \d+\/([^ ]+) doesn't exist/", $msg, $matches);
						if ($matches) {
							$error['code'] = 409;
							$error['message'] = "Parent collection {$matches[1]} not found";
							$error['data']['collection'] = $matches[1];
						}
					}
				}
				else if ($errorCode == Z_ERROR_ITEM_NOT_FOUND) {
					preg_match("/Parent item \d+\/([^ ]+) doesn't exist/", $msg, $matches);
					if ($matches) {
						$error['code'] = 409;
						$error['message'] = "Parent item {$matches[1]} not found";
						$error['data']['parentItem'] = $matches[1];
					}
				}
				if (!isset($error['code'])) {
					// TODO: Change to 409
					$error['code'] = 400;
				}
				$error['log'] = true;
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
				if (!($e instanceof HTTPException) || $errorCode == 500) {
					$error['code'] = 500;
					if (Z_ENV_TESTING_SITE) {
						$error['message'] = $e;
					}
					else {
						$error['message'] = "An error occurred";
					}
				}
				$error['log'] = true;
		}
		
		if ($e instanceof HTTPException) {
			$error['code'] = $e->getCode();
		}
		
		return $error;
	}
}
