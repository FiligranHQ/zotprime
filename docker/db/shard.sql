--  ***** BEGIN LICENSE BLOCK *****
--  
--  This file is part of the Zotero Data Server.
--  
--  Copyright © 2010 Center for History and New Media
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


--
-- IMPORTANT: All tables added here must be added to Zotero_Shards::copyLibrary()!
--

CREATE TABLE `collectionItems` (
  `collectionID` int(10) unsigned NOT NULL,
  `itemID` bigint unsigned NOT NULL,
  `orderIndex` mediumint(8) unsigned DEFAULT NULL,
  PRIMARY KEY (`collectionID`,`itemID`),
  KEY `itemID` (`itemID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



CREATE TABLE `collections` (
  `collectionID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `libraryID` int(10) unsigned NOT NULL,
  `collectionName` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `parentCollectionID` int(10) unsigned DEFAULT NULL,
  `key` char(8) NOT NULL,
  `dateAdded` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `dateModified` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `serverDateModified` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `version` int(10) unsigned NOT NULL DEFAULT '1',
  PRIMARY KEY (`collectionID`),
  UNIQUE KEY `key` (`libraryID`,`key`),
  KEY `parentCollection` (`libraryID`,`parentCollectionID`),
  KEY `collections_ibfk_1` (`parentCollectionID`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;



CREATE TABLE `creators` (
  `creatorID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `libraryID` int(10) unsigned NOT NULL,
  `creatorDataHash` char(32) CHARACTER SET ascii DEFAULT NULL,
  `firstName` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lastName` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fieldMode` tinyint(1) unsigned DEFAULT NULL,
  `dateAdded` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `dateModified` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `key` char(8) NOT NULL,
  `serverDateModified` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `serverDateModifiedMS` smallint(4) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`creatorID`),
  UNIQUE KEY `key` (`libraryID`,`key`),
  KEY `hash` (`libraryID`,`creatorDataHash`(5)),
  KEY `name` (`libraryID`,`lastName`(7),`firstName`(6))
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



CREATE TABLE `deletedCollections` (
  `collectionID` int(10) unsigned NOT NULL,
  `dateDeleted` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`collectionID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


CREATE TABLE `deletedItems` (
  `itemID` bigint unsigned NOT NULL,
  `dateDeleted` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`itemID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


CREATE TABLE `deletedSearches` (
  `searchID` int(10) unsigned NOT NULL,
  `dateDeleted` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`searchID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


CREATE TABLE `groupItems` (
  `itemID` bigint unsigned NOT NULL,
  `createdByUserID` int(10) unsigned DEFAULT NULL,
  `lastModifiedByUserID` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`itemID`),
  KEY `createdByUserID` (`createdByUserID`),
  KEY `lastModifiedByUserID` (`lastModifiedByUserID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


CREATE TABLE `publicationsItems` (
  `itemID` bigint unsigned NOT NULL,
  PRIMARY KEY (`itemID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


CREATE TABLE `itemAttachments` (
  `itemID` bigint unsigned NOT NULL,
  `sourceItemID` bigint unsigned DEFAULT NULL,
  `linkMode` enum('IMPORTED_FILE','IMPORTED_URL','LINKED_FILE','LINKED_URL','EMBEDDED_IMAGE'),
  `mimeType` varchar(255) NOT NULL,
  `charsetID` tinyint(3) unsigned DEFAULT NULL,
  `path` blob NOT NULL,
  `storageModTime` bigint(13) unsigned DEFAULT NULL,
  `storageHash` char(32) DEFAULT NULL,
  PRIMARY KEY (`itemID`),
  KEY `sourceItemID` (`sourceItemID`),
  KEY `charsetID` (`charsetID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


CREATE TABLE `itemAnnotations` (
  `itemID` bigint unsigned NOT NULL,
  `parentItemID` bigint unsigned NOT NULL,
  `type` enum('highlight','note','image', 'ink') CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `authorName` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL DEFAULT '',
  `text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `comment` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `color` char(6) CHARACTER SET ascii NOT NULL,
  `pageLabel` varchar(50) NOT NULL,
  `sortIndex` varchar(18) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `position` text CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (`itemID`),
  KEY `parentItemID` (`parentItemID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE `itemCreators` (
  `itemID` bigint unsigned NOT NULL,
  `creatorID` bigint unsigned NOT NULL,
  `creatorTypeID` smallint(5) unsigned NOT NULL,
  `orderIndex` smallint(5) unsigned NOT NULL,
  PRIMARY KEY (`itemID`,`creatorID`,`orderIndex`),
  KEY `creatorID` (`creatorID`),
  KEY `creatorTypeID` (`creatorTypeID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



CREATE TABLE `itemData` (
  `itemID` bigint unsigned NOT NULL,
  `fieldID` smallint(5) unsigned NOT NULL,
  `itemDataValueHash` char(32) CHARACTER SET ascii DEFAULT NULL,
  `value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci,
  PRIMARY KEY (`itemID`,`fieldID`),
  KEY `fieldID` (`fieldID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


CREATE TABLE `itemFulltext` (
  `itemID` bigint unsigned NOT NULL,
  `libraryID` int(10) unsigned NOT NULL,
  `version` int(10) unsigned NOT NULL DEFAULT '0',
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`itemID`),
  KEY `libraryVersion` (`libraryID`,`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE `itemNotes` (
  `itemID` bigint unsigned NOT NULL,
  `sourceItemID` bigint unsigned DEFAULT NULL,
  `note` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `noteSanitized` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NULL,
  `title` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `hash` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (`itemID`),
  KEY `sourceItemID` (`sourceItemID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



CREATE TABLE `itemRelated` (
  `itemID` bigint unsigned NOT NULL,
  `linkedItemID` bigint unsigned NOT NULL,
  PRIMARY KEY (`itemID`,`linkedItemID`),
  KEY `linkedItemID` (`linkedItemID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



CREATE TABLE `itemSortFields` (
  `itemID` bigint unsigned NOT NULL,
  `sortTitle` varchar(79) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NULL DEFAULT NULL,
  `creatorSummary` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NULL DEFAULT NULL,
  PRIMARY KEY (`itemID`),
  KEY `sortTitle` (`sortTitle`),
  KEY `creatorSummary` (`creatorSummary`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



CREATE TABLE `items` (
  `itemID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `libraryID` int(10) unsigned NOT NULL,
  `itemTypeID` smallint(5) unsigned NOT NULL,
  `dateAdded` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `dateModified` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `key` char(8) NOT NULL,
  `serverDateModified` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `version` int(10) unsigned NOT NULL DEFAULT '1',
  PRIMARY KEY (`itemID`),
  UNIQUE KEY `key` (`libraryID`,`key`),
  KEY `itemTypeID` (`itemTypeID`),
  KEY `serverDateModified` (`serverDateModified`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;



CREATE TABLE `itemTags` (
  `itemID` bigint unsigned NOT NULL,
  `tagID` int(10) unsigned NOT NULL,
  PRIMARY KEY (`itemID`,`tagID`),
  KEY `tagID` (`tagID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



CREATE TABLE `itemTopLevel` (
  `itemID` bigint unsigned NOT NULL,
  `topLevelItemID` bigint unsigned NOT NULL,
  PRIMARY KEY (`itemID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



CREATE TABLE `relations` (
  `relationID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `libraryID` int(10) unsigned NOT NULL,
  `key` char(32) CHARACTER SET ascii NOT NULL,
  `subject` varchar(255) NOT NULL,
  `predicate` varchar(255) NOT NULL,
  `object` varchar(255) NOT NULL,
  `serverDateModified` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`relationID`),
  UNIQUE KEY `uniqueRelations` (`libraryID`,`key`),
  KEY `subject` (`libraryID`,`subject`),
  KEY `object` (`libraryID`,`object`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



CREATE TABLE `savedSearchConditions` (
  `searchID` int(10) unsigned NOT NULL,
  `searchConditionID` smallint(5) unsigned NOT NULL,
  `condition` varchar(50) NOT NULL,
  `mode` varchar(50) NOT NULL,
  `operator` varchar(25) NOT NULL,
  `value` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `required` tinyint(1) unsigned NOT NULL,
  PRIMARY KEY (`searchID`,`searchConditionID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



CREATE TABLE `savedSearches` (
  `searchID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `libraryID` int(10) unsigned NOT NULL,
  `searchName` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `dateAdded` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `dateModified` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `key` char(8) NOT NULL,
  `serverDateModified` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `version` int(10) unsigned NOT NULL DEFAULT '1',
  PRIMARY KEY (`searchID`),
  UNIQUE KEY `key` (`libraryID`,`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


CREATE TABLE `settings` (
  `libraryID` int(10) unsigned NOT NULL,
  `name` varchar(35) NOT NULL,
  `value` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `version` int(10) unsigned NOT NULL,
  `lastUpdated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`libraryID`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


CREATE TABLE `shardLibraries` (
  `libraryID` int unsigned NOT NULL,
  `libraryType` enum('user','group','publications') NOT NULL,
  `lastUpdated` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `version` int unsigned NOT NULL DEFAULT '1',
  `storageUsage` bigint NOT NULL DEFAULT '0',
  PRIMARY KEY (`libraryID`),
  KEY `libraryType` (`libraryType`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


CREATE TABLE `storageFileItems` (
  `storageFileID` int(10) unsigned NOT NULL,
  `itemID` bigint unsigned NOT NULL,
  `mtime` bigint(13) unsigned NOT NULL,
  `size` int(10) unsigned NOT NULL,
  PRIMARY KEY (`storageFileID`,`itemID`),
  UNIQUE KEY `itemID` (`itemID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



CREATE TABLE `syncDeleteLogIDs` (
  `libraryID` int(10) unsigned NOT NULL,
  `objectType` enum('group') NOT NULL,
  `id` int(10) unsigned NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `timestampMS` smallint(4) unsigned NOT NULL DEFAULT '0' DEFAULT '0',
  PRIMARY KEY (`libraryID`,`objectType`,`id`),
  KEY `libraryID` (`libraryID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



CREATE TABLE `syncDeleteLogKeys` (
  `libraryID` int(10) unsigned NOT NULL,
  `objectType` enum('collection','creator','item','relation','search','setting','tag','tagName') NOT NULL,
  `key` varchar(255) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `version` int(10) unsigned NOT NULL DEFAULT '1',
  `data` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`libraryID`,`objectType`,`key`),
  KEY `objectType` (`objectType`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



CREATE TABLE `tags` (
  `tagID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `libraryID` int(10) unsigned NOT NULL,
  `name` varchar(255) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
  `type` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `dateAdded` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `dateModified` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `key` char(8) NOT NULL,
  `serverDateModified` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `version` int(10) unsigned NOT NULL DEFAULT '1',
  PRIMARY KEY (`tagID`),
  UNIQUE KEY `uniqueTags` (`libraryID`,`name`,`type`),
  UNIQUE KEY `key` (`libraryID`,`key`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;



CREATE TABLE `tmpCreatedByUsers` (
  `userID` int unsigned NOT NULL,
  `username` varchar(255) NOT NULL,
  PRIMARY KEY (userID),
  INDEX (username)
);



CREATE TABLE `tmpItemTypeNames` (
  `itemTypeID` smallint(4) unsigned NOT NULL,
  `locale` char(5) NOT NULL,
  `itemTypeName` varchar(255) NOT NULL,
  PRIMARY KEY (itemTypeID, locale),
  INDEX (itemTypeName)
);



ALTER TABLE `collectionItems`
  ADD CONSTRAINT `collectionItems_ibfk_1` FOREIGN KEY (`collectionID`) REFERENCES `collections` (`collectionID`) ON DELETE CASCADE,
  ADD CONSTRAINT `collectionItems_ibfk_2` FOREIGN KEY (`itemID`) REFERENCES `items` (`itemID`) ON DELETE CASCADE;

ALTER TABLE `collections`
  ADD CONSTRAINT `collections_ibfk_1` FOREIGN KEY (`parentCollectionID`) REFERENCES `collections` (`collectionID`) ON DELETE CASCADE,
  ADD CONSTRAINT `collections_ibfk_2` FOREIGN KEY (`libraryID`) REFERENCES `shardLibraries` (`libraryID`) ON DELETE CASCADE;

ALTER TABLE `creators`
  ADD CONSTRAINT `creators_ibfk_1` FOREIGN KEY (`libraryID`) REFERENCES `shardLibraries` (`libraryID`) ON DELETE CASCADE;

ALTER TABLE `deletedCollections`
  ADD CONSTRAINT `deletedCollections_ibfk_1` FOREIGN KEY (`collectionID`) REFERENCES `collections` (`collectionID`) ON DELETE CASCADE;

ALTER TABLE `deletedItems`
  ADD CONSTRAINT `deletedItems_ibfk_1` FOREIGN KEY (`itemID`) REFERENCES `items` (`itemID`) ON DELETE CASCADE;

ALTER TABLE `deletedSearches`
  ADD CONSTRAINT `deletedSearches_ibfk_1` FOREIGN KEY (`searchID`) REFERENCES `savedSearches` (`searchID`) ON DELETE CASCADE;

ALTER TABLE `groupItems`
  ADD CONSTRAINT `groupItems_ibfk_1` FOREIGN KEY (`itemID`) REFERENCES `items` (`itemID`) ON DELETE CASCADE;

ALTER TABLE `publicationsItems`
  ADD CONSTRAINT `publicationsItems_ibfk_1` FOREIGN KEY (`itemID`) REFERENCES `items` (`itemID`) ON DELETE CASCADE;

ALTER TABLE `itemAnnotations`
  ADD CONSTRAINT `itemAnnotations_ibfk_1` FOREIGN KEY (`itemID`) REFERENCES `items` (`itemID`) ON DELETE CASCADE,
  ADD CONSTRAINT `itemAnnotations_ibfk_2` FOREIGN KEY (`parentItemID`) REFERENCES `itemAttachments` (`itemID`);

ALTER TABLE `itemAttachments`
  ADD CONSTRAINT `itemAttachments_ibfk_1` FOREIGN KEY (`itemID`) REFERENCES `items` (`itemID`) ON DELETE CASCADE,
  ADD CONSTRAINT `itemAttachments_ibfk_2` FOREIGN KEY (`sourceItemID`) REFERENCES `items` (`itemID`) ON DELETE SET NULL;

ALTER TABLE `itemCreators`
  ADD CONSTRAINT `itemCreators_ibfk_1` FOREIGN KEY (`itemID`) REFERENCES `items` (`itemID`) ON DELETE CASCADE,
  ADD CONSTRAINT `itemCreators_ibfk_2` FOREIGN KEY (`creatorID`) REFERENCES `creators` (`creatorID`) ON DELETE CASCADE;

ALTER TABLE `itemFulltext`
  ADD CONSTRAINT `itemFulltext_ibfk_1` FOREIGN KEY (`itemID`) REFERENCES `itemAttachments` (`itemID`);

ALTER TABLE `itemData`
  ADD CONSTRAINT `itemData_ibfk_1` FOREIGN KEY (`itemID`) REFERENCES `items` (`itemID`) ON DELETE CASCADE;

ALTER TABLE `itemNotes`
  ADD CONSTRAINT `itemNotes_ibfk_1` FOREIGN KEY (`itemID`) REFERENCES `items` (`itemID`) ON DELETE CASCADE,
  ADD CONSTRAINT `itemNotes_ibfk_2` FOREIGN KEY (`sourceItemID`) REFERENCES `items` (`itemID`) ON DELETE SET NULL;

ALTER TABLE `itemRelated`
  ADD CONSTRAINT `itemRelated_ibfk_1` FOREIGN KEY (`itemID`) REFERENCES `items` (`itemID`) ON DELETE CASCADE,
  ADD CONSTRAINT `itemRelated_ibfk_2` FOREIGN KEY (`linkedItemID`) REFERENCES `items` (`itemID`) ON DELETE CASCADE;

ALTER TABLE `itemSortFields`
  ADD CONSTRAINT `itemSortFields_ibfk_1` FOREIGN KEY (`itemID`) REFERENCES `items` (`itemID`) ON DELETE CASCADE;

ALTER TABLE `items`
  ADD CONSTRAINT `items_ibfk_2` FOREIGN KEY (`libraryID`) REFERENCES `shardLibraries` (`libraryID`) ON DELETE CASCADE;

ALTER TABLE `itemTags`
  ADD CONSTRAINT `itemTags_ibfk_1` FOREIGN KEY (`itemID`) REFERENCES `items` (`itemID`) ON DELETE CASCADE,
  ADD CONSTRAINT `itemTags_ibfk_2` FOREIGN KEY (`tagID`) REFERENCES `tags` (`tagID`) ON DELETE CASCADE;

ALTER TABLE `itemTopLevel`
  ADD CONSTRAINT `itemTopLevel_ibfk_1` FOREIGN KEY (`itemID`) REFERENCES `items` (`itemID`) ON DELETE CASCADE,
  ADD CONSTRAINT `itemTopLevel_ibfk_2` FOREIGN KEY (`topLevelItemID`) REFERENCES `items` (`itemID`) ON DELETE CASCADE;

ALTER TABLE `relations`
  ADD CONSTRAINT `relations_ibfk_1` FOREIGN KEY (`libraryID`) REFERENCES `shardLibraries` (`libraryID`) ON DELETE CASCADE;

ALTER TABLE `savedSearchConditions`
  ADD CONSTRAINT `savedSearchConditions_ibfk_1` FOREIGN KEY (`searchID`) REFERENCES `savedSearches` (`searchID`) ON DELETE CASCADE;

ALTER TABLE `savedSearches`
  ADD CONSTRAINT `savedSearches_ibfk_1` FOREIGN KEY (`libraryID`) REFERENCES `shardLibraries` (`libraryID`) ON DELETE CASCADE;

-- Keep in sync with fkd_items_storageUsage trigger
ALTER TABLE `storageFileItems`
  ADD CONSTRAINT `storageFileItems_ibfk_2` FOREIGN KEY (`itemID`) REFERENCES `items` (`itemID`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `syncDeleteLogIDs`
  ADD CONSTRAINT `syncDeleteLogIDs_ibfk_1` FOREIGN KEY (`libraryID`) REFERENCES `shardLibraries` (`libraryID`) ON DELETE CASCADE;

ALTER TABLE `syncDeleteLogKeys`
  ADD CONSTRAINT `syncDeleteLogKeys_ibfk_1` FOREIGN KEY (`libraryID`) REFERENCES `shardLibraries` (`libraryID`) ON DELETE CASCADE;

ALTER TABLE `tags`
  ADD CONSTRAINT `tags_ibfk_1` FOREIGN KEY (`libraryID`) REFERENCES `shardLibraries` (`libraryID`) ON DELETE CASCADE;
