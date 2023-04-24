<?php
/*
    ***** BEGIN LICENSE BLOCK *****
    
    This file is part of the Zotero Data Server.
    
    Copyright © 2015 Center for History and New Media
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

class Zotero_Publications {
	// Currently unused
	private static $rights = [
		'CC-BY-4.0',
		'CC-BY-ND-4.0',
		'CC-BY-NC-SA-4.0',
		'CC-BY-SA-4.0',
		'CC-BY-NC-4.0',
		'CC-BY-NC-ND-4.0',
		'CC0'
	];
	
	
	public static function getETag($userID) {
		$cacheKey = "publicationsETag_" . $userID;
		$etag = Z_Core::$MC->get($cacheKey);
		return $etag ? $etag : self::updateETag($userID);
	}
	
	
	public static function updateETag($userID) {
		$cacheKey = "publicationsETag_" . $userID;
		$etag = Zotero_Utilities::randomString(8, 'mixed');
		Z_Core::$MC->set($cacheKey, $etag, 86400);
		return $etag;
	}
}
