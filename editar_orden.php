<?php
session_start();

$orden_id = $_GET['orden_id'] ?? 0;

if($orden_id > 0) {
    $_SESSION['orden_actual'] = $orden_id;
}

header('Location: menu.php');
exit;
?>
