<?php

declare(strict_types=1);

/**
 * Tool for gradient generation using GD library
 */
abstract class Tinyfier_Image_Gradient
{

    /**
     * Generate a gradient
     *
     * @param list<array{int|float, string, string|array<int, int|float>}> $color_stops      Color stops, in format (position, unit, color)
     * @param string                                                      $direction        Gradient direction (vertical, horizontal, diagonal, radial, square, diamond)
     * @param bool                                                        $invert           Inver gradient
     * @param list<int|float>|null                                        $background_color If set, the final color of the gradient will be saved here
     */
    public static function generate(int $width, int $height, array $color_stops, string $direction = 'vertical', bool $invert = false, ?array &$background_color = null): Tinyfier_Image_Tool
    {
        //Crear imagen
        $image = imagecreatetruecolor($width, $height);

        //Calcular el número de líneas a dibujar
        $fill_background = false;
        $center_x = $center_y = 0;
        $rh = $rw = 1;
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
                $lines = ceil(
                    max($width, $height) / 1.5
                ); //Lo correcto sería /2, pero se aplica 1.5 para expandir el degradado y hacerlo más similar al generado por los navegadores

                $fill_background = true;
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
                throw new InvalidArgumentException("Invalid gradient direction '{$direction}'");
        }

        //Ordenar paradas de color      
        $colors = [];
        foreach ($color_stops as $stop) {
            [$position, $unit, $color] = $stop;

            $percentage = match ($unit) {
                'px' => 100 / $lines * $position,
                default => $position,
            };
            // Las claves de un array son enteras: la posición se trunca, como hacía PHP con las claves float
            $colors[(int)$position] = Tinyfier_CSS_Color::create($color)
                                                             ->to_array();
        }
        ksort($colors);

        $positions = array_keys($colors);
        if (!isset($colors[0])) { //Usar el primero como color de inicio
            $colors[0] = $colors[array_first($positions)];
        }
        if (!isset($colors[100])) { //Usar el último como color final
            $colors[100] = $colors[array_last($positions)];
        }
        //Fill background
        $background_color = $colors[100];
        if ($fill_background) {
            [$r1, $g1, $b1] = $colors[100];
            $background = imagecolorallocate($image, (int)$r1, (int)$g1, (int)$b1);
            if ($background === false) {
                throw new RuntimeException('Cannot allocate the gradient background color');
            }
            imagefill($image, 0, 0, $background);
        }

        //Invert colors
        if ($invert) {
            $invert_colors = [];
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
        $start_color_progress = 0;
        $start_color = $end_color = $colors[0];
        for ($i = 0; $i < $lines; $i = $i + $incr) {
            //Escoger color
            $total_progress = 100 / $lines * $i;
            if ($total_progress >= $end_color_progress) { //Cambiar de rango de colores
                //Buscar color inicial a partir del progreso total
                $j = intval($total_progress);
                do {
                    $color_index = array_search($j--, $color_change_positions);
                } while ($color_index === false && $j >= 0);

                //Obtener colores inicio y final para este rango
                // Si no se encuentra la posición, se usa el primer rango (como antes, al usar false como índice)
                if ($color_index === false) {
                    $color_index = 0;
                }
                $start_color_progress = $color_change_positions[$color_index];
                $start_color = $colors[$start_color_progress];
                $end_color_progress = $color_change_positions[$color_index + 1];
                $end_color = $colors[$end_color_progress];
            }
            $internal_progress = ($total_progress - $start_color_progress) / ($end_color_progress - $start_color_progress);
            $r = $start_color[0] + ($end_color[0] - $start_color[0]) * $internal_progress;
            $g = $start_color[1] + ($end_color[1] - $start_color[1]) * $internal_progress;
            $b = $start_color[2] + ($end_color[2] - $start_color[2]) * $internal_progress;
            $color = imagecolorallocate($image, (int)$r, (int)$g, (int)$b);
            if ($color === false) {
                throw new RuntimeException('Cannot allocate a gradient color');
            }

            //Dibujar línea
            switch ($direction) {
                case 'vertical': //Draw from top to bottom
                    imagefilledrectangle($image, 0, (int)$i, $width, (int)($i + $incr), $color);
                    break;

                case 'horizontal': //Draw from left to right
                    imagefilledrectangle($image, (int)$i, 0, (int)($i + $incr), $height, $color);
                    break;

                case 'diagonal': //Draw from top-left to bottom-right
                    imagefilledpolygon(
                        $image,
                        [
                            $i,
                            0,
                            $i + $incr,
                            0,
                            0,
                            $i + $incr,
                            0,
                            $i
                        ],
                        $color
                    );
                    break;

                case 'square': //Draw from outside to center
                    imagefilledrectangle(
                        $image,
                        (int)($i * $width / $height),
                        (int)($i * $height / $width),
                        (int)($width - ($i * $width / $height)),
                        (int)($height - ($i * $height / $width)),
                        $color
                    );
                    break;

                case 'radial': //Draw from outside to center
                    imagefilledellipse($image, (int)$center_x, (int)$center_y, (int)(($lines - $i) * $rh * 2), (int)(($lines - $i) * $rw * 2), $color);
                    break;

                case 'diamond': //Draw from outside to center
                    imagefilledpolygon(
                        $image,
                        [
                            $width / 2,
                            $i * $rw - 0.5 * $height,
                            $i * $rh - 0.5 * $width,
                            $height / 2,
                            $width / 2,
                            1.5 * $height - $i * $rw,
                            1.5 * $width - $i * $rh,
                            $height / 2
                        ],
                        $color
                    );
                    break;
            }
        }

        return new Tinyfier_Image_Tool($image);
    }

}
