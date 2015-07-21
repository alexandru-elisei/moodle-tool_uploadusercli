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

// Verified, actually needed.
require_once($CFG->dirroot . '/user/profile/lib.php');

// Verified, actually needed.
require_once($CFG->dirroot . '/admin/tool/uploaduser/locallib.php');

// Verified, actually needed.
require_once($CFG->libdir . '/moodlelib.php');

// Verified, actually needed.
require_once($CFG->libdir . '/weblib.php');

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

    /** @var array supported auth plugins that enabled. */
    protected $supportedauths = array();

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

        // Supported authentification plugins.
        $this->supportedauths = uu_supported_auths();
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
     * Does the mode allow for user update?
     *
     * @return bool
     */
    public function can_update() {
        return in_array($this->mode,
                array(
                    tool_uploaduser_processor::MODE_UPDATE_ONLY,
                    tool_uploaduser_processor::MODE_CREATE_OR_UPDATE)
                ) && $this->updatemode !== tool_uploaduser_processor::UPDATE_NOTHING;
    }

    /**
     * Does the mode allow for user creation?
     *
     * @return bool
     */
    protected function can_create() {
        return in_array($this->mode, array(tool_uploaduser_processor::MODE_CREATE_ALL,
            tool_uploaduser_processor::MODE_CREATE_NEW,
            tool_uploaduser_processor::MODE_CREATE_OR_UPDATE));
    }

    /**
     * Does the mode allow for user renaming?
     *
     * @return bool
     */
    public function can_rename() {
        return $this->importoptions['allowrenames'];
    }

    /**
     * Return final user data.
     *
     * @return void
     */
    public function get_finaldata() {
        return $this->finaldata;
    }

    /**
     * Return the ID of the processed user.
     *
     * @return int|null
     */
    public function get_id() {
        /*
        if (!$this->processstarted) {
            throw new coding_exception('The course has not been processed yet!');
        }
         */
        return $this->id;
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
     * Delete the current user.
     *
     * @return bool
     */
    protected function delete() {
        global $DB;

        try {
            $ret = delete_user($this->existing);
        }
        catch (moodle_exception $e) {
            return false;
        }
        return $ret;
    }

    /**
     * Validates and prepares the data.
     *
     * @return bool false is any error occured.
     */
    public function prepare() {
        global $DB;

        $this->prepared = true;
        
        // Standardise username.
        if ($this->importoptions['standardise']) {
            $this->username = clean_param($this->username, PARAM_USERNAME);
        }

        // Validate username format
        if ($this->username !== clean_param($this->username, PARAM_USERNAME)) {
            $this->error('invalidusername', new lang_string('invalidusername',
                'error'));
            return false;
        }

        // Validate moodle net host id.
        if (!is_numeric($this->mnethostid)) {
            $this->error('mnethostidnotanumber', new lang_string('mnethostidnotanumber',
                'error'));
            return false;
        }

        $this->existing = $this->exists();

        var_dump($this);

        // Can we delete the user? We only need username for deletion.
        if (!empty($this->options['deleted'])) {
            if (empty($this->existing)) {
                $this->error('usernotdeletedmissing', new lang_string('usernotdeletedmissing',
                    'error'));
                return false;
            } else if (!$this->can_delete()) {
                $this->error('usernotdeletedoff', new lang_string('usernotdeletedoff',
                    'error'));
                return false;
            } else if ($this->username === 'admin') {
                $this->error('usernotdeletedadmin', new lang_string('usernotdeletedadmin',
                    'error'));
                return false;
            }
            $this->do = self::DO_DELETE;

            print "USER::deletion queued...\n";

            return true;
        }
 
        // Validate id field.
        if (isset($this->rawdata['id']) && !is_numeric($this->rawdata['id'])) {
            $this->error('useridnotanumber', new lang_string('useridnotanumber',
                'error'));
            return false;
        }
 
        // Checking mandatory fields.
        foreach (self::$mandatoryfields as $key => $field) {
            if (!isset($this->rawdata[$field])) {
                $this->error('missingfield', new lang_string('missingfield',
                    'error', $field));
                return false;
            }
        }

        // Cannot edit guest user.
        if ($this->username === 'guest') {
            $this->error('guestnoeditprofileother', new lang_string('guestnoeditprofileother',
                'error'));
            return false;
        }

        // Can we create/update the user under those conditions?
        if ($this->existing) {
            if ($this->mode === tool_uploaduser_processor::MODE_CREATE_NEW) {
                $this->error('userexistsupdatenotallowed',
                    new lang_string('userexistsupdatenotallowed', 'error'));
                return false;
            }
        } else {
            // If I cannot create the course, or I'm in update-only mode and I'm 
            // not renaming
            if (!$this->can_create() && 
                    $this->mode === tool_uploaduser_processor::MODE_UPDATE_ONLY &&
                    !isset($this->rawdata['oldusername'])) {
                $this->error('usernotexistscreatenotallowed',
                    new lang_string('usernotexistscreatenotallowed', 'error'));
                return false;
            }
        }

        print  "USER::Passed updating/existing checks...\n";

        // Preparing final category data.
        $finaldata = array();
        foreach ($this->rawdata as $field => $value) {
            if (!in_array($field, self::$validfields)) {
                continue;
            }
            $finaldata[$field] = $value;
        }
        $finaldata['username'] = $this->username;
        $finaldata['mnethostid'] = $this->mnethostid;

        /*
        print "Final data:\n";
        var_dump($finaldata);
         */
       
        // Can the user be renamed?
        if (!empty($finaldata['oldusername'])) {
            if ($this->existing) {
                $this->error('usernotrenamedexists',
                    new lang_string('usernotrenamedexists',  'error'));
                return false;
            }

            $oldusername = trim($finaldata['oldusername']);
            if ($this->importoptions['standardise']) {
                $oldusername = clean_param($oldusername, PARAM_USERNAME);
            }
            $this->existing = $this->exists($oldusername);

            if (!$this->can_update()) {
                $this->error('usernotupdatederror', 
                    new lang_string('usernotupdatederror', 'error'));
                return false;
            } else if (!$this->existing) {
                $this->error('usernotrenamedmissing',
                    new lang_string('usernotrenamedmissing', 'error'));
                return false;
            } else if (!$this->can_rename()) {
                $this->error('usernotrenamedoff',
                    new lang_string('usernotrenamedoff', 'error'));
                return false;
            } else if (isset($this->rawdata['id'])) {
                // If category id belongs to another user
                if ($this->existing->id !== $finaldata['id'] &&
                        $DB->record_exists('user', array('id' => $finaldata['id']))) {
                    $this->error('idnumberalreadyexists', new lang_string('idnumberalreadyexists', 
                        'error'));
                    return false;
                }
            }

            /*
            // All the needed operations for renaming are done.
            $this->finaldata = $this->get_final_update_data($finaldata, $this->existing);
             */
            $this->do = self::DO_UPDATE;

            print "USER::Renaming queued...\n";

            $this->set_status('userrenamed', new lang_string('userrenamed', 
                'tool_uploaduser', array('from' => $oldname, 'to' => $finaldata['name'])));

            //return true;
        }

        // Do not update admin and guest account through the csv.
        if ($this->existing) {
            if (is_siteadmin($this->existing->id)) {
                $this->error('usernotupdatedadmin',
                    new lang_string('usernotupdatedadmin',  'tool_uploaduser'));
                return false;
            } else if ($this->existing->username === 'guest') {
                $this->error('guestnoeditprofileother', new lang_string('guestnoeditprofileother',
                    'error'));
                return false;
            }
        }

        print "Before incrementing name...\n";

        // If exists, but we only want to create users, increment the username.
        if ($this->existing && $this->mode === tool_uploaduser_processor::MODE_CREATE_ALL) {
            $original = $this->username;
            $this->username = uu_increment_username($this->username);
            // We are creating a new user.
            $this->existing = null;

            if ($this->username !== $original) {
                $this->set_status('userrenamed',
                    new lang_string('userrenamed', 'tool_uploaduser',
                    array('from' => $original, 'to' => $this->name)));
                /*
                if (isset($finaldata['id'])) {
                    $originalidn = $finaldata['id'];
                    $finaldata['idnumber'] = uu_increment_idnumber($finaldata['idnumber']);
                }
                 */
            }
        }  

        /*
        // Check if idnumber is already taken
        if (!$this->existing && isset($finaldata['idnumber']) &&
                $DB->record_exists('course_categories', array('idnumber' => $finaldata['idnumber']))) {
            $this->error('idnumbernotunique', new lang_string('idnumbernotunique',
                'tool_uploaduser'));
            return false;
        }
         */

        print "Last check...\n";

        // Ultimate check mode vs. existence.
        switch ($this->mode) {
            case tool_uploaduser_processor::MODE_CREATE_NEW:
                if ($this->existing) {
                    $this->error('usernotaddedregistered',
                        new lang_string('usernotaddedregistered', 'error'));
                    return false;
                }
                break;
            case tool_uploaduser::MODE_CREATE_ALL:
                // This should not happen, we set existing to null when we increment. 
                if ($this->existing) {
                    $this->error('usernotaddederror',
                        new lang_string('usernotaddederror', 'error'));
                    return false;
                }
                break;
            case tool_uploaduser_processor::MODE_UPDATE_ONLY:
                if (!$this->existing) {
                    $this->error('usernotexistcreatenotallowed',
                        new lang_string('usernotexistcreatenotallowed', 'tool_uploaduser'));
                    return false;
                }
                // No break!
            case tool_uploaduser_processor::MODE_CREATE_OR_UPDATE:
                if ($this->existing) {
                    if ($this->updatemode === tool_uploaduser_processor::UPDATE_NOTHING) {
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
            $this->do = self::DO_UPDATE;
        } 
        /*
        else {
            $finaldata = $this->get_final_create_data($coursedata);
            $this->do = self::DO_CREATE;
        }

         */

        // Saving data.
        $finaldata['username'] = $this->username;
        $this->finaldata = $finaldata;
        return true;
    }

    /**
     * Proceed with the import of the user.
     *
     * @return void
     */
    public function proceed() {
        if (!$this->prepared) {
            throw new coding_exception('The course has not been prepared.');
        } else if ($this->has_errors()) {
            throw new moodle_exception('Cannot proceed, errors were detected.');
        } else if ($this->processstarted) {
            throw new coding_exception('The process has already been started.');
        }
        $this->processstarted = true;

        if ($this->do === self::DO_DELETE) {
            if ($this->delete()) {
                $this->set_status('userdeleted', 
                    new lang_string('userdeleted', 'tool_uploaduser'));
            } else {
                $this->error('usernotdeletederror', new lang_string('usernotdeletederror',
                    'error'));
            }
            return true;
        }
    }

    /**
     * Assemble the user data.
     *
     * This returns the final data to be passed to update_category().
     *
     * @param array $finaldata current data.
     * @param bool $usedefaults are defaults allowed?
     * @param array $existingdata existing category data.
     * @param bool $missingonly ignore fields which are already set.
     * @return array
     */
    protected function get_final_update_data($data, $existingdata, $usedefaults = false, $missingonly = false) {
        global $DB;

        $dologout = false;

        $this->existing->timemodified = time();
        profile_load_data($this->existing);

        if ($this->updatemode != tool_uploaduser_processor::UPDATE_NOTHING) {
            // Changing auth information.
            if (!empty($existingdata['auth'])) && $data['auth'] {
                $existingdata['auth'] = $data['auth'];
                if ($data['auth'] === 'nologin') {
                    $dologout = true;
                }
            }
            foreach ($this->validfields as $field) {
                if ($field === 'username' || $field === 'password' ||
                        $field === 'auth' || $field === 'suspended')
                    continue;

                if (!$data[$field] || !$existingdata[$field]) {
                    continue;
                }
                if ($missingonly) {
                    if ($existingdata[$field]) {
                        continue;
                    }
                } else if ($this->updatemode === tool_uploaduser_processor::UPDATE_ALL_WITH_SATA_OR_DEFAULTS) {
                    // Override everything.

                } else if ($this->updatemode === tool_uploaduser_processor::UPDATE_ALL_WITH_DATA_ONLY) {
                    if (!empty($this->defaults[$field]) {
                        // Do not override with form defaults.
                        continue;
                    }
                }
            }
        }


        return $newdata;
    }
}
