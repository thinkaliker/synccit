<?php

// Never print PHP warnings/notices into the HTTP response — they corrupt JSON/XML
// API output and trigger "headers already sent". Log them instead.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
// Send the log copy to stderr so it surfaces in `docker compose logs synccit`.
// This is a write to an existing fd, so it works under the read-only rootfs.
ini_set('error_log', '/proc/self/fd/2');
error_reporting(E_ALL);

// Catch fatal errors (parse/uncaught-exception/E_ERROR) that bypass the API's
// own xerror() path and otherwise vanish behind display_errors=0. Logs the
// precise message/file/line to stderr so a 500 leaves a breadcrumb.
register_shutdown_function(function () {
	$e = error_get_last();
	if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
		error_log(sprintf('FATAL: %s in %s:%d', $e['message'], $e['file'], $e['line']));
	}
});


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

