<?php
include 'config.php';

/* Protección de la vista, solo los admins pueden registrar usuarios */
if (!isLoggedIn() || !isAdmin()) {
    header('Location: login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nombre   = trim($_POST['nombre']);
    $apellido = trim($_POST['apellido']);
    $rut      = trim($_POST['rut']);
    $email    = trim($_POST['email']);
    $telefono = trim($_POST['telefono']);
    $tipo     = $_POST['tipo'];
    $plan     = $_POST['plan'];

    // Limpiar RUT
    $rut_limpio = str_replace(["-", " "], "", $rut);

    if (strlen($rut_limpio) < 4) {
        $error = "RUT inválido";
    } else {

        // Quitar dígito verificador
        $rut_sin_dv = substr($rut_limpio, 0, -1);

        // Password = últimos 4 dígitos
        $password_plano = substr($rut_sin_dv, -4);
        $hashed_password = password_hash($password_plano, PASSWORD_DEFAULT);

        // Verificar RUT duplicado
        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE rut = ?");
        $stmt->execute([$rut]);

        if ($stmt->rowCount() > 0) {
            $error = "El RUT ya está registrado";
        } else {

            // Verificar email duplicado
            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
            $stmt->execute([$email]);

            if ($stmt->rowCount() > 0) {
                $error = "El correo ya está registrado";
            } else {

                // Insertar usuario
                $stmt = $pdo->prepare("
                    INSERT INTO usuarios 
                    (nombre, apellido, rut, email, telefono, password, tipo, plan, password_temporal)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
                ");

                if ($stmt->execute([
                    $nombre,
                    $apellido,
                    $rut,
                    $email,
                    $telefono,
                    $hashed_password,
                    $tipo,
                    $plan
                ])) {
                    header('Location: admin.php?registered=1');
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
            font-family: Arial, sans-serif;
            margin: 0;
            background-color: #c2ffc2;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;

            /* centra SOLO el formulario */
            min-height: calc(100vh - 120px);
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .header {
            width: 100%;
            background-color: #eeffee;
        }

        .header-inner {
            max-width: 1300px;
            margin: 0 auto;
            padding: 5px 5px;
            display: flex;
            align-items: center;
        }

        .logo-img {
            height: 90px;
            width: auto;
            max-width: 260px;
            cursor: pointer;
        }

        @media (max-width: 768px) {
            .logo-img {
                height: 40px;
            }
        }

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


        .form-group select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            background-color: #ffffff;
            cursor: pointer;
            
        }

        .form-group select:focus {
            border-color: #a777e3;
            outline: none;
        }

        /* --- MODO CELULAR --- */
        
    </style>
</head>
<body>

    <div class="header">
        <div class="header-inner">
            <a href="index.php">
                <img src="teniscanchalogo.png" alt="Sistema de Canchas" class="logo-img">
            </a>
        </div>
    </div>

    <div class="container">
        <div class="container-box">
            <h2>Registro de Socio</h2>

            <?php if (isset($error)): ?>
                <div class="error"><?php echo $error; ?></div>
            <?php endif; ?>

            <form method="POST">

                <div class="form-group">
                    <label>Nombre:</label>
                    <input type="text" name="nombre" required>
                </div>

                <div class="form-group">
                    <label>Apellido:</label>
                    <input type="text" name="apellido" required>
                </div>

                <div class="form-group">
                    <label>RUT:</label>
                    <input type="text" name="rut" id="rut" required placeholder="Sin puntos ni guión">
                </div>

                <div class="form-group">
                    <label>Email:</label>
                    <input type="email" name="email" required placeholder="Ej: usuario@gmail.com">
                </div>

                <div class="form-group">
                    <label>Teléfono:</label>
                    <input type="number" name="telefono" required placeholder="Ej: 9XXXXXXXX">
                </div>

                <div class="form-group">
                    <label>Tipo de Usuario:</label>
                    <select name="tipo" required>
                        <option value="socio" selected>Socio</option>
                        <option value="admin">Administrador</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Plan:</label>
                    <select name="plan" required>
                        <option value="Individual" selected>Individual</option>
                        <option value="Familiar">Familiar</option>
                    </select>
                </div>

                <button type="submit">Registrar</button>
            </form>

        </div>
    </div>

    


<!-- Funcion para rellenar el guión automaticamente -->
<script>
document.getElementById("rut").addEventListener("input", function (e) {
    let value = e.target.value;

    // Quitar todo excepto números y K/k
    value = value.replace(/[^0-9kK]/g, "");

    // Si tiene más de 1 carácter, agrega el guión antes del último
    if (value.length > 1) {
        const cuerpo = value.slice(0, -1);
        const dv = value.slice(-1);
        value = cuerpo + "-" + dv;
    }

    e.target.value = value;
});
</script>    
</body>
</html>