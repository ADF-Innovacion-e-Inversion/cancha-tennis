<?php
ini_set("display_errors", 1);
ini_set("display_startup_errors", 1);
error_reporting(E_ALL);

/*
  Webpay Plus (Integración / Pruebas) - Demo en 1 solo archivo
  Uso:
    - Abre: http://localhost/tu_ruta/webpay_demo.php
    - "init" crea transacción y redirige a Webpay
    - Webpay vuelve a este mismo archivo con POST token_ws a ?action=commit
*/

// ========= CONFIG PRUEBAS =========
// Deja tus llaves aquí si estás en entorno de pruebas (no recomendado en prod).
$TBK_API_KEY_ID     = "597055555532";
$TBK_API_KEY_SECRET = "579B532A7440BB0C9079DED94D31EA1615BACEB56610332264630D42D0A36B1C";

// Endpoint integración
$BASE_TEST = "https://webpay3gint.transbank.cl";

// Construye URL base del archivo actual (http/https) sin querystring
$scheme  = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";
$baseSelf = $scheme . "://" . $_SERVER["HTTP_HOST"] . strtok($_SERVER["REQUEST_URI"], "?");

// Acción
$action = $_GET["action"] ?? "init";

// ==============================
// Helper para llamar API Transbank
// ==============================
function tbk_request($base, $endpoint, $method, $apiKeyId, $apiKeySecret, $payload = null) {
    $ch = curl_init($base . $endpoint);

    $headers = [
        "Tbk-Api-Key-Id: " . $apiKeyId,
        "Tbk-Api-Key-Secret: " . $apiKeySecret,
        "Content-Type: application/json",
    ];

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);

    // Método
    if ($method === "POST") {
        curl_setopt($ch, CURLOPT_POST, true);
    } else {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    }

    // Body (si aplica)
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ["ok" => false, "http" => 0, "body" => null, "raw_body" => null, "error" => $err];
    }

    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsz  = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $bodyStr = substr($raw, $hsz);
    $json = json_decode($bodyStr, true);

    return [
        "ok" => ($http >= 200 && $http < 300),
        "http" => $http,
        "body" => $json,
        "raw_body" => $bodyStr,
        "error" => null
    ];
}

// ==============================
// INIT: crear transacción y redirigir
// ==============================
if ($action === "init") {

    $buyOrder  = "ORDER_" . time();     // Único
    $sessionId = "SESS_" . time();      // Demo
    $amount    = 15000;                // Monto demo

    $returnUrl = $baseSelf . "?action=commit";

    $payload = json_encode([
        "buy_order" => $buyOrder,
        "session_id" => $sessionId,
        "amount" => $amount,
        "return_url" => $returnUrl
    ], JSON_UNESCAPED_SLASHES);

    $res = tbk_request(
        $BASE_TEST,
        "/rswebpaytransaction/api/webpay/v1.0/transactions",
        "POST",
        $TBK_API_KEY_ID,
        $TBK_API_KEY_SECRET,
        $payload
    );

    if (!$res["ok"] || !is_array($res["body"])) {
        echo "<h2>Error creando transacción</h2>";
        echo "<p>HTTP: " . (int)$res["http"] . "</p>";
        echo "<pre>" . htmlspecialchars($res["raw_body"] ?? $res["error"] ?? "sin detalle") . "</pre>";
        exit;
    }

    $url = $res["body"]["url"] ?? null;
    $token = $res["body"]["token"] ?? null;

    if (!$url || !$token) {
        echo "<h2>Respuesta inesperada</h2>";
        echo "<pre>" . htmlspecialchars($res["raw_body"]) . "</pre>";
        exit;
    }

    // Redirigir a Webpay por POST
    ?>
    <!doctype html>
    <html lang="es">
    <head>
        <meta charset="utf-8">
        <title>Redirigiendo a Webpay...</title>
    </head>
    <body>
        <p>Redirigiendo a Webpay (pruebas)...</p>
        <form id="tbkForm" method="post" action="<?php echo htmlspecialchars($url); ?>">
            <input type="hidden" name="token_ws" value="<?php echo htmlspecialchars($token); ?>">
            <button type="submit">Continuar</button>
        </form>
        <script>document.getElementById("tbkForm").submit();</script>
    </body>
    </html>
    <?php
    exit;
}

// ==============================
// COMMIT: Webpay vuelve con token_ws y confirmas
// ==============================
if ($action === "commit") {

    echo "<h2>Retorno Webpay (commit) - Pruebas</h2>";

    echo "<h3>POST recibido</h3>";
    echo "<pre>";
    print_r($_POST);
    echo "</pre>";

    $token = $_POST["token_ws"] ?? null;

    // Si no viene token_ws, el usuario puede haber cancelado o hubo error
    if (!$token) {
        echo "<p><b>No llegó token_ws.</b> Posible cancelación o error.</p>";
        echo '<p><a href="' . htmlspecialchars($baseSelf) . '">Intentar de nuevo</a></p>';
        exit;
    }

    $endpoint = "/rswebpaytransaction/api/webpay/v1.0/transactions/" . urlencode($token);

    $res = tbk_request(
        $BASE_TEST,
        $endpoint,
        "PUT",
        $TBK_API_KEY_ID,
        $TBK_API_KEY_SECRET,
        "" // PUT sin body
    );

    echo "<h3>Respuesta commit</h3>";
    echo "<p>HTTP: " . (int)$res["http"] . "</p>";
    echo "<pre>";
    print_r($res["body"]);
    echo "</pre>";

    // Interpretación básica
    $status = $res["body"]["status"] ?? null;
    $responseCode = $res["body"]["response_code"] ?? null;

    echo "<h3>Interpretación</h3>";
    if ($res["ok"] && $status === "AUTHORIZED" && $responseCode === 0) {
        echo "<p><b>Pago APROBADO</b></p>";
    } else {
        echo "<p><b>Pago NO aprobado / cancelado / error</b></p>";
    }

    echo '<p><a href="' . htmlspecialchars($baseSelf) . '">Crear nueva transacción</a></p>';
    exit;
}

echo "Acción no válida.";
