<?php
date_default_timezone_set('America/Costa_Rica');

// ConfiguraciÃ³n de la base de datos
$host = 'localhost';
$port = 3307;
$dbname = 'pizzeria';
$username = 'root';
$password = '';

function getConnection() {
    global $host, $port, $dbname, $username, $password;

    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
    $conn = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $conn->exec("SET time_zone = '-06:00'");

    return $conn;
}

