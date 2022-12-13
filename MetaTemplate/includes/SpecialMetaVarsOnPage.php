<?php

/**
 * Implements Special:MetaVarsOnPage
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @ingroup SpecialPage
 */

/**
 * A special page that lists existing blocks
 *
 * @internal This class used SpecialBlockList.php as a starting point. Compare with that class for future updates.
 */
class SpecialMetaVarsOnPage extends SpecialPage
{
    private const METAVARS_ID = 'MetaVarsOnPage';

    private $limit;
    private $pageName;

    function __construct()
    {
        parent::__construct(self::METAVARS_ID);
    }

    public function execute($subPage): void
    {
        $this->setHeaders();
        $this->outputHeader();
        $out = $this->getOutput();
        $out->addModuleStyles('mediawiki.special');
        $request = $this->getRequest();
        $this->pageName = $request->getVal('page', $subPage);
        $this->limit = intval($request->getVal('limit', 50));

        $lang = $this->getLanguage();
        $fields = [
            'Page' => [
                'type' => 'text',
                'name' => 'page',
                'label-message' => 'metatemplate-metavarsonpage-pagecolon',
                'default' => $this->pageName,
            ],
            'Limit' => [
                'type' => 'select',
                'name' => 'limit',
                'label-message' => 'table_pager_limit_label',
                'options' => [
                    $lang->formatNum(20) => 20,
                    $lang->formatNum(50) => 50,
                    $lang->formatNum(100) => 100,
                    $lang->formatNum(250) => 250,
                    $lang->formatNum(500) => 500,
                ],
                'default' => 50,
            ],
        ];

        HTMLForm::factory('ooui', $fields, $this->getContext())
            ->setMethod('get')
            ->setFormIdentifier(self::METAVARS_ID)
            ->setWrapperLegendMsg('metatemplate-metavarsonpage-legend')
            ->setSubmitTextMsg('metatemplate-metavarsonpage-submit')
            ->prepareForm()
            ->displayForm(false);
        $this->showList();
    }

    public function showList(): void
    {
        if (!$this->pageName) {
            return;
        }

        $title = Title::newFromText($this->pageName);
        $out = $this->getOutput();

        if (!$title || !$title->canExist()) {
            $out->addWikiMsg('metatemplate-metavarsonpage-no-page');
            return;
        }

        // RHshow($title->getFullText(), ' (', $title->getArticleID(), ')');
        $pager = new MetaVarsPager($this->getContext(), $title->getArticleId(), $this->limit);
        if (!$pager->getNumRows()) {
            $out->addWikiMsg('metatemplate-metavarsonpage-no-results');
            return;
        }

        $out->addParserOutput($pager->getFullOutput());
    }

    protected function getGroupName(): string
    {
        return 'wiki';
    }
}
