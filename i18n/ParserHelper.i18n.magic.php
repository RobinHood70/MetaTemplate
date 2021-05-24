<?php
if (!isset($magicWords['en']))
	throw new RuntimeException('Magic words not initialized!');

$magicWords['en'] += [
	ParserHelper::AV_ANY => [0, 'any'],
	ParserHelper::AV_ALWAYS => [0, 'always'],
	ParserHelper::NA_CASE => [0, 'case'],
	ParserHelper::NA_IF => [0, 'if'],
	ParserHelper::NA_IFNOT => [0, 'ifnot'],
	ParserHelper::NA_NSBASE => [0, 'ns_base'],
	ParserHelper::NA_NSID => [0, 'ns_id'],
];
