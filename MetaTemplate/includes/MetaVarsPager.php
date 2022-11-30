<?php
class MetaVarsPager extends TablePager
{
    private const HEADER_KEYS = [
        'setName' => 'metatemplate-metavarsonpage-set',
        'varName' => 'metatemplate-metavarsonpage-varname',
        'varValue' => 'metatemplate-metavarsonpage-varvalue',
        'parseOnLoad' => 'metatemplate-metavarsonpage-parseonload',
    ];
    private $headers;
    private $pageId;

    /**
     * Creates a new instance of the MetaVarsPager class.
     *
     * @param IContextSource $context The MediaWiki context.
     * @param mixed $conds Conditions to be applied to the results.
     * @param mixed $limit The number of results to list.
     *
     */
    public function __construct(IContextSource $context, int $pageId, int $limit)
    {
        $this->pageId = $pageId;
        $this->mLimit = $limit;
        $this->mDefaultDirection = false;
        foreach (self::HEADER_KEYS as $key => $val) {
            $this->headers[$key] = $this->msg($val)->text();
        }

        // TablePager doesn't handle two-key offsets and doesn't seem to support simple numerical offsets either.
        // This seemed like an acceptable trade-off, since it offers the added benefit of always showing
        // an entire set. The drawback is that if limit is set to less than the number of keys in the set,
        // you'll never get anywhere.
        $this->mIncludeOffset = true;
        parent::__construct($context);
    }

    public function getFieldNames(): array
    {
        return $this->headers;
    }

    function formatValue($name, $value): string
    {
        switch ($name) {
            case 'parseOnLoad':
                return $value ? 'Yes' : '';
            case 'setName':
                return Html::rawElement(
                    'span',
                    [
                        'class' => 'metatemplate-metavarsonpage-set',
                        'style' => 'white-space:nowrap;'
                    ],
                    $value
                );
            case 'varValue':
                return str_replace("\n", "<br>", $value);
            default:
                return htmlspecialchars($value);
        }
    }

    function getQueryInfo(): array
    {
        return MetaTemplateSql::getInstance()->loadQuery($this->pageId, null, [], true);
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
        return true; // $name !== MetaTemplateSql::DATA_TABLE . '.varValue';
    }
}
