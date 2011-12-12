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
    public function add_image($path, $tag=null) {
        //Comprobar que el archivo no está ya incluido
        foreach ($this->_images as $image) {
            if ($image->Path == $path)
                return;
        }

        $im = new gd_sprite_image($path);
        $im->Tag = $tag;
        $this->_images[] = $im;
    }

    /**
     * Crea el sprite CSS en memoria y devuelve su identificador
     * @return mixed
     */
    public function build() {
        //Organizar imágenes dentro del sprite
        $this->_sort_images();

        //Dibujar sprite
        $sprite = $this->_draw_sprite();

        return $sprite;
    }

    /**
     * Obtiene las imágenes incluidas en este sprite en un array de objetos gd_sprite_image
     * @return array
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
            $image->Top = $y;
            $image->Left = 0;
            $y+=$image->Height;
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
            $w = max($w, $image->Left + $image->Width);
            $h = max($w, $image->Top + $image->Height);
        }

        //Crear sprite
        $sprite = imagecreatetruecolor($w, $h);
        imagealphablending($sprite, false); //Soporte de transparencias
        foreach ($this->_images as $image) {
            imagecopy($sprite, $image->Handle, $image->Left, $image->Top, 0, 0, $image->Width, $image->Height);
        }
        return $sprite;
    }

}

/**
 * Representa una imagen dentro de un sprite
 */
class gd_sprite_image {

    public function __construct($path) {
        $this->Path = $path;
        list($this->Width, $this->Height, $type) = getimagesize($path);
        $this->Handle = $this->_load_image($path, $type);
    }

    /**
     * Ruta del archivo original que forma esta imagen
     * @var string
     */
    public $Path;
    
    /**
     * Identificador de la imagen para su manipulación
     * @var mixed
     */
    public $Handle;
    /**
     * Ancho de la imagen
     * @var int 
     */
    public $Width;
    /**
     * Altura de la imagen
     * @var int 
     */
    public $Height;
    /**
     * Número de píxeles respecto al border superior donde se mostrará la imagen
     * @var int
     */
    public $Top;
     /**
     * Número de píxeles respecto al border izquierdo donde se mostrará la imagen
     * @var int
     */
    public $Left;
    /**
     * Información asociada a esta imagen
     * @var mixed
     */
    public $Tag;

    private function _load_image($path, $type) {
        switch ($type) {
            case IMAGETYPE_GIF :
                return imagecreatefromgif($path);

            case IMAGETYPE_JPEG:
                return imagecreatefromjpeg($path);

            case IMAGETYPE_PNG:
                return imagecreatefrompng($path);

            case IMAGETYPE_SWF :
                return imagecreatefromswf($path);

            case IMAGETYPE_WBMP :
                return imagecreatefromwbmp($path);

            case IMAGETYPE_XBM :
                return imagecreatefromxbm($path);
        }
        return false;
    }

}