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

SET SQL_MODE="NO_AUTO_VALUE_ON_ZERO";

CREATE TABLE `abstractCreators` (
  `creatorID` int(10) unsigned NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



CREATE TABLE `abstractItems` (
  `itemID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (`itemID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



CREATE TABLE `baseFieldMappings` (
  `itemTypeID` smallint(5) unsigned NOT NULL,
  `baseFieldID` smallint(5) unsigned NOT NULL,
  `fieldID` smallint(5) unsigned NOT NULL,
  PRIMARY KEY (`itemTypeID`,`baseFieldID`,`fieldID`),
  KEY `baseFieldID` (`baseFieldID`),
  KEY `fieldID` (`fieldID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



CREATE TABLE `charsets` (
  `charsetID` tinyint(3) unsigned NOT NULL,
  `charset` varchar(50) NOT NULL,
  PRIMARY KEY (`charsetID`),
  KEY `charset` (`charset`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



CREATE TABLE `collectionItems` (
  `collectionID` int(10) unsigned NOT NULL,
  `itemID` int(10) unsigned NOT NULL,
  `orderIndex` mediumint(8) unsigned DEFAULT NULL,
  PRIMARY KEY (`collectionID`,`itemID`),
  KEY `itemID` (`itemID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TRIGGER IF EXISTS `fki_collectionItems`;
DELIMITER //
CREATE TRIGGER `fki_collectionItems` BEFORE INSERT ON `collectionItems`
 FOR EACH ROW BEGIN
    
    IF (
    (
        (SELECT libraryID FROM collections WHERE collectionID = NEW.collectionID) IS NULL
            AND
        (SELECT libraryID FROM items WHERE itemID = NEW.itemID) IS NOT NULL
    ) OR (
        (SELECT libraryID FROM collections WHERE collectionID = NEW.collectionID) IS NOT NULL
            AND
        (SELECT libraryID FROM items WHERE itemID = NEW.itemID) IS NULL
    ) OR
        (SELECT libraryID FROM collections WHERE collectionID = NEW.collectionID) != (SELECT libraryID FROM items WHERE itemID = NEW.itemID)
    ) THEN
    SELECT libraryIDs_do_not_match INTO @failure FROM collectionItems;
    END IF;
    
    
    IF (
        SELECT COUNT(*) FROM itemAttachments WHERE itemID=NEW.itemID AND sourceItemID IS NOT NULL
    )=1 OR (
        SELECT COUNT(*) FROM itemNotes WHERE itemID=NEW.itemID AND sourceItemID IS NOT NULL
    )=1 THEN
    SELECT collection_item_must_be_top_level INTO @failure FROM collectionItems;
    END IF;
  END
//
DELIMITER ;
DROP TRIGGER IF EXISTS `fku_collectionItems`;
DELIMITER //
CREATE TRIGGER `fku_collectionItems` BEFORE UPDATE ON `collectionItems`
 FOR EACH ROW BEGIN
    IF (
    (
        (SELECT libraryID FROM collections WHERE collectionID = NEW.collectionID) IS NULL
            AND
        (SELECT libraryID FROM items WHERE itemID = NEW.itemID) IS NOT NULL
    ) OR (
        (SELECT libraryID FROM collections WHERE collectionID = NEW.collectionID) IS NOT NULL
            AND
        (SELECT libraryID FROM items WHERE itemID = NEW.itemID) IS NULL
    ) OR
        (SELECT libraryID FROM collections WHERE collectionID = NEW.collectionID) != (SELECT libraryID FROM items WHERE itemID = NEW.itemID)
    ) THEN
    SELECT libraryIDs_do_not_match INTO @failure FROM collectionItems;
    END IF;
    
    IF (
        SELECT COUNT(*) FROM itemAttachments WHERE itemID=NEW.itemID AND sourceItemID IS NOT NULL
    )=1 OR (
        SELECT COUNT(*) FROM itemNotes WHERE itemID=NEW.itemID AND sourceItemID IS NOT NULL
    )=1 THEN
    SELECT collection_item_must_be_top_level INTO @failure FROM collectionItems;
    END IF;
  END
//
DELIMITER ;



CREATE TABLE `collections` (
  `collectionID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `libraryID` int(10) unsigned NOT NULL,
  `collectionName` varchar(255) NOT NULL,
  `parentCollectionID` int(10) unsigned DEFAULT NULL,
  `key` char(8) NOT NULL,
  `dateAdded` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `dateModified` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `serverDateModified` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `serverDateModifiedMS` smallint(4) unsigned NOT NULL,
  PRIMARY KEY (`collectionID`),
  UNIQUE KEY `key` (`libraryID`,`key`),
  KEY `parentCollection` (`libraryID`,`parentCollectionID`),
  KEY `collections_ibfk_1` (`parentCollectionID`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;



CREATE TABLE `creatorData` (
  `creatorDataID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `firstName` varchar(255) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
  `lastName` varchar(255) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
  `shortName` varchar(100) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
  `fieldMode` tinyint(1) unsigned NOT NULL,
  `birthYear` year(4) DEFAULT NULL,
  PRIMARY KEY (`creatorDataID`),
  KEY `lastName` (`lastName`(20),`firstName`(5))
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;



CREATE TABLE `creators` (
  `creatorID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `libraryID` int(10) unsigned NOT NULL,
  `creatorDataID` int(10) unsigned NOT NULL,
  `dateAdded` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `dateModified` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `key` char(8) NOT NULL,
  `serverDateModified` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `serverDateModifiedMS` smallint(4) unsigned NOT NULL,
  PRIMARY KEY (`creatorID`),
  UNIQUE KEY `key` (`libraryID`,`key`),
  KEY `creatorDataID` (`creatorDataID`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;



CREATE TABLE `creatorTypes` (
  `creatorTypeID` smallint(5) unsigned NOT NULL,
  `creatorTypeName` varchar(50) NOT NULL,
  `custom` tinyint(1) unsigned NOT NULL,
  PRIMARY KEY (`creatorTypeID`),
  UNIQUE KEY `creatorTypeName` (`creatorTypeName`),
  KEY `custom` (`custom`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



CREATE TABLE `deletedItems` (
  `itemID` int(10) unsigned NOT NULL,
  `dateDeleted` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`itemID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TRIGGER IF EXISTS `fki_deletedItems_libraryID`;
DELIMITER //
CREATE TRIGGER `fki_deletedItems_libraryID` BEFORE INSERT ON `deletedItems`
 FOR EACH ROW BEGIN
    IF (
        SELECT COUNT(*) FROM items WHERE itemID=NEW.itemID AND libraryID IN (SELECT libraryID FROM libraries WHERE libraryType='user')
    )=0 THEN
    SELECT deleted_item_must_belong_to_user_library INTO @failure FROM deletedItems;
    END IF;
  END
//
DELIMITER ;
DROP TRIGGER IF EXISTS `fku_deletedItems_libraryID`;
DELIMITER //
CREATE TRIGGER `fku_deletedItems_libraryID` BEFORE UPDATE ON `deletedItems`
 FOR EACH ROW BEGIN
    IF (
        SELECT COUNT(*) FROM items WHERE itemID=NEW.itemID AND libraryID IN (SELECT libraryID FROM libraries WHERE libraryType='user')
    )=0 THEN
    SELECT deleted_item_must_belong_to_user_library INTO @failure FROM deletedItems;
    END IF;
  END
//
DELIMITER ;



CREATE TABLE `fields` (
  `fieldID` smallint(5) unsigned NOT NULL,
  `fieldName` varchar(50) NOT NULL,
  `fieldFormatID` tinyint(3) unsigned DEFAULT NULL,
  `custom` tinyint(1) unsigned NOT NULL,
  PRIMARY KEY (`fieldID`),
  UNIQUE KEY `fieldName` (`fieldName`),
  KEY `custom` (`custom`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



CREATE TABLE `groupItems` (
  `itemID` int(10) unsigned NOT NULL,
  `createdByUserID` int(10) unsigned DEFAULT NULL,
  `lastModifiedByUserID` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`itemID`),
  KEY `createdByUserID` (`createdByUserID`),
  KEY `lastModifiedByUserID` (`lastModifiedByUserID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



CREATE TABLE `groups` (
  `groupID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `libraryID` int(10) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) DEFAULT NULL,
  `type` enum('PublicOpen','PublicClosed','Private') NOT NULL DEFAULT 'Private',
  `libraryEnabled` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `libraryEditing` enum('admins','members') NOT NULL DEFAULT 'admins',
  `libraryReading` enum('members','all') NOT NULL DEFAULT 'all',
  `fileEditing` enum('none','admins','members') NOT NULL DEFAULT 'admins',
  `description` text NOT NULL,
  `url` varchar(255) NOT NULL,
  `hasImage` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `dateAdded` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `dateModified` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (`groupID`),
  UNIQUE KEY `libraryID` (`libraryID`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;



CREATE TABLE `groupUsers` (
  `groupID` int(10) unsigned NOT NULL,
  `userID` int(10) unsigned NOT NULL,
  `role` enum('owner','admin','member') NOT NULL DEFAULT 'member',
  `joined` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `lastUpdated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`groupID`,`userID`),
  KEY `userID` (`userID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



CREATE TABLE `itemAttachments` (
  `itemID` int(10) unsigned NOT NULL,
  `sourceItemID` int(10) unsigned DEFAULT NULL,
  `linkMode` enum('IMPORTED_FILE','IMPORTED_URL','LINKED_FILE','LINKED_URL') NOT NULL,
  `mimeTypeID` smallint(5) unsigned DEFAULT NULL,
  `charsetID` tinyint(3) unsigned DEFAULT NULL,
  `path` blob NOT NULL,
  `storageModTime` bigint(13) unsigned DEFAULT NULL,
  `storageHash` char(32) DEFAULT NULL,
  PRIMARY KEY (`itemID`),
  KEY `sourceItemID` (`sourceItemID`),
  KEY `mimeTypeID` (`mimeTypeID`),
  KEY `charsetID` (`charsetID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TRIGGER IF EXISTS `fki_itemAttachments`;
DELIMITER //
CREATE TRIGGER `fki_itemAttachments` BEFORE INSERT ON `itemAttachments`
 FOR EACH ROW BEGIN
    
    IF (NEW.sourceItemID IS NOT NULL) AND (
    (
        (SELECT libraryID FROM items WHERE itemID = NEW.itemID) IS NULL
            AND
        (SELECT libraryID FROM items WHERE itemID = NEW.sourceItemID) IS NOT NULL
    ) OR (
        (SELECT libraryID FROM items WHERE itemID = NEW.itemID) IS NOT NULL
            AND
        (SELECT libraryID FROM items WHERE itemID = NEW.sourceItemID) IS NULL
    ) OR
        (SELECT libraryID FROM items WHERE itemID = NEW.itemID) != (SELECT libraryID FROM items WHERE itemID = NEW.sourceItemID)
    ) THEN
    SELECT libraryIDs_do_not_match INTO @failure FROM itemAttachments;
    END IF;
    
    
    IF ((SELECT itemTypeID FROM items WHERE itemID = NEW.itemID) != 14) THEN
    SELECT not_an_attachment INTO @failure FROM items;
    END IF;
    
    
    IF (NEW.sourceItemID IS NOT NULL AND (SELECT itemTypeID FROM items WHERE itemID = NEW.sourceItemID) IN (1,14)) THEN
    SELECT parent_not_regular_item INTO @failure FROM items;
    END IF;
    
    
    IF (NEW.sourceItemID IS NOT NULL AND (SELECT COUNT(*) FROM collectionItems WHERE itemID=NEW.itemID)>0) THEN
    SELECT collection_item_must_be_top_level INTO @failure FROM collectionItems;
    END IF;
  END
//
DELIMITER ;
DROP TRIGGER IF EXISTS `fku_itemAttachments_libraryID`;
DELIMITER //
CREATE TRIGGER `fku_itemAttachments_libraryID` BEFORE UPDATE ON `itemAttachments`
 FOR EACH ROW BEGIN
    IF (NEW.sourceItemID IS NOT NULL) AND (
    (
        (SELECT libraryID FROM items WHERE itemID = NEW.itemID) IS NULL
            AND
        (SELECT libraryID FROM items WHERE itemID = NEW.sourceItemID) IS NOT NULL
    ) OR (
        (SELECT libraryID FROM items WHERE itemID = NEW.itemID) IS NOT NULL
            AND
        (SELECT libraryID FROM items WHERE itemID = NEW.sourceItemID) IS NULL
    ) OR
        (SELECT libraryID FROM items WHERE itemID = NEW.itemID) != (SELECT libraryID FROM items WHERE itemID = NEW.sourceItemID)
    ) THEN
    SELECT libraryIDs_do_not_match INTO @failure FROM itemAttachments;
    END IF;
    
    
    IF (NEW.sourceItemID IS NOT NULL AND (SELECT itemTypeID FROM items WHERE itemID = NEW.sourceItemID) IN (1,14)) THEN
    SELECT parent_not_regular_item INTO @failure FROM items;
    END IF;
    
    
    IF (NEW.sourceItemID IS NOT NULL AND (SELECT COUNT(*) FROM collectionItems WHERE itemID=NEW.itemID)>0) THEN
    SELECT collection_item_must_be_top_level INTO @failure FROM collectionItems;
    END IF;
  END
//
DELIMITER ;



CREATE TABLE `itemCreators` (
  `itemID` int(10) unsigned NOT NULL,
  `creatorID` int(10) unsigned NOT NULL,
  `creatorTypeID` smallint(5) unsigned NOT NULL,
  `orderIndex` smallint(5) unsigned NOT NULL,
  PRIMARY KEY (`itemID`,`creatorID`,`orderIndex`),
  KEY `creatorID` (`creatorID`),
  KEY `creatorTypeID` (`creatorTypeID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TRIGGER IF EXISTS `fki_itemCreators_libraryID`;
DELIMITER //
CREATE TRIGGER `fki_itemCreators_libraryID` BEFORE INSERT ON `itemCreators`
 FOR EACH ROW BEGIN
    IF (
    (
        (SELECT libraryID FROM creators WHERE creatorID = NEW.creatorID) IS NULL
            AND
        (SELECT libraryID FROM items WHERE itemID = NEW.itemID) IS NOT NULL
    ) OR (
        (SELECT libraryID FROM creators WHERE creatorID = NEW.creatorID) IS NOT NULL
            AND
        (SELECT libraryID FROM items WHERE itemID = NEW.itemID) IS NULL
    ) OR
        (SELECT libraryID FROM creators WHERE creatorID = NEW.creatorID) != (SELECT libraryID FROM items WHERE itemID = NEW.itemID)
    ) THEN
    SELECT libraryIDs_do_not_match INTO @failure FROM itemCreators;
    END IF;
  END
//
DELIMITER ;
DROP TRIGGER IF EXISTS `fku_itemCreators_libraryID`;
DELIMITER //
CREATE TRIGGER `fku_itemCreators_libraryID` BEFORE UPDATE ON `itemCreators`
 FOR EACH ROW BEGIN
    IF (
    (
        (SELECT libraryID FROM creators WHERE creatorID = NEW.creatorID) IS NULL
            AND
        (SELECT libraryID FROM items WHERE itemID = NEW.itemID) IS NOT NULL
    ) OR (
        (SELECT libraryID FROM creators WHERE creatorID = NEW.creatorID) IS NOT NULL
            AND
        (SELECT libraryID FROM items WHERE itemID = NEW.itemID) IS NULL
    ) OR
        (SELECT libraryID FROM creators WHERE creatorID = NEW.creatorID) != (SELECT libraryID FROM items WHERE itemID = NEW.itemID)
    ) THEN
    SELECT libraryIDs_do_not_match INTO @failure FROM itemCreators;
    END IF;
  END
//
DELIMITER ;



CREATE TABLE `itemData` (
  `itemID` int(10) unsigned NOT NULL,
  `fieldID` smallint(5) unsigned NOT NULL,
  `itemDataValueID` int(10) unsigned NOT NULL,
  PRIMARY KEY (`itemID`,`fieldID`),
  KEY `fieldID` (`fieldID`),
  KEY `itemDataValueID` (`itemDataValueID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



CREATE TABLE `itemDataValues` (
  `itemDataValueID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `value` text CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
  PRIMARY KEY (`itemDataValueID`),
  KEY `value` (`value`(130))
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;



CREATE TABLE `itemNotes` (
  `itemID` int(10) unsigned NOT NULL,
  `sourceItemID` int(10) unsigned DEFAULT NULL,
  `note` mediumtext NOT NULL,
  `title` varchar(80) NOT NULL,
  `hash` varchar(32) NOT NULL,
  PRIMARY KEY (`itemID`),
  KEY `sourceItemID` (`sourceItemID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TRIGGER IF EXISTS `fki_itemNotes`;
DELIMITER //
CREATE TRIGGER `fki_itemNotes` BEFORE INSERT ON `itemNotes`
 FOR EACH ROW BEGIN
    
    IF (NEW.sourceItemID IS NOT NULL) AND (
    (
        (SELECT libraryID FROM items WHERE itemID = NEW.itemID) IS NULL
            AND
        (SELECT libraryID FROM items WHERE itemID = NEW.sourceItemID) IS NOT NULL
    ) OR (
        (SELECT libraryID FROM items WHERE itemID = NEW.itemID) IS NOT NULL
            AND
        (SELECT libraryID FROM items WHERE itemID = NEW.sourceItemID) IS NULL
    ) OR
        (SELECT libraryID FROM items WHERE itemID = NEW.itemID) != (SELECT libraryID FROM items WHERE itemID = NEW.sourceItemID)
    ) THEN
    SELECT libraryIDs_do_not_match INTO @failure FROM itemNotes;
    END IF;
    
    
    IF ((SELECT itemTypeID FROM items WHERE itemID = NEW.itemID) NOT IN (1,14)) THEN
    SELECT not_an_attachment INTO @failure FROM items;
    END IF;
    
    
    IF (NEW.sourceItemID IS NOT NULL AND (SELECT itemTypeID FROM items WHERE itemID = NEW.sourceItemID) IN (1,14)) THEN
    SELECT parent_not_regular_item INTO @failure FROM items;
    END IF;
    
    
    IF (NEW.sourceItemID IS NOT NULL AND (SELECT COUNT(*) FROM collectionItems WHERE itemID=NEW.itemID)>0) THEN
    SELECT collection_item_must_be_top_level INTO @failure FROM collectionItems;
    END IF;
  END
//
DELIMITER ;
DROP TRIGGER IF EXISTS `fku_itemNotes_libraryID`;
DELIMITER //
CREATE TRIGGER `fku_itemNotes_libraryID` BEFORE UPDATE ON `itemNotes`
 FOR EACH ROW BEGIN
    IF (NEW.sourceItemID IS NOT NULL) AND (
    (
        (SELECT libraryID FROM items WHERE itemID = NEW.itemID) IS NULL
            AND
        (SELECT libraryID FROM items WHERE itemID = NEW.sourceItemID) IS NOT NULL
    ) OR (
        (SELECT libraryID FROM items WHERE itemID = NEW.itemID) IS NOT NULL
            AND
        (SELECT libraryID FROM items WHERE itemID = NEW.sourceItemID) IS NULL
    ) OR
        (SELECT libraryID FROM items WHERE itemID = NEW.itemID) != (SELECT libraryID FROM items WHERE itemID = NEW.sourceItemID)
    ) THEN
    SELECT libraryIDs_do_not_match INTO @failure FROM itemNotes;
    END IF;
    
    
    IF (NEW.sourceItemID IS NOT NULL AND (SELECT itemTypeID FROM items WHERE itemID = NEW.sourceItemID) IN (1,14)) THEN
    SELECT parent_not_regular_item INTO @failure FROM items;
    END IF;
    
    
    IF (NEW.sourceItemID IS NOT NULL AND (SELECT COUNT(*) FROM collectionItems WHERE itemID=NEW.itemID)>0) THEN
    SELECT collection_item_must_be_top_level INTO @failure FROM collectionItems;
    END IF;
  END
//
DELIMITER ;



CREATE TABLE `itemRelated` (
  `itemID` int(10) unsigned NOT NULL,
  `linkedItemID` int(10) unsigned NOT NULL,
  PRIMARY KEY (`itemID`,`linkedItemID`),
  KEY `linkedItemID` (`linkedItemID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TRIGGER IF EXISTS `fki_itemRelated_libraryID`;
DELIMITER //
CREATE TRIGGER `fki_itemRelated_libraryID` BEFORE INSERT ON `itemRelated`
 FOR EACH ROW BEGIN
    IF (
    (
        (SELECT libraryID FROM items WHERE itemID = NEW.itemID) IS NULL
            AND
        (SELECT libraryID FROM items WHERE itemID = NEW.linkedItemID) IS NOT NULL
    ) OR (
        (SELECT libraryID FROM items WHERE itemID = NEW.itemID) IS NOT NULL
            AND
        (SELECT libraryID FROM items WHERE itemID = NEW.linkedItemID) IS NULL
    ) OR
        (SELECT libraryID FROM items WHERE itemID = NEW.itemID) != (SELECT libraryID FROM items WHERE itemID = NEW.linkedItemID)
    ) THEN
    SELECT libraryIDs_do_not_match INTO @failure FROM itemRelated;
    END IF;
  END
//
DELIMITER ;
DROP TRIGGER IF EXISTS `fku_itemRelated_libraryID`;
DELIMITER //
CREATE TRIGGER `fku_itemRelated_libraryID` BEFORE UPDATE ON `itemRelated`
 FOR EACH ROW BEGIN
    IF (
    (
        (SELECT libraryID FROM items WHERE itemID = NEW.itemID) IS NULL
            AND
        (SELECT libraryID FROM items WHERE itemID = NEW.linkedItemID) IS NOT NULL
    ) OR (
        (SELECT libraryID FROM items WHERE itemID = NEW.itemID) IS NOT NULL
            AND
        (SELECT libraryID FROM items WHERE itemID = NEW.linkedItemID) IS NULL
    ) OR
        (SELECT libraryID FROM items WHERE itemID = NEW.itemID) != (SELECT libraryID FROM items WHERE itemID = NEW.linkedItemID)
    ) THEN
    SELECT libraryIDs_do_not_match INTO @failure FROM itemRelated;
    END IF;
  END
//
DELIMITER ;



CREATE TABLE `items` (
  `itemID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `libraryID` int(10) unsigned NOT NULL,
  `itemTypeID` smallint(5) unsigned NOT NULL,
  `dateAdded` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `dateModified` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `key` char(8) NOT NULL,
  `serverDateModified` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `serverDateModifiedMS` smallint(4) unsigned NOT NULL,
  PRIMARY KEY (`itemID`),
  UNIQUE KEY `key` (`libraryID`,`key`),
  KEY `itemTypeID` (`itemTypeID`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;



CREATE TABLE `itemTags` (
  `itemID` int(10) unsigned NOT NULL,
  `tagID` int(10) unsigned NOT NULL,
  PRIMARY KEY (`itemID`,`tagID`),
  KEY `tagID` (`tagID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TRIGGER IF EXISTS `fki_itemTags_libraryID`;
DELIMITER //
CREATE TRIGGER `fki_itemTags_libraryID` BEFORE INSERT ON `itemTags`
 FOR EACH ROW BEGIN
    IF (
    (
        (SELECT libraryID FROM tags WHERE tagID = NEW.tagID) IS NULL
            AND
        (SELECT libraryID FROM items WHERE itemID = NEW.itemID) IS NOT NULL
    ) OR (
        (SELECT libraryID FROM tags WHERE tagID = NEW.tagID) IS NOT NULL
            AND
        (SELECT libraryID FROM items WHERE itemID = NEW.itemID) IS NULL
    ) OR
        (SELECT libraryID FROM tags WHERE tagID = NEW.tagID) != (SELECT libraryID FROM items WHERE itemID = NEW.itemID)
    ) THEN
    SELECT libraryIDs_do_not_match INTO @failure FROM itemTags;
    END IF;
  END
//
DELIMITER ;
DROP TRIGGER IF EXISTS `fku_itemTags_libraryID`;
DELIMITER //
CREATE TRIGGER `fku_itemTags_libraryID` BEFORE UPDATE ON `itemTags`
 FOR EACH ROW BEGIN
    IF (
    (
        (SELECT libraryID FROM tags WHERE tagID = NEW.tagID) IS NULL
            AND
        (SELECT libraryID FROM items WHERE itemID = NEW.itemID) IS NOT NULL
    ) OR (
        (SELECT libraryID FROM tags WHERE tagID = NEW.tagID) IS NOT NULL
            AND
        (SELECT libraryID FROM items WHERE itemID = NEW.itemID) IS NULL
    ) OR
        (SELECT libraryID FROM tags WHERE tagID = NEW.tagID) != (SELECT libraryID FROM items WHERE itemID = NEW.itemID)
    ) THEN
    SELECT libraryIDs_do_not_match INTO @failure FROM itemTags;
    END IF;
  END
//
DELIMITER ;



CREATE TABLE `itemTypeCreatorTypes` (
  `itemTypeID` smallint(5) unsigned NOT NULL,
  `creatorTypeID` smallint(5) unsigned NOT NULL,
  `primaryField` tinyint(1) unsigned NOT NULL,
  PRIMARY KEY (`itemTypeID`,`creatorTypeID`),
  KEY `creatorTypeID` (`creatorTypeID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



CREATE TABLE `itemTypeFields` (
  `itemTypeID` smallint(5) unsigned NOT NULL,
  `fieldID` smallint(5) unsigned NOT NULL,
  `hide` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `orderIndex` tinyint(3) unsigned NOT NULL,
  PRIMARY KEY (`itemTypeID`,`fieldID`),
  KEY `fieldID` (`fieldID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



CREATE TABLE `itemTypes` (
  `itemTypeID` smallint(5) unsigned NOT NULL,
  `itemTypeName` varchar(50) NOT NULL,
  `custom` tinyint(1) unsigned NOT NULL,
  PRIMARY KEY (`itemTypeID`),
  UNIQUE KEY `itemTypeName` (`itemTypeName`),
  KEY `custom` (`custom`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



CREATE TABLE `keyAccessLog` (
  `keyID` int(10) unsigned NOT NULL,
  `ipAddress` int(10) unsigned NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=MyISAM DEFAULT CHARSET=utf8;



CREATE TABLE `keyPermissions` (
  `keyID` int(10) unsigned NOT NULL,
  `libraryID` int(10) unsigned NOT NULL,
  `permission` enum('library','notes','group') NOT NULL,
  `granted` tinyint(1) unsigned NOT NULL,
  PRIMARY KEY (`keyID`,`libraryID`,`permission`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



CREATE TABLE `keys` (
  `keyID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `key` char(24) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
  `userID` int(10) unsigned NOT NULL,
  `name` varchar(255) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
  `dateAdded` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`keyID`),
  UNIQUE KEY `key` (`key`),
  KEY `userID` (`userID`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;



CREATE TABLE `libraries` (
  `libraryID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `libraryType` enum('user','group') NOT NULL,
  `lastUpdated` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `lastUpdatedMS` smallint(5) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`libraryID`),
  KEY `libraryType` (`libraryType`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;



CREATE TABLE `mimeTypes` (
  `mimeTypeID` smallint(5) unsigned NOT NULL AUTO_INCREMENT,
  `mimeType` varchar(255) NOT NULL,
  PRIMARY KEY (`mimeTypeID`),
  UNIQUE KEY `mimeType` (`mimeType`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;



CREATE TABLE `relations` (
  `relationID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `libraryID` int(10) unsigned NOT NULL,
  `subject` varchar(255) NOT NULL,
  `predicate` varchar(255) NOT NULL,
  `object` varchar(255) NOT NULL,
  `serverDateModified` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `serverDateModifiedMS` smallint(4) unsigned NOT NULL,
  PRIMARY KEY (`relationID`),
  UNIQUE KEY `uniqueRelations` (`libraryID`,`subject`,`predicate`,`object`),
  KEY `object` (`libraryID`,`object`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;



CREATE TABLE `savedSearchConditions` (
  `searchID` int(10) unsigned NOT NULL,
  `searchConditionID` smallint(5) unsigned NOT NULL,
  `condition` varchar(50) NOT NULL,
  `mode` varchar(50) NOT NULL,
  `operator` varchar(25) NOT NULL,
  `value` varchar(255) NOT NULL,
  `required` tinyint(1) unsigned NOT NULL,
  PRIMARY KEY (`searchID`,`searchConditionID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



CREATE TABLE `savedSearches` (
  `searchID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `libraryID` int(10) unsigned NOT NULL,
  `searchName` varchar(255) NOT NULL,
  `dateAdded` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `dateModified` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `key` char(8) NOT NULL,
  `serverDateModified` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `serverDateModifiedMS` smallint(4) unsigned NOT NULL,
  PRIMARY KEY (`searchID`),
  UNIQUE KEY `key` (`libraryID`,`key`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;



CREATE TABLE `storageAccounts` (
  `userID` int(10) unsigned NOT NULL,
  `quota` smallint(5) unsigned NOT NULL,
  `expiration` timestamp NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (`userID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



CREATE TABLE `storageDownloadLog` (
  `ownerUserID` int(10) unsigned NOT NULL,
  `downloadUserID` int(10) unsigned DEFAULT NULL,
  `ipAddress` int(10) unsigned NULL,
  `storageFileID` int(10) unsigned NOT NULL,
  `filename` varchar(1024) NOT NULL,
  `size` int(10) unsigned NOT NULL,
  `time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=MyISAM DEFAULT CHARSET=utf8 DELAY_KEY_WRITE=1;



CREATE TABLE `storageFileItems` (
  `storageFileID` int(10) unsigned NOT NULL,
  `itemID` int(10) unsigned NOT NULL,
  `mtime` bigint(13) unsigned NOT NULL,
  PRIMARY KEY (`storageFileID`,`itemID`),
  UNIQUE KEY `itemID` (`itemID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



CREATE TABLE `storageFiles` (
  `storageFileID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `hash` char(32) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `size` int(10) unsigned NOT NULL,
  `zip` tinyint(1) unsigned NOT NULL,
  `uploaded` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `lastDeleted` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (`storageFileID`),
  UNIQUE KEY `hash` (`hash`,`filename`,`zip`),
  KEY `zip` (`zip`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;



CREATE TABLE `storageLastSync` (
  `userID` int(10) unsigned NOT NULL,
  `time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`userID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



CREATE TABLE `storageUploadLog` (
  `ownerUserID` int(10) unsigned NOT NULL,
  `uploadUserID` int(10) unsigned NOT NULL,
  `ipAddress` int(10) unsigned NULL,
  `storageFileID` int(10) unsigned NOT NULL,
  `filename` varchar(1024) NOT NULL,
  `size` int(10) unsigned NOT NULL,
  `time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=MyISAM DEFAULT CHARSET=utf8 DELAY_KEY_WRITE=1;



CREATE TABLE `storageUploadQueue` (
  `uploadKey` char(32) NOT NULL,
  `userID` int(10) unsigned NOT NULL,
  `hash` char(32) NOT NULL,
  `filename` varchar(1024) NOT NULL,
  `zip` tinyint(1) unsigned NOT NULL,
  `time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`uploadKey`),
  KEY `userID` (`userID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



CREATE TABLE `syncDeleteLogIDs` (
  `libraryID` int(10) unsigned NOT NULL,
  `objectType` enum('group') NOT NULL,
  `id` int(10) unsigned NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `timestampMS` smallint(4) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`libraryID`,`objectType`,`id`),
  KEY `libraryID` (`libraryID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



CREATE TABLE `syncDeleteLogKeys` (
  `libraryID` int(10) unsigned NOT NULL,
  `objectType` enum('collection','creator','item','search','tag') NOT NULL,
  `key` char(8) NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `timestampMS` smallint(4) unsigned NOT NULL,
  PRIMARY KEY (`libraryID`,`objectType`,`key`),
  KEY `objectType` (`objectType`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



CREATE TABLE `syncDownloadQueue` (
  `syncDownloadQueueID` int(10) unsigned NOT NULL,
  `syncQueueHostID` tinyint(3) unsigned NOT NULL,
  `userID` int(10) unsigned NOT NULL,
  `sessionID` char(32) CHARACTER SET ascii NOT NULL,
  `lastsync` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `lastsyncMS` smallint(5) unsigned NOT NULL DEFAULT '0',
  `version` smallint(5) unsigned NOT NULL,
  `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `objects` int(10) unsigned NOT NULL,
  `tries` smallint(5) unsigned NOT NULL DEFAULT '0',
  `started` timestamp NULL DEFAULT NULL,
  `syncDownloadProcessID` int(10) unsigned DEFAULT NULL,
  `finished` timestamp NULL DEFAULT NULL,
  `finishedMS` smallint(5) unsigned NOT NULL DEFAULT '0',
  `xmldata` longtext,
  `errorCode` int(10) unsigned DEFAULT NULL,
  `errorMessage` text,
  PRIMARY KEY (`syncDownloadQueueID`),
  KEY `userID` (`userID`),
  KEY `sessionID` (`sessionID`),
  KEY `started` (`started`),
  KEY `syncDownloadProcessID` (`syncDownloadProcessID`),
  KEY `syncQueueHostID` (`syncQueueHostID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



CREATE TABLE `syncProcesses` (
  `syncProcessID` int(10) unsigned NOT NULL,
  `userID` int(10) unsigned NOT NULL,
  `started` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`syncProcessID`),
  UNIQUE KEY `userID` (`userID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



CREATE TABLE `syncProcessLocks` (
  `syncProcessID` int(10) unsigned NOT NULL,
  `libraryID` int(10) unsigned NOT NULL,
  PRIMARY KEY (`syncProcessID`,`libraryID`),
  KEY `libraryID` (`libraryID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



CREATE TABLE `syncProcessQueue` (
  `userID` mediumint(8) unsigned NOT NULL,
  `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `running` tinyint(1) unsigned NOT NULL,
  `finished` timestamp NULL DEFAULT NULL,
  `response` text NOT NULL,
  PRIMARY KEY (`userID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



CREATE TABLE `syncQueue` (
  `syncQueueID` int(10) unsigned NOT NULL,
  `syncQueueHostID` tinyint(3) unsigned NOT NULL,
  `xmldata` mediumtext NOT NULL,
  `dataLength` int(10) unsigned NOT NULL DEFAULT '0',
  `hasCreator` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `userID` int(10) unsigned NOT NULL,
  `sessionID` char(32) CHARACTER SET ascii NOT NULL,
  `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `errorCheck` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `tries` smallint(5) unsigned NOT NULL DEFAULT '0',
  `started` timestamp NULL DEFAULT NULL,
  `startedMS` smallint(5) unsigned NOT NULL DEFAULT '0',
  `syncProcessID` int(10) unsigned DEFAULT NULL,
  `finished` timestamp NULL DEFAULT NULL,
  `finishedMS` smallint(5) unsigned NOT NULL DEFAULT '0',
  `errorCode` int(10) unsigned DEFAULT NULL,
  `errorMessage` mediumtext,
  PRIMARY KEY (`syncQueueID`),
  UNIQUE KEY `sessionID` (`sessionID`),
  UNIQUE KEY `syncProcessID` (`syncProcessID`),
  KEY `userID` (`userID`),
  KEY `started` (`started`),
  KEY `syncQueue_ibfk_4` (`syncQueueHostID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



CREATE TABLE `syncQueueHosts` (
  `syncQueueHostID` tinyint(3) unsigned NOT NULL,
  `hostname` varchar(50) NOT NULL,
  PRIMARY KEY (`syncQueueHostID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



CREATE TABLE `syncQueueLocks` (
  `syncQueueID` int(10) unsigned NOT NULL,
  `libraryID` int(10) unsigned NOT NULL,
  PRIMARY KEY (`syncQueueID`,`libraryID`),
  KEY `libraryID` (`libraryID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



CREATE TABLE `syncUploadProcessLog` (
  `userID` int(10) unsigned NOT NULL,
  `dataLength` int(10) unsigned NOT NULL,
  `syncQueueHostID` tinyint(3) unsigned DEFAULT NULL,
  `processDuration` float(6,2) NOT NULL,
  `totalDuration` smallint(5) unsigned NOT NULL,
  `error` tinyint(4) NOT NULL DEFAULT '0',
  `finished` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `syncQueueHostID` (`syncQueueHostID`),
  KEY `finished` (`finished`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



CREATE TABLE `syncUploadQueuePostWriteLog` (
  `syncUploadQueueID` int(10) unsigned NOT NULL,
  `objectType` enum('group','groupUser') NOT NULL,
  `ids` varchar(30) NOT NULL,
  `action` enum('update','delete') NOT NULL,
  PRIMARY KEY (`syncUploadQueueID`,`objectType`,`ids`,`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



CREATE TABLE `tagData` (
  `tagDataID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
  PRIMARY KEY (`tagDataID`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;



CREATE TABLE `tags` (
  `tagID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `libraryID` int(10) unsigned NOT NULL,
  `tagDataID` int(10) unsigned NOT NULL,
  `type` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `dateAdded` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `dateModified` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `key` char(8) NOT NULL,
  `serverDateModified` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `serverDateModifiedMS` smallint(4) unsigned NOT NULL,
  PRIMARY KEY (`tagID`),
  UNIQUE KEY `uniqueTags` (`libraryID`,`tagDataID`,`type`),
  UNIQUE KEY `key` (`libraryID`,`key`),
  KEY `tagDataID` (`tagDataID`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;


CREATE TABLE `uploadLock` (
  `semaphore` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`semaphore`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



CREATE TABLE `users` (
  `userID` int(10) unsigned NOT NULL,
  `libraryID` int(10) unsigned NOT NULL,
  `username` varchar(255) NOT NULL,
  `joined` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `lastSyncTime` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (`userID`),
  UNIQUE KEY `libraryID` (`libraryID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


CREATE TABLE `zotero_sessions`.`sessions` (
  `sessionID` char(32) CHARACTER SET ascii NOT NULL,
  `userID` mediumint(8) unsigned NOT NULL DEFAULT '0',
  `ipAddress` int(10) unsigned NULL,
  `exclusive` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`sessionID`),
  KEY `userID` (`userID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `zotero_sessions`.`sessionsDeleted` (
  `sessionID` char(32) CHARACTER SET ascii NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`sessionID`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;


ALTER TABLE `baseFieldMappings`
  ADD CONSTRAINT `baseFieldMappings_ibfk_1` FOREIGN KEY (`itemTypeID`) REFERENCES `itemTypes` (`itemTypeID`),
  ADD CONSTRAINT `baseFieldMappings_ibfk_2` FOREIGN KEY (`baseFieldID`) REFERENCES `fields` (`fieldID`),
  ADD CONSTRAINT `baseFieldMappings_ibfk_3` FOREIGN KEY (`fieldID`) REFERENCES `fields` (`fieldID`);


ALTER TABLE `collectionItems`
  ADD CONSTRAINT `collectionItems_ibfk_1` FOREIGN KEY (`collectionID`) REFERENCES `collections` (`collectionID`) ON DELETE CASCADE,
  ADD CONSTRAINT `collectionItems_ibfk_2` FOREIGN KEY (`itemID`) REFERENCES `items` (`itemID`) ON DELETE CASCADE;

ALTER TABLE `collections`
  ADD CONSTRAINT `collections_ibfk_1` FOREIGN KEY (`parentCollectionID`) REFERENCES `collections` (`collectionID`) ON DELETE CASCADE,
  ADD CONSTRAINT `collections_ibfk_2` FOREIGN KEY (`libraryID`) REFERENCES `libraries` (`libraryID`) ON DELETE CASCADE;

ALTER TABLE `creators`
  ADD CONSTRAINT `creators_ibfk_1` FOREIGN KEY (`creatorDataID`) REFERENCES `creatorData` (`creatorDataID`),
  ADD CONSTRAINT `creators_ibfk_2` FOREIGN KEY (`libraryID`) REFERENCES `libraries` (`libraryID`) ON DELETE CASCADE;

ALTER TABLE `deletedItems`
  ADD CONSTRAINT `deletedItems_ibfk_1` FOREIGN KEY (`itemID`) REFERENCES `items` (`itemID`) ON DELETE CASCADE;

ALTER TABLE `groupItems`
  ADD CONSTRAINT `groupItems_ibfk_1` FOREIGN KEY (`itemID`) REFERENCES `items` (`itemID`) ON DELETE CASCADE,
  ADD CONSTRAINT `groupItems_ibfk_2` FOREIGN KEY (`createdByUserID`) REFERENCES `users` (`userID`) ON DELETE SET NULL,
  ADD CONSTRAINT `groupItems_ibfk_3` FOREIGN KEY (`lastModifiedByUserID`) REFERENCES `users` (`userID`) ON DELETE SET NULL;

ALTER TABLE `groups`
  ADD CONSTRAINT `groups_ibfk_1` FOREIGN KEY (`libraryID`) REFERENCES `libraries` (`libraryID`) ON DELETE CASCADE;

ALTER TABLE `groupUsers`
  ADD CONSTRAINT `groupUsers_ibfk_1` FOREIGN KEY (`groupID`) REFERENCES `groups` (`groupID`) ON DELETE CASCADE,
  ADD CONSTRAINT `groupUsers_ibfk_2` FOREIGN KEY (`userID`) REFERENCES `users` (`userID`) ON DELETE CASCADE,
  ADD CONSTRAINT `groupUsers_ibfk_3` FOREIGN KEY (`groupID`) REFERENCES `groups` (`groupID`) ON DELETE CASCADE,
  ADD CONSTRAINT `groupUsers_ibfk_4` FOREIGN KEY (`userID`) REFERENCES `users` (`userID`) ON DELETE CASCADE;

ALTER TABLE `itemAttachments`
  ADD CONSTRAINT `itemAttachments_ibfk_1` FOREIGN KEY (`itemID`) REFERENCES `items` (`itemID`) ON DELETE CASCADE,
  ADD CONSTRAINT `itemAttachments_ibfk_2` FOREIGN KEY (`sourceItemID`) REFERENCES `items` (`itemID`) ON DELETE SET NULL,
  ADD CONSTRAINT `itemAttachments_ibfk_3` FOREIGN KEY (`mimeTypeID`) REFERENCES `mimeTypes` (`mimeTypeID`),
  ADD CONSTRAINT `itemAttachments_ibfk_4` FOREIGN KEY (`charsetID`) REFERENCES `charsets` (`charsetID`);

ALTER TABLE `itemCreators`
  ADD CONSTRAINT `itemCreators_ibfk_1` FOREIGN KEY (`itemID`) REFERENCES `items` (`itemID`) ON DELETE CASCADE,
  ADD CONSTRAINT `itemCreators_ibfk_2` FOREIGN KEY (`creatorID`) REFERENCES `creators` (`creatorID`) ON DELETE CASCADE,
  ADD CONSTRAINT `itemCreators_ibfk_3` FOREIGN KEY (`creatorTypeID`) REFERENCES `creatorTypes` (`creatorTypeID`);

ALTER TABLE `itemData`
  ADD CONSTRAINT `itemData_ibfk_1` FOREIGN KEY (`itemID`) REFERENCES `items` (`itemID`) ON DELETE CASCADE,
  ADD CONSTRAINT `itemData_ibfk_2` FOREIGN KEY (`itemDataValueID`) REFERENCES `itemDataValues` (`itemDataValueID`),
  ADD CONSTRAINT `itemData_ibfk_3` FOREIGN KEY (`fieldID`) REFERENCES `fields` (`fieldID`);

ALTER TABLE `itemNotes`
  ADD CONSTRAINT `itemNotes_ibfk_1` FOREIGN KEY (`itemID`) REFERENCES `items` (`itemID`) ON DELETE CASCADE,
  ADD CONSTRAINT `itemNotes_ibfk_2` FOREIGN KEY (`sourceItemID`) REFERENCES `items` (`itemID`) ON DELETE SET NULL;

ALTER TABLE `itemRelated`
  ADD CONSTRAINT `itemRelated_ibfk_1` FOREIGN KEY (`itemID`) REFERENCES `items` (`itemID`) ON DELETE CASCADE,
  ADD CONSTRAINT `itemRelated_ibfk_2` FOREIGN KEY (`linkedItemID`) REFERENCES `items` (`itemID`) ON DELETE CASCADE;

ALTER TABLE `items`
  ADD CONSTRAINT `items_ibfk_1` FOREIGN KEY (`itemTypeID`) REFERENCES `itemTypes` (`itemTypeID`),
  ADD CONSTRAINT `items_ibfk_2` FOREIGN KEY (`libraryID`) REFERENCES `libraries` (`libraryID`) ON DELETE CASCADE;

ALTER TABLE `itemTags`
  ADD CONSTRAINT `itemTags_ibfk_1` FOREIGN KEY (`itemID`) REFERENCES `items` (`itemID`) ON DELETE CASCADE,
  ADD CONSTRAINT `itemTags_ibfk_2` FOREIGN KEY (`tagID`) REFERENCES `tags` (`tagID`) ON DELETE CASCADE;

ALTER TABLE `itemTypeCreatorTypes`
  ADD CONSTRAINT `itemTypeCreatorTypes_ibfk_1` FOREIGN KEY (`itemTypeID`) REFERENCES `itemTypes` (`itemTypeID`),
  ADD CONSTRAINT `itemTypeCreatorTypes_ibfk_2` FOREIGN KEY (`creatorTypeID`) REFERENCES `creatorTypes` (`creatorTypeID`);

ALTER TABLE `itemTypeFields`
  ADD CONSTRAINT `itemTypeFields_ibfk_1` FOREIGN KEY (`itemTypeID`) REFERENCES `itemTypes` (`itemTypeID`),
  ADD CONSTRAINT `itemTypeFields_ibfk_2` FOREIGN KEY (`fieldID`) REFERENCES `fields` (`fieldID`);

ALTER TABLE `keyPermissions`
  ADD CONSTRAINT `keyPermissions_ibfk_1` FOREIGN KEY (`keyID`) REFERENCES `keys` (`keyID`) ON DELETE CASCADE;

ALTER TABLE `relations`
  ADD CONSTRAINT `relations_ibfk_1` FOREIGN KEY (`libraryID`) REFERENCES `libraries` (`libraryID`) ON DELETE CASCADE;

ALTER TABLE `savedSearchConditions`
  ADD CONSTRAINT `savedSearchConditions_ibfk_1` FOREIGN KEY (`searchID`) REFERENCES `savedSearches` (`searchID`) ON DELETE CASCADE;

ALTER TABLE `savedSearches`
  ADD CONSTRAINT `savedSearches_ibfk_1` FOREIGN KEY (`libraryID`) REFERENCES `libraries` (`libraryID`) ON DELETE CASCADE;

ALTER TABLE `storageAccounts`
  ADD CONSTRAINT `storageAccounts_ibfk_1` FOREIGN KEY (`userID`) REFERENCES `users` (`userID`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `storageFileItems`
  ADD CONSTRAINT `storageFileItems_ibfk_1` FOREIGN KEY (`storageFileID`) REFERENCES `storageFiles` (`storageFileID`),
  ADD CONSTRAINT `storageFileItems_ibfk_2` FOREIGN KEY (`itemID`) REFERENCES `items` (`itemID`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `storageLastSync`
  ADD CONSTRAINT `storageLastSync_ibfk_1` FOREIGN KEY (`userID`) REFERENCES `users` (`userID`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `storageUploadQueue`
  ADD CONSTRAINT `storageUploadQueue_ibfk_1` FOREIGN KEY (`userID`) REFERENCES `users` (`userID`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `syncDeleteLogIDs`
  ADD CONSTRAINT `syncDeleteLogIDs_ibfk_1` FOREIGN KEY (`libraryID`) REFERENCES `libraries` (`libraryID`) ON DELETE CASCADE;

ALTER TABLE `syncDeleteLogKeys`
  ADD CONSTRAINT `syncDeleteLogKeys_ibfk_1` FOREIGN KEY (`libraryID`) REFERENCES `libraries` (`libraryID`) ON DELETE CASCADE;

ALTER TABLE `syncDownloadQueue`
  ADD CONSTRAINT `syncDownloadQueue_ibfk_1` FOREIGN KEY (`userID`) REFERENCES `users` (`userID`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `syncDownloadQueue_ibfk_2` FOREIGN KEY (`syncQueueHostID`) REFERENCES `syncQueueHosts` (`syncQueueHostID`),
  ADD CONSTRAINT `syncDownloadQueue_ibfk_3` FOREIGN KEY (`sessionID`) REFERENCES `zotero_sessions`.`sessions` (`sessionID`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `syncProcesses`
  ADD CONSTRAINT `syncProcesses_ibfk_1` FOREIGN KEY (`userID`) REFERENCES `users` (`userID`);

ALTER TABLE `syncProcessLocks`
  ADD CONSTRAINT `syncProcessLocks_ibfk_1` FOREIGN KEY (`syncProcessID`) REFERENCES `syncProcesses` (`syncProcessID`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `syncProcessLocks_ibfk_2` FOREIGN KEY (`libraryID`) REFERENCES `libraries` (`libraryID`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `syncQueue`
  ADD CONSTRAINT `syncQueue_ibfk_1` FOREIGN KEY (`userID`) REFERENCES `users` (`userID`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `syncQueue_ibfk_2` FOREIGN KEY (`syncProcessID`) REFERENCES `syncProcesses` (`syncProcessID`) ON DELETE SET NULL,
  ADD CONSTRAINT `syncQueue_ibfk_3` FOREIGN KEY (`sessionID`) REFERENCES `zotero_sessions`.`sessions` (`sessionID`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `syncQueue_ibfk_4` FOREIGN KEY (`syncQueueHostID`) REFERENCES `syncQueueHosts` (`syncQueueHostID`);

ALTER TABLE `syncQueueLocks`
  ADD CONSTRAINT `syncQueueLocks_ibfk_1` FOREIGN KEY (`syncQueueID`) REFERENCES `syncQueue` (`syncQueueID`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `syncQueueLocks_ibfk_2` FOREIGN KEY (`libraryID`) REFERENCES `libraries` (`libraryID`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `syncUploadProcessLog`
  ADD CONSTRAINT `syncUploadProcessLog_ibfk_1` FOREIGN KEY (`syncQueueHostID`) REFERENCES `syncQueueHosts` (`syncQueueHostID`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `syncUploadQueuePostWriteLog`
  ADD CONSTRAINT `syncUploadQueuePostWriteLog_ibfk_1` FOREIGN KEY (`syncUploadQueueID`) REFERENCES `syncQueue` (`syncQueueID`) ON DELETE CASCADE;

ALTER TABLE `tags`
  ADD CONSTRAINT `tags_ibfk_1` FOREIGN KEY (`tagDataID`) REFERENCES `tagData` (`tagDataID`) ON DELETE CASCADE,
  ADD CONSTRAINT `tags_ibfk_2` FOREIGN KEY (`libraryID`) REFERENCES `libraries` (`libraryID`) ON DELETE CASCADE;

ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`libraryID`) REFERENCES `libraries` (`libraryID`) ON DELETE CASCADE;
