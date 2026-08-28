-- =============================================================================
-- Sun2 production schema apply: 2026-08-27 match memory + inbound cache + embedding
-- Paste into phpMyAdmin SQL tab. Safe to re-run (information_schema checks).
--
-- Covers migrations:
--   2026_08_27_204617_add_inbound_media_cache_to_channel_messages_table
--   2026_08_27_211546_create_product_image_match_memories_table
--   2026_08_27_212000_add_embedding_vector_to_product_images_table
--
-- BEFORE: take a DB backup / snapshot.
-- AFTER code deploy:
--   php artisan products:index-image-hashes --force
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- -----------------------------------------------------------------------------
-- 1) Inbound screenshot cache (channel_messages)
--    Note: matched_product_id may already sit after media_mime; new columns
--    are inserted immediately after media_mime (before matched_product_id).
-- -----------------------------------------------------------------------------
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'channel_messages'
       AND COLUMN_NAME = 'media_path') = 0,
    'ALTER TABLE `channel_messages` ADD COLUMN `media_path` VARCHAR(500) NULL AFTER `media_mime`',
    'SELECT ''channel_messages.media_path already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'channel_messages'
       AND COLUMN_NAME = 'media_dhash') = 0,
    'ALTER TABLE `channel_messages` ADD COLUMN `media_dhash` VARCHAR(16) NULL AFTER `media_path`',
    'SELECT ''channel_messages.media_dhash already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'channel_messages'
       AND COLUMN_NAME = 'media_dct_hash') = 0,
    'ALTER TABLE `channel_messages` ADD COLUMN `media_dct_hash` VARCHAR(16) NULL AFTER `media_dhash`',
    'SELECT ''channel_messages.media_dct_hash already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 2) Catalog DCT pHash (product_images.dct_hash)
--    AFTER perceptual_hashes when present, else perceptual_hash, else path.
-- -----------------------------------------------------------------------------
SET @sun2_dct_after := (
    SELECT CASE
        WHEN EXISTS (
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'product_images'
              AND COLUMN_NAME = 'perceptual_hashes'
        ) THEN 'perceptual_hashes'
        WHEN EXISTS (
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'product_images'
              AND COLUMN_NAME = 'perceptual_hash'
        ) THEN 'perceptual_hash'
        ELSE 'path'
    END
);

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'product_images'
       AND COLUMN_NAME = 'dct_hash') = 0,
    CONCAT('ALTER TABLE `product_images` ADD COLUMN `dct_hash` VARCHAR(16) NULL AFTER `', @sun2_dct_after, '`'),
    'SELECT ''product_images.dct_hash already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'product_images'
       AND INDEX_NAME = 'product_images_dct_hash_index') = 0,
    'ALTER TABLE `product_images` ADD INDEX `product_images_dct_hash_index` (`dct_hash`)',
    'SELECT ''product_images_dct_hash_index already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 3) Staff correction memory (exact inbound hash → product)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `product_image_match_memories` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `hash` VARCHAR(16) NOT NULL,
  `hash_kind` VARCHAR(8) NOT NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `source_channel_message_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `hit_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_hit_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_image_match_memories_hash_hash_kind_unique` (`hash`, `hash_kind`),
  KEY `product_image_match_memories_product_id_foreign` (`product_id`),
  CONSTRAINT `product_image_match_memories_product_id_foreign`
    FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `product_image_match_memories_source_channel_message_id_foreign`
    FOREIGN KEY (`source_channel_message_id`) REFERENCES `channel_messages` (`id`) ON DELETE SET NULL,
  CONSTRAINT `product_image_match_memories_created_by_foreign`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4) GD embedding fallback index (product_images.embedding_vector)
-- -----------------------------------------------------------------------------
SET @sun2_embed_after := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'product_images'
       AND COLUMN_NAME = 'dct_hash') > 0,
    'dct_hash',
    @sun2_dct_after
);

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'product_images'
       AND COLUMN_NAME = 'embedding_vector') = 0,
    CONCAT('ALTER TABLE `product_images` ADD COLUMN `embedding_vector` JSON NULL AFTER `', @sun2_embed_after, '`'),
    'SELECT ''product_images.embedding_vector already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 5) Mark Laravel migrations applied (skip if you run `php artisan migrate --force`)
-- -----------------------------------------------------------------------------
INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_08_27_204617_add_inbound_media_cache_to_channel_messages_table', (SELECT IFNULL(MAX(`batch`), 0) + 1 FROM `migrations` AS m)
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations` WHERE `migration` = '2026_08_27_204617_add_inbound_media_cache_to_channel_messages_table'
);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_08_27_211546_create_product_image_match_memories_table', (SELECT IFNULL(MAX(`batch`), 0) + 1 FROM `migrations` AS m)
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations` WHERE `migration` = '2026_08_27_211546_create_product_image_match_memories_table'
);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_08_27_212000_add_embedding_vector_to_product_images_table', (SELECT IFNULL(MAX(`batch`), 0) + 1 FROM `migrations` AS m)
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations` WHERE `migration` = '2026_08_27_212000_add_embedding_vector_to_product_images_table'
);

SELECT 'OK: 2026-08-27 match-memory schema applied (or already present).' AS result;

-- =============================================================================
-- RECOVERY: if you already ran the raw step-1 channel_messages ALTER manually,
-- skip section 1 above (or run this whole file — step 1 no-ops safely).
--
-- Step 2 fails with "Unknown column 'perceptual_hashes'" when production only
-- has perceptual_hash (migration 2026_08_25 not applied yet). Use AFTER below:
--
--   ALTER TABLE `product_images`
--     ADD COLUMN `dct_hash` VARCHAR(16) NULL AFTER `perceptual_hash`,
--     ADD INDEX `product_images_dct_hash_index` (`dct_hash`);
--
-- Or run sections 2–5 of this file only (from "Catalog DCT pHash" downward).
-- =============================================================================
