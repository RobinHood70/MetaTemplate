<?php
class MetaTemplatePreprocessor extends Preprocessor_Hash
{
    /**
     * newFrame
     *
     * @return MetaTemplateFrameHash
     */
    function newFrame()
    {
        return new MetaTemplateFrameHash($this);
    }
}
