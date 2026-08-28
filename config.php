<?php

// Connexion MySQL Railway avec MYSQL_URL
$mysql_url = getenv('MYSQL_URL');

if (!$mysql_url) {
    die("Erreur : MYSQL_URL n'est pas disponible.");
}

// Analyse de l'URL MySQL
$url = parse_url($mysql_url);

$host = $url['host'] ?? '';
$port = $url['port'] ?? 3306;
$user = $url['user'] ?? '';
$password = $url['pass'] ?? '';
$database = isset($url['path']) ? ltrim($url['path'], '/') : '';

// Vérification
if (!$host || !$user || !$database) {
    die("Erreur : les informations MySQL sont incomplètes.");
}

// Connexion
$conn = new mysqli(
    $host,
    $user,
    $password,
    $database,
    (int)$port
);

// Vérification de la connexion
if ($conn->connect_error) {
    die("Erreur de connexion MySQL : " . $conn->connect_error);
}

// Encodage UTF-8
$conn->set_charset("utf8mb4");

?>
