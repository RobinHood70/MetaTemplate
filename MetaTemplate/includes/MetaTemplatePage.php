<?php

class MetaTemplatePage
{
    /** @var int $namespace */
    public $namespace;

    /** @var string $pagename */
    public $pagename;

    /**
     * All sets on the page.
     *
     * @var MetaTemplateSet[]
     */
    public $sets = [];

    /**
     * Stores information for use with the bulk-loading features of <catpagetemplate> and {{#listsaved}}.
     *
     * @param mixed $namespace The namespace of the page.
     * @param mixed $pagename The page name.
     *
     */
    public function __construct($namespace, $pagename)
    {
        $this->namespace = (int)$namespace;
        $this->pagename = (string)$pagename;
    }

    /**
     * Gets a set by name if it exists or creates one if it doesn't.
     *
     * @param int $setId The set ID. If set to zero, the set will be ignored for deletes and updates, though it will be
     *                   added, if appropriate.
     * @param string $setName
     *
     * @return MetaTemplateSet
     *
     */
    public function addToSet(string $setName, array $variables, bool $anyCase = false)
    {
        if (isset($this->sets[$setName])) {
            $set = $this->sets[$setName];
        } else {
            $set = new MetaTemplateSet($setName, [], $anyCase);
            $this->sets[$setName] = $set;
        }

        foreach ($variables as $key => $value) {
            if ($value !== false) {
                if (!($value instanceof MetaTemplateVariable)) {
                    $value = new MetaTemplateVariable($value, false);
                }

                $set->variables[$key] = $value;
            }
        }
    }
}
