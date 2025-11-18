<?php
// asistencia_save_meta_bulk.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/conexion.php';
date_default_timezone_set('America/Mexico_City');

function bad($code, $msg){
  http_response_code($code);
  echo json_encode(['ok'=>false, 'message'=>$msg], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  $raw = file_get_contents('php://input');
  $body = json_decode($raw, true);
  if (!is_array($body)) bad(400, 'JSON inválido');

  // CSRF
  $csrf = $body['csrf'] ?? '';
  if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
    bad(403, 'CSRF inválido');
  }

  $rows = $body['rows'] ?? null;
  if (!is_array($rows) || empty($rows)) bad(400, 'Sin filas a guardar');

  $upd = $conn->prepare("
    UPDATE t_attendance_day
       SET incidence_desc = :inc,
           sanction_desc  = :sdesc,
           sanction_amount= :samt
     WHERE CvPerson = :p AND work_date = :d
  ");

  $conn->beginTransaction();
  $updated = 0;

  foreach ($rows as $r) {
    $CvPerson = isset($r['CvPerson']) ? (int)$r['CvPerson'] : 0;
    $work_date = $r['work_date'] ?? '';

    if ($CvPerson <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $work_date)) {
      continue; // saltar fila inválida
    }

    // Normaliza campos (nullable)
    $inc  = isset($r['incidence_desc']) ? trim((string)$r['incidence_desc']) : null;
    $sdesc= isset($r['sanction_desc']) ? trim((string)$r['sanction_desc']) : null;
    $samt = null;
    if (isset($r['sanction_amount']) && $r['sanction_amount'] !== '' && $r['sanction_amount'] !== null) {
      $samt = (float)$r['sanction_amount'];
    }

    $upd->execute([
      ':inc'  => ($inc === '') ? null : $inc,
      ':sdesc'=> ($sdesc === '') ? null : $sdesc,
      ':samt' => $samt,
      ':p'    => $CvPerson,
      ':d'    => $work_date
    ]);

    $updated += $upd->rowCount(); // cuenta las que realmente cambiaron
  }

  $conn->commit();
  echo json_encode(['ok'=>true, 'updated'=>$updated], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  if ($conn && $conn->inTransaction()) { $conn->rollBack(); }
  bad(500, 'Error: '.$e->getMessage());
}
