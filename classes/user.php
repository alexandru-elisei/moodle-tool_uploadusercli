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
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->libdir . '/moodlelib.php');

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

    /** @var int the moodle net host id. */
    protected $mnethostid;

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
        global $CFG;
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

        if (!empty($rawdata['username'])) {
            // Stripping whitespaces.
            $this->username = trim($rawdata['username']);
        }
        if (empty($rawdata['mnethostid'])) {
            $this->mnethostid = $CFG->mnet_localhost_id;
        } else {
            $this->mnethostid = $rawdata['mnethostid'];
        }

        $this->rawdata = $rawdata;

        // Extract user options.
        foreach (self::$optionfields as $option => $default) {
            $this->options[$option] = $rawdata[$option] ? $rawdata[$option] : null;
        }

        // Copy import options.
        $this->importoptions = $importoptions;
    }

    /**
     * Log an error
     *
     * @param string $code error code.
     * @param lang_string $message error message.
     * @return void
     */
    protected function error($code, lang_string $message) {
        $this->errors[$code] = $message;
    }
    
    /**
     * Return the errors found.
     *
     * @return array
     */
    public function get_errors() {
        return $this->errors;
    }

    /**
     * Log a status
     *
     * @param string $code status code.
     * @param lang_string $message status message.
     * @return void
     */
    protected function set_status($code, lang_string $message) {
        if (array_key_exists($code, $this->statuses)) {
            throw new coding_exception('Status code already defined');
        }
        $this->statuses[$code] = $message;
    }

    /**
     * Return whether there were errors with this user.
     *
     * @return bool
     */
    public function has_errors() {
        return !empty($this->errors);
    }

    /**
     * Return the errors found during preparation.
     *
     * @return array
     */
    public function get_statuses() {
        return $this->statuses;
    }

    /**
     * Does the mode allow for user deletion?
     *
     * @return bool
     */
    protected function can_delete() {
        return $this->importoptions['allowdeletes'];
    }

    /**
     * Return the user database entry, or null.
     *
     * @param string $username the username to use to check if the user exists.
     * @param int $mnethostid the moodle net host id.
     * @return bool
     */
    protected function exists($username = null, $mnethostid = null) {
        global $DB;

        if (is_null($username)) {
            $username = $this->username;
        }
        if (is_null($mnethostid)) {
            $mnethostid = $this->mnethostid;
        }

        /*
        print "\nEXISTS()::username:\n";
        var_dump($username);
        print "mnethostid:\n";
        var_dump($mnethostid);
         */

        return $DB->get_record('user', array('username' => $username, 'mnethostid' => $mnethostid));
    }

    /**
     * Validates and prepares the data.
     *
     * @return bool false is any error occured.
     */
    public function prepare() {
        global $DB;

        $this->prepared = true;
        
        // Checking mandatory fields.
        foreach (self::$mandatoryfields as $key => $field) {
            if (!isset($this->rawdata[$field])) {
                $this->error('missingmandatoryfields', new lang_string('missingmandatoryfields',
                    'tool_uploaduser'));
                return false;
            }
        }

        // Validate id field.
        if (isset($this->rawdata['id']) && !is_numeric($this->rawdata['id'])) {
            $this->error('idnotanumber', new lang_string('idnotanumber',
                'tool_uploaduser'));
            return false;
        }

        // Standardise username.
        if ($this->importoptions['standardise']) {
            $this->username = clean_param($this->username, PARAM_USERNAME);
        }

        // Validate username format
        if ($this->username !== clean_param($this->username, PARAM_USERNAME)) {
            $this->error('invalidusername', new lang_string('invalidusername',
                'tool_uploaduser'));
            return false;
        }

        // Validate moodle net host id.
        if (!is_numeric($this->mnethostid)) {
            $this->error('mnethostidnotanumber', new lang_string('mnethostidnotanumber',
                'tool_uploaduser'));
            return false;
        }

        $this->existing = $this->exists();

        // Can we delete the user?
        if (!empty($this->options['deleted'])) {
            if (empty($this->existing)) {
                $this->error('usernotdeletedmissing', new lang_string('usernotdeletedmissing',
                    'tool_uploaduser'));
                return false;
            } else if (!$this->can_delete()) {
                $this->error('usernotdeletedoff', new lang_string('usernotdeletedoff',
                    'tool_uploaduser'));
                return false;
            }
            $this->do = self::DO_DELETE;

            print "USER::deletion queued...\n";

            return true;
        }
/*

        // Can we create/update the course under those conditions?
        if ($this->existing) {

            if ($this->mode === tool_uploaduser_processor::MODE_CREATE_NEW) {
                $this->error('categoryexistsanduploadnotallowed',
                    new lang_string('categoryexistsanduploadnotallowed', 'tool_uploaduser'));
                return false;
            }
        } else {
            // If I cannot create the course, or I'm in update-only mode and I'm 
            // not renaming
            if (!$this->can_create() && 
                    $this->mode === tool_uploaduser_processor::MODE_UPDATE_ONLY &&
                    !isset($this->rawdata['oldname'])) {
                $this->error('categorydoesnotexistandcreatenotallowed',
                    new lang_string('categorydoesnotexistandcreatenotallowed', 
                        'tool_uploaduser'));
                return false;
            }
        }

        // Preparing final category data.
        $finaldata = array();
        foreach ($this->rawdata as $field => $value) {
            if (!in_array($field, self::$validfields)) {
                continue;
            }
            $finaldata[$field] = $value;
        }
        $finaldata['name'] = $this->name;
       
        // Can the category be renamed?
        if (!empty($finaldata['oldname'])) {
            if ($this->existing) {
                $this->error('cannotrenamenamealreadyinuse',
                    new lang_string('cannotrenamenamealreadyinuse', 
                        'tool_uploaduser'));
                return false;
            }

            $categories = explode('/', $finaldata['oldname']);
            $oldname = array_pop($categories);
            $oldname = trim($oldname);
            $oldparentid = $this->prepare_parent($categories, 0);
            $this->existing = $this->exists($oldname, $oldparentid);

            if ($oldparentid === -1) {
                $this->error('oldcategoryhierarchydoesnotexist', 
                    new lang_string('coldcategoryhierarchydoesnotexist',
                        'tool_uploaduser'));
                return false;
            } else if (!$this->can_update()) {
                $this->error('canonlyrenameinupdatemode', 
                    new lang_string('canonlyrenameinupdatemode', 'tool_uploaduser'));
                return false;
            } else if (!$this->existing) {
                $this->error('cannotrenameoldcategorynotexist',
                    new lang_string('cannotrenameoldcategorynotexist', 
                        'tool_uploaduser'));
                return false;
            } else if (!$this->can_rename()) {
                $this->error('categoryrenamingnotallowed',
                    new lang_string('categoryrenamingnotallowed', 
                        'tool_uploaduser'));
                return false;
            } else if (isset($this->rawdata['idnumber'])) {
                // If category id belongs to another category
                if ($this->existing->idnumber !== $finaldata['idnumber'] &&
                        $DB->record_exists('course_categories', array('idnumber' => $finaldata['idnumber']))) {
                    $this->error('idnumberalreadyexists', new lang_string('idnumberalreadyexists', 
                        'tool_uploaduser'));
                    return false;
                }
            }

            // All the needed operations for renaming are done.
            $this->finaldata = $this->get_final_update_data($finaldata, $this->existing);
            $this->do = self::DO_UPDATE;

            $this->set_status('coursecategoryrenamed', new lang_string('coursecategoryrenamed', 
                'tool_uploaduser', array('from' => $oldname, 'to' => $finaldata['name'])));

            return true;
        }

        // If exists, but we only want to create categories, increment the name.
        if ($this->existing && $this->mode === tool_uploaduser_processor::MODE_CREATE_ALL) {
            $original = $this->name;
            $this->name = cc_increment_name($this->name);
            // We are creating a new course category
            $this->existing = null;

            if ($this->name !== $original) {
                $this->set_status('coursecategoryrenamed',
                    new lang_string('coursecategoryrenamed', 'tool_uploaduser',
                    array('from' => $original, 'to' => $this->name)));
                if (isset($finaldata['idnumber'])) {
                    $originalidn = $finaldata['idnumber'];
                    $finaldata['idnumber'] = cc_increment_idnumber($finaldata['idnumber']);
                }
            }
        }  

        // Check if idnumber is already taken
        if (!$this->existing && isset($finaldata['idnumber']) &&
                $DB->record_exists('course_categories', array('idnumber' => $finaldata['idnumber']))) {
            $this->error('idnumbernotunique', new lang_string('idnumbernotunique',
                'tool_uploaduser'));
            return false;
        }

        // Ultimate check mode vs. existence.
        switch ($this->mode) {
            case tool_uploaduser_processor::MODE_CREATE_NEW:
            case tool_uploaduser_processor::MODE_CREATE_ALL:
                if ($this->existing) {
                    $this->error('categoryexistsanduploadnotallowed',
                        new lang_string('categoryexistsanduploadnotallowed', 
                            'tool_uploaduser'));
                    return false;
                }
                break;
            case tool_uploaduser_processor::MODE_UPDATE_ONLY:
                if (!$this->existing) {
                    $this->error('categorydoesnotexistandcreatenotallowed',
                        new lang_string('categorydoesnotexistandcreatenotallowed',
                            'tool_uploaduser'));
                    return false;
                }
                // No break!
            case tool_uploaduser_processor::MODE_CREATE_OR_UPDATE:
                if ($this->existing) {
                    if ($updatemode === tool_uploaduser_processor::UPDATE_NOTHING) {
                        $this->error('updatemodedoessettonothing',
                            new lang_string('updatemodedoessettonothing', 'tool_uploaduser'));
                        return false;
                    }
                }
                break;
            default:
                // O_o Huh?! This should really never happen here!
                $this->error('unknownimportmode', new lang_string('unknownimportmode', 
                    'tool_uploaduser'));
                return false;
        }

        // Get final data.
        if ($this->existing) {
            $missingonly = ($updatemode === tool_uploaduser_processor::UPDATE_MISSING_WITH_DATA_OR_DEFAULTS);
            $finaldata = $this->get_final_update_data($finaldata, $this->existing, $this->defaults, $missingonly);

            // Make sure we are not trying to mess with the front page, though we should never get here!
            if ($finaldata['id'] == $SITE->id) {
                $this->error('cannotupdatefrontpage', new lang_string('cannotupdatefrontpage', 
                    'tool_uploaduser'));
                return false;
            }

            $this->do = self::DO_UPDATE;
        } else {
            $finaldata = $this->get_final_create_data($coursedata);
            $this->do = self::DO_CREATE;
        }

        // Saving data.
        $this->finaldata = $finaldata;
         */

        return true;
    }

}
