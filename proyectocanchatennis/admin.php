<?php
include 'config.php';

if (!isLoggedIn() || !isAdmin()) {
    header('Location: login.php');
    exit();
}

// Procesar cambio de estado de cancha
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['cambiar_estado'])) {
    $cancha_id = $_POST['cancha_id'];
    $estado = $_POST['estado'];
    
    $stmt = $pdo->prepare("UPDATE canchas SET estado = ? WHERE id = ?");
    $stmt->execute([$estado, $cancha_id]);
    header('Location: admin.php?success=1');
    exit();
}

// Procesar cancelación de reserva
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['cancelar_reserva'])) {
    $reserva_id = $_POST['reserva_id'];
    
    $stmt = $pdo->prepare("UPDATE reservas SET estado = 'cancelada' WHERE id = ?");
    $stmt->execute([$reserva_id]);
    header('Location: admin.php?success=2');
    exit();
}

// Obtener canchas
$canchas = $pdo->query("SELECT * FROM canchas")->fetchAll(PDO::FETCH_ASSOC);

// Obtener reservas activas
$reservas = $pdo->query("
    SELECT r.*, c.nombre as cancha_nombre, u.nombre as usuario_nombre, u.email 
    FROM reservas r 
    JOIN canchas c ON r.cancha_id = c.id 
    JOIN usuarios u ON r.usuario_id = u.id 
    WHERE r.estado = 'confirmada' 
    ORDER BY r.fecha, r.hora
")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Administración</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 1200px; margin: 0 auto; padding: 20px; background-color: #fbeedbff;}
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; width: 100%;}
        .section { margin-bottom: 30px; width: 100%; overflow-x: auto;}
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; background-color: #f5f5f5;}
        th, td { border: 1px solid #ddd; padding: 10px; text-align: center; }
        th { background: #f8f9fa; }
        .success { background: #d4edda; color: #155724; padding: 10px; border-radius: 4px; margin-bottom: 15px; }
        .form-inline { display: inline; }
        .btn { padding: 5px 10px; border: none; border-radius: 3px; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-primary { background: #007bff; color: white; }
        
        .logo-link {
            display: flex;
            align-items: center;
            text-decoration: none;
        }

        .logo-img {
            height: 90px;       /* tamaño PC */
            width: auto;
            max-width: 260px;
        }

        .btn-sistema {
            background-color: #007bff;   /* azul */
            color: #ffffff;
            border: none;
            padding: 8px 14px;
            font-size: 15px;
            font-weight: bold;
            border-radius: 6px;
            cursor: pointer;
        }

        .btn-sistema:hover {
            opacity: 0.5;
        }

        .btn-registro {
            background-color: #007bff;   /* azul */
            color: #ffffff;
            border: none;
            padding: 8px 14px;
            font-size: 15px;
            font-weight: bold;
            border-radius: 6px;
            cursor: pointer;
        }

        .btn-registro:hover {
            opacity: 0.5;
        }

        .logout-btn:hover{opacity: 0.5;}
        .logout-btn {
            background-color: #dc3545;   /* rojo */
            color: #ffffff;              /* texto blanco */
            border: none;
            padding: 8px 14px;
            font-size: 15px;
            font-weight: bold;
            border-radius: 6px;
            cursor: pointer;
        }
        .Main-link:hover{opacity: 0.5;}
        .Main-link {
            color: #1d6cd2ff;
            font-size: 18px;
            font-weight: bold;
        }
        .Main-link:visited {color: #1d6cd2ff;}
        .Bienvenida {
            font-size: 18px;
        }
        .Register-link:hover{opacity: 0.5;}
        .Register-link {
            color: #1d6cd2ff;
            font-size: 18px;
            font-weight: bold;
        }
        .Register-link:visited {color: #1d6cd2ff;}

        .nav-container {
            display: flex;
            align-items: center;
            gap: 12px;
            position: relative;
        }

        /* Menú normal */
        .nav-menu {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        /* Botón hamburguesa oculto en desktop */
        .hamburger {
            display: none;
            font-size: 26px;
            background: none;
            border: none;
            cursor: pointer;
        }

        .Bienvenida-texto {
            font-size: 14px;
            max-width: 200px;       /* límite físico */
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        @media (max-width: 768px) {
            .form-inline {
                display: flex;
                flex-direction: column;
                gap: 8px; /* ← espacio entre select y botón */
            }
        }

        /* --- MODO CELULAR --- */
        @media (max-width: 768px) {
            .nav-menu {
                display: none;
                position: absolute;
                top: 40px;
                right: 0;
                background: #fbeedbff;
                border: 1px solid #d1bfa7;
                border-radius: 8px;
                padding: 12px;
                flex-direction: column;
                gap: 10px;
                z-index: 1000;
            }

            .nav-menu.show {
                display: flex;
            }

            .hamburger {
                display: block;
            }
        }

        /* --- MODO CELULAR --- */
        @media (max-width: 768px) {
            .logo-img {
                height: 40px;   /* tamaño celular */
                max-width: 180px;
            }
        }

    </style>
</head>
<body>
    <div class="header">
        <a href="index.php" class="logo-link">
            <img src="teniscanchalogo.png" alt="Panel de Administración" class="logo-img">
        </a>
        <div class="nav-container">
        <span class="Bienvenida-texto">
            Bienvenido, <?php echo $_SESSION['user_name']; ?>
        </span>

        <!-- Botón hamburguesa -->
        <button class="hamburger" onclick="toggleMenu()">☰</button>

        <!-- Menú -->
        <div id="navMenu" class="nav-menu">
            <form action="index.php" method="get">
                <button type="submit" class="btn-sistema">
                    Ir al Sistema
                </button>
            </form>
            <form action="register.php" method="get">
                <button type="submit" class="btn-registro">
                    Registrar
                </button>
            </form>
            <form action="logout.php" method="post">
                <button type="submit" class="logout-btn">Cerrar Sesión</button>
            </form>
        </div>
</div>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="success">
            <?php 
            if ($_GET['success'] == 1) echo "Estado de cancha actualizado correctamente";
            if ($_GET['success'] == 2) echo "Reserva cancelada correctamente";
            ?>
        </div>
    <?php endif; ?>

    <div class="section">
        <h2>Gestión de Canchas</h2>
        <table>
            <thead>
                <tr>
                    <th>Cancha</th>
                    <th>Estado Actual</th>
                    <th>Cambiar Estado</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($canchas as $cancha): ?>
                    <tr>
                        <td><?php echo $cancha['nombre']; ?></td>
                        <td>
                            <?php 
                            $estados = [
                                'disponible' => '✅ Disponible',
                                'mantenimiento' => '🔧 En Mantenimiento',
                                'ocupada' => '❌ Ocupada'
                            ];
                            echo $estados[$cancha['estado']];
                            ?>
                        </td>
                        <td>
                            <form method="POST" class="form-inline">
                                <input type="hidden" name="cancha_id" value="<?php echo $cancha['id']; ?>">
                                <select name="estado">
                                    <option value="disponible" <?php echo $cancha['estado'] == 'disponible' ? 'selected' : ''; ?>>Disponible</option>
                                    <option value="mantenimiento" <?php echo $cancha['estado'] == 'mantenimiento' ? 'selected' : ''; ?>>Mantenimiento</option>
                                    <option value="ocupada" <?php echo $cancha['estado'] == 'ocupada' ? 'selected' : ''; ?>>Ocupada</option>
                                </select>
                                <button type="submit" name="cambiar_estado" class="btn btn-primary">Actualizar</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="section">
        <h2>Reservas Activas</h2>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Usuario</th>
                    <th>Email</th>
                    <th>Cancha</th>
                    <th>Fecha</th>
                    <th>Hora</th>
                    <th>Fecha Reserva</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reservas as $reserva): ?>
                    <tr>
                        <td><?php echo $reserva['id']; ?></td>
                        <td><?php echo $reserva['usuario_nombre']; ?></td>
                        <td><?php echo $reserva['email']; ?></td>
                        <td><?php echo $reserva['cancha_nombre']; ?></td>
                        <td><?php echo date('d/m/Y', strtotime($reserva['fecha'])); ?></td>
                        <td><?php echo date('H:i', strtotime($reserva['hora'])); ?></td>
                        <td><?php echo date('d/m/Y H:i', strtotime($reserva['fecha_reserva'])); ?></td>
                        <td>
                            <form method="POST" class="form-inline">
                                <input type="hidden" name="reserva_id" value="<?php echo $reserva['id']; ?>">
                                <button type="submit" name="cancelar_reserva" class="btn btn-danger" 
                                        onclick="return confirm('¿Estás seguro de cancelar esta reserva?')">
                                    Cancelar
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

<script>
function toggleMenu() {
    document.getElementById("navMenu").classList.toggle("show");
}
</script>

</body>
</html>