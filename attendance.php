<?php
require_once "conexion.php";
$dia = $_GET['d'] ?? date('Y-m-d');
if (isset($_GET['recalc'])) {
  $conn->prepare("CALL sp_process_attendance(:d,:d,NULL)")->execute([':d'=>$dia]);
}
$rows = $conn->prepare("
  SELECT a.*, e.empleado
  FROM t_attendance_day a
  JOIN vw_empleados e ON e.CvPerson=a.CvPerson
  WHERE a.work_date=:d
  ORDER BY e.empleado
");
$rows->execute([':d'=>$dia]);
$rows = $rows->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html><html lang="es"><meta charset="utf-8"><title>Asistencia <?=$dia?></title>
<body>
  <h2>Asistencia <?=$dia?></h2>
  <a href="?d=<?=$dia?>&recalc=1">Recalcular</a>
  <table border="1" cellpadding="6">
    <tr>
      <th>Empleado</th><th>Estado</th><th>1a entrada</th><th>Última salida</th>
      <th>Trab. (min)</th><th>Retardo</th><th>Salida ant.</th><th>Extra</th>
    </tr>
    <?php foreach($rows as $r): ?>
    <tr>
      <td><?=htmlspecialchars($r['empleado'])?></td>
      <td><?=$r['status']?></td>
      <td><?=$r['first_in']?></td>
      <td><?=$r['last_out']?></td>
      <td><?=$r['work_minutes']?></td>
      <td><?=$r['late_minutes']?></td>
      <td><?=$r['early_leave_minutes']?></td>
      <td><?=$r['overtime_minutes']?></td>
    </tr>
    <?php endforeach; ?>
  </table>
</body></html>
