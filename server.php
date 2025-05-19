<?php

use Saraf\JsonSnitchServer;

require "vendor/autoload.php";

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

if ($_ENV['SNITCH_PASSWORD_REQUIRED'] === 'true') {
    $username = $_ENV['SNITCH_USERNAME'];
    $password = $_ENV['SNITCH_PASSWORD'];

    if (strlen($username) == 0 || strlen($password) == 0) {
        echo "SNITCH_USERNAME and SNITCH_PASSWORD is required for authentication";
        exit(1);
    }
} else {
    $username = null;
    $password = null;
}

$server = new JsonSnitchServer($username, $password);

echo "Start Running on http://0.0.0.0:9898" . PHP_EOL;
$server->start("0.0.0.0", 9898);