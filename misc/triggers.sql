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

delimiter //

DROP TRIGGER IF EXISTS fki_collectionItems;//
CREATE TRIGGER fki_collectionItems
  BEFORE INSERT ON collectionItems
  FOR EACH ROW BEGIN
    -- collectionItems libraryID
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
    
    -- Child items can't be in collections
    IF (
        SELECT COUNT(*) FROM itemAttachments WHERE itemID=NEW.itemID AND sourceItemID IS NOT NULL
    )=1 OR (
        SELECT COUNT(*) FROM itemNotes WHERE itemID=NEW.itemID AND sourceItemID IS NOT NULL
    )=1 THEN
    SELECT collection_item_must_be_top_level INTO @failure FROM collectionItems;
    END IF;
  END;//


DROP TRIGGER IF EXISTS fku_collectionItems;//
CREATE TRIGGER fku_collectionItems
  BEFORE UPDATE ON collectionItems
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
  END;//

DROP TRIGGER IF EXISTS fki_itemAttachments;//
CREATE TRIGGER fki_itemAttachments
  BEFORE INSERT ON itemAttachments
  FOR EACH ROW BEGIN
    -- itemAttachments libraryID
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
    
    -- Make sure this is an attachment item
    IF ((SELECT itemTypeID FROM items WHERE itemID = NEW.itemID) != 14) THEN
    SELECT not_an_attachment INTO @failure FROM items;
    END IF;
    
    -- Make sure parent is a regular item
    IF (NEW.sourceItemID IS NOT NULL AND (SELECT itemTypeID FROM items WHERE itemID = NEW.sourceItemID) IN (1,14)) THEN
    SELECT parent_not_regular_item INTO @failure FROM items;
    END IF;
    
    -- If child, make sure attachment is not in a collection
    IF (NEW.sourceItemID IS NOT NULL AND (SELECT COUNT(*) FROM collectionItems WHERE itemID=NEW.itemID)>0) THEN
    SELECT collection_item_must_be_top_level INTO @failure FROM collectionItems;
    END IF;
  END;//

DROP TRIGGER IF EXISTS fku_itemAttachments_libraryID;//
CREATE TRIGGER fku_itemAttachments_libraryID
  BEFORE UPDATE ON itemAttachments
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
    
    -- Make sure parent is a regular item
    IF (NEW.sourceItemID IS NOT NULL AND (SELECT itemTypeID FROM items WHERE itemID = NEW.sourceItemID) IN (1,14)) THEN
    SELECT parent_not_regular_item INTO @failure FROM items;
    END IF;
    
    -- If child, make sure attachment is not in a collection
    IF (NEW.sourceItemID IS NOT NULL AND (SELECT COUNT(*) FROM collectionItems WHERE itemID=NEW.itemID)>0) THEN
    SELECT collection_item_must_be_top_level INTO @failure FROM collectionItems;
    END IF;
  END;//


-- itemCreators libraryID
DROP TRIGGER IF EXISTS fki_itemCreators_libraryID;//
CREATE TRIGGER fki_itemCreators_libraryID
  BEFORE INSERT ON itemCreators
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
  END;//

DROP TRIGGER IF EXISTS fku_itemCreators_libraryID;//
CREATE TRIGGER fku_itemCreators_libraryID
  BEFORE UPDATE ON itemCreators
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
  END;//


DROP TRIGGER IF EXISTS fki_itemNotes;//
CREATE TRIGGER fki_itemNotes
  BEFORE INSERT ON itemNotes
  FOR EACH ROW BEGIN
    -- itemNotes libraryID
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
    
    -- Make sure this is an attachment or note item
    IF ((SELECT itemTypeID FROM items WHERE itemID = NEW.itemID) NOT IN (1,14)) THEN
    SELECT not_an_attachment INTO @failure FROM items;
    END IF;
    
    -- Make sure parent is a regular item
    IF (NEW.sourceItemID IS NOT NULL AND (SELECT itemTypeID FROM items WHERE itemID = NEW.sourceItemID) IN (1,14)) THEN
    SELECT parent_not_regular_item INTO @failure FROM items;
    END IF;
    
    -- If child, make sure note is not in a collection
    IF (NEW.sourceItemID IS NOT NULL AND (SELECT COUNT(*) FROM collectionItems WHERE itemID=NEW.itemID)>0) THEN
    SELECT collection_item_must_be_top_level INTO @failure FROM collectionItems;
    END IF;
  END;//

DROP TRIGGER IF EXISTS fku_itemNotes_libraryID;//
CREATE TRIGGER fku_itemNotes_libraryID
  BEFORE UPDATE ON itemNotes
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
    
    -- Make sure parent is a regular item
    IF (NEW.sourceItemID IS NOT NULL AND (SELECT itemTypeID FROM items WHERE itemID = NEW.sourceItemID) IN (1,14)) THEN
    SELECT parent_not_regular_item INTO @failure FROM items;
    END IF;
    
    -- If child, make sure note is not in a collection
    IF (NEW.sourceItemID IS NOT NULL AND (SELECT COUNT(*) FROM collectionItems WHERE itemID=NEW.itemID)>0) THEN
    SELECT collection_item_must_be_top_level INTO @failure FROM collectionItems;
    END IF;
  END;//


-- itemRelated libraryID
DROP TRIGGER IF EXISTS fki_itemRelated_libraryID;//
CREATE TRIGGER fki_itemRelated_libraryID
  BEFORE INSERT ON itemRelated
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
  END;//

DROP TRIGGER IF EXISTS fku_itemRelated_libraryID;//
CREATE TRIGGER fku_itemRelated_libraryID
  BEFORE UPDATE ON itemRelated
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
  END;//


-- itemTags libraryID
DROP TRIGGER IF EXISTS fki_itemTags_libraryID;//
CREATE TRIGGER fki_itemTags_libraryID
  BEFORE INSERT ON itemTags
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
  END;//

DROP TRIGGER IF EXISTS fku_itemTags_libraryID;//
CREATE TRIGGER fku_itemTags_libraryID
  BEFORE UPDATE ON itemTags
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
  END;//


-- Group items can't be in trash
DROP TRIGGER IF EXISTS fki_deletedItems_libraryID;//
CREATE TRIGGER fki_deletedItems_libraryID
  BEFORE INSERT ON deletedItems
  FOR EACH ROW BEGIN
    IF (
        SELECT COUNT(*) FROM items WHERE itemID=NEW.itemID AND libraryID IN (SELECT libraryID FROM libraries WHERE libraryType='user')
    )=0 THEN
    SELECT deleted_item_must_belong_to_user_library INTO @failure FROM deletedItems;
    END IF;
  END;//

DROP TRIGGER IF EXISTS fku_deletedItems_libraryID;//
CREATE TRIGGER fku_deletedItems_libraryID
  BEFORE UPDATE ON deletedItems
  FOR EACH ROW BEGIN
    IF (
        SELECT COUNT(*) FROM items WHERE itemID=NEW.itemID AND libraryID IN (SELECT libraryID FROM libraries WHERE libraryType='user')
    )=0 THEN
    SELECT deleted_item_must_belong_to_user_library INTO @failure FROM deletedItems;
    END IF;
  END;//

delimiter ;
