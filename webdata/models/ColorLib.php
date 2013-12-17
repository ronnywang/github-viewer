<?php

class ColorLib
{
    public static function getColor($value, $colors)
    {
        $last_color = null;
        foreach ($colors as $color) {
            list($v, $rgb) = $color;
            if ($value < $v) {
                if (is_null($last_color)) {
                    // first color
                    return $rgb;
                }
                $ret_rgb = [];
                for ($i = 0; $i < 3; $i ++) {
                    $ret_rgb[$i] = $last_color[1][$i] + ($value - $last_color[0]) * ($rgb[$i] - $last_color[1][$i]) / ($v - $last_color[0]);
                }
                return $ret_rgb;
            }
            $last_color = $color;
        }

        // final color
        return $rgb;
    }

    public static function getColorConfig($set_config, $tab_id)
    {
        if (!property_exists($set_config->tabs, $tab_id)) {
            return null;
        }
        $tab_info = $set_config->tabs->{$tab_id};

        // final format
        if (property_exists($tab_info, 'colors')) {
            if (is_array($tab_info->colors)) {
                return $tab_info->colors;
            }

            if (is_scalar($tab_info->colors) and property_exists($set_config->color_set, $tab_info->colors)) {
                return $set_config->color_set->{$tab_info->colors};
            }

            return null;
        }

        if (property_exists($tab_info, 'color')) {
            $colors = array(
                array($tab_info->min, array(255, 255, 255)),
                array($tab_info->max, $tab_info->color),
            );
            return $colors;
        }

        return null;
    }
}
