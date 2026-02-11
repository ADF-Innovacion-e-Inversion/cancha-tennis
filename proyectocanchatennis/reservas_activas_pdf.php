<?php
include "config.php";

if (!isLoggedIn() || !isAdmin()) {
    header("Location: login.php");
    exit();
}

// Traer SOLO reservas activas (confirmadas desde hoy)
$stmt = $pdo->prepare("
    SELECT u.nombre AS usuario_nombre, u.apellido AS usuario_apellido,
           c.nombre AS cancha_nombre, r.hora
    FROM reservas r
    JOIN canchas c ON r.cancha_id = c.id
    JOIN usuarios u ON r.usuario_id = u.id
    WHERE r.estado = 'confirmada'
      AND r.fecha = DATE_ADD(CURDATE(), INTERVAL 1 DAY)
    ORDER BY r.hora
");
$stmt->execute();
$reservas = $stmt->fetchAll(PDO::FETCH_ASSOC);


// 1) Capturar HTML
$fechaManana = date("d/m/Y", strtotime("+1 day"));
ob_start();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; }
        h2 { margin: 0 0 8px 0; }
        .meta { margin: 0 0 12px 0; font-size: 11px; color: #333; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #000; padding: 6px; text-align: center; }
        th { background: #f2f2f2; }
    </style>
</head>
<body>
    <h2>Reservas Activas (<?php echo $fechaManana; ?>)</h2>
    <p class="meta">Generado: <?php echo date("d/m/Y H:i"); ?></p>

    <table>
        <thead>
            <tr>
                <th>Nombre</th>
                <th>Apellido</th>
                <th>Cancha</th>
                <th>Hora</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($reservas) === 0): ?>
                <tr><td colspan="4">No hay reservas activas.</td></tr>
            <?php else: ?>
                <?php foreach ($reservas as $r): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($r["usuario_nombre"]); ?></td>
                        <td><?php echo htmlspecialchars($r["usuario_apellido"]); ?></td>
                        <td><?php echo htmlspecialchars($r["cancha_nombre"]); ?></td>
                        <td><?php echo date("H:i", strtotime($r["hora"])); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</body>
</html>
<?php
$html = ob_get_clean();

// 2) Dompdf
require_once "dompdf/autoload.inc.php"; // ajusta ruta si tu dompdf está en otra carpeta

use Dompdf\Dompdf;
$dompdf = new Dompdf();

$options = $dompdf->getOptions();
$options->set(["isRemoteEnabled" => true]);
$dompdf->setOptions($options);

$dompdf->loadHtml($html);
$dompdf->setPaper("A4", "portrait");
$dompdf->render();

// 3) Nombre con fecha
$fechaNombre = date("Y-m-d", strtotime("+1 day"));
$nombrePdf = "Reservas_Activas_" . $fechaNombre . ".pdf";

// Forzar descarga:
$dompdf->stream($nombrePdf, ["Attachment" => true]);
exit();
