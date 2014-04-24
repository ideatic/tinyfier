<?php

/**
 * Basic image manipulation tool (resizing, format change, optimization, etc.)
 */
class Tinyfier_Image_Tool {

    protected $_handle;
    protected $_format;

    public function __construct($path_or_handle) {
        if (is_string($path_or_handle)) {
            $this->_handle = $this->_load_image($path_or_handle);
        } else {
            $this->_handle = $path_or_handle;
        }

        if (!$this->_handle) {
            throw new InvalidArgumentException('Invalid path or image handle');
        }
    }

    private function _load_image($path) {
        list($w, $h, $type) = getimagesize($path);
        switch ($type) {
            case IMAGETYPE_GIF :
                $this->_format = 'gif';
                return imagecreatefromgif($path);

            case IMAGETYPE_JPEG:
                $this->_format = 'jpg';
                return imagecreatefromjpeg($path);

            case IMAGETYPE_PNG:
                $this->_format = 'png';
                return imagecreatefrompng($path);

            case IMAGETYPE_SWF :
                $this->_format = 'swf';
                return imagecreatefromswf($path);

            case IMAGETYPE_WBMP :
                $this->_format = 'wbmp';
                return imagecreatefromwbmp($path);

            case IMAGETYPE_XBM :
                $this->_format = 'xbm';
                return imagecreatefromxbm($path);

            default:
                return imagecreatefromstring(file_get_contents($path));
        }
        return FALSE;
    }

    /**
     * Gets the current image handle
     * @return mixed
     */
    public function handle() {
        return $this->_handle;
    }

    /**
     * Gets the current image format
     */
    public function format() {
        return $this->_format;
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
     * Sets the background color for transparent images
     */
    public function set_bg_color($r = 255, $g = 255, $b = 255) {
        // Create a new true color image with the same size
        $w = imagesx($this->_handle);
        $h = imagesy($this->_handle);
        $with_bg = imagecreatetruecolor($w, $h);

        // Fill the new image with white background
        $bg = imagecolorallocate($with_bg, $r, $g, $b);
        imagefill($with_bg, 0, 0, $bg);

        // Copy original transparent image onto the new image
        imagecopy($with_bg, $this->_handle, 0, 0, 0, 0, $w, $h);

        $this->_handle = $with_bg;
    }

    /**
     * Apply a filter on the selected image. For the list of available filters
     * , please check http://php.net/manual/function.imagefilter.php
     * @param string $path
     * @param string $filter
     */
    public function filter($filter, $arg1 = '', $arg2 = '', $arg3 = '') {
        //Find filter constant
        if (!is_numeric($filter) && !defined($filter)) {
            $replaces = array(
                'desaturate' => 'grayscale',
                'invert' => 'negate',
                'edges' => 'edgedetect',
                'blur' => 'gaussian_blur',
            );

            foreach ($replaces as $s => $r) {
                if ($filter == $s) {
                    $filter = $r;
                    break;
                }
            }

            $filter = 'IMG_FILTER_' . strtoupper($filter);

            if (!defined($filter)) {
                throw new UnexpectedValueException("Filter '$filter' not valid");
            }

            $filter = constant($filter);
        }

        //Apply filter
        $args = func_get_args();
        array_shift($args);
        array_unshift($args, $filter);
        array_unshift($args, $this->_handle);
        return call_user_func_array('imagefilter', $args);
    }

    /**
     * Save the current image on the specified path
     * @param string $path Path to the saved file, NULL to send it to the browser
     * @param int|boolean $quality Lossy quality of the saved file. TRUE for maximum quality and LOSSLESS optimization
     * @param boolean|array $optimize Optimize image after save. It can be an array of Tinyfier_Image_Optimizer::process settings
     * @param boolean $check_is_equal Check, before save, that the file exists and it's equal to the current image (so it doesnt have to optimize again)
     * @param mixed $format Output format for the image, NULL to detect it from the file name
     * @return boolean
     * @warning Optimization is not available when the image is sent to the browser
     */
    public function save($path, $quality = 85, $optimize = TRUE, $check_is_equal = FALSE, $format = NULL) {
        //Check if image exists and is equal
        if ($check_is_equal && file_exists($path) && self::equal($this, $path)) {
            return FALSE; //Same images, don't overwrite
        }
        if (!isset($format)) {
            $format = str_replace('.', '', strtolower(pathinfo($path, PATHINFO_EXTENSION)));
        }

        //Save image
        switch ($format) {
            case 'jpg':
            case 'jpeg':
            case IMAGETYPE_JPEG:
                $this->set_bg_color();
                $success = imagejpeg($this->_handle, $path, $optimize || $quality === TRUE ? 100 : $quality);
                break;

            case 'gif':
            case IMAGETYPE_GIF:
                $success = imagegif($this->_handle, $path);
                break;

            case 'png':
            case IMAGETYPE_PNG:
                imagesavealpha($this->_handle, TRUE);
                $success = imagepng($this->_handle, $path, 9, PNG_ALL_FILTERS);
                $format = 'png';
                break;

            default:
                throw new InvalidArgumentException("Unrecognized format '$format'");
        }

        //Optimize
        if ($optimize && $path != NULL && $success) {
            Tinyfier_Image_Optimizer::process($path, (is_array($optimize) ? $optimize : array()) + array(
                Tinyfier_Image_Optimizer::MODE => $quality === TRUE ? Tinyfier_Image_Optimizer::MODE_LOSSLESS : Tinyfier_Image_Optimizer::MODE_LOSSY,
                Tinyfier_Image_Optimizer::LOSSY_QUALITY => $quality
            ));
        }

        return $success;
    }

    /**
     * Send the current image to the browser
     */
    public function send($format = 'png', $quality = 90) {
        header('Content-type: ' . (is_string($format) ? 'image/' . $format : image_type_to_mime_type($format)));
        return $this->save(NULL, $quality, FALSE, FALSE, $format);
    }

    /**
     * Check if a path refers to a valid image
     * @param string $path
     * @return boolean
     */
    public static function is_valid_image($path) {
        if (!is_file($path)) {
            return FALSE;
        }

        if (function_exists('exif_imagetype')) {
            return exif_imagetype($path) !== FALSE;
        } elseif (function_exists('getimagesize')) {
            return getimagesize($path) !== FALSE;
        } else {
            throw new RuntimeException('Could not find exif_imagetype or getimagesize functions');
        }

        return FALSE;
    }

    /**
     * Do a pixel by pixel comparation of two images
     * @warning this function can take several minutes on large files
     * @param mixed $image_a Path or handle to the first image
     * @param mixed $image_b Path or handle to the second image
     * @return bool
     */
    public static function equal($image_a, $image_b) {
        @set_time_limit(0);

        if (!($image_a instanceof self)) {
            $image_a = new self($image_a);
        }
        if (!($image_b instanceof self)) {
            $image_b = new self($image_b);
        }

        //Compare size
        if ($image_a->width() != $image_b->width() || $image_a->height() != $image_b->height()) {
            return FALSE;
        }

        //Compare pixel
        $ha = $image_a->handle();
        $hb = $image_b->handle();
        for ($x = 0; $x <= imagesx($ha) - 1; $x++) {
            for ($y = 0; $y <= imagesy($ha) - 1; $y++) {
                $color_index_a = imagecolorat($ha, $x, $y);
                $color_index_b = imagecolorat($hb, $x, $y);

                if ($color_index_a != $color_index_b) {
                    //If alfa value is zero, color doesn't matter
                    $alpha_a = ($color_index_a >> 24) & 0x7F;
                    $alpha_b = ($color_index_b >> 24) & 0x7F;
                    if ($alpha_a != 0 || $alpha_b != 0) {
                        return FALSE;
                    }
                }
            }
        }

        return TRUE;
    }

}
