<?php
class MetaTemplatePreprocessor extends Preprocessor_Hash
{
    /**
     * newFrame
     *
     * @return MetaTemplateFrameRoot
     */
    function newFrame(): MetaTemplateFrameRoot
    {
        return new MetaTemplateFrameRoot($this);
    }
}
