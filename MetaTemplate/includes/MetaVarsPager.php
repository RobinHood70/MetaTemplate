<?php
class MetaVarsPager extends TablePager
{
    private $conds;

    private $headers;

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

        if (is_null($this->headers)) {
            $this->headers = [
                'setName' => 'metatemplate-metavarsonpage-set',
                'varName' => 'metatemplate-metavarsonpage-varname',
                'varValue' => 'metatemplate-metavarsonpage-varvalue',
                'parsed' => 'metatemplate-metavarsonpage-parsed',
            ];

            foreach ($this->headers as $key => $val) {
                $this->headers[$key] = $this->msg($val)->text();
            }
        }

        return $this->headers;
    }

    function formatValue($name, $value)
    {
        switch ($name) {
            case 'setName':
                return Html::rawElement(
                    'span',
                    [
                        'class' => 'metatemplate-metavarsonpage-set',
                        'style' => 'white-space:nowrap;'
                    ],
                    $value
                );
            case 'parsed':
                return $value ? '' : 'No';
            default:
                return htmlspecialchars($value);
        }
    }

    function getQueryInfo()
    {
        return [
            'tables' => [MetaTemplateSql::SET_TABLE, MetaTemplateSql::DATA_TABLE],
            'fields' => [
                'setName',
                'varName',
                'varValue',
                'parsed',
            ],
            'conds' => $this->conds,
            'join_conds' => [
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
