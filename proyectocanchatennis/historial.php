<?php
include 'config.php';

if (!isLoggedIn() || !isAdmin()) {
    header('Location: login.php');
    exit();
}

// ✅ Cancelar reserva desde historial
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["cancelar_reserva_historial"])) {
    $reserva_id = (int)($_POST["reserva_id"] ?? 0);

    if ($reserva_id > 0) {
        $stmt = $pdo->prepare("
            UPDATE reservas
            SET estado = 'cancelada'
            WHERE id = ?
              AND estado = 'confirmada'
        ");
        if ($stmt->execute([$reserva_id])) {
            // Si quieres borrar el comprobante al cancelar (igual que admin)
            if (function_exists("eliminarComprobanteDeReserva")) {
                eliminarComprobanteDeReserva($reserva_id);
            }
        }
    }

    header("Location: historial.php?success=2");
    exit();
}

// Obtener canchas
$canchas = $pdo->query("SELECT * FROM canchas")->fetchAll(PDO::FETCH_ASSOC);

// Obtener reservas activas
$stmt = $pdo->prepare("
    SELECT r.*, 
           c.nombre AS cancha_nombre, 
           u.nombre AS usuario_nombre,
           u.apellido AS usuario_apellido
    FROM reservas r
    JOIN canchas c ON r.cancha_id = c.id
    JOIN usuarios u ON r.usuario_id = u.id
    WHERE r.estado = 'confirmada'
      AND r.fecha >= CURDATE()
    ORDER BY r.fecha, r.hora
");
$stmt->execute();
$reservas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener historial de reservas (todas las confirmadas, sin importar fecha)
$stmt = $pdo->prepare("
    SELECT r.*, 
           c.nombre AS cancha_nombre, 
           u.nombre AS usuario_nombre,
           u.apellido AS usuario_apellido
    FROM reservas r
    JOIN canchas c ON r.cancha_id = c.id
    JOIN usuarios u ON r.usuario_id = u.id
    WHERE r.estado = 'confirmada'
    ORDER BY r.fecha DESC, r.hora DESC
");
$stmt->execute();
$reservasHistorial = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Administración</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0 auto;  background-color: #c2ffc2;}
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        .header { width: 100%; background-color: #eeffee;}
        .header-inner {
            max-width: 1300px;
            margin: 0 auto;
            padding: 5px 5px; 
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .section { margin-bottom: 30px; width: 100%; overflow-x: auto;}
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; background-color: #f5f5f5;}
        th, td { border: 1px solid #7f7f7f; padding: 10px; text-align: center; }
        th { background: #f8f9fa; }
        .success { background: #d4edda; color: #155724; padding: 10px; border-radius: 4px; margin-bottom: 15px; }
        .form-inline { display: inline; }
        .btn { padding: 5px 10px; border: none; border-radius: 3px; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-danger:hover {opacity: 0.5;}
        .btn-primary { background: #007bff; color: white; }
        .btn-primary:hover {opacity: 0.5;}

        .logo-link {
            display: flex;
            align-items: center;
            text-decoration: none;
        }

        .logo-img {
            height: 90px;       
            width: auto;
            max-width: 260px;
        }

        .btn-sistema {
            background-color: #007bff;   
            color: #ffffff;
            border: none;
            padding: 8px 14px;
            font-size: 15px;
            font-weight: bold;
            border-radius: 6px;
            cursor: pointer;
        }

        .btn-sistema:hover {
            background-color: #86c1ff;
            color: #000000;
        }

        .btn-admin {
            background-color: #007bff;   
            color: #ffffff;
            border: none;
            padding: 8px 14px;
            font-size: 15px;
            font-weight: bold;
            border-radius: 6px;
            cursor: pointer;
        }

        .btn-admin:hover {
            background-color: #86c1ff;
            color: #000000;
        }

        .logout-btn:hover{background-color: #ffacaa; color: #000000;}
        .logout-btn {
            background-color: #dc3545;   
            color: #ffffff;              
            border: none;
            padding: 8px 14px;
            font-size: 15px;
            font-weight: bold;
            border-radius: 6px;
            cursor: pointer;
        }
        .Bienvenida {
            font-size: 18px;
        }

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
            font-weight: bold;
            max-width: 200px;       
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .ventana-flotante {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.55);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }

        .ventana-flotante-contenido {
            background: #ffffff;
            padding: 25px 30px;
            border-radius: 10px;
            width: 360px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0,0,0,0.25);
        }

        .ventana-flotante-acciones {
            display: flex;
            justify-content: center;
            gap: 15px;
        }

        @media (max-width: 768px) {
            .form-inline {
                display: flex;
                flex-direction: column;
                gap: 8px; 
            }
        }

        /* --- MODO CELULAR --- */
        @media (max-width: 768px) {
            .nav-menu {
                display: none;
                position: absolute;
                top: 40px;
                right: 0;
                background: #eeffee;
                border: 1px solid #7dfe7d;
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

            .ventana-flotante { 
                padding-left: 16px; 
                padding-right: 16px; 
                box-sizing: border-box; 
            }

            .ventana-flotante-contenido { 
                width: 100%; 
                max-width: 340px; 
                box-sizing: border-box; 
            }
        }

        /* --- MODO CELULAR --- */
        @media (max-width: 768px) {
            .logo-img {
                height: 40px;   
                max-width: 180px;
            }
        }

    </style>

<link rel="icon" href="teniscanchalogo.png" type="image/png">    
</head>
<body>
    <div class="header">
        <div class="header-inner">

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
                    <form action="admin.php" method="get">
                        <button type="submit" class="btn-admin">
                            Administración
                        </button>
                    </form>
                    <form action="register.php" method="get">
                        <button type="submit" class="btn-sistema">
                            Ir al Sistema
                        </button>
                    </form>
                    <form action="logout.php" method="post">
                        <button type="submit" class="logout-btn">
                            Cerrar Sesión
                        </button>
                    </form>
                </div>
            </div>

        </div>
    </div>

    <div class="container">

        <div class="section">
            <h2>Historial de Reservas</h2>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nombre</th>
                        <th>Apellido</th>
                        <th>Cancha</th>
                        <th>Fecha</th>
                        <th>Hora</th>
                        <th>Fecha Reserva</th>
                        <th>Comprobante</th>
                        <th>Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($reservasHistorial) === 0): ?>
                        <tr>
                            <td colspan="9">No hay reservas registradas.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($reservasHistorial as $reserva): ?>
                            <tr>
                                <td><?php echo $reserva['id']; ?></td>
                                <td><?php echo htmlspecialchars($reserva['usuario_nombre']); ?></td>
                                <td><?php echo htmlspecialchars($reserva['usuario_apellido']); ?></td>
                                <td><?php echo htmlspecialchars($reserva['cancha_nombre']); ?></td>
                                <td><?php echo date('d/m/Y', strtotime($reserva['fecha'])); ?></td>
                                <td><?php echo date('H:i', strtotime($reserva['hora'])); ?></td>
                                <td><?php echo date('d/m/Y H:i', strtotime($reserva['fecha_reserva'])); ?></td>
                                <td>
                                    <?php
                                    $reservaId = (int)$reserva["id"];
                                    $baseDir = __DIR__ . "/comprobantes/";
                                    $baseUrl = "comprobantes/";

                                    $candidatos = [
                                        $baseDir . "reserva_" . $reservaId . ".jpg",
                                        $baseDir . "reserva_" . $reservaId . ".png",
                                        $baseDir . "reserva_" . $reservaId . ".webp",
                                        $baseDir . "reserva_" . $reservaId . ".jpeg"
                                    ];

                                    $archivoEncontrado = "";
                                    foreach ($candidatos as $path) {
                                        if (file_exists($path)) {
                                            $archivoEncontrado = basename($path);
                                            break;
                                        }
                                    }

                                    if ($archivoEncontrado !== "") {
                                        echo "<a class=\"btn btn-primary\" href=\"" . $baseUrl . htmlspecialchars($archivoEncontrado) . "\" target=\"_blank\">Ver</a>";
                                    } else {
                                        echo "—";
                                    }
                                    ?>
                                </td>
                                <td>
                                    <form method="POST" class="form-inline">
                                        <input type="hidden" name="reserva_id" value="<?php echo (int)$reserva["id"]; ?>">
                                        <button type="submit" name="cancelar_reserva_historial" class="btn btn-danger"
                                                onclick="return confirm('¿Estás seguro de cancelar esta reserva?')">
                                            Cancelar
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    

<script>
function toggleMenu() {
    document.getElementById("navMenu").classList.toggle("show");
}

function abrirModalActividad() {
    document.getElementById("modalActividad").style.display = "flex";
}

function cerrarModalActividad() {
    document.getElementById("modalActividad").style.display = "none";
}

</script>

</body>
</html>