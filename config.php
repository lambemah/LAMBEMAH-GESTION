<?php

// Connexion MySQL Railway
$host = getenv('MYSQLHOST');
$user = getenv('MYSQLUSER');
$password = getenv('MYSQLPASSWORD');
$database = getenv('MYSQLDATABASE');
$port = getenv('MYSQLPORT');

// Vérification des variables
if (!$host || !$user || !$database || !$port) {
    die("Erreur : les variables MySQL Railway sont incomplètes.");
}

// Le port doit obligatoirement être un entier pour mysqli
$port = (int) $port;

// Connexion
$conn = new mysqli(
    $host,
    $user,
    $password,
    $database,
    $port
);

// Vérification de la connexion
if ($conn->connect_error) {
    die("Erreur de connexion à la base de données : " . $conn->connect_error);
}

// Encodage UTF-8
$conn->set_charset("utf8mb4");

?>
