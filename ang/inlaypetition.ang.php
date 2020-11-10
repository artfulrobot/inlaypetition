<?php
// This file declares an Angular module which can be autoloaded
// in CiviCRM. See also:
// \https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_angularModules/n
return [
  'js' => [
    'ang/inlaypetition.js',
    'ang/inlaypetition/*.js',
    'ang/inlaypetition/*/*.js',
  ],
  'css' => [
    'ang/inlaypetition.css',
  ],
  'partials' => [
    'ang/inlaypetition',
  ],
  'requires' => [
    'crmUi',
    'crmUtil',
    'ngRoute',
  ],
  'settings' => [],
];
