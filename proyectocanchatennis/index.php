<?php
include "config.php";
require_once __DIR__ . "/mail_reserva.php"; // ✅ NUEVO: para enviar correo al reservar desde admin

if (!isLoggedIn()) {
    header("Location: login.php");
    exit();
}

// 🔹 Obtener plan del usuario
$stmt = $pdo->prepare("SELECT plan FROM usuarios WHERE id = ?");
$stmt->execute([$_SESSION["user_id"]]);
$planUsuario = $stmt->fetchColumn(); // "Individual" o "Familiar"

// 🔐 Límites según plan
$limiteSemanal = ($planUsuario === "Familiar") ? 6 : 3;
$limiteDiario  = ($planUsuario === "Familiar") ? 2 : 1;

// Procesar filtro de fecha
$fecha = isset($_GET["fecha"]) ? $_GET["fecha"] : date("Y-m-d");
$esDomingo = (date("w", strtotime($fecha)) == 0); // 0 domingo
$esLunes   = (date("N", strtotime($fecha)) == 1); // 1 lunes

// Obtener reservas para la fecha seleccionada
$stmt = $pdo->prepare("
    SELECT r.cancha_id, r.hora, r.estado, c.nombre as cancha_nombre, u.nombre as usuario_nombre 
    FROM reservas r 
    JOIN canchas c ON r.cancha_id = c.id 
    JOIN usuarios u ON r.usuario_id = u.id 
    WHERE r.fecha = ? AND r.estado IN ('confirmada','pendiente')
");
$stmt->execute([$fecha]);
$reservas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Organizar reservas por cancha y hora
$reservas_organizadas = [];
foreach ($reservas as $reserva) {
    $reservas_organizadas[$reserva["cancha_id"]][$reserva["hora"]] = $reserva;
}

// Obtener canchas
$canchas = $pdo->query("SELECT * FROM canchas")->fetchAll(PDO::FETCH_ASSOC);
$horas = getHorasDisponibles();

// -------------------------
// ACTIVIDADES RECURRENTES (OCUPADO AL MOSTRAR)
// -------------------------
function actividadAplicaADia($dow, $desde, $hasta) {
    if ($desde <= $hasta) {
        return ($dow >= $desde && $dow <= $hasta);
    }
    return ($dow >= $desde || $dow <= $hasta);
}

function solapaRangoHora($bloqueInicio, $bloqueFin, $actInicio, $actFin) {
    return !(strtotime($bloqueFin) <= strtotime($actInicio) || strtotime($bloqueInicio) >= strtotime($actFin));
}

$dowSeleccionado = (int)date("N", strtotime($fecha)); // 1..7

$stmt = $pdo->prepare("
    SELECT grupo_id, cancha_id, dia_desde, dia_hasta, hora_inicio, hora_fin, nombre
    FROM actividades_rec
    WHERE estado = 'activa'
");
$stmt->execute();
$actividadesRec = $stmt->fetchAll(PDO::FETCH_ASSOC);

$ocupadoPorActividad = [];
foreach ($actividadesRec as $ar) {
    $canchaAct = (int)$ar["cancha_id"];
    $desde = (int)$ar["dia_desde"];
    $hasta = (int)$ar["dia_hasta"];

    if (!actividadAplicaADia($dowSeleccionado, $desde, $hasta)) {
        continue;
    }

    foreach ($horas as $bloque) {
        $bIni = $bloque["inicio"];
        $bFin = $bloque["fin"];

        if (solapaRangoHora($bIni, $bFin, $ar["hora_inicio"], $ar["hora_fin"])) {
            $ocupadoPorActividad[$canchaAct][$bIni] = true;
        }
    }
}

// Verificar si el usuario es admin
$isAdmin = isset($_SESSION["user_type"]) && $_SESSION["user_type"] === "admin";

// traer lista de usuarios (solo admin) + contador semanal y 24h
$usuariosParaAsignar = [];
if ($isAdmin) {
    $stmt = $pdo->query("
        SELECT 
            u.id,
            u.nombre,
            u.apellido,
            u.plan,
            COALESCE(SUM(CASE 
                WHEN r.estado = 'confirmada'
                 AND YEARWEEK(r.fecha_reserva, 1) = YEARWEEK(CURDATE(), 1)
                THEN 1 ELSE 0 END), 0) AS totalSemana,
            COALESCE(SUM(CASE
                WHEN r.estado = 'confirmada'
                 AND r.fecha_reserva >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                THEN 1 ELSE 0 END), 0) AS total24h
        FROM usuarios u
        LEFT JOIN reservas r ON r.usuario_id = u.id
        GROUP BY u.id, u.nombre, u.apellido, u.plan
        ORDER BY u.nombre ASC, u.apellido ASC
    ");
    $usuariosParaAsignar = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// helper (se usa en validación)
function bloqueOcupadoPorActividad($pdo, $fecha, $canchaId, $bloqueInicio) {
    $dow = (int)date("N", strtotime($fecha));
    $bloque = obtenerBloqueHora($bloqueInicio);
    if (!$bloque) return true;

    $bIni = $bloque["inicio"];
    $bFin = $bloque["fin"];

    $stmt = $pdo->prepare("
        SELECT dia_desde, dia_hasta, hora_inicio, hora_fin
        FROM actividades_rec
        WHERE estado = 'activa' AND cancha_id = ?
    ");
    $stmt->execute([(int)$canchaId]);
    $acts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($acts as $ar) {
        $desde = (int)$ar["dia_desde"];
        $hasta = (int)$ar["dia_hasta"];

        $aplica = ($desde <= $hasta)
            ? ($dow >= $desde && $dow <= $hasta)
            : ($dow >= $desde || $dow <= $hasta);

        if (!$aplica) continue;

        $solapa = !(strtotime($bFin) <= strtotime($ar["hora_inicio"]) || strtotime($bIni) >= strtotime($ar["hora_fin"]));
        if ($solapa) return true;
    }
    return false;
}

// -------------------------
// PROCESAR NUEVA RESERVA
// -------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["reservar"])) {

    $cancha_id = (int)($_POST["cancha_id"] ?? 0);
    $hora = $_POST["hora"] ?? "";

    // Definir si es tarde (>= 20:00)
    $esTarde = (strtotime($hora) >= strtotime("20:00:00"));

    if (bloqueOcupadoPorActividad($pdo, $fecha, $cancha_id, $hora)) {
        $error = "Ese horario está ocupado por una actividad recurrente.";
    }

    // ✅ ADMIN: asigna a usuario + envía correo al usuario asignado
    if ($isAdmin) {

        $usuarioIdFinal = (int)($_POST["usuario_id_asignado"] ?? 0);

        if (!isset($error) && $usuarioIdFinal <= 0) {
            $error = "Debes asignar un usuario para realizar la reserva.";
        }

        // Disponibilidad del bloque
        if (!isset($error)) {
            $stmt = $pdo->prepare("
                SELECT id
                FROM reservas
                WHERE cancha_id = ?
                  AND fecha = ?
                  AND hora = ?
                  AND estado IN ('confirmada','pendiente')
            ");
            $stmt->execute([$cancha_id, $fecha, $hora]);

            if ($stmt->rowCount() != 0) {
                $error = "La cancha ya está reservada en ese horario";
            }
        }

        // Validar cupos del usuario asignado (semana + 24h)
        if (!isset($error)) {

            $stmt = $pdo->prepare("SELECT plan FROM usuarios WHERE id = ?");
            $stmt->execute([$usuarioIdFinal]);
            $planAsignado = $stmt->fetchColumn();

            if (!$planAsignado) {
                $error = "Usuario asignado no válido.";
            } else {

                $limiteSemanalU = ($planAsignado === "Familiar") ? 6 : 3;
                $limiteDiarioU  = ($planAsignado === "Familiar") ? 2 : 1;

                $stmt = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM reservas
                    WHERE usuario_id = ?
                      AND estado IN ('confirmada','pendiente')
                      AND YEARWEEK(fecha_reserva, 1) = YEARWEEK(CURDATE(), 1)
                ");
                $stmt->execute([$usuarioIdFinal]);
                $totalSemanaU = (int)$stmt->fetchColumn();

                if ($totalSemanaU >= $limiteSemanalU) {
                    $error = ($planAsignado === "Familiar")
                        ? "Este usuario (Plan Familiar) ya alcanzó el máximo semanal (6)."
                        : "Este usuario (Plan Individual) ya alcanzó el máximo semanal (3).";
                } else {

                    $stmt = $pdo->prepare("
                        SELECT COUNT(*)
                        FROM reservas
                        WHERE usuario_id = ?
                          AND estado IN ('confirmada','pendiente')
                          AND fecha_reserva >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                    ");
                    $stmt->execute([$usuarioIdFinal]);
                    $total24hU = (int)$stmt->fetchColumn();

                    if ($total24hU >= $limiteDiarioU) {
                        $error = ($planAsignado === "Familiar")
                            ? "Este usuario (Plan Familiar) ya alcanzó el límite de 24h (2)."
                            : "Este usuario (Plan Individual) ya alcanzó el límite de 24h (1).";
                    }
                }
            }
        }

        // Insertar + enviar correo al usuario asignado
        if (!isset($error)) {

            // ✅ Admin siempre deja confirmada (si quieres pendiente por tarde también, me dices)
            $estadoNuevaReserva = "confirmada";

            $stmt = $pdo->prepare("
                INSERT INTO reservas (usuario_id, cancha_id, fecha, hora, estado)
                VALUES (?, ?, ?, ?, ?)
            ");

            if ($stmt->execute([$usuarioIdFinal, $cancha_id, $fecha, $hora, $estadoNuevaReserva])) {

                // ✅ NUEVO: enviar correo al usuario asignado
                $stmtU = $pdo->prepare("SELECT nombre, email FROM usuarios WHERE id = ?");
                $stmtU->execute([$usuarioIdFinal]);
                $u = $stmtU->fetch(PDO::FETCH_ASSOC);

                $stmtC = $pdo->prepare("SELECT nombre FROM canchas WHERE id = ?");
                $stmtC->execute([$cancha_id]);
                $canchaNombre = $stmtC->fetchColumn();

                if ($u && $canchaNombre) {
                    enviarEmailReserva($u["email"], $u["nombre"], [
                        "estado" => $estadoNuevaReserva,
                        "fecha" => date("d/m/Y", strtotime($fecha)),
                        "hora" => date("H:i", strtotime($hora)),
                        "canchaNombre" => $canchaNombre
                    ]);
                }

                header("Location: index.php?fecha=$fecha&success=1");
                exit();

            } else {
                $error = "No se pudo registrar la reserva.";
            }
        }

    } else {

        // -------------------------
        // SOCIO (se mantiene igual)
        // -------------------------

        // Validación: máximo reservas por semana
        if (!isset($error)) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as total
                FROM reservas
                WHERE usuario_id = ?
                  AND estado IN ('confirmada','pendiente')
                  AND YEARWEEK(fecha_reserva, 1) = YEARWEEK(CURDATE(), 1)
            ");
            $stmt->execute([$_SESSION["user_id"]]);
            $totalSemana = (int)$stmt->fetch(PDO::FETCH_ASSOC)["total"];

            if ($totalSemana >= $limiteSemanal) {
                $error = ($planUsuario === "Familiar")
                    ? "Tu plan Familiar permite hasta 6 reservas activas por semana."
                    : "Tu plan Individual permite hasta 3 reservas activas por semana.";
            }
        }

        // Validación: solo hoy/mañana/pasado mañana
        if (!isset($error)) {
            $hoy = new DateTime("today");
            $fechaCancha = new DateTime($fecha);
            $fechaMaxima = (clone $hoy)->modify("+2 days");

            if ($fechaCancha < $hoy || $fechaCancha > $fechaMaxima) {
                $fechaHabil = $fechaMaxima->format("d/m/Y");
                $error = "Las reservas solo pueden realizarse para hoy, mañana o pasado mañana. Podrás reservar hasta el {$fechaHabil}.";
            }
        }

        // Validación: límite 24h según plan
        if (!isset($error)) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM reservas
                WHERE usuario_id = ?
                  AND estado IN ('confirmada','pendiente')
                  AND fecha_reserva >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $stmt->execute([$_SESSION["user_id"]]);
            $reservasUltimas24h = (int)$stmt->fetchColumn();

            if ($reservasUltimas24h >= $limiteDiario) {
                $stmt = $pdo->prepare("
                    SELECT fecha_reserva
                    FROM reservas
                    WHERE usuario_id = ?
                      AND estado IN ('confirmada','pendiente')
                    ORDER BY fecha_reserva DESC
                    LIMIT 1
                ");
                $stmt->execute([$_SESSION["user_id"]]);
                $fechaUltima = new DateTime($stmt->fetchColumn());

                $proximaDisponible = (clone $fechaUltima)->modify("+24 hours");
                $ahora = new DateTime();

                $diff = $ahora->diff($proximaDisponible);
                $horasRestantes = ($diff->days * 24) + $diff->h;
                $minutosRestantes = $diff->i;

                $error = ($planUsuario === "Familiar")
                    ? "Tu plan Familiar permite hasta 2 reservas cada 24 horas. Podrás reservar nuevamente en {$horasRestantes} horas y {$minutosRestantes} minutos."
                    : "Tu plan Individual permite solo 1 reserva cada 24 horas. Podrás reservar nuevamente en {$horasRestantes} horas y {$minutosRestantes} minutos.";
            }
        }

        // Disponibilidad del bloque
        if (!isset($error)) {
            $stmt = $pdo->prepare("
                SELECT id
                FROM reservas
                WHERE cancha_id = ?
                  AND fecha = ?
                  AND hora = ?
                  AND estado IN ('confirmada','pendiente')
            ");
            $stmt->execute([$cancha_id, $fecha, $hora]);

            if ($stmt->rowCount() != 0) {
                $error = "La cancha ya está reservada en ese horario";
            }
        }

        // Comprobante obligatorio si es tarde
        if (!isset($error) && $esTarde) {
            if (!isset($_FILES["comprobante"]) || $_FILES["comprobante"]["error"] !== UPLOAD_ERR_OK) {
                $error = "Debes subir un comprobante para realizar la reserva.";
            } else {
                $maxBytes = 5 * 1024 * 1024;
                if ($_FILES["comprobante"]["size"] > $maxBytes) {
                    $error = "El comprobante supera el tamaño máximo permitido (5MB).";
                } else {
                    $tmpPath = $_FILES["comprobante"]["tmp_name"];
                    $imgInfo = @getimagesize($tmpPath);
                    if ($imgInfo === false) {
                        $error = "El comprobante debe ser una imagen válida.";
                    } else {
                        $extension = strtolower(pathinfo($_FILES["comprobante"]["name"], PATHINFO_EXTENSION));
                        if ($extension === "jpeg") $extension = "jpg";
                        $extPermitidas = ["jpg", "png", "webp"];
                        if (!in_array($extension, $extPermitidas)) {
                            $error = "Formato no permitido. Usa JPG, PNG o WEBP.";
                        }
                    }
                }
            }
        }

        // Insertar (SOCIO)
        if (!isset($error)) {

            $estadoNuevaReserva = ($esTarde ? "pendiente" : "confirmada");

            $stmt = $pdo->prepare("
                INSERT INTO reservas (usuario_id, cancha_id, fecha, hora, estado)
                VALUES (?, ?, ?, ?, ?)
            ");

            if ($stmt->execute([$_SESSION["user_id"], $cancha_id, $fecha, $hora, $estadoNuevaReserva])) {

                $reservaId = (int)$pdo->lastInsertId();

                // Guardar comprobante (solo tarde)
                if ($esTarde && isset($_FILES["comprobante"]) && $_FILES["comprobante"]["error"] === UPLOAD_ERR_OK) {
                    $tmpPath = $_FILES["comprobante"]["tmp_name"];

                    $extension = strtolower(pathinfo($_FILES["comprobante"]["name"], PATHINFO_EXTENSION));
                    if ($extension === "jpeg") $extension = "jpg";
                    $ext = $extension;

                    $destDir = __DIR__ . "/comprobantes";
                    if (!is_dir($destDir)) {
                        @mkdir($destDir, 0755, true);
                    }

                    $destPath = $destDir . "/reserva_" . $reservaId . "." . $ext;
                    @move_uploaded_file($tmpPath, $destPath);
                }

                // Enviar correo al socio
                $stmtU = $pdo->prepare("SELECT nombre, email FROM usuarios WHERE id = ?");
                $stmtU->execute([$_SESSION["user_id"]]);
                $u = $stmtU->fetch(PDO::FETCH_ASSOC);

                $stmtC = $pdo->prepare("SELECT nombre FROM canchas WHERE id = ?");
                $stmtC->execute([$cancha_id]);
                $canchaNombre = $stmtC->fetchColumn();

                if ($u && $canchaNombre) {
                    enviarEmailReserva($u["email"], $u["nombre"], [
                        "estado" => $estadoNuevaReserva,
                        "fecha" => date("d/m/Y", strtotime($fecha)),
                        "hora" => date("H:i", strtotime($hora)),
                        "canchaNombre" => $canchaNombre
                    ]);
                }

                header("Location: index.php?fecha=$fecha&success=1");
                exit();
            } else {
                $error = "No se pudo registrar la reserva.";
            }
        }
    }
}

// -------------------------
// RESERVAS DISPONIBLES SEMANA
// -------------------------
$stmt = $pdo->prepare("
    SELECT COUNT(*) as total
    FROM reservas
    WHERE usuario_id = ?
    AND estado IN ('confirmada','pendiente')
    AND YEARWEEK(fecha_reserva, 1) = YEARWEEK(CURDATE(), 1)
");
$stmt->execute([$_SESSION["user_id"]]);
$totalSemana = (int)$stmt->fetch(PDO::FETCH_ASSOC)["total"];

if ($isAdmin) {
    $reservasDisponibles = "Ilimitadas";
} else {
    $reservasDisponibles = max(0, $limiteSemanal - $totalSemana);
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reserva de Canchas</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0 auto;  background-color: #c2ffc2;}
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .header { width: 100%; background-color: #eeffee;}
        .header-inner {
            max-width: 1300px;
            margin: 0 auto;
            padding: 5px 5px; 
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .filtro { margin-bottom: 20px; }
        .disponibilidad-row {
            font-size: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .fecha-disponibilidad {
            font-size: 15px;
            font-weight: bold;
            padding: 10px 10px;         
            border-radius: 8px;         
        }

        .reservas-restantes {
            border: 2px solid #000000;   
            font-size: 15px;
            font-weight: bold;
            padding: 10px 10px;         
            border-radius: 8px;         
        }
        table { width: 100%; border-collapse: collapse; background-color: #f5f5f5;}
        th, td { border: 1px solid #7f7f7f; padding: 10px; text-align: center; }
        th { background: #f8f9fa; }
        .disponible { background: #b9f5ff; cursor: pointer; }
        .ocupada { background: #f8d7da; }
        .pendiente { background: #fff3cd; }
        .reservar-btn { padding: 5px 10px; background: #007bff; color: white; border: none; border-radius: 3px; cursor: pointer; }
        .reservar-btn:hover { background: #000000; color: #00ff5e}

        .logo-link { display: flex; align-items: center; }
        .logo-img {
            height: 90px;
            width: auto;
            max-width: 260px;
            cursor: pointer;
        }
        @media (max-width: 768px) { .logo-img { height: 40px; } }

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

        .btn-reserva, .btn-admin {
            background-color: #007bff;
            color: #ffffff;
            border: none;
            padding: 8px 14px;
            font-size: 15px;
            font-weight: bold;
            border-radius: 6px;
            cursor: pointer;
        }
        .btn-reserva:hover, .btn-admin:hover {
            background-color: #86c1ff;
            color: #000000;
        }

        h3 { font-size: 20px; }
        .Bienvenida {
            font-size: 14px;
            font-weight: bold;
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .Filtro { font-size: 18px; }
        .boton-filtro { font-size: 20px; font-weight: bold; padding: 4px 8px; }

        input[name="fecha"] {
            font-size: 15px;
            padding: 8px 8px;
            width: 150px;
            border-radius: 5px;
            border: 1px solid #ccc;
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
        .ventana-flotante-contenido h3 { margin-bottom: 10px; }
        .ventana-flotante-contenido p { margin-bottom: 20px; font-size: 15px; }

        .ventana-flotante-acciones { display: flex; justify-content: center; gap: 15px; }

        .btn-confirmar {
            background: #28a745;
            color: #ffffff;
            border: none;
            padding: 10px 18px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: bold;
        }
        .btn-cancelar {
            background: #dc3545;
            color: #ffffff;
            border: none;
            padding: 10px 18px;
            border-radius: 6px;
            cursor: pointer;
        }
        .btn-confirmar:hover, .btn-cancelar:hover { opacity: 0.85; }

        .success { background: #d4edda; color: #155724; padding: 10px; border-radius: 4px; margin-bottom: 15px; }
        .error { background: #f8d7da; color: #721c24; padding: 10px; border-radius: 4px; margin-bottom: 15px; }

        .nav-container { display: flex; align-items: center; gap: 12px; position: relative; }
        .nav-menu { display: flex; align-items: center; gap: 12px; }
        .no-disponible { background-color: #f8d7da; }

        .hamburger { display: none; font-size: 26px; background: none; border: none; cursor: pointer; }

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
            .nav-menu.show { display: flex; }
            .hamburger { display: block; }

            .ventana-flotante { width: 100%; padding-left: 16px; padding-right: 16px; box-sizing: border-box; }
            .ventana-flotante-contenido { width: 100%; max-width: 340px; box-sizing: border-box; }
        }
    </style>

    <link rel="icon" href="teniscanchalogo.png" type="image/png">
</head>

<body>
    <div class="header">
        <div class="header-inner">
            <a href="index.php" class="logo-link">
                <img src="teniscanchalogo.png" alt="Sistema de Reserva de Canchas" class="logo-img">
            </a>

            <div class="nav-container">
                <span class="Bienvenida">
                    Bienvenido, <?php echo $_SESSION["user_name"]; ?>
                </span>

                <button class="hamburger" onclick="toggleMenu()">☰</button>

                <div id="navMenu" class="nav-menu">
                    <?php if ($isAdmin): ?>
                        <form action="admin.php" method="get">
                            <button type="submit" class="btn-admin">Administración</button>
                        </form>
                    <?php endif; ?>

                    <form action="logout.php" method="post">
                        <button type="submit" class="logout-btn">Cerrar Sesión</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <?php if (isset($_GET["success"])): ?>
            <div class="success">¡Reserva realizada exitosamente!</div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>

        <div class="filtro">
            <form method="GET">
                <label class="Filtro">Filtrar por fecha:</label>
                <input type="date" name="fecha" value="<?php echo $fecha; ?>" min="<?php echo date("Y-m-d"); ?>">
                <button type="submit" class="boton-filtro">Filtrar</button>
            </form>
        </div>

        <div class="disponibilidad-row">
            <h3 class="fecha-disponibilidad">
                Disponibilidad para: <?php echo date("d/m/Y", strtotime($fecha)); ?>
            </h3>

            <div class="reservas-restantes">
                Reservas disponibles esta semana: <?php echo $reservasDisponibles; ?>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Hora</th>
                    <?php foreach ($canchas as $cancha): ?>
                        <th>
                            <?php echo $cancha["nombre"]; ?>
                            (<?php echo $cancha["estado"] == "disponible" ? "✅" : "🚫"; ?>)
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($horas as $hora): ?>
                    <tr>
                        <td>
                            <?php echo date("H:i", strtotime($hora["inicio"])); ?> -
                            <?php echo date("H:i", strtotime($hora["fin"])); ?>
                        </td>

                        <?php foreach ($canchas as $cancha): ?>
                            <?php
                            $hayReserva = isset($reservas_organizadas[$cancha["id"]][$hora["inicio"]]);
                            $estadoReserva = $hayReserva ? $reservas_organizadas[$cancha["id"]][$hora["inicio"]]["estado"] : null;
                            $hayActividad = isset($ocupadoPorActividad[$cancha["id"]][$hora["inicio"]]);
                            $estaOcupado  = ($hayReserva || $hayActividad);

                            $esHoraNoDisponibleDomingo = ($esDomingo && strtotime($hora["inicio"]) > strtotime("11:00:00"));
                            $esHoraNoDisponibleLunes   = ($esLunes && strtotime($hora["inicio"]) < strtotime("11:00:00"));

                            $esHoy = ($fecha === date("Y-m-d"));
                            $inicioBloque = strtotime($fecha . " " . $hora["inicio"]);
                            $ahora = time();
                            $esHoraPasada = ($esHoy && $inicioBloque <= $ahora && !$estaOcupado);

                            $esHoraNoDisponible = ($esHoraNoDisponibleDomingo || $esHoraNoDisponibleLunes || $esHoraPasada);
                            ?>

                            <td class="<?php
                                if ($hayReserva && $estadoReserva === "pendiente") {
                                    echo "pendiente";
                                } elseif ($estaOcupado) {
                                    echo "ocupada";
                                } else {
                                    echo $esHoraNoDisponible
                                        ? "no-disponible"
                                        : ($cancha["estado"] == "disponible" ? "disponible" : "ocupada");
                                }
                            ?>">
                                <?php if ($estaOcupado): ?>
                                    <?php echo ($estadoReserva === "pendiente") ? "Pendiente" : "Ocupada"; ?>
                                <?php elseif ($esHoraNoDisponible): ?>
                                    <?php echo $esHoraNoDisponibleLunes ? "En mantenimiento" : "No disponible"; ?>
                                <?php elseif ($cancha["estado"] == "disponible"): ?>
                                    <form method="POST" enctype="multipart/form-data" style="margin: 0;">
                                        <input type="hidden" name="cancha_id" value="<?php echo $cancha["id"]; ?>">
                                        <input type="hidden" name="hora" value="<?php echo $hora["inicio"]; ?>">
                                        <input type="hidden" name="reservar" value="1">

                                        <?php if ($isAdmin): ?>
                                            <input type="hidden" name="usuario_id_asignado" value="">
                                        <?php else: ?>
                                            <input
                                                type="file"
                                                name="comprobante"
                                                accept="image/*"
                                                class="input-comprobante"
                                                style="display:none; margin-top:10px;"
                                            >
                                        <?php endif; ?>

                                        <button type="button" class="reservar-btn" onclick="abrirVentanaFlotante(this)">
                                            Reservar
                                        </button>
                                    </form>
                                <?php else: ?>
                                    No Disponible
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="nav-links">
            <h3>Mis Reservas</h3>
            <form action="mis_reservas.php" method="get">
                <button type="submit" class="btn-reserva">Ver mis reservas activas</button>
            </form>
        </div>

        <!-- Ventana flotante -->
        <div id="ventanaFlotante" class="ventana-flotante">
            <div class="ventana-flotante-contenido">
                <?php if ($isAdmin): ?>
                    <h3>Asignar reserva a un usuario</h3>

                    <div style="max-height: 320px; overflow: auto; text-align: left; border: 1px solid #ccc; border-radius: 8px;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr>
                                    <th style="border: 1px solid #ddd; padding: 8px;">Nombre</th>
                                    <th style="border: 1px solid #ddd; padding: 8px;">Apellido</th>
                                    <th style="border: 1px solid #ddd; padding: 8px;">Disponibles</th>
                                    <th style="border: 1px solid #ddd; padding: 8px;">Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($usuariosParaAsignar as $u): ?>
                                    <?php
                                    $limiteSemanalU = ($u["plan"] === "Familiar") ? 6 : 3;
                                    $limiteDiarioU  = ($u["plan"] === "Familiar") ? 2 : 1;

                                    $dispSemana = max(0, $limiteSemanalU - (int)$u["totalSemana"]);
                                    $disp24h    = max(0, $limiteDiarioU - (int)$u["total24h"]);

                                    $textoDisponibles = $disp24h . " reservas";
                                    $sinCupo = ($disp24h <= 0);
                                    ?>
                                    <tr>
                                        <td style="border: 1px solid #ddd; padding: 8px;"><?php echo htmlspecialchars($u["nombre"]); ?></td>
                                        <td style="border: 1px solid #ddd; padding: 8px;"><?php echo htmlspecialchars($u["apellido"]); ?></td>
                                        <td style="border: 1px solid #ddd; padding: 8px;"><?php echo htmlspecialchars($textoDisponibles); ?></td>
                                        <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">
                                            <button type="button" class="btn-confirmar"
                                                <?php echo $sinCupo ? "disabled" : ""; ?>
                                                style="<?php echo $sinCupo ? "opacity:0.5; cursor:not-allowed;" : ""; ?>"
                                                onclick="asignarYReservar(<?php echo (int)$u["id"]; ?>)">
                                                Asignar
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <p id="mensajePago" style="color: red; margin-top: 10px;"></p>

                    <div class="ventana-flotante-acciones" style="margin-top: 12px;">
                        <button id="btnCancelarReserva" class="btn-cancelar">Cerrar</button>
                    </div>

                <?php else: ?>
                    <h3>Confirmar reserva</h3>
                    <p>¿Estás seguro de que deseas realizar esta reserva?</p>

                    <p id="mensajePago" style="color: red;"></p>

                    <div id="contenedorComprobante" style="display:none; text-align:left; margin-top:10px; margin-bottom:10px;"></div>

                    <p id="errorComprobante" style="display:none; color:#b00020; margin-top:8px;"></p>

                    <div class="ventana-flotante-acciones">
                        <button id="btnConfirmarReserva" class="btn-confirmar">Sí, reservar</button>
                        <button id="btnCancelarReserva" class="btn-cancelar">Cancelar</button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

<script>
let formularioSeleccionado = null;
let inputComprobanteActual = null;

function abrirVentanaFlotante(boton) {
    formularioSeleccionado = boton.closest("form");

    const mensajePagoEl = document.getElementById("mensajePago");
    if (mensajePagoEl) mensajePagoEl.innerText = "";

    const cont = document.getElementById("contenedorComprobante");
    const err  = document.getElementById("errorComprobante");
    if (err) { err.style.display = "none"; err.innerText = ""; }

    const horaStr = formularioSeleccionado.querySelector("input[name='hora']")?.value || "00:00:00";
    const partes = horaStr.split(":");
    const h = parseInt(partes[0], 10) || 0;
    const m = parseInt(partes[1], 10) || 0;
    const esTarde = (h > 20) || (h === 20 && m >= 0);

    if (mensajePagoEl) {
        mensajePagoEl.innerText = esTarde
            ? "Recuerda que debes realizar el pago de la luz antes de hacer la reserva."
            : "";
    }

    const inputFile = formularioSeleccionado.querySelector("input[name='comprobante']");
    inputComprobanteActual = inputFile || null;

    if (cont) {
        if (esTarde) {
            cont.style.display = "block";
            cont.innerHTML = `
                <label style="display:block; font-weight:bold; margin-bottom:6px;">
                    Subir comprobante (obligatorio)
                </label>

                <button type="button" id="btnElegirArchivo"
                    style="padding:8px 12px; border:1px solid #ccc; border-radius:6px; cursor:pointer;">
                    Elegir archivo
                </button>

                <div id="nombreArchivo" style="margin-top:8px; font-size:14px; color:#333;">
                    Ningún archivo seleccionado
                </div>
            `;
        } else {
            cont.style.display = "none";
            cont.innerHTML = "";
        }
    }

    if (inputFile) {
        inputFile.required = esTarde;

        if (esTarde) {
            const btnElegir = document.getElementById("btnElegirArchivo");
            const lblNombre = document.getElementById("nombreArchivo");

            if (btnElegir) btnElegir.onclick = () => inputFile.click();

            inputFile.onchange = () => {
                if (!lblNombre) return;
                if (inputFile.files && inputFile.files.length > 0) {
                    lblNombre.innerText = inputFile.files[0].name;
                } else {
                    lblNombre.innerText = "Ningún archivo seleccionado";
                }
            };
        }
    }

    document.getElementById("ventanaFlotante").style.display = "flex";
}

document.getElementById("btnCancelarReserva").addEventListener("click", () => {
    if (formularioSeleccionado && inputComprobanteActual) {
        inputComprobanteActual.required = false;
        inputComprobanteActual.style.display = "none";
        formularioSeleccionado.appendChild(inputComprobanteActual);
    }

    document.getElementById("ventanaFlotante").style.display = "none";
    formularioSeleccionado = null;
    inputComprobanteActual = null;
});

<?php if (!$isAdmin): ?>
document.getElementById("btnConfirmarReserva").addEventListener("click", () => {
    if (!formularioSeleccionado) return;

    const horaStr = formularioSeleccionado.querySelector("input[name='hora']")?.value || "00:00:00";
    const partes = horaStr.split(":");
    const h = parseInt(partes[0], 10) || 0;
    const m = parseInt(partes[1], 10) || 0;
    const esTarde = (h > 20) || (h === 20 && m >= 0);

    const inputFile = (inputComprobanteActual || formularioSeleccionado.querySelector("input[name='comprobante']"));
    const err = document.getElementById("errorComprobante");

    if (esTarde) {
        if (!inputFile || !inputFile.files || inputFile.files.length === 0) {
            if (err) {
                err.style.display = "block";
                err.innerText = "Debes subir un comprobante para realizar la reserva.";
            }
            return;
        }
    }

    formularioSeleccionado.submit();
});
<?php endif; ?>

function asignarYReservar(usuarioId) {
    if (!formularioSeleccionado) return;

    const inputAsignado = formularioSeleccionado.querySelector("input[name='usuario_id_asignado']");
    if (!inputAsignado) return;

    inputAsignado.value = usuarioId;
    formularioSeleccionado.submit();
}

function toggleMenu() {
    document.getElementById("navMenu").classList.toggle("show");
}
</script>
</body>
</html>
