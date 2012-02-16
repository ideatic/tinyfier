<?php

/**
 * css_optimizer - Add vendor prefixes in your CSS files for cross browser compatibility
 * 
 * --
 * Copyright (c) Javier Marín
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 * --
 * 
 * @package		css_optimizer
 * @link		https://github.com/javiermarinros/css_optimizer
 * @version		1
 * @author		Javier Marín <https://github.com/javiermarinros>
 * @copyright           Javier Marín <https://github.com/javiermarinros>
 * @license		http://opensource.org/licenses/mit-license.php MIT License
 */
require 'cssmin.php';

class css_optimizer {

    protected $_settings;
    protected $_errors;

    public function __construct($settings = array()) {
        if (isset($settings['prefix']) && $settings['prefix'] == 'all')
            unset($settings['prefix']); //Use default settings (add all prefix)
        $this->_settings = array_merge(self::default_settings(), $settings);
    }

    public static function default_settings() {
        return array(
            'compress' => true,
            'optimize' => true,
            'extra_optimize' => false,
            'remove_ie_hacks' => false,
            'prefix' => array(
                'webkit' => true,
                'mozilla' => true,
                'opera' => true,
                'microsoft' => true,
            ),
        );
    }

    public function process($css) {
        $plugins = array(
            'Variables' => false,
            'ConvertFontWeight' => $this->_settings['optimize'],
            'ConvertHslColors' => $this->_settings['optimize'],
            'ConvertRgbColors' => $this->_settings['optimize'],
            'ConvertNamedColors' => $this->_settings['optimize'],
            'CompressUnitValues' => $this->_settings['optimize'],
            'CompressExpressionValues' => false,
            //Custom
            'CustomCompressColorValues' => $this->_settings['optimize'],
        );

        $filters = array(
            'ImportImports' => false,
            'RemoveComments' => $this->_settings['optimize'],
            'RemoveEmptyRulesets' => $this->_settings['optimize'],
            'RemoveEmptyAtBlocks' => $this->_settings['optimize'],
            'ConvertLevel3AtKeyframes' => $this->_settings['optimize'],
            'ConvertLevel3Properties' => false,
            'Variables' => false,
            'RemoveLastDelarationSemiColon' => $this->_settings['optimize'],
            'SortRulesetProperties' => $this->_settings['extra_optimize'],
            //Custom filters
            'AddVendorPrefix' => $this->_settings['prefix'],
            'CustomConvertLevel3Properties' => $this->_settings['prefix'],
            'RemoveIEHacks' => $this->_settings['remove_ie_hacks'],
                //   'OptimizeSelectorsCompressionRatio' => $this->_settings['extra_optimize'],
                //  'OptimizeRulesCompressionRatio' => $this->_settings['extra_optimize'],
        );


        $minifier = new CssMinifier($css, $filters, $plugins);

        if ($this->_settings['extra_optimize']) {
            // $filter=new CssOptimizeRulesCompressionRatioMinifierFilter($minifier);
            //   $filter->apply($minifier->getMinifiedTokens());
        }

        if (!$this->_settings['compress']) {//Format result
            $formatter = new CssOtbsFormatter($minifier->getMinifiedTokens(), "\t", 25);
            $min = (string) $formatter;
        } else {
            $min = $minifier->getMinified();
        }

        if (CssMin::hasErrors()) {
            $this->_errors = CssMin::getErrors();
        } else {
            $this->_errors = null;
        }

        return $min;
    }

    /**
     * Errors produced during the last execution 
     */
    public function errors() {
        return $this->_errors;
    }

}

class CssCustomCompressColorValuesMinifierPlugin extends CssCompressColorValuesMinifierPlugin {

    /**
     * Regular expression matching 6 char hexadecimal color values.
     * 
     * @var string
     */
    private $reMatch = "/\#([0-9a-f]{6})/iS";

    /**
     * Implements {@link aCssMinifierPlugin::minify()}.
     * 
     * @param aCssToken $token Token to process
     * @return boolean Return TRUE to break the processing of this token; FALSE to continue
     */
    public function apply(aCssToken &$token) {
        if (strpos($token->Value, "#") !== false) {

            //Ignore IE filters colors, they needs full hex colors
            if ($token instanceof aCssDeclarationToken && strpos($token->Property, "filter") !== false)
                return false;

            preg_match_all($this->reMatch, $token->Value, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                $value = strtolower($m[1]);
                if ($value[0] == $value[1] && $value[2] == $value[3] && $value[4] == $value[5]) {
                    $token->Value = str_replace($m[0], "#" . $value[0] . $value[2] . $value[4], $token->Value);
                }
            }
        }
        return false;
    }

}

class CssCustomConvertLevel3PropertiesMinifierFilter extends CssConvertLevel3PropertiesMinifierFilter {

    function __construct(CssMinifier $minifier, array $configuration = array()) {
        parent::__construct($minifier, $configuration);

        //Change the transformations array for comply the settings
        foreach ($this->transformations as $key => $transformation) {
            if (count($transformation) == 2) {
                if ($transformation[1] == 'opacity' && $configuration['microsoft'] == false)
                    $transformation = array(null, null, null, null);
                else if ($transformation[1] == 'filter' && $configuration['microsoft'] == false)
                    $transformation = array(null, null, null, null);
                else if ($transformation[1] == 'whiteSpace')
                    $transformation[0] = __CLASS__;
            } else {
                //$transformation is Array(Mozilla, Webkit, Opera, Internet Explorer);
                if ($configuration['mozilla'] == false)
                    $transformation[0] = null;

                if ($configuration['webkit'] == false)
                    $transformation[1] = null;

                if ($configuration['opera'] == false)
                    $transformation[2] = null;

                if ($configuration['microsoft'] == false)
                    $transformation[3] = null;
            }

            $this->transformations[$key] = $transformation;
        }
    }

    /**
     * Transforms "white-space: pre-wrap" into browser specific counterparts.
     * 
     * @param aCssToken $token
     * @return array
     */
    public static function whiteSpace($token) {
        if (strtolower($token->Value) === "pre-wrap") {
            $r = array();

            if ($this->configuration['mozilla']) // Firefox < 3
                $r [] = new CssRulesetDeclarationToken("white-space", "-moz-pre-wrap", $token->MediaTypes);

            if ($this->configuration['webkit']) // Webkit
                $r [] = new CssRulesetDeclarationToken("white-space", "-webkit-pre-wrap", $token->MediaTypes);

            if ($this->configuration['opera']) {
                // Opera >= 4 <= 6
                $r [] = new CssRulesetDeclarationToken("white-space", "-pre-wrap", $token->MediaTypes);
                // Opera >= 7
                $r [] = new CssRulesetDeclarationToken("white-space", "-o-pre-wrap", $token->MediaTypes);
            }

            if ($this->configuration['microsoft']) // Internet Explorer >= 5.5
                $r [] = new CssRulesetDeclarationToken("word-wrap", "break-word", $token->MediaTypes);

            return $r;
        } else {
            return array();
        }
    }

}

class CssAddVendorPrefixMinifierFilter extends aCssMinifierFilter {

    function __construct(CssMinifier $minifier, array $configuration = array()) {
        parent::__construct($minifier, $configuration);
    }

    public function apply(array &$tokens) {
        $prefixes = array();
        $replacements = array(
            'webkit' => '-webkit-',
            'mozilla' => '-moz-',
            'opera' => '-o-',
            'microsoft' => '-ms-',
        );
        foreach ($replacements as $name => $prefix) {
            if (isset($this->configuration[$name]) && $this->configuration[$name])
                $prefixes[] = $prefix;
        }

        for ($i = 0, $l = count($tokens); $i < $l; $i++) {
            if (get_class($tokens[$i]) === "CssRulesetDeclarationToken") {

                $value = $tokens[$i]->Value;

                $result = null;
                if (preg_match('/(\s|^)((repeating-)?(radial|linear)-gradient)/i', $value, $match)) {
                    $result = array();
                    //Add w3c gradient formats
                    foreach ($prefixes as $prefix) {
                        $new_value = str_replace($match[2], $prefix . $match[2], $value);

                        //Check if repeated
                        $siblings = $this->sibling_rules($tokens, $i);
                        foreach ($siblings as $sibling) {
                            if (get_class($sibling) === "CssRulesetDeclarationToken" && $sibling->Property == $tokens[$i]->Property && $sibling->Value == $new_value) {
                                continue 2;
                            }
                        }
                        $tokens[$i]->IsLast = false;
                        $result[] = new CssRulesetDeclarationToken($tokens[$i]->Property, $new_value, $tokens[$i]->MediaTypes);
                    }

                    //Add old webkit format
                    $color_stops_regex = '(?<color>(rgb|hsl)a?\s*\([^\)]+\)|#[\da-f]+|\w+)\s+(?<unit>\d+(%|em|px|in|cm|mm|ex|em|pt|pc)?)';
                    if ($this->configuration['webkit']) {
                        //Examples
                        //
                    //Horizontal
                        //new: background: -webkit-linear-gradient(left, rgb(255,255,255) 0%,rgb(255,255,255) 98%); /* Chrome10+,Safari5.1+ */
                        //old: background: -webkit-gradient(linear, left top, right top, color-stop(0%,rgb(255,255,255)), color-stop(98%,rgb(255,255,255))); /* Chrome,Safari4+ */
                        //
                    //Vertical
                        //new: background: -webkit-linear-gradient(top, rgb(30,87,153) 0%,rgb(41,137,216) 50%,rgb(32,124,202) 51%,rgb(125,185,232) 100%); /* Chrome10+,Safari5.1+ */
                        //old: background: -webkit-gradient(linear, left top, left bottom, color-stop(0%,rgb(30,87,153)), color-stop(50%,rgb(41,137,216)), color-stop(51%,rgb(32,124,202)), color-stop(100%,rgb(125,185,232))); /* Chrome,Safari4+ */
                        //
                    //Radial
                        //new: background: -webkit-radial-gradient(center, ellipse cover, rgb(255,255,255) 0%,rgb(255,255,255) 98%); /* Chrome10+,Safari5.1+ */
                        //old: background: -webkit-gradient(radial, center center, 0px, center center, 100%, color-stop(0%,rgb(255,255,255)), color-stop(98%,rgb(255,255,255))); /* Chrome,Safari4+ */
                        $replacements = array(
                            'linear-gradient\s*\(' => '-webkit-gradient(linear, ',
                            'radial-gradient\s*\(' => '-webkit-gradient(radial, ',
                            'left\s*,' => 'left top, right top,',
                            'top\s*,' => 'left top, left bottom,',
                            '-45deg\s*,' => 'left top, right bottom,',
                            '45deg\s*,' => 'left bottom, right top,',
                            'ellipse\s+cover\s*,' => 'center center,',
                            $color_stops_regex => 'color-stop($3,$1)'
                        );
                        $webkit = $value;
                        foreach ($replacements as $search => $replace) {
                            $webkit = preg_replace("/$search/i", $replace, $webkit);
                        }
                        $result[] = new CssRulesetDeclarationToken($tokens[$i]->Property, $webkit, $tokens[$i]->MediaTypes);
                    }

                    //Old IE format
                    if ($this->configuration['microsoft']) {
                        preg_match_all("/$color_stops_regex/i", $value, $matches, PREG_SET_ORDER);
                        if (!empty($matches)) {
                            $first = reset($matches);
                            $last = end($matches);
                            $gradient_type = stripos($value, 'top') !== false ? 0 : 1;
                            $result[] = new CssRulesetDeclarationToken("filter", "progid:DXImageTransform.Microsoft.gradient( startColorstr='{$this->ie_color($first['color'])}', endColorstr='{$this->ie_color($last['color'])}',GradientType=$gradient_type)", $tokens[$i]->MediaTypes);
                        }
                    }
                }

                //Apply changes
                if (isset($result) && count($result) > 0) {
                    array_splice($tokens, $i + 1, 0, $result);
                    $i += count($result);
                    $l += count($result);
                }
            }
        }
    }

    private function sibling_rules(&$tokens, $pos) {
        //Search selector begin
        $start = $pos;
        for ($i = $pos; $i >= 0; $i--) {
            if (get_class($tokens[$i]) !== "CssRulesetStartToken") {
                continue;
            }
            $start = $i;
            break;
        }

        //Search end
        $end = $pos;
        for ($i = $pos, $l = count($tokens); $i < $l; $i++) {
            if (get_class($tokens[$i]) !== "CssRulesetEndToken") {
                continue;
            }
            $end = $i;
            break;
        }

        return array_slice($tokens, $start + 1, $end - $start - 1);
    }

    private function ie_color($color) {
        $color = trim($color);
        if (preg_match('/#\d+/', $color) && strlen($color) == 4) {
            return "#$color[1]$color[1]$color[2]$color[2]$color[3]$color[3]";
        }
        return $color;
    }

}

class CssRemoveIEHacksMinifierFilter extends aCssMinifierFilter {

    public function apply(array &$tokens) {
        $changes = 0;
        for ($i = 0, $l = count($tokens); $i < $l; $i++) {
            $token = &$tokens[$i];
            if ($token instanceof aCssDeclarationToken) {
                if (strcasecmp($token->Property, "filter") === 0 || strcasecmp($token->Property, "-ms-filter") === 0) {//Filter
                    $token = null;
                    $changes++;
                } else if ($token->Property[0] == '_' || $token->Property[0] == '*') {//Hack (_width, *background)
                    $token = null;
                    $changes++;
                } else if (stripos($token->Value, 'expression') === 0) {//CSS Expression
                    $token = null;
                    $changes++;
                }
            }
        }
        return $changes;
    }

}

/**
 * Sort all the rules in order to improve the compression ratio
 */
class CssOptimizeRulesCompressionRatioMinifierFilter extends aCssMinifierFilter {

    /**
     * Implements {@link aCssMinifierFilter::filter()}.
     * 
     * @param array $tokens Array of objects of type aCssToken
     * @return integer Count of added, changed or removed tokens; a return value larger than 0 will rebuild the array
     */
    public function apply(array &$tokens) {
        $r = 0;
        //Step 1: Sort rules
        for ($i = 0, $l = count($tokens); $i < $l; $i++) {
            // Only look for ruleset start rules
            if (get_class($tokens[$i]) !== "CssRulesetStartToken") {
                continue;
            }
            // Look for the corresponding ruleset end
            $endIndex = false;
            for ($ii = $i + 1; $ii < $l; $ii++) {
                if (get_class($tokens[$ii]) !== "CssRulesetEndToken") {
                    continue;
                }
                $endIndex = $ii;
                break;
            }
            if (!$endIndex) {
                break;
            }
            $startIndex = $i;
            $i = $endIndex;
            // Skip if there's only one token in this ruleset
            if ($endIndex - $startIndex <= 2) {
                continue;
            }
            // Ensure that everything between the start and end is a declaration token, for safety
            for ($ii = $startIndex + 1; $ii < $endIndex; $ii++) {
                if (get_class($tokens[$ii]) !== "CssRulesetDeclarationToken") {
                    continue(2);
                }
            }
            $declarations = array_slice($tokens, $startIndex + 1, $endIndex - $startIndex - 1);

            // Look for the best compression, looking in ALL possible permutations
            $this->_best_order = $this->_min_size = null;
            $this->_max_size = null;
            $this->permutations($declarations, array($this, '_check_permutation'), 1000);
            // echo "SAVING: <b>".($this->_max_size-$this->_min_size)."</b> MIN: $this->_min_size MAX: $this->_max_size ORIGINAL: ".implode('',$declarations)." NEW: ".implode('',$this->_best_order)."<br/>";

            if (isset($this->_best_order))
                $declarations = $this->_best_order;

            // Update "IsLast" property
            for ($ii = 0, $ll = count($declarations) - 1; $ii <= $ll; $ii++) {
                if ($ii == $ll) {
                    $declarations[$ii]->IsLast = true;
                } else {
                    $declarations[$ii]->IsLast = false;
                }
            }
            // Splice back into the array.
            array_splice($tokens, $startIndex + 1, $endIndex - $startIndex - 1, $declarations);
            $r += $endIndex - $startIndex - 1;
        }

        return $r;
    }

    /**
     * PHP function to generate all permutations
     * @see http://www.needcodefor.com/php/php-function-to-generate-all-permutations/
     * @param array $declarations
     * @return array 
     */
    private function permutations(array $set, $callback = null, $limit = 0) {
        $solutions = array();
        $n = count($set);
        $p = array_keys($set);
        $i = 1;
        $permutations = 1;

        //Include original order
        if (isset($callback))
            call_user_func($callback, $set);
        else
            $solutions[] = $set;

        while ($i < $n && ($limit == 0 || $permutations < $limit)) {
            if ($p[$i] > 0) {
                $p[$i]--;
                $j = 0;
                if ($i % 2 == 1)
                    $j = $p[$i];

                //Swap
                $tmp = $set[$j];
                $set[$j] = $set[$i];
                $set[$i] = $tmp;
                $i = 1;

                //Check permutation
                if (isset($callback))
                    call_user_func($callback, $set);
                else
                    $solutions[] = $set;
                $permutations++;
            } elseif ($p[$i] == 0) {
                $p[$i] = $i;
                $i++;
            }
        }
        return isset($callback) ? true : $solutions;
    }

    private $_best_order;
    private $_min_size = null;
    private $_max_size = null;

    /**
     * @access private
     */
    public function _check_permutation($declarations) {
        $css = implode('', $declarations);
        $compressed_css = gzdeflate($css, 3);
        if (!isset($this->_min_size) || strlen($compressed_css) < $this->_min_size) {
            $this->_best_order = $declarations;
            $this->_min_size = strlen($compressed_css);
        }
        if (!isset($this->_max_size) || strlen($compressed_css) > $this->_max_size) {
            $this->_max_size = strlen($compressed_css);
        }
    }

}

/**
 * Sort all the selectors in order to improve the compression ratio
 */
class CssOptimizeSelectorsCompressionRatioMinifierFilter extends aCssMinifierFilter {

    /**
     * Implements {@link aCssMinifierFilter::filter()}.
     * 
     * @param array $tokens Array of objects of type aCssToken
     * @return integer Count of added, changed or removed tokens; a return value larger than 0 will rebuild the array
     */
    public function apply(array &$tokens) {
        $r = 0;

        //Step 1: Split rules
        $chunks = array();
        $chunk = array();
        foreach ($tokens as $token) {
            $class = get_class($token);
            $chunk[] = $token;
            if ($class === "CssRulesetEndToken") {
                //Start new chunk
                $chunks[] = $chunk;
                $chunk = array();
            }
        }
        $chunks[] = $chunk;

        foreach ($chunks as $i => $chunk) {
            // echo '<h4>' . $i . '</h4>' . implode('', $chunk);
        }

        //Step 2: Reorder rules, looking for the best compression
        $best_seed = $seed = false;
        $min_size = null;
        for ($i = 0; $i < 1500; $i++) {
            //Shuffle array
            if ($i > 0) {
                $seed = mt_rand();
                srand($seed);
                shuffle($chunks);
            }

            //Generate css
            $css = array();
            foreach ($chunks as $chunk) {
                foreach ($chunk as $token) {
                    $css[] = $token;
                }
            }
            $css = implode('', $css);

            //Test compression
            $compressed_css = gzdeflate($css);
            if (!isset($min_size) || strlen($compressed_css) < $min_size) {
                $best_seed = $seed;
                $min_size = strlen($compressed_css);
            }
        }

        //Step 3: Shuffle array with the best seed and rebuild original
        if ($best_seed !== false) {
            srand($best_seed);
            shuffle($chunks);
            $tokens = array();
            foreach ($chunks as $chunk) {
                foreach ($chunk as $token) {
                    $tokens[] = $token;
                }
            }
        }

        return 1;
    }

}