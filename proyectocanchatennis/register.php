<?php
include 'config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nombre = trim($_POST['nombre']);
    $email = trim($_POST['email']);
    
    // Validaciones
    $rut_limpio = str_replace(["-", " "], "", $nombre);

    // Validación de la longitud mínima del rut
    if (strlen($rut_limpio) < 4) {
        $error = "RUT inválido";
    } else {

        // Quitar dígito verificador
        $rut_sin_dv = substr($rut_limpio, 0, -1);

        // Contraseña automática: Los últimos 4 dígitos del RUT
        $password_plano = substr($rut_sin_dv, -4);
        $hashed_password = password_hash($password_plano, PASSWORD_DEFAULT);

        // Verificar si el RUT ya existe
        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE nombre = ?");
        $stmt->execute([$nombre]);

        if ($stmt->rowCount() > 0) {
            $error = "El RUT ya está registrado";
        } else {
            // Verificar si el correo ya existe
            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
            $stmt->execute([$email]);

            if ($stmt->rowCount() > 0) {
                $error = "El correo ya está registrado";
            } else {
                // Crear usuario
                $stmt = $pdo->prepare("
                    INSERT INTO usuarios (nombre, email, password, tipo, password_temporal)
                    VALUES (?, ?, ?, 'socio', 1)
                ");

                if ($stmt->execute([$nombre, $email, $hashed_password])) {
                    header('Location: login.php?registered=1');
                    exit();
                } else {
                    $error = "Error al crear la cuenta";
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro - Reserva de Canchas</title>
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

        .login-link { 
            text-align: center; 
            margin-top: 20px;
        }

        .login-link:hover {
            opacity: 0.5;
        } 

        .login-link a {
            color: #1d6cd2ff
        }

        .login-link a:visited {
            color: #1d6cd2ff
        }

    </style>
</head>
<body>
    <div class="container-box">
        <h2>Registro de Socio</h2>

        <?php if (isset($error)): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label>Nombre usuario:</label>
                <input type="text" name="nombre" required placeholder="Ej: 12345678-9">
            </div>
            
            <div class="form-group">
                <label>Email:</label>
                <input type="email" name="email" required placeholder="Ej: usuario@gmail.com">
            </div>
            
            <button type="submit">Registrarse</button>
        </form>

        <div class="login-link">
            <a href="login.php">¿Ya tienes una cuenta? Inicia sesión aquí</a>
        </div>
    </div>
</body>
</html>