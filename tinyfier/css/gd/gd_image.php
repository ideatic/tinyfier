<?php

class gd_image {

    protected $_handle;

    public function __construct($path_or_handle) {
        if (is_string($path_or_handle))
            $this->_handle = self::load_image_handle($path_or_handle);
        else
            $this->_handle = $path_or_handle;
    }

    /**
     * Save a image on the specified path
     * @param string $path
     * @param bool $check_is_equal Check, before save, that the file exists and it's equal to the current image
     * @return bool Image write (true); error or image skipped (false)
     */
    public function save($path, $format = 'png', $check_is_equal = true, $quality = 90) {
        //Check if image exists and is equal
        if ($check_is_equal && file_exists($path) && self::compare_images($this->_handle, $path)) {
            return false; //Same images, don't overwrite
        }

        //Save image
        switch ($format) {
            case 'jpg':
            case 'jpeg':
                return imagejpeg($this->_handle, $path, $quality);

            case 'gif':
                return imagegif($this->_handle, $path);

            default:
                imagesavealpha($this->_handle, true);
                return imagepng($this->_handle, $path, 9, PNG_ALL_FILTERS);
        }
    }

    /**
     * Apply a filter on the selected image
     * @see http://php.net/manual/function.imagefilter.php
     * @param string $path
     * @param string $filter 
     */
    public function filter($filter, $arg1 = '', $arg2 = '', $arg3 = '') {
        switch ($filter) {
            case 'invert':
            case 'negate':
                imagefilter($this->_handle, IMG_FILTER_NEGATE);
                break;

            case 'grayscale':
            case 'desaturate':
                imagefilter($this->_handle, IMG_FILTER_GRAYSCALE);
                break;

            case 'brightness':
                imagefilter($this->_handle, IMG_FILTER_BRIGHTNESS, $arg1);
                break;

            case 'contrast':
                imagefilter($this->_handle, IMG_FILTER_CONTRAST, $arg1);
                break;

            case 'colorize':
                imagefilter($this->_handle, IMG_FILTER_COLORIZE, $arg1, $arg2, $arg3);
                break;

            case 'edges':
            case 'edgedetect':
                imagefilter($this->_handle, IMG_FILTER_EDGEDETECT);
                break;

            case 'emboss':
                imagefilter($this->_handle, IMG_FILTER_EMBOSS);
                break;

            case 'blur':
            case 'gaussian_blur':
                imagefilter($this->_handle, IMG_FILTER_GAUSSIAN_BLUR);
                break;

            case 'selective_blur':
                imagefilter($this->_handle, IMG_FILTER_SELECTIVE_BLUR);
                break;

            case 'mean_removal':
                imagefilter($this->_handle, IMG_FILTER_MEAN_REMOVAL);
                break;

            case 'smooth':
                imagefilter($this->_handle, IMG_FILTER_SMOOTH, $arg1);
                break;

            case 'pixelate':
                imagefilter($this->_handle, IMG_FILTER_PIXELATE, $arg1, isset($arg2) ? $arg2 : false);
                break;

            default:
                throw new UnexpectedValueException("Filter '$filter' not valid");
                break;
        }
    }

    /**
     * Load an image and returns its handle
     * @param string $path
     * @return boolean 
     */
    public static function load_image_handle($path) {
        list($w, $h, $type) = getimagesize($path);
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

    /**
     * Do a pixel by pixel comparation of two images
     * @param mixed $image_a Identificador de la imagen a comparar o ruta a su ubicación
     * @param mixed $image_b Identificador de la imagen a comparar o ruta a su ubicación
     * @return bool
     */
    public static function compare_images($image_a, $image_b) {
        if (is_string($image_a))
            $image_a = self::load_image_handle($image_a);
        if (is_string($image_b))
            $image_b = self::load_image_handle($image_b);

        //Comparar tamaños
        if (imagesx($image_a) != imagesx($image_b) || imagesy($image_a) != imagesy($image_b))
            return false;

        //Comparar píxeles
        for ($x = 0; $x <= imagesx($image_a) - 1; $x++) {
            for ($y = 0; $y <= imagesy($image_a) - 1; $y++) {
                $color_index_a = imagecolorat($image_a, $x, $y);
                $color_index_b = imagecolorat($image_b, $x, $y);

                if ($color_index_a != $color_index_b) {
                    //Comprobar si el canal alfa es cero en ambos, el color no importa
                    $alpha_a = ($color_index_a >> 24) & 0x7F;
                    $alpha_b = ($color_index_b >> 24) & 0x7F;
                    if ($alpha_a != 0 || $alpha_b != 0) {
                        // echo "Píxel ($x, $y) distinto: $color_index_a != $color_index_b\n";
                        return false;
                    }
                }
            }
        }

        return true;
    }

}