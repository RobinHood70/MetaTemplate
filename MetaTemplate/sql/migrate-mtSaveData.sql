-- SQL query to copy data from the MetaTemplate v1 table to the MetaTemplate v2 table.

-- The inner joins enforce data consistency, as there are some invalid records floating around in the old tables.

INSERT INTO /*_*/mtSaveData (
	setId,
	varName,
	varValue)
SELECT
	mt_save_id,
	mt_save_varname,
	mt_save_value
FROM
	mt_save_data
		JOIN
	mt_save_set ON mt_save_data.mt_save_id = mt_save_set.mt_set_id
		JOIN
	page ON mt_save_set.mt_set_page_id = page.page_id;