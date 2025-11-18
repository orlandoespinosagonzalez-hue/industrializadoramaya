<?php
// asistencia_export_excel.php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__.'/conexion.php';
date_default_timezone_set('America/Mexico_City');

/* ====== Función de traducción ====== */
function traducirEstado($estado) {
  $map = [
    'PRESENT'   => 'PRESENTE',
    'LATE'      => 'RETARDO',
    'ABSENT'    => 'AUSENTE',
    'ON_LEAVE'  => 'PERMISO',
    'HOLIDAY'   => 'FESTIVO',
    'REST'      => 'DESCANSO'
  ];
  $estado = strtoupper(trim($estado));
  return $map[$estado] ?? $estado;
}

/* ====== Parámetros ====== */
$emp = isset($_GET['emp']) ? (int)$_GET['emp'] : 0;
$del = $_GET['del'] ?? date('Y-m-01');
$al  = $_GET['al']  ?? date('Y-m-t');

/* ====== Validaciones ====== */
$valid = function($d){
  $dt = DateTime::createFromFormat('Y-m-d', $d);
  return $dt && $dt->format('Y-m-d') === $d;
};
if (!$valid($del)) $del = date('Y-m-01');
if (!$valid($al))  $al  = date('Y-m-t');
if ($del > $al) { $tmp = $del; $del = $al; $al = $tmp; }

/* ====== Consulta ====== */
$where  = ["a.work_date BETWEEN :d AND :a"];
$params = [':d'=>$del, ':a'=>$al];
if ($emp>0){ $where[]="a.CvPerson=:p"; $params[':p']=$emp; }

$sql = "
  SELECT 
    a.CvPerson,
    CONCAT(n.DsNombre,' ', ap1.DsApellido,' ', ap2.DsApellido) AS empleado,
    a.work_date,
    a.first_in, a.last_out,
    a.work_minutes, a.overtime_minutes, a.late_minutes, a.early_leave_minutes,
    a.status,
    a.incidence_desc, a.sanction_desc, a.sanction_amount,
    -- Campos equivalentes a los que se ven en pantalla
    CASE WHEN (a.work_minutes > 0 OR a.status IN ('present','late')) THEN 'Sí' ELSE '—' END AS trabajo,
    CASE WHEN (a.late_minutes > 0 OR a.status = 'late') THEN 'Sí' ELSE '—' END AS retardo,
    CASE WHEN a.status = 'absent' THEN 'Sí' ELSE '—' END AS ausente,
    CASE WHEN a.status = 'on_leave' THEN 'Sí' ELSE '—' END AS permiso,
    CASE WHEN a.status = 'rest' THEN 'Sí' ELSE '—' END AS descanso
  FROM t_attendance_day a
  JOIN mdtperson p   ON p.CvPerson=a.CvPerson
  JOIN cnombre n     ON n.CvNombre=p.CvNombre
  JOIN capellido ap1 ON ap1.CvApellido=p.CvApePat
  JOIN capellido ap2 ON ap2.CvApellido=p.CvApeMat
  WHERE ".implode(" AND ",$where)."
  ORDER BY empleado, a.work_date
";

$st=$conn->prepare($sql);
$st->execute($params);
$rows=$st->fetchAll(PDO::FETCH_ASSOC);

/* ====== Nombre del archivo ====== */
$fname = "asistencia_{$del}_{$al}" . ($emp>0?"_emp{$emp}":"") . ".xls";

/* ====== Cabeceras para Excel ====== */
header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"{$fname}\"");
header("Pragma: no-cache");
header("Expires: 0");

/* ====== Salida HTML compatible con Excel ====== */
echo '<meta charset="UTF-8">';
echo '<table border="1" cellspacing="0" cellpadding="5">';
echo '<thead style="background-color:#800020;color:white;font-weight:bold;">';
echo '<tr>
        <th>Código</th>
        <th>Empleado</th>
        <th>Fecha</th>
        <th>Primera entrada</th>
        <th>Última salida</th>
        <th>Min. trabajados</th>
        <th>Min. extra</th>
        <th>Min. retardo</th>
        <th>Min. salida anticipada</th>
        <th>Estado</th>
        <th>Trabajó</th>
        <th>Retardo</th>
        <th>Ausente</th>
        <th>Permiso</th>
        <th>Descanso</th>
        <th>Incidencia</th>
        <th>Sanción</th>
        <th>Monto</th>
      </tr>';
echo '</thead><tbody>';

foreach ($rows as $r) {
  echo '<tr>';
  echo '<td>'.htmlspecialchars($r['CvPerson']).'</td>';
  echo '<td>'.htmlspecialchars($r['empleado']).'</td>';
  echo '<td>'.htmlspecialchars($r['work_date']).'</td>';
  echo '<td>'.htmlspecialchars($r['first_in']).'</td>';
  echo '<td>'.htmlspecialchars($r['last_out']).'</td>';
  echo '<td>'.(int)$r['work_minutes'].'</td>';
  echo '<td>'.(int)$r['overtime_minutes'].'</td>';
  echo '<td>'.(int)$r['late_minutes'].'</td>';
  echo '<td>'.(int)$r['early_leave_minutes'].'</td>';
  echo '<td>'.htmlspecialchars(traducirEstado($r['status']), ENT_QUOTES, 'UTF-8').'</td>';
  echo '<td>'.$r['trabajo'].'</td>';
  echo '<td>'.$r['retardo'].'</td>';
  echo '<td>'.$r['ausente'].'</td>';
  echo '<td>'.$r['permiso'].'</td>';
  echo '<td>'.$r['descanso'].'</td>';
  echo '<td>'.htmlspecialchars($r['incidence_desc']).'</td>';
  echo '<td>'.htmlspecialchars($r['sanction_desc']).'</td>';
  echo '<td>'.htmlspecialchars($r['sanction_amount']).'</td>';
  echo '</tr>';
}

echo '</tbody></table>';
exit;
?>
