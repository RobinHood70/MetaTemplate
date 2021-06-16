<?php

class MetaTemplateDataUpdate extends DataUpdate
{
    /** @var Title */
    private $title;

    /** @var ParserOutput */
    private $output;

    public function __construct(Title $title, ParserOutput $parserOutput)
    {
        $this->title = $title;
        $this->output = $parserOutput;
    }

    public function doUpdate()
    {
        if ($this->output && $this->title && $this->title->getNamespace() >= NS_MAIN && !wfReadOnly()) {
            $vars = $this->output->getExtensionData(MetaTemplateData::PF_SAVE);
            if ($vars && count($vars)) {
                MetaTemplateSql::getInstance()->saveVariables($vars);
                $this->output->setExtensionData(MetaTemplateData::PF_SAVE, null);
            }
        }

        return true;
    }
}
