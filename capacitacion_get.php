<?php
// capacitacion_get.php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/conexion.php';

id:
$id   = (int)($_GET['id'] ?? 0);
$mode = $_GET['mode'] ?? 'download';  // 'inline' | 'download'
if ($id<=0) { http_response_code(404); exit('Not found'); }

$st = $conn->prepare("SELECT * FROM capacitacion_archivos WHERE id=:id AND is_deleted=0");
$st->execute([':id'=>$id]);
$r = $st->fetch(PDO::FETCH_ASSOC);
if (!$r) { http_response_code(404); exit('Not found'); }

$file = __DIR__ . '/uploads/capacitacion/' . $r['stored_name'];
if (!is_file($file)) { http_response_code(404); exit('Missing file'); }

$mime = $r['mime_type'] ?: 'application/octet-stream';
$dispo = ($mode==='inline' ? 'inline' : 'attachment');
header('Content-Type: '.$mime);
header('Content-Length: '.filesize($file));
header('Content-Disposition: '.$dispo.'; filename="'.rawurlencode($r['original_name']).'"');
header('X-Content-Type-Options: nosniff');

// incrementa descargas solo en modo download
if ($mode !== 'inline') {
  $conn->prepare("UPDATE capacitacion_archivos SET download_count=download_count+1 WHERE id=:id")->execute([':id'=>$id]);
}

$fp=fopen($file,'rb');
fpassthru($fp);
