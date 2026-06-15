<?php


// SETUP
// Database info — set via environment variables or edit directly
$dbhost = getenv('DB_HOST') ?: 'localhost';
$dbuser = getenv('DB_USER') ?: 'root';
$dbpass = getenv('DB_PASS') ?: '';
$dbname = getenv('DB_NAME') ?: 'rddtsync';

// Base URL: empty string if serving from /, otherwise /foldername with no trailing slash
$baseurl = getenv('BASE_URL') !== false ? getenv('BASE_URL') : '';

// Your host with no trailing slash — used in password reset emails
$basehost = getenv('BASE_HOST') ?: 'http://localhost';

// API location
$apiloc = getenv('API_LOC') ?: ($basehost . '/api/');

// Pretty URLs — requires server mod_rewrite support (enabled in Docker)
$prettyurls = getenv('PRETTY_URLS') !== false ? (bool) getenv('PRETTY_URLS') : true;

// Set to true to disable new user registration
$disableRegistration = filter_var(getenv('DISABLE_REGISTRATION') ?: 'false', FILTER_VALIDATE_BOOLEAN);

// For password reset emails, using SMTP
$smtpserver = getenv('SMTP_SERVER') ?: 'smtp.gmail.com';
$smtpauth   = true;
$smtpuser   = getenv('SMTP_USER') ?: '';
$smtppass   = getenv('SMTP_PASS') ?: '';
$smtpenc    = getenv('SMTP_ENC')  ?: 'ssl';
$smtpport   = (int) (getenv('SMTP_PORT') ?: 465);

$fromemail  = getenv('FROM_EMAIL') ?: $smtpuser;





$mysql = new mysqli($dbhost, $dbuser, $dbpass, $dbname);
if($mysql->connect_errno) {
	echo "database connection failure <!-- ".$mysql->connect_error." -->";
	die;
}
// Make real_escape_string charset-aware — without this it cannot reliably
// neutralize multibyte injection payloads.
$mysql->set_charset('utf8mb4');

