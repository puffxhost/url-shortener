CREATE TABLE IF NOT EXISTS `short_urls` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `long_url` VARCHAR(500) NOT NULL,
  `short_code` VARCHAR(32) NOT NULL,
  `owner_token_hash` CHAR(64) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME NULL DEFAULT NULL,
  `click_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_short_urls_code` (`short_code`),
  KEY `idx_short_urls_owner_created` (`owner_token_hash`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
