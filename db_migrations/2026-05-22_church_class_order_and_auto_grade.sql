-- Grade sequence for church-specific classes + optional annual auto grade-up date.
-- Run once on production. api.php also auto-adds columns on first use.

-- 1) Explicit grade order (separate from UI display_order for future class-tab editing)
ALTER TABLE `church_classes`
  ADD COLUMN IF NOT EXISTS `order` INT NOT NULL DEFAULT 0
  COMMENT 'Grade sequence (lowest = youngest); used for promote-to-next-grade'
  AFTER `display_order`;

UPDATE `church_classes`
SET `order` = `display_order`
WHERE `order` = 0 OR `order` IS NULL;

-- 2) Scheduled auto grade-up (per church)
ALTER TABLE `church_settings`
  ADD COLUMN IF NOT EXISTS `auto_grade_month` TINYINT UNSIGNED NULL DEFAULT NULL
  COMMENT '1-12; month when annual grade-up runs',
  ADD COLUMN IF NOT EXISTS `auto_grade_day` TINYINT UNSIGNED NULL DEFAULT NULL
  COMMENT '1-31; day when annual grade-up runs',
  ADD COLUMN IF NOT EXISTS `last_auto_grade_year` SMALLINT UNSIGNED NULL DEFAULT NULL
  COMMENT 'Last calendar year auto grade-up completed';
