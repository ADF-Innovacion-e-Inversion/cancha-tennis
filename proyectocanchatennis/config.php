<?php
session_start();

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

// Función para obtener horas disponibles
function getHorasDisponibles() {
    return [
        '08:00:00', '09:00:00', '10:00:00', '11:00:00', 
        '12:00:00', '13:00:00', '14:00:00', '15:00:00', 
        '16:00:00', '17:00:00', '18:00:00', '19:00:00'
    ];
}
?>