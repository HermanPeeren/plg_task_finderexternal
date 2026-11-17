CREATE TABLE IF NOT EXISTS `#__finderexternal_urls` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `task_id` int unsigned NOT NULL,
  `url` varchar(2048) NOT NULL,
  `url_hash` char(40) NOT NULL,
  `depth` smallint unsigned NOT NULL DEFAULT 0,
  `in_sitemap` tinyint unsigned NOT NULL DEFAULT 0,
  `seen_cycle` int unsigned NOT NULL DEFAULT 0,
  `checked_cycle` int unsigned NOT NULL DEFAULT 0,
  `status` varchar(20) NOT NULL DEFAULT 'new',
  `link_id` int unsigned NOT NULL DEFAULT 0,
  `content_hash` char(40) NOT NULL DEFAULT '',
  `etag` varchar(255) NOT NULL DEFAULT '',
  `last_modified` varchar(64) NOT NULL DEFAULT '',
  `last_checked` datetime NULL DEFAULT NULL,
  `message` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_task_url` (`task_id`, `url_hash`),
  KEY `idx_task_due` (`task_id`, `checked_cycle`, `depth`),
  KEY `idx_link` (`link_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__finderexternal_cycles` (
  `task_id` int unsigned NOT NULL,
  `cycle` int unsigned NOT NULL DEFAULT 0,
  `phase` varchar(20) NOT NULL DEFAULT 'discover',
  `state` mediumtext NOT NULL,
  `started` datetime NOT NULL,
  `finished` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
