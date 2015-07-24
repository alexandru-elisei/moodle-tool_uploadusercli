<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * File containing the debug class.
 *
 * @package    tool_uploadusercli
 * @copyright  2015 Alexandru Elisei
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

define('NONE', 0);
define('LOW', 1);
define('VERBOSE', 2);

/**
 * Debug class.
 *
 * @package    tool_uploadusercli
 * @copyright  2015 Alexandru Elisei
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

class tool_uploadusercli_debug {

    /** @var int debug level. */
    protected $debuglevel = -1;

    /**
     * Constructor.
     *
     * @param debuglevel the debug verbosity level
     */
    public function __construct($debuglevel = NONE) {
        $this->debuglevel = $debuglevel;
    }

    /**
     * Print debug messages.
     *
     * @param string $message the message to display. 
     * @param int $requiredlevel at what debug verbosity level to display the message.
     * @param int $currentlevel override current debug level.
     * @param string $obj the object calling the debug output.
     * @param string $func the function callig the debug output.
     * @param var $var variable to dump.
     * @return void
     */
    public function show($message, $requiredlevel, $currentlevel = null, $obj = null, $func = null, $var = null) {
        if (is_null($currentlevel)) {
            if ($this->debuglevel == -1) {
                print "DEBUG::Class not constructed!\n";
                return null;
            } else {
                $currentlevel = $this->debuglevel;
            }
        }
        if (empty($requiredlevel) || !is_number($requiredlevel)) {
            print "DEBUG::Specify a debug level!\n";
            return null;
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
}

