<?php


/**
 * Print debug messages.
 *
 * @param string $message the message to display. 
 * @param int $requiredlevel at what debug verbosity level to display the message.
 * @param int $currentlevel current debug level.
 * @param string $obj the object calling the debug output.
 * @param string $func the function calling the debug output.
 * @param var $var variable to dump.
 */
function uuc_debug_show($message, $requiredlevel, $currentlevel, $obj = NULL, $func = NULL, $var = NULL) {
    if (empty($requiredlevel) || !is_number($requiredlevel)) {
        print "DEBUG::Specify a debug level!\n";
        return NULL;
    }

    if ($currentlevel >= $requiredlevel) {
        if (!is_null($obj)) {
            $message = trim(core_text::strtoupper($obj)) . "::" . $message;
        }
        if (!is_null($func)) {
            $message =  $message . " (function " . $func . "())";
        }
        $message = $message . "\n";
        print $message;

        if (!is_null($var)) {
            var_dump($var);
        }
    }
}

