<?php

declare(strict_types=1);

/**
 * Tool for CSS sprites creation
 */
class Tinyfier_Image_Sprite
{

    /**
     * @var Tinyfier_Image_SpriteImage[]
     */
    private array $_images = [];

    /**
     * Adds a new image to the sprite
     */
    public function add_image(string $path, mixed $tag = null): void
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
        $transparent = imagecolorallocatealpha($sprite, 0, 0, 0, 127);
        if ($transparent === false) {
            throw new RuntimeException('Cannot allocate the sprite background color');
        }
        imagefill($sprite, 0, 0, $transparent); //Fondo transparente
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

    /**
     * Image added to the sprite
     */
    public readonly Tinyfier_Image_Tool $image;

    /**
     * Top margin of the image in the sprite
     */
    public int $top = 0;

    /**
     * Left margin of the image in the sprite
     */
    public int $left = 0;

    /**
     * Information related to the image
     */
    public mixed $tag = null;

    /**
     * @param string $path Original path to the image file
     */
    public function __construct(public readonly string $path)
    {
        $this->image = new Tinyfier_Image_Tool($path);
    }

}
