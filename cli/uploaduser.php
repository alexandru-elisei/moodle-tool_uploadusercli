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
 * @package    tool_uploaduser
 * @copyright  2015 Alexandru Elisei
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/csvlib.class.php');
require_once($CFG->dirroot . '/user/profile/lib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/group/lib.php');
require_once($CFG->dirroot . '/cohort/lib.php');

// Now get cli options.
list($options, $unrecognized) = cli_get_params(array(
    'help' => false,
    'mode' => '',
    'updatemode' => 'nothing',
    'password' => 'generate',         # other option: field
    'file' => '',
    'delimiter' => 'comma',
    'encoding' => 'UTF-8',
    'updatepassword' => false,
    'allowdeletes' => false,
    'allowrenames' => false,
    'standardise' => true,
    'createmissing' => false
),
array(
    'h' => 'help',
    'm' => 'mode',
    'u' => 'updatemode',
    'f' => 'file',
    'd' => 'delimiter',
    'e' => 'encoding',
    'p' => 'password',
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
-p, --password             Password creation: generate (default), field
--allowdeletes             Allow courses to be deleted: true or false (default)
--allowrenames             Allow courses to be renamed: true or false (default)
--standardise              Standardise category names: true (default) or false
--createmissing            Create missing parents in the hierarchy: true or false (default)
--updatepassword           Update existing user password: false (default), true


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

echo "Moodle user uploader running ...\n\n";

$processoroptions = array(
    'allowdeletes' => (is_bool($options['allowdeletes']) && $options['allowdeletes']
        ) || (core_text::strtolower($options['allowdeletes']) === 'true'),
    'allowrenames' => (is_bool($options['allowrenames']) && $options['allowrenames']
        ) || (core_text::strtolower($options['allowrenames']) === 'true'),
    'standardise' => (is_bool($options['standardise']) && $options['standardise']
        ) || (core_text::strtolower($options['standardise']) === 'true'),
    'createmissing' => (is_bool($options['createmissing']) && $options['createmissing']
        ) || (core_text::strtolower($options['createmissing']) === 'true'),
    'updatepassword' => (is_bool($options['updatepassword']) && $options['updatepassword']
        ) || (core_text::strtolower($options['updatepassword']) === 'true'),
);

// Confirm that the mode is valid.
$modes = array(
    /*
    'createnew' => tool_uploadcoursecategory_processor::MODE_CREATE_NEW,
    'createall' => tool_uploadcoursecategory_processor::MODE_CREATE_ALL,
    'createorupdate' => tool_uploadcoursecategory_processor::MODE_CREATE_OR_UPDATE,
    'update' => tool_uploadcoursecategory_processor::MODE_UPDATE_ONLY
     */

    'createnew' => 1,
    'createall' => 2,
    'createorupdate' => 3,
    'update' => 4,
);

if (!isset($options['mode']) || !isset($modes[$options['mode']])) {
    echo get_string('invalidmode', 'tool_uploaduser')."\n";
    echo $help;
    die();
}
$processoroptions['mode'] = $modes[$options['mode']];

/*
// Check that the update mode is valid.
$updatemodes = array(
    'nothing' => tool_uploadcoursecategory_processor::UPDATE_NOTHING,
    'dataonly' => tool_uploadcoursecategory_processor::UPDATE_ALL_WITH_DATA_ONLY,
    'dataordefaults' => tool_uploadcoursecategory_processor::UPDATE_ALL_WITH_DATA_OR_DEFAULTS,
    'missingonly' => tool_uploadcoursecategory_processor::UPDATE_MISSING_WITH_DATA_OR_DEFAULTS
);

if (($processoroptions['mode'] === tool_uploadcoursecategory_processor::MODE_CREATE_OR_UPDATE ||
        $processoroptions['mode'] === tool_uploadcoursecategory_processor::MODE_UPDATE_ONLY)
        && (!isset($options['updatemode']) || !isset($updatemodes[$options['updatemode']]))) {
    echo get_string('invalideupdatemode', 'tool_uploadcoursecategory')."\n";
    echo $help;
    die();
}
$processoroptions['updatemode'] = $updatemodes[$options['updatemode']];

// File.
if (!empty($options['file'])) {
    $options['file'] = realpath($options['file']);
}
if (!file_exists($options['file'])) {
    echo get_string('invalidcsvfile', 'tool_uploadcategory')."\n";
    echo $help;
    die();
}

// Encoding.
$encodings = core_text::get_encodings();
if (!isset($encodings[$options['encoding']])) {
    echo get_string('invalidencoding', 'tool_uploadcategory')."\n";
    echo $help;
    die();
}

// Emulate admin session.
cron_setup_user();

// Let's get started!
$content = file_get_contents($options['file']);
$importid = csv_import_reader::get_new_iid('uploadcoursecategory');
$cir = new csv_import_reader($importid, 'uploadcoursecategory');
$readcount = $cir->load_csv_content($content, $options['encoding'], $options['delimiter']);
if ($readcount === false) {
    print_error('csvfileerror', 'tool_uploadcourse', '', $cir->get_error());
} else if ($readcount == 0) {
    print_error('csvemptyfile', 'error', '', $cir->get_error());
}
unset($content);

$processor = new tool_uploadcoursecategory_processor($cir, $processoroptions);
$processor->execute();
 */

print "\nDone.\n";
