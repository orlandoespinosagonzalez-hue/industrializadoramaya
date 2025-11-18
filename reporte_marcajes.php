<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once "conexion.php";
date_default_timezone_set('America/Mexico_City');

/* ====== Catálogo de empleados ====== */
$empleados = [];
try {
  $empleados = $conn->query("
    SELECT p.CvPerson,
           CONCAT(n.DsNombre,' ', ap1.DsApellido,' ', ap2.DsApellido) AS nombre
    FROM mdtperson p
    JOIN cnombre   n   ON n.CvNombre = p.CvNombre
    JOIN capellido ap1 ON ap1.CvApellido = p.CvApePat
    JOIN capellido ap2 ON ap2.CvApellido = p.CvApeMat
    WHERE p.CvTpPerson = 3
    ORDER BY nombre
  ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { /* deja lista vacía */ }

/* ====== Filtros ====== */
$f_emp = isset($_GET['emp']) ? (int)$_GET['emp'] : 0;
// ====== Fechas base ======
if (empty($_GET['del']) && empty($_GET['al'])) {
    // Semana actual por defecto
    $hoy = new DateTime();
    $inicioSemana = clone $hoy;
    $inicioSemana->modify('monday this week');
    $finSemana = clone $hoy;
    $finSemana->modify('sunday this week');

    $f_del = $inicioSemana->format('Y-m-d');
    $f_al  = $finSemana->format('Y-m-d');
} else {
    // Si el usuario eligió fechas, usa esas
    $f_del = $_GET['del'] ?? date('Y-m-d');
    $f_al  = $_GET['al']  ?? date('Y-m-d');
}


/* Valida fechas (YYYY-mm-dd). Si son inválidas, usa hoy */
$valid = function($d){
  $dt = DateTime::createFromFormat('Y-m-d', $d);
  return $dt && $dt->format('Y-m-d') === $d;
};
if (!$valid($f_del)) $f_del = date('Y-m-d');
if (!$valid($f_al))  $f_al  = date('Y-m-d');

/* Si el usuario las invirtió, corrige el orden */
if ($f_del > $f_al) { $tmp = $f_del; $f_del = $f_al; $f_al = $tmp; }

/* ====== Consulta ====== */
$where  = [];
$params = [
  ':d' => $f_del . ' 00:00:00',
  ':a' => $f_al  . ' 23:59:59',
];

if ($f_emp > 0) { $where[] = "p.CvPerson = :p"; $params[':p'] = $f_emp; }
$where[] = "p.ts BETWEEN :d AND :a";

$sql = "
  SELECT
    p.id, p.CvPerson, p.ts, p.kind, p.source, p.device_id,
    p.ip, p.lat, p.lng, p.notes,
    d.name AS device_name,
    CONCAT(n.DsNombre,' ', ap1.DsApellido,' ', ap2.DsApellido) AS empleado
  FROM t_punch p
  LEFT JOIN t_device   d   ON d.id = p.device_id
  JOIN mdtperson       mp  ON mp.CvPerson = p.CvPerson
  JOIN cnombre         n   ON n.CvNombre  = mp.CvNombre
  JOIN capellido       ap1 ON ap1.CvApellido = mp.CvApePat
  JOIN capellido       ap2 ON ap2.CvApellido = mp.CvApeMat
  " . ($where ? " WHERE " . implode(" AND ", $where) : "") . "
  ORDER BY p.ts DESC
";

$data = [];
$errores = [];
try {
  $st = $conn->prepare($sql);
  $st->execute($params);
  $data = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  $errores[] = "Error consultando marcajes: " . $e->getMessage();
}

/* ====== Layout (header/footer compartidos) ====== */
if (!defined('NO_LAYOUT')) {
  $page_title = 'Detalle de marcajes';
  $active     = 'marcajes';
  require __DIR__ . '/partials/header.php';
}
?>
<link rel="stylesheet" href="css/reporte_semanal.css">




<div class="form-container">
  <h2>Reporte Semanal de Asistencia</h2>

  <form id="formSemanal" class="filters-grid-asist" method="get" novalidate>
    <div class="field">
      <label for="emp">Empleado</label>
      <select id="emp" name="emp">
        <option value="">-- Todos --</option>
        <?php foreach ($empleados as $e): ?>
          <option value="<?= (int)$e['CvPerson'] ?>" <?= $f_emp === (int)$e['CvPerson'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($e['nombre'], ENT_QUOTES, 'UTF-8') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label for="del">Del</label>
      <input id="del" type="date" name="del" value="<?= htmlspecialchars($f_del, ENT_QUOTES, 'UTF-8') ?>" required>
    </div>

    <div class="field">
      <label for="al">Al</label>
      <input id="al" type="date" name="al" value="<?= htmlspecialchars($f_al, ENT_QUOTES, 'UTF-8') ?>" required>
    </div>

    <div class="actions">
      <button id="btnFiltrar" type="button" class="btn btn-primary">
        <i class="fas fa-filter"></i> Filtrar
      </button>
      <button id="btnLimpiar" type="button" class="btn btn-light">
        <i class="fas fa-undo"></i> Limpiar
      </button>
    </div>
  </form>
</div>







<!-- ==================== REPORTE SEMANAL DE ASISTENCIA ==================== -->

<?php
// Consulta de resumen semanal desde la vista v_resumen_asistencia_semanal
$where = [];
$params = [];

if ($f_emp > 0) { $where[] = "CvPerson = :p"; $params[':p'] = $f_emp; }
$where[] = "fecha_inicio BETWEEN :d AND :a";
$params[':d'] = $f_del;
$params[':a'] = $f_al;

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


$reporte = [];
try {
  $st = $conn->prepare($sql);
  $st->execute($params);
  $reporte = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  $errores[] = "Error cargando resumen semanal: " . $e->getMessage();
}
?>

<div style="margin-top:10px; text-align:right;">
  <button id="btnExportMarcajes" type="button" class="btn">
    <i class="fas fa-file-csv"></i> Exportar CSV
  </button>

  <!-- 🔹 Nuevo botón: abre el módulo de impresión en una pestaña nueva -->
  <button id="btnPrintMarcajes" type="button" class="btn btn-secondary">
    <i class="fas fa-print"></i> Vista previa / Imprimir
  </button>
</div>

<!-- Cabecera institucional (solo visible al imprimir) -->
<div id="print-header" style="display:none;">
  <h1>Industrializadora Maya S.A. de C.V.</h1>
  <div>Reporte Semanal de Asistencia</div>
  <small>
    (Del <?= htmlspecialchars($f_del, ENT_QUOTES, 'UTF-8') ?> 
    al <?= htmlspecialchars($f_al, ENT_QUOTES, 'UTF-8') ?>)
    <br>
    Fecha de impresión: <?= date('d/m/Y, g:i a') ?>
  </small>
</div>

<div id="reporte-semanal" style="margin-top:15px;">
  <h2 style="color:#800020; margin-bottom:15px;">📊 Reporte Semanal de Asistencia</h2>

<?php if (empty($reporte)): ?>
  <div style="
    background:#fff3cd;
    border:1px solid #ffeeba;
    padding:16px;
    border-radius:10px;
    margin-top:20px;
    font-family:'Inter', sans-serif;
  ">
    <strong style="color:#a36b00;">⚠️ Sin registros encontrados</strong><br>
    No se hallaron resultados en el rango seleccionado
    <small style="display:block;margin-top:6px;color:#555;">
      Sugerencia: amplía el rango de fechas o verifica si existen registros recientes en la base de datos.
    </small>
  </div>
<?php else: ?>

  
  <div style="
      display:grid;
      grid-template-columns:repeat(auto-fit,minmax(340px,1fr));
      gap:18px;
  ">
    <?php foreach ($reporte as $r): ?>
    <div style="
      background:#fff;
      border-radius:12px;
      border:1px solid #ddd;
      box-shadow:0 2px 6px rgba(0,0,0,0.1);
      padding:16px;
      transition:transform 0.2s;
    " 
    onmouseover="this.style.transform='scale(1.02)'"
    onmouseout="this.style.transform='scale(1)'">
<h3 style="margin:0;color:#800020;font-size:1.1rem;">
  <?= htmlspecialchars($r['empleado'], ENT_QUOTES, 'UTF-8') ?>
  <?php if (!empty($r['puesto'])): ?>
    <small style="color:#555;font-weight:normal;"> — <?= htmlspecialchars($r['puesto'], ENT_QUOTES, 'UTF-8') ?></small>
  <?php endif; ?>
</h3>
      <p style="margin:4px 0 10px;color:#555;font-size:.9rem;">
        Semana <?= htmlspecialchars($r['semana'], ENT_QUOTES, 'UTF-8') ?> <br>
        <span style="color:#777;">(<?= htmlspecialchars($r['rango_fechas'], ENT_QUOTES, 'UTF-8') ?>)</span>
      </p>

      <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:6px;">
        <div style="background:#f8f9fa;border-radius:6px;padding:6px;">
          <strong>Laborados:</strong> <?= (int)$r['dias_laborados'] ?>
        </div>
        <div style="background:#f8f9fa;border-radius:6px;padding:6px;">
          <strong>Descansos:</strong> <?= (int)$r['dias_descanso'] ?>
        </div>
        <div style="background:#f8f9fa;border-radius:6px;padding:6px;">
          <strong>Faltas:</strong> <?= (int)$r['faltas_injustificadas'] ?>
        </div>
        <div style="background:#f8f9fa;border-radius:6px;padding:6px;">
          <strong>Retardos:</strong> <?= (int)$r['retardos'] ?>
        </div>
        <div style="background:#e9f7ef;border-radius:6px;padding:6px;">
          <strong>Horas Trabajadas:</strong><br>
          <?= htmlspecialchars($r['total_horas_trabajadas'], ENT_QUOTES, 'UTF-8') ?> h
        </div>
        <div style="background:#f3e5f5;border-radius:6px;padding:6px;">
          <strong>Horas Extra:</strong><br>
          <?= htmlspecialchars($r['horas_extra'], ENT_QUOTES, 'UTF-8') ?> h
        </div>
      </div>

      <?php if (!empty($r['observacion'])): ?>
      <div style="margin-top:10px;background:#fff8e1;border-left:4px solid #ff9800;padding:8px;border-radius:6px;">
        <strong>🗒 Observación:</strong> <?= htmlspecialchars($r['observacion'], ENT_QUOTES, 'UTF-8') ?>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div> <!-- cierre de #reporte-semanal -->

<script>
document.addEventListener("DOMContentLoaded", () => {
  const form = document.getElementById("formSemanal");
  const btnFiltrar = document.getElementById("btnFiltrar");
  const btnLimpiar = document.getElementById("btnLimpiar");

  // ✅ FILTRAR
  btnFiltrar.addEventListener("click", () => {
    const emp = form.emp.value.trim();
    const del = form.del.value;
    const al = form.al.value;

    if (!del || !al) {
      Swal.fire({
        icon: "warning",
        title: "Fechas requeridas",
        text: "Selecciona un rango de fechas válido antes de continuar.",
        confirmButtonColor: "#0D47A1"
      });
      return;
    }

    if (del > al) {
      Swal.fire({
        icon: "error",
        title: "Rango incorrecto",
        text: "La fecha inicial no puede ser posterior a la final.",
        confirmButtonColor: "#0D47A1"
      });
      return;
    }

    Swal.fire({
      icon: "question",
      title: "¿Aplicar filtros?",
      text: `Generar reporte del ${del} al ${al}${emp ? " para el empleado seleccionado." : "."}`,
      showCancelButton: true,
      confirmButtonColor: "#0D47A1",
      cancelButtonColor: "#6c757d",
      confirmButtonText: "Sí, filtrar"
    }).then(result => {
      if (result.isConfirmed) {
        const params = new URLSearchParams(new FormData(form));
        window.location.href = `reporte_marcajes.php?${params.toString()}`;
      }
    });
  });

  // ✅ LIMPIAR
  btnLimpiar.addEventListener("click", () => {
    Swal.fire({
      icon: "warning",
      title: "¿Limpiar filtros?",
      text: "Se restablecerán los valores del formulario.",
      showCancelButton: true,
      confirmButtonColor: "#0D47A1",
      cancelButtonColor: "#6c757d",
      confirmButtonText: "Sí, limpiar"
    }).then(result => {
      if (result.isConfirmed) {
        const today = new Date().toISOString().split("T")[0];
        form.emp.value = "";
        form.del.value = today;
        form.al.value = today;
        window.location.href = "reporte_marcajes.php";
      }
    });
  });
});

/* ✅ Exportar CSV */
document.addEventListener("click", (e) => {
  if (e.target && e.target.id === "btnExportMarcajes") {
    const url = new URL('reporte_semanal_export_csv.php', window.location.origin + '/MAYA');
    url.searchParams.set('del', document.querySelector('[name="del"]').value);
    url.searchParams.set('al',  document.querySelector('[name="al"]').value);
    const emp = document.querySelector('[name="emp"]').value;
    if(emp) url.searchParams.set('emp', emp);
    window.location.href = url.toString();
  }
});

/* ✅ IMPRIMIR */
document.addEventListener("click", (e) => {
  if (e.target && e.target.id === "btnPrintMarcajes") {
    const del = document.querySelector('[name="del"]').value;
    const al = document.querySelector('[name="al"]').value;
    const emp = document.querySelector('[name="emp"]').value;

    const url = new URL('print_reporte_semanal.php', window.location.origin + '/MAYA');
    url.searchParams.set('del', del);
    url.searchParams.set('al', al);
    if (emp) url.searchParams.set('emp', emp);

    window.location.href = url.toString();
  }
});
</script>




<?php
if (!defined('NO_LAYOUT')) {
  require __DIR__ . '/partials/footer.php';
}
