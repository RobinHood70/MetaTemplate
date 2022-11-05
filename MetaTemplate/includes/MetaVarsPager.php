<?php
class MetaVarsPager extends TablePager
{
    private $conds;

    private $headers;

    /**
     * Creates a new instance of the MetaVarsPager class.
     *
     * @param IContextSource $context The MediaWiki context.
     * @param mixed $conds Conditions to be applied to the results.
     * @param mixed $limit The number of results to list.
     *
     */
    public function __construct(IContextSource $context, $conds, $limit)
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

    public function getFieldNames(): array
    {

        if (is_null($this->headers)) {
            $this->headers = [
                'setName' => 'metatemplate-metavarsonpage-set',
                'varName' => 'metatemplate-metavarsonpage-varname',
                'varValue' => 'metatemplate-metavarsonpage-varvalue',
                'parseOnLoad' => 'metatemplate-metavarsonpage-parseonload',
            ];

            foreach ($this->headers as $key => $val) {
                $this->headers[$key] = $this->msg($val)->text();
            }
        }

        return $this->headers;
    }

    function formatValue($name, $value): string
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
            case 'parseOnLoad':
                return $value ? 'Yes' : '';
            default:
                return htmlspecialchars($value);
        }
    }

    function getQueryInfo(): array
    {
        return [
            'tables' => [MetaTemplateSql::SET_TABLE, MetaTemplateSql::DATA_TABLE],
            'fields' => [
                'setName',
                'varName',
                'varValue',
                'parseOnLoad',
            ],
            'conds' => $this->conds,
            'join_conds' => [
                MetaTemplateSql::DATA_TABLE => ['INNER JOIN', MetaTemplateSql::SET_TABLE . '.setId=' . MetaTemplateSql::DATA_TABLE . '.setId'],
            ]
        ];
    }

    public function getTableClass(): string
    {
        return 'TablePager metatemplate-metavarsonpage';
    }

    public function getDefaultSort(): string
    {
        return MetaTemplateSql::SET_TABLE . '.setName';
    }

    public function getExtraSortFields(): array
    {
        return [MetaTemplateSql::DATA_TABLE . '.varName'];
    }

    public function isFieldSortable($name): bool
    {
        return $name !== MetaTemplateSql::DATA_TABLE . '.varValue';
    }
}
