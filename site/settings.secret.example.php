<?php

/**
 * @file
 * Example of the secrets file: settings that are secret or differ per
 * environment. On each server, copy this to
 *   eastercon2028_secrets/settings.secret.php
 * (beside eastercon2028_drupal/ and eastercon2028_keys/, outside the webroot
 * and outside git), fill in the values, and make it readable only by the web
 * and deploy users (chmod 640). settings.php includes it last, so it can
 * override anything.
 *
 * On DDEV the database connection and hash salt come from settings.ddev.php,
 * so the file there can stay empty.
 */

$databases['default']['default'] = [
  'database' => 'CHANGE_ME',
  'username' => 'CHANGE_ME',
  'password' => 'CHANGE_ME',
  'host' => 'localhost',
  'port' => '3306',
  'driver' => 'mysql',
  'prefix' => '',
  'collation' => 'utf8mb4_general_ci',
];

// Generate with: drush php:eval "echo \Drupal\Component\Utility\Crypt::randomBytesBase64(55)"
$settings['hash_salt'] = 'CHANGE_ME';

$settings['trusted_host_patterns'] = [
  '^eastercon2028\.org$',
  '^www\.eastercon2028\.org$',
];

// Secrets that are stored in configuration are overridden here rather than
// exported. Set the real value; do not commit this file.
// $config['friendlycaptcha.settings']['api_key'] = 'CHANGE_ME';
