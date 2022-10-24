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
 * @file
 * @ingroup SpecialPage
 */

/**
 * A special page that lists existing blocks
 *
 * @ingroup SpecialPage
 */
class SpecialMetaVarsOnPage extends SpecialPage
{

    /**
     * The name of the page to look at.
     *
     * @var Page
     */
    private $pageName;

    private $limit;

    function __construct()
    {
        parent::__construct('MetaVarsOnPage');
    }

    /**
     * Main execution point
     *
     * @param string $par title fragment
     */
    public function execute($par)
    {
        $this->setHeaders();
        $this->outputHeader();
        $out = $this->getOutput();
        $lang = $this->getLanguage();
        $out->addModuleStyles('mediawiki.special');

        $request = $this->getRequest();
        $this->pageName = $request->getVal('page', $par);
        $this->limit = intval($request->getVal('limit', 50));

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

        $form = new HTMLForm($fields, $this->getContext());
        $form->setMethod('get');
        $form->setWrapperLegendMsg('metatemplate-metavarsonpage-legend');
        $form->setSubmitTextMsg('metatemplate-metavarsonpage-submit');
        $form->prepareForm();

        $form->displayForm('');
        $this->showList();
    }

    function showList()
    {
        if (!$this->pageName) {
            return;
        }

        $title = Title::newFromText($this->pageName);
        $conds = [];
        $out = $this->getOutput();

        if ($title && $title->canExist()) {
            // RHshow($title->getFullText(), ' (', $title->getArticleID(), ')');
            $conds[MetaTemplateSql::SET_TABLE . '.pageId'] = $title->getArticleID();
            $pager = new MetaVarsPager($this->getContext(), $conds, $this->limit);
            if ($pager->getNumRows()) {
                $out->addParserOutput($pager->getFullOutput());
            } else {
                $out->addWikiMsg('metatemplate-metavarsonpage-no-results');
            }
        } else {
            $out->addWikiMsg('metatemplate-metavarsonpage-no-page');
        }
    }

    protected function getGroupName()
    {
        return 'wiki';
    }
}
