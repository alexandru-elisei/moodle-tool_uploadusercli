## moodle-tool_uploadusercli

#### Description
moodle-tool_uploadusercli is a plugin for Moodle to upload users from a CSV
(comma separated values) file using the cli, in much the same way that 
admin/tool/uploadcourse/cli works for uploading courses.

#### Dependencies
This plugin requires the plugin tool_uploaduser installed to function properly.

#### Installation
This plugin has been tested to work with Moodle 2.6. There are no guarantess it
will work with earlier versions.

General installation procedures are those common for all moodle plugins:
https://docs.moodle.org/29/en/Installing_plugins

The basic process involves cloning the repository into MOODLE_ROOT_DIRECTORY/admin/tool/uploadusercli:

    git clone https://github.com/alexandru-elisei/moodle-tool_uploadusercli.git MOODLE_ROOT_DIRECTORY/admin/tool/uploadusercli,

replacing MOODLE_ROOT_DIRECTORY with the actual moodle installation root
directory path.

As an alternative, you can also download the zip file and extract it to the same
location.

If you are cloning the git repository, keep in mind that this also creates a
.git directory.

#### Usage
Basic usage:

    php uploadusercli.php --mode=createnew --file=csv_file.csv

The script supports all the modes and actions that are available in the web
interface form. For a complete list of options and their syntax please use 
the following command:

    php uploadusercli.php --help

The CSV file has the same format CSV used to upload users from the web 
interface. More details can be found here: https://docs.moodle.org/29/en/Upload_users

#### Copyright
Copyright (C) Alexandru Elisei 2015 and beyond, All right reserved.

moodle-tool_uploadusercli is free software; you can redistribute it and/or
modify it under the terms of the GNU Lesser General Public License as published
by the Free Software Foundation; either version 3 of the license, or (at your
option) any later version.

This software is distributed in the hope that it will be useful, but WITHOUT
ANY WARRANTY; without even the implied warranty of the MERCHANTABILITY or
FITNESS FOR A PARTICULAR PURPOSE. See the GNU Lesser General Public License for
more details.
