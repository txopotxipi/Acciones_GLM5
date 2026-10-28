<?php
// config.example.php — Plantilla de configuración de base de datos
// Renombrar a config.php y configurar con tus credenciales reales

$host = 'TU_HOST_MYSQL';
$db   = 'TU_BASE_DE_DATOS';
$user = 'TU_USUARIO_MYSQL';
$pass = 'TU_PASSWORD_MYSQL';
$charset = 'utf8mb4';

// CLAVE INTERNA PARA PROTEGER ENDPOINTS AJAX, CRON Y ACCESO A LA API
define('INTERNAL_API_KEY', 'TU_CLAVE_SECRETA_AQUI');

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die("Error de conexión a la base de datos.");
}
?>
