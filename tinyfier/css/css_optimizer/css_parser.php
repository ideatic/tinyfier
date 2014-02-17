<?php

class css_parser {

    public $trim = TRUE;

    /**
     * 
     * @param string $css
     * @return css_group
     */
    public function parse($css) {

        # Split at all position not after the start: ^ 
        # and not before the end: $ 
        $chars = preg_split('/(?<!^)(?!$)/u', $css);

        $current_group = $initial_group = new css_group;

        $partial = '';
        for ($i = 0, $c = count($chars); $i < $c; $i++) {
            $char = $chars[$i];

            switch ($char) {
                case '{'://Group start
                    $new_group = new css_group;
                    $new_group->name = $this->_process_string($partial);
                    $current_group->add_child($new_group);
                    $current_group = $new_group;
                    $partial = '';
                    break;

                case ';'://Property or import end
                case '}'://Group end
                    //End current property
                    if (($separator = strpos($partial, ':')) !== FALSE) {
                        $property = new css_property;
                        $property->name = $this->_process_string(substr($partial, 0, $separator));
                        $property->value = $this->_process_string(substr($partial, $separator + 1));
                        $current_group->add_child($property);
                        $partial = '';
                    } else if ($char == ';') {
                        if (strpos(trim($partial), '@import') === 0) {//@import
                            $import = new css_element();
                            $import->value = $this->_process_string($partial) . ';';
                            $import->type = 'import';
                            $current_group->add_child($import);
                            $partial = '';
                        } else {//Out-of-property semicolon
                            if (strlen(trim($partial)) > 0)
                                $partial.=$char;
                        }
                    }

                    //End current group 
                    if ($char == '}') {
                        if (isset($current_group->parent)) {
                            $current_group = $current_group->parent;
                        } else {//Out-of-property bracket
                            $partial.=$char;
                        }
                    }
                    break;

                case '"':
                case "'"://String
                    $string = $this->_read_string($chars, $i);
                    $partial.=$string;
                    break;

                case '/'://Comment
                    if ($chars[$i + 1] == '*') {
                        $comment = $this->_read_comment($chars, $i);
                        $comment->parent = $current_group;
                        $current_group->children[] = $comment;
                    } else {
                        $partial.=$char;
                    }
                    break;

                default:
                    $partial.=$char;
                    break;
            }

            $prev = $char;
        }

        return $initial_group;
    }

    private function _process_string($val) {
        return $this->trim ? trim($val) : $val;
    }

    private function _read_string($chars, &$i) {
        //Read until '*/' is found
        $delimiter = $chars[$i];
        $string = '';
        $prev = NULL;
        for ($c = count($chars); $i < $c; $i++) {
            if (isset($prev) && $prev != '\\' && $chars[$i] == $delimiter) {
                $string.= $delimiter;
                return $string;
            }
            $prev = $chars[$i];
            $string.= $prev;
        }
        return FALSE;
    }

    /**
     * @return css_element
     */
    private function _read_comment($chars, &$i) {
        //Read until '*/' is found
        $value = '';
        $prev = '';
        for ($c = count($chars); $i < $c; $i++) {
            if ($prev == '*' && $chars[$i] == '/') {
                $value.= '/';
                $element = new css_element;
                $element->type = 'comment';
                $element->value = $value;
                return $element;
            }
            $prev = $chars[$i];
            $value.= $prev;
        }
        return FALSE;
    }

}

class css_element {

    /**
     *
     * @var css_group 
     */
    public $parent;
    public $value;

    /**
     * enum(property, comment, import)
     * @var string
     */
    public $type;

    public function render($compressed = FALSE) {
        return $this->value;
    }

    public function remove() {
        foreach ($this->parent->children as $key => $value) {
            if ($this === $value) {
                unset($this->parent->children[$key]);
            }
        }
        $this->parent = NULL;
    }

    /**
     * @return css_element[]
     */
    public function siblings($type = NULL, $include_self = FALSE) {
        if (!isset($this->parent)) {
            return array();
        }
        $siblings = array();
        foreach ($this->parent->children as $sibling) {
            if ($include_self || $sibling !== $this) {
                if (isset($type) ? $sibling instanceof $type : TRUE) {
                    $siblings[] = $sibling;
                }
            }
        }
        return $siblings;
    }

    public function insert_after($element) {
        if (!isset($this->parent)) {
            throw new RuntimeException('The current element has been removed');
        }
        $pos = 0;
        foreach ($this->parent->children as $child) {
            $pos++;
            if ($child === $this) {
                break;
            }
        }

        $this->parent->children = array_merge(array_slice($this->parent->children, 0, $pos), array($element), array_slice($this->parent->children, $pos));
    }

    /**
     * 
     * @param boolean $include_self
     * @return css_element[]
     */
    public function parents($include_self = TRUE) {
        $found = array();

        $current = $include_self ? $this : $this->parent;
        while ($current) {
            $found[] = $current;
            $current = $current->parent;
        }

        return $found;
    }

    public function make_clone() {
        //unreference parent to avoid memory leaking on huge files
        $parent = $this->parent;
        $this->parent = NULL;
        $copy = unserialize(serialize($this));
        $this->parent = $parent;
        return $copy;
    }

}

/**
 * Represents a group of css property (selectors, @media, @keyframes, etc.)
 */
class css_group extends css_element {

    public $name;

    /**
     *
     * @var css_property[]|css_group[]
     */
    public $children = array();

    public function __construct() {
        $this->type = 'property';
    }

    public function render($compressed = FALSE) {
        $content = array();
        foreach ($this->children as $child) {
            $content[] = $child->render($compressed);
        }


        if ($this->name) {
            if ($compressed) {
                return "$this->name{" . implode('', $content) . "}";
            } else {
                return "$this->name{\n\t" . implode("\n\t", $content) . "\n}\n";
            }
        } else {
            return implode($compressed ? '' : "\n", $content);
        }
    }

    public function add_child($element) {
        $element->parent = $this;
        $this->children[] = $element;
    }

    /**
     * Get all properties of all children of this group
     * @return css_property[]
     */
    public function find_all($type) {
        $result = array();
        $this->_find($type, $this->children, $result);
        return $result;
    }

    private function _find($type, $items, &$result) {
        foreach ($items as $element) {
            if ($element instanceof $type) {
                $result[] = $element;
            }
            if ($element instanceof css_group) {
                $this->_find($type, $element->children, $result);
            }
        }
    }

}

class css_property extends css_element {

    public $name;

    public function __construct($name = NULL, $value = NULL) {
        $this->type = 'property';
        $this->name = $name;
        $this->value = $value;
    }

    public function render($compressed = FALSE) {
        $last = $this->parent && end($this->parent->children) === $this;
        if ($compressed && $last) {
            return "$this->name:$this->value";
        } else {
            return "$this->name:$this->value;";
        }
    }

    public function insert_after($property, $value = NULL) {
        if (is_string($property)) {
            $property = new self($property, $value);
        }
        parent::insert_after($property);
    }

}
