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

class Zotero_DataObject {
	protected $_id;
	protected $_libraryID;
	protected $_key;
	protected $_version;
	
	protected $loaded = false;
	
	
	public function __get($field) {
		switch ($field) {
		case 'libraryKey':
			return $this->libraryID . "/" . $this->key;
		}
	}
	
	
	/**
	 * Set the object's version to the version found in the DB. This can be set by search code
	 * (which should grab the version) to allow a cached copy of the object to be used. Otherwise,
	 * the primary data would need to be loaded just to get the version number needed to get the
	 * cached object.)
	 */
	public function setAvailableVersion($version) {
		$version = (int) $version;
		if ($this->loaded && $this->_version != $version) {
			throw new Exception("Version does not match current value ($version != $this->_version)");
		}
		$this->_version = $version;
	}
}
