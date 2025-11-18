<?php
require_once "conexion.php";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="reporte_semanal_asistencia.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, [
  'Empleado', 'Puesto', 'Semana', 'Rango de Fechas',
  'Días Laborados', 'Descansos', 'Faltas',
  'Retardos', 'Horas Trabajadas', 'Horas Extra', 'Observación'
]);


$f_emp = isset($_GET['emp']) ? (int)$_GET['emp'] : 0;
$f_del = $_GET['del'] ?? date('Y-m-d');
$f_al  = $_GET['al']  ?? date('Y-m-d');

$where = [];
$params = [':d'=>$f_del, ':a'=>$f_al];
if ($f_emp > 0) { $where[] = "CvPerson = :p"; $params[':p'] = $f_emp; }
$where[] = "fecha_inicio BETWEEN :d AND :a";

$sql = "
  SELECT 
    r.*, 
    pu.nombre AS puesto
  FROM v_resumen_asistencia_semanal r
  LEFT JOIN mdtperson mp ON mp.CvPerson = r.CvPerson
  LEFT JOIN puesto pu ON pu.id = mp.puesto_id
  " . ($where ? " WHERE " . implode(" AND ", $where) : "") . "
  ORDER BY r.anio DESC, r.semana DESC
";


$st = $conn->prepare($sql);
$st->execute($params);
while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
fputcsv($out, [
  $r['empleado'], $r['puesto'], $r['semana'], $r['rango_fechas'],
  $r['dias_laborados'], $r['dias_descanso'], $r['faltas_injustificadas'],
  $r['retardos'], $r['total_horas_trabajadas'], $r['horas_extra'], $r['observacion']
]);
}
fclose($out);
exit;
