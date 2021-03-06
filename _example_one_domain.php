<?php

if(!defined("PHP_VERSION_ID") || PHP_VERSION_ID < 50300 || !extension_loaded('openssl') || !extension_loaded('curl')) {
    die("You need at least PHP 5.3.0 with OpenSSL and curl extension\n");
}
require 'src/Lescript.php';
require 'src/ClientInterface.php';
require 'src/Client.php';
require 'src/Base64UrlSafeEncoder.php';

// you can use any logger according to Psr\Log\LoggerInterface
class Logger { function __call($name, $arguments) { echo date('Y-m-d H:i:s')." [$name] ${arguments[0]}\n"; }}
$logger = new Logger();

try {

    $le = new Analogic\ACME\Lescript('/certificate/storage', '/var/www/test.com', $logger);
    # or without logger:
    # $le = new Analogic\ACME\Lescript('/certificate/storage', '/var/www/test.com');

    $le->contact = array('mailto:test@test.com'); // optional

    $le->initAccount();

    // Get the data to confirm the domain
    $auth = $le->authDomain('test.com');

	// Manually create a file to verify your domain
	// --------------------------------------------

	// confirm domain
    $le->signDomain();

} catch (\Exception $e) {

    $logger->error($e->getMessage());
    $logger->error($e->getTraceAsString());
}
