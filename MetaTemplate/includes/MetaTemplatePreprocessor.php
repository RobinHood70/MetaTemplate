<?php
class MetaTemplatePreprocessor extends Preprocessor_Hash
{
    /**
     * newFrame
     *
     * @return MetaTemplateFrameRoot
     */
    function newFrame()
    {
        return new MetaTemplateFrameRoot($this);
    }
}
