<?php
include 'config.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit(); // Este bloque ees para que solo usuarios logueados puedan acceder a la vista para cambiar contraseña
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $password = trim($_POST['password']);
    $confirm  = trim($_POST['confirm_password']);

    if ($password !== $confirm) {
        $error = "Las contraseñas no coinciden";
    } else {

        $hashed = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("
            UPDATE usuarios
            SET password = ?, password_temporal = 0
            WHERE id = ?
        ");

        if ($stmt->execute([$hashed, $_SESSION['user_id']])) {
            header('Location: index.php');
            exit();
        } else {
            $error = "Error al actualizar la contraseña";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reestablecer Contraseña</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body { font-family: Arial, sans-serif; max-width: 400px; margin: 50px auto; padding: 20px; }
        .form-group { 
            margin-bottom: 15px; 
            display: flex;
            flex-direction: column;
        }
        label { display: block; margin-bottom: 5px; }
        input[type="text"], input[type="email"], input[type="password"] { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        /* 🔽 BOTÓN VERDE CON EFECTOS */
        button {
            display: block;
            width: 97%;
            margin: 0 auto;
            padding: 12px;
            background: linear-gradient(135deg, #28a745, #218838);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 16px;
            font-weight: bold;
            transition:
                background 0.3s ease,
                transform 0.2s ease,
                box-shadow 0.2s ease;
        }

        button:hover {
            background: linear-gradient(135deg, #218838, #1e7e34);
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.2);
        }

        button:active {
            transform: translateY(0);
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.2);
        }
        .error { color: red; margin-bottom: 15px; }
        .login-link { text-align: center; margin-top: 20px; }
    </style>
</head>
<body>
    <h2>Reestablecer Contraseña</h2>
    
    <?php if (isset($error)): ?>
        <div class="error"><?php echo $error; ?></div>
    <?php endif; ?>
    
    <form method="POST">
        <div class="form-group">
            <label>Nueva Contraseña:</label>
            <input type="password" name="password" required>
        </div>

        <div class="form-group">
            <label>Confirmar Contraseña:</label>
            <input type="password" name="confirm_password" required>
        </div>
        
        <button type="submit">Cambiar Contraseña</button>
    </form>
    
    <div class="login-link">
        <a href="login.php">¿Ya tienes cuenta? Inicia sesión aquí</a>
    </div>
</body>
</html>