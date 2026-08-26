<?php
date_default_timezone_set('America/Costa_Rica');

// Cargar credenciales locales si existen (desarrollo en XAMPP)
// Este archivo está en .gitignore y nunca sube a producción
if(file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
} else {
    // Producción: leer desde variables de entorno del hosting
    define('DB_HOST',     getenv('DB_HOST')     ?: 'localhost');
    define('DB_PORT',     getenv('DB_PORT')     ?: '3306');
    define('DB_NAME',     getenv('DB_NAME')     ?: 'pizzeria');
    define('DB_USER',     getenv('DB_USER')     ?: '');
    define('DB_PASS',     getenv('DB_PASS')     ?: '');
}

function getConnection() {
    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $conn = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $conn->exec("SET time_zone = '-06:00'");
    return $conn;
}
