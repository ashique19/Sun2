-- =============================================================================
-- Sun2 production schema apply: 2026-07-27 feature set
-- Paste into phpMyAdmin SQL tab. Safe to re-run (IF NOT EXISTS / column checks).
--
-- Covers:
--   1) ai_image_prompts
--   2) channel_conversations.last_read_at / last_read_by  (Admin Inbox unread)
--   3) products.priced_image_path / priced_image_layout
--   4) social_posts + social_post_products + social_post_publications
--
-- BEFORE: take a DB backup / snapshot.
-- AFTER code deploy (optional):
--   php artisan migrate --force
--   (or insert migration rows below if you track migrations manually)
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- -----------------------------------------------------------------------------
-- 1) ai_image_prompts
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ai_image_prompts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `prompt` TEXT NOT NULL,
  `use_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_used_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ai_image_prompts_last_used_at_index` (`last_used_at`),
  KEY `ai_image_prompts_user_id_last_used_at_index` (`user_id`, `last_used_at`),
  CONSTRAINT `ai_image_prompts_user_id_foreign`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2) channel_conversations unread tracking
-- -----------------------------------------------------------------------------
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'channel_conversations'
       AND COLUMN_NAME = 'last_read_at') = 0,
    'ALTER TABLE `channel_conversations` ADD COLUMN `last_read_at` TIMESTAMP NULL DEFAULT NULL AFTER `last_outbound_at`',
    'SELECT ''channel_conversations.last_read_at already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'channel_conversations'
       AND COLUMN_NAME = 'last_read_by') = 0,
    'ALTER TABLE `channel_conversations` ADD COLUMN `last_read_by` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `last_read_at`, ADD CONSTRAINT `channel_conversations_last_read_by_foreign` FOREIGN KEY (`last_read_by`) REFERENCES `users` (`id`) ON DELETE SET NULL',
    'SELECT ''channel_conversations.last_read_by already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'channel_conversations'
       AND INDEX_NAME = 'channel_conversations_read_inbound_index') = 0,
    'ALTER TABLE `channel_conversations` ADD INDEX `channel_conversations_read_inbound_index` (`last_read_at`, `last_inbound_at`)',
    'SELECT ''channel_conversations_read_inbound_index already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 3) products priced-image fields
-- -----------------------------------------------------------------------------
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'products'
       AND COLUMN_NAME = 'priced_image_path') = 0,
    'ALTER TABLE `products` ADD COLUMN `priced_image_path` VARCHAR(255) NULL DEFAULT NULL AFTER `compare_at_price`',
    'SELECT ''products.priced_image_path already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'products'
       AND COLUMN_NAME = 'priced_image_layout') = 0,
    'ALTER TABLE `products` ADD COLUMN `priced_image_layout` JSON NULL AFTER `priced_image_path`',
    'SELECT ''products.priced_image_layout already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 4) social posts tables
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `social_posts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `body` LONGTEXT NOT NULL,
  `image_source` VARCHAR(20) NOT NULL DEFAULT 'thumb',
  `layout` VARCHAR(20) NOT NULL DEFAULT 'album',
  `thumbnail_path` VARCHAR(255) NULL DEFAULT NULL,
  `collage_path` VARCHAR(255) NULL DEFAULT NULL,
  `status` VARCHAR(30) NOT NULL DEFAULT 'draft',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_social_posts_latest` (`status`, `created_at`),
  CONSTRAINT `social_posts_created_by_foreign`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `social_post_products` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `social_post_id` BIGINT UNSIGNED NOT NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `sort_order` SMALLINT NOT NULL DEFAULT 0,
  `thumb_snapshot_path` VARCHAR(255) NULL DEFAULT NULL,
  `priced_snapshot_path` VARCHAR(255) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `social_post_products_social_post_id_product_id_unique` (`social_post_id`, `product_id`),
  KEY `idx_social_post_products_order` (`social_post_id`, `sort_order`),
  CONSTRAINT `social_post_products_social_post_id_foreign`
    FOREIGN KEY (`social_post_id`) REFERENCES `social_posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `social_post_products_product_id_foreign`
    FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `social_post_publications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `social_post_id` BIGINT UNSIGNED NOT NULL,
  `channel` VARCHAR(20) NOT NULL,
  `external_id` VARCHAR(128) NULL DEFAULT NULL,
  `external_url` TEXT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `error` TEXT NULL,
  `published_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_social_post_pubs` (`social_post_id`, `channel`, `published_at`),
  CONSTRAINT `social_post_publications_social_post_id_foreign`
    FOREIGN KEY (`social_post_id`) REFERENCES `social_posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Optional: mark Laravel migrations as run (only if you use the migrations table
-- and will NOT also run `php artisan migrate` for these batches).
-- Safe to re-run (skips existing rows).
-- -----------------------------------------------------------------------------
INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_07_27_062112_create_ai_image_prompts_table', (SELECT IFNULL(MAX(`batch`), 0) + 1 FROM `migrations` AS m)
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations` WHERE `migration` = '2026_07_27_062112_create_ai_image_prompts_table'
);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_07_27_070500_add_last_read_to_channel_conversations_table', (SELECT IFNULL(MAX(`batch`), 0) + 1 FROM `migrations` AS m)
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations` WHERE `migration` = '2026_07_27_070500_add_last_read_to_channel_conversations_table'
);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_07_27_072000_add_priced_image_fields_to_products_table', (SELECT IFNULL(MAX(`batch`), 0) + 1 FROM `migrations` AS m)
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations` WHERE `migration` = '2026_07_27_072000_add_priced_image_fields_to_products_table'
);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_07_27_073500_create_social_posts_table', (SELECT IFNULL(MAX(`batch`), 0) + 1 FROM `migrations` AS m)
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations` WHERE `migration` = '2026_07_27_073500_create_social_posts_table'
);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_07_27_073501_create_social_post_products_table', (SELECT IFNULL(MAX(`batch`), 0) + 1 FROM `migrations` AS m)
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations` WHERE `migration` = '2026_07_27_073501_create_social_post_products_table'
);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_07_27_073502_create_social_post_publications_table', (SELECT IFNULL(MAX(`batch`), 0) + 1 FROM `migrations` AS m)
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations` WHERE `migration` = '2026_07_27_073502_create_social_post_publications_table'
);

SELECT 'OK: 2026-07-27 feature schema applied (or already present).' AS result;
