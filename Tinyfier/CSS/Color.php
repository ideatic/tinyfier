<?php

/**
 * Represents a CSS Color
 * 
 * @note based on http://www.phpied.com/rgb-color-parser-in-javascript/
 */
class Tinyfier_CSS_Color {

    public $valid = FALSE;
    public $r, $g, $b, $a = 1;

    public function __construct($color) {
        $this->parse($color);
    }

    /**
     * Parse the specified color. Valid formats: rgb, rgba, hex, hsl, blue/white/...
     * @param string $color
     * @return boolean
     */
    public function parse($color) {
        $this->valid = FALSE;
        if (is_array($color)) {
            if (count($color) == 3 || count($color) == 4) {
                $this->r = $color[0];
                $this->g = $color[1];
                $this->b = $color[2];
                $this->a = isset($color[3]) ? $color[3] : 1;
            }
        } else {
            $this->r = $this->g = $this->b = 0;
            $this->a = 1;

            //Clean input
            if ($color[0] == '#') {
                $color = substr($color, 1);
            }
            $color = strtolower(str_replace(' ', '', $color));

            //Check if is a color name
            $names = self::color_names();
            if (isset($names[$color])) {
                $color = $names[$color];
            }

            //Define formats
            $formats = array(
                array(
                    //'rgb(123, 234, 45)', 'rgb(255,234,245)', 'rgba(255,234,245,0.5)'
                    'regex' => '/^rgba?\s*\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)(?:\s*,\s*(\d+(?:\.\d+)?))?\s*\)$/i',
                    'callback' => '_parse_rgba'
                ),
                array(
                    //'#00ff00', '336699'
                    'regex' => '/^(\w{2})(\w{2})(\w{2})$/',
                    'callback' => '_parse_hex'
                ),
                array(
                    //'#fb0', 'f0f'
                    'regex' => '/^(\w{1})(\w{1})(\w{1})$/',
                    'callback' => '_parse_hex_short'
                ),
                array(
                    //hsl(0, 100%, 50%);
                    'regex' => '/^hsl\s*\(\s*(\d+)\s*,\s*(\d+)%\s*,\s*(\d+)%\s*\)$/i',
                    'callback' => '_parse_hsl'
                )
            );

            //Find the current format
            $this->valid = FALSE;
            foreach ($formats as $format) {
                if (preg_match($format['regex'], $color, $match)) {
                    $callback = $format['callback'];
                    $this->$callback($match);

                    //Clean color values
                    foreach (array('r' => 255, 'g' => 255, 'b' => 255, 'a' => 1) as $prop => $max) {
                        $v = $this->$prop;
                        $this->$prop = $v < 0 || is_nan($v) ? 0 : min($v, $max);
                    }

                    $this->valid = TRUE;
                    break;
                }
            }
        }
        return $this->valid;
    }

    private function _parse_rgba($match) {
        $this->r = intval($match[1]);
        $this->g = intval($match[2]);
        $this->b = intval($match[3]);
        $this->a = !isset($match[4]) || $match[4] == '' ? 1 : floatval($match[4]);
    }

    private function _parse_hex($match) {
        $this->r = hexdec($match[1]);
        $this->g = hexdec($match[2]);
        $this->b = hexdec($match[3]);
    }

    private function _parse_hex_short($match) {
        $this->r = hexdec($match[1] . $match[1]);
        $this->g = hexdec($match[2] . $match[2]);
        $this->b = hexdec($match[3] . $match[3]);
    }

    /**
     * Convert a HSL value to RGB
     * 
     * Based on: {@link http://www.easyrgb.com/index.php?X=MATH&H=19#text19}.ss Lightnesss
     * @return string
     */
    private function _parse_hsl($match) {
        list($hue, $saturation, $lightness) = array($match[1], $match[2], $match[3]);

        $hue = $hue / 360;
        $saturation = $saturation / 100;
        $lightness = $lightness / 100;
        if ($saturation == 0) {
            $this->r = $lightness * 255;
            $this->g = $lightness * 255;
            $this->b = $lightness * 255;
        } else {
            if ($lightness < 0.5) {
                $v2 = $lightness * (1 + $saturation);
            } else {
                $v2 = ($lightness + $saturation) - ($saturation * $lightness);
            }
            $v1 = 2 * $lightness - $v2;
            $this->r = 255 * $this->_hue2rgb($v1, $v2, $hue + (1 / 3));
            $this->g = 255 * $this->_hue2rgb($v1, $v2, $hue);
            $this->b = 255 * $this->_hue2rgb($v1, $v2, $hue - (1 / 3));
        }
    }

    private function _hue2rgb($v1, $v2, $hue) {
        if ($hue < 0) {
            $hue += 1;
        }
        if ($hue > 1) {
            $hue -= 1;
        }
        if ((6 * $hue) < 1) {
            return ($v1 + ($v2 - $v1) * 6 * $hue);
        }
        if ((2 * $hue) < 1) {
            return ($v2);
        }
        if ((3 * $hue) < 2) {
            return ($v1 + ($v2 - $v1) * (( 2 / 3) - $hue) * 6);
        }
        return $v1;
    }

    /**
     * Get the color in CSS rgb/rgba format
     * @return string
     */
    public function to_rgb($alpha = TRUE) {
        if ($alpha && $this->a != 1) {
            return "rgba($this->r, $this->g, $this->b, $this->a)";
        } else {
            return "rgb($this->r, $this->g, $this->b)";
        }
    }

    /**
     * Get the color in HEX format
     * @return string
     */
    public function to_hex($allow_short = TRUE) {
        $hex = "#";
        $hex.= str_pad(dechex($this->r), 2, "0", STR_PAD_LEFT);
        $hex.= str_pad(dechex($this->g), 2, "0", STR_PAD_LEFT);
        $hex.= str_pad(dechex($this->b), 2, "0", STR_PAD_LEFT);

        if ($allow_short && $hex[1] == $hex[2] && $hex[3] == $hex[4] && $hex[5] == $hex[6]) {
            $hex = "#{$hex[1]}{$hex[3]}{$hex[5]}";
        }

        return $hex;
    }

    /**
     * Get the color in a RGB/RGBA array
     * @return array
     */
    public function to_array($alpha = TRUE) {
        if ($alpha && $this->a != 1) {
            return array($this->r, $this->g, $this->b, $this->a);
        } else {
            return array($this->r, $this->g, $this->b);
        }
    }

    /**
     * Parse the input color
     * @param string $color
     * @return self
     */
    public static function create($color) {
        return new self($color);
    }

    /**
     * List of common color names used in HTML and CSS
     * @return array
     */
    public static function color_names() {
        return array(
            'aliceblue' => 'f0f8ff',
            'antiquewhite' => 'faebd7',
            'aqua' => '00ffff',
            'aquamarine' => '7fffd4',
            'azure' => 'f0ffff',
            'beige' => 'f5f5dc',
            'bisque' => 'ffe4c4',
            'black' => '000000',
            'blanchedalmond' => 'ffebcd',
            'blue' => '0000ff',
            'blueviolet' => '8a2be2',
            'brown' => 'a52a2a',
            'burlywood' => 'deb887',
            'cadetblue' => '5f9ea0',
            'chartreuse' => '7fff00',
            'chocolate' => 'd2691e',
            'coral' => 'ff7f50',
            'cornflowerblue' => '6495ed',
            'cornsilk' => 'fff8dc',
            'crimson' => 'dc143c',
            'cyan' => '00ffff',
            'darkblue' => '00008b',
            'darkcyan' => '008b8b',
            'darkgoldenrod' => 'b8860b',
            'darkgray' => 'a9a9a9',
            'darkgreen' => '006400',
            'darkkhaki' => 'bdb76b',
            'darkmagenta' => '8b008b',
            'darkolivegreen' => '556b2f',
            'darkorange' => 'ff8c00',
            'darkorchid' => '9932cc',
            'darkred' => '8b0000',
            'darksalmon' => 'e9967a',
            'darkseagreen' => '8fbc8f',
            'darkslateblue' => '483d8b',
            'darkslategray' => '2f4f4f',
            'darkturquoise' => '00ced1',
            'darkviolet' => '9400d3',
            'deeppink' => 'ff1493',
            'deepskyblue' => '00bfff',
            'dimgray' => '696969',
            'dodgerblue' => '1e90ff',
            'feldspar' => 'd19275',
            'firebrick' => 'b22222',
            'floralwhite' => 'fffaf0',
            'forestgreen' => '228b22',
            'fuchsia' => 'ff00ff',
            'gainsboro' => 'dcdcdc',
            'ghostwhite' => 'f8f8ff',
            'gold' => 'ffd700',
            'goldenrod' => 'daa520',
            'gray' => '808080',
            'green' => '008000',
            'greenyellow' => 'adff2f',
            'honeydew' => 'f0fff0',
            'hotpink' => 'ff69b4',
            'indianred' => 'cd5c5c',
            'indigo' => '4b0082',
            'ivory' => 'fffff0',
            'khaki' => 'f0e68c',
            'lavender' => 'e6e6fa',
            'lavenderblush' => 'fff0f5',
            'lawngreen' => '7cfc00',
            'lemonchiffon' => 'fffacd',
            'lightblue' => 'add8e6',
            'lightcoral' => 'f08080',
            'lightcyan' => 'e0ffff',
            'lightgoldenrodyellow' => 'fafad2',
            'lightgrey' => 'd3d3d3',
            'lightgreen' => '90ee90',
            'lightpink' => 'ffb6c1',
            'lightsalmon' => 'ffa07a',
            'lightseagreen' => '20b2aa',
            'lightskyblue' => '87cefa',
            'lightslateblue' => '8470ff',
            'lightslategray' => '778899',
            'lightsteelblue' => 'b0c4de',
            'lightyellow' => 'ffffe0',
            'lime' => '00ff00',
            'limegreen' => '32cd32',
            'linen' => 'faf0e6',
            'magenta' => 'ff00ff',
            'maroon' => '800000',
            'mediumaquamarine' => '66cdaa',
            'mediumblue' => '0000cd',
            'mediumorchid' => 'ba55d3',
            'mediumpurple' => '9370d8',
            'mediumseagreen' => '3cb371',
            'mediumslateblue' => '7b68ee',
            'mediumspringgreen' => '00fa9a',
            'mediumturquoise' => '48d1cc',
            'mediumvioletred' => 'c71585',
            'midnightblue' => '191970',
            'mintcream' => 'f5fffa',
            'mistyrose' => 'ffe4e1',
            'moccasin' => 'ffe4b5',
            'navajowhite' => 'ffdead',
            'navy' => '000080',
            'oldlace' => 'fdf5e6',
            'olive' => '808000',
            'olivedrab' => '6b8e23',
            'orange' => 'ffa500',
            'orangered' => 'ff4500',
            'orchid' => 'da70d6',
            'palegoldenrod' => 'eee8aa',
            'palegreen' => '98fb98',
            'paleturquoise' => 'afeeee',
            'palevioletred' => 'd87093',
            'papayawhip' => 'ffefd5',
            'peachpuff' => 'ffdab9',
            'peru' => 'cd853f',
            'pink' => 'ffc0cb',
            'plum' => 'dda0dd',
            'powderblue' => 'b0e0e6',
            'purple' => '800080',
            'red' => 'ff0000',
            'rosybrown' => 'bc8f8f',
            'royalblue' => '4169e1',
            'saddlebrown' => '8b4513',
            'salmon' => 'fa8072',
            'sandybrown' => 'f4a460',
            'seagreen' => '2e8b57',
            'seashell' => 'fff5ee',
            'sienna' => 'a0522d',
            'silver' => 'c0c0c0',
            'skyblue' => '87ceeb',
            'slateblue' => '6a5acd',
            'slategray' => '708090',
            'snow' => 'fffafa',
            'springgreen' => '00ff7f',
            'steelblue' => '4682b4',
            'tan' => 'd2b48c',
            'teal' => '008080',
            'thistle' => 'd8bfd8',
            'tomato' => 'ff6347',
            'turquoise' => '40e0d0',
            'violet' => 'ee82ee',
            'violetred' => 'd02090',
            'wheat' => 'f5deb3',
            'white' => 'ffffff',
            'whitesmoke' => 'f5f5f5',
            'yellow' => 'ffff00',
            'yellowgreen' => '9acd32'
        );
    }

}
