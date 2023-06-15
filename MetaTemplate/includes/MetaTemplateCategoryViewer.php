<?php

if (version_compare(VersionHelper::getMWVersion(), '1.37', '>=')) {
	require_once(__DIR__ . '/MetaTemplateCategoryViewer37.php');
} elseif (version_compare(VersionHelper::getMWVersion(), '1.28', '>=')) {
	require_once(__DIR__ . '/MetaTemplateCategoryViewer28.php');
} else {
	throw new Exception('MediaWiki version could not be found or is too low.');
}
