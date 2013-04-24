<?php

/**
 * Tool for CSS sprites creation
 */
class gd_sprite {

    /**
     * @var gd_sprite_image[]
     */
    private $_images;

    public function __construct() {
        $this->_images = array();
    }

    /**
     * Adds a new image to the sprite
     */
    public function add_image($path, $tag = NULL) {
        //Check if the file is already included
        foreach ($this->_images as $image) {
            if ($image->path == $path)
                return;
        }

        $im = new gd_sprite_image($path);
        $im->tag = $tag;
        $this->_images[] = $im;
    }

    /**
     * Build the CSS sprite in memory
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
     * Images added to the sprite
     * @return gd_sprite_image[]
     */
    public function images() {
        return $this->_images;
    }

    /**
     * Sort the images of the sprite
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
     * Draw the images on the sprite
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
        $sprite = imagecreatetruecolor($w, $h);
        imagealphablending($sprite, FALSE); //Soporte de transparencias
        imagefill($sprite, 0, 0, imagecolorallocatealpha($sprite, 0, 0, 0, 127)); //Fondo transparente
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
     * Original path to the image file
     * @var string
     */
    public $path;

    /**
     * Image handler
     * @var mixed
     */
    public $handle;

    /**
     * Image width
     * @var int
     */
    public $width;

    /**
     * Image height
     * @var int
     */
    public $height;

    /**
     * Top margin of the image in the sprite
     * @var int
     */
    public $top;

    /**
     * Left margin of the image in the sprite
     * @var int
     */
    public $left;

    /**
     * Information related to the image
     * @var mixed
     */
    public $tag;

}