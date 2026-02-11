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
    if ($stmt->execute([$reserva_id])) {
        // ✅ Eliminar comprobante del disco
        eliminarComprobanteDeReserva($reserva_id);
    }
    header('Location: admin.php?success=2');
    exit();
}

// Procesar creación de actividad (por rango de días, crea un grupo)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["crear_actividad"])) {
    $canchaId = (int)($_POST["cancha_id"] ?? 0);
    $diaDesde = (int)($_POST["dia_desde"] ?? 0);
    $diaHasta = (int)($_POST["dia_hasta"] ?? 0);

    $horaInicio = $_POST["hora_inicio"] ?? "";
    $horaFin = $_POST["hora_fin"] ?? "";
    $nombreActividad = trim($_POST["nombre"] ?? "");

    if (
        $canchaId <= 0 ||
        $diaDesde < 1 || $diaDesde > 7 ||
        $diaHasta < 1 || $diaHasta > 7 ||
        $horaInicio === "" || $horaFin === "" ||
        $nombreActividad === ""
    ) {
        header("Location: admin.php?error=actividad_invalida");
        exit();
    }

    if (strtotime($horaFin) <= strtotime($horaInicio)) {
        header("Location: admin.php?error=actividad_rango");
        exit();
    }

    $grupoId = bin2hex(random_bytes(16));

    $stmtInsert = $pdo->prepare("
        INSERT INTO actividades_rec (grupo_id, cancha_id, dia_desde, dia_hasta, hora_inicio, hora_fin, nombre, estado)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'activa')
    ");
    $stmtInsert->execute([$grupoId, $canchaId, $diaDesde, $diaHasta, $horaInicio, $horaFin, $nombreActividad]);

    header("Location: admin.php?success=3");
    exit();
}

// Procesar cancelación de actividad (cancela todo el grupo)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["cancelar_actividad"])) {
    $grupoId = $_POST["grupo_id"] ?? "";

    if ($grupoId === "") {
        header("Location: admin.php?error=cancelar_invalido");
        exit();
    }

    $stmt = $pdo->prepare("
        UPDATE actividades_rec
        SET estado = 'cancelada'
        WHERE grupo_id = ?
          AND estado = 'activa'
    ");
    $stmt->execute([$grupoId]);

    header("Location: admin.php?success=4");
    exit();
}

// Obtener actividades activas
$actividades = $pdo->query("
    SELECT 
        ar.grupo_id,
        ar.cancha_id,
        c.nombre AS cancha_nombre,
        ar.dia_desde,
        ar.dia_hasta,
        ar.hora_inicio,
        ar.hora_fin,
        ar.nombre,
        ar.created_at
    FROM actividades_rec ar
    JOIN canchas c ON c.id = ar.cancha_id
    WHERE ar.estado = 'activa'
    ORDER BY ar.cancha_id, ar.dia_desde, ar.hora_inicio
")->fetchAll(PDO::FETCH_ASSOC);

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

$dias = [
    1 => "Lunes",
    2 => "Martes",
    3 => "Miércoles",
    4 => "Jueves",
    5 => "Viernes",
    6 => "Sábado",
    7 => "Domingo"
];
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

        .btn-registro {
            background-color: #007bff;   
            color: #ffffff;
            border: none;
            padding: 8px 14px;
            font-size: 15px;
            font-weight: bold;
            border-radius: 6px;
            cursor: pointer;
        }

        .btn-registro:hover {
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
                        <button type="submit" class="logout-btn">
                            Cerrar Sesión
                        </button>
                    </form>
                </div>
            </div>

        </div>
    </div>

    <div class="container">
        <?php if (isset($_GET['success'])): ?>
            <div class="success">
                <?php 
                if ($_GET['success'] == 1) echo "Estado de cancha actualizado correctamente";
                if ($_GET['success'] == 2) echo "Reserva cancelada correctamente";
                if ($_GET["success"] == 3) echo "Actividad creada correctamente";
                if ($_GET["success"] == 4) echo "Actividad cancelada correctamente";
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
            <button type="button" class="btn btn-primary" onclick="abrirModalActividad()">
                Crear Actividad
            </button>
        </div>

        <div id="modalActividad" class="ventana-flotante">
            <div class="ventana-flotante-contenido">
                <h3>Crear Actividad</h3>

                <form method="POST" style="text-align:left;">
                    <input type="hidden" name="crear_actividad" value="1">

                    <label style="display:block; margin-top:10px;">Cancha</label>
                    <select name="cancha_id" required style="width:100%; padding:8px;">
                        <?php foreach ($canchas as $c): ?>
                            <option value="<?php echo (int)$c["id"]; ?>">
                                <?php echo htmlspecialchars($c["nombre"]); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label style="display:block; margin-top:10px;">Desde (día)</label>
                    <select name="dia_desde" required style="width:100%; padding:8px;">
                        <option value="1">Lunes</option>
                        <option value="2">Martes</option>
                        <option value="3">Miércoles</option>
                        <option value="4">Jueves</option>
                        <option value="5">Viernes</option>
                        <option value="6">Sábado</option>
                        <option value="7">Domingo</option>
                    </select>

                    <label style="display:block; margin-top:10px;">Hasta (día)</label>
                    <select name="dia_hasta" required style="width:100%; padding:8px;">
                        <option value="1">Lunes</option>
                        <option value="2">Martes</option>
                        <option value="3">Miércoles</option>
                        <option value="4">Jueves</option>
                        <option value="5">Viernes</option>
                        <option value="6">Sábado</option>
                        <option value="7">Domingo</option>
                    </select>

                    <label style="display:block; margin-top:10px;">Hora inicio</label>
                    <select name="hora_inicio" required style="width:100%; padding:8px;">
                        <?php foreach (getHorasDisponibles() as $b): ?>
                            <option value="<?php echo $b["inicio"]; ?>">
                                <?php echo date("H:i", strtotime($b["inicio"])); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label style="display:block; margin-top:10px;">Hora fin</label>
                    <select name="hora_fin" required style="width:100%; padding:8px;">
                        <?php foreach (getHorasDisponibles() as $b): ?>
                            <option value="<?php echo $b["fin"]; ?>">
                                <?php echo date("H:i", strtotime($b["fin"])); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label style="display:block; margin-top:10px;">Nombre de la actividad</label>
                    <input type="text" name="nombre" required maxlength="120" style="width:100%; padding:8px;">

                    <div class="ventana-flotante-acciones" style="margin-top: 15px;">
                        <button type="submit" class="btn btn-primary">Crear</button>
                        <button type="button" class="btn btn-danger" onclick="cerrarModalActividad()">Cerrar</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="section">
            <h2>Actividades Activas</h2>
            <table>
                <thead>
                    <tr>
                        <th>Cancha</th>
                        <th>Desde</th>
                        <th>Hasta</th>
                        <th>Hora inicio</th>
                        <th>Hora fin</th>
                        <th>Nombre</th>
                        <th>Fecha creación</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($actividades) === 0): ?>
                        <tr>
                            <td colspan="8">No hay actividades activas.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($actividades as $a): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($a["cancha_nombre"]); ?></td>

                                <!-- Desde (día) -->
                                <td><?php echo $dias[(int)$a["dia_desde"]] ?? "—"; ?></td>

                                <!-- Hasta (día) -->
                                <td><?php echo $dias[(int)$a["dia_hasta"]] ?? "—"; ?></td>

                                <td><?php echo date("H:i", strtotime($a["hora_inicio"])); ?></td>
                                <td><?php echo date("H:i", strtotime($a["hora_fin"])); ?></td>
                                <td><?php echo htmlspecialchars($a["nombre"]); ?></td>
                                <td><?php echo date("d/m/Y H:i", strtotime($a["created_at"])); ?></td>

                                <td>
                                    <form method="POST" class="form-inline">
                                        <input type="hidden" name="grupo_id" value="<?php echo htmlspecialchars($a["grupo_id"]); ?>">
                                        <button type="submit" name="cancelar_actividad" class="btn btn-danger"
                                                onclick="return confirm('¿Cancelar esta actividad recurrente?')">
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

        <div class="section">
            <h2>Reservas Activas</h2>
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
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reservas as $reserva): ?>
                        <tr>
                            <td><?php echo $reserva['id']; ?></td>
                            <td><?php echo $reserva['usuario_nombre']; ?></td>
                            <td><?php echo htmlspecialchars($reserva['usuario_apellido']); ?></td>
                            <td><?php echo $reserva['cancha_nombre']; ?></td>
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
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($reservasHistorial) === 0): ?>
                        <tr>
                            <td colspan="8">No hay reservas registradas.</td>
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