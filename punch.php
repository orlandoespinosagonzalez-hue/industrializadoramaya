<?php
// /api/punch.php — API de registro de marcajes (entradas/salidas)
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
if (!is_array($data)) respond(400, ['ok' => false, 'error' => 'JSON inválido.']);

$apiKey = trim((string)($data['api_key'] ?? ''));
if ($apiKey === '') respond(400, ['ok' => false, 'error' => 'Falta api_key.']);

// === Validar dispositivo ===
$st = $conn->prepare("SELECT id, allowed_ip, active FROM t_device WHERE api_key=:k LIMIT 1");
$st->execute([':k' => $apiKey]);
$dev = $st->fetch(PDO::FETCH_ASSOC);
if (!$dev) respond(401, ['ok' => false, 'error' => 'api_key no válida.']);
if ((int)$dev['active'] !== 1) respond(403, ['ok' => false, 'error' => 'Dispositivo inactivo.']);

$reqIp = $_SERVER['REMOTE_ADDR'] ?? null;

// Si la IP permitida no coincide, permitir localhost en modo prueba
if (!empty($dev['allowed_ip']) && $reqIp && $dev['allowed_ip'] !== $reqIp) {
    $permitidasPrueba = ['127.0.0.1', '::1'];
    if (!in_array($reqIp, $permitidasPrueba)) {
        respond(403, [
            'ok' => false,
            'error' => "IP no autorizada ({$reqIp})."
        ]);
    }
}

$deviceId = (int)$dev['id'];

// === Normalizar eventos ===
$events = $data['punches'] ?? ($data['events'] ?? [$data]);
if (!is_array($events) || empty($events)) {
  respond(400, ['ok' => false, 'error' => 'No hay eventos válidos.']);
}

$ins = $conn->prepare("
  INSERT INTO t_punch (CvPerson, ts, kind, source, device_id, ip, lat, lng, notes)
  VALUES (:p, :ts, :k, 'device', :d, :ip, :lat, :lng, :notes)
");
$map = $conn->prepare("SELECT CvPerson FROM t_fingerprint_map WHERE device_id=:d AND fp_uid=:u LIMIT 1");
$proc = $conn->prepare("CALL sp_process_attendance(:d1,:d2,:p)");

$inserted = [];
$errors = [];

foreach ($events as $i => $ev) {
  try {
    $kind = strtolower(trim((string)($ev['kind'] ?? 'in')));
    if (!in_array($kind, ['in', 'out'], true)) throw new Exception("kind inválido (in|out)");

    $ts = $ev['ts'] ?? date('Y-m-d H:i:s');
    $dt = date_create($ts);
    if (!$dt) throw new Exception('ts inválido');
    $tsStr = $dt->format('Y-m-d H:i:s');
    $day = $dt->format('Y-m-d');

    // Resolver empleado
    $CvPerson = (int)($ev['CvPerson'] ?? 0);
    if ($CvPerson <= 0) {
      $fp_uid = trim((string)($ev['fp_uid'] ?? ''));
      if ($fp_uid === '') throw new Exception('Falta CvPerson o fp_uid');
      $map->execute([':d' => $deviceId, ':u' => $fp_uid]);
      $CvPerson = (int)$map->fetchColumn();
      if ($CvPerson <= 0) throw new Exception('UID no enrolado en este dispositivo.');
    }

    // Insertar punch
    $ins->execute([
      ':p' => $CvPerson,
      ':ts' => $tsStr,
      ':k' => $kind,
      ':d' => $deviceId,
      ':ip' => $reqIp,
      ':lat' => (float)($ev['lat'] ?? 0),
      ':lng' => (float)($ev['lng'] ?? 0),
      ':notes' => mb_substr((string)($ev['notes'] ?? ''), 0, 250)
    ]);

    $lastId = (int)$conn->lastInsertId();

    // Procesar asistencia
    $proc->execute([
      ':d1' => date('Y-m-d', strtotime("$day -1 day")),
      ':d2' => $day,
      ':p'  => $CvPerson
    ]);

    $inserted[] = [
      'idx' => $i,
      'id' => $lastId,
      'CvPerson' => $CvPerson,
      'ts' => $tsStr,
      'kind' => $kind
    ];

  } catch (Throwable $e) {
    $errors[] = ['idx' => $i, 'error' => $e->getMessage()];
  }
}

respond(empty($errors) ? 200 : 207, [
  'ok' => empty($errors),
  'device_id' => $deviceId,
  'inserted' => $inserted,
  'errors' => $errors
]);
