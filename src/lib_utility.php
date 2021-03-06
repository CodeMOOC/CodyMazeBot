<?php
/**
 * Telegram Bot Sample
 * ===================
 * UWiClab, University of Urbino
 * ===================
 * Support library. Don't change a thing here.
 */

/**
 * Gets whether the script is running from the Command Line Interface.
 *
 * @return bool True if running from the CLI.
 */
function is_cli() {
    return (php_sapi_name() === 'cli');
}

/**
 * Mixes together parameters for an HTTP request.
 *
 * @param array $orig_params Original parameters or null.
 * @param array $add_params Additional parameters or null.
 * @return array Final mixed parameters.
 */
function prepare_parameters($orig_params, $add_params) {
    if(!$orig_params || !is_array($orig_params)) {
        $orig_params = array();
    }

    if($add_params && is_array($add_params)) {
        foreach ($add_params as $key => &$val) {
            $orig_params[$key] = $val;
        }
    }

    return $orig_params;
}

/**
 * Checks whether a text string starts with another.
 * Performs a case-insensitive check.
 *
 * @param $text String to search in.
 * @param $substring String to search for.
 * @return bool True if $text starts with $substring.
 */
function starts_with($text = '', $substring = '') {
    return (strpos(mb_strtolower($text), mb_strtolower($substring)) === 0);
}

/**
 * Extracts the command payload from a string.
 * Returns the string following the first command in a string (i.e., given
 * input "/start 123", returns "123").
 *
 * @param $text String to search in.
 * @return string Command payload, if any, or empty string.
 */
function extract_command_payload($text = '') {
    return mb_ereg_replace("^\/[a-zA-Z0-9_]*( |$)", '', $text);
}

function clamp($min, $max, $value) {
    if($value < $min)
        return $min;
    else if($value > $max)
        return $max;
    else
        return $value;
}
