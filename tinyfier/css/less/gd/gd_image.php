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
                $format = 'png';
                break;
        }

        //Optimize
        if ($optimize && $success) {
            $this->_optimize($path, $format);
        }

        return $success;
    }

    private function _optimize($file, $format = NULL) {
        global $tinypng_api_key;

        if (!function_exists('curl_init'))
            return FALSE;
        $compressed = FALSE;
        if ($format == 'png' && !empty($tinypng_api_key)) {
            $compressed = $this->_compress_tinypng($file, $tinypng_api_key);
        }

        if (!$compressed) {
            $compressed = $this->_compress_smushit($file);
        }

        $success = $compressed ? file_put_contents($file, $compressed) : FALSE;

        return $success;
    }

    private function _compress_tinypng($file, $apikey) {
        //Optimize using TinyPNG
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_URL, 'https://api.tinypng.com/shrink');
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, "api:$apikey");
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents($file));
        $response = curl_exec($ch);

        //Parse response and save 
        if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200) {
            curl_close($ch);
            return $response;
        } else {
            curl_close($ch);

            $json = json_decode($response);

            if ($json && isset($json->error)) {
                trigger_error("tinypng error: $json->error, $json->message.");
            } else if ($json && isset($json->output->url)) {
                return file_get_contents($json->output->url);
            } else {
                trigger_error("tinypng error: undefined, '$response'");
            }
        }


        return FALSE;
    }

    private function _compress_smushit($file) {
        //Optimize using Yahoo Smush.it
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_URL, 'http://www.smushit.com/ysmush.it/ws.php');
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, array(
            'files' => file_get_contents($file),
        ));
        $json_str = curl_exec($ch);

        //Parse response and save file
        $json = json_decode($json_str);

        if ($json && !isset($json->error)) {
            curl_close($ch);
            return file_get_contents($json->dest);
        } else {
            trigger_error("smushit error: $json_str");
        }

        curl_close($ch);

        return FALSE;
    }

    /**
     * Return the current image width
     * @return int
     */
    public function width() {
        return imagesx($this->_handle);
    }

    /**
     * Return the current image height 
     * @return int
     */
    public function height() {
        return imagesy($this->_handle);
    }

    /**
     * Resizes the current image
     * @param int $width
     * @param int $height
     * @param boolean $keep_aspect
     */
    public function resize($width, $height = NULL, $keep_aspect = TRUE) {
        $current_width = $this->width($this->_handle);
        $current_height = $this->height($this->_handle);

        if (!isset($height)) {
            $height = $keep_aspect ? PHP_INT_MAX : $current_height;
        }

        //Adjust final size
        if ($keep_aspect) {
            $aspect_ratio = min($width / $current_width, $height / $current_height);
            $dest_width = round($current_width * $aspect_ratio);
            $dest_height = round($current_height * $aspect_ratio);
        } else {
            $dest_width = $width;
            $dest_height = $height;
        }

        //Resize image
        $thumb = imagecreatetruecolor($dest_width, $dest_height);
        imagealphablending($thumb, FALSE);
        $success = imagecopyresampled($thumb, $this->_handle, 0, 0, 0, 0, $dest_width, $dest_height, $current_width, $current_height);
        imagedestroy($this->_handle);

        $this->_handle = $thumb;

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
        @set_time_limit(0);

        if (is_string($image_a))
            $image_a = self::load_image_handle($image_a);
        if (is_string($image_b))
            $image_b = self::load_image_handle($image_b);

        //Comparar tamaños
        if (imagesx($image_a) != imagesx($image_b) || imagesy($image_a) != imagesy($image_b) || imagesx($image_a) * imagesy($image_a) > 1000 * 1000)
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
