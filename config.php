<?php

$host = getenv('MYSQLHOST');
$port = getenv('MYSQLPORT');
$user = getenv('MYSQLUSER');
$password = getenv('MYSQLPASSWORD');
$database = getenv('MYSQLDATABASE');

if (!$host || !$port || !$user || !$database) {
    die("Erreur : les variables MySQL Railway sont manquantes.");
}

$conn = new mysqli(
    $host,
    $user,
    $password,
    $database,
    (int)$port
);

if ($conn->connect_error) {
    die("Erreur de connexion MySQL : " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

?>
