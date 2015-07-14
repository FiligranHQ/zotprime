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

class Zotero_Settings extends Zotero_DataObjects {
	public static $MAX_VALUE_LENGTH = 1000;
	
	protected static $ZDO_object = 'setting';
	protected static $ZDO_key = 'name';
	protected static $ZDO_id = 'name';
	protected static $ZDO_timestamp = 'lastUpdated';
	
	protected static $primaryFields = array(
		'libraryID' => '',
		'name' => '',
		'value' => '',
		'version' => ''
	);
	
	public static function search($libraryID, $params) {
		// Default empty library
		if ($libraryID === 0) {
			return [];
		}
		
		$sql = "SELECT name FROM settings WHERE libraryID=?";
		$params = array($libraryID);
		
		if (!empty($params['since'])) {
			$sql .= "AND version > ? ";
			$sqlParams[] = $params['since'];
		}
		
		// TEMP: for sync transition
		if (!empty($params['sincetime'])) {
			$sql .= "AND lastUpdated >= FROM_UNIXTIME(?) ";
			$sqlParams[] = $params['sincetime'];
		}
		
		$names = Zotero_DB::columnQuery($sql, $params, Zotero_Shards::getByLibraryID($libraryID));
		if (!$names) {
			$names = array();
		}
		
		$settings = array();
		foreach ($names as $name) {
			$setting = new Zotero_Setting;
			$setting->libraryID = $libraryID;
			$setting->name = $name;
			$settings[] = $setting;
		}
		return $settings;
	}
	
	
	
	/**
	 * Converts a DOMElement item to a Zotero_Setting object
	 *
	 * @param DOMElement $xml Setting data as DOMElement
	 * @return Zotero_Setting Setting object
	 */
	public static function convertXMLToSetting(DOMElement $xml) {
		$libraryID = (int) $xml->getAttribute('libraryID');
		$name = (string) $xml->getAttribute('name');
		$setting = self::getByLibraryAndKey($libraryID, $name);
		if (!$setting) {
			$setting = new Zotero_Setting;
			$setting->libraryID = $libraryID;
			$setting->name = $name;
		}
		$setting->value = json_decode((string) $xml->nodeValue);
		return $setting;
	}
	
	
	/**
	 * Converts a Zotero_Setting object to a SimpleXMLElement item
	 *
	 * @param Zotero_Setting $item Zotero_Setting object
	 * @return DOMElement
	 */
	public static function convertSettingToXML(Zotero_Setting $setting, DOMDocument $doc) {
		$xmlSetting = $doc->createElement('setting');
		$xmlSetting->setAttribute('libraryID', $setting->libraryID);
		$xmlSetting->setAttribute('name', $setting->name);
		$xmlSetting->setAttribute('version', $setting->version);
		$xmlSetting->appendChild($doc->createTextNode(json_encode($setting->value)));
		return $xmlSetting;
	}
	
	
	/**
	 * @param Zotero_Setting $setting The setting object to update;
	 *                                this should be either an existing
	 *                                setting or a new setting
	 *                                with a library and name assigned.
	 * @param object $json Setting data to write
	 * @param boolean [$requireVersion=0] See Zotero_API::checkJSONObjectVersion()
	 * @return boolean True if the setting was changed, false otherwise
	 */
	public static function updateFromJSON(Zotero_Setting $setting,
	                                      $json,
	                                      $requestParams,
	                                      $userID,
	                                      $requireVersion=0) {
		self::validateJSONObject($setting->name, $json, $requestParams);
		Zotero_API::checkJSONObjectVersion(
			$setting, $json, $requestParams, $requireVersion
		);
		
		$changed = false;
		
		if (!Zotero_DB::transactionInProgress()) {
			Zotero_DB::beginTransaction();
			$transactionStarted = true;
		}
		else {
			$transactionStarted = false;
		}
		
		$setting->value = $json->value;
		$changed = $setting->save() || $changed;
		
		if ($transactionStarted) {
			Zotero_DB::commit();
		}
		
		return $changed;
	}
	
	
	private static function validateJSONObject($name, $json, $requestParams) {
		if (!is_object($json)) {
			throw new Exception('$json must be a decoded JSON object');
		}
		
		$requiredProps = array('value');
		
		switch ($name) {
		case 'tagColors':
			break;
		
		default:
			throw new Exception("Invalid setting '$name'");
		}
		
		foreach ($requiredProps as $prop) {
			if (!isset($json->$prop)) {
				throw new Exception("'$prop' property not provided", Z_ERROR_INVALID_INPUT);
			}
		}
		
		foreach ($json as $key=>$val) {
			switch ($key) {
				// Handled by Zotero_API::checkJSONObjectVersion()
				case 'version':
					break;
				
				case 'value':
					self::checkSettingValue($name, $val);
					break;
					
				default:
					throw new Exception("Invalid property '$key'", Z_ERROR_INVALID_INPUT);
			}
		}
	}
	
	
	public static function updateMultipleFromJSON($json, $libraryID, $requestParams, $userID, $requireVersion, $parent=null) {
		self::validateMultiObjectJSON($json, $requestParams);
		
		Zotero_DB::beginTransaction();
		
		$changed = false;
		foreach ($json as $name => $jsonObject) {
			if (!is_object($jsonObject)) {
				throw new Exception(
					"Invalid property '$name'; expected JSON setting object",
					Z_ERROR_INVALID_INPUT
				);
			}
			
			$obj = new Zotero_Setting;
			$obj->libraryID = $libraryID;
			$obj->name = $name;
			$changed = static::updateFromJSON(
				$obj, $jsonObject, $requestParams, $requireVersion
			) || $changed;
		}
		
		Zotero_DB::commit();
		
		return $changed;
	}
	
	
	public static function checkSettingValue($setting, $value) {
		switch ($setting) {
		// Array settings
		case 'tagColors':
			if (!is_array($value)) {
				throw new Exception("'value' must be an array", Z_ERROR_INVALID_INPUT);
			}
			
			if (empty($value)) {
				throw new Exception("'value' array cannot be empty", Z_ERROR_INVALID_INPUT);
			}
			
			if (mb_strlen(json_encode($value)) > self::$MAX_VALUE_LENGTH) {
				throw new Exception("'value' cannot be longer than "
					. self::$MAX_VALUE_LENGTH . " characters", Z_ERROR_INVALID_INPUT);
			}
			break;
		
		// String settings
		default:
			if (!is_string($value)) {
				throw new Exception("'value' be a string", Z_ERROR_INVALID_INPUT);
			}
			
			if ($val === "") {
				throw new Exception("'value' cannot be empty", Z_ERROR_INVALID_INPUT);
			}
			
			if (mb_strlen($value) > self::$MAX_VALUE_LENGTH) {
				throw new Exception("'value' cannot be longer than "
					. self::$MAX_VALUE_LENGTH . " characters", Z_ERROR_INVALID_INPUT);
			}
			break;
		}
	}
	
	
	protected static function validateMultiObjectJSON($json, $requestParams) {
		if (!is_object($json)) {
			throw new Exception('$json must be a decoded JSON object');
		}
		
		if (sizeOf(get_object_vars($json)) > Zotero_API::$maxWriteSettings) {
			throw new Exception("Cannot add more than "
				. Zotero_API::$maxWriteSettings
				. " settings at a time", Z_ERROR_UPLOAD_TOO_LARGE);
		}
	}
	
	
	private static function invalidValueError($prop, $value) {
		throw new Exception("Invalid '$prop' value '$value'");
	}
}
