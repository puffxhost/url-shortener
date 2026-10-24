-- Back up your database before running this migration.
-- Existing links continue to redirect. Older links have no browser owner token,
-- so they will not appear in a browser's private recent-links list.
-- Legacy user_ip values are retained; this app no longer displays or writes them.
ALTER TABLE `short_urls`
  MODIFY `short_code` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  ADD COLUMN `owner_token_hash` CHAR(64) NULL AFTER `short_code`,
  ADD KEY `idx_short_urls_owner_created` (`owner_token_hash`, `created_at`);
