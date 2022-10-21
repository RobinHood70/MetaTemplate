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

class MetaVarsPager extends TablePager
{
    private $conds;

    /**
     * @param $page SpecialPage
     * @param $conds Array
     */
    function __construct($context, $conds, $limit)
    {
        $this->conds = $conds;
        $this->mLimit = $limit;
        $this->mDefaultDirection = false;

        // TablePager doesn't handle two-key offsets and doesn't seem to support simple numerical offsets either.
        // This seemed like an acceptable trade-off, since it offers the added benefit of always showing
        // an entire set. The drawback is that if limit is set to less than the number of keys in the set,
        // you'll never get anywhere.
        $this->mIncludeOffset = true;
        parent::__construct($context);
    }

    function getFieldNames()
    {
        static $headers = null;

        if ($headers === null) {
            $headers = [
                'setName' => 'metatemplate-metavarsonpage-set',
                'varName' => 'metatemplate-metavarsonpage-varname',
                'varValue' => 'metatemplate-metavarsonpage-varvalue',
                'parsed' => 'metatemplate-metavarsonpage-parsed',
            ];

            foreach ($headers as $key => $val) {
                $headers[$key] = $this->msg($val)->text();
            }
        }

        return $headers;
    }

    function formatValue($name, $value)
    {
        switch ($name) {
            case 'setName':
                $formatted = Html::rawElement(
                    'span',
                    [
                        'class' => 'metatemplate-metavarsonpage-set',
                        'style' => 'white-space:nowrap;'
                    ],
                    $value
                );
                break;
            case 'parsed':
                $formatted = $value ? 'Yes' : 'No';
                break;
            default:
                $formatted = htmlspecialchars($value);
                break;
        }

        return $formatted;
    }

    function getQueryInfo()
    {
        return [
            'tables' => [MetaTemplateSql::SET_TABLE, MetaTemplateSql::DATA_TABLE],
            'fields' => [
                MetaTemplateSql::SET_TABLE . '.pageId',
                MetaTemplateSql::SET_TABLE . '.setName',
                MetaTemplateSql::DATA_TABLE . '.varName',
                MetaTemplateSql::DATA_TABLE . '.varValue',
                MetaTemplateSql::DATA_TABLE . '.parsed',
            ],
            'conds' => $this->conds,
            'options' => [],
            'join_conds' => [
                // . MetaTemplateSql::DATA_TABLE . '.mt_save_id'),
                MetaTemplateSql::DATA_TABLE => ['INNER JOIN', MetaTemplateSql::SET_TABLE . '.setId=' . MetaTemplateSql::DATA_TABLE . '.setId'],
            ]
        ];
    }

    public function getTableClass()
    {
        return 'TablePager metatemplate-metavarsonpage';
    }

    function getDefaultSort()
    {
        return MetaTemplateSql::SET_TABLE . '.setName';
    }

    function getExtraSortFields()
    {
        return [MetaTemplateSql::DATA_TABLE . '.varName'];
    }

    function isFieldSortable($name)
    {
        return $name !== MetaTemplateSql::DATA_TABLE . '.varValue';
    }
}
