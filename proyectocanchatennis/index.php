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

// Procesar nueva reserva
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['reservar'])) {
    $cancha_id = $_POST['cancha_id'];
    $hora = $_POST['hora'];
    
    // Validación: mínimo 3 días
    $hoy = new DateTime();           // Fecha actual
    $fechaCancha = new DateTime($fecha);
    $fechaMinima = (clone $hoy)->modify('+3 days');

    if ($fechaCancha < $fechaMinima) {
        $error = "Las reservas deben realizarse con al menos 3 días de anticipación.";
    } else {

        // 2. Validación: 1 reserva cada 24 hrs
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
                $error = "Solo puedes realizar una reserva cada 24 horas.";
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
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reserva de Canchas</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 1200px; margin: 0 auto; padding: 20px; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        /* .header h1 {text-shadow: 2px 5px 5px green}; */
        .filtro { margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: center; }
        th { background: #f8f9fa; }
        .disponible { background: #d4edda; cursor: pointer; }
        .ocupada { background: #f8d7da; }
        .reservar-btn { padding: 5px 10px; background: #007bff; color: white; border: none; border-radius: 3px; cursor: pointer; }
        .reservar-btn:hover { background: #0056b3; }
        .nav-links { margin-top: 20px; }
        .success { background: #d4edda; color: #155724; padding: 10px; border-radius: 4px; margin-bottom: 15px; }
        .error { background: #f8d7da; color: #721c24; padding: 10px; border-radius: 4px; margin-bottom: 15px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Sistema de Reserva de Canchas</h1>
        <div>
            Bienvenido, <?php echo $_SESSION['user_name']; ?> | 
            <?php if (isAdmin()): ?>
                <a href="admin.php">Administración</a> |
            <?php endif; ?>
            <a href="logout.php">Cerrar Sesión</a>
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
            <label>Filtrar por fecha:</label>
            <input type="date" name="fecha" value="<?php echo $fecha; ?>" min="<?php echo date('Y-m-d'); ?>">
            <button type="submit">Filtrar</button>
        </form>
    </div>

    <h3>Disponibilidad para: <?php echo date('d/m/Y', strtotime($fecha)); ?></h3>
    
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
                    <td><?php echo date('H:i', strtotime($hora)); ?></td>
                    <?php foreach ($canchas as $cancha): ?>
                        <td class="<?php echo isset($reservas_organizadas[$cancha['id']][$hora]) ? 'ocupada' : ($cancha['estado'] == 'disponible' ? 'disponible' : 'ocupada'); ?>">
                            <?php if (isset($reservas_organizadas[$cancha['id']][$hora])): ?>
                                Ocupada
                            <?php elseif ($cancha['estado'] == 'disponible'): ?>
                                <form method="POST" style="margin: 0;">
                                    <input type="hidden" name="cancha_id" value="<?php echo $cancha['id']; ?>">
                                    <input type="hidden" name="hora" value="<?php echo $hora; ?>">
                                    <button type="submit" name="reservar" class="reservar-btn">Reservar</button>
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
        <a href="mis_reservas.php">Ver mis reservas activas</a>
    </div>
</body>
</html>