<?php

/**
 * Tool for CSS sprites creation
 */
class Tinyfier_Image_Sprite
{

    /**
     * @var Tinyfier_Image_SpriteImage[]
     */
    private array $_images;

    public function __construct()
    {
        $this->_images = [];
    }

    /**
     * Adds a new image to the sprite
     */
    public function add_image($path, $tag = null): void
    {
        //Check if the file is already included
        foreach ($this->_images as $image) {
            if ($image->path == $path) {
                return;
            }
        }

        $im = new Tinyfier_Image_SpriteImage($path);
        $im->tag = $tag;
        $this->_images[] = $im;
    }

    /**
     * Build the CSS sprite in memory
     * @return Tinyfier_Image_Tool
     */
    public function build(): Tinyfier_Image_Tool
    {
        //Sort images inside the sprite
        $y = 0;
        foreach ($this->_images as $image) {
            $image->top = $y;
            $image->left = 0;
            $y += $image->image->height();
        }

        //Draw sprite
        $w = 0;
        $h = 0;
        foreach ($this->_images as $image) {
            $w = max($w, $image->left + $image->image->width());
            $h = max($w, $image->top + $image->image->height());
        }

        $sprite = imagecreatetruecolor($w, $h);
        imagealphablending($sprite, false); //Soporte de transparencias
        imagefill($sprite, 0, 0, imagecolorallocatealpha($sprite, 0, 0, 0, 127)); //Fondo transparente
        foreach ($this->_images as $image) {
            imagecopy($sprite, $image->image->handle(), $image->left, $image->top, 0, 0, $image->image->width(), $image->image->height());
        }

        return new Tinyfier_Image_Tool($sprite);
    }

    /**
     * Images added to the sprite
     * @return Tinyfier_Image_SpriteImage[]
     */
    public function images(): array
    {
        return $this->_images;
    }

}

/**
 * Represents an image inside a sprite
 */
class Tinyfier_Image_SpriteImage
{

    public function __construct($path)
    {
        $this->path = $path;
        $this->image = new Tinyfier_Image_Tool($path);
    }

    /**
     * Image added to the sprite
     * @var Tinyfier_Image_Tool
     */
    public Tinyfier_Image_Tool $image;

    /**
     * Original path to the image file
     * @var string
     */
    public string $path;

    /**
     * Top margin of the image in the sprite
     * @var int
     */
    public int $top;

    /**
     * Left margin of the image in the sprite
     * @var int
     */
    public int $left;

    /**
     * Information related to the image
     * @var mixed
     */
    public mixed $tag;

}
