<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function enviarEmailReserva($toEmail, $toNombre, $reserva) {

    require_once __DIR__ . "/PHPMailer/PHPMailer.php";
    require_once __DIR__ . "/PHPMailer/SMTP.php";
    require_once __DIR__ . "/PHPMailer/Exception.php";

    $mail = new PHPMailer(true);
    $mail->CharSet = "UTF-8";

    // SMTP
    $mail->isSMTP();
    $mail->Host = "c2711985.ferozo.com";
    $mail->SMTPAuth = true;
    $mail->Username = "informaciones@adfii-server.cl";
    $mail->Password = "*5SmClPl";
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port = 465;

    $mail->SMTPOptions = [
    "ssl" => [
        "verify_peer" => false,
        "verify_peer_name" => false,
        "allow_self_signed" => true
    ]
    ];

    // From / To
    $mail->setFrom("informaciones@adfii-server.cl", "Club de Tennis Centenario");
    $mail->addAddress($toEmail, $toNombre);

    // Estado
    $estado = $reserva["estado"] ?? "";
    $nota = "";

    if ($estado === "confirmada") {
        $titulo = "Reserva confirmada";
        $mensajeEstado = "Se registró una reserva con el siguiente estado:";
    } elseif ($estado === "pendiente") {
        $titulo = "Reserva pendiente de confirmación";
        $mensajeEstado = "Se registró una reserva con el siguiente estado:";
        $nota = "<p style=\"color:#b00020;\">
                    Tu reserva quedó <b>PENDIENTE</b>. Será revisada por administración.
                 </p>";
    } elseif ($estado === "cancelada") {
        $titulo = "Reserva cancelada";
        $mensajeEstado = "Tu reserva fue actualizada al siguiente estado:";
        $nota = "<p style=\"color:#b00020;\">
                    Tu reserva fue <b>CANCELADA</b>.
                 </p>";
    } else {
        $titulo = "Actualización de reserva";
        $mensajeEstado = "Tu reserva fue actualizada al siguiente estado:";
    }

    $estadoTxt = strtoupper($estado);

    // Asunto / HTML
    $mail->isHTML(true);
    $mail->Subject = $titulo;

    $mail->Body = "
        <h3>{$titulo}</h3>
        <p>Hola <b>" . htmlspecialchars($toNombre) . "</b>,</p>
        <p>{$mensajeEstado} <b>{$estadoTxt}</b></p>
        {$nota}
        <hr>
        <p><b>Cancha:</b> " . htmlspecialchars($reserva["canchaNombre"] ?? "") . "</p>
        <p><b>Fecha:</b> " . htmlspecialchars($reserva["fecha"] ?? "") . "</p>
        <p><b>Hora:</b> " . htmlspecialchars($reserva["hora"] ?? "") . "</p>
        <hr>
        <p>Atte. Club de Tennis Centenario</p>
    ";

    return $mail->send();
}