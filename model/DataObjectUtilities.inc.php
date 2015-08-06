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

namespace Zotero;

abstract class DataObjectUtilities {
	public static $allowedKeyChars = "23456789ABCDEFGHIJKLMNPQRSTUVWXYZ";
	
	public static function getTypeFromObject($object) {
		if (!preg_match("/(Item|Collection|Search|Setting)$/", get_class($object), $matches)) {
			throw new Exception("Invalid object type");
		}
		return strtolower($matches[0]);
	}
	
	
	public static function getObjectTypePlural($objectType) {
		if ($objectType == 'search') {
			return $objectType . "es";
		}
		return $objectType . "s";
	}
	
	
	public static function checkID($dataID) {
		if (!is_int($dataID) || $dataID <= 0) {
			throw new Exception("id must be a positive integer");
		}
		return $dataID;
	}
	
	
	public static function checkKey($key) {
		if (!$key) return null;
		if (!self::isValidKey($key)) throw new Exception("key is not valid");
		return $key;
	}
	
	public static function isValidKey($key) {
		return !!preg_match('/^[' . self::$allowedKeyChars . ']{8}$/', $key);
	}
}
