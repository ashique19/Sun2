-- SQL for creating admin_attention_items table
-- Migration: 2026_07_26_165210_create_admin_attention_items_table.php

CREATE TABLE `admin_attention_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint unsigned DEFAULT NULL,
  `issue_type` varchar(255) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `data` json DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `resolved_by` bigint unsigned DEFAULT NULL,
  `resolution_notes` text,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `admin_attention_items_order_id_foreign` (`order_id`),
  KEY `admin_attention_items_issue_type_index` (`issue_type`),
  KEY `admin_attention_items_issue_type_resolved_at_index` (`issue_type`,`resolved_at`),
  KEY `admin_attention_items_order_id_resolved_at_index` (`order_id`,`resolved_at`),
  KEY `admin_attention_items_resolved_by_foreign` (`resolved_by`),
  CONSTRAINT `admin_attention_items_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `admin_attention_items_resolved_by_foreign` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sample data for issue types (for reference)
-- These are the constants defined in AdminAttentionItem model:
-- 'cod_mismatch'      - COD amount discrepancies
-- 'address_validation' - Address issues  
-- 'payment_discrepancy' - Payment problems
-- 'system_alert'      - General system alerts
-- 'other'             - Miscellaneous issues

-- Example of how a COD mismatch would be recorded:
-- INSERT INTO admin_attention_items (order_id, issue_type, title, description, data, created_at)
-- VALUES (
--   123, 
--   'cod_mismatch',
--   'COD Mismatch - Order #ORD-12345',
--   'COD is ৳500 but collected ৳300 at courier',
--   '{"expected_amount": 500, "collected_amount": 300, "discrepancy": 200, "order_number": "ORD-12345", "source": "steadfast_webhook"}',
--   NOW()
-- );

-- Notes:
-- 1. The table supports multiple issue types for future expansion
-- 2. JSON data field stores issue-specific metadata
-- 3. Indexes are optimized for common query patterns (filtering by issue_type, order_id, resolved status)
-- 4. Foreign keys ensure data integrity with orders and users tables
-- 5. The table is designed for extensibility with new issue types