-- phpMyAdmin / MySQL: Admin Inbox reply-to column
-- Safe to re-run (skips if column already exists).

SET @sun2_has_reply_to := (
    SELECT COUNT(*)
    FROM `information_schema`.`COLUMNS`
    WHERE `TABLE_SCHEMA` = DATABASE()
      AND `TABLE_NAME` = 'channel_messages'
      AND `COLUMN_NAME` = 'reply_to_message_id'
);

SET @sun2_sql := IF(
    @sun2_has_reply_to = 0,
    'ALTER TABLE `channel_messages`
        ADD COLUMN `reply_to_message_id` BIGINT UNSIGNED NULL AFTER `external_message_id`,
        ADD CONSTRAINT `channel_messages_reply_to_message_id_foreign`
            FOREIGN KEY (`reply_to_message_id`) REFERENCES `channel_messages` (`id`)
            ON DELETE SET NULL',
    'SELECT ''channel_messages.reply_to_message_id already exists'' AS info'
);

PREPARE sun2_stmt FROM @sun2_sql;
EXECUTE sun2_stmt;
DEALLOCATE PREPARE sun2_stmt;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_07_27_083000_add_reply_to_message_id_to_channel_messages_table', (SELECT IFNULL(MAX(`batch`), 0) + 1 FROM `migrations` AS m)
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations` WHERE `migration` = '2026_07_27_083000_add_reply_to_message_id_to_channel_messages_table'
);

SELECT 'OK: reply_to_message_id ready.' AS result;
