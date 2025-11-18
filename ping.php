<?php
// api/ping.php — Verifica si un dispositivo puede conectarse correctamente
require_once __DIR__ . '/../conexion.php';
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Mexico_City');

function respond($code, $payload) {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

$apiKey = $_GET['api_key'] ?? '';
if (!$apiKey) {
  respond(400, ['ok'=>false, 'message'=>'Falta api_key']);
}

try {
  $st = $conn->prepare("SELECT id, name, allowed_ip, active FROM t_device WHERE api_key=:k LIMIT 1");
  $st->execute([':k'=>$apiKey]);
  $dev = $st->fetch(PDO::FETCH_ASSOC);

  if (!$dev) respond(401, ['ok'=>false, 'message'=>'api_key no válida']);
  if ((int)$dev['active'] !== 1) respond(403, ['ok'=>false, 'message'=>'Dispositivo inactivo']);

  $reqIp = $_SERVER['REMOTE_ADDR'] ?? null;
  $ipCheck = (!empty($dev['allowed_ip']) && $reqIp && $dev['allowed_ip'] !== $reqIp)
    ? '⚠️ IP no coincide (' . $reqIp . ')'
    : '✅ IP autorizada o no restringida';

  respond(200, [
    'ok' => true,
    'message' => 'Dispositivo activo y reconocido.',
    'device' => [
      'id' => $dev['id'],
      'name' => $dev['name'],
      'allowed_ip' => $dev['allowed_ip'],
      'estado' => $dev['active'] ? 'Activo' : 'Inactivo'
    ],
    'conexion' => [
      'fecha_servidor' => date('Y-m-d H:i:s'),
      'ip_origen' => $reqIp,
      'verificacion_ip' => $ipCheck
    ]
  ]);
} catch (Throwable $e) {
  respond(500, ['ok'=>false, 'message'=>'Error: '.$e->getMessage()]);
}
