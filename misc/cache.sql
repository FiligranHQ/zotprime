CREATE TABLE IF NOT EXISTS `syncDownloadCache` (
  `hash` char(32) NOT NULL,
  `userID` int(10) unsigned NOT NULL,
  `lastsync` varchar(20) NOT NULL,
  `xmldata` longblob NOT NULL,
  `lastUsed` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE EVENT purgeCache ON SCHEDULE EVERY 24 HOUR DO DELETE FROM syncDownloadCache WHERE lastUsed<NOW() - INTERVAL 2 WEEK;
