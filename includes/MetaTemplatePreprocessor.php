<?php
class MetaTemplatePreprocessor extends Preprocessor_Hash
{
    /**
     * newFrame
     *
     * @return MetaTemplateFrame_Hash
     */
    function newFrame()
    {
        return new MetaTemplateFrame_Hash($this);
    }
}
