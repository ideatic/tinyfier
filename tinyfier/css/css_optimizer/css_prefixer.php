<?php

class css_prefixer {

    public $webkit = TRUE;
    public $mozilla = TRUE;
    public $opera = TRUE;
    public $msie = TRUE;

    public function add_prefixes($css_doc) {
        return $this->_add_prefixes($css_doc);
    }

    /**
     * @param $css_doc
     */
    protected function _add_prefixes(css_group $css_doc, $vendor_override = NULL, $ignore_keyframes = FALSE, $remove_original_property = FALSE) {
        $vendors_ids = array(
            1 => 'webkit',
            0 => 'mozilla',
            2 => 'opera',
            3 => 'msie',
        );
        $originals = array();
        $apply_vendors = array();
        foreach ($vendors_ids as $id => $prop) {
            $originals[$prop] = $this->$prop;
            if (isset($vendor_override)) {
                $this->$prop = isset($vendor_override[$id]) ? $vendor_override[$id] : (isset($vendor_override[$prop]) ? $vendor_override[$prop] : FALSE);
            }
            $apply_vendors[$id] = $this->$prop;
        }

        foreach ($css_doc->find_all('css_property') as $property) {
            /* @var $property css_property */
            //Check if property is inside a @keyframes
            $keyframe = NULL;
            if (!$ignore_keyframes) {
                foreach ($property->parents() as $parent) {
                    if ($parent instanceof css_group && stripos($parent->name, '@keyframes') === 0) {
                        $keyframe = $parent;
                    }
                }
            }

            if (isset($keyframe)) {
                //Create vendor keyframes, each one with its own vendor prefixes
                $this->_prefix_keyframe($keyframe, $apply_vendors);
            } else if (array_key_exists($property->name, $this->_transformations)) {
                $applied = $this->_apply_transformation($property, $vendors_ids);

                if ($applied && $remove_original_property)
                    $property->remove();
            } else {
                //Replace vendor functions (gradients)
                $this->_prefix_gradients($property, $vendors_ids);
            }
        }

        //Restore original values
        foreach ($originals as $prop => $value) {
            $this->$prop = $value;
        }
    }

    private function _ie_filter_color($color) {
        $color = trim($color);
        if (preg_match('/#[0-9a-f]+/i', $color)) {
            if (strlen($color) == 4) {
                $color = "#FF$color[1]$color[1]$color[2]$color[2]$color[3]$color[3]";
            } elseif (strlen($color) == 7) {
                $color = "#FF$color[1]$color[2]$color[3]$color[4]$color[5]$color[6]";
            }
        }
        return strtoupper($color);
    }

    /**
     * @param $property
     * @param $vendors_ids
     * @return boolean
     */
    private function _apply_transformation($property, $vendors_ids) {
        $transformation = $this->_transformations[$property->name];
        $applied = FALSE;
        if (is_callable($transformation)) {
            call_user_func($transformation, $property, $this);
        } else {
            foreach ($transformation as $vendor_id => $new_name) {
                if ($new_name == NULL)
                    continue;

                $prop = $vendors_ids[$vendor_id];
                if ($this->$prop) {
                    //Check if property is not already defined
                    $already_defined = FALSE;
                    foreach ($property->siblings() as $sibling) {
                        if ($sibling->name == $new_name) {
                            $already_defined = TRUE;
                        }
                    }

                    //Create vendor prefix
                    if (!$already_defined) {
                        $property->insert_after(new css_property($new_name, $property->value));
                        $applied = TRUE;
                    }
                }
            }
        }
        return $applied;
    }

    private function _prefix_keyframe(css_group $keyframe, $apply_list) {
        $prefixes = array(
            3 => "@-ms-keyframes",
            2 => "@-o-keyframes",
            0 => "@-moz-keyframes",
            1 => "@-webkit-keyframes"
        );

        foreach ($prefixes as $id => $value) {
            if (isset($apply_list[$id]) && $apply_list[$id]) {
                $new_name = str_replace('@keyframes', $prefixes[$id], $keyframe->name);

                //Check if keyframe with prefix exists
                $found = FALSE;
                foreach ($keyframe->siblings('css_group') as $sibling) {
                    if ($sibling->name == $new_name) {
                        $found = TRUE;
                    }
                }

                if (!$found) {
                    //Create new keyframe only with prefix for the current vendor
                    $new_keyframe = $keyframe->make_clone();
                    $new_keyframe->name = $new_name;
                    $keyframe->insert_after($new_keyframe);
                    $this->_add_prefixes($new_keyframe, array($id => TRUE), TRUE, TRUE);
                }
            }
        }
    }

    /**
     * Transforms the Internet Explorer specific declaration property "filter" to Internet Explorer 8+ compatible
     * declaratiopn property "-ms-filter".
     */
    private static function filter(css_property $property, css_prefixer $prefixer) {
        if ($prefixer->msie) {
            $property->insert_after('-ms-filter', strpos($property->value, "'") === FALSE ? "'$property->value'" : '"' . $property->value . '"');
        }
    }

    /**
     * Transforms "opacity: {value}" into browser specific counterparts.
     */
    private static function opacity(css_property $property, css_prefixer $prefixer) {
        if ($prefixer->msie && is_numeric($property->value)) {
            $ie_value = (int) ((float) $property->value * 100);

            // Internet Explorer >= 8
            $property->insert_after('-ms-filter', "\"alpha(opacity=" . $ie_value . ")\"");
            // Internet Explorer >= 4 <= 7
            $property->insert_after('filter', "alpha(opacity=" . $ie_value . ")");
            $property->insert_after('zoom', '1');
        }
    }

    /**
     * Transforms "white-space: pre-wrap" into browser specific counterparts.
     */
    private static function whiteSpace(css_property $property, css_prefixer $prefixer) {
        if (strtolower($property->value) === "pre-wrap") {
            // Firefox < 3
            if ($prefixer->mozilla)
                $property->insert_after("white-space", "-moz-pre-wrap");
            // Webkit
            if ($prefixer->webkit)
                $property->insert_after("white-space", "-webkit-pre-wrap");
            if ($prefixer->opera) {
                // Opera >= 4 <= 6
                $property->insert_after("white-space", "-pre-wrap");
                // Opera >= 7
                $property->insert_after("white-space", "-o-pre-wrap");
            }
            // Internet Explorer >= 5.5
            if ($prefixer->msie)
                $property->insert_after("word-wrap", "break-word");
        }
    }

    /**
     * @param $property
     * @param $vendors_ids
     * @return mixed
     */
    protected function _prefix_gradients($property, $vendors_ids) {
        $gradient_transforms = array(
            'linear-gradient' => array('-moz-linear-gradient', '-webkit-linear-gradient', '-o-linear-gradient', NULL),
            'repeating-linear-gradient' => array('-moz-repeating-linear-gradient', '-webkit-repeating-linear-gradient', '-o-linear-repeating-gradient', NULL),
            'radial-gradient' => array('-moz-radial-gradient', '-webkit-radial-gradient', '-o-radial-gradient', NULL),
            'repeating-radial-gradient' => array('-moz-repeating-radial-gradient', '-webkit-repeating-radial-gradient', '-o-radial-repeating-gradient', NULL)
        );
        foreach ($gradient_transforms as $function => $transformations) {
            $regex = '/\b(?<!-)' . preg_quote($function) . '\b/i';
            if (preg_match($regex, $property->value)) {
                foreach ($transformations as $vendor_id => $new_name) {
                    if ($new_name == NULL)
                        continue;

                    $new_value = preg_replace($regex, $new_name, $property->value);
                    $new_value = strtr($new_value, array(
                        'to bottom' => 'top',
                        'to right' => 'left',
                    ));

                    $prop = $vendors_ids[$vendor_id];
                    if ($this->$prop) {
                        //Check if value is not already defined
                        $already_defined = FALSE;
                        foreach ($property->siblings() as $sibling) {
                            if ($property->value == $new_value) {
                                $already_defined = TRUE;
                            }
                        }

                        //Create vendor prefix
                        if (!$already_defined) {
                            $property->insert_after(new css_property($property->name, $new_value));
                        }
                    }
                }

                //Old webkit format (buggy)
                $color_stops_regex = '(?<color>(rgb|hsl)a?\s*\([^\)]+\)|#[\da-f]+|\w+)\s+(?<unit>\d+(%|em|px|in|cm|mm|ex|em|pt|pc)?)';

                //Old IE format (buggy)
                if ($this->msie) {
                    preg_match_all("/$color_stops_regex/i", $property->value, $matches, PREG_SET_ORDER);
                    if (!empty($matches)) {
                        $first = reset($matches);
                        $last = end($matches);
                        if ($first[0] == '#' && $last[0] == '#') {//Colors must be in HEX format
                            $gradient_type = stripos($value, 'top') !== FALSE ? 0 : 1;
                            $property->insert_after(new css_property('filter', "progid:DXImageTransform.Microsoft.gradient( startColorstr='{$this->_ie_filter_color($first['color'])}', endColorstr='{$this->_ie_filter_color($last['color'])}',GradientType=$gradient_type)"));
                        }
                    }
                }
            }
        }
    }

    protected $_transformations = array
        (
        // Property						Array(Mozilla, Webkit, Opera, Internet Explorer); NULL values are placeholders and will get ignored
        'animation' => array(NULL, '-webkit-animation', NULL, NULL),
        'animation-delay' => array(NULL, '-webkit-animation-delay', NULL, NULL),
        'animation-direction' => array(NULL, '-webkit-animation-direction', NULL, NULL),
        'animation-duration' => array(NULL, '-webkit-animation-duration', NULL, NULL),
        'animation-fill-mode' => array(NULL, '-webkit-animation-fill-mode', NULL, NULL),
        'animation-iteration-count' => array(NULL, '-webkit-animation-iteration-count', NULL, NULL),
        'animation-name' => array(NULL, '-webkit-animation-name', NULL, NULL),
        'animation-play-state' => array(NULL, '-webkit-animation-play-state', NULL, NULL),
        'animation-timing-function' => array(NULL, '-webkit-animation-timing-function', NULL, NULL),
        'appearance' => array('-moz-appearance', '-webkit-appearance', NULL, NULL),
        'backface-visibility' => array(NULL, '-webkit-backface-visibility', NULL, NULL),
        'background-clip' => array(NULL, '-webkit-background-clip', NULL, NULL),
        'background-composite' => array(NULL, '-webkit-background-composite', NULL, NULL),
        'background-inline-policy' => array('-moz-background-inline-policy', NULL, NULL, NULL),
        'background-origin' => array(NULL, '-webkit-background-origin', NULL, NULL),
        'background-position-x' => array(NULL, NULL, NULL, '-ms-background-position-x'),
        'background-position-y' => array(NULL, NULL, NULL, '-ms-background-position-y'),
        'background-size' => array(NULL, '-webkit-background-size', NULL, NULL),
        'behavior' => array(NULL, NULL, NULL, '-ms-behavior'),
        'binding' => array('-moz-binding', NULL, NULL, NULL),
        'border-after' => array(NULL, '-webkit-border-after', NULL, NULL),
        'border-after-color' => array(NULL, '-webkit-border-after-color', NULL, NULL),
        'border-after-style' => array(NULL, '-webkit-border-after-style', NULL, NULL),
        'border-after-width' => array(NULL, '-webkit-border-after-width', NULL, NULL),
        'border-before' => array(NULL, '-webkit-border-before', NULL, NULL),
        'border-before-color' => array(NULL, '-webkit-border-before-color', NULL, NULL),
        'border-before-style' => array(NULL, '-webkit-border-before-style', NULL, NULL),
        'border-before-width' => array(NULL, '-webkit-border-before-width', NULL, NULL),
        'border-border-bottom-colors' => array('-moz-border-bottom-colors', NULL, NULL, NULL),
        'border-bottom-left-radius' => array('-moz-border-radius-bottomleft', '-webkit-border-bottom-left-radius', NULL, NULL),
        'border-bottom-right-radius' => array('-moz-border-radius-bottomright', '-webkit-border-bottom-right-radius', NULL, NULL),
        'border-end' => array('-moz-border-end', '-webkit-border-end', NULL, NULL),
        'border-end-color' => array('-moz-border-end-color', '-webkit-border-end-color', NULL, NULL),
        'border-end-style' => array('-moz-border-end-style', '-webkit-border-end-style', NULL, NULL),
        'border-end-width' => array('-moz-border-end-width', '-webkit-border-end-width', NULL, NULL),
        'border-fit' => array(NULL, '-webkit-border-fit', NULL, NULL),
        'border-horizontal-spacing' => array(NULL, '-webkit-border-horizontal-spacing', NULL, NULL),
        'border-image' => array('-moz-border-image', '-webkit-border-image', NULL, NULL),
        'border-left-colors' => array('-moz-border-left-colors', NULL, NULL, NULL),
        'border-radius' => array('-moz-border-radius', '-webkit-border-radius', NULL, NULL),
        'border-top-right-radius' => array('-moz-border-radius-topright', '-webkit-border-top-right-radius', NULL, NULL),
        'border-top-left-radius' => array('-moz-border-radius-topleft', '-webkit-border-top-left-radius', NULL, NULL),
        'border-bottom-right-radius' => array('-moz-border-radius-bottomright', '-webkit-border-bottom-right-radius', NULL, NULL),
        'border-bottom-left-radius' => array('-moz-border-radius-bottomleft', '-webkit-border-bottom-left-radius', NULL, NULL),
        'border-border-right-colors' => array('-moz-border-right-colors', NULL, NULL, NULL),
        'border-start' => array('-moz-border-start', '-webkit-border-start', NULL, NULL),
        'border-start-color' => array('-moz-border-start-color', '-webkit-border-start-color', NULL, NULL),
        'border-start-style' => array('-moz-border-start-style', '-webkit-border-start-style', NULL, NULL),
        'border-start-width' => array('-moz-border-start-width', '-webkit-border-start-width', NULL, NULL),
        'border-top-colors' => array('-moz-border-top-colors', NULL, NULL, NULL),
        'border-top-left-radius' => array('-moz-border-radius-topleft', '-webkit-border-top-left-radius', NULL, NULL),
        'border-top-right-radius' => array('-moz-border-radius-topright', '-webkit-border-top-right-radius', NULL, NULL),
        'border-vertical-spacing' => array(NULL, '-webkit-border-vertical-spacing', NULL, NULL),
        'box-align' => array('-moz-box-align', '-webkit-box-align', NULL, NULL),
        'box-direction' => array('-moz-box-direction', '-webkit-box-direction', NULL, NULL),
        'box-flex' => array('-moz-box-flex', '-webkit-box-flex', NULL, NULL),
        'box-flex-group' => array(NULL, '-webkit-box-flex-group', NULL, NULL),
        'box-flex-lines' => array(NULL, '-webkit-box-flex-lines', NULL, NULL),
        'box-ordinal-group' => array('-moz-box-ordinal-group', '-webkit-box-ordinal-group', NULL, NULL),
        'box-orient' => array('-moz-box-orient', '-webkit-box-orient', NULL, NULL),
        'box-pack' => array('-moz-box-pack', '-webkit-box-pack', NULL, NULL),
        'box-reflect' => array(NULL, '-webkit-box-reflect', NULL, NULL),
        'box-shadow' => array('-moz-box-shadow', '-webkit-box-shadow', NULL, NULL),
        'box-sizing' => array('-moz-box-sizing', NULL, NULL, NULL),
        'color-correction' => array(NULL, '-webkit-color-correction', NULL, NULL),
        'column-break-after' => array(NULL, '-webkit-column-break-after', NULL, NULL),
        'column-break-before' => array(NULL, '-webkit-column-break-before', NULL, NULL),
        'column-break-inside' => array(NULL, '-webkit-column-break-inside', NULL, NULL),
        'column-count' => array('-moz-column-count', '-webkit-column-count', NULL, NULL),
        'column-gap' => array('-moz-column-gap', '-webkit-column-gap', NULL, NULL),
        'column-rule' => array('-moz-column-rule', '-webkit-column-rule', NULL, NULL),
        'column-rule-color' => array('-moz-column-rule-color', '-webkit-column-rule-color', NULL, NULL),
        'column-rule-style' => array('-moz-column-rule-style', '-webkit-column-rule-style', NULL, NULL),
        'column-rule-width' => array('-moz-column-rule-width', '-webkit-column-rule-width', NULL, NULL),
        'column-span' => array(NULL, '-webkit-column-span', NULL, NULL),
        'column-width' => array('-moz-column-width', '-webkit-column-width', NULL, NULL),
        'columns' => array(NULL, '-webkit-columns', NULL, NULL),
        'filter' => array(__CLASS__, 'filter'),
        'float-edge' => array('-moz-float-edge', NULL, NULL, NULL),
        'font-feature-settings' => array('-moz-font-feature-settings', NULL, NULL, NULL),
        'font-language-override' => array('-moz-font-language-override', NULL, NULL, NULL),
        'font-size-delta' => array(NULL, '-webkit-font-size-delta', NULL, NULL),
        'font-smoothing' => array(NULL, '-webkit-font-smoothing', NULL, NULL),
        'force-broken-image-icon' => array('-moz-force-broken-image-icon', NULL, NULL, NULL),
        'highlight' => array(NULL, '-webkit-highlight', NULL, NULL),
        'hyphenate-character' => array(NULL, '-webkit-hyphenate-character', NULL, NULL),
        'hyphenate-locale' => array(NULL, '-webkit-hyphenate-locale', NULL, NULL),
        'hyphens' => array(NULL, '-webkit-hyphens', NULL, NULL),
        'force-broken-image-icon' => array('-moz-image-region', NULL, NULL, NULL),
        'ime-mode' => array(NULL, NULL, NULL, '-ms-ime-mode'),
        'interpolation-mode' => array(NULL, NULL, NULL, '-ms-interpolation-mode'),
        'layout-flow' => array(NULL, NULL, NULL, '-ms-layout-flow'),
        'layout-grid' => array(NULL, NULL, NULL, '-ms-layout-grid'),
        'layout-grid-char' => array(NULL, NULL, NULL, '-ms-layout-grid-char'),
        'layout-grid-line' => array(NULL, NULL, NULL, '-ms-layout-grid-line'),
        'layout-grid-mode' => array(NULL, NULL, NULL, '-ms-layout-grid-mode'),
        'layout-grid-type' => array(NULL, NULL, NULL, '-ms-layout-grid-type'),
        'line-break' => array(NULL, '-webkit-line-break', NULL, '-ms-line-break'),
        'line-clamp' => array(NULL, '-webkit-line-clamp', NULL, NULL),
        'line-grid-mode' => array(NULL, NULL, NULL, '-ms-line-grid-mode'),
        'logical-height' => array(NULL, '-webkit-logical-height', NULL, NULL),
        'logical-width' => array(NULL, '-webkit-logical-width', NULL, NULL),
        'margin-after' => array(NULL, '-webkit-margin-after', NULL, NULL),
        'margin-after-collapse' => array(NULL, '-webkit-margin-after-collapse', NULL, NULL),
        'margin-before' => array(NULL, '-webkit-margin-before', NULL, NULL),
        'margin-before-collapse' => array(NULL, '-webkit-margin-before-collapse', NULL, NULL),
        'margin-bottom-collapse' => array(NULL, '-webkit-margin-bottom-collapse', NULL, NULL),
        'margin-collapse' => array(NULL, '-webkit-margin-collapse', NULL, NULL),
        'margin-end' => array('-moz-margin-end', '-webkit-margin-end', NULL, NULL),
        'margin-start' => array('-moz-margin-start', '-webkit-margin-start', NULL, NULL),
        'margin-top-collapse' => array(NULL, '-webkit-margin-top-collapse', NULL, NULL),
        'marquee ' => array(NULL, '-webkit-marquee', NULL, NULL),
        'marquee-direction' => array(NULL, '-webkit-marquee-direction', NULL, NULL),
        'marquee-increment' => array(NULL, '-webkit-marquee-increment', NULL, NULL),
        'marquee-repetition' => array(NULL, '-webkit-marquee-repetition', NULL, NULL),
        'marquee-speed' => array(NULL, '-webkit-marquee-speed', NULL, NULL),
        'marquee-style' => array(NULL, '-webkit-marquee-style', NULL, NULL),
        'mask' => array(NULL, '-webkit-mask', NULL, NULL),
        'mask-attachment' => array(NULL, '-webkit-mask-attachment', NULL, NULL),
        'mask-box-image' => array(NULL, '-webkit-mask-box-image', NULL, NULL),
        'mask-clip' => array(NULL, '-webkit-mask-clip', NULL, NULL),
        'mask-composite' => array(NULL, '-webkit-mask-composite', NULL, NULL),
        'mask-image' => array(NULL, '-webkit-mask-image', NULL, NULL),
        'mask-origin' => array(NULL, '-webkit-mask-origin', NULL, NULL),
        'mask-position' => array(NULL, '-webkit-mask-position', NULL, NULL),
        'mask-position-x' => array(NULL, '-webkit-mask-position-x', NULL, NULL),
        'mask-position-y' => array(NULL, '-webkit-mask-position-y', NULL, NULL),
        'mask-repeat' => array(NULL, '-webkit-mask-repeat', NULL, NULL),
        'mask-repeat-x' => array(NULL, '-webkit-mask-repeat-x', NULL, NULL),
        'mask-repeat-y' => array(NULL, '-webkit-mask-repeat-y', NULL, NULL),
        'mask-size' => array(NULL, '-webkit-mask-size', NULL, NULL),
        'match-nearest-mail-blockquote-color' => array(NULL, '-webkit-match-nearest-mail-blockquote-color', NULL, NULL),
        'max-logical-height' => array(NULL, '-webkit-max-logical-height', NULL, NULL),
        'max-logical-width' => array(NULL, '-webkit-max-logical-width', NULL, NULL),
        'min-logical-height' => array(NULL, '-webkit-min-logical-height', NULL, NULL),
        'min-logical-width' => array(NULL, '-webkit-min-logical-width', NULL, NULL),
        'object-fit' => array(NULL, NULL, '-o-object-fit', NULL),
        'object-position' => array(NULL, NULL, '-o-object-position', NULL),
        'opacity' => array(__CLASS__, 'opacity'),
        'outline-radius' => array('-moz-outline-radius', NULL, NULL, NULL),
        'outline-bottom-left-radius' => array('-moz-outline-radius-bottomleft', NULL, NULL, NULL),
        'outline-bottom-right-radius' => array('-moz-outline-radius-bottomright', NULL, NULL, NULL),
        'outline-top-left-radius' => array('-moz-outline-radius-topleft', NULL, NULL, NULL),
        'outline-top-right-radius' => array('-moz-outline-radius-topright', NULL, NULL, NULL),
        'padding-after' => array(NULL, '-webkit-padding-after', NULL, NULL),
        'padding-before' => array(NULL, '-webkit-padding-before', NULL, NULL),
        'padding-end' => array('-moz-padding-end', '-webkit-padding-end', NULL, NULL),
        'padding-start' => array('-moz-padding-start', '-webkit-padding-start', NULL, NULL),
        'perspective' => array(NULL, '-webkit-perspective', NULL, NULL),
        'perspective-origin' => array(NULL, '-webkit-perspective-origin', NULL, NULL),
        'perspective-origin-x' => array(NULL, '-webkit-perspective-origin-x', NULL, NULL),
        'perspective-origin-y' => array(NULL, '-webkit-perspective-origin-y', NULL, NULL),
        'rtl-ordering' => array(NULL, '-webkit-rtl-ordering', NULL, NULL),
        'scrollbar-3dlight-color' => array(NULL, NULL, NULL, '-ms-scrollbar-3dlight-color'),
        'scrollbar-arrow-color' => array(NULL, NULL, NULL, '-ms-scrollbar-arrow-color'),
        'scrollbar-base-color' => array(NULL, NULL, NULL, '-ms-scrollbar-base-color'),
        'scrollbar-darkshadow-color' => array(NULL, NULL, NULL, '-ms-scrollbar-darkshadow-color'),
        'scrollbar-face-color' => array(NULL, NULL, NULL, '-ms-scrollbar-face-color'),
        'scrollbar-highlight-color' => array(NULL, NULL, NULL, '-ms-scrollbar-highlight-color'),
        'scrollbar-shadow-color' => array(NULL, NULL, NULL, '-ms-scrollbar-shadow-color'),
        'scrollbar-track-color' => array(NULL, NULL, NULL, '-ms-scrollbar-track-color'),
        'stack-sizing' => array('-moz-stack-sizing', NULL, NULL, NULL),
        'svg-shadow' => array(NULL, '-webkit-svg-shadow', NULL, NULL),
        'tab-size' => array('-moz-tab-size', NULL, '-o-tab-size', NULL),
        'table-baseline' => array(NULL, NULL, '-o-table-baseline', NULL),
        'text-align-last' => array(NULL, NULL, NULL, '-ms-text-align-last'),
        'text-autospace' => array(NULL, NULL, NULL, '-ms-text-autospace'),
        'text-combine' => array(NULL, '-webkit-text-combine', NULL, NULL),
        'text-decorations-in-effect' => array(NULL, '-webkit-text-decorations-in-effect', NULL, NULL),
        'text-emphasis' => array(NULL, '-webkit-text-emphasis', NULL, NULL),
        'text-emphasis-color' => array(NULL, '-webkit-text-emphasis-color', NULL, NULL),
        'text-emphasis-position' => array(NULL, '-webkit-text-emphasis-position', NULL, NULL),
        'text-emphasis-style' => array(NULL, '-webkit-text-emphasis-style', NULL, NULL),
        'text-fill-color' => array(NULL, '-webkit-text-fill-color', NULL, NULL),
        'text-justify' => array(NULL, NULL, NULL, '-ms-text-justify'),
        'text-kashida-space' => array(NULL, NULL, NULL, '-ms-text-kashida-space'),
        'text-overflow' => array(NULL, NULL, '-o-text-overflow', '-ms-text-overflow'),
        'text-security' => array(NULL, '-webkit-text-security', NULL, NULL),
        'text-size-adjust' => array(NULL, '-webkit-text-size-adjust', NULL, '-ms-text-size-adjust'),
        'text-stroke' => array(NULL, '-webkit-text-stroke', NULL, NULL),
        'text-stroke-color' => array(NULL, '-webkit-text-stroke-color', NULL, NULL),
        'text-stroke-width' => array(NULL, '-webkit-text-stroke-width', NULL, NULL),
        'text-underline-position' => array(NULL, NULL, NULL, '-ms-text-underline-position'),
        'transform' => array('-moz-transform', '-webkit-transform', '-o-transform', '-ms-transform'),
        'transform-origin' => array('-moz-transform-origin', '-webkit-transform-origin', '-o-transform-origin', NULL),
        'transform-origin-x' => array(NULL, '-webkit-transform-origin-x', NULL, NULL),
        'transform-origin-y' => array(NULL, '-webkit-transform-origin-y', NULL, NULL),
        'transform-origin-z' => array(NULL, '-webkit-transform-origin-z', NULL, NULL),
        'transform-style' => array(NULL, '-webkit-transform-style', NULL, NULL),
        'transition' => array('-moz-transition', '-webkit-transition', '-o-transition', NULL),
        'transition-delay' => array('-moz-transition-delay', '-webkit-transition-delay', '-o-transition-delay', NULL),
        'transition-duration' => array('-moz-transition-duration', '-webkit-transition-duration', '-o-transition-duration', NULL),
        'transition-property' => array('-moz-transition-property', '-webkit-transition-property', '-o-transition-property', NULL),
        'transition-timing-function' => array('-moz-transition-timing-function', '-webkit-transition-timing-function', '-o-transition-timing-function', NULL),
        'user-drag' => array(NULL, '-webkit-user-drag', NULL, NULL),
        'user-focus' => array('-moz-user-focus', NULL, NULL, NULL),
        'user-input' => array('-moz-user-input', NULL, NULL, NULL),
        'user-modify' => array('-moz-user-modify', '-webkit-user-modify', NULL, NULL),
        'user-select' => array('-moz-user-select', '-webkit-user-select', NULL, NULL),
        'white-space' => array(__CLASS__, 'whiteSpace'),
        'window-shadow' => array('-moz-window-shadow', NULL, NULL, NULL),
        'word-break' => array(NULL, NULL, NULL, '-ms-word-break'),
        'word-wrap' => array(NULL, NULL, NULL, '-ms-word-wrap'),
        'writing-mode' => array(NULL, '-webkit-writing-mode', NULL, '-ms-writing-mode'),
        'zoom' => array(NULL, NULL, NULL, '-ms-zoom')
    );

}
