<?php

/**
 * Tool for gradient generation using GD library
 */
abstract class Tinyfier_Image_Gradient {

    /**
     * Generate a gradient
     * @param int $width
     * @param int $height
     * @param array $color_stops Color stops, in format (position, unit, color)
     * @param string $direction Gradient direction (vertical, horizontal, diagonal, radial, square, diamond)
     * @param bool $invert Inver gradient
     * @param array $background_color If set, the final color of the gradient will be saved here
     * @return Tinyfier_Image_Tool
     */
    public static function generate($width, $height, $color_stops, $direction = 'vertical', $invert = FALSE, &$background_color = NULL) {
        //Crear imagen
        $image = imagecreateTRUEcolor($width, $height);

        //Calcular el número de líneas a dibujar
        $lines;
        $fill_background = FALSE;
        switch ($direction) {
            case 'vertical':
                $lines = $height;
                break;
            case 'horizontal':
                $lines = $width;
                break;
            case 'diagonal':
                $lines = max($width, $height) * 2;
                break;
            case 'radial':
                $center_x = $width / 2;
                $center_y = $height / 2;
                $rh = $height > $width ? 1 : $width / $height;
                $rw = $width > $height ? 1 : $height / $width;
                $lines = ceil(max($width, $height) / 1.5); //Lo correcto sería /2, pero se aplica 1.5 para expandir el degradado y hacerlo más similar al generado por los navegadores

                $fill_background = TRUE;
                $invert = !$invert; //The gradient is drawn from outside to inside
                break;
            case 'square':
            case 'rectangle':
                $direction = 'square';
                $lines = max($width, $height) / 2;

                $invert = !$invert; //The gradient is drawn from outside to inside
                break;
            case 'diamond':
                $rh = $height > $width ? 1 : $width / $height;
                $rw = $width > $height ? 1 : $height / $width;
                $lines = min($width, $height);

                $invert = !$invert; //The gradient is drawn from outside to inside
                break;

            default:
                return FALSE;
                break;
        }

        //Ordenar paradas de color      
        $colors = array();
        foreach ($color_stops as $stop) {
            list($position, $unit, $color) = $stop;

            $percentage;
            switch ($unit) {
                case 'px':
                    $percentage = 100 / $lines * $position;
                    break;
                default:
                    $percentage = $position;
                    break;
            }
            $colors[floatval($position)] = Tinyfier_CSS_Color::create($color)->to_array();
        }
        ksort($colors);

        $positions = array_keys($colors);
        if (!isset($colors[0])) { //Usar el primero como color de inicio
            $colors[0] = $colors[reset($positions)];
        }if (!isset($colors[100])) { //Usar el último como color final
            $colors[100] = $colors[end($positions)];
        }
        //Fill background
        $background_color = $colors[100];
        if ($fill_background) {
            list($r1, $g1, $b1) = $colors[100];
            imagefill($image, 0, 0, imagecolorallocate($image, $r1, $g1, $b1));
        }

        //Invert colors
        if ($invert) {
            $invert_colors = array();
            foreach ($colors as $key => $value) {
                $invert_colors[100 - $key] = $value;
            }
            $colors = $invert_colors;
        }
        ksort($colors);

        //Draw line by line
        $incr = 1;
        $color_change_positions = array_keys($colors);
        $end_color_progress = 0; //Forzar que en la primera iteración se seleccione el rango de colores
        for ($i = 0; $i < $lines; $i = $i + $incr) {
            //Escoger color
            $total_progress = 100 / $lines * $i;
            if ($total_progress >= $end_color_progress) { //Cambiar de rango de colores
                //Buscar color inicial a partir del progreso total
                $j = intval($total_progress);
                do {
                    $color_index = array_search($j--, $color_change_positions);
                } while ($color_index === FALSE && $j >= 0);

                //Obtener colores inicio y final para este rango
                $start_color_progress = $color_change_positions[$color_index];
                $start_color = $colors[$start_color_progress];
                $end_color_progress = $color_change_positions[$color_index + 1];
                $end_color = $colors[$end_color_progress];
            }
            $internal_progress = ($total_progress - $start_color_progress) / ($end_color_progress - $start_color_progress);
            $r = $start_color[0] + ($end_color[0] - $start_color[0]) * $internal_progress;
            $g = $start_color[1] + ($end_color[1] - $start_color[1]) * $internal_progress;
            $b = $start_color[2] + ($end_color[2] - $start_color[2]) * $internal_progress;
            $color = imagecolorallocate($image, $r, $g, $b);

            //Dibujar línea
            switch ($direction) {
                case 'vertical': //Draw from top to bottom
                    imagefilledrectangle($image, 0, $i, $width, $i + $incr, $color);
                    break;

                case 'horizontal': //Draw from left to right
                    imagefilledrectangle($image, $i, 0, $i + $incr, $height, $color);
                    break;

                case 'diagonal': //Draw from top-left to bottom-right
                    imagefilledpolygon($image, array(
                        $i, 0,
                        $i + $incr, 0,
                        0, $i + $incr,
                        0, $i), 4, $color);
                    break;

                case 'square': //Draw from outside to center
                    imagefilledrectangle($image, $i * $width / $height, $i * $height / $width, $width - ($i * $width / $height), $height - ($i * $height / $width), $color);
                    break;

                case 'radial': //Draw from outside to center
                    imagefilledellipse($image, $center_x, $center_y, ($lines - $i) * $rh * 2, ($lines - $i) * $rw * 2, $color);
                    break;

                case 'diamond': //Draw from outside to center
                    imagefilledpolygon($image, array(
                        $width / 2, $i * $rw - 0.5 * $height,
                        $i * $rh - 0.5 * $width, $height / 2,
                        $width / 2, 1.5 * $height - $i * $rw,
                        1.5 * $width - $i * $rh, $height / 2), 4, $color);
                    break;
            }
        }

        return new Tinyfier_Image_Tool($image);
    }

}
