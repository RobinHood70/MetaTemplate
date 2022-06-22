-- SQL to create tables specific to MetaTemplate
-- Designed to be run from the install script or via update.php, which
-- ensure that proper variable substitution is done

-- Table that actually saves the requested data
CREATE TABLE IF NOT EXISTS mt_save_data (
  mt_save_id INT(8) UNSIGNED NOT NULL,
  mt_save_varname VARCHAR(50) NOT NULL,
  mt_save_value MEDIUMBLOB,
  mt_save_parsed BOOLEAN DEFAULT TRUE,
  PRIMARY KEY (mt_save_id , mt_save_varname),
  INDEX varvalue (mt_save_varname (10) , mt_save_value (15))
) /*$wgDBTableOptions*/;

