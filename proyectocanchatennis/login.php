<?php
include 'config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nombre = trim($_POST['nombre']); // Ahora se loguean con el RUT
    $password = trim($_POST['password']);
    
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE nombre = ?");
    $stmt->execute([$nombre]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['nombre'];
        $_SESSION['user_type'] = $user['tipo'];
        
        // Login Admin
        if ($user['tipo'] === 'admin') {
            header('Location: admin.php');
        } else {
           // Forzar cambio de contraseña si es temporal
            if ($user['password_temporal'] == 1) {
                header('Location: resetpassword.php');
                exit();
            } else {
                header('Location: index.php');
            }
            exit(); 
        }      
        
    } else {
        $error = "Usuario o contraseña incorrectos";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inicio de Sesión</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Arial', sans-serif;
        }
        
        body {
            background-color: #f4e1c3;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        
        .container {
            display: flex;
            width: 900px;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.1);
            border-radius: 10px;
            overflow: hidden;
        }
        
        .info-box {
            flex: 1;
            background: linear-gradient(rgba(20, 192, 140, 0.6),rgba(16, 136, 90, 0.8)); 
            background-color: #f5f5f5;
            color: white;
            padding: 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background-image: url('teniscanchalogo.png');
            background-repeat: no-repeat; 
            background-position: center;
            
        }
        
        .info-box h2 {
            margin-bottom: 20px;
            font-size: 28px;
        }
        
        .info-box p {
            margin-bottom: 15px;
            line-height: 1.6;
        }
        
        .login-box {
            flex: 1;
            background-color: white;
            padding: 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
       
        }
        
        .login-box h2 {
            margin-bottom: 30px;
            color: #333;
            text-align: center;
        }
        
        .form-group {
            margin-bottom: 20px;
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
        
        .btn {
            width: 100%;
            padding: 12px;
            background: linear-gradient(rgba(20, 192, 140, 0.6),rgb(16, 136, 90, 0.8));
            border: none;
            color: white;
            font-size: 16px;
            font-weight: bold;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn:hover {
            opacity: 0.9;
            transform: translateY(-2px);
        }

        /* Estilos para el mensaje de error */
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
            font-size: 14px;
            display: flex;
            align-items: center;
            animation: fadeIn 0.5s ease-in-out;
        }
        
        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .alert-icon {
            margin-right: 10px;
            font-size: 20px;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Efecto de shake cuando hay error */
        .shake {
            animation: shake 0.5s;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
            20%, 40%, 60%, 80% { transform: translateX(5px); }

        }

        .register-link {
            margin-top: 20px;   /* ajusta el valor a gusto */
            text-align: center;
        }

        .register-link:hover{
            opacity: 0.5;
        }

        .register-link a {
            color: #1d6cd2ff
        }

        .register-link a:visited {
            color: #1d6cd2ff
        }
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
    <div class="container">
        <!-- Rectángulo de información -->
        <div class="info-box">
            <!-- <h2>Bienvenido a Project</h2>
            <p> <p> -->
            <!-- <p>Accede a tu cuenta para gestionar todos los servicios disponibles.</p>
            <p>Características principales:</p>
            <ul style="margin-left: 20px; margin-bottom: 15px;">
                <li>Gestion de proyectos profesionales</li>
                <li>Herramientas de uso simple</li>
                <li>Seguridad garantizada</li>
            </ul> -->
        </div>
        
        <!-- Rectángulo de login -->
        <div class="login-box">
            <h2>Inicio de Sesión</h2>
            <!-- <link rel="img" href="teniscanchalogo.png"> -->

            <?php if(!empty($error)): ?>
                <div class="alert alert-danger">
                    <span class="alert-icon">⚠️</span>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="login.php">
                <div class="form-group">
                    <label for="login-nombre">Usuario (RUT)</label>
                    <input type="text" id="login-nombre" name="nombre" placeholder="Ej: 12345678-9" required>
                </div>
                <div class="form-group">
                    <label for="login-password">Contraseña</label>
                    <input type="password" id="login-password" name="password" placeholder="Ingresa tu contraseña" required maxlength="50">
                </div>

                <div class="info-password">
                    Por seguridad, en tu primer inicio de sesión se te pedirá cambiar la contraseña temporal por una contraseña propia.
                </div>

                <button type="submit" class="btn">Iniciar Sesión</button>
            </form>

            <div class="register-link">
                <a href="register.php">¿No tienes una cuenta? Crea una aquí</a>
            </div>
        </div>
    </div>
</body>
<!-- <head>
    <link rel="stylesheet" href="carpetacss/login.css">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Reserva de Canchas</title>
     -->
<!-- </head> -->
<!-- <body>
    <h2 id="titulo1">Iniciar Sesión</h2>
    
    <?php if (isset($error)): ?>
        <div class="error"><?php echo $error; ?></div>
    <?php endif; ?>
    
    <form method="POST">
        <div class="form-group">
            <label>Email:</label>
            <input type="email" name="email" required>
        </div>
        
        <div class="form-group">
            <label>Contraseña:</label>
            <input type="password" name="password" required>
        </div>
        
        <button type="submit", class="login-btn">Ingresar</button>
    </form>
    
     <div class="register-link">
        <a href="register.php">¿No tienes cuenta? Regístrate aquí</a>
    </div> -->
<!-- </body> --> 
</html>