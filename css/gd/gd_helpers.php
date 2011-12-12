<?php

/**
 * Guarda una imagen en la ruta especificada
 * @param type $image
 * @param type $path
 * @param type $format
 * @param type $quality
 * @return type 
 */
function save_image($image, $path, $format='png', $quality=90) {
    switch ($format) {
        case 'jpg':
        case 'jpeg':
            return imagejpeg($image, $path, $quality);

        case 'gif':
            return imagegif($image, $path);

        default:
            //Guardar en formato PNG
            imagesavealpha($image, true);
            imagepng($image, $path, 9, PNG_ALL_FILTERS);

            return true;
    }
}

/**
 *  Guarda una imagen en formato png y en la ruta indicada
 * @param mixed $image Imagen a guardar
 * @param string $path
 * @param bool $check_is_equal Comprobar antes de guardar que ya existe una imagen en la ruta indicada y que ésta es igual a la imagen que se quiere guardar
 * @return bool Valor que indica si la imagen se escribió (true) o ya existía una similar (false)
 */
function save_image_png($image, $path, $check_is_equal=true) {
    //Comprobar si la imagen ya existe y es distinta
    if ($check_is_equal && file_exists($path) && compare_images($image, $path)) {
        return false; //La imagen ya existe y es igual que la que se quiere guardar
    }

    //Guardar en formato PNG
    imagesavealpha($image, true);
    imagepng($image, $path, 9, PNG_ALL_FILTERS);

    return true;
}

/**
 * Compara dos imágenes píxel a píxel
 * @param mixed $image_a Identificador de la imagen a comparar o ruta a su ubicación
 * @param mixed $image_b Identificador de la imagen a comparar o ruta a su ubicación
 * @return bool
 */
function compare_images($image_a, $image_b) {
    if (is_string($image_a))
        $image_a = imagecreatefrompng($image_a);
    if (is_string($image_b))
        $image_b = imagecreatefrompng($image_b);

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