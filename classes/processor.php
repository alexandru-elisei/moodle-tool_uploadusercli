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
     * Create all users, appending a suffix to the name if the user exists.
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

    /**
     * During creation, generate password.
     */
    const PASSWORD_MODE_GENERATE = 9;

    /**
     * During creation, require password field from file.
     */
    const PASSWORD_MODE_FIELD = 10;

    /**
     * @var object debug class
     */
    protected $debug = null;

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
    protected $allowsuspends;

    /** @var bool allow email duplicates. */
    protected $noemailduplicates;

    /** @var int debug level. */
    protected $debuglevel;

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
            $this->passwordmode = $options['passwordmode'];
        }

        if (isset($options['allowsuspends'])) {
            $this->allowsuspends = $options['allowsuspends'];
        }

        if (isset($options['noemailduplicates'])) {
            $this->noemailduplicates = $options['noemailduplicates'];
        }

        if (isset($options['debuglevel'])) {
            $this->debug = new tool_uploaduser_debug($options['debuglevel']);
            var_dump($this);
        }

        if ($this->debuglevel >= tool_uploaduser_processor::DEBUG_LEVEL_LOW) {
            print "PROCESSOR::Entered constructor...\n";
        }

        $this->cir = $cir;
        $this->columns = $cir->get_columns();
        $this->validate_csv();
        $this->reset();

        if ($this->debuglevel === tool_uploaduser_processor::DEBUG_LEVEL_VERBOSE) {
            print "\nPROCESSOR::new class created (function __construct):\n";
            var_dump($this);
        }
    }

    /**
     * Execute the process.
     *
     * @param object $tracker the output tracker to use.
     * @return void
     */
    public function execute($tracker = null) {

        global $DB;

        if ($this->debuglevel >= tool_uploaduser_processor::DEBUG_LEVEL_LOW) {
            print "PROCESSOR::Entered execute...\n";
        }

        if ($this->processstarted) {
            throw new moodle_exception('process_already_started', 'error');
        }
        $this->processstarted = true;

        if (is_null($tracker)) {
            $tracker = new tool_uploaduser_tracker(tool_uploaduser_tracker::OUTPUT_PLAIN);
        }
        $tracker->start();

        // Statistics for tracker.
        $total = 0;
        $created = 0;
        $updated = 0;
        $deleted = 0;
        $errors = 0;

        core_php_time_limit::raise();
        raise_memory_limit(MEMORY_HUGE);

        // Loop over CSV lines.
        while ($line = $this->cir->next()) {
            $this->linenum++;
            $total++;

            $data = $this->parse_line($line);
            $user = $this->get_user($data);

            //var_dump($user);

            if ($user->prepare()) {
                $user->proceed();

                if ($this->debuglevel >= tool_uploaduser_processor::DEBUG_LEVEL_LOW) {
                    print "PROCESSOR::User prepared...\n";
                }

                $status = $user->get_statuses();
                if (array_key_exists('coursecategoriescreated', $status)) {
                    $created++;
                } else if (array_key_exists('coursecategoryupdated', $status)) {
                    $updated++;
                } else if (array_key_exists('userdeleted', $status)) {
                    $deleted++;
                }
                
                if ($this->debuglevel === tool_uploaduser_processor::DEBUG_LEVEL_VERBOSE) {
                    print "PROCESSOR::user status after proceeding (function execute()):\n";
                    var_dump($status);
                }

                $data = array_merge($data, $user->get_finaldata(), array('id' => $user->get_id()));
                $tracker->output($this->linenum, true, $status, $data);
            } else {

                if ($this->debuglevel >= tool_uploaduser_processor::DEBUG_LEVEL_LOW) {
                    print "PROCESSOR::Prepare user failed...\n";
                }

                $errors++;
                $tracker->output($this->linenum, false, $user->get_errors(), $data);

                /*
                print "PROCESSOR::proceed errors:\n";
                $errors = $user->get_errors();
                var_dump($errors);
                 */
            }
        }
        $tracker->results($total, $created, $updated, $deleted, $errors);
    }

    /**
     * Return a user import object.
     *
     * @param array $data to import the user with.
     * @return tool_uploaduser_user
     */
    protected function get_user($data) {
        $importoptions = array(
            'allowdeletes'          => $this->allowdeletes,
            'allowrenames'          => $this->allowrenames,
            'standardise'           => $this->standardise,
            'paswordmode'           => $this->passwordmode,
            'updatepassword'        => $this->updatepassword,
            'allowsuspends'         => $this->allowsuspends,
            'noemailduplicates'     => $this->noemailduplicates,
            'debuglevel'            => $this->debuglevel,
        );
        return new tool_uploaduser_user($this->mode, $this->updatemode, $data, $importoptions);
    }

    /**
     * Parse a line to return an array(column => value)
     *
     * @param array $line returned by csv_import_reader
     * @return array
     */
    protected function parse_line($line) {
        $data = array();
        foreach ($line as $keynum => $value) {
            $column = $this->columns[$keynum];
            $lccolumn = trim(core_text::strtolower($column));
            $data[$lccolumn] = $value;
        }
        return $data;
    }


    /** 
     * CSV file validation.
     *
     * @return void
     */
    protected function validate_csv() {
        if (empty($this->columns)) {
            throw new moodle_exception('cannot_read_tmp_file', 'error');
        }
    }

    /**
     * Reset the current process.
     *
     * @return void.
     */
    protected function reset() {
        $this->processstarted = false;
        $this->linenum = 0;
        $this->cir->init();
        $this->errors = array();
    }
}
