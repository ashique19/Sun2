-- =============================================================================
-- Sun2 production schema apply: 2026-10-11 visual inbox screenshot matching
-- Paste into phpMyAdmin SQL tab. Safe to re-run (column checks).
--
-- Covers:
--   1) channel_messages.image_match_checked_at  (from PR #332, included for safety)
--   2) channel_messages.image_match_meta        (stored candidates, rejections)
--   3) product_images.match_signature           (catalog visual signatures)
--   4) product_image_match_memories.signature   (signatures learned from staff tags)
--
-- BEFORE: take a DB backup / snapshot.
-- AFTER code deploy:
--   php artisan migrate --force   (or insert the migration rows below)
--   php artisan products:index-image-hashes
--     Signs every catalog image once (also done lazily by the matcher, a few
--     seconds per request, if this step is skipped).
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- -----------------------------------------------------------------------------
-- 1) channel_messages.image_match_checked_at
-- -----------------------------------------------------------------------------
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'channel_messages'
       AND COLUMN_NAME = 'image_match_checked_at') = 0,
    'ALTER TABLE `channel_messages` ADD COLUMN `image_match_checked_at` TIMESTAMP NULL DEFAULT NULL AFTER `matched_product_id`',
    'SELECT ''channel_messages.image_match_checked_at already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 2) channel_messages.image_match_meta
-- -----------------------------------------------------------------------------
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'channel_messages'
       AND COLUMN_NAME = 'image_match_meta') = 0,
    'ALTER TABLE `channel_messages` ADD COLUMN `image_match_meta` JSON NULL DEFAULT NULL AFTER `image_match_checked_at`',
    'SELECT ''channel_messages.image_match_meta already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 3) product_images.match_signature
-- -----------------------------------------------------------------------------
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'product_images'
       AND COLUMN_NAME = 'match_signature') = 0,
    'ALTER TABLE `product_images` ADD COLUMN `match_signature` TEXT NULL DEFAULT NULL AFTER `embedding_vector`',
    'SELECT ''product_images.match_signature already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 4) product_image_match_memories.signature
-- -----------------------------------------------------------------------------
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'product_image_match_memories'
       AND COLUMN_NAME = 'signature') = 0,
    'ALTER TABLE `product_image_match_memories` ADD COLUMN `signature` TEXT NULL DEFAULT NULL AFTER `hash_kind`',
    'SELECT ''product_image_match_memories.signature already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 5) Mark Laravel migrations applied (skip if you run `php artisan migrate --force`)
-- -----------------------------------------------------------------------------
INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_10_11_165438_add_image_match_checked_at_to_channel_messages_table', (SELECT IFNULL(MAX(`batch`), 0) + 1 FROM `migrations` AS m)
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations` WHERE `migration` = '2026_10_11_165438_add_image_match_checked_at_to_channel_messages_table'
);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_10_11_172343_add_visual_image_match_columns', (SELECT IFNULL(MAX(`batch`), 0) + 1 FROM `migrations` AS m)
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations` WHERE `migration` = '2026_10_11_172343_add_visual_image_match_columns'
);

SELECT 'OK: 2026-10-11 visual image match schema applied (or already present).' AS result;
