<?php

/**
 * Parser de código CSS
 */
class css_document {

    private $_document;

    /**
     * Analiza un documento CSS y permite acceder a su contenido programáticamente
     * @param string $css
     * @return bool 
     */
    public function parse($css) {
        // Eliminar comentarios
        $css = preg_replace("/\/\*(.*)?\*\//Usi", "", $css);

        //Parsear CSS
        $this->_document = array();
        foreach (explode('}', $css) as $block) {
            $block_parts = explode('{', $block);

            if (count($block_parts) > 2)
                throw new Exception("Parse error at '$block'");

            $selector = trim($block_parts[0]);
            if (!empty($selector)) {
                $selector = $this->get_selector($selector);

                //Parsear propiedades
                foreach (preg_split('/;(?!\s*base64)/', $block_parts[1]) as $property) {
                    $pos = strpos($property, ':');
                    if ($pos !== false) {
                        $name = trim(substr($property, 0, $pos));
                        $value = trim(substr($property, $pos + 1));

                        $selector->add_property($name, $value);
                    } else if (trim($property) != '') {
                        throw new Exception("Parse error at '$property'");
                    }
                }
            }
        }
        return true;
    }

    /**
     * Obtiene un selector CSS
     * @param string $name 
     * @return css_selector
     */
    public function get_selector($name) {
        if (!isset($this->_document[$name]))
            $this->_document[$name] = new css_selector($name, $this);

        return $this->_document[$name];
    }

    /**
     * Guarda el documento actual como código CSS
     * @return string
     */
    public function save($pretty=false) {

        $css = array();
        foreach ($this->_document as $selector) {
            //Añadir propiedades
            $properties_str = '';
            foreach ($selector->properties() as $property) {
                if ($pretty) { //Formato legible
                    $properties_str .= "   $property->Name: $property->Value;\n";
                } else { //Mostrar en los mínimos caracteres posibles
                    $properties_str .= "$property->Name:$property->Value;";
                }
            }

            //Añadir selector
            if ($pretty) {
                $css [] = "$selector->Name {\n$properties_str}\n\n";
            } else {//Eliminar último ';'
                $css [] = $selector->Name . '{' . (substr($properties_str, -1) == ';' ? substr($properties_str, 0, -1) : $properties_str) . '}';
            }
        }

        return implode('', $css);
    }

    /**
     * Obtiene un array con todos los selectores del documento actual
     * @return css_selector
     */
    public function selectors() {
        return $this->_document;
    }

}

/**
 * Representa un selector CSS y el conjunto de propiedades asociadas a éste
 */
class css_selector {

    public function __construct($name, css_document $parent) {
        $this->Name = $name;
        $this->properties = array();
        $this->_document = $parent;
    }

    public $Name;
    public $properties;
    private $_document;

    /**
     * Añade una nueva propiedad al selector
     * @param string $name
     * @param string $value 
     */
    public function add_property($name, $value) {
        $this->properties[] = new css_property($name, $value, $this);
    }

    /**
     * Obtiene todos los valores asociados a una propiedad
     * @param string $name 
     * @return css_property
     */
    public function get_property($name) {
        foreach ($this->properties as $key => $prop) {
            if ($prop->Name == $name) {
                return $prop;
            }
        }
        return false;
    }

    /**
     * Elimina la propiedad indicada de este selector CSS
     * @param css_property $property
     * @return bool 
     */
    public function delete_property(css_property $property) {
        foreach ($this->properties as $key => $prop) {
            if ($prop == $property) {
                unset($this->properties[$key]);
                return true;
            }
        }
        return false;
    }

    public function properties() {
        return $this->properties;
    }

}

class css_property {

    public function __construct($name, $value, css_selector $selector) {
        $this->Name = $name;
        $this->Value = $value;
        $this->_selector = $selector;
    }

    public $Name;
    public $Value;
    /**
     * Selector al que pertenece esta propiedad
     * @var css_selector 
     */
    private $_selector;

    public function delete() {
        $this->_selector->delete_property($this);
    }

    /**
     * Obtiene el selector padre de esta propiedad
     * @return css_selector
     */
    public function selector() {
        return $this->_selector;
    }

}