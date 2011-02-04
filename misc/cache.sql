CREATE TABLE IF NOT EXISTS `syncDownloadCache` (
  `hash` char(32) NOT NULL,
  `userID` int(10) unsigned NOT NULL,
  `lastsync` varchar(20) NOT NULL,
  `xmldata` longblob NOT NULL,
  `lastUsed` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
