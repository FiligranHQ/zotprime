<?
/*
    ***** BEGIN LICENSE BLOCK *****
    
    This file is part of the Zotero Data Server.
    
    Copyright © 2010 Center for History and New Media
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

class Zotero_CharacterSets {
	private static $initialized = false;
	private static $charsetIDs = array();
	private static $charsets = array();
	private static $charsetMap = [];
	
	private static function init() {
		if (self::$initialized) {
			return;
		}
		self::$initialized = true;
		
		///
		// This should match the client.
		//
		// From https://encoding.spec.whatwg.org/#names-and-labels
		// Don't use this, use charsetMap. See below
		$charsetList = [
			"utf-8" => ["unicode-1-1-utf-8", "utf-8", "utf8"],
			"ibm866" => ["866", "cp866", "csibm866", "ibm866"],
			"iso-8859-2" => ["csisolatin2", "iso-8859-2", "iso-ir-101", "iso8859-2", "iso88592", "iso_8859-2", "iso_8859-2:1987","l2", "latin2"],
			"iso-8859-3" => ["csisolatin3", "iso-8859-3", "iso-ir-109", "iso8859-3", "iso88593", "iso_8859-3", "iso_8859-3:1988","l3", "latin3"],
			"iso-8859-4" => ["csisolatin4", "iso-8859-4", "iso-ir-110", "iso8859-4", "iso88594", "iso_8859-4", "iso_8859-4:1988","l4", "latin4"],
			"iso-8859-5" => ["csisolatincyrillic", "cyrillic", "iso-8859-5", "iso-ir-144", "iso8859-5", "iso88595", "iso_8859-5", "iso_8859-5:1988"],
			"iso-8859-6" => ["arabic", "asmo-708", "csiso88596e", "csiso88596i", "csisolatinarabic", "ecma-114", "iso-8859-6", "iso-8859-6-e", "iso-8859-6-i", "iso-ir-127", "iso8859-6", "iso88596", "iso_8859-6", "iso_8859-6:1987"],
			"iso-8859-7" => ["csisolatingreek", "ecma-118", "elot_928", "greek", "greek8", "iso-8859-7", "iso-ir-126", "iso8859-7", "iso88597", "iso_8859-7", "iso_8859-7:1987","sun_eu_greek"],
			"iso-8859-8" => ["csiso88598e", "csisolatinhebrew", "hebrew", "iso-8859-8", "iso-8859-8-e", "iso-ir-138", "iso8859-8", "iso88598", "iso_8859-8", "iso_8859-8:1988","visual"],
			"iso-8859-8-i" => ["csiso88598i", "iso-8859-8-i", "logical"],
			"iso-8859-10" => ["csisolatin6", "iso-8859-10", "iso-ir-157", "iso8859-10", "iso885910", "l6", "latin6"],
			"iso-8859-13" => ["iso-8859-13", "iso8859-13", "iso885913"],
			"iso-8859-14" => ["iso-8859-14", "iso8859-14", "iso885914"],
			"iso-8859-15" => ["csisolatin9", "iso-8859-15", "iso8859-15", "iso885915", "iso_8859-15", "l9"],
			"iso-8859-16" => ["iso-8859-16"],
			"koi8-r" => ["cskoi8r", "koi", "koi8", "koi8-r", "koi8_r"],
			"koi8-u" => ["koi8-u"],
			"macintosh" => ["csmacintosh", "mac", "macintosh", "x-mac-roman"],
			"windows-874" => ["dos-874", "iso-8859-11", "iso8859-11", "iso885911", "tis-620", "windows-874"],
			"windows-1250" => ["cp1250", "windows-1250", "x-cp1250"],
			"windows-1251" => ["cp1251", "windows-1251", "x-cp1251"],
			"windows-1252" => ["ansi_x3.4-1968","ascii", "cp1252", "cp819", "csisolatin1", "ibm819", "iso-8859-1", "iso-ir-100", "iso8859-1", "iso88591", "iso_8859-1", "iso_8859-1:1987","l1", "latin1", "us-ascii", "windows-1252", "x-cp1252"],
			"windows-1253" => ["cp1253", "windows-1253", "x-cp1253"],
			"windows-1254" => ["cp1254", "csisolatin5", "iso-8859-9", "iso-ir-148", "iso8859-9", "iso88599", "iso_8859-9", "iso_8859-9:1989","l5", "latin5", "windows-1254", "x-cp1254"],
			"windows-1255" => ["cp1255", "windows-1255", "x-cp1255"],
			"windows-1256" => ["cp1256", "windows-1256", "x-cp1256"],
			"windows-1257" => ["cp1257", "windows-1257", "x-cp1257"],
			"windows-1258" => ["cp1258", "windows-1258", "x-cp1258"],
			"x-mac-cyrillic" => ["x-mac-cyrillic", "x-mac-ukrainian"],
			"gbk" => ["chinese", "csgb2312", "csiso58gb231280", "gb2312", "gb_2312", "gb_2312-80", "gbk", "iso-ir-58", "x-gbk"],
			"gb18030" => ["gb18030"],
			"big5" => ["big5", "cn-big5", "csbig5", "x-x-big5"],
			"big5-hkscs" => ["big5-hkscs"], // see https://bugzilla.mozilla.org/show_bug.cgi?id=912470
			"euc-jp" => ["cseucpkdfmtjapanese", "euc-jp", "x-euc-jp"],
			"iso-2022-jp" => ["csiso2022jp", "iso-2022-jp"],
			"shift_jis" => ["csshiftjis", "ms_kanji", "shift-jis", "shift_jis", "sjis", "windows-31j", "x-sjis"],
			"euc-kr" => ["cseuckr", "csksc56011987", "euc-kr", "iso-ir-149", "korean", "ks_c_5601-1987", "ks_c_5601-1989", "ksc5601", "ksc_5601", "windows-949"],
			"replacement" => ["csiso2022kr", "hz-gb-2312", "iso-2022-cn", "iso-2022-cn-ext", "iso-2022-kr"],
			"utf-16be" => ["utf-16be"],
			"utf-16le" => ["utf-16", "utf-16le"],
			"x-user-defined" => ["x-user-defined"]
		];
		
		// As per https://dom.spec.whatwg.org/#dom-document-characterset
		$compatibilityNames = [
			"utf-8" => "UTF-8",
			"ibm866" => "IBM866",
			"iso-8859-2" => "ISO-8859-2",
			"iso-8859-3" => "ISO-8859-3",
			"iso-8859-4" => "ISO-8859-4",
			"iso-8859-5" => "ISO-8859-5",
			"iso-8859-6" => "ISO-8859-6",
			"iso-8859-7" => "ISO-8859-7",
			"iso-8859-8" => "ISO-8859-8",
			"iso-8859-8-i" => "ISO-8859-8-I",
			"iso-8859-10" => "ISO-8859-10",
			"iso-8859-13" => "ISO-8859-13",
			"iso-8859-14" => "ISO-8859-14",
			"iso-8859-15" => "ISO-8859-15",
			"iso-8859-16" => "ISO-8859-16",
			"koi8-r" => "KOI8-R",
			"koi8-u" => "KOI8-U",
			"gbk" => "GBK",
			"big5" => "Big5",
			"euc-jp" => "EUC-JP",
			"iso-2022-jp" => "ISO-2022-JP",
			"shift_jis" => "Shift_JIS",
			"euc-kr" => "EUC-KR",
			"utf-16be" => "UTF-16BE",
			"utf-16le" => "UTF-16LE"
		];
		
		$charsetMap = [];
		foreach ($charsetList as $canonical => $alternates) {
			$charsetMap[strtolower($canonical)] = $canonical;
			foreach ($alternates as $c) {
				$charsetMap[strtolower($c)] = $canonical;
			}
			
			if (!isset($compatibilityNames[$canonical])) {
				$compatibilityNames[$canonical] = $canonical;
			}
		}
		self::$charsetMap = $charsetMap;
	}
	
	public static function getID($charsetOrCharsetID) {
		if (isset(self::$charsetIDs[$charsetOrCharsetID])) {
			return self::$charsetIDs[$charsetOrCharsetID];
		}
		
		$sql = "(SELECT charsetID FROM charsets WHERE charsetID=?) UNION
				(SELECT charsetID FROM charsets WHERE charset=?) LIMIT 1";
		$charsetID = Zotero_DB::valueQuery($sql, array($charsetOrCharsetID, $charsetOrCharsetID));
		
		self::$charsetIDs[$charsetOrCharsetID] = $charsetID;
		
		return $charsetID;
	}
	
	
	public static function getName($charsetOrCharsetID) {
		if (isset(self::$charsets[$charsetOrCharsetID])) {
			return self::$charsets[$charsetOrCharsetID];
		}
		
		$sql = "(SELECT charset FROM charsets WHERE charsetID=?) UNION
				(SELECT charset FROM charsets WHERE charset=?) LIMIT 1";
		$charset = Zotero_DB::valueQuery($sql, array($charsetOrCharsetID, $charsetOrCharsetID));
		
		self::$charsets[$charsetOrCharsetID] = $charset;
		
		return $charset;
	}
	
	
	/**
	 * Convert charset label to charset name
	 * https://encoding.spec.whatwg.org/#names-and-labels
	 * @param {String} charset
	 * @return {String|Boolean} - Normalized charset name or FALSE if not recognized
	 */
	public static function toCanonical($charset) {
		self::init();
		
		$canonical = strtolower(trim($charset));
		if (!isset(self::$charsetMap[$canonical])) {
			Z_Core::debug("Unrecognized charset: " . $charset);
			return false;
		}
		return self::$charsetMap[$canonical];
	}
}
?>
