CREATE TABLE `syncDownloadCache_0` (
  `hash` char(32) NOT NULL,
  `userID` int(10) unsigned NOT NULL,
  `lastsync` varchar(20) NOT NULL,
  `xmldata` longblob NOT NULL,
  `lastUsed` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`hash`),
  KEY `lastUsed` (`lastUsed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `syncDownloadCache_1` (
  `hash` char(32) NOT NULL,
  `userID` int(10) unsigned NOT NULL,
  `lastsync` varchar(20) NOT NULL,
  `xmldata` longblob NOT NULL,
  `lastUsed` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`hash`),
  KEY `lastUsed` (`lastUsed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `syncDownloadCache_2` (
  `hash` char(32) NOT NULL,
  `userID` int(10) unsigned NOT NULL,
  `lastsync` varchar(20) NOT NULL,
  `xmldata` longblob NOT NULL,
  `lastUsed` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`hash`),
  KEY `lastUsed` (`lastUsed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `syncDownloadCache_3` (
  `hash` char(32) NOT NULL,
  `userID` int(10) unsigned NOT NULL,
  `lastsync` varchar(20) NOT NULL,
  `xmldata` longblob NOT NULL,
  `lastUsed` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`hash`),
  KEY `lastUsed` (`lastUsed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `syncDownloadCache_4` (
  `hash` char(32) NOT NULL,
  `userID` int(10) unsigned NOT NULL,
  `lastsync` varchar(20) NOT NULL,
  `xmldata` longblob NOT NULL,
  `lastUsed` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`hash`),
  KEY `lastUsed` (`lastUsed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `syncDownloadCache_5` (
  `hash` char(32) NOT NULL,
  `userID` int(10) unsigned NOT NULL,
  `lastsync` varchar(20) NOT NULL,
  `xmldata` longblob NOT NULL,
  `lastUsed` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`hash`),
  KEY `lastUsed` (`lastUsed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `syncDownloadCache_6` (
  `hash` char(32) NOT NULL,
  `userID` int(10) unsigned NOT NULL,
  `lastsync` varchar(20) NOT NULL,
  `xmldata` longblob NOT NULL,
  `lastUsed` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`hash`),
  KEY `lastUsed` (`lastUsed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `syncDownloadCache_7` (
  `hash` char(32) NOT NULL,
  `userID` int(10) unsigned NOT NULL,
  `lastsync` varchar(20) NOT NULL,
  `xmldata` longblob NOT NULL,
  `lastUsed` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`hash`),
  KEY `lastUsed` (`lastUsed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `syncDownloadCache_8` (
  `hash` char(32) NOT NULL,
  `userID` int(10) unsigned NOT NULL,
  `lastsync` varchar(20) NOT NULL,
  `xmldata` longblob NOT NULL,
  `lastUsed` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`hash`),
  KEY `lastUsed` (`lastUsed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `syncDownloadCache_9` (
  `hash` char(32) NOT NULL,
  `userID` int(10) unsigned NOT NULL,
  `lastsync` varchar(20) NOT NULL,
  `xmldata` longblob NOT NULL,
  `lastUsed` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`hash`),
  KEY `lastUsed` (`lastUsed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `syncDownloadCache_a` (
  `hash` char(32) NOT NULL,
  `userID` int(10) unsigned NOT NULL,
  `lastsync` varchar(20) NOT NULL,
  `xmldata` longblob NOT NULL,
  `lastUsed` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`hash`),
  KEY `lastUsed` (`lastUsed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `syncDownloadCache_b` (
  `hash` char(32) NOT NULL,
  `userID` int(10) unsigned NOT NULL,
  `lastsync` varchar(20) NOT NULL,
  `xmldata` longblob NOT NULL,
  `lastUsed` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`hash`),
  KEY `lastUsed` (`lastUsed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `syncDownloadCache_c` (
  `hash` char(32) NOT NULL,
  `userID` int(10) unsigned NOT NULL,
  `lastsync` varchar(20) NOT NULL,
  `xmldata` longblob NOT NULL,
  `lastUsed` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`hash`),
  KEY `lastUsed` (`lastUsed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `syncDownloadCache_d` (
  `hash` char(32) NOT NULL,
  `userID` int(10) unsigned NOT NULL,
  `lastsync` varchar(20) NOT NULL,
  `xmldata` longblob NOT NULL,
  `lastUsed` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`hash`),
  KEY `lastUsed` (`lastUsed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `syncDownloadCache_e` (
  `hash` char(32) NOT NULL,
  `userID` int(10) unsigned NOT NULL,
  `lastsync` varchar(20) NOT NULL,
  `xmldata` longblob NOT NULL,
  `lastUsed` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`hash`),
  KEY `lastUsed` (`lastUsed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `syncDownloadCache_f` (
  `hash` char(32) NOT NULL,
  `userID` int(10) unsigned NOT NULL,
  `lastsync` varchar(20) NOT NULL,
  `xmldata` longblob NOT NULL,
  `lastUsed` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`hash`),
  KEY `lastUsed` (`lastUsed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


DELIMITER |
CREATE EVENT purgeCache ON SCHEDULE EVERY 8 HOUR DO
    BEGIN
        DELETE FROM syncDownloadCache_0 WHERE lastUsed<NOW() - INTERVAL 2 WEEK;
        DELETE FROM syncDownloadCache_1 WHERE lastUsed<NOW() - INTERVAL 2 WEEK;
        DELETE FROM syncDownloadCache_2 WHERE lastUsed<NOW() - INTERVAL 2 WEEK;
        DELETE FROM syncDownloadCache_3 WHERE lastUsed<NOW() - INTERVAL 2 WEEK;
        DELETE FROM syncDownloadCache_4 WHERE lastUsed<NOW() - INTERVAL 2 WEEK;
        DELETE FROM syncDownloadCache_5 WHERE lastUsed<NOW() - INTERVAL 2 WEEK;
        DELETE FROM syncDownloadCache_6 WHERE lastUsed<NOW() - INTERVAL 2 WEEK;
        DELETE FROM syncDownloadCache_7 WHERE lastUsed<NOW() - INTERVAL 2 WEEK;
        DELETE FROM syncDownloadCache_8 WHERE lastUsed<NOW() - INTERVAL 2 WEEK;
        DELETE FROM syncDownloadCache_9 WHERE lastUsed<NOW() - INTERVAL 2 WEEK;
        DELETE FROM syncDownloadCache_a WHERE lastUsed<NOW() - INTERVAL 2 WEEK;
        DELETE FROM syncDownloadCache_b WHERE lastUsed<NOW() - INTERVAL 2 WEEK;
        DELETE FROM syncDownloadCache_c WHERE lastUsed<NOW() - INTERVAL 2 WEEK;
        DELETE FROM syncDownloadCache_d WHERE lastUsed<NOW() - INTERVAL 2 WEEK;
        DELETE FROM syncDownloadCache_e WHERE lastUsed<NOW() - INTERVAL 2 WEEK;
        DELETE FROM syncDownloadCache_f WHERE lastUsed<NOW() - INTERVAL 2 WEEK;
   END |
DELIMITER ;
