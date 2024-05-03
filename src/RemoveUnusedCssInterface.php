<?php

namespace WeblaborMx\PhpRemoveUnusedCss;

/**
 * Remove unused CSS from a stylesheet
 */
interface RemoveUnusedCssInterface
{
    /**
     * Refactor the CSS and remove the unused elements
     *
     * @return  $this
     */
    public function refactor();


    /**
     * Save the new CSS files
     *
     * @return  $this
     */
    public function saveFiles();


    /**
     * Instead of saving as files, return the CSS
     * in an array of strings (per file)
     *
     * @return  array
     */
    public function returnAsText(): array;
}
