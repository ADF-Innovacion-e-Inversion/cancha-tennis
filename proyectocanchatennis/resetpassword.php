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
            font-family: 'Arial', sans-serif;
        }

        body { 
            background-color: #f4e1c3;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;}
        
        .container-box {
            background-color: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.12);
            width: 480px;;          /*controla el ancho */
            max-width: 90%;        /*responsive en pantallas chicas */
        }
            
        .container-box h2 {
            margin-bottom: 30px;
            color: #333;
            text-align: center;
        }
        
        .container-box label {
            display: block;
            margin-bottom: 8px;
            color: #555;
            font-weight: bold;
        }    

        .form-group { 
            margin-bottom: 15px; 
            display: flex;
            flex-direction: column;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #555;
            font-weight: bold;
        }

        .form-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            maxlength: 50;
        }

        .form-group input:focus {
            border-color: #a777e3;
            outline: none;
        }
  
        button {
            display: block;
            width: 97%;
            margin: 0 auto;
            padding: 12px;
            background: linear-gradient(rgba(20, 192, 140, 0.6),rgb(16, 136, 90, 0.8));
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
            opacity: 0.9;
            transform: translateY(-2px);
        }

        button:active {
            transform: translateY(0);
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.2);
        }
        .error { color: red; margin-bottom: 15px; }

        .login-link { text-align: center; margin-top: 20px; }

        .info-password {
            margin: 15px 0;
            padding: 10px 12px;
            background-color: #eef4ff;
            border-left: 4px solid #1d6cd2ff;
            color: #333;
            font-size: 14px;
            border-radius: 5px;
        }

    </style>
</head>
<body>
    <div class="container-box">
        <h2>Reestablecer Contraseña</h2>

        <?php if (isset($error)): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label>Contraseña:</label>
                <input type="password" name="password" required>
            </div>

            <div class="form-group">
                <label>Confirmar Contraseña:</label>
                <input type="password" name="confirm_password" required>
            </div>

            <div class="info-password">
                Por seguridad, en tu primer inicio de sesión se te pedirá cambiar la contraseña temporal por una contraseña propia.
            </div>

            <button type="submit">Cambiar Contraseña</button>
        </form>
    </div>
</body>
</html>