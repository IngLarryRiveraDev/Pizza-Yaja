<?php
header('Content-Type: text/html; charset=UTF-8');
session_start();

// Si ya estÃ¡ logueado, redirigir segÃºn su rol
if(isset($_SESSION['usuario_id'])) {
    if($_SESSION['rol'] == 'admin') {
        header('Location: admin.php');
    } elseif($_SESSION['rol'] == 'cocina') {
        header('Location: cocina.php');
    } else {
        header('Location: ordenes_activas.php');
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Pizza Yaja</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: Arial, sans-serif;
            background: #1a1a1a;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .login-container {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0,0,0,0.3);
            width: 100%;
            max-width: 400px;
        }
        h1 {
            text-align: center;
            color: #ff9800;
            margin-bottom: 30px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            color: #333;
            font-weight: bold;
        }
        input {
            width: 100%;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
        }
        input:focus {
            outline: none;
            border-color: #ff9800;
        }
        button {
            width: 100%;
            padding: 15px;
            background: #ff9800;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 18px;
            font-weight: bold;
            cursor: pointer;
        }
        button:hover {
            background: #e68900;
        }
        .error {
            background: #ffebee;
            color: #c62828;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h1>🍕 Pizza Yaja</h1>
        
        <?php if(isset($_GET['error'])): ?>
            <div class="error">
                Usuario o contraseña incorrectos
            </div>
        <?php endif; ?>
        
        <form method="POST" action="login_process.php">
            <div class="form-group">
                <label>Usuario:</label>
                <input type="text" name="usuario" required autofocus>
            </div>
            
            <div class="form-group">
                <label>Contraseña:</label>
                <input type="password" name="contrasena" required>
            </div>
            
            <button type="submit">Iniciar Sesión</button>
        </form>
    </div>
</body>
</html>
