<?php
include 'config.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

// Obtener reservas del usuario
$stmt = $pdo->prepare("
    SELECT r.*, c.nombre as cancha_nombre 
    FROM reservas r 
    JOIN canchas c ON r.cancha_id = c.id 
    WHERE r.usuario_id = ? AND r.estado = 'confirmada' 
    ORDER BY r.fecha, r.hora
");
$stmt->execute([$_SESSION['user_id']]);
$reservas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Cancelar reserva
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['cancelar_reserva'])) {
    $reserva_id = $_POST['reserva_id'];
    
    $stmt = $pdo->prepare("UPDATE reservas SET estado = 'cancelada' WHERE id = ? AND usuario_id = ?");
    if ($stmt->execute([$reserva_id, $_SESSION['user_id']])) {
        header('Location: mis_reservas.php?success=1');
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Reservas</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 1000px; margin: 0 auto; padding: 20px; background-color: #fbeedbff;}
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; background-color: #f5f5f5;}
        th, td { border: 1px solid #ddd; padding: 10px; text-align: center; }
        th { background: #f8f9fa; }
        .success { background: #d4edda; color: #155724; padding: 10px; border-radius: 4px; margin-bottom: 15px; }
        .btn { padding: 5px 10px; border: none; border-radius: 3px; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-danger { background: #dc3545; color: white; }
        .logout-link:hover{opacity: 0.5;}
        .logout-link {
            color: #1d6cd2ff;
            font-size: 18px;
            font-weight: bold;
        }
        .logout-link:visited {color: #1d6cd2ff;}
        .Main-link:hover{opacity: 0.5;}
        .Main-link {
            color: #1d6cd2ff;
            font-size: 18px;
            font-weight: bold;
        }
        .Main-link:visited {color: #1d6cd2ff;}
        .vacio {
            font-size: 20px;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Mis Reservas</h1>
        <div>
            <a href="index.php" class="Main-link">Volver al Sistema</a> |
            <a href="logout.php" class="logout-link">Cerrar Sesión</a>
        </div>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="success">Reserva cancelada correctamente</div>
    <?php endif; ?>

    <?php if (count($reservas) > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>Cancha</th>
                    <th>Fecha</th>
                    <th>Hora</th>
                    <th>Fecha de Reserva</th>
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
    <?php else: ?>
        <p class="vacio">No tienes reservas activas.</p>
    <?php endif; ?>
</body>
</html>