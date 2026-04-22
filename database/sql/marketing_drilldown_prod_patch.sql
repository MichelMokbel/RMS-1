-- Marketing reporting drill-down patch.
-- Run once on production after the base marketing module tables already exist.

ALTER TABLE `marketing_spend_snapshots`
    ADD COLUMN `ad_id` BIGINT UNSIGNED NULL AFTER `ad_set_id`,
    ADD COLUMN `reach` BIGINT NOT NULL DEFAULT 0 AFTER `impressions`;

ALTER TABLE `marketing_spend_snapshots`
    ADD INDEX `marketing_spend_ad_date_index` (`ad_id`, `snapshot_date`);

ALTER TABLE `marketing_spend_snapshots`
    ADD CONSTRAINT `marketing_spend_snapshots_ad_id_foreign`
    FOREIGN KEY (`ad_id`) REFERENCES `marketing_ads` (`id`)
    ON DELETE SET NULL;
