<?php

use Wikimedia\Rdbms\IResultWrapper;

/* In theory, this process could be optimized further by subdividing <catpagetemplate> into a section for pages and a
 * section for sets so that only the set portion is parsed inside the loop at the end of processTemplate(). Given the
 * syntax changes already being introduced in this version and the extra level of user knowledge that a pages/sets
 * style would require, I don't think it's especially useful.
 */

/**
 * This class wraps around the base CategoryViewer class to provide MetaTemplate's custom capabilities like altering
 * the title and showing set names on a page.
 */
class MetaTemplateCategoryViewer extends CategoryViewer
{
	#region Public Constants
	// CategoryViewer does not define these despite wide-spread internal usage in later versions, so we do. If that
	// changes in the future, these can be removed and the code altered, or they can be made synonyms for the CV names.
	public const CV_FILE = 'file';
	public const CV_PAGE = 'page';
	public const CV_SUBCAT = 'subcat';

	public const NA_IMAGE = 'metatemplate-image';
	public const NA_PAGE = 'metatemplate-page';
	public const NA_PAGELENGTH = 'metatemplate-pagelength';
	public const NA_SORTKEY = 'metatemplate-sortkey';
	public const NA_SUBCAT = 'metatemplate-subcat';

	public const TG_CATPAGETEMPLATE = 'metatemplate-catpagetemplate';
	#endregion

	#region Private Constants
	/**
	 * Key for the value to store catpagetemplate data in for browser refresh.
	 *
	 * @var string ([PPFrame $frame, ?string[] $templates])
	 */
	private const KEY_TEMPLATES = MetaTemplate::KEY_METATEMPLATE . '#cptTemplates';
	#endregion

	#region Private Static Varables
	/** @var Language */
	private static $contLang = null;

	/** @var ?PPFrame_Hash */
	private static $frame = null;

	/** @var ?string[] */
	private static $mwPageLength = null;

	/** @var ?string[] */
	private static $mwSortKey = null;

	/** @var ?string[] */
	private static $templates = null; // Must be null for proper init on refresh
	#endregion

	#region Public Static Functions
	/**
	 * Creates an inline template to use with the different types of category entries.
	 *
	 * @param string $content The content of the tag.
	 * @param array $attributes The tag attributes.
	 * @param Parser $parser The parser in use.
	 * @param PPFrame $frame The frame in use.
	 */
	public static function doCatPageTemplate(string $content, array $attributes, Parser $parser, PPFrame $frame = NULL): string
	{
		if ($parser->getTitle()->getNamespace() !== NS_CATEGORY || !strlen(trim($content))) {
			return '';
		}

		// The parser cache doesn't store our custom category data nor allow us to parse it in any way short of caching
		// the entire parser and bringing it back in parseCatPageTemplate(), which seems inadvisable. Caching later in
		// the process also isn't an option as the cache is already saved by then. Short of custom parser caching,
		// which is probably not advisable unless/until I understand the full dynamics of category generation, the only
		// option is to disable the cache for any page with <catpagetemplate> on it.
		$output = $parser->getOutput();
		$output->updateCacheExpiry(0);
		self::$frame = $frame;

		static $magicWords;
		$magicWords = $magicWords ?? new MagicWordArray([
			self::NA_IMAGE,
			self::NA_PAGE,
			self::NA_SUBCAT
		]);

		$attributes = ParserHelper::transformAttributes($attributes, $magicWords);
		$none = !isset($attributes[self::NA_IMAGE]) && !isset($attributes[self::NA_PAGE]) && !isset($attributes[self::NA_SUBCAT]);
		if (isset($attributes[self::NA_IMAGE]) || $none) {
			self::$templates[self::CV_FILE] = $content;
		}

		if (isset($attributes[self::NA_PAGE]) || $none) {
			self::$templates[self::CV_PAGE] = $content;
		}

		if (isset($attributes[self::NA_SUBCAT]) || $none) {
			self::$templates[self::CV_SUBCAT] = $content;
		}

		// We don't care about the results, just that any #preload gets parsed. Transferring the ignore_set option via
		// the parser output seemed like a better choice than doing it via a static, in the event that there's somehow
		// more than one parser active.
		$output->setExtensionData(MetaTemplateData::KEY_IGNORE_SET, true);
		$dom = $parser->preprocessToDom($content);
		$content = $frame->expand($dom);
		$output->setExtensionData(MetaTemplateData::KEY_IGNORE_SET, null);
		$output->setExtensionData(self::KEY_TEMPLATES, self::$templates);
		return '';
	}

	/**
	 * Indicates whether any custom templates have been defined on the page.
	 *
	 * @return bool True if at least one custom template has been defined; otherwise, false.
	 */
	public static function hasTemplate(): bool
	{
		return !empty(self::$templates);
	}

	/**
	 * @todo This is a HORRIBLE way to do this. Needs to be re-written to cache the data, not the parser and so forth.
	 * @todo Leave magic words as magic words and use all synonyms when setting the names.
	 * Initializes the class, accounting for possible parser caching.
	 *
	 * @param ?ParserOutput $parserOutput The current ParserOutput object if the page is retrieved from the cache.
	 */
	public static function init(ParserOutput $parserOutput = null): void
	{
		if (!MetaTemplate::getSetting(MetaTemplate::STTNG_ENABLECPT)) {
			return;
		}

		if ($parserOutput) {
			// We got here via the parser cache (Article::view(), case 2), so reload everything we don't have.
			// Article::view();
			self::$templates = $parserOutput->getExtensionData(self::KEY_TEMPLATES);
		}

		// While we could just import the global $wgContLang here, the global function still works and isn't deprecated
		// as of MediaWiki 1.40. In 1.32, however, MediaWiki introduces the method used on the commented out line, and
		// it seems likely they'll eventually make that the official method. Given that it's valid for so much longer
		// via this method, however, there's little point in versioning it via VersionHelper unless this code is used
		// outside our own wikis; we can just switch once we get to 1.32.
		self::$contLang = self::$contLang ?? wfGetLangObj(true);
		// self::$contLang = self::$contLang ?? MediaWikiServices::getInstance()->getContentLanguage();
		self::$mwPageLength = self::$mwPageLength ?? MagicWord::get(self::NA_PAGELENGTH)->getSynonym(0);
		self::$mwSortKey = self::$mwSortKey ?? MagicWord::get(self::NA_SORTKEY)->getSynonym(0);
	}

	/**
	 * Gets any additional set variables requested.
	 *
	 * @param string $type The type of results ('page', 'subcat', 'image').
	 * @param IResultWrapper $result The database results.
	 */
	public static function onDoCategoryQuery(string $type, IResultWrapper $result): void
	{
		if (
			!self::$frame || // No catpagetemplate
			$result->numRows() === 0 || // No categories
			!MetaTemplate::getSetting(MetaTemplate::STTNG_ENABLEDATA) // No possible sets
		) {
			return;
		}

		$output = self::$frame->parser->getOutput();

		/** @var MetaTemplateSet[] $varSets */
		$varSets = $output->getExtensionData(MetaTemplateData::KEY_VAR_CACHE_WANTED);

		/** @var MetaTemplatePage[] $pages */
		$pages = [];
		$varNames = [];
		if (isset($varSets[''])) {
			$varNames = $varSets['']->variables;
			$varNames = array_keys($varNames['*'] ?? []);
		}

		for ($row = $result->fetchRow(); $row; $row = $result->fetchRow()) {
			$pageId = $row['page_id'];
			$ns = $row['page_namespace'];
			$title = $row['page_title'];
			$pages[$pageId] = new MetaTemplatePage($ns, $title);
		}

		$result->rewind();
		MetaTemplateSql::getInstance()->catQuery($pages, $varNames);
		$output = self::$frame->parser->getOutput();
		$allPages = $output->getExtensionData(MetaTemplateData::KEY_VAR_CACHE);
		foreach ($pages as $pageId => $page) {
			if (!isset($allPages[$pageId])) {
				$allPages[$pageId] = $page;
			}
		}

		#RHshow('allPages', $allPages);
		$output->setExtensionData(MetaTemplateData::KEY_VAR_CACHE, $allPages);
	}
	#endregion

	#region Public Override Functions
	public function addImage(Title $title, $sortkey, $pageLength, $isRedirect = false)
	{
		#RHshow(__METHOD__, $title->getPrefixedText());
		if ($this->showGallery && isset(self::$templates[self::CV_FILE])) {
			$type = self::CV_FILE;
		} elseif (!$this->showGallery && isset(self::$templates[self::CV_PAGE])) {
			$type = self::CV_PAGE;
		} else {
			$type = null;
		}

		if (is_null(self::$templates[$type])) {
			parent::addImage($title, $sortkey, $pageLength, $isRedirect);
			return;
		}

		[$group, $link] = $this->processTemplate($type, $title, $sortkey, $pageLength, $isRedirect);
		$this->imgsNoGallery[] = $link;
		$this->imgsNoGallery_start_char[] = $group;
	}

	public function addPage($title, $sortkey, $pageLength, $isRedirect = false)
	{
		#RHshow(__METHOD__, $title->getPrefixedText());
		$template = self::$templates[self::CV_PAGE] ?? null;
		if (is_null($template)) {
			parent::addPage($title, $sortkey, $pageLength, $isRedirect);
			return;
		}

		[$group, $link] = $this->processTemplate(self::CV_PAGE, $title, $sortkey, $pageLength, $isRedirect);
		$this->articles[] = $link;
		$this->articles_start_char[] = $group;
	}

	public function addSubcategoryObject(Category $cat, $sortkey, $pageLength)
	{
		#RHshow(__METHOD__, $cat->getTitle()->getPrefixedText());
		$template = self::$templates[self::CV_SUBCAT] ?? null;
		if (is_null($template)) {
			parent::addSubcategoryObject($cat, $sortkey, $pageLength);
			return;
		}

		[$group, $link] = $this->processTemplate(self::CV_SUBCAT, $cat->getTitle(), $sortkey, $pageLength);
		$this->children[] = $link;
		$this->children_start_char[] = $group;
	}
	#endregion

	#region Private Functions
	/**
	 * Evaluates the template in the context of the category entry and each set on that page.
	 *
	 * @param string $template The template to be parsed.
	 * @param Title $title The title of the category entry.
	 * @param MetaTemplateSet $set The current set on the entry page.
	 * @param string|null $sortkey The current sortkey.
	 * @param int $pageLength The page length.
	 *
	 * @return MetaTemplateCategoryVars
	 */
	private function parseCatPageTemplate(string $type, Title $title, ?string $sortkey, int $pageLength, MetaTemplateSet $set): MetaTemplateCategoryVars
	{
		/** @todo Pagename entry should be changed to getText() for consistency. */
		$child = self::$frame->preprocessor->newFrame();
		MetaTemplate::setVarSynonyms($child, MetaTemplate::$mwFullPageName, $title->getFullText());
		MetaTemplate::setVarSynonyms($child, MetaTemplate::$mwNamespace, $title->getNsText());
		MetaTemplate::setVarSynonyms($child, MetaTemplate::$mwPageName, $title->getFullText());
		MetaTemplate::setVarSynonyms($child, self::$mwPageLength, (string)$pageLength);
		MetaTemplate::setVarSynonyms($child, self::$mwSortKey, explode("\n", $sortkey)[0]);
		if (MetaTemplate::getSetting(MetaTemplate::STTNG_ENABLEDATA)) {
			MetaTemplate::setVarSynonyms($child, MetaTemplateData::$mwSet, $set->name);
		}

		foreach ($set->variables as $varName => $varValue) {
			// Note that these are uncontrolled values, not magic words, so synonyms are ignored.
			MetaTemplate::setVar($child, $varName, $varValue);
		}

		$dom = self::$frame->parser->preprocessToDom(self::$templates[$type], Parser::PTD_FOR_INCLUSION);
		$templateOutput = $child->expand($dom);
		$retval = new MetaTemplateCategoryVars($child, $title, $templateOutput);

		return $retval->setSkip ? null : $retval;
	}

	/**
	 * Generates the text of the entry.
	 *
	 * @param string $template The catpagetemplate to use for this category entry.
	 * @param string $type What type of category entry this is.
	 * @param Title $title The title of the entry.
	 * @param string $sortkey The sortkey for the entry.
	 * @param int $pageLength The page length of the entry.
	 * @param bool $isRedirect Whether or not the entry is a redirect.
	 *
	 * @return array
	 */
	private function processTemplate(string $type, Title $title, string $sortkey, int $pageLength, bool $isRedirect = false): array
	{
		$output = self::$frame->parser->getOutput();
		/** @var MetaTemplatePage[] $allPages */
		$allPages = $output->getExtensionData(MetaTemplateData::KEY_VAR_CACHE) ?? [];
		$articleId = $title->getArticleID();
		if (isset($allPages[$articleId]) && MetaTemplate::getSetting(MetaTemplate::STTNG_ENABLEDATA)) {
			$setsFound = $allPages[$articleId]->sets;
			$defaultSet = $setsFound[''] ?? new MetaTemplateSet('');
		} else {
			$defaultSet = new MetaTemplateSet('');
			$setsFound = [];
		}
		#RHshow('Sets found', count($setsFound), "\n", $setsFound);

		unset($setsFound['']);
		$catVars = $this->parseCatPageTemplate($type, $title, $sortkey, $pageLength, $defaultSet);
		#RHshow('$catVars', $catVars);

		/* $catGroup does not need sanitizing as MW runs it through htmlspecialchars later in the process.
		 * Unfortunately, that means you can't make links without deriving formatList(), which can then call either
		 * static::columnList() instead of self::columnList() and the same for shortList() so that those two methods
		 * can be statically derived. Are we having fun yet?
		 */
		$catGroup = $catVars->catGroup ?? ($type === self::CV_SUBCAT
			? $this->getSubcategorySortChar($title, $sortkey)
			: self::$contLang->convert($this->collation->getFirstLetter($sortkey)));
		$catText = $catVars->catTextPre . $this->generateLink($type, $title, $isRedirect, $catVars->catLabel) . $catVars->catTextPost;
		$texts = [];
		if (count($setsFound) && (!is_null($catVars->setLabel) || !is_null($catVars->setPage))) {
			foreach (array_values($setsFound) as $setkey => $setValues) {
				#RHshow('Set', $setValues->name, ' => ', $setValues);
				$setVars = $this->parseCatPageTemplate($type, $title, null, -1, $setValues);
				if ($setVars) {
					$texts[$setVars->setSortKey . '.' . $setkey] = is_null($setVars->setPage)
						? $setVars->setLabel
						: $this->generateLink(
							$type,
							$setVars->setPage ?? $title,
							$setVars->setRedirect ?? $isRedirect,
							$setVars->setLabel ?? $title->getFullText()
						);
				}
			}
		}

		ksort($texts, SORT_NATURAL);
		$text = implode($catVars->setSeparator, $texts);
		if (strlen($text)) {
			$text = $catVars->setTextPre . $text . $catVars->setTextPost;
		}

		return [$catGroup, $catText . $text];
	}
	#endregion
}
