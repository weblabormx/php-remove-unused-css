<?php

namespace WeblaborMx\PhpRemoveUnusedCss;

use Illuminate\Support\Str;

/**
 * This is the basic version of the strip tool - this will only
 * check to see if the final level matches, there's no real
 * tree traversal going on, e.g.
 *
 * .this .that { color: red; }
 *
 * Will match <div class="that">, regardless if it is wrapped in
 * another like <div class="this">
 */
class RemoveUnusedCssBasic implements RemoveUnusedCssInterface
{
    /**
     * Traits
     */
    use RemoveUnusedCss;


    /**
     * @var array
     */
    protected $foundUsedCssElements = ['*'];
    protected $foundCssStructure    = [];
    protected $readyForSave         = [];


    /**
     * @var string
     */
    protected $elementForNoMediaBreak = '__NO_MEDIA__';


    /**
     * @var array
     */
    protected $regexForHtmlFiles = [
        'HTML Tags' => [
            'regex' => '/<(\w+)/',
            'stringPlaceBefore' => '',
            'stringPlaceAfter'  => '',
        ],
        'CSS Classes' => [
            'regex' => '/class\s*=\s*"([^"]*)"/',
            'stringPlaceBefore' => '.',
            'stringPlaceAfter'  => '',
        ],
        'IDs' => [
            'regex' => '/\<.*id\=\"([[:alnum:]\s_-]+)\".*(?!\/)\>/',
            'stringPlaceBefore' => '#',
            'stringPlaceAfter'  => '',
        ],
        'Data Tags (Without Values)' => [
            'regex' => '/\<.*(data-[[:alnum:]_-]+)\=\"(.*)\".*(?!\/)\>/',
            'stringPlaceBefore' => '[',
            'stringPlaceAfter'  => ']',
        ],
        'Data Tags (With Values)' => [
            'regex' => '/\<.*(data-[[:alnum:]_-]+\=\"(.*)\").*(?!\/)\>/',
            'stringPlaceBefore' => '[',
            'stringPlaceAfter'  => ']',
        ],
    ];


    /**
     * @var string[]
     */
    protected $regexForCssFiles = [
        '/}*([[*a-zA-Z0-9-_\\\\ \/~>^"=n()@+,.#:\]*]+){+([^}]+)}/',
    ];


    /**
     * @inheritDoc
     */
    public function refactor()
    {
        $this->findAllHtmlFiles();
        $this->findAllStyleSheetFiles();
        $this->scanHtmlFilesForUsedElements();
        $this->scanCssFilesForAllElements();
        $this->filterCss();
        $this->prepareForSaving();

        return $this;
    }


    /**
     * @inheritDoc
     */
    public function saveFiles() {

        $this->createFiles();

        return $this;
    }


    /**
     * @inheritDoc
     */
    public function returnAsText(): array
    {
        return $this->readyForSave;
    }


    /**
     * Strip out the unused element
     *
     * @return  void
     */
    protected function filterCss()
    {
        $mergedArray = array_merge($this->whitelistArray, $this->foundUsedCssElements);
        foreach ($this->foundCssStructure as $file => &$fileData) {

            foreach ($fileData as $key => &$block) {

                foreach ($block as $selectors => $values) {

                    $keep = false;
                    $explode = explode(',', $selectors);

                    foreach ($explode as $selector) {
                        
                        $selector_without_extra = preg_split('/(?<!\\\\):/', $selector, 2)[0];
                        $selector_without_extra = str_replace('\:', ':', $selector_without_extra);
                        $selector_without_extra = str_replace('\/', '/', $selector_without_extra);

                        $selector2 = $selector_without_extra;
                        $selectors_to_check = [' ', '>', '+', '~'];
                        if(Str::contains($selector_without_extra, $selectors_to_check)) {
                            foreach($selectors_to_check as $selector_to_check) {
                                $explode2 = explode($selector_to_check, $selector2);
                                $selector2 = end($explode2);
                            }
                            $selector2 = trim($selector2);
                        }
                      
                        if (
                            in_array($selector_without_extra, $mergedArray) ||
                            in_array($selector2, $mergedArray) ||
                            $selector_without_extra == '*'
                        ) {
                            $keep = true;
                        }
                    }

                    if (!$keep) {
                        unset($block[$selectors]);
                    }
                }
            }
        }
    }


    /**
     * Get the source ready to be saved in files or returned
     *
     * @return  void
     */
    public function prepareForSaving()
    {
        foreach ($this->foundCssStructure as $file => $fileData) {

            $source = '';

            foreach ($fileData as $key => $block) {

                $prefix  = '';
                $postfix = '';
                $indent  = 0;

                if ($key != $this->elementForNoMediaBreak) {

                    $prefix  = $key." {\n";
                    $postfix = "}\n\n";
                    $indent  = 4;
                }

                if (!empty($block)) {

                    $source .= $prefix;

                    foreach ($block as $selector => $values) {

                        $values = collect($values)->implode(';');
                        $values = trim($values);

                        if (substr($values, -1) !== ';') { $values .= ';'; }
                        if (strpos($values, '{') !== false) { $values .= '}'; }

                        $source .= str_pad('', $indent, ' ').$selector." {\n";
                        $source .= str_pad('', $indent, ' ')."    ".$values."\n";
                        $source .= str_pad('', $indent, ' ')."}\n";
                    }

                    $source .= $postfix;
                }
            }

            $filenameBeforeExt = substr($file, 0, strrpos($file, '.'));
            $filenameExt       = substr($file, strrpos($file, '.'), strlen($file));

            if (!empty($this->appendFilename)) {
                $filenameExt = $this->appendFilename.$filenameExt;
            }

            $newFileName = $filenameBeforeExt.$filenameExt;

            $this->readyForSave[] = [
                'filename'    => $file,
                'newFilename' => $newFileName,
                'source'      => (
                    $this->minify
                        ? $this->performMinification($source)
                        : $this->getComment().$source
                ),
            ];
        }
    }


    /**
     * Create the stripped down CSS files
     *
     * @return  void
     */
    protected function createFiles()
    {
        foreach ($this->readyForSave as $fileData) {
            $this->createFile($fileData['newFilename'], $fileData['source']);
        }
    }


    /**
     * Scan the CSS files for all main elements
     *
     * @return void
     */
    protected function scanCssFilesForAllElements()
    {
        foreach ($this->foundCssFiles as $file) {
            $content = file_get_contents($file);
            $content = preg_replace('/\/\*.*?\*\//s', '', $content);
            $this->scanCss($content, $file);
        }

        foreach ($this->inlineCss as $key => $content) {
            $this->scanCss($content, "inline_{$key}");
        }
    }

    private function scanCss($content, $name)
    {
        $breaks = explode('@media', $content);

        $loop = 0;

        foreach ($breaks as $num => $break) {

            $break = trim($break);

            if ($loop == 0) {
                $key = $this->elementForNoMediaBreak;
                $cssSectionOfBreakArray = [$break];
            } else {
                $key = '@media '.substr($break, 0, strpos($break, '{'));
                $cssSectionOfBreakToArrayize = substr($break, strpos($break, '{'), strrpos($break, '}'));
                $cssSectionOfBreakArray = $this->splitBlockIntoMultiple($cssSectionOfBreakToArrayize);
            }

            foreach ($cssSectionOfBreakArray as $counter => $cssSectionOfBreak) {

                if ($counter > 0) {
                    $key = $this->elementForNoMediaBreak;
                }
                foreach ($this->regexForCssFiles as $regex) {
                    preg_match_all($regex, $cssSectionOfBreak, $matches);
                  
                    if (!empty($matches)) {
                        foreach ($matches[1] as $regexKey => $element) {
                            $selector = trim(preg_replace('/\s+/', ' ', $element));
                            $this->foundCssStructure[$name][$key][$selector][] = trim(preg_replace('/\s+/', ' ', $matches[2][$regexKey]));
                        }
                    }
                }
            }

            $loop++;
        }
    }


    /**
     * Because we break on @media there's often another block of non
     * @media CSS after it, so we need to get that out separately
     *
     * @param   string  $string
     * @return  string[]
     */
    protected function splitBlockIntoMultiple($string = '')
    {
        $totalOpen   = 0;
        $totalClosed = 0;
        $counterMark = 0;
        $blocks      = [];
        $stringSoFar = '';

        foreach (str_split($string) as $counter => $character) {

            $stringSoFar .= $character;

            if ($character == '{') {
                $totalOpen++;
            }

            if ($character == '}') {

                $totalClosed++;

                if ($totalClosed == $totalOpen) {

                    $blocks[$counterMark] = $stringSoFar;

                    $stringSoFar = ''; $totalOpen = 0; $totalClosed = 0;
                    $counterMark = $counter;
                }
            }
        }

        $returnBlock = [0 => '', 1 => ''];

        foreach ($blocks as $block) {

            if (substr(trim($block), 0, 1) == '{') {
                $returnBlock[0] = $block;
            } else {
                $returnBlock[1] .= $block."\n";
            }
        }

        return array_filter($returnBlock);
    }


    /**
     * Find all matching HTML css elements
     *
     * @return void
     */
    protected function scanHtmlFilesForUsedElements()
    {
        foreach ($this->foundHtmlFiles as $file) {
            $html = strlen($file) > 200 ? $file : file_get_contents($file);

            foreach ($this->regexForHtmlFiles as $regex) {
                preg_match_all($regex['regex'], $html, $matches, PREG_PATTERN_ORDER);
                $matches = collect($matches[1])->unique()->values();

                if (isset($matches)) {

                    foreach ($matches as $match) {

                        if(str_contains($match, "'")) {
                            $matches = [];
                            preg_match_all("/'(.*?)'/", $match, $matches);
                            $match = collect($matches[1])->filter(function($item) {
                                return strlen($item) > 1;
                            })->implode(' ');
                        }

                        foreach (explode(' ', $match) as $explodedMatch) {

                            $formattedMatch = $regex['stringPlaceBefore'].trim($explodedMatch).$regex['stringPlaceAfter'];
                            if (!in_array($formattedMatch, $this->foundUsedCssElements)) {
                                $this->foundUsedCssElements[] = $formattedMatch;
                            }
                        }
                    }
                }
            }
        }
    }
}
