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
 * CLI user registration script from a comma separated file.
 *
 * @package    tool_uploadusercli
 * @copyright  2015 Alexandru Elisei
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/enrollib.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/csvlib.class.php');
require_once($CFG->dirroot . '/user/profile/lib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/group/lib.php');
require_once($CFG->dirroot . '/cohort/lib.php');
require_once($CFG->dirroot . '/admin/tool/uploaduser/locallib.php');
require_once('../classes/debug.php');

/** @var array of standard csv fields. */
$UUC_STD_FIELDS = array('id', 'username', 'email',
    'city', 'country', 'lang', 'timezone', 'mailformat', 'firstname',
    'maildisplay', 'maildigest', 'htmleditor', 'autosubscribe',
    'institution', 'department', 'idnumber', 'skype', 'lastname',
    'msn', 'aim', 'yahoo', 'icq', 'phone1', 'phone2', 'address',
    'url', 'description', 'descriptionformat', 'password',
    'auth',        
    'oldusername', // use when renaming users - this is the original username
    'suspended',   // 1 means suspend user account, 0 means activate user account, nothing means keep as is for existing users
    'deleted',     // 1 means delete user
    'mnethostid',  // Can not be used for adding, updating or deleting of users - only for enrolments, groups, cohorts and suspending.
);

/** @var array of profile fields. */
$UUC_PRF_FIELDS = array();

// Now get cli options.
list($options, $unrecognized) = cli_get_params(array(
    'help' => false,
    'mode' => '',
    'updatemode' => 'nothing',
    'passwordmode' => 'generate',         # other option: field
    'file' => '',
    'delimiter' => 'comma',
    'encoding' => 'UTF-8',
    'updatepassword' => false,
    'allowdeletes' => false,
    'allowrenames' => false,
    'allowsuspends' => true,
    'noemailduplicates' => true,
    'standardise' => true,
    'debuglevel' => 'none',
    'forcepasswordchange' => 'none',
),
array(
    'h' => 'help',
    'm' => 'mode',
    'u' => 'updatemode',
    'f' => 'file',
    'd' => 'delimiter',
    'e' => 'encoding',
    'p' => 'passwordmode',
));

$help =
"Execute User Upload.

Options:
-h, --help                 Print out this help
-m, --mode                 Import mode: createnew, createall, createorupdate, update
-u, --updatemode           Update mode: nothing (default), dataonly, dataordefaults¸ missingonly
-f, --file                 CSV file
-d, --delimiter            CSV delimiter: colon, semicolon, tab, cfg, comma (default)
-e, --encoding             CSV file encoding: utf8 (default), ... etc
-p, --passwordmode         Password creation mode: generate (default), field
--allowdeletes             Allow users to be deleted: true or false (default)
--allowrenames             Allow users to be renamed: true or false (default)
--standardise              Standardise user names: true (default) or false
--updatepassword           Update existing user password: false (default) or true
--allowsuspends            Allow suspending or activating of accounts: true (default) false
--noemailduplicates        Do not allow duplicate email addresses: true (default) or false
--debuglevel               Debug level: none (default), low or verbose
--forcepasswordchange      Force users to reset their passwords: none (default), weak, all

Example:
\$sudo -u www-data /usr/bin/php admin/tool/uploaduser/cli/uploaduser.php --mode=createnew \\
       --file=./users.csv --delimiter=comma
";

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if ($options['help']) {
    echo $help;
    die();
}

//echo "Moodle user uploader running ...\n\n";

$processoroptions = array(
    'allowdeletes' => ($options['allowdeletes'] === true ||
                core_text::strtolower($options['allowdeletes']) == 'true'),
    'allowrenames' => ($options['allowrenames'] === true ||
                core_text::strtolower($options['allowrenames']) == 'true'),
    'standardise' => ($options['standardise'] === true ||
                core_text::strtolower($options['standardise']) == 'true'),
    'updatepassword' => ($options['updatepassword'] === true || 
                core_text::strtolower($options['updatepassword']) == 'true'),
    'allowsuspends' => ($options['allowsuspends'] === true || 
                core_text::strtolower($options['allowsuspends']) == 'true'),
    'noemailduplicates' => ($options['noemailduplicates'] === true ||
                core_text::strtolower($options['noemailduplicates']) == 'true'),
);

// Confirm that the mode is valid.
$modes = array(
    'createnew' => tool_uploadusercli_processor::MODE_CREATE_NEW,
    'createall' => tool_uploadusercli_processor::MODE_CREATE_ALL,
    'createorupdate' => tool_uploadusercli_processor::MODE_CREATE_OR_UPDATE,
    'update' => tool_uploadusercli_processor::MODE_UPDATE_ONLY
);

if (!isset($options['mode']) || !isset($modes[$options['mode']])) {
    echo get_string('invalidmode', 'tool_uploaduser')."\n";
    echo $help;
    die();
}
$processoroptions['mode'] = $modes[$options['mode']];

// Check that the update mode is valid.
$updatemodes = array(
    'nothing' => tool_uploadusercli_processor::UPDATE_NOTHING,
    'dataonly' => tool_uploadusercli_processor::UPDATE_ALL_WITH_DATA_ONLY,
    'dataordefaults' => tool_uploadusercli_processor::UPDATE_ALL_WITH_DATA_OR_DEFAULTS,
    'missingonly' => tool_uploadusercli_processor::UPDATE_MISSING_WITH_DATA_OR_DEFAULTS
);

if (($processoroptions['mode'] === tool_uploadusercli_processor::MODE_CREATE_OR_UPDATE ||
        $processoroptions['mode'] === tool_uploadusercli_processor::MODE_UPDATE_ONLY)
        && (!isset($options['updatemode']) || !isset($updatemodes[$options['updatemode']]))) {
    echo get_string('invalideupdatemode', 'tool_uploaduser')."\n";
    echo $help;
    die();
}
$processoroptions['updatemode'] = $updatemodes[$options['updatemode']];


// Check that password creation mode is valid.
$passwordmodes = array(
    'generate' => tool_uploadusercli_processor::PASSWORD_MODE_GENERATE,
    'field' => tool_uploadusercli_processor::PASSWORD_MODE_FIELD, 
);

if (!isset($options['passwordmode']) || !isset($passwordmodes[$options['passwordmode']])) {
    echo get_string('invalidpasswordmode', 'tool_uploaduser')."\n";
    echo $help;
    die();
}
$processoroptions['passwordmode'] = $passwordmodes[$options['passwordmode']];

// Check if enforcing password changing is valid.
$forcepasswordchanges = array(
    'none' => tool_uploadusercli_processor::FORCE_PASSWORD_CHANGE_NONE,
    'weak' => tool_uploadusercli_processor::FORCE_PASSWORD_CHANGE_WEAK,
    'all' => tool_uploadusercli_processor::FORCE_PASSWORD_CHANGE_ALL,
);

if (!isset($options['forcepasswordchange']) || !isset($forcepasswordchanges[$options['forcepasswordchange']])) {
    echo get_string('invalidpasswordenforcingmode', 'tool_uploaduser')."\n";
    echo $help;
    die();
}
$processoroptions['forcepasswordchange'] = $forcepasswordchanges[$options['forcepasswordchange']];

// Check debug level.
$debuglevels = array(
    'none' => UUC_DEBUG_NONE,
    'low' => UUC_DEBUG_LOW,
    'verbose' => UUC_DEBUG_VERBOSE,
);

if (!isset($options['debuglevel']) || !isset($debuglevels[$options['debuglevel']])) {
    echo get_string('invaliddebuglevel', 'tool_uploaduser')."\n";
    echo $help;
    die();
}
$processoroptions['debuglevel'] = $debuglevels[$options['debuglevel']];

// File.
if (!empty($options['file'])) {
    $options['file'] = realpath($options['file']);
}
if (!file_exists($options['file'])) {
    echo get_string('invalidcsvfile', 'tool_uploaduser')."\n";
    echo $help;
    die();
}

// Encoding.
$encodings = core_text::get_encodings();
if (!isset($encodings[$options['encoding']])) {
    echo get_string('invalidencoding', 'tool_uploaduser')."\n";
    echo $help;
    die();
}

// Emulate admin session.
cron_setup_user();

// Let's get started!
$content = file_get_contents($options['file']);
$importid = csv_import_reader::get_new_iid('uploaduser');
$cir = new csv_import_reader($importid, 'uploaduser');
$readcount = $cir->load_csv_content($content, $options['encoding'], $options['delimiter']);
if ($readcount === false) {
    print_error('csvfileerror', 'tool_uploaduser', '', $cir->get_error());
} else if ($readcount == 0) {
    print_error('csvemptyfile', 'error', '', $cir->get_error());
}

unset($content);

$processor = new tool_uploadusercli_processor($cir, $processoroptions);
$processor->execute();

print "Done.\n";
