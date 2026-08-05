<?php
session_start();

$_SESSION['carrito'] = [];

header('Location: ver_orden.php');
exit;
?>
