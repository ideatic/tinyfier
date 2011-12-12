<?php

/**
 * Rutinas de compresión y procesado de código CSS
 *
 * @package Tinyfier
 */
class CSS {

    /**
     * Opciones para el parser. Opciones disponibles:
     *
     * 'pretty': valor booleano que indica si se formateará el código para hacerlo más legible
     *
     * 'path': ruta completa al archivo CSS original en el sistema de archivos local
     *
     * 'relative_path': ruta relativa al archivo CSS respecto al script invocador y el host (obtenido del parámetro ?f=)
     *
     * 'removeHacks': valor booleano que indica si se borrarán los hacks css para IE (prefijos *-_)
     *
     * 'inlineImages': valor booleano que indica si se incrustarán imágenes en base64 de las propiedades con el valor !inline
     *
     * @var array
     */
    private $_settings;

    public static function Process($css, $settings = null) {
        $parser = new CSS($settings);
        return $parser->parse($css);
    }

    public function __construct($settings = null) {
        if (!isset($settings)) {
            $settings = array(
                'path' => '',
                'relative_path' => '',
                'pretty' => false,
                'ie_compatible' => false
            );
        }

        $this->_settings = $settings;
    }

    public function parse($css) {
        // 1. Añadir funciones helper que se pueden utilizar durante el desarrollo
        $helpers = file_get_contents(dirname(__FILE__) . '/css/helpers.css');

        // 2. Procesar con LESS
        require_once 'css/tinyfier_less.php';
        $less = new tinyfier_less($this->_settings);
        $css = $less->parse("$helpers\n$css");

        // 3. Comprimir y eliminar hacks
        require_once 'css/css_document.php';
        $css_document = new css_document();
        $css_document->parse($css);
        $this->_process_document($css_document);

        // 4 . Volver a crear código CSS
        $css = $css_document->save($this->_settings['pretty']);

        return $css;
    }

    /**
     * Procesa todas las propiedades de una hoja de estilos CSS, ajustando sus URLs
     * y añadiendoles funcionalidad.
     * @param css_document $doc Documento CSS a procesar
     */
    private function _process_document(css_document $doc) {
        //Recorrer propiedades
        foreach ($doc->selectors() as $selector) {
            foreach ($selector->properties() as $property) {
                //Eliminar hacks para IE
                if (!$this->_settings['ie_compatible'] && (preg_match('/^\s*(filter|_|\*|-(?!moz|webkit))/', $property->Name)
                        || stripos($property->Value, 'expression') === 0)) {
                    $property->delete();
                }
            }
        }
    }
}
