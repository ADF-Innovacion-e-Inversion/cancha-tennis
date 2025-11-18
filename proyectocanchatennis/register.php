<?php
include 'config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nombre = trim($_POST['nombre']);
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);
    $confirm_password = trim($_POST['confirm_password']);
    
    // Validaciones
    if ($password !== $confirm_password) {
        $error = "Las contraseñas no coinciden";
    } else {
        // Verificar si el email ya existe
        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
        $stmt->execute([$email]);
        
        if ($stmt->rowCount() > 0) {
            $error = "El email ya está registrado";
        } else {
            // Crear usuario
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO usuarios (nombre, email, password, tipo) VALUES (?, ?, ?, 'socio')");
            
            if ($stmt->execute([$nombre, $email, $hashed_password])) {
                header('Location: login.php?registered=1');
                exit();
            } else {
                $error = "Error al crear la cuenta";
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
        body { font-family: Arial, sans-serif; max-width: 400px; margin: 50px auto; padding: 20px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; }
        input[type="text"], input[type="email"], input[type="password"] { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        button { width: 100%; padding: 10px; background: #28a745; color: white; border: none; border-radius: 4px; cursor: pointer; }
        button:hover { background: #218838; }
        .error { color: red; margin-bottom: 15px; }
        .login-link { text-align: center; margin-top: 20px; }
    </style>
</head>
<body>
    <h2>Registro de Socio</h2>
    
    <?php if (isset($error)): ?>
        <div class="error"><?php echo $error; ?></div>
    <?php endif; ?>
    
    <form method="POST">
        <div class="form-group">
            <label>Nombre completo:</label>
            <input type="text" name="nombre" required>
        </div>
        
        <div class="form-group">
            <label>Email:</label>
            <input type="email" name="email" required>
        </div>
        
        <div class="form-group">
            <label>Contraseña:</label>
            <input type="password" name="password" required>
        </div>
        
        <div class="form-group">
            <label>Confirmar Contraseña:</label>
            <input type="password" name="confirm_password" required>
        </div>
        
        <button type="submit">Registrarse</button>
    </form>
    
    <div class="login-link">
        <a href="login.php">¿Ya tienes cuenta? Inicia sesión aquí</a>
    </div>
</body>
</html>