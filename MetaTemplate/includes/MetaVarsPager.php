<?php
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
            $headers = array(
                'mt_set_subset' => 'metatemplate-metavarsonpage-set',
                'mt_save_varname' => 'metatemplate-metavarsonpage-varname',
                'mt_save_value' => 'metatemplate-metavarsonpage-varvalue',
            );

            foreach ($headers as $key => $val) {
                $headers[$key] = $this->msg($val)->text();
            }
        }

        return $headers;
    }

    function formatValue($name, $value)
    {
        switch ($name) {
            case 'mt_set_subset':
                $formatted = Html::rawElement(
                    'span',
                    array('class' => 'metatemplate-metavarsonpage-set', 'style' => 'white-space:nowrap;'),
                    $value
                );
                break;
            default:
                $formatted = htmlspecialchars($value);
                break;
        }

        return $formatted;
    }

    function getQueryInfo()
    {
        return array(
            'tables' => array('mt_save_set', 'mt_save_data'),
            'fields' => array(
                'mt_set_page_id',
                'mt_set_subset',
                'mt_save_varname',
                'mt_save_value',
            ),
            'conds' => $this->conds,
            'options' => array(),
            'join_conds' => array(
                'mt_save_data' => array('INNER JOIN', 'mt_save_set.mt_set_id = mt_save_data.mt_save_id'),
            ),
        );
    }

    public function getTableClass()
    {
        return 'TablePager metatemplate-metavarsonpage';
    }

    function getDefaultSort()
    {
        return 'mt_set_subset';
    }

    function getExtraSortFields()
    {
        return array('mt_save_varname');
    }

    function isFieldSortable($name)
    {
        return $name !== 'mt_save_value';
    }
}
