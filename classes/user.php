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
 * @package    tool_uploadusercli
 * @copyright  2015 Alexandru Elisei
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * User class.
 *
 * @package    tool_uploadusercli
 * @copyright  2015 Alexandru Elisei
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_uploadusercli_user {

    /** Outcome of the process: creating the user */
    const DO_CREATE = 1;

    /** Outcome of the process: updating the user */
    const DO_UPDATE = 2;

    /** Outcome of the process: deleting the user */
    const DO_DELETE = 3;

    /** @var array final import data. */
    protected $finaldata = null;

    /** @var array user import data. */
    protected $rawdata = array();

    /** @var array errors. */
    protected $errors = array();

    /** @var array default values. */
    protected $defaults = array();

    /** @var int if the user will need to change his/her password. */
    protected $needpasswordchange = false;

    /** @var int debug level. */
    protected $debuglevel;

    /** @var array containing options passed from the processor. */
    protected $importoptions = array();

    /** @var int import mode. Matches tool_uploadusercli_processor::MODE_* */
    protected $mode;

    /** @var int update mode. Matches tool_uploadusercli_processor::UPDATE_* */
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

    /** @var bool if we need to logout the user after updating. */
    protected $dologout = false;

    /** @var bool set to true once we have started processing the user. */
    protected $processstarted = false;

    /** @var array supported auth plugins that enabled. */
    protected $supportedauths = array();

    /** @var array fields required on user creation. */
    static protected $mandatoryfields = array('username', 'firstname', 
                                              'lastname', 'email');

    /** @var array fields which are considered as options. */
    static protected $optionfields = array('deleted' => false, 'suspended' => false,
                                    'visible' => true, 'oldusername' => null);

    /**
     * Constructor
     *
     * @param int $mode import mode, constant matching tool_uploadusercli_processor::MODE_*
     * @param int $updatemode update mode, constant matching tool_uploadusercli_processor::UPDATE_*
     * @param array $rawdata raw user data.
     * @param array $importoptions import options.
     */
    public function __construct($mode, $updatemode, $rawdata, $importoptions = array()) {
        global $CFG;

        if ($mode !== tool_uploadusercli_processor::MODE_CREATE_NEW &&
                $mode !== tool_uploadusercli_processor::MODE_CREATE_ALL &&
                $mode !== tool_uploadusercli_processor::MODE_CREATE_OR_UPDATE &&
                $mode !== tool_uploadusercli_processor::MODE_UPDATE_ONLY) {
            throw new coding_exception('Incorrect mode.');
        } else if ($updatemode !== tool_uploadusercli_processor::UPDATE_NOTHING &&
                $updatemode !== tool_uploadusercli_processor::UPDATE_ALL_WITH_DATA_ONLY &&
                $updatemode !== tool_uploadusercli_processor::UPDATE_ALL_WITH_DATA_OR_DEFAULTS &&
                $updatemode !== tool_uploadusercli_processor::UPDATE_MISSING_WITH_DATA_OR_DEFAULTS) {
            throw new coding_exception('Incorrect update mode.');
        }

        $this->mode = $mode;
        $this->updatemode = $updatemode;

        if (!empty($rawdata['username'])) {
            // Stripping whitespaces.
            $this->rawdata['username'] = trim($rawdata['username']);
        }
        if (empty($rawdata['mnethostid'])) {
            $rawdata['mnethostid'] = (int) $CFG->mnet_localhost_id;
        }

        $this->debuglevel = UUC_DEBUG_NONE;
        if (!empty($importoptions['debuglevel'])) {
            $this->debuglevel = $importoptions['debuglevel'];
        }

        $this->rawdata = $rawdata;
        $this->finaldata = new stdClass();

        // Extract user options.
        foreach (self::$optionfields as $option => $default) {
            $this->options[$option] = $rawdata[$option] ? $rawdata[$option] : null;
        }

        // Copy import options.
        $this->importoptions = $importoptions;

        // Supported authentification plugins.
        $this->supportedauths = uu_supported_auths();

        tool_uploadusercli_debug::show("New class created", UUC_DEBUG_VERBOSE, 
                            $this->debuglevel, "USER", "__construct", $this);

        //var_dump($this);
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
     * Do we need to reset the password?
     *
     * @return bool
     */
    protected function reset_password() {
        return $this->importoptions['forcepasswordchange'] == tool_uploadusercli_processor::FORCE_PASSWORD_CHANGE_NONE ? false : true;
    }

    /**
     * Does the mode allow for user update?
     *
     * @return bool
     */
    public function can_update() {
        return in_array($this->mode,
                array(
                    tool_uploadusercli_processor::MODE_UPDATE_ONLY,
                    tool_uploadusercli_processor::MODE_CREATE_OR_UPDATE)
                ) && $this->updatemode !== tool_uploadusercli_processor::UPDATE_NOTHING;
    }

    /**
     * Does the mode allow for user creation?
     *
     * @return bool
     */
    protected function can_create() {
        return in_array($this->mode, array(tool_uploadusercli_processor::MODE_CREATE_ALL,
            tool_uploadusercli_processor::MODE_CREATE_NEW,
            tool_uploadusercli_processor::MODE_CREATE_OR_UPDATE));
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
        return (array) $this->finaldata;
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
        return $this->finaldata->id;
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
            $username = $this->rawdata['username'];
        }
        if (is_null($mnethostid)) {
            $mnethostid = $this->rawdata['mnethostid'];
        }

        return $DB->get_record('user', array('username' => $username, 
                                                'mnethostid' => $mnethostid));
    }

    /**
     * Validates and prepares the data.
     *
     * @return bool false is any error occured.
     */
    public function prepare() {
        global $DB, $CFG;

        tool_uploadusercli_debug::show("Entered prepare.", UUC_DEBUG_LOW, 
                                        $this->debuglevel, "USER");

        $this->prepared = true;
        
        // Standardise username.
        if ($this->importoptions['standardise']) {
            $this->rawdata['username'] = clean_param($this->rawdata['username'],
                                                    PARAM_USERNAME);
        }

        // Validate username format
        if ($this->rawdata['username'] !== clean_param($this->rawdata['username'],
                                                        PARAM_USERNAME)) {
            $this->error('invalidusername', 
                         new lang_string('invalidusername', 'error'));
            return false;
        }

        tool_uploadusercli_debug::show("Validated username.", UUC_DEBUG_LOW, 
                                                    $this->debuglevel, "USER");

        // Validate moodle net host id.
        if (!is_numeric($this->rawdata['mnethostid'])) {
            var_dump($this->rawdata['mnethostid']);
            $this->error('mnethostidnotanumber', 
                            new lang_string('mnethostidnotanumber', 'error'));
            return false;
        }

        tool_uploadusercli_debug::show("Validated mnethostid.", UUC_DEBUG_LOW,
                                                    $this->debuglevel, "USER");

        $this->existing = $this->exists();

        // Can we delete the user? We only need username for deletion.
        if (!empty($this->options['deleted'])) {
            if (empty($this->existing)) {
                $this->error('usernotdeletedmissing', 
                            new lang_string('usernotdeletedmissing', 'error'));
                return false;
            } else if (!$this->can_delete()) {
                $this->error('usernotdeletedoff', 
                                new lang_string('usernotdeletedoff', 'error'));
                return false;
            } else if (is_siteadmin($this->existing->id)) {
                $this->error('usernotdeletedadmin', 
                            new lang_string('usernotdeletedadmin', 'error'));
                return false;
            } else if ($this->rawdata['username'] === 'guest') {
                $this->error('guestnoeditprofileother', 
                        new lang_string('guestnoeditprofileother', 'error'));
                return false;
            }
            $this->do = self::DO_DELETE;

            tool_uploadusercli_debug::show("User deletion queued.", UUC_DEBUG_LOW,
                                                    $this->debuglevel, "USER");

            return true;
        }
 
        // Validate id field.
        if (isset($this->rawdata['id']) && !is_numeric($this->rawdata['id'])) {
            $this->error('useridnotanumber', new lang_string('useridnotanumber',
                'error'));
            return false;
        }

        tool_uploadusercli_debug::show("Validated id field.", UUC_DEBUG_LOW, 
                                                    $this->debuglevel, "USER");
 
        // Checking mandatory fields.
        foreach (self::$mandatoryfields as $key => $field) {
            if (!isset($this->rawdata[$field])) {
                $this->error('missingfield', new lang_string('missingfield',
                    'error', $field));
                return false;
            }
        }

        // Can we create/update the user under those conditions?
        if ($this->existing) {
            if (!$this->can_update() &&
            $this->mode != tool_uploadusercli_processor::MODE_CREATE_ALL) {
                $this->error('userexistsupdatenotallowed',
                    new lang_string('userexistsupdatenotallowed', 'error'));
                return false;
            }
        } else {
            // If I cannot create the course, or I'm in update-only mode and I'm 
            // not renaming
            if (!$this->can_create() && 
                    $this->mode === tool_uploadusercli_processor::MODE_UPDATE_ONLY &&
                    !isset($this->rawdata['oldusername'])) {

                $this->error('usernotexistscreatenotallowed',
                    new lang_string('usernotexistscreatenotallowed', 'error'));
                return false;
            }
        }

        // Preparing final user data.
        $finaldata = new stdClass();
        foreach ($this->rawdata as $field => $value) {
            $finaldata->$field = trim($value);
        }
        // Can the user be renamed?
        if (!empty($finaldata->oldusername)) {
            if ($this->existing) {
                $this->error('usernotrenamedexists',
                    new lang_string('usernotrenamedexists',  'error'));
                return false;
            }

            $oldusername = $finaldata->oldusername;
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
            }

            $this->do = self::DO_UPDATE;

            tool_uploadusercli_debug::show("Renaming queued.", UUC_DEBUG_LOW,
                                                    $this->debuglevel, "USER");

            $this->set_status('userrenamed', new lang_string('userrenamed', 
                'tool_uploadusercli', array('from' => $oldname, 'to' => $finaldata->name)));
        }

        // Do not update admin and guest account through the csv.
        if ($this->existing) {
            if (is_siteadmin($this->existing)) {
                $this->error('usernotupdatedadmin',
                    new lang_string('usernotupdatedadmin',  'tool_uploadusercli'));
                return false;
            } else if ($this->existing->username === 'guest') {
                $this->error('guestnoeditprofileother',
                        new lang_string('guestnoeditprofileother', 'error'));
                return false;
            }
        }

        // If exists, but we only want to create users, increment the username.
        if ($this->existing && $this->mode === tool_uploadusercli_processor::MODE_CREATE_ALL) {
            $original = $finaldata->username;
            $finaldata->username = uu_increment_username($finaldata->username);
            // We are creating a new user.
            $this->existing = null;

            if ($finaldata->username !== $original) {
                $this->set_status('userrenamed',
                            new lang_string('userrenamed', 'tool_uploadusercli',
                            array('from' => $original, 'to' => $this->name)));
            }

            tool_uploadusercli_debug::show("Username incremented.", UUC_DEBUG_LOW,
                                            $this->debuglevel, "USER");
        }  

        tool_uploadusercli_debug::show("Last sanity checks...", UUC_DEBUG_LOW,
                                        $this->debuglevel, "USER");

        // Ultimate check mode vs. existence.
        switch ($this->mode) {
            case tool_uploadusercli_processor::MODE_CREATE_NEW:
                if ($this->existing) {
                    $this->error('usernotaddedregistered',
                        new lang_string('usernotaddedregistered', 'error'));
                    return false;
                }
                break;
            case tool_uploadusercli_processor::MODE_CREATE_ALL:
                // This should not happen, we set existing to null when we increment. 
                if ($this->existing) {
                    $this->error('usernotaddederror',
                        new lang_string('usernotaddederror', 'error'));
                    return false;
                }
                break;
            case tool_uploadusercli_processor::MODE_UPDATE_ONLY:
                if (!$this->existing) {
                    $this->error('usernotexistcannotcreate',
                        new lang_string('usernotexistcannotcreate', 'tool_uploadusercli'));
                    return false;
                }
                break;
            case tool_uploadusercli_processor::MODE_CREATE_OR_UPDATE:
                if ($this->existing) {
                    if ($this->updatemode === tool_uploadusercli_processor::UPDATE_NOTHING) {
                        $this->error('updatemodedoessettonothing',
                            new lang_string('updatemodedoessettonothing', 'tool_uploadusercli'));
                        return false;
                    }
                }
                break;
            default:
                // O_o Huh?! This should really never happen here!
                $this->error('unknownimportmode', 
                    new lang_string('unknownimportmode', 'tool_uploadusercli'));
                return false;
        }

        // Get final data.
        if ($this->existing) {

            tool_uploadusercli_debug::show("Getting final update data.", 
                                    UUC_DEBUG_LOW, $this->debuglevel, "USER");

            $missingonly = ($updatemode === tool_uploadusercli_processor::UPDATE_MISSING_WITH_DATA_OR_DEFAULTS);
            $finaldata = $this->get_final_update_data($finaldata, $this->existing,
                                               $this->defaults, $missingonly);
            if (!$finaldata) {
                $this->error('usernotupdatederror', 
                            new lang_string('usernotupdatederror', 'error'));
                return false;
            } else {
                tool_uploadusercli_debug::show("Finaldata:", UUC_DEBUG_VERBOSE,
                            $this->debuglevel, "USER", "prepare", $finaldata);
                $this->do = self::DO_UPDATE;
            }
        } 
        else {
            $finaldata = $this->get_final_create_data($finaldata);
            if (!$finaldata) {
                $this->error('usernotaddederror',
                    new lang_string('usernotaddederror', 'error'));
                return false;
            } else {
                tool_uploadusercli_debug::show("Creation queued.", UUC_DEBUG_LOW,
                                                    $this->debuglevel, "USER");
                tool_uploadusercli_debug::show("Finaldata:", UUC_DEBUG_VERBOSE,
                            $this->debuglevel, "USER", "prepare", $finaldata);
                $this->do = self::DO_CREATE;
            }
        }

        // Saving data.
        $this->finaldata = $finaldata;
        return true;
    }

    /**
     * Proceed with the import of the user.
     *
     * @return void
     */
    public function proceed() {

        tool_uploadusercli_debug::show("Entering proceed.", UUC_DEBUG_LOW,
                                                    $this->debuglevel, "USER");

        if (!$this->prepared) {
            throw new coding_exception('The course has not been prepared.');
        } else if ($this->has_errors()) {
            throw new moodle_exception('Cannot proceed, errors were detected.');
        } else if ($this->processstarted) {
            throw new coding_exception('The process has already been started.');
        }
        $this->processstarted = true;

        if ($this->do === self::DO_DELETE) {

            tool_uploadusercli_debug::show("Deleting.", UUC_DEBUG_LOW,
                                            $this->debuglevel, "USER");

            $this->finaldata = $this->existing;
            try {
                $deletesuccess = delete_user($this->existing);
            } catch (moodle_exception $e) {
                $deletesuccess = false;
            }
            if ($deletesuccess) {
                $this->set_status('userdeleted', 
                    new lang_string('userdeleted', 'tool_uploaduser'));
            } else {
                $this->error('usernotdeletederror',
                    new lang_string('usernotdeletederror', 'error'));
            }
            return true;
        } else if ($this->do === self::DO_CREATE) {
            try {
                $this->finaldata->id = user_create_user($this->finaldata, false, false);
            } catch (Exception $e) {
                $this->error('errorcreatinguser',
                    new lang_string('errorcreatinguser', 'tool_uploadusercli'));
                return false;
            }

            $this->finaldata = uu_pre_process_custom_profile_data($this->finaldata);
            profile_save_data($this->finaldata);

            if ($this->needpasswordchange) {
                set_user_preference('auth_forcepasswordchange', 1, $this->finaldata);
                $this->set_status('forcepasswordchange', 
                                    new lang_string('forcepasswordchange'));
            }
            if ($this->finaldata->password === 'to be generated') {
                set_user_preference('create_password', 1, $this->finaldata);
            }

            $this->set_status('useradded', new lang_string('newuser'));
        } else if ($this->do === self::DO_UPDATE) {
            try {
                user_update_user($this->finaldata, false, false);
            } catch (Exception $e) {
                $this->error('usernotupdatederror',
                    new lang_string('usernotupdatederror', 'tool_uploadusercli'));
                return false;
            }

            // Wrong - class variable remoteuser
            /*
            if (!$remoteuser) {
                $this->finaldata = uu_pre_process_custom_profile_data($this->finaldata);
                profile_save_data($existinguser);
            }
             */
            
            if ($this->dologout) {
                \core\session\manager::kill_user_sessions($this->finaldata->id);
            }


            // DO SCRIPTS FOR BULK
            
            $this->set_status('useraccountupdated', 
                    new lang_string('useraccountupdated', 'tool_uploaduser'));
        }

        if ($this->do === self::DO_UPDATE || $this->do === self::DO_CREATE) {
            $this->add_to_cohort();
            $this->add_to_egr();
        }
    }

    /**
     * Assemble the user data.
     *
     * This returns the final data to be passed to update_user().
     *
     * @param array $finaldata current data.
     * @param bool $usedefaults are defaults allowed?
     * @param array $existingdata existing category data.
     * @param bool $missingonly ignore fields which are already set.
     * @return array
     */
    protected function get_final_update_data($data, $existingdata, $usedefaults = false, $missingonly = false) {
        global $DB, $UUC_STD_FIELDS, $UUC_PRF_FIELDS;
        $doupdate = false;

        $existingdata->timemodified = time();
        profile_load_data($existingdata);

        //var_dump($existingdata);

        // Changing auth information.
        if (!empty($existingdata->auth) && $data->auth) {
            $existingdata->auth = $data->auth;
            if ($data->auth === 'nologin') {
                $this->dologout = true;
            }
        }

        $allfields = array_merge($UUC_STD_FIELDS, $UUC_PRF_FIELDS);
        foreach ($allfields as $field) {
            // These fields are being processed separatedly.
            if ($field === 'password' || $field === 'auth' || 
                $field === 'suspended' || $field === 'oldusername') {
                continue;
            }

            // Field not present in the CSV file.
            if (!$data->$field && !$existingdata->$field) {
                continue;
            }

            if ($missingonly) {
                if ($existingdata->$field) {
                    continue;
                }
            } else if ($this->updatemode === tool_uploadusercli_processor::UPDATE_ALL_WITH_DATA_OR_DEFAULTS) {
                // Override everything.

            } else if ($this->updatemode === tool_uploadusercli_processor::UPDATE_ALL_WITH_DATA_ONLY) {
                if (!empty($this->defaults[$field])) {
                    // Do not override with form defaults.
                    continue;
                }
            }

            if ($existingdata->$field !== $data->$field) {
                // Renaming.
                if ($field === 'username' && $this->rawdata['oldusername'] &&
                                    $this->rawdata['oldusername'] !== '') {
                    $existingdata->username = $this->rawdata['username'];
                    $doupdate = true;
                    continue;
                } else if ($field === 'email') {
                    if ($DB->record_exists('user', array('email' => $data->email))) {
                        if ($this->importoptions['noemailduplicates']) {
                            $this->error('useremailduplicate',
                                new lang_string('useremailduplicate', 'error'));
                            return false;
                        } else {
                            $this->set_status('useremailduplicate', 
                                new lang_string('useremailduplicate', 'error'));
                        }
                    }
                    if (!validate_email($data->email)) {
                        $this->set_status('invalidemail', 
                                    new lang_string('invalidemail', 'warning'));
                    }
                } else if ($field === 'lang') {
                    if (empty($data->lang)) {
                        // Don't change language if not already set.
                        continue;
                    } else if (clean_param($data->lang, PARAM_LANG) === '') {
                        $this->set_status('cannotfindlang',
                                    new lang_string('cannotfindlang', 'error'));
                        continue;
                    }
                }
                // A new field was added to data while processing it.
                if (!empty($data->$field) && $data->$field !== '') {
                    $existingdata->$field = $data->$field;
                }

                $doupdate = true;
            }
        }
        try {
            $auth = get_auth_plugin($existingdata->auth);
        } catch (Exception $e) {
            $this->error('userautherror', new lang_string('userautherror', 'error'));
            return false;
        }

        $isinternalauth = $auth->is_internal();
        if ($this->importoptions['allowsuspends'] && isset($data->suspended) && 
                                                    $data->suspended !== '') {
            $data->suspended = $data->suspended ? 1 : 0;
            if ($existingdata->suspended != $data->suspended) {
                $existingdata->suspended = $data->suspended;
                $doupdate = true;
                if ($existingdata->suspended) {
                    $this->dologout = true;
                }
                if ($existingdata->suspended) {
                    $this->set_status('usersuspended',
                        new lang_string('usersuspended', 'tool_uploadusercli'));
                }
            }
        }

        $oldpasswd = $existingdata->password;
        if (!$isinternalauth) {
            $existingdata->password = AUTH_PASSWORD_NOT_CACHES;
            unset_user_preference('create_password', $existingdata);
            unset_user_preference('auth_forcepasswordchange', $existingdata);
        } else if (!empty($data->password)) {
            if ($this->can_update() && 
                    $this->updatemode != tool_uploadusercli_processor::UPDATE_MISSING_WITH_DATA_OR_DEFAULTS) {
                $errmsg = null;
                $weak = !check_password_policy($data->password, $errmsg);
                if ($this->importoptions['forcepassworchange'] === tool_uploadusercli_processor::FORCE_PASSWORD_CHANGE_ALL ||
                        ($this->reset_password() && $weak)) {
                    set_user_preference('auth_forcepasswordchange', $existingdata);
                } else {
                    unset_user_preference('auth_forcepasswordchange', $existingdata);
                }
                unset_user_preference('create_password', $existingdata);
                $existingdata->password = hash_internal_user_password($data->password, true);
            }
        }

        return $existingdata;
    }

    /**
     * Assemble the user data based on defaults.
     * This returns the final data to be passed to create_user().
     *
     * @param array data current data.
     * @return array
     */
    protected function get_final_create_data($data) {
        global $CFG, $DB;

        tool_uploadusercli_debug::show("Entering get_final_create_data.", 
                                    UUC_DEBUG_LOW, $this->debuglevel, "USER");

        $data->confirmed = 1;
        $data->timemodified = time();
        $data->timecreated = time();
        
        // Only local accounts. Huh?
        $data->mnethostid = $CFG->mnet_localhost_id;

        if (!isset($data->suspended) || $data->suspended === '') {
            $data->suspended = 0;
        } else {
            $data->suspended = $data->suspended ? 1 : 0;
        }

        if (empty($data->auth)) {
            $data->auth = 'manual';
        }
        try {
            $auth = get_auth_plugin($data->auth);
        } catch (Exception $e) {
            $this->error('exceptiongettingauthplugin', 
                        new lang_string('exceptiongettingauthplugin', 'error'));
            return false;
        }
        if (!isset($this->supportedauths[$data->auth])) {
            $this->set_status('userauthunsupported', 
                            new lang_string('userauthunsupported', 'error'));
        }

        if ($DB->record_exists('user', array('email' => $data->email))) {
            if ($this->importoptions['noemailduplicates']) {
                $this->error('useremailduplicate', 
                                new lang_string('useremailduplicate', 'error'));
                return false;
            } else {
                $this->set_status('useremailduplicate',
                                new lang_string('useremailduplicate', 'error'));
            }
        }
        if (!validate_email($data->email)) {
            $this->set_status('invalidemail', 
                                    new lang_string('invalidemail', 'warning'));
        }

        if (empty($data->lang)) {
            $data->lang ='';
        } else if (clean_param($data->lang, PARAM_LANG) === '') {
            $this->set_status('cannotfindlang', 
                                    new lang_string('cannotfindlang', 'error'));
            $data->lang='';
        }

        $isinternalauth = $auth->is_internal();
        $this->needpasswordchange = false;

        if ($isinternalauth) {

            tool_uploadusercli_debug::show("Checking password.", UUC_DEBUG_LOW,
                                                    $this->debuglevel, "USER");

            if (empty($data->password)) {
                if ($this->importoptions['passwordmode'] === tool_uploadusercli_processor::PASSWORD_MODE_GENERATE) {
                    $data->password = 'to be generated';
                } else {
                    $this->error('missingfield', 
                        new lang_string('missingfield', 'error', 'password'));
                    return false;
                }
            } else {
                $errmsg = null;
                $weak = !check_password_policy($data->password, $errmsg);
                if ($this->importoptions['forcepasswordchange'] == tool_uploadusercli_processor::FORCE_PASSWORD_CHANGE_ALL ||
                        ($this->reset_password() && $weak)) {
                    $this->needpasswordchange = true;
                }
                // Use a low cost factor when generating hash so it's not too
                // slow when uploading lots of users. Hashes will be 
                // automatically updated the first time the user logs in.
                $data->password = hash_internal_user_password($data->password, true);
            }
        } else {
            $data->password = AUTH_PASSWORD_NOT_CACHED;
        }
        // insert_record only keeps the valid fields for the record
        //$data->id = user_create_user($data, false, false);
        return $data;
    }

    /**
     * Adds an user to a cohort.
     *
     * @return void.
     */
    protected function add_to_cohort() {
        global $DB;
        $cohorts = array();

        tool_uploadusercli_debug::show("Entering add_to_cohort", UUC_DEBUG_LOW,
                                                    $this->debuglevel, 'USER');

        // Cohort is not a standard or profile field, it is not saved in the 
        // finaldata.
        foreach ($this->rawdata as $field => $value) {
            if (!preg_match('/^cohort\d+$/', $field)) {
                continue;
            }
            $addcohort = $value;
            if (!isset($cohorts[$addcohort])) {
                if (is_number($addcohort)) {
                    $cohort = $DB->get_record('cohort', array('id' => $addcohort));
                } else {
                    $cohort = $DB->get_record('cohort', array('idnumber' => $addcohort));
                    // Creating cohort.
                    if (empty($cohort)) {
                        try {
                            $cohortid = cohort_add_cohort((object) array(
                                'idnumber' => $addcohort,
                                'name' => $addcohort,
                                'contextid' => context_system::instance() ->id,
                            ));
                        } catch (Exception $e) {
                            $this->set_status($e->getMessage(), new lang_string (
                                    $e->getMessage(), 'tool_uploadusercli'));
                            return;
                        }

                        $cohort = $DB->get_record('cohort', array('id' => $cohortid));
                    }
                }

                if (empty($cohort)) {
                    $this->set_status("unknowncohort", new lang_string(
                        'unknowncohort', 'tool_uploadusercli'
                    ));
                } else if (!empty($cohort->component)) {
                    // Cohorts synced with external sources need not be modified
                    $cohorts[$addcohort] = get_string('external', 'core_cohort');
                } else {
                    $cohorts[$addcohort] = $cohort;
                }
            }

            if (is_object($cohorts[$addcohort])) {
                $cohort = $cohorts[$addcohort];
                if (!$DB->record_exists('cohort_members', 
                        array('cohortid' => $cohort->id, 'userid' => $this->finaldata->id))) {
                    try {
                        cohort_add_member($cohort->id, $this->finaldata->id);
                    } catch (Exception $e) {
                        $this->set_status($e->getMessage(), new lang_string(
                                    $e->getMessage(), 'tool_uploadusercli'));
                        return;
                    }
                    $this->set_status('cohortcreateduseradded', new lang_string(
                            'cohortcreateduseradded', 'tool_uploadusercli'));
                } else {
                    $this->set_status('erroraddingtocohort', new lang_string(
                                'erroraddingtocohort', 'tool_uploadusercli'));
                }
            }
        }
    }

    /**
     * Enrol the user, add him to groups and assign him roles.
     *
     * @return void
     */
    protected function add_to_egr() {
        global $DB;
        
        tool_uploadusercli_debug::show("Entering add_to_egr", UUC_DEBUG_LOW,
                                        $this->debuglevel, "USER");

        foreach ($this->rawdata as $field => $value) {
            if (preg_match('/^sysrole\d+$/', $field)) {
                $removing = false;
                if (!empty($value)) {
                    $sysrolename = $value;
                    // Removing sysrole.
                    if ($sysrolename[0] == '-') {
                        $removing = true;
                        $sysrolename = substr($sysrolename, 1);
                    }

                    // System roles lookup.
                    $sysrolecache = uu_allowed_sysroles_cache();
                    if (array_key_exists($sysrolename, $sysrolecache)) {
                        $sysroleid = $sysrolecache[$sysrolename]->id;
                    } else {
                        $this->set_status('unknownrole',
                            new lang_string('unknownrole', 'error', s($sysrolename)));
                        continue;
                    }

                    $isassigned = user_has_role_assignment($this->finaldata->id,
                                                    $sysroleid, SYSCONTEXTID);
                    if ($removing) {
                        if ($isassigned) {
                            role_unassign($sysroleid, $this->finaldata->id,
                                            SYSCONTEXTID);
                        }
                    } else {
                        if (!$isassigned) {
                            role_assign($sysroleid, $this->finaldata->id,
                                        SYSCONTEXTID);
                        }
                    }
                }
            } else if (preg_match('/^course\d+$/', $field)) {
                // Course number.
                $i = substr($field, 6);
                $shortname = $value;

                $course = $DB->get_record('course', array('shortname' => $shortname));
                if (!$course) {
                    $this->set_status('unknowncourse',
                        new lang_string('unknowncourse', 'error', s($shortname)));
                    continue;
                }
                $courseid = $course->id;
                $coursecontext = context_course::instance($courseid);

                $roles = uu_allowed_roles_cache();

                /*
                 * DE MUTAT LA MANUALCACHE!!!!!!
                 */

                if ($instances = enrol_get_instances($courseid, false)) {
                    foreach ($instances as $instance) {
                        if ($instance->enrol === 'manual') {
                            $coursecache = $instance;
                            break;
                        }
                    }
                }

                // Checking if manual enrol is enabled. If it's not, no 
                // enrolment is done.
                if (enrol_is_enabled('manual')) {
                    $manual = enrol_get_plugin('manual');
                } else {
                    $manual = null;
                }

                if ($courseid == SITEID) {
                    if (!empty($this->rawdata['role' . $i])) {
                        $rolename = $this->rawdata['role' . $i];
                        if (array_key_exists($rolename, $roles)) {
                            $roleid = $roles[$rolename]->id;
                        } else {
                            $this->set_status('unknownrole',
                                new lang_string('unknownrole', 'error', s($rolename)));
                            continue;
                        }

                        role_assign($roleid, $this->finaldata->id,
                                    context_course::instance($courseid));
                    }
                } else if ($manual) {
                    $roleid = false;
                    if (!empty($this->rawdata['role' . $i])) {
                        $rolename = $this->rawdata['role' . $i];
                        if (array_key_exists($rolename, $roles)) {
                            $roleid = $roles[$rolename]->id;
                        } else {
                            $this->set_status('unknownrole',
                                new lang_string('unknownrole', 'error', s($rolename)));
                            continue;
                        }
                    } else if (!empty($this->rawdata['type' . $i])) {
                        // If no role, find "old" enrolment type.
                        $addtype = $this->rawdata['type' . $i];
                        if ($addtype < 1 or $addtype > 3) {
                            $this->set_status('typeerror',
                                new lang_string('typeerror', 'error'));
                            continue;
                        } else {
                            $roleid = $this->rawdata['type' . $i];
                        }
                    } else {
                        // No role specified, use default course role.
                        $roleid = $coursecache->roleid;
                    }

                    if ($roleid) {
                        // Find duration and/or enrol status.
                        $timeend = 0;
                        $status = NULL;

                        if (isset($this->rawdata['enrolstatus' . $i])) {
                            $enrolstatus = $this->rawdata['enrolstatus' . $i];
                            if ($enrolstatus == '') {
                                // Do nothing
                            } else if ($enrolstatus === (string)ENROL_USER_ACTIVE) {
                                $status = ENROL_USER_ACTIVE;
                            } else if ($enrolstatus === (string)ENROL_USER_SUSPENDED) {
                                $status = ENROL_USER_SUSPENDED;
                            } else {
                                $this->set_status('unknownenrolstatus',
                                    new lang_string('unknownenrolstatus', 'error'));
                            }
                        }

                        if ($this->do === self::DO_UPDATE) {
                            $now = $this->finaldata->timemodified;
                        } else {
                            $now = $this->finaldata->timecreated;
                        }
                        if (!empty($this->rawdata['enrolperiod' . $i])) {
                            // Duration, in seconds.
                            $duration = (int)$this->rawdata['enrolperiod' . $i] * 60*60*24;
                            if ($duration > 0) {
                                $timeend = $now + $duration;
                            }
                        } else if ($coursecache->enrolperiod > 0) {
                            $timeend = $now + $coursecache->enrolperiod;
                        }

                        $manual->enrol_user($coursecache, $this->finaldata->id,
                                            $roleid, $now, $timeend, $status);
                    }
                }
            }
        }
    }

}
