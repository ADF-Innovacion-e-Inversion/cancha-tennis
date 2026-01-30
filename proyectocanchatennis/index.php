<?php
include 'config.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

// 🔹 Obtener plan del usuario
$stmt = $pdo->prepare("SELECT plan FROM usuarios WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$planUsuario = $stmt->fetchColumn(); // 'Individual' o 'Familiar'

// 🔐 Límites según plan
$limiteSemanal = ($planUsuario === 'Familiar') ? 6 : 3;
$limiteDiario  = ($planUsuario === 'Familiar') ? 2 : 1;

// Procesar filtro de fecha
$fecha = isset($_GET['fecha']) ? $_GET['fecha'] : date('Y-m-d');
// Verificar si es domingo
$esDomingo = (date('w', strtotime($fecha)) == 0); // 0 significa domingo

// Obtener reservas para la fecha seleccionada
$stmt = $pdo->prepare("
    SELECT r.cancha_id, r.hora, c.nombre as cancha_nombre, u.nombre as usuario_nombre 
    FROM reservas r 
    JOIN canchas c ON r.cancha_id = c.id 
    JOIN usuarios u ON r.usuario_id = u.id 
    WHERE r.fecha = ? AND r.estado = 'confirmada'
");
$stmt->execute([$fecha]);
$reservas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Organizar reservas por cancha y hora
$reservas_organizadas = [];
foreach ($reservas as $reserva) {
    $reservas_organizadas[$reserva['cancha_id']][$reserva['hora']] = $reserva;
}

// Obtener canchas
$canchas = $pdo->query("SELECT * FROM canchas")->fetchAll(PDO::FETCH_ASSOC);
$horas = getHorasDisponibles();

// Verificar si el usuario es admin
$isAdmin = isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin';

//traer lista de usuarios (solo admin)
$usuariosParaAsignar = [];
if ($isAdmin) {
    $stmt = $pdo->query("
        SELECT id, nombre, apellido, rut, email
        FROM usuarios
        ORDER BY nombre ASC, apellido ASC
    ");
    $usuariosParaAsignar = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Procesar nueva reserva al presionar el botón
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["reservar"])) {
    $cancha_id = $_POST["cancha_id"];
    $hora = $_POST["hora"];

    // Definir si es tarde (después de las 20:00)
    $horaReserva = new DateTime($hora);
    $horaLimite = new DateTime("20:00:00");
    $esTarde = $horaReserva >= $horaLimite;

    // Si la hora es 20:00 o después, añadir el mensaje especial
    if ($esTarde) {
        $mensajePago = "Recuerda que debes realizar el pago antes de la reserva.";
    } else {
        $mensajePago = "";
    }

    // ✅ ADMIN: no aplicar restricciones (pero sí validar disponibilidad)
    if ($isAdmin) {

        // Verificar si la cancha ya está reservada en ese horario
        $stmt = $pdo->prepare("
            SELECT id
            FROM reservas
            WHERE cancha_id = ?
            AND fecha = ?
            AND hora = ?
            AND estado = 'confirmada'
        ");
        $stmt->execute([$cancha_id, $fecha, $hora]);

        if ($stmt->rowCount() == 0) {

            $stmt = $pdo->prepare("
                INSERT INTO reservas (usuario_id, cancha_id, fecha, hora)
                VALUES (?, ?, ?, ?)
            ");

            $usuarioIdFinal = (int)($_POST["usuario_id_asignado"] ?? 0);

            if ($usuarioIdFinal <= 0) {
                $error = "Debes asignar un usuario para realizar la reserva.";
            } else {
                if ($stmt->execute([$usuarioIdFinal, $cancha_id, $fecha, $hora])) {
                    header("Location: index.php?fecha=$fecha&success=1");
                    exit();
                } else {
                    $error = "No se pudo registrar la reserva.";
                }
            }

        } else {
            $error = "La cancha ya está reservada en ese horario";
        }
    } else {

        /*
        Validación: máximo reservas por semana (según plan)
        */
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total
            FROM reservas
            WHERE usuario_id = ?
              AND estado = 'confirmada'
              AND YEARWEEK(fecha_reserva, 1) = YEARWEEK(CURDATE(), 1)
        ");
        $stmt->execute([$_SESSION["user_id"]]);
        $totalSemana = (int)$stmt->fetch(PDO::FETCH_ASSOC)["total"];

        if ($totalSemana >= $limiteSemanal) {
            if ($planUsuario === "Familiar") {
                $error = "Tu plan Familiar permite hasta 6 reservas activas por semana.";
            } else {
                $error = "Tu plan Individual permite hasta 3 reservas activas por semana.";
            }

        } else {

            // Validación: solo permitir reservas hoy, mañana o pasado mañana
            $hoy = new DateTime("today"); // hoy a las 00:00
            $fechaCancha = new DateTime($fecha); // fecha seleccionada (00:00)

            // Permitir hasta 2 días de anticipación (hoy, mañana y pasado mañana)
            $fechaMaxima = (clone $hoy)->modify("+2 days"); // Fecha máxima: pasado mañana

            if ($fechaCancha < $hoy || $fechaCancha > $fechaMaxima) {
                $fechaHabil = $fechaMaxima->format("d/m/Y");
                $error = "Las reservas solo pueden realizarse para hoy, mañana o pasado mañana. "
                    . "Podrás reservar hasta el {$fechaHabil}.";
            } else {

                // 🔐 Validación por plan (24 horas)
                $stmt = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM reservas
                    WHERE usuario_id = ?
                      AND estado = 'confirmada'
                      AND fecha_reserva >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                ");
                $stmt->execute([$_SESSION["user_id"]]);
                $reservasUltimas24h = (int)$stmt->fetchColumn();

                if ($reservasUltimas24h >= $limiteDiario) {

                    // Obtener la ÚLTIMA reserva realizada
                    $stmt = $pdo->prepare("
                        SELECT fecha_reserva
                        FROM reservas
                        WHERE usuario_id = ?
                          AND estado = 'confirmada'
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

                    if ($planUsuario === "Familiar") {
                        $error = "Tu plan Familiar permite hasta 2 reservas cada 24 horas. "
                            . "Podrás reservar nuevamente en {$horasRestantes} horas y {$minutosRestantes} minutos.";
                    } else {
                        $error = "Tu plan Individual permite solo 1 reserva cada 24 horas. "
                            . "Podrás reservar nuevamente en {$horasRestantes} horas y {$minutosRestantes} minutos.";
                    }

                } else {

                    // Verificar si la cancha está disponible (no reservada)
                    $stmt = $pdo->prepare("
                        SELECT id
                        FROM reservas
                        WHERE cancha_id = ?
                          AND fecha = ?
                          AND hora = ?
                          AND estado = 'confirmada'
                    ");
                    $stmt->execute([$cancha_id, $fecha, $hora]);

                    if ($stmt->rowCount() == 0) {
                        $stmt = $pdo->prepare("
                            INSERT INTO reservas (usuario_id, cancha_id, fecha, hora)
                            VALUES (?, ?, ?, ?)
                        ");
                        if ($stmt->execute([$_SESSION["user_id"], $cancha_id, $fecha, $hora])) {
                            header("Location: index.php?fecha=$fecha&success=1");
                            exit();
                        }
                    } else {
                        $error = "La cancha ya está reservada en ese horario";
                    }
                }
            }
        }
    }
}

// Función para calcular las reservas disponibles para la semana (según plan)

$stmt = $pdo->prepare("
    SELECT COUNT(*) as total
    FROM reservas
    WHERE usuario_id = ?
    AND estado = 'confirmada'
    AND YEARWEEK(fecha_reserva, 1) = YEARWEEK(CURDATE(), 1)
");
$stmt->execute([$_SESSION['user_id']]);
$totalSemana = (int)$stmt->fetch(PDO::FETCH_ASSOC)['total'];

// ✅ Si es admin, puedes mostrar "∞" (opcional). Si no quieres, borra este if.
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
        .disponible { background: #b9f5ff; cursor: pointer; } /* #d4edda  */
        .ocupada { background: #f8d7da; }
        .reservar-btn { padding: 5px 10px; background: #007bff; color: white; border: none; border-radius: 3px; cursor: pointer; }
        .reservar-btn:hover { background: #000000; color: #00ff5e}
        
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
        
        .reservation-link { margin-top: 20px; }
        .reservation-link:hover{opacity: 0.5;}
        .reservation-link {
            color: #1d6cd2ff
            font-size: 18px;
            font-weight: bold;
        }
        .reservation-link:visited {color: #1d6cd2ff}
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

        .btn-reserva {
            background-color: #007bff;   
            color: #ffffff;
            border: none;
            padding: 8px 14px;
            font-size: 15px;
            font-weight: bold;
            border-radius: 6px;
            cursor: pointer;
        }

        .btn-reserva:hover {
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
  
        .Admin-link:hover{opacity: 0.5;}
        .Admin-link {
            color: #1d6cd2ff; 
            font-size: 18px;
            font-weight: bold;
        }
        .Admin-link:visited {color: #1d6cd2ff;}
        h3 {
            font-size: 20px;
        }
        .Bienvenida {
            font-size: 14px;
            font-weight: bold;
            max-width: 200px;       
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .Filtro {
            font-size: 18px;

        }
        .boton-filtro {
            font-size: 20px;
            font-weight: bold;
            padding: 4px 8px;
        }
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

        .ventana-flotante-contenido h3 {
            margin-bottom: 10px;
        }

        .ventana-flotante-contenido p {
            margin-bottom: 20px;
            font-size: 15px;
        }

        .ventana-flotante-acciones {
            display: flex;
            justify-content: center;
            gap: 15px;
        }

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

        .btn-confirmar:hover,
        .btn-cancelar:hover {
            opacity: 0.85;
        }

        .success { background: #d4edda; color: #155724; padding: 10px; border-radius: 4px; margin-bottom: 15px; }
        .error { background: #f8d7da; color: #721c24; padding: 10px; border-radius: 4px; margin-bottom: 15px; }

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

        .no-disponible {
            background-color: #f8d7da; 
            
        }

        .hamburger {
            display: none;
            font-size: 26px;
            background: none;
            border: none;
            cursor: pointer;
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
                width: 100%;
                padding-left: 16px;
                padding-right: 16px;
                box-sizing: border-box;
            }

            .ventana-flotante-contenido {
                width: 100%;            /* ocupa el espacio disponible */
                max-width: 340px;       /* ✅ límite para que no sea gigante */
                box-sizing: border-box; /* ✅ el padding no aumenta el ancho */
            }

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
                    Bienvenido, <?php echo $_SESSION['user_name']; ?>
                </span>

                <!-- Botón hamburguesa -->
                <button class="hamburger" onclick="toggleMenu()">☰</button>

                <!-- Menú -->
                <div id="navMenu" class="nav-menu">
                    <?php if ($isAdmin): ?>
                        <form action="admin.php" method="get">
                            <button type="submit" class="btn-admin">
                                Administración
                            </button>
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
        <?php if (isset($_GET['success'])): ?>
            <div class="success">¡Reserva realizada exitosamente!</div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>

        <div class="filtro">
            <form method="GET">
                <label class="Filtro">Filtrar por fecha:</label>
                <input type="date" name="fecha" value="<?php echo $fecha; ?>" min="<?php echo date('Y-m-d'); ?>">
                <button type="submit" class="boton-filtro">Filtrar</button>
            </form>
        </div>

        <div class="disponibilidad-row">
            <h3 class="fecha-disponibilidad">
                Disponibilidad para: <?php echo date('d/m/Y', strtotime($fecha)); ?>
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
                        <th><?php echo $cancha['nombre']; ?> 
                            (<?php echo $cancha['estado'] == 'disponible' ? '✅' : '🚫'; ?>)
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($horas as $hora): ?>
                    <tr>
                        <td> 
                            <?php echo date("H:i", strtotime($hora["inicio"])); //Se le da el formato a la hora (inicio - final) ?>
                            -
                            <?php echo date("H:i", strtotime($hora["fin"])); ?>
                        </td>
                        <?php foreach ($canchas as $cancha): ?>
                            <?php 
                            // Verificar si es domingo y si la hora es posterior a las 11:00
                            $esHoraNoDisponible = ($esDomingo && strtotime($hora["inicio"]) > strtotime("11:00:00"));
                            ?>
                            <td class="<?php echo isset($reservas_organizadas[$cancha['id']][$hora["inicio"]]) ? 'ocupada' : ($esHoraNoDisponible ? 'no-disponible' : ($cancha['estado'] == 'disponible' ? 'disponible' : 'ocupada')); ?>">
                                <?php if ($esHoraNoDisponible): ?>
                                    No disponible
                                <?php elseif (isset($reservas_organizadas[$cancha['id']][$hora["inicio"]])): ?>
                                    Ocupada
                                <?php elseif ($cancha['estado'] == 'disponible'): ?>
                                    <form method="POST" style="margin: 0;">
                                        <input type="hidden" name="cancha_id" value="<?php echo $cancha["id"]; ?>">
                                        <input type="hidden" name="hora" value="<?php echo $hora["inicio"]; ?>">
                                        <input type="hidden" name="reservar" value="1">

                                        <?php if ($isAdmin): ?>
                                            <input type="hidden" name="usuario_id_asignado" value="">
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
                <button type="submit" class="btn-reserva">
                    Ver mis reservas activas
                </button>
            </form>
        </div>

    <!-- Ventana flotante de confirmación -->
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
                                <!--
                                <th style="border: 1px solid #ddd; padding: 8px;">RUT</th>
                                <th style="border: 1px solid #ddd; padding: 8px;">Email</th>
                                -->
                                <th style="border: 1px solid #ddd; padding: 8px;">Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($usuariosParaAsignar as $u): ?>
                                <tr>
                                    <td style="border: 1px solid #ddd; padding: 8px;"><?php echo htmlspecialchars($u["nombre"]); ?></td>
                                    <td style="border: 1px solid #ddd; padding: 8px;"><?php echo htmlspecialchars($u["apellido"]); ?></td>
                                    <!--
                                    <td style="border: 1px solid #ddd; padding: 8px;"><?php echo htmlspecialchars($u["rut"]); ?></td>
                                    <td style="border: 1px solid #ddd; padding: 8px;"><?php echo htmlspecialchars($u["email"]); ?></td>
                                    -->
                                    <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">
                                        <button type="button" class="btn-confirmar"
                                                onclick="asignarYReservar(<?php echo (int)$u['id']; ?>)">
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

                <div class="ventana-flotante-acciones">
                    <button id="btnConfirmarReserva" class="btn-confirmar">Sí, reservar</button>
                    <button id="btnCancelarReserva" class="btn-cancelar">Cancelar</button>
                </div>
            <?php endif; ?>
        </div>
    </div>


<script>
let formularioSeleccionado = null;

function abrirVentanaFlotante(boton) {
    formularioSeleccionado = boton.closest("form");
    const horaSeleccionada = formularioSeleccionado.querySelector("input[name='hora']").value;

    const horaLimite = "20:00:00";
    const mensajePago = (horaSeleccionada >= horaLimite) ? "Recuerda que debes realizar el pago." : "";

    document.getElementById("mensajePago").innerText = mensajePago;

    document.getElementById("ventanaFlotante").style.display = "flex";
}

document.getElementById("btnCancelarReserva").addEventListener("click", () => {
    document.getElementById("ventanaFlotante").style.display = "none";
    formularioSeleccionado = null;
});

<?php if (!$isAdmin): ?>
document.getElementById("btnConfirmarReserva").addEventListener("click", () => {
    if (formularioSeleccionado) {
        formularioSeleccionado.submit();
    }
});
<?php endif; ?>

// ✅ Solo admin: asignar usuario y reservar
function asignarYReservar(usuarioId) {
    if (!formularioSeleccionado) return;

    const inputAsignado = formularioSeleccionado.querySelector("input[name='usuario_id_asignado']");
    if (!inputAsignado) return;

    inputAsignado.value = usuarioId;
    formularioSeleccionado.submit();
}
</script>

<script> //Funcion para abrir el menú de hamburguesa
function toggleMenu() {
    document.getElementById("navMenu").classList.toggle("show");
}
</script>
</body>
</html>