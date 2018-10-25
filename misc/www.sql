--  ***** BEGIN LICENSE BLOCK *****
--
--  This file is part of the Zotero Data Server.
--
--  Copyright © 2017 Center for History and New Media
--                   George Mason University, Fairfax, Virginia, USA
--                   http://zotero.org
--
--  This program is free software: you can redistribute it and/or modify
--  it under the terms of the GNU Affero General Public License as published by
--  the Free Software Foundation, either version 3 of the License, or
--  (at your option) any later version.
--
--  This program is distributed in the hope that it will be useful,
--  but WITHOUT ANY WARRANTY; without even the implied warranty of
--  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
--  GNU Affero General Public License for more details.
--
--  You should have received a copy of the GNU Affero General Public License
--  along with this program.  If not, see <http://www.gnu.org/licenses/>.
--
--  ***** END LICENSE BLOCK *****

CREATE TABLE IF NOT EXISTS `users` (
  `userID` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(40) CHARACTER SET utf8 NOT NULL,
  `password` char(40) COLLATE utf8_bin NOT NULL,
  PRIMARY KEY (`userID`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin;

CREATE TABLE IF NOT EXISTS `users_email` (
  `emailID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `userID` int(10) unsigned NOT NULL,
  `email` varchar(100) CHARACTER SET utf8 NOT NULL,
  PRIMARY KEY (`emailID`),
  KEY `userID` (`userID`),
  KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin;

CREATE TABLE IF NOT EXISTS `GDN_User` (
  `userID` int(10) unsigned NOT NULL,
  `Banned` int(1) NOT NULL DEFAULT '0',
  KEY `userID` (`userID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `LUM_User` (
  `UserID` int(10) NOT NULL AUTO_INCREMENT,
  `RoleID` int(2) NOT NULL DEFAULT '0',
    PRIMARY KEY (`UserID`),
  KEY `user_role` (`RoleID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `storage_institutions` (
  `institutionID` smallint(5) unsigned NOT NULL AUTO_INCREMENT,
  `domain` varchar(100) NOT NULL,
  `domainBlacklist` text,
  `storageQuota` int(11) NOT NULL,
  PRIMARY KEY (`institutionID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `storage_institution_email` (
  `institutionID` smallint(5) unsigned NOT NULL,
  `email` varchar(255) COLLATE utf8_bin NOT NULL,
  PRIMARY KEY (`institutionID`,`email`),
  KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin;

CREATE TABLE IF NOT EXISTS `users_meta` (
  `userID` mediumint(8) unsigned NOT NULL,
  `metaKey` varchar(60) CHARACTER SET utf8 NOT NULL,
  `metaValue` text CHARACTER SET utf8 NOT NULL,
  `lastUpdated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`userID`,`metaKey`),
  KEY `metaKey` (`metaKey`,`metaValue`(20))
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin;
