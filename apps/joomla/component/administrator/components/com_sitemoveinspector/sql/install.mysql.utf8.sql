CREATE TABLE IF NOT EXISTS `#__sitemoveinspector_jobs` (
	`id` CHAR(36) NOT NULL,
	`user_id` BIGINT UNSIGNED NOT NULL,
	`status` VARCHAR(32) NOT NULL,
	`state_json` LONGTEXT NOT NULL,
	`report_json` LONGTEXT NULL,
	`lock_token` CHAR(64) NULL,
	`locked_until` DATETIME NULL,
	`created_at` DATETIME NOT NULL,
	`updated_at` DATETIME NOT NULL,
	`expires_at` DATETIME NOT NULL,
	PRIMARY KEY (`id`),
	UNIQUE KEY `uq_sitemoveinspector_user` (`user_id`),
	KEY `idx_sitemoveinspector_user_status` (`user_id`, `status`),
	KEY `idx_sitemoveinspector_expires_at` (`expires_at`),
	KEY `idx_sitemoveinspector_locked_until` (`locked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
