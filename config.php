<?php

$mysql_url = getenv('MYSQL_URL');

if (!$mysql_url) {
    die("Erreur : MYSQL_URL n'est pas disponible sur Railway.");
}

$url = parse_url($mysql_url);

$host = $url['host'] ?? '';
$port = isset($url['port']) ? (int)$url['port'] : 3306;
$user = $url['user'] ?? '';
$password = $url['pass'] ?? '';
$database = isset($url['path']) ? ltrim($url['path'], '/') : '';

$conn = new mysqli(
    $host,
    $user,
    $password,
    $database,
    $port
);

if ($conn->connect_error) {
    die("Erreur de connexion à MySQL : " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

?>
