<?php

/**
 * css_optimizer - Optimize, compress and add vendor prefixes in your CSS files for cross browser compatibility
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
 * @package         css_optimizer
 * @link            https://github.com/javiermarinros/css_optimizer
 * @version         2
 * @author          Javier Marín <https://github.com/javiermarinros>
 * @copyright       Javier Marín <https://github.com/javiermarinros>
 * @license         http://opensource.org/licenses/mit-license.php MIT License
 */
class css_optimizer {

    /**
     * Compress CSS code, removing unused whitespace and symbols
     * @var boolean 
     */
    public $compress = TRUE;

    /**
     * Remove comments
     * @var boolean 
     */
    public $remove_comments = TRUE;

    /**
     * Optimize CSS colors, units, etc.
     * @var boolean
     */
    public $optimize = TRUE;

    /**
     * Merge selectors, (may be unsafe)
     * @var type 
     */
    public $extra_optimize = FALSE;

    /**
     * Remove Internet Explorer hacks (filter, expressions, ...)
     * @var boolean
     */
    public $remove_ie_hacks = FALSE;

    /**
     * Remove empty groups and selectos
     * @var boolean
     */
    public $remove_empty = TRUE;
    public $prefixes = 'all';
    protected $_errors;

    public function __construct($settings = NULL) {
        if (isset($settings)) {
            foreach ($settings as $prop => $value) {
                $this->$prop = $value;
            }
        }
    }

    public function process($css) {
        //Parse CSS
        require_once 'css_parser.php';

        $parser = new css_parser();

        $css_doc = $parser->parse($css);

        //Remove comments
        if ($this->remove_comments) {
            foreach ($css_doc->find_all('css_element') as $element) {
                if ($element->type == 'comment') {
                    $element->remove();
                }
            }
        }

        //Lowercase all property names
        foreach ($css_doc->find_all('css_property') as $property) {
            $property->name = strtolower($property->name);
        }

        //Remove IE hacks
        if ($this->remove_ie_hacks) {
            $this->_remove_ie_hacks($css_doc);
        }

        //Optimize
        if ($this->optimize) {
            $this->_optimize($css_doc);
        }

        //Extra optimize
        if ($this->extra_optimize) {
            $this->_extra_optimize($css_doc);
        }

        //Add vendor prefixes
        if ($this->prefixes) {
            require_once 'css_prefixer.php';

            $prefixer = new css_prefixer;
            $options = explode(',', $this->prefixes);
            $prefixer->webkit = $this->prefixes == 'all' || in_array('webkit', $options);
            $prefixer->mozilla = $this->prefixes == 'all' || in_array('mozilla', $options);
            $prefixer->opera = $this->prefixes == 'all' || in_array('opera', $options);
            $prefixer->msie = $this->prefixes == 'all' || in_array('msie', $options);

            $prefixer->add_prefixes($css_doc);
        }

        return $css_doc->render($this->compress);
    }

    protected function _optimize(css_group $document) {
        require_once 'css_color.php';
        $color_regex = '/(^|\b)(\#[0-9A-Fa-f]{3,6}|\w+\(.*?\)|' . implode('|', array_map('preg_quote', array_keys(css_color::color_names()))) . ')($|\b)/i';

        foreach ($document->find_all('css_property') as $property) {
            //Optimize font-weight
            if (in_array($property->name, array('font', 'font-weight'))) {
                $transformation = array(
                    "normal" => "400",
                    "bold" => "700"
                );
                foreach ($transformation as $s => $r) {
                    $property->value = trim(preg_replace('#(^|\s)+(' . preg_quote($s, '#') . ')(\s|$)+#i', " $r ", $property->value));
                }
            }

            //Optimize colors       
            if (!in_array($property->name, array('filter', '-ms-filter'))) {
                $property->value = preg_replace_callback($color_regex, array($this, '_compress_color'), $property->value);
            }

            //Optimize background position
            if (in_array($property->name, array('background-position'))) {
                $property->value = str_replace(array(
                    'top left', 'top center', 'top right',
                    'center left', 'center center', 'center right',
                    'bottom left', 'bottom center', 'bottom right'
                        ), array(
                    '0 0', '50% 0', '100% 0',
                    '0 50%', '50% 50%', '100% 50%',
                    '0 100%', '50% 100%', '100% 100%'
                        ), $property->value);

                $property->value = str_replace(array(' top', ' left', ' center', ' right', ' bottom'), array(' 0', ' 0', ' 50%', ' 100%', ' 100%'), $property->value);
            }

            //Use shorthand anotation
            $this->_shorthand($property);

            //Optimize units
            //0.5% -> .5%
            $property->value = preg_replace('#\b0+(\.\d+(px|em|ex|%|in|cm|mm|pt|pc))(\b|$)#i', '$1', $property->value);
            //Combine to turn things like "margin: 10px 10px 10px 10px" into "margin: 10px"
            $css_unit = '\d+(?:\.\d+)?(?:px|em|ex|%|in|cm|mm|pt|pc)';
            $property->value = preg_replace("/^($css_unit)\s+($css_unit)\s+($css_unit)\s+\\2$/", '$1 $2 $3', $property->value); // Make from 4 to 3
            $property->value = preg_replace("/^($css_unit)\s+($css_unit)\s+\\1$/", '$1 $2', $property->value); // Make from 3 to 2
            $property->value = preg_replace("/^($css_unit)\s+\\1$/", '$1', $property->value); // Make from 2 to 1
            //0px -> 0
            $property->value = preg_replace('#\b0+(px|em|ex|%|in|cm|mm|pt|pc)\b#i', '0', $property->value);
        }

        //Remove empty groups
        foreach ($document->find_all('css_group') as $group) {
            if (empty($group->children)) {
                $group->remove();
            }
        }
    }

    private function _compress_color($color_match) {
        $color = new css_color($color_match[0]);
        if ($color->valid && $color->a == 1) {
            $hex = $color->to_hex();
            if (strlen($hex) < strlen($color_match[0])) {
                return $hex;
            }
        }
        return $color_match[0];
    }

    private function _shorthand(css_property $property) {
        $shorthands = array(
            'background' => array(
                'background-color',
                'background-image',
                'background-repeat',
                'background-position',
                'background-attachment',
            ),
            'font' => array(
                'font-style',
                'font-variant',
                'font-weight',
                'font-size',
                'line-height',
                'font-family'
            ),
            /*   'border' => array( //Problem with multiple border -> border-style: solid; border-width: 100px 100px 0 100px; border-color: #007bff transparent transparent transparent;
              'border-width',
              'border-style',
              'border-color'
              ), */
            'margin' => array(
                'margin-top',
                'margin-right',
                'margin-bottom',
                'margin-left',
            ),
            'padding' => array(
                'padding-top',
                'padding-right',
                'padding-bottom',
                'padding-left',
            ),
            'list-style' => array(
                'list-style-type',
                'list-style-position',
                'list-style-image',
            ),
            'border-width' => array(
                'border-top-width',
                'border-right-width',
                'border-bottom-width',
                'border-left-width',
            ),
            'border-radius' => array(
                'border-top-left-radius',
                'border-top-right-radius',
                'border-bottom-right-radius',
                'border-bottom-left-radius',
            )
        );

        foreach ($shorthands as $shorthand => $shorthand_properties) {
            if (in_array($property->name, $shorthand_properties)) {
                //All properties must be defined in order to use the shorthand version
                $properties = array();
                $siblings = $property->siblings('css_property', TRUE);
                foreach ($shorthand_properties as $name) {
                    $found = FALSE;
                    foreach ($siblings as $sibling) {
                        if ($sibling->name == $name) {
                            $properties[] = $sibling;
                            $found = TRUE;
                            break;
                        }
                    }
                    if (!$found) {
                        break;
                    }
                }

                if ($found && count($properties) == count($shorthand_properties)) {
                    //Replace with shorthand
                    $values = array();
                    foreach ($properties as $p) {
                        $values[] = $p->value;
                        if ($p != $property) {
                            $p->remove();
                        }
                    }
                    $property->name = $shorthand;
                    $property->value = implode(' ', $values);
                }
            }
        }
    }

    /**
     * @see http://net.tutsplus.com/tutorials/html-css-techniques/quick-tip-how-to-target-ie6-ie7-and-ie8-uniquely-with-4-characters/
     */
    protected function _remove_ie_hacks(css_group $document) {
        foreach ($document->find_all('css_property') as $property) {
            $is_hack = in_array($property->name, array('filter', '-ms-filter'))//Filter
                    || in_array($property->name[0], array('*', '_'))//Hack (_width, *background)
                    || stripos($property->value, 'expression') === 0 //CSS Expression
                    || substr($property->value, -2) === '\9'; //IE8 Hack

            if ($is_hack) {
                $property->remove();
            }
        }
    }

    protected function _extra_optimize($css_doc) {
        //Merge selectors
        $dummy_selector = 'selector';
        foreach ($css_doc->find_all('css_group') as $group) {
            $reference = $group->make_clone();
            $reference->name = $dummy_selector;
            $reference = $reference->render();

            foreach ($group->siblings('css_group') as $sibling) {
                $sibling_content = $sibling->make_clone();
                $sibling_content->name = $dummy_selector;
                $sibling_content = $sibling_content->render();

                if ($reference == $sibling_content) {
                    $group->name.=',' . $sibling->name;
                    $sibling->remove();
                }
            }
        }
    }

}
