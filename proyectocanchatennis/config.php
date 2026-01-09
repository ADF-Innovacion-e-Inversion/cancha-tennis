<?php
session_start();

date_default_timezone_set("America/Santiago"); //Esto es para que concorden las zonas horarias
// Configuración de la base de datos
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'reserva_canchas');

// Conexión a la base de datos
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("ERROR: No se pudo conectar. " . $e->getMessage());
}

// Función para verificar si el usuario está logueado
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Función para verificar si es administrador
function isAdmin() {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin';
}

// Función para obtener horas disponibles, las horas se guardan como array para poder almacenar su hora de inicio y de fin
function getHorasDisponibles() {
    return [
        [
            "inicio" => "07:30:00",
            "fin"    => "08:30:00"
        ],
        [
            "inicio" => "09:00:00",
            "fin"    => "10:30:00"
        ],
        [
            "inicio" => "11:00:00",
            "fin"    => "12:30:00"
        ],
        [
            "inicio" => "18:00:00",
            "fin"    => "19:30:00"
        ],
        [
            "inicio" => "20:00:00",
            "fin"    => "21:30:00"
        ],
        [
            "inicio" => "22:00:00",
            "fin"    => "23:30:00"
        ]
    ];
}

// Función para mostrar los bloques con sus horas de inicio y fin respectivas
function obtenerBloqueHora($horaInicio) {
    foreach (getHorasDisponibles() as $bloque) {
        if ($bloque["inicio"] === $horaInicio) {
            return $bloque;
        }
    }
    return null;
}
?>