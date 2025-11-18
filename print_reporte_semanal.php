<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
require_once "conexion.php";
date_default_timezone_set('America/Mexico_City');

/* ====== FILTROS RECIBIDOS ====== */
$f_del = $_GET['del'] ?? date('Y-m-d');
$f_al  = $_GET['al']  ?? date('Y-m-d');
$f_emp = isset($_GET['emp']) ? (int)$_GET['emp'] : 0;

/* Validación de fechas */
$valid = fn($d) => DateTime::createFromFormat('Y-m-d', $d) && DateTime::createFromFormat('Y-m-d', $d)->format('Y-m-d') === $d;
if (!$valid($f_del)) $f_del = date('Y-m-d');
if (!$valid($f_al))  $f_al  = date('Y-m-d');
if ($f_del > $f_al) { $tmp = $f_del; $f_del = $f_al; $f_al = $tmp; }

/* ====== CONSULTA DE REPORTE ====== */
try {
  $where = [];
  $params = [];

  if ($f_emp > 0) {
    $where[] = "r.CvPerson = :p";
    $params[':p'] = $f_emp;
  }

  $where[] = "r.fecha_inicio BETWEEN :d AND :a";
  $params[':d'] = $f_del;
  $params[':a'] = $f_al;

  $sql = "
    SELECT 
      r.*,
      pu.nombre AS puesto,
      CONCAT(n.DsNombre,' ', ap1.DsApellido,' ', ap2.DsApellido) AS empleado
    FROM v_resumen_asistencia_semanal r
    LEFT JOIN mdtperson mp ON mp.CvPerson = r.CvPerson
    LEFT JOIN puesto pu ON pu.id = mp.puesto_id
    LEFT JOIN cnombre n ON n.CvNombre = mp.CvNombre
    LEFT JOIN capellido ap1 ON ap1.CvApellido = mp.CvApePat
    LEFT JOIN capellido ap2 ON ap2.CvApellido = mp.CvApeMat
    " . ($where ? " WHERE " . implode(" AND ", $where) : "") . "
    ORDER BY r.anio DESC, r.semana DESC
  ";

  $st = $conn->prepare($sql);
  $st->execute($params);
  $reporte = $st->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
  die("<h3>Error al cargar reporte: {$e->getMessage()}</h3>");
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Vista previa - Reporte semanal</title>
<link rel="stylesheet" href="css/print_reporte_semanal.css">

</head>

<body>

<!-- Barra superior -->
<div class="top-bar">
  <button class="back" onclick="window.location.href='asistencia.php?tab=marcajes'">⬅ Regresar</button>
  <h2>Vista previa del reporte</h2>
  <button id="print">🖨️ Imprimir</button>
</div>

<header>
  <h1>Industrializadora Maya S.A. de C.V.</h1>
  <div>Reporte Semanal de Asistencia</div>
  <small>(Del <?= htmlspecialchars($f_del) ?> al <?= htmlspecialchars($f_al) ?>) <br>
  Fecha de impresión: <?= date('d/m/Y, g:i a') ?></small>
</header>

<main>
<?php if (empty($reporte)): ?>
  <div style="background:#fff3cd;border:1px solid #ffeeba;padding:12px;border-radius:8px;">
    <strong>⚠️ Sin registros:</strong> No se encontraron resultados en el rango seleccionado.
  </div>
<?php else: ?>
  <?php foreach ($reporte as $r): ?>
  <div class="card">
    <div class="card-header">
      <?= htmlspecialchars($r['empleado'] ?? '') ?>
      <?php if (!empty($r['puesto'])): ?>
        <small> — <?= htmlspecialchars($r['puesto']) ?></small>
      <?php endif; ?>
    </div>

    <div class="card-body">
      <p><strong>Semana <?= $r['semana'] ?></strong> (<?= htmlspecialchars($r['rango_fechas']) ?>)</p>
      <div class="grid">
        <div><strong>Laborados:</strong> <?= (int)$r['dias_laborados'] ?></div>
        <div><strong>Descansos:</strong> <?= (int)$r['dias_descanso'] ?></div>
        <div><strong>Faltas:</strong> <?= (int)$r['faltas_injustificadas'] ?></div>
        <div><strong>Retardos:</strong> <?= (int)$r['retardos'] ?></div>
        <div><strong>Horas Trabajadas:</strong> <?= htmlspecialchars($r['total_horas_trabajadas']) ?> h</div>
        <div><strong>Horas Extra:</strong> <?= htmlspecialchars($r['horas_extra']) ?> h</div>
      </div>

      <?php if (!empty($r['observacion'])): ?>
      <div class="obs">
        <strong>Observación:</strong> <?= htmlspecialchars($r['observacion']) ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
<?php endif; ?>
</main>

<script>
document.getElementById("print").addEventListener("click", ()=>window.print());
</script>

</body>
</html>
