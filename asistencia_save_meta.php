<?php
// asistencia_save_meta.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

session_start();
require_once __DIR__.'/conexion.php';

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));

function bad($code,$msg){ http_response_code($code); echo json_encode(['ok'=>false,'message'=>$msg],JSON_UNESCAPED_UNICODE); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') bad(405,'Método no permitido');

$csrf = $_POST['csrf'] ?? '';
if (!hash_equals($_SESSION['csrf'], $csrf)) bad(401,'CSRF inválido');

$CvPerson = (int)($_POST['CvPerson'] ?? 0);
$work_date = $_POST['work_date'] ?? '';
$incidence_desc  = trim((string)($_POST['incidence_desc'] ?? ''));
$sanction_desc   = trim((string)($_POST['sanction_desc'] ?? ''));
$sanction_amount = $_POST['sanction_amount'] !== '' ? (float)$_POST['sanction_amount'] : null;

$dt = DateTime::createFromFormat('Y-m-d', $work_date);
if ($CvPerson <= 0 || !$dt || $dt->format('Y-m-d') !== $work_date) bad(400,'Datos inválidos');

try {
  $st = $conn->prepare("
    UPDATE t_attendance_day
       SET incidence_desc=:i,
           sanction_desc=:s,
           sanction_amount=:m
     WHERE CvPerson=:p AND work_date=:d
     LIMIT 1
  ");
  $st->execute([
    ':i'=>$incidence_desc !== '' ? $incidence_desc : null,
    ':s'=>$sanction_desc  !== '' ? $sanction_desc  : null,
    ':m'=>$sanction_amount,
    ':p'=>$CvPerson,
    ':d'=>$work_date
  ]);
  echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  bad(500, 'Error guardando: '.$e->getMessage());
}
