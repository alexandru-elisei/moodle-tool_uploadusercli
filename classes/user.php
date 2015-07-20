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
 * File containing the user class.
 *
 * @package    tool_uploaduser
 * @copyright  2015 Alexandru Elisei
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot . '/admin/tool/uploadcoursecategory/locallib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->libdir . '/coursecatlib.php');

/**
 * User class.
 *
 * @package    tool_uploaduser
 * @copyright  2015 Alexandru Elisei
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_uploaduser_user {

    /** Outcome of the process: creating the user */
    const DO_CREATE = 1;

    /** Outcome of the process: updating the user */
    const DO_UPDATE = 2;

    /** Outcome of the process: deleting the user */
    const DO_DELETE = 3;

    /** @var array final import data. */
    protected $finaldata = array();

    /** @var array user import data. */
    protected $rawdata = array();

    /** @var array errors. */
    protected $errors = array();

    /** @var array default values. */
    protected $defaults = array();

    /** @var int the ID of the user that has been processed. */
    protected $id;

    /** @var array containing options passed from the processor. */
    protected $importoptions = array();

    /** @var int import mode. Matches tool_uploaduser_processor::MODE_* */
    protected $mode;

    /** @var int update mode. Matches tool_uploaduser_processor::UPDATE_* */
    protected $updatemode;

    /** @var array user import options. */
    protected $options = array();
   
    /** @var array operations executed. */
    protected $status = array();

    /** @var int constant value of self::DO_*, what to do with that user. */
    protected $do;

    /** @var array database record of an existing user. */
    protected $existing = null;

    /** @var bool set to true once we have prepared the user. */
    protected $prepared = false;

    /** @var bool set to true once we have started processing the user. */
    protected $processstarted = false;

    /** @var string username. */
    protected $username;

    /** @var array fields allowed as user data. */
    static protected $validfields = array('id', 'username', 'email',
        'city', 'country', 'lang', 'timezone', 'mailformat', 'firstname',
        'maildisplay', 'maildigest', 'htmleditor', 'autosubscribe',
        'institution', 'department', 'idnumber', 'skype', 'lastname',
        'msn', 'aim', 'yahoo', 'icq', 'phone1', 'phone2', 'address',
        'url', 'description', 'descriptionformat', 'password',
        'auth',        // watch out when changing auth type or using external auth plugins!
        'oldusername', // use when renaming users - this is the original username
        'suspended',   // 1 means suspend user account, 0 means activate user account, nothing means keep as is for existing users
        'deleted',     // 1 means delete user
        'mnethostid',  // Can not be used for adding, updating or deleting of users - only for enrolments, groups, cohorts and suspending.
    );

    /** @var array fields required on user creation. */
    static protected $mandatoryfields = array('username', 'firstname', 
        'lastname', 'email'
    );

    /** @var array fields which are considered as options. */
    static protected $optionfields = array('deleted' => false, 'suspended' => false,
        'visible' => true, 'oldusername' => null
    );

    /**
     * Constructor
     *
     * @param int $mode import mode, constant matching tool_uploaduser_processor::MODE_*
     * @param int $updatemode update mode, constant matching tool_uploaduser_processor::UPDATE_*
     * @param array $rawdata raw user data.
     * @param array $importoptions import options.
     */
    public function __construct($mode, $updatemode, $rawdata, $importoptions = array()) {
        if ($mode !== tool_uploaduser_processor::MODE_CREATE_NEW &&
                $mode !== tool_uploaduser_processor::MODE_CREATE_ALL &&
                $mode !== tool_uploaduser_processor::MODE_CREATE_OR_UPDATE &&
                $mode !== tool_uploaduser_processor::MODE_UPDATE_ONLY) {
            throw new coding_exception('Incorrect mode.');
        } else if ($updatemode !== tool_uploaduser_processor::UPDATE_NOTHING &&
                $updatemode !== tool_uploaduser_processor::UPDATE_ALL_WITH_DATA_ONLY &&
                $updatemode !== tool_uploaduser_processor::UPDATE_ALL_WITH_DATA_OR_DEFAULTS &&
                $updatemode !== tool_uploaduser_processor::UPDATE_MISSING_WITH_DATA_OR_DEFAULTS) {
            throw new coding_exception('Incorrect update mode.');
        }

        $this->mode = $mode;
        $this->updatemode = $updatemode;

        if (isset($rawdata['username'])) {
            // Stripping whitespaces.
            $this->username = trim($rawdata['username']);
        }
        $this->rawdata = $rawdata;

        // Extract user options.
        foreach (self::$optionfields as $option => $default) {
            $this->options[$option] = $rawdata[$option] ? $rawdata[$option] : null;
        }

        // Copy import options.
        $this->importoptions = $importoptions;
    }
}
