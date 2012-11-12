<?php

class gd_image {

    protected $_handle;
    public $format;

    public function __construct($path_or_handle) {
        if (is_string($path_or_handle))
            $this->_handle = self::load_image_handle($path_or_handle, $this->format);
        else
            $this->_handle = $path_or_handle;
    }

    /**
     * Save a image on the specified path
     * @param string $path
     * @param bool $check_is_equal Check, before save, that the file exists and it's equal to the current image
     * @return bool Image written (TRUE); error or image skipped (FALSE)
     */
    public function save($path, $format = 'png', $check_is_equal = TRUE, $quality = 90, $optimize = TRUE) {
        //Check if image exists and is equal
        if ($check_is_equal && file_exists($path) && self::compare_images($this->_handle, $path)) {
            return FALSE; //Same images, don't overwrite
        }

        //Save image
        switch ($format) {
            case 'jpg':
            case 'jpeg':
                $success = imagejpeg($this->_handle, $path, $quality);
                break;

            case 'gif':
                $success = imagegif($this->_handle, $path);
                break;

            default:
                imagesavealpha($this->_handle, TRUE);
                $success = imagepng($this->_handle, $path, 9, PNG_ALL_FILTERS);
                break;
        }

        //Optimize
        if ($optimize && $success) {
            $this->_smush($path);
        }

        return $success;
    }

    private function _smush($file) {
        if (!function_exists('curl_init'))
            return FALSE;

        //Prepare cUrl
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'http://www.smushit.com/ysmush.it/ws.php?');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, array('files' => '@' . $file));
        $json_str = curl_exec($ch);
        curl_close($ch);

        //Parse response and save file
        $json = json_decode($json_str);

        if (is_null($json) || isset($json->error)) {
            return FALSE;
        }

        $compressed = file_get_contents($json->dest);
        $success = $compressed ? file_put_contents($file, $compressed) : FALSE;

        return $success;
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
                imagefilter($this->_handle, IMG_FILTER_PIXELATE, $arg1, isset($arg2) ? $arg2 : FALSE);
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
    public static function load_image_handle($path, &$format = NULL) {
        list($w, $h, $type) = getimagesize($path);
        switch ($type) {
            case IMAGETYPE_GIF :
                $format = 'gif';
                return imagecreatefromgif($path);

            case IMAGETYPE_JPEG:
                $format = 'jpg';
                return imagecreatefromjpeg($path);

            case IMAGETYPE_PNG:
                $format = 'png';
                return imagecreatefrompng($path);

            case IMAGETYPE_SWF :
                $format = 'swf';
                return imagecreatefromswf($path);

            case IMAGETYPE_WBMP :
                $format = 'wbmp';
                return imagecreatefromwbmp($path);

            case IMAGETYPE_XBM :
                $format = 'xbm';
                return imagecreatefromxbm($path);
        }
        return FALSE;
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
            return FALSE;

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
                        return FALSE;
                    }
                }
            }
        }

        return TRUE;
    }

}