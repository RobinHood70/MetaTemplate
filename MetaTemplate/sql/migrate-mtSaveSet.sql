-- SQL query to copy data from the MetaTemplate v1 table to the MetaTemplate v2 table.

-- The inner join enforces data consistency, as there are some invalid records floating around in the old tables.

INSERT INTO /*_*/mtSaveSet (
	pageId,
	setName,
	revId,
	setId)
SELECT
	mt_set_page_id,
	mt_set_subset,
	mt_set_rev_id,
	mt_set_id
FROM
	mt_save_set
		INNER JOIN
	page ON mt_save_set.mt_set_page_id = page.page_id;
