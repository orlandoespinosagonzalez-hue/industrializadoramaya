<?php
// seed_user.php
require_once "conexion.php"; // $conn (PDO)

try {
  $conn->exec("
    CREATE TABLE IF NOT EXISTS app_user (
      id INT AUTO_INCREMENT PRIMARY KEY,
      username VARCHAR(60) NOT NULL UNIQUE,
      password_hash VARCHAR(255) NOT NULL,
      role ENUM('admin','user') NOT NULL DEFAULT 'admin',
      is_active TINYINT NOT NULL DEFAULT 1,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");

  // Si no existe 'amaya', lo creamos con pass maya123
  $st = $conn->prepare("SELECT COUNT(*) FROM app_user WHERE username = :u");
  $st->execute([':u'=>'amaya']);
  if ((int)$st->fetchColumn() === 0) {
    $hash = password_hash('maya123', PASSWORD_DEFAULT);
    $ins = $conn->prepare("INSERT INTO app_user (username, password_hash, role) VALUES (:u, :h, 'admin')");
    $ins->execute([':u'=>'amaya', ':h'=>$hash]);
    echo "OK: Usuario 'amaya' creado con contraseña 'maya123'.";
  } else {
    echo "Aviso: El usuario 'amaya' ya existe. No se hizo nada.";
  }
} catch (Exception $e) {
  http_response_code(500);
  echo "Error: ".$e->getMessage();
}
