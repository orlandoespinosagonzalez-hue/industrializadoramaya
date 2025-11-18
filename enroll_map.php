<?php
// /api/enroll_map.php — API para enrolar huellas UID → empleado
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Mexico_City');

require_once __DIR__ . '/../conexion.php';

function respond(int $code, array $payload): void {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  respond(405, ['ok' => false, 'error' => 'Método no permitido. Usa POST JSON.']);
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
  respond(400, ['ok' => false, 'error' => 'JSON inválido.']);
}

$apiKey   = trim((string)($data['api_key'] ?? ''));
$fp_uid   = trim((string)($data['fp_uid'] ?? ''));
$CvPerson = (int)($data['CvPerson'] ?? 0);

if ($apiKey === '' || $fp_uid === '' || $CvPerson <= 0) {
  respond(400, ['ok' => false, 'error' => 'Campos requeridos: api_key, fp_uid, CvPerson.']);
}

try {
  $st = $conn->prepare("SELECT id, allowed_ip, active FROM t_device WHERE api_key=:k LIMIT 1");
  $st->execute([':k' => $apiKey]);
  $dev = $st->fetch(PDO::FETCH_ASSOC);

  if (!$dev) respond(401, ['ok' => false, 'error' => 'api_key no válida.']);
  if ((int)$dev['active'] !== 1) respond(403, ['ok' => false, 'error' => 'Dispositivo inactivo.']);

  $reqIp = $_SERVER['REMOTE_ADDR'] ?? null;
  if (!empty($dev['allowed_ip']) && $reqIp && $dev['allowed_ip'] !== $reqIp) {
    $permitidas = ['127.0.0.1', '::1']; // modo prueba
    if (!in_array($reqIp, $permitidas)) {
      respond(403, ['ok' => false, 'error' => "IP no autorizada ($reqIp)."]);
    }
  }

  $chk = $conn->prepare("SELECT 1 FROM mdtperson WHERE CvPerson=:p LIMIT 1");
  $chk->execute([':p' => $CvPerson]);
  if (!$chk->fetchColumn()) {
    respond(404, ['ok' => false, 'error' => 'Empleado no encontrado.']);
  }

$up = $conn->prepare("
  INSERT INTO t_fingerprint_map (device_id, fp_uid, CvPerson, created_at)
  VALUES (:d, :u, :p, NOW())
  ON DUPLICATE KEY UPDATE 
    CvPerson = VALUES(CvPerson)
");
$up->execute([
  ':d' => $dev['id'],
  ':u' => $fp_uid,
  ':p' => $CvPerson
]);


  respond(200, [
    'ok' => true,
    'device_id' => (int)$dev['id'],
    'inserted' => [
      [
        'CvPerson' => $CvPerson,
        'fp_uid' => $fp_uid,
        'device_ip' => $reqIp,
        'ts' => date('Y-m-d H:i:s')
      ]
    ],
    'errors' => [],
    'message' => 'Huella registrada correctamente.'
  ]);

} catch (Throwable $e) {
  respond(500, ['ok' => false, 'error' => 'Error del servidor: '.$e->getMessage()]);
}
