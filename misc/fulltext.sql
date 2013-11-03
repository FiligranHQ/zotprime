CREATE TABLE IF NOT EXISTS `fulltextContent` (
  `libraryID` int(10) unsigned NOT NULL,
  `key` char(8) CHARACTER SET ascii NOT NULL,
  `content` mediumtext NOT NULL,
  `indexedChars` mediumint(8) unsigned NOT NULL DEFAULT '0',
  `totalChars` mediumint(8) unsigned NOT NULL DEFAULT '0',
  `indexedPages` smallint(5) unsigned NOT NULL DEFAULT '0',
  `totalPages` smallint(5) unsigned NOT NULL DEFAULT '0',
  `version` int(10) unsigned NOT NULL DEFAULT '0',
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`libraryID`,`key`),
  FULLTEXT KEY `content` (`content`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
