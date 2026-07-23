-- =============================================================================
-- Sun2 production schema apply: reseller foundation + order money adjustments
-- Paste into phpMyAdmin SQL tab. Safe to re-run (IF NOT EXISTS / column checks).
--
-- BEFORE: take a DB backup / snapshot.
-- AFTER code deploy: run `php artisan permission:cache-reset` (or clear app cache)
--   so Spatie picks up role rename vendors → reseller.
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- Optional: wrap in a transaction if your phpMyAdmin/MySQL supports DDL in txns.
-- START TRANSACTION;

-- -----------------------------------------------------------------------------
-- 0) Helper: only ADD COLUMN when missing (MySQL 8+ / MariaDB)
-- -----------------------------------------------------------------------------

-- 1) Role: vendors → reseller (keeps model_has_roles links via role id)
UPDATE `roles`
SET `name` = 'reseller', `updated_at` = NOW()
WHERE `name` = 'vendors' AND `guard_name` = 'web';

INSERT INTO `roles` (`name`, `guard_name`, `created_at`, `updated_at`)
SELECT 'reseller', 'web', NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM `roles` WHERE `name` = 'reseller' AND `guard_name` = 'web'
);

-- 2) products.commission
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'commission') = 0,
    'ALTER TABLE `products` ADD COLUMN `commission` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `purchase_price`',
    'SELECT ''products.commission already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3) products.max_discount (after commission if present, else at end)
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'max_discount') = 0,
    IF(
        (SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'commission') > 0,
        'ALTER TABLE `products` ADD COLUMN `max_discount` DECIMAL(12,2) NULL DEFAULT NULL AFTER `commission`',
        'ALTER TABLE `products` ADD COLUMN `max_discount` DECIMAL(12,2) NULL DEFAULT NULL'
    ),
    'SELECT ''products.max_discount already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4) orders.reseller_id
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'reseller_id') = 0,
    'ALTER TABLE `orders` ADD COLUMN `reseller_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `user_id`, ADD CONSTRAINT `orders_reseller_id_foreign` FOREIGN KEY (`reseller_id`) REFERENCES `users` (`id`) ON DELETE SET NULL',
    'SELECT ''orders.reseller_id already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5) orders.courier_charge
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'courier_charge') = 0,
    'ALTER TABLE `orders` ADD COLUMN `courier_charge` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `charge`',
    'SELECT ''orders.courier_charge already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 6) order_products.base_price / commission_rate / commission_earned / max_discount
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_products' AND COLUMN_NAME = 'base_price') = 0,
    'ALTER TABLE `order_products` ADD COLUMN `base_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `quantity`',
    'SELECT ''order_products.base_price already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_products' AND COLUMN_NAME = 'commission_rate') = 0,
    'ALTER TABLE `order_products` ADD COLUMN `commission_rate` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `purchase_price`',
    'SELECT ''order_products.commission_rate already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_products' AND COLUMN_NAME = 'commission_earned') = 0,
    'ALTER TABLE `order_products` ADD COLUMN `commission_earned` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `commission_rate`',
    'SELECT ''order_products.commission_earned already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_products' AND COLUMN_NAME = 'max_discount') = 0,
    'ALTER TABLE `order_products` ADD COLUMN `max_discount` DECIMAL(12,2) NULL DEFAULT NULL AFTER `commission_earned`',
    'SELECT ''order_products.max_discount already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill historical base_price from unit sell price (only where still 0)
UPDATE `order_products`
SET `base_price` = `price`
WHERE `base_price` = 0 AND `price` <> 0;

-- 7) users.reseller_balance
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'reseller_balance') = 0,
    'ALTER TABLE `users` ADD COLUMN `reseller_balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `referral_balance`',
    'SELECT ''users.reseller_balance already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 8) reseller_wallet_entries
CREATE TABLE IF NOT EXISTS `reseller_wallet_entries` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `type` VARCHAR(32) NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `balance_after` DECIMAL(12,2) NOT NULL,
  `order_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `note` VARCHAR(255) NULL DEFAULT NULL,
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `reseller_wallet_entries_user_id_created_at_index` (`user_id`, `created_at`),
  KEY `reseller_wallet_entries_order_id_type_index` (`order_id`, `type`),
  CONSTRAINT `reseller_wallet_entries_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `reseller_wallet_entries_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `reseller_wallet_entries_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9) order_adjustments
CREATE TABLE IF NOT EXISTS `order_adjustments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `type` VARCHAR(16) NOT NULL,
  `label` VARCHAR(255) NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `coupon_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `source` VARCHAR(32) NOT NULL,
  `sort_order` SMALLINT NOT NULL DEFAULT 0,
  `meta` JSON NULL DEFAULT NULL,
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_adjustments_order_id_coupon_id_unique` (`order_id`, `coupon_id`),
  KEY `order_adjustments_order_id_type_index` (`order_id`, `type`),
  KEY `order_adjustments_order_id_sort_order_index` (`order_id`, `sort_order`),
  KEY `order_adjustments_coupon_id_index` (`coupon_id`),
  CONSTRAINT `order_adjustments_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `order_adjustments_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `order_adjustments_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10) order_adjustment_logs
CREATE TABLE IF NOT EXISTS `order_adjustment_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `order_adjustment_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `action` VARCHAR(32) NOT NULL,
  `type` VARCHAR(16) NULL DEFAULT NULL,
  `label` VARCHAR(255) NULL DEFAULT NULL,
  `field` VARCHAR(64) NULL DEFAULT NULL,
  `phase` VARCHAR(32) NULL DEFAULT NULL,
  `source_courier_data_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `amount_before` DECIMAL(12,2) NULL DEFAULT NULL,
  `amount_after` DECIMAL(12,2) NULL DEFAULT NULL,
  `coupon_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `meta_before` JSON NULL DEFAULT NULL,
  `meta_after` JSON NULL DEFAULT NULL,
  `order_charge_before` DECIMAL(12,2) NULL DEFAULT NULL,
  `order_charge_after` DECIMAL(12,2) NULL DEFAULT NULL,
  `order_discount_before` DECIMAL(12,2) NULL DEFAULT NULL,
  `order_discount_after` DECIMAL(12,2) NULL DEFAULT NULL,
  `order_total_before` DECIMAL(12,2) NULL DEFAULT NULL,
  `order_total_after` DECIMAL(12,2) NULL DEFAULT NULL,
  `note` TEXT NULL DEFAULT NULL,
  `actor_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `order_adjustment_logs_order_id_created_at_index` (`order_id`, `created_at`),
  KEY `order_adjustment_logs_order_adjustment_id_index` (`order_adjustment_id`),
  CONSTRAINT `order_adjustment_logs_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `order_adjustment_logs_actor_id_foreign` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11) payment_transactions extras
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_transactions' AND COLUMN_NAME = 'kind') = 0,
    'ALTER TABLE `payment_transactions` ADD COLUMN `kind` VARCHAR(32) NULL DEFAULT NULL AFTER `status`',
    'SELECT ''payment_transactions.kind already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_transactions' AND COLUMN_NAME = 'paid_at') = 0,
    'ALTER TABLE `payment_transactions` ADD COLUMN `paid_at` TIMESTAMP NULL DEFAULT NULL AFTER `kind`',
    'SELECT ''payment_transactions.paid_at already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_transactions' AND COLUMN_NAME = 'payment_method_id') = 0,
    'ALTER TABLE `payment_transactions` ADD COLUMN `payment_method_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `paid_at`, ADD CONSTRAINT `payment_transactions_payment_method_id_foreign` FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`id`) ON DELETE SET NULL',
    'SELECT ''payment_transactions.payment_method_id already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_transactions' AND COLUMN_NAME = 'external_id') = 0,
    'ALTER TABLE `payment_transactions` ADD COLUMN `external_id` VARCHAR(255) NULL DEFAULT NULL AFTER `payment_method_id`',
    'SELECT ''payment_transactions.external_id already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Unique (method, external_id) — skip if index already exists
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'payment_transactions'
       AND INDEX_NAME = 'payment_transactions_method_external_id_unique') = 0,
    'ALTER TABLE `payment_transactions` ADD UNIQUE KEY `payment_transactions_method_external_id_unique` (`method`, `external_id`)',
    'SELECT ''payment_transactions_method_external_id_unique already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 12) Seed payment_methods (unique on code)
INSERT IGNORE INTO `payment_methods` (`name`, `code`, `charge`, `is_active`, `display_order`, `created_at`, `updated_at`) VALUES
('Cash on Delivery', 'cod',   0, 1, 1, NOW(), NOW()),
('bKash',            'bkash', 0, 1, 2, NOW(), NOW()),
('Nagad',            'nagad', 0, 1, 3, NOW(), NOW()),
('Cash',             'cash',  0, 1, 4, NOW(), NOW()),
('Bank Transfer',    'bank',  0, 1, 5, NOW(), NOW());

-- 13) Backfill order_adjustments from legacy scalars (skip rows already backfilled)
INSERT INTO `order_adjustments`
    (`order_id`, `type`, `label`, `amount`, `source`, `sort_order`, `created_at`, `updated_at`)
SELECT o.`id`, 'charge', 'Charge', o.`charge`, 'backfill', 10, NOW(), NOW()
FROM `orders` o
WHERE o.`charge` > 0
  AND NOT EXISTS (
      SELECT 1 FROM `order_adjustments` oa
      WHERE oa.`order_id` = o.`id` AND oa.`type` = 'charge' AND oa.`source` = 'backfill'
  );

INSERT INTO `order_adjustments`
    (`order_id`, `type`, `label`, `amount`, `coupon_id`, `source`, `sort_order`, `created_at`, `updated_at`)
SELECT o.`id`, 'coupon', COALESCE(c.`code`, 'Coupon'), o.`discount`, o.`coupon_id`, 'backfill', 20, NOW(), NOW()
FROM `orders` o
LEFT JOIN `coupons` c ON c.`id` = o.`coupon_id`
WHERE o.`discount` > 0
  AND o.`coupon_id` IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM `order_adjustments` oa
      WHERE oa.`order_id` = o.`id` AND oa.`coupon_id` = o.`coupon_id`
  );

INSERT INTO `order_adjustments`
    (`order_id`, `type`, `label`, `amount`, `source`, `sort_order`, `created_at`, `updated_at`)
SELECT o.`id`, 'discount', 'Discount', o.`discount`, 'backfill', 20, NOW(), NOW()
FROM `orders` o
WHERE o.`discount` > 0
  AND o.`coupon_id` IS NULL
  AND NOT EXISTS (
      SELECT 1 FROM `order_adjustments` oa
      WHERE oa.`order_id` = o.`id` AND oa.`type` = 'discount' AND oa.`source` = 'backfill'
  );

-- 14) Backfill audit logs for backfilled lines (skip if already logged)
INSERT INTO `order_adjustment_logs`
    (`order_id`, `order_adjustment_id`, `action`, `type`, `label`,
     `amount_after`, `order_charge_after`, `order_discount_after`, `order_total_after`,
     `note`, `created_at`)
SELECT
    oa.`order_id`,
    oa.`id`,
    'backfilled',
    oa.`type`,
    oa.`label`,
    oa.`amount`,
    o.`charge`,
    o.`discount`,
    o.`total`,
    'Backfilled from legacy scalar data',
    NOW()
FROM `order_adjustments` oa
JOIN `orders` o ON o.`id` = oa.`order_id`
WHERE oa.`source` = 'backfill'
  AND NOT EXISTS (
      SELECT 1 FROM `order_adjustment_logs` l
      WHERE l.`order_adjustment_id` = oa.`id` AND l.`action` = 'backfilled'
  );

-- 15) Mark Laravel migrations as run (so `php artisan migrate` will not re-apply)
INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_07_23_100000_add_reseller_foundation', (SELECT IFNULL(MAX(`batch`), 0) + 1 FROM `migrations` AS m)
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations` WHERE `migration` = '2026_07_23_100000_add_reseller_foundation'
);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_07_23_110000_add_order_money_adjustments', (SELECT IFNULL(MAX(`batch`), 0) + 1 FROM `migrations` AS m)
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations` WHERE `migration` = '2026_07_23_110000_add_order_money_adjustments'
);

-- COMMIT;

-- =============================================================================
-- VERIFY (run after; expect 1 / non-zero where noted)
-- =============================================================================
-- SELECT name FROM roles WHERE name IN ('reseller','vendors');
-- SHOW COLUMNS FROM products LIKE 'commission';
-- SHOW COLUMNS FROM products LIKE 'max_discount';
-- SHOW COLUMNS FROM orders LIKE 'reseller_id';
-- SHOW COLUMNS FROM orders LIKE 'courier_charge';
-- SHOW COLUMNS FROM order_products LIKE 'base_price';
-- SHOW COLUMNS FROM users LIKE 'reseller_balance';
-- SHOW TABLES LIKE 'reseller_wallet_entries';
-- SHOW TABLES LIKE 'order_adjustments';
-- SELECT COUNT(*) AS adjustment_lines FROM order_adjustments;
-- SELECT migration FROM migrations WHERE migration LIKE '2026_07_23_%';
