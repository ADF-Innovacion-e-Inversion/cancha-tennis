<?php
include 'config.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

// Procesar filtro de fecha
$fecha = isset($_GET['fecha']) ? $_GET['fecha'] : date('Y-m-d');

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



// Procesar nueva reserva al presionar el botón
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['reservar'])) {
    $cancha_id = $_POST['cancha_id'];
    $hora = $_POST['hora'];
    
    
    /*
    Validación: máximo 3 reservas por semana
    */
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total
        FROM reservas
        WHERE usuario_id = ?
        AND estado = 'confirmada'
        AND YEARWEEK(fecha_reserva, 1) = YEARWEEK(CURDATE(), 1)
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $totalSemana = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    if ($totalSemana >= 3) {
        $error = "Ya alcanzaste el máximo de 3 reservas activas esta semana.";
        
    } else {

        // Validación: mínimo 3 días de anticipación (solo por día, sin hora)
        $hoy = new DateTime('today');                 // hoy a las 00:00
        $fechaCancha = new DateTime($fecha);          // fecha seleccionada (00:00)
        $fechaMinima = (clone $hoy)->modify('+3 days');

        if ($fechaCancha < $fechaMinima) {
            $fechaHabil = $fechaMinima->format("d/m/Y");
            

            $error = "Las reservas deben realizarse con al menos 3 días de anticipación. "
                . "Podrás reservar a partir del {$fechaHabil}.";
        } else {

            // Validación: 1 reserva cada 24 hrs
            $stmt = $pdo->prepare("
                SELECT fecha_reserva
                FROM reservas
                WHERE usuario_id = ?
                AND estado = 'confirmada'
                ORDER BY fecha_reserva DESC
                LIMIT 1
            ");
            $stmt->execute([$_SESSION['user_id']]);
            $ultimaReserva = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($ultimaReserva) {
                $fechaUltima = new DateTime($ultimaReserva['fecha_reserva']);
                $proximaPermitida = (clone $fechaUltima)->modify('+24 hours');

                if (new DateTime() < $proximaPermitida) {
                    $ahora = new DateTime();
                    $diff = $ahora->diff($proximaPermitida);

                    $horasRestantes = ($diff->days * 24) + $diff->h;
                    $minutosRestantes = $diff->i;

                    $error = "Solo puedes realizar una reserva cada día. "
                        . "Podrás reservar nuevamente en {$horasRestantes} horas y {$minutosRestantes} minutos.";
                } else {
                    // Verificar si la cancha está disponible
                    $stmt = $pdo->prepare("SELECT id FROM reservas WHERE cancha_id = ? AND fecha = ? AND hora = ? AND estado = 'confirmada'");
                    $stmt->execute([$cancha_id, $fecha, $hora]);
                    
                    if ($stmt->rowCount() == 0) {
                        $stmt = $pdo->prepare("INSERT INTO reservas (usuario_id, cancha_id, fecha, hora) VALUES (?, ?, ?, ?)");
                        if ($stmt->execute([$_SESSION['user_id'], $cancha_id, $fecha, $hora])) {
                            header("Location: index.php?fecha=$fecha&success=1");
                            exit();
                        }
                    } else {
                        $error = "La cancha ya está reservada en ese horario";
                    }
                }
            } else {
                // Verificar si la cancha está disponible
                    $stmt = $pdo->prepare("SELECT id FROM reservas WHERE cancha_id = ? AND fecha = ? AND hora = ? AND estado = 'confirmada'");
                    $stmt->execute([$cancha_id, $fecha, $hora]);
                    
                    if ($stmt->rowCount() == 0) {
                        $stmt = $pdo->prepare("INSERT INTO reservas (usuario_id, cancha_id, fecha, hora) VALUES (?, ?, ?, ?)");
                        if ($stmt->execute([$_SESSION['user_id'], $cancha_id, $fecha, $hora])) {
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

//Función para calcular las reservas disponibles para la semana
$stmt = $pdo->prepare("
    SELECT COUNT(*) as total
    FROM reservas
    WHERE usuario_id = ?
    AND estado = 'confirmada'
    AND YEARWEEK(fecha_reserva, 1) = YEARWEEK(CURDATE(), 1)
");
$stmt->execute([$_SESSION['user_id']]);
$totalSemana = (int)$stmt->fetch(PDO::FETCH_ASSOC)['total'];

$reservasDisponibles = max(0, 3 - $totalSemana);

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reserva de Canchas</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 1200px; margin: 0 auto; padding: 20px; background-color: #fbeedbff;}
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        /* .header h1 {text-shadow: 2px 5px 5px green}; */
        .filtro { margin-bottom: 20px; }
        .disponibilidad-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .reservas-restantes {
            border: 2px solid #d1bfa7;   /* borde suave */
            font-size: 17px;
            font-weight: bold;
            padding: 10px 14px;         /* ← espacio interno */
            border-radius: 8px;         /* opcional, se ve mejor */
        }
        table { width: 100%; border-collapse: collapse; background-color: #f5f5f5;}
        th, td { border: 1px solid #ddd; padding: 10px; text-align: center; }
        th { background: #f8f9fa; }
        .disponible { background: #d4edda; cursor: pointer; }
        .ocupada { background: #f8d7da; }
        .reservar-btn { padding: 5px 10px; background: #007bff; color: white; border: none; border-radius: 3px; cursor: pointer; }
        .reservar-btn:hover { background: #0056b3; }
        
        .logo-link {
            display: flex;
            align-items: center;
        }

        .logo-img {
            height: 90px;        /* tamaño ideal header */
            width: auto;
            max-width: 260px;
            cursor: pointer;
        }

        @media (max-width: 768px) {
            .logo-img {
                height: 40px;    /* un poco más chico en celular */
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
            font-size: 18px;
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
            font-size: 15px;     /* tamaño del texto */
            padding: 8px 8px;  /* altura del campo */
            width: 150px;        /* ancho del calendario */
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

    </style>
</head>


<body>
    <div class="header">
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
                <?php if (isAdmin()): ?>
                    <a href="admin.php" class="Admin-link">Administración</a>
                <?php endif; ?>

                <form action="logout.php" method="post">
                    <button type="submit" class="logout-btn">Cerrar Sesión</button>
                </form>
            </div>
        </div>
    </div>

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
        <h3>
            Disponibilidad para: <?php echo date('d/m/Y', strtotime($fecha)); ?>
        </h3>

        <div class="reservas-restantes">
            <?php echo $reservasDisponibles; ?>  Reservas disponibles esta semana
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
                        <td class="<?php echo isset($reservas_organizadas[$cancha['id']][$hora["inicio"]]) ? 'ocupada' : ($cancha['estado'] == 'disponible' ? 'disponible' : 'ocupada'); ?>">
                            <?php if (isset($reservas_organizadas[$cancha['id']][$hora["inicio"]])): ?>
                                Ocupada
                            <?php elseif ($cancha['estado'] == 'disponible'): ?>
                                <form method="POST" style="margin: 0;">
                                    <input type="hidden" name="cancha_id" value="<?php echo $cancha['id']; ?>">
                                    <input type="hidden" name="hora" value="<?php echo $hora["inicio"]; //Se utiliza la hora de inicio para reservar los bloques ?>">
                                    <input type="hidden" name="reservar" value="1"> 
                                    <button type="button"  class="reservar-btn" onclick="abrirVentanaFlotante(this)">Reservar</button> <!-- Se configura el boton de reservar para que active la ventana flotante -->
                                    
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
        <a href="mis_reservas.php" class="reservation-link">Ver mis reservas activas</a>
    </div>

<!-- Ventana flotante de confirmación -->
<div id="ventanaFlotante" class="ventana-flotante">
    <div class="ventana-flotante-contenido">
        <h3>Confirmar reserva</h3>
        <p>¿Estás seguro de que deseas realizar esta reserva?</p>

        <div class="ventana-flotante-acciones">
            <button id="btnConfirmarReserva" class="btn-confirmar">Sí, reservar</button>
            <button id="btnCancelarReserva" class="btn-cancelar">Cancelar</button>
        </div>
    </div>
</div>

<script>
let formularioSeleccionado = null;

//Aca se define la funcion javascript para la condiguración de la ventana flotante de confirmación
function abrirVentanaFlotante(boton) {
    formularioSeleccionado = boton.closest("form");
    document.getElementById("ventanaFlotante").style.display = "flex";
}

document.getElementById("btnCancelarReserva").addEventListener("click", () => {
    document.getElementById("ventanaFlotante").style.display = "none";
    formularioSeleccionado = null;
});

document.getElementById("btnConfirmarReserva").addEventListener("click", () => {
    if (formularioSeleccionado) {
        formularioSeleccionado.submit();
    }
});
</script>

<script> //Funcion para abrir el menú de hamburguesa
function toggleMenu() {
    document.getElementById("navMenu").classList.toggle("show");
}
</script>
</body>
</html>