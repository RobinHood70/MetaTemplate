<?php

use MediaWiki\MediaWikiServices;
use MediaWiki\Page\PageReference;

/* In theory, this process could be optimized further by subdividing <catpagetemplate> into a section for pages and a
 * section for sets so that only the set portion is parsed inside the loop at the end of processTemplate(). Given the
 * syntax changes already being introduced in this version and the extra level of user knowledge that a pages/sets
 * style would require, I don't think it's especially useful.
 */

/**
 * This class wraps around the base CategoryViewer class to provide MetaTemplate's custom capabilities like altering
 * the title and showing set names on a page.
 */
class MetaTemplateCategoryViewer37 extends MetaTemplateCategoryViewer
{
	#region Public Override Functions
	#region Public Override Functions
	public function addImage(PageReference $page, string $sortkey, int $pageLength, bool $isRedirect = false): void
	{
		#RHshow(__METHOD__, $page->getPrefixedText());
		if ($this->showGallery && isset(self::$templates[self::CV_FILE])) {
			$type = self::CV_FILE;
		} elseif (!$this->showGallery && isset(self::$templates[self::CV_PAGE])) {
			$type = self::CV_PAGE;
		} else {
			$type = null;
		}

		if (is_null(self::$templates[$type])) {
			parent::addImage($page, $sortkey, $pageLength, $isRedirect);
			return;
		}

		[$group, $link] = $this->processTemplate($type, $page, $sortkey, $pageLength, $isRedirect);
		$this->imgsNoGallery[] = $link;
		$this->imgsNoGallery_start_char[] = $group;
	}

	public function addPage(PageReference $page, string $sortkey, int $pageLength, bool $isRedirect = false): void
	{
		#RHshow(__METHOD__, $page->getPrefixedText());
		$template = self::$templates[self::CV_PAGE] ?? null;
		if (is_null($template)) {
			parent::addPage($page, $sortkey, $pageLength, $isRedirect);
			return;
		}

		[$group, $link] = $this->processTemplate(self::CV_PAGE, $page, $sortkey, $pageLength, $isRedirect);
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

		$page = $cat->getPage();
		if (!$page) {
			return;
		}

		// Subcategory; strip the 'Category' namespace from the link text.
		$pageRecord = MediaWikiServices::getInstance()->getPageStore()
			->getPageByReference($page);
		if (!$pageRecord) {
			return;
		}

		[$group, $link] = $this->processTemplate(self::CV_SUBCAT, $pageRecord, $sortkey, $pageLength);
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
	 * @param string|null $sortkey The current sortkey.
	 * @param int $pageLength The page length.
	 * @param MetaTemplateSet $set The current set on the entry page.
	 *
	 * @return MetaTemplateCategoryVars
	 */
	protected function parseCatPageTemplate(string $type, Title $title, ?string $sortkey, int $pageLength, MetaTemplateSet $set): ?MetaTemplateCategoryVars
	{
		/** @todo Pagename entry should be changed to getText() for consistency. */
		$frame = self::$templateFrames[$type];
		$child = $frame->newChild(false, $title, 0);
		$tf = $mws->getTitleFormatter();
		MetaTemplate::setVar($child, MetaTemplate::$mwFullPageName, $title->getPrefixedText());
		MetaTemplate::setVar($child, MetaTemplate::$mwNamespace, $title->getNsText());
		MetaTemplate::setVar($child, MetaTemplate::$mwPageName, $title->getText());
		MetaTemplate::setVar($child, self::$mwPageLength, (string)$pageLength);
		MetaTemplate::setVar($child, self::$mwSortKey, explode("\n", $sortkey)[0]);
		if (MetaTemplate::getSetting(MetaTemplate::STTNG_ENABLEDATA)) {
			MetaTemplate::setVar($child, MetaTemplateData::$mwSet, $set->name);
		}

		$parser = $frame->parser;
		foreach ($set->variables as $varName => $varValue) {
			$dom = $parser->preprocessToDom($varValue);
			MetaTemplate::setVarDirect($child, $varName, $dom, $varValue);
		}

		$dom = $parser->preprocessToDom(self::$templates[$type], Parser::PTD_FOR_INCLUSION);
		$templateOutput = trim($child->expand($dom));
		$retval = new MetaTemplateCategoryVars($child, $title, $templateOutput);

		return $retval->setSkip ? null : $retval;
	}

	/**
	 * Generates the text of the entry.
	 *
	 * @param string $template The catpagetemplate to use for this category entry.
	 * @param string $type What type of category entry this is.
	 * @param PageReference $title The title of the entry.
	 * @param string $sortkey The sortkey for the entry.
	 * @param int $pageLength The page length of the entry.
	 * @param bool $isRedirect Whether or not the entry is a redirect.
	 *
	 * @return array
	 */
	private function processTemplate(string $type, PageReference $page, string $sortkey, int $pageLength, bool $isRedirect = false): array
	{
		$pageRecord = MediaWikiServices::getInstance()->getPageStore()
			->getPageByReference($page);
		$articleId = $pageRecord->getId();
		if (isset(MetaTemplateData::$preloadCache[$articleId]) && MetaTemplate::getSetting(MetaTemplate::STTNG_ENABLEDATA)) {
			$setsFound = MetaTemplateData::$preloadCache[$articleId]->sets;
			$defaultSet = $setsFound[''] ?? new MetaTemplateSet('');
		} else {
			$defaultSet = new MetaTemplateSet('');
			$setsFound = [];
		}
		#RHshow('Sets found', count($setsFound), "\n", $setsFound);

		unset($setsFound['']);
		$mws = MediaWikiServices::getInstance();
		$title = $mws->getTitleFactory()->castFromPageReference($page);
		$catVars = $this->parseCatPageTemplate($type, $title, $sortkey, $pageLength, $defaultSet);
		#RHDebug::show('$catVars', $catVars);

		/* $catGroup does not need sanitizing as MW runs it through htmlspecialchars later in the process.
		 * Unfortunately, that means you can't make links without deriving formatList(), which can then call either
		 * static::columnList() instead of self::columnList() and the same for shortList() so that those two methods
		 * can be statically derived. Are we having fun yet?
		 */
		$catGroup = $catVars->catGroup ?? ($type === self::CV_SUBCAT
			? $this->getSubcategorySortChar($page, $sortkey)
			: self::$contLang->convert($this->collation->getFirstLetter($sortkey)));
		$catText = $catVars->catTextPre . $this->generateLink($type, $pageRecord, $isRedirect, $catVars->catLabel) . $catVars->catTextPost;
		$texts = [];
		if (count($setsFound) && (!is_null($catVars->setLabel) || !is_null($catVars->setPage))) {
			foreach (array_values($setsFound) as $setkey => $setValues) {
				#RHDebug::show('Set', $setValues->name, ' => ', $setValues);
				$mws = MediaWikiServices::getInstance();
				$setVars = $this->parseCatPageTemplate($type, $title, null, -1, $setValues);
				if ($setVars) {
					$tf = MediaWikiServices::getInstance()->getTitleFormatter();
					$texts[$setVars->setSortKey . '.' . $setkey] = is_null($setVars->setPage)
						? $setVars->setLabel
						: $this->generateLink(
							$type,
							$setVars->setPage ?? $page,
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
