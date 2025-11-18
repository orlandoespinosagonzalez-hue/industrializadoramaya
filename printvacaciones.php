<?php
// print_vacaciones.php
session_start();
require_once __DIR__ . '/conexion.php';

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
  SELECT 
    v.id, 
    e.empleado,
    CONCAT(n2.DsNombre,' ', ap12.DsApellido,' ', ap22.DsApellido) AS posturero,
    v.fecha_inicio, 
    v.fecha_fin, 
    v.estado,
    fn_anios_laborados(v.CvPerson) AS anios,
    fn_dias_vacaciones(fn_anios_laborados(v.CvPerson)) AS dias_derecho
  FROM mvacaciones v
  JOIN vw_empleados e ON e.CvPerson = v.CvPerson
  LEFT JOIN mdtperson pp   ON pp.CvPerson = v.CvPosturero
  LEFT JOIN cnombre n2     ON n2.CvNombre = pp.CvNombre
  LEFT JOIN capellido ap12 ON ap12.CvApellido = pp.CvApePat
  LEFT JOIN capellido ap22 ON ap22.CvApellido = pp.CvApeMat
";
if ($where) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY v.id DESC";




try {
  $st = $conn->prepare($sql);
  $st->execute($params);
  $listado = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  $listado = [];
  $err = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Imprimir — Vacaciones</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css"/>
<link rel="stylesheet" href="css/print_vacaciones.css">

</head>
<body>

<div class="header" style="border-bottom:3px solid var(--vino); text-align:center;">
  <div style="text-align:center; width:100%;">
    <h2 style="color:var(--vino); font-weight:700; margin:0;">Industrializadora Maya S.A. de C.V.</h2>
    <h3 style="margin:4px 0 0;">Reporte de Vacaciones</h3>
<?php
// ✅ Establece la zona horaria de Monterrey o CDMX
if (function_exists('date_default_timezone_set')) {
  date_default_timezone_set('America/Monterrey'); // o 'America/Mexico_City'
}
$fecha_impresion = date('d/m/Y, h:i a');
?>
<p style="margin:2px 0; font-size:13px; color:#555;">
  Fecha de impresión: <?= htmlspecialchars($fecha_impresion, ENT_QUOTES, 'UTF-8') ?>
</p>
  </div>
  <div class="actions" style="position:absolute; top:15px; right:20px;">
    <a class="btn" href="#" onclick="window.print(); return false;"><i class="fas fa-print"></i> Imprimir</a>
    <?php
      $qs = http_build_query(['emp'=>$f_emp?:null, 'estado'=>$f_est?:null, 'del'=>$f_del?:null, 'al'=>$f_al?:null]);
      $qs = $qs ? ('?'.$qs) : '';
    ?>
    <a class="btn" href="export_vacaciones.php<?= htmlspecialchars($qs) ?>&format=csv"><i class="fas fa-file-csv"></i> CSV</a>
    <a class="btn" href="export_vacaciones.php<?= htmlspecialchars($qs) ?>&format=xlsx"><i class="fas fa-file-excel"></i> Excel</a>
    <a class="btn" href="vacacionesMaya.php"><i class="fas fa-arrow-left"></i> Volver</a>
  </div>
</div>


<div class="container">
  <?php if (!empty($err)): ?>
    <p style="color:#b00;">Error: <?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></p>
  <?php endif; ?>

<table>
  <thead>
    <tr>
      <th>#</th>
      <th>Empleado</th>
      <th>Posturero</th>
      <th>Años</th>
      <th>Derecho (días)</th>
      <th>Fecha Inicio</th>
      <th>Fecha Fin</th>
      <th>Estado</th>
    </tr>
  </thead>
  <tbody>
    <?php if (empty($listado)): ?>
      <tr><td colspan="8">Sin registros.</td></tr>
    <?php else: foreach ($listado as $r): ?>
      <tr>
        <td><?= (int)$r['id'] ?></td>
        <td><?= htmlspecialchars($r['empleado'], ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars($r['posturero'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= (int)$r['anios'] ?></td>
        <td><?= (int)$r['dias_derecho'] ?></td>
        <td><?= htmlspecialchars($r['fecha_inicio']) ?></td>
        <td><?= htmlspecialchars($r['fecha_fin']) ?></td>
        <td><?= htmlspecialchars($r['estado']) ?></td>
      </tr>
    <?php endforeach; endif; ?>
  </tbody>
</table>


</div>
</body>
</html>
