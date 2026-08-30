<?php
session_start();

if(!isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}

$orden_id = (int)($_GET['orden_id'] ?? 0);

if($orden_id > 0) {
    $_SESSION['orden_actual'] = $orden_id;
}

header('Location: menu.php');
exit;
