<?php

/**
 * Funcionalidad para la creación de sprites de imágenes utilizadas en hojas de estilos CSS
 */
class gd_sprite {

    /**
     * Array con las imágenes añadidas al sprite
     * @var gd_sprite_image
     */
    private $_images;

    public function __construct() {
        $this->_images = array();
    }

    /**
     * Añade una nueva imagen al sprite
     */
    public function add_image($path, $tag = NULL) {
        //Comprobar que el archivo no está ya incluido
        foreach ($this->_images as $image) {
            if ($image->path == $path)
                return;
        }

        $im = new gd_sprite_image($path);
        $im->tag = $tag;
        $this->_images[] = $im;
    }

    /**
     * Crea el sprite CSS en memoria y devuelve su identificador
     * @return gd_image
     */
    public function build() {
        //Organizar imágenes dentro del sprite
        $this->_sort_images();

        //Dibujar sprite
        $sprite = $this->_draw_sprite();

        return new gd_image($sprite);
    }

    /**
     * Obtiene las imágenes incluidas en este sprite en un array de objetos gd_sprite_image
     * @return gd_sprite_image[]
     */
    public function images() {
        return $this->_images;
    }

    /**
     * Organiza las imágenes del sprite
     */
    private function _sort_images() {
        $y = 0;
        foreach ($this->_images as $image) {
            $image->top = $y;
            $image->left = 0;
            $y += $image->height;
        }
    }

    /**
     * Dibuja el sprite en una nueva imagen y la devuelve
     */
    private function _draw_sprite() {
        //Calcular tamaño del sprite
        $w = 0;
        $h = 0;
        foreach ($this->_images as $image) {
            $w = max($w, $image->left + $image->width);
            $h = max($w, $image->top + $image->height);
        }

        //Crear sprite
        $sprite = imagecreateTRUEcolor($w, $h);
        imagealphablending($sprite, FALSE); //Soporte de transparencias
        foreach ($this->_images as $image) {
            imagecopy($sprite, $image->handle, $image->left, $image->top, 0, 0, $image->width, $image->height);
        }
        return $sprite;
    }

}

/**
 * Represents an image inside a sprite
 */
class gd_sprite_image {

    public function __construct($path) {
        $this->path = $path;
        list($this->width, $this->height) = getimagesize($path);
        $this->handle = gd_image::load_image_handle($path);
    }

    /**
     * Ruta del archivo original que forma esta imagen
     * @var string
     */
    public $path;

    /**
     * Identificador de la imagen para su manipulación
     * @var mixed
     */
    public $handle;
    /**
     * Ancho de la imagen
     * @var int
     */
    public $width;
    /**
     * Altura de la imagen
     * @var int
     */
    public $height;
    /**
     * Número de píxeles respecto al border superior donde se mostrará la imagen
     * @var int
     */
    public $top;
    /**
     * Número de píxeles respecto al border izquierdo donde se mostrará la imagen
     * @var int
     */
    public $left;
    /**
     * Información asociada a esta imagen
     * @var mixed
     */
    public $tag;
}