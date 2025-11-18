<?php
// export_vacaciones.php
session_start();
require_once __DIR__ . '/conexion.php';

$format = isset($_GET['format']) ? strtolower($_GET['format']) : 'csv';
$filename = "vacaciones_" . date('Ymd_His');

// Filtros GET
$f_emp  = isset($_GET['emp'])   ? (int)$_GET['emp'] : 0;
$f_est  = isset($_GET['estado'])? trim($_GET['estado']) : '';
$f_del  = isset($_GET['del'])   ? trim($_GET['del']) : '';
$f_al   = isset($_GET['al'])    ? trim($_GET['al'])  : '';

$validDate = function($d){
  $dt = DateTime::createFromFormat('Y-m-d', $d);
  return $dt && $dt->format('Y-m-d') === $d;
};
if ($f_del && !$validDate($f_del)) $f_del = '';
if ($f_al  && !$validDate($f_al))  $f_al  = '';

$where = [];
$params = [];

if ($f_emp > 0) { $where[] = 'v.CvPerson = :emp'; $params[':emp'] = $f_emp; }
if ($f_est !== '') { $where[] = 'v.estado = :est'; $params[':est'] = $f_est; }

if ($f_del && $f_al) {
  $where[] = '(v.fecha_inicio <= :al AND v.fecha_fin >= :del)';
  $params[':del'] = $f_del; $params[':al'] = $f_al;
} elseif ($f_del) {
  $where[] = 'v.fecha_fin >= :del';
  $params[':del'] = $f_del;
} elseif ($f_al) {
  $where[] = 'v.fecha_inicio <= :al';
  $params[':al'] = $f_al;
}

$sql = "
  SELECT v.id, e.empleado, v.fecha_inicio, v.fecha_fin, v.estado,
         fn_anios_laborados(v.CvPerson) AS anios,
         fn_dias_vacaciones(fn_anios_laborados(v.CvPerson)) AS dias_derecho
  FROM mvacaciones v
  JOIN vw_empleados e ON e.CvPerson = v.CvPerson
";
if ($where) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY v.id DESC";

try {
  $st = $conn->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  http_response_code(500);
  echo "Error consultando datos: ".htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8');
  exit;
}

$headers = ['#','Empleado','Años','Derecho (días)','Fecha de Inicio','Fecha de Fin','Estado'];

// XLSX si tienes PhpSpreadsheet
if ($format === 'xlsx' && file_exists(__DIR__ . '/vendor/autoload.php')) {
  require __DIR__ . '/vendor/autoload.php';
  $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
  $sheet = $spreadsheet->getActiveSheet();

  $col=1; foreach ($headers as $h){ $sheet->setCellValueByColumnAndRow($col++,1,$h); }
  $r=2; foreach($rows as $row){
    $sheet->setCellValueByColumnAndRow(1,$r,(int)$row['id']);
    $sheet->setCellValueByColumnAndRow(2,$r,$row['empleado']);
    $sheet->setCellValueByColumnAndRow(3,$r,(int)$row['anios']);
    $sheet->setCellValueByColumnAndRow(4,$r,(int)$row['dias_derecho']);
    $sheet->setCellValueByColumnAndRow(5,$r,$row['fecha_inicio']);
    $sheet->setCellValueByColumnAndRow(6,$r,$row['fecha_fin']);
    $sheet->setCellValueByColumnAndRow(7,$r,$row['estado']);
    $r++;
  }
  foreach (range('A','G') as $c) $sheet->getColumnDimension($c)->setAutoSize(true);
  header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
  header('Content-Disposition: attachment; filename="'.$filename.'.xlsx"');
  header('Cache-Control: max-age=0');
  (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
  exit;
}

// CSV (fallback)
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$filename.'.csv"');
$out = fopen('php://output', 'w');
fwrite($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8
fputcsv($out, $headers);
foreach ($rows as $row) {
  fputcsv($out, [
    (int)$row['id'],
    $row['empleado'],
    (int)$row['anios'],
    (int)$row['dias_derecho'],
    $row['fecha_inicio'],
    $row['fecha_fin'],
    $row['estado'],
  ]);
}
fclose($out);
exit;
