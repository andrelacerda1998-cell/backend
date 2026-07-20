<?php

if (!function_exists('format_class')) {
    /**
     * Converts a fully qualified class name to a lowercase string of the class's short name.
     *
     * @param object $class The instance of the class to be formatted.
     * @return string The lowercase short name of the class.
     */
    function format_class(object $class): string
    {
        $class = get_class($class);
        $class = explode('\\', $class);
        $class = $class[count($class) - 1];
        return strtolower($class);
    }
}
