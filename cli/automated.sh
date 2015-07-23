#!/bin/bash

test_file='automated.csv'
debuglevel="${1}"

create_text() {
	name=${1}
	expected=${2}
	columns=${3}
	values=${4}

	echo -e "\n-------------------------------------------------------------------"
	echo -e "\t\t${name} (expecting ${expected})"
	echo "${columns}" | tee "$test_file"
	echo "${values}" | tee -a "$test_file"
	echo "-------------------------------------------------------------------"
	echo -e "\n"
}

create_aux() {
	columns=${1}
	values=${2}
	sed -i 's/$processor->execute()/$processor->execute(new tool_uploaduser_tracker(tool_uploaduser_tracker::NO_OUTPUT))/' uploaduser.php
	echo "${columns}" > "$test_file"
	echo "${values}" >> "$test_file"
}

make_pristine() {
	sed -i 's/$processor->execute(new tool_uploaduser_tracker(tool_uploaduser_tracker::NO_OUTPUT))/$processor->execute()/' uploaduser.php
}

#new=`date|cut -d' ' -f1-4|sed 's/ //g'|sed 's/://g'`;
new=`date +%s`
make_pristine


# Creation (success)
no="1"
create_text "${no}. Creating" "success"\
	"username,firstname,lastname,email" \
       	"${new},${new},${new},${new}@mail.com"
php uploaduser.php --mode=createnew --file=${test_file} ${debuglevel}


# Deletion (success)
((no++))
create_text "${no}. Deleting" "success" \
	"username,firstname,lastname,email,deleted" \
       	"${new},${new},${new},${new}@mail.com,1"
php uploaduser.php --mode=createnew --file=${test_file} ${debuglevel} --allowdeletes


# Deletion (failure - deletes not allowed)
((no++))
create_aux "username,firstname,lastname,email" \
       	"${new},${new},${new},${new}@mail.com"
php uploaduser.php --mode=createnew --file=${test_file}
make_pristine

create_text "${no}. Deleting" "failure, deletes not allowed"\
	"username,firstname,lastname,email,deleted" \
       	"${new},${new},${new},${new}@mail.com,1"
php uploaduser.php --mode=createnew --file=${test_file} ${debuglevel}


# Deletion (failure - user does not exist)
((no++))
create_aux "username,firstname,lastname,email,deleted" \
       	"${new},${new},${new},${new}@mail.com,1"
php uploaduser.php --mode=createnew --file=${test_file} --allowdeletes
make_pristine

create_text "${no}. Deleting" "failure - user does not exist"\
	"username,firstname,lastname,email,deleted" \
       	"${new},${new},${new},${new}@mail.com,1"
php uploaduser.php --mode=createnew --file=${test_file} ${debuglevel} --allowdeletes


# Deletion (failure - user admin)
((no++))
create_text "${no}. Deleting" "failure - user admin"\
	"username,firstname,lastname,email,deleted" \
       	"admin,${new},${new},${new}@mail.com,1"
php uploaduser.php --mode=createnew --file=${test_file} ${debuglevel} --allowdeletes


# Deletion (failure - user guest)
((no++))
create_text "${no}. Deleting" "failure - user guest"\
	"username,firstname,lastname,email,deleted" \
       	"guest,${new},${new},${new}@mail.com,1"
php uploaduser.php --mode=createnew --file=${test_file} ${debuglevel} --allowdeletes


# Creation (failure - mnethostid invalid)
((no++))
create_text "${no}. Creation" "failure - mnethostid invalid"\
	"username,mnethostid" \
       	"${new},a"
php uploaduser.php --mode=createnew --file=${test_file} ${debuglevel}


# Creation (failure - invalid id)
((no++))
create_text "${no}. Creating" "failure - invalid id"\
	"username,firstname,lastname,email,id" \
       	"guest,${new},${new},${new}@mail.com,a"
php uploaduser.php --mode=createnew --file=${test_file} ${debuglevel} --allowdeletes


# Creation (failure - missing fields)
((no++))
create_text "${no}. Creating" "failure - missing fields"\
	"username,firstname,lastname" \
       	"guest,${new},${new}"
php uploaduser.php --mode=createnew --file=${test_file} ${debuglevel} --allowdeletes


# Creation (failure - user exists)
((no++))
create_aux "username,firstname,lastname,email" \
       	"${new},${new},${new},${new}@mail.com"
php uploaduser.php --mode=createnew --file=${test_file}
make_pristine

create_text "${no}. Creation" "failure - user exists"\
	"username,firstname,lastname,email" \
       	"${new},${new},${new},${new}@mail.com"
php uploaduser.php --mode=createnew --file=${test_file} ${debuglevel}

create_aux "username,firstname,lastname,email,deleted" \
       	"${new},${new},${new},${new}@mail.com,1"
php uploaduser.php --mode=createnew --file=${test_file} --allowdeletes
make_pristine


# Updating (failure - user does not exist)
((no++))
create_aux "username,firstname,lastname,email,deleted" \
       	"${new},${new},${new},${new}@mail.com,1"
php uploaduser.php --mode=createnew --file=${test_file} --allowdeletes
make_pristine

create_text "${no}. Updating" "failure - user does not exist"\
	"username,firstname,lastname,email" \
       	"${new},${new},${new},${new}@mail.com"
php uploaduser.php --mode=update --file=${test_file} ${debuglevel}


# Renaming (failure - renaming not allowed)
create_aux "username,firstname,lastname,email" \
       	"${new},${new},${new},${new}@mail.com"
php uploaduser.php --mode=createnew --file=${test_file} --allowdeletes
make_pristine

create_text "${no}. Renaming" "failure - renaming not allowed"\
	"username,firstname,lastname,email,oldusername" \
       	"newusername,${new},${new},${new}@mail.com,${new}"
php uploaduser.php --mode=update --updatemode=dataonly --file=${test_file} ${debuglevel}

create_aux "username,firstname,lastname,email,deleted" \
       	"${new},${new},${new},${new}@mail.com,1"
php uploaduser.php --mode=createnew --file=${test_file} --allowdeletes
make_pristine


# Renaming (failure - new user exists)
((no++))
create_aux "username,firstname,lastname,email" \
       	"${new},${new},${new},${new}@mail.com"
php uploaduser.php --mode=createnew --file=${test_file} --allowdeletes
make_pristine

create_text "${no}. Renaming" "failure - new username exists"\
	"username,firstname,lastname,email,oldusername" \
       	"${new},${new},${new},${new}@mail.com,${new}"
php uploaduser.php --mode=update --file=${test_file} ${debuglevel} --allowrenames

create_aux "username,firstname,lastname,email,deleted" \
       	"${new},${new},${new},${new}@mail.com,1"
php uploaduser.php --mode=createnew --file=${test_file} --allowdeletes
make_pristine


# Renaming (failure - can not update)
((no++))
create_aux "username,firstname,lastname,email" \
       	"${new},${new},${new},${new}@mail.com"
php uploaduser.php --mode=createnew --file=${test_file} --allowdeletes
make_pristine

create_text "${no}. Renaming" "failure - can not uptdate"\
	"username,firstname,lastname,email,oldusername" \
       	"newusername,${new},${new},${new}@mail.com,${new}"
php uploaduser.php --mode=update --updatemode=nothing --file=${test_file} ${debuglevel} --allowrenames

create_aux "username,firstname,lastname,email,deleted" \
       	"${new},${new},${new},${new}@mail.com,1"
php uploaduser.php --mode=createnew --file=${test_file} --allowdeletes
make_pristine


# Renaming (failure - oldusername does not exist)
((no++))
create_aux "username,firstname,lastname,email,deleted" \
       	"${new},${new},${new},${new}@mail.com,1"
php uploaduser.php --mode=createnew --file=${test_file} --allowdeletes
make_pristine

create_text "${no}. Renaming" "failure - oldusername does not exist"\
	"username,firstname,lastname,email,oldusername" \
       	"newusername,${new},${new},${new}@mail.com,${new}"
php uploaduser.php --mode=update --updatemode=dataonly --file=${test_file} ${debuglevel} --allowrenames


# Renaming (failure - can not rename, part 2)
create_aux "username,firstname,lastname,email" \
       	"${new},${new},${new},${new}@mail.com"
php uploaduser.php --mode=createnew --file=${test_file} --allowdeletes
make_pristine

create_text "${no}. Renaming" "failure - can not rename, part 2"\
	"username,firstname,lastname,email,oldusername" \
       	"newusername,${new},${new},${new}@mail.com,${new}"
php uploaduser.php --mode=createorupdate --updatemode=dataonly --file=${test_file} ${debuglevel}

create_aux "username,firstname,lastname,email,deleted" \
       	"${new},${new},${new},${new}@mail.com,1"
php uploaduser.php --mode=createnew --file=${test_file} --allowdeletes
make_pristine


# Renaming (failure - renaming admin)
((no++))
create_text "${no}. Renaming" "failure - renaming admin"\
	"username,firstname,lastname,email,oldusername" \
       	"newusername,${new},${new},${new}@mail.com,admin"
php uploaduser.php --mode=createorupdate --updatemode=dataonly --file=${test_file} ${debuglevel} --allowrenames
make_pristine


# Renaming (failure - renaming guest)
((no++))
create_text "${no}. Renaming" "failure - renaming guest"\
	"username,firstname,lastname,email,oldusername" \
       	"newusername,${new},${new},${new}@mail.com,guest"
php uploaduser.php --mode=createorupdate --updatemode=dataonly --file=${test_file} ${debuglevel} --allowrenames
make_pristine
