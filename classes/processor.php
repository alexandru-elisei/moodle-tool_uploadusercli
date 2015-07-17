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
 * File containing processor class.
 *
 * @package    tool_uploaduser
 * @copyright  2015 Alexandru Elisei
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/csvlib.class.php');
require_once($CFG->libdir . '/datalib.php');

/**
 * Processor class.
 *
 * @package    tool_uploaduser
 * @copyright  2015 Alexandru Elisei
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_uploaduser_processor {

    /**
     * Create users that do not exist yet.
     */
    const MODE_CREATE_NEW = 1;

    /**
     * Create all users, appending a suffix to the ?????? ??name ???? if the user exists.
     */
    const MODE_CREATE_ALL = 2;

    /**
     * Create users, and update the ones that already exist.
     */
    const MODE_CREATE_OR_UPDATE = 3;

    /**
     * Only update existing users.
     */
    const MODE_UPDATE_ONLY = 4;

    /**
     * Do not update existing users.
     */
    const UPDATE_NOTHING = 5;

    /**
     * During update, only use data passed from the CSV.
     */
    const UPDATE_ALL_WITH_DATA_ONLY = 6;

    /**
     * During update, use either data from the CSV, or defaults.
     */
    const UPDATE_ALL_WITH_DATA_OR_DEFAULTS = 7;

    /**
     * During update, update missing values from either data from the CSV, or defaults.
     */
    const UPDATE_MISSING_WITH_DATA_OR_DEFAULTS = 8;

    /** @var int processor mode. */
    protected $mode;

    /** @var int upload mode. */
    protected $updatemode;

    /** @var int password creation mode. */
    protected $passwordmode;

    /** @var bool are renames allowed. */
    protected $allowrenames;

    /** @var bool are deletes allowed. */
    protected $allowdeletes;

    /** @var bool are to be standardised. */
    protected $standardise;

    /** @var bool are existing passwords updated. */
    protected $updatepassword;

    /** @var bool allow suspending or activating of users. */
    protected $allowsuspendoractivate;

    /** @var bool allow email duplicates. */
    protected $allowemailduplicates;

    /** @var csv_import_reader. */
    protected $cir;

    /** @var array CSV columns. */
    protected $columns = array();

    /** @var array of errors where the key is the line number. */
    protected $errors = array();

    /** @var int line number. */
    protected $linenum = 0;

    /** @var bool whether the process has been started or not. */
    protected $processstarted = false;

    /**
     * Constructor
     *
     * @param csv_import_reader $cir import reader object
     * @param array $options options of the process
     */
    public function __construct(csv_import_reader $cir, array $options) {

        // Extra sanity checks
        if (!isset($options['mode']) || !in_array($options['mode'], array(self::MODE_CREATE_NEW, self::MODE_CREATE_ALL,
                self::MODE_CREATE_OR_UPDATE, self::MODE_UPDATE_ONLY))) {
            throw new coding_exception('Unknown process mode');
        }

        // Force int to make sure === comparison work as expected.
        $this->mode = (int) $options['mode'];

        // Default update mode
        $this->updatemode = self::UPDATE_NOTHING;
        if (isset($options['updatemode'])) {
            $this->updatemode = (int) $options['updatemode'];
        }
        if (isset($options['allowrenames'])) {
            $this->allowrenames = $options['allowrenames'];
        }
        if (isset($options['allowdeletes'])) {
            $this->allowdeletes = $options['allowdeletes'];
        }
        if (isset($options['standardise'])) {
            $this->standardise = $options['standardise'];
        }

        if (isset($options['updatepassword'])) {
            $this->updatepassword = $options['updatepassword'];
        }

        if (isset($options['passwordmode'])) {
            $this->paswordmode = $options['passwordmode'];
        }

        if (isset($options['allowsuspendoractivate'])) {
            $this->allowsuspendoractivate = $options['allowsuspendoractivate'];
        }

        if (isset($options['allowemailduplicates'])) {
            $this->allowemailduplicates = $options['allowemailduplicates'];
        }

        $this->cir = $cir;
        $this->columns = $cir->get_columns();

        //var_dump($this);
        /*
        $this->validate_csv();
        $this->reset();
         */
    }


}

