<?php
try {
    $conn = new PDO(
        "mysql:host=localhost;dbname=amaya_rrhh;charset=utf8mb4",
        "root",
        "",
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false, // MUY IMPORTANTE
        ]
    );
    // Por si el server ignora el charset del DSN
    $conn->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}
