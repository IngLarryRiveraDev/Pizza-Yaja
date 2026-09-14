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

// Llave fuera de la DB: PASS_KEY si está configurada, si no se deriva de las credenciales de la DB
function passKey() {
    $k = defined('PASS_KEY') ? PASS_KEY : (getenv('PASS_KEY') ?: DB_USER . '|' . DB_PASS . '|' . DB_NAME . '|pizza-yaja');
    return hash('sha256', $k, true);
}

function passCifrar($plano) {
    $iv = random_bytes(16);
    $cif = openssl_encrypt($plano, 'AES-256-CBC', passKey(), OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $cif);
}

function passDescifrar($guardado) {
    if(!$guardado) return null;
    $raw = base64_decode($guardado, true);
    if($raw === false || strlen($raw) < 17) return null;
    $plano = openssl_decrypt(substr($raw, 16), 'AES-256-CBC', passKey(), OPENSSL_RAW_DATA, substr($raw, 0, 16));
    return $plano === false ? null : $plano;
}
