-- SQL to create tables specific to MetaTemplate
-- Designed to be run from the install script or via update.php, which
-- ensure that proper variable substitution is done

-- Table providing the save_set number corresponding to a given page and/or rev_id
-- timestamp also saved to help identify out-of-date data
CREATE TABLE IF NOT EXISTS /*_*/mt_save_set (
  mt_set_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  mt_set_page_id INT UNSIGNED NOT NULL,
  mt_set_subset VARCHAR(50) NOT NULL DEFAULT '',
  mt_set_rev_id INT UNSIGNED NOT NULL,
  INDEX (mt_set_page_id),
  INDEX (mt_set_subset),
  INDEX (mt_set_rev_id)
) /*$wgDBTableOptions*/;