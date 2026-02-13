<?php
include 'config.php';
require_once __DIR__ . "/mail_reserva.php";

if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$error = null;

// Obtener reservas del usuario
$stmt = $pdo->prepare("
    SELECT r.*, c.nombre as cancha_nombre 
    FROM reservas r 
    JOIN canchas c ON r.cancha_id = c.id 
    WHERE r.usuario_id = ?
      AND r.estado IN ('confirmada','pendiente')
      AND r.fecha >= CURDATE()
    ORDER BY r.fecha, r.hora, r.cancha_id
");
$stmt->execute([$_SESSION['user_id']]);
$reservas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Cancelar reserva (solo si faltan 3 horas o más)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["cancelar_reserva"])) {
    $reserva_id = (int)($_POST["reserva_id"] ?? 0);

    $stmt = $pdo->prepare("
        SELECT fecha, hora, cancha_id
        FROM reservas
        WHERE id = ?
        AND usuario_id = ?
        AND estado IN ('confirmada','pendiente')
        LIMIT 1
    ");

    $stmt->execute([$reserva_id, $_SESSION["user_id"]]);
    $reserva = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reserva) {
        $error = "No se encontró la reserva o ya no está activa.";
    } else {
        // 2) Validar 3 horas de anticipación
        $inicioReserva = new DateTime($reserva["fecha"] . " " . $reserva["hora"]);
        $ahora = new DateTime();

        $segundosRestantes = $inicioReserva->getTimestamp() - $ahora->getTimestamp();

        if ($segundosRestantes <= 0) {
            $error = "No puedes cancelar una reserva que ya comenzó o ya pasó.";
        } elseif ($segundosRestantes < 10800) {
            $error = "Solo puedes cancelar con un mínimo de 3 horas de anticipación.";
        } else {
            // 3) Cancelar
            $stmt = $pdo->prepare("
                UPDATE reservas
                SET estado = 'cancelada'
                WHERE id = ?
                  AND usuario_id = ?
                  AND estado IN ('confirmada','pendiente')
            ");

            if ($stmt->execute([$reserva_id, $_SESSION["user_id"]])) {

                // ✅ Eliminar comprobante del disco
                eliminarComprobanteDeReserva($reserva_id);

                $stmtU = $pdo->prepare("SELECT nombre, email FROM usuarios WHERE id = ?");
                $stmtU->execute([$_SESSION["user_id"]]);
                $u = $stmtU->fetch(PDO::FETCH_ASSOC);

                $stmtC = $pdo->prepare("SELECT nombre FROM canchas WHERE id = ?");
                $stmtC->execute([(int)$reserva["cancha_id"]]);
                $canchaNombre = $stmtC->fetchColumn();

                if ($u && $canchaNombre) {
                    enviarEmailReserva($u["email"], $u["nombre"], [
                        "estado" => "cancelada",
                        "fecha" => date("d/m/Y", strtotime($reserva["fecha"])),
                        "hora" => date("H:i", strtotime($reserva["hora"])),
                        "canchaNombre" => $canchaNombre
                    ]);
                }

                header("Location: mis_reservas.php?success=1");
                exit();
            } else {
                $error = "No se pudo cancelar la reserva.";
            }
        }
    }

    // 4) Recargar tabla (si hubo error), manteniendo el filtro desde hoy
    $stmt = $pdo->prepare("
        SELECT r.*, c.nombre as cancha_nombre
        FROM reservas r
        JOIN canchas c ON r.cancha_id = c.id
        WHERE r.usuario_id = ?
          AND r.estado IN ('confirmada','pendiente')
          AND r.fecha >= CURDATE()
        ORDER BY r.fecha, r.hora, r.cancha_id
    ");
    $stmt->execute([$_SESSION["user_id"]]);
    $reservas = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Reservas</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0 auto;  background-color: #c2ffc2;}
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {width: 100%; background-color: #eeffee;}
        .header-inner {
            max-width: 1300px;
            margin: 0 auto;
            padding: 5px 5px; 
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        table { width: 100%; border-collapse: collapse; background-color: #f5f5f5;}
        th, td { border: 1px solid #7f7f7f; padding: 10px; text-align: center; }
        th { background: #f8f9fa; }
        .success { background: #d4edda; color: #155724; padding: 10px; border-radius: 4px; margin-bottom: 15px; }
        .error { background: #f8d7da; color: #721c24; padding: 10px; border-radius: 4px; margin-bottom: 15px; }
        .btn { padding: 5px 10px; border: none; border-radius: 3px; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-primary { background: #007bff; color: white; }
        .btn-primary:hover {opacity: 0.5;}


        
        .section {
            margin-bottom: 30px;
            width: 100%;
            overflow-x: auto;
        }
        .logo-link {
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
        .btn-volver {
            background-color: #007bff;
            color: #ffffff;
            border: none;
            padding: 8px 14px;
            font-size: 15px;
            font-weight: bold;
            border-radius: 6px;
            cursor: pointer;
        }

        .btn-volver:hover {
            background-color: #86c1ff;
            color: #000000;
        }

        .vacio {
            font-size: 20px;
            font-weight: bold;
        }

        .nav-container {
            display: flex;
            align-items: center;
            gap: 12px;
            position: relative;
        }

        .nav-menu {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .hamburger {
            display: none;
            font-size: 26px;
            background: none;
            border: none;
            cursor: pointer;
        }

        /* Botón cerrar sesión */
        .logout-btn:hover {background-color: #ffacaa; color: #000000;}
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
            font-size: 14px;
            font-weight: bold;
            max-width: 200px;       
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
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
        }
    </style>

<link rel="icon" href="teniscanchalogo.png" type="image/png">    
</head>
<body>
    <div class="header">
        <div class="header-inner">
            <a href="index.php" class="logo-link">
                <img src="teniscanchalogo.png" alt="Mis Reservas" class="logo-img">
            </a>

            <div class="nav-container">
                <span class="Bienvenida">
                    Bienvenido, <?php echo $_SESSION['user_name']; ?>
                </span>

                <!-- Botón hamburguesa -->
                <button class="hamburger" onclick="toggleMenu()">☰</button>

                <!-- Menú -->
                <div id="navMenu" class="nav-menu">
                    <form action="index.php" method="get">
                        <button type="submit" class="btn-volver">Volver</button>
                    </form>

                    <form action="logout.php" method="post">
                        <button type="submit" class="logout-btn">Cerrar Sesión</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <?php if (isset($_GET['success'])): ?>
            <div class="success">Reserva cancelada correctamente</div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if (count($reservas) > 0): ?>
            <div class="section">
            <h2>Mis Reservas Activas</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Cancha</th>
                            <th>Fecha</th>
                            <th>Hora</th>
                            <th>Fecha de Reserva</th>
                            <th>Comprobante</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reservas as $reserva): ?>
                            <?php $bloque = obtenerBloqueHora($reserva["hora"]);    //Aca se usa una funcion helper para obtener la hora de inicio y de fin para mostrar al cliente ?>
                            <tr>
                                <td><?php echo $reserva['cancha_nombre']; ?></td>
                                <td><?php echo date('d/m/Y', strtotime($reserva['fecha'])); ?></td>
                                <td>
                                    <?php if ($bloque): ?>
                                        <?php echo date("H:i", strtotime($bloque["inicio"])); ?>
                                        -
                                        <?php echo date("H:i", strtotime($bloque["fin"])); ?>
                                    <?php else: ?>
                                        <?php echo date("H:i", strtotime($reserva["hora"])); ?>
                                    <?php endif; ?>
                                </td>
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
                                    <?php echo ($reserva["estado"] === "pendiente") ? "Pendiente" : "Confirmada"; ?>
                                </td>
                                <td>
                                    <form method="POST">
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
            
        <?php else: ?>
            <p class="vacio">No tienes reservas activas.</p>
        <?php endif; ?>
        </div>

    


<script>//Funcion para abrir el menú de hamburguesa
function toggleMenu() {
    document.getElementById("navMenu").classList.toggle("show");
}
</script>    
</body>
</html>