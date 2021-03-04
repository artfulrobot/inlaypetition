# Petition "Inlay" for adding petitions to remote websites

Add high performance petitions, configured in CiviCRM, to your separate public website.

- Please see [Inlay](https://lab.civicrm.org/extensions/inlay) for an introduction to the technology this is based on.

- Note this is currently fairly generic, but I reserve the right to bend it to my client's wishes at any point. *Inlay* provides a way to develop customised remote forms; this is one example of its use.

The extension is licensed under [AGPL-3.0](LICENSE.txt).

## Features

Uses custom field to store whether signups were new to the group or not.

php

- count special type of activity (@todo make optional)
- 3 stage UX inc socal
- optin options; add to group, if one selected.
- add activity (but prevent duplicates)
- queue + job
- send thank you msgtpl

angular

- simple fieldsets.

front end

- vue - reusable.


## Requirements

* PHP v7.3+
* CiviCRM 5.31+

## Installation (Web UI)

Learn more about installing CiviCRM extensions in the [CiviCRM Sysadmin Guide](https://docs.civicrm.org/sysadmin/en/latest/customize/extensions/).

## Installation (CLI, Zip)

Sysadmins and developers may download the `.zip` file for this extension and
install it with the command-line tool [cv](https://github.com/civicrm/cv).

```bash
cd <extension-dir>
cv dl inlaypetition@https://github.com/FIXME/inlaypetition/archive/master.zip
```

## Installation (CLI, Git)

Sysadmins and developers may clone the [Git](https://en.wikipedia.org/wiki/Git) repo for this extension and
install it with the command-line tool [cv](https://github.com/civicrm/cv).

```bash
git clone https://github.com/FIXME/inlaypetition.git
cv en inlaypetition
```

## Getting Started

Once intstalled, visit **Administer » Inlays** and create a Petition Inlay.

