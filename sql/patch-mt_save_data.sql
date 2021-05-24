ALTER TABLE /*_*/mt_save_data
  MODIFY mt_save_id INT UNSIGNED NOT NULL,
  MODIFY mt_save_parsed BOOLEAN NOT NULL DEFAULT TRUE,
  MODIFY mt_save_value BLOB NOT NULL;
