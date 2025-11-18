<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once "conexion.php";
date_default_timezone_set('America/Mexico_City');

/* ====== CSRF (no se usa en GET, pero mantenemos la sesión consistente) ====== */
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));

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
} catch (Exception $e) {
  // dejar $empleados vacío
}

/* ====== Filtros ====== */
$f_emp = isset($_GET['emp']) ? (int)$_GET['emp'] : 0;
$f_del = $_GET['del'] ?? date('Y-m-01');
$f_al  = $_GET['al']  ?? date('Y-m-t');

/* Valida fechas (YYYY-mm-dd). Si son inválidas, usa rango mensual actual */
$errores = [];
$valid = function($d){
  $dt = DateTime::createFromFormat('Y-m-d', $d);
  return $dt && $dt->format('Y-m-d') === $d;
};
if (!$valid($f_del)) $f_del = date('Y-m-01');
if (!$valid($f_al))  $f_al  = date('Y-m-t');
if ($f_del > $f_al) { $tmp = $f_del; $f_del = $f_al; $f_al = $tmp; }

/* ====== Consulta ====== */
$where  = ["a.work_date BETWEEN :d AND :a"];
$params = [':d'=>$f_del, ':a'=>$f_al];
if ($f_emp > 0) { $where[] = "a.CvPerson = :p"; $params[':p'] = $f_emp; }

$sql = "
  SELECT 
    a.CvPerson,
    CONCAT(n.DsNombre,' ', ap1.DsApellido,' ', ap2.DsApellido) AS empleado,
    COUNT(*) AS dias,
    COALESCE(SUM(a.work_minutes),0)          AS minutos_trab,
    COALESCE(SUM(a.overtime_minutes),0)      AS minutos_extra,
    COALESCE(SUM(a.late_minutes),0)          AS minutos_tarde,
    COALESCE(SUM(a.early_leave_minutes),0)   AS minutos_anticip,
    SUM(a.status='present')                  AS dias_present,
    SUM(a.status='late')                     AS dias_tarde,
    SUM(a.status='absent')                   AS dias_ausente,
    SUM(a.status='on_leave')                 AS dias_permiso,
    SUM(a.status='holiday')                  AS dias_festivo,
    SUM(a.status='rest')                     AS dias_descanso
  FROM t_attendance_day a
  JOIN mdtperson p ON p.CvPerson = a.CvPerson
  JOIN cnombre   n   ON n.CvNombre   = p.CvNombre
  JOIN capellido ap1 ON ap1.CvApellido = p.CvApePat
  JOIN capellido ap2 ON ap2.CvApellido = p.CvApeMat
  " . ($where ? " WHERE " . implode(" AND ", $where) : "") . "
  GROUP BY a.CvPerson
  ORDER BY empleado ASC
";

$rows = [];

  $CSRF = $_SESSION['csrf'];

try {
  $st = $conn->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  // <-- NUEVO: token para guardados por fila
} catch (Exception $e) {
  $errores[] = "Error consultando asistencia: " . $e->getMessage();
}

/* ====== Layout compartido ====== */
$page_title = 'Reporte de asistencia';
$active     = 'reporte_asistencia';
$hide_header_banner = true;          // <— OCULTA la franja grande del encabezado en esta página
require_once __DIR__ . '/partials/header.php';

?>

<link rel="stylesheet" href="css/reporte_asistencia.css">



<div class="form-container">
  <h2>Reporte Semanal de Asistencia</h2>

  <!-- FORMULARIO DE FILTROS -->
  <form id="formReporteSemanal" action="reporte_asistencia.php" method="get" class="filters-grid-asist" novalidate>
    <div class="field">
      <label>Empleado</label>
      <select name="emp">
        <option value="">-- Todos --</option>
        <?php foreach ($empleados as $e): ?>
          <option value="<?= (int)$e['CvPerson'] ?>" <?= $f_emp === (int)$e['CvPerson'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($e['nombre'], ENT_QUOTES, 'UTF-8') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label>Del</label>
      <input type="date" name="del" value="<?= htmlspecialchars($f_del, ENT_QUOTES, 'UTF-8') ?>" required>
    </div>

    <div class="field">
      <label>Al</label>
      <input type="date" name="al" value="<?= htmlspecialchars($f_al, ENT_QUOTES, 'UTF-8') ?>" required>
    </div>

    <!-- Botones principales -->
    <div class="actions left">
      <button id="btnFiltrar" class="btn btn-primary" type="submit">
        <i class="fas fa-filter"></i> Filtrar
      </button>

      <button id="btnLimpiar" class="btn" type="button">
        <i class="fas fa-undo"></i> Limpiar
      </button>
    </div>

    <!-- Acciones complementarias -->
    <div class="actions right">
      <button id="btnSaveAll" type="button" class="btn btn-primary">
        <i class="fas fa-save"></i> Guardar todo
      </button>
      <button id="btnExport" type="button" class="btn">
        <i class="fas fa-file-csv"></i> Exportar CSV
      </button>
      <button id="btnPrint" type="button" class="btn">
        <i class="fas fa-print"></i> Imprimir
      </button>
      <button id="btnRecalc" type="button" class="btn">
        <i class="fas fa-sync"></i> Recalcular asistencia
      </button>
    </div>
  </form>
</div>
<!-- 🔒 Cierre único del formulario de filtros -->





<!-- ================= NUEVO: DETALLE POR DÍA (editable) ================= -->

<?php
// Filtros y parámetros
$where  = ["a.work_date BETWEEN :d AND :a"];
$params = [':d' => $f_del, ':a' => $f_al];

if (!empty($f_emp) && (int)$f_emp > 0) {
  $where[]      = "a.CvPerson = :p";
  $params[':p'] = (int)$f_emp;
}

/* Consulta de detalle diario (usa first_in/last_out y nuevas columnas) */
$sqlDay = "
  SELECT 
    a.CvPerson,
    CONCAT(n.DsNombre,' ', ap1.DsApellido,' ', ap2.DsApellido) AS empleado,
    a.work_date,
    a.first_in,
    a.last_out,
    a.work_minutes,
    a.overtime_minutes,
    a.late_minutes,
    a.early_leave_minutes,
    a.status,
    a.incidence_desc,
    a.sanction_desc,
    a.sanction_amount
  FROM t_attendance_day a
  JOIN mdtperson p   ON p.CvPerson   = a.CvPerson
  JOIN cnombre n     ON n.CvNombre   = p.CvNombre
  JOIN capellido ap1 ON ap1.CvApellido = p.CvApePat
  JOIN capellido ap2 ON ap2.CvApellido = p.CvApeMat
  WHERE ".implode(" AND ", $where)."
  ORDER BY empleado, a.work_date
";

$st = $conn->prepare($sqlDay);
$st->execute($params);
$days = $st->fetchAll(PDO::FETCH_ASSOC);
?>

<?php
// ========= Helpers para pintar Sí/No y contar totales =========
function badge_yes($cond){
  return $cond
    ? '<span class="badge bok">Sí</span>'
    : '<span class="badge boff">—</span>';
}
$tot = [
  'trabajados' => 0,
  'retardos'   => 0,
  'ausentes'   => 0,
  'permiso'    => 0,
  'descanso'   => 0,
];
?>

<h2 style="margin-top:18px;color:#0D47A1">Control de asistencia (detalle por día)</h2>

<?php
// ====== Totales coherentes con la tabla ======
$tot = [
  'trabajados' => 0,
  'retardos'   => 0,
  'ausentes'   => 0,
  'permiso'    => 0,
  'descanso'   => 0,
];

if (!empty($days)) {
  foreach ($days as $__r) {
    $hasIn  = !empty($__r['first_in']);
    $hasOut = !empty($__r['last_out']);
    $mins   = (int)$__r['work_minutes'];
    $lateM  = (int)$__r['late_minutes'];
    $status = strtolower(trim($__r['status']));
    $horarioBase = 480;

    // === Lógica unificada con la tabla ===
    if (!$hasIn && !$hasOut) {
        $status = 'absent';
    }
    elseif ($mins === 0 && !in_array($status, ['on_leave','rest'], true)) {
        $status = 'absent';
    }
    elseif ($mins > 0 && $lateM > 0 && $mins >= 30) {
        $status = 'late';
    }
    elseif ($mins > 0 && $lateM === 0) {
        $status = 'present';
    }
    elseif ($mins < 30) {
        $status = 'absent';
    }

    // Migrar permisos a descanso
    if ($status === 'on_leave') $status = 'rest';

    // === Totales corregidos ===
    if (in_array($status, ['present','late'], true)) $tot['trabajados']++;
    if ($status === 'late') $tot['retardos']++;
    if ($status === 'absent') $tot['ausentes']++;
    if ($status === 'rest') $tot['descanso']++;
  }
}


?>

<?php if (!empty($days)): ?>
<div class="card" style="margin-bottom:10px;display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:8px;">
  <div style="color:#155724"><strong>Días trabajados:</strong> <?= (int)$tot['trabajados'] ?></div>
  <div style="color:#856404"><strong>Con retardo:</strong> <?= (int)$tot['retardos'] ?></div>
  <div style="color:#721c24"><strong>Ausentes:</strong> <?= (int)$tot['ausentes'] ?></div>
  <div style="color:#383d41"><strong>Descansos (incluye vacaciones):</strong> <?= (int)$tot['descanso'] ?></div>
</div>
<?php endif; ?>



<div style="overflow:auto;">
  
  <table>
    <thead>
      <tr>
        <th>Fecha</th>
        <th>Empleado</th>
        <th>Entrada</th>
        <th>Salida</th>
        <th>Horas Trabajadas</th>
        <th>Estado</th>


        <!-- NUEVOS indicadores que reemplazan el resumen -->
        <th>Trabajó</th>
        <th>Retardo</th>
        <th>Ausente</th>
        <th>Descanso</th>

        <!-- Campos que ya tenía la tabla -->
        <th>Incidencia (editable)</th>
        <th>Sanción (opcional)</th>
        <th>Monto</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($days)): ?>
        <tr><td colspan="13">Sin registros en el rango.</td></tr>
      <?php else: foreach ($days as $r):
        $wid = (int)$r['CvPerson'].'__'.$r['work_date'];

        // Flags por fila (para pintar Sí/No)
// ========================= 🔧 LÓGICA CORREGIDA DE ESTADOS =========================
$hasIn  = !empty($r['first_in']);
$hasOut = !empty($r['last_out']);
$mins   = (int)$r['work_minutes'];
$lateM  = (int)$r['late_minutes'];

// 1️⃣ Determinar si realmente trabajó (solo si hay entradas y salidas y minutos > 0)
$worked = ($hasIn && $hasOut && $mins > 0);

// 2️⃣ Clasificación más precisa de estado
if (!$hasIn && !$hasOut) {
    // No registró nada → AUSENTE
    $r['status'] = 'absent';
}
elseif ($mins === 0 && $r['status'] !== 'on_leave' && $r['status'] !== 'rest') {
    // Entró o marcó pero no trabajó nada → AUSENTE
    $r['status'] = 'absent';
}
elseif ($mins > 0 && $lateM > 0 && $mins >= 30) {
    // Llegó tarde pero trabajó al menos 30 min → RETARDO
    $r['status'] = 'late';
}
elseif ($mins > 0 && $lateM === 0) {
    // Trabajó y no llegó tarde → PRESENTE
    $r['status'] = 'present';
}
elseif ($mins < 30 && $r['status'] === 'late') {
    // Menos de 30 minutos trabajados → AUSENTE (aunque haya retardo)
    $r['status'] = 'absent';
}

// 3️⃣ Bandas booleanas (para las columnas de indicadores)
$late = ($r['status'] === 'late');
$abs  = ($r['status'] === 'absent');
$perm = ($r['status'] === 'on_leave');
$rest = ($r['status'] === 'rest');


      ?>
<tr id="row-<?= htmlspecialchars($wid) ?>"
    data-cvp="<?= (int)$r['CvPerson'] ?>"
    data-day="<?= htmlspecialchars($r['work_date'], ENT_QUOTES) ?>">
          <td><?= htmlspecialchars($r['work_date'], ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= htmlspecialchars($r['empleado'], ENT_QUOTES, 'UTF-8') ?></td>
<td><?= htmlspecialchars(substr($r['first_in'],11,5) ?? '—') ?></td>
<td><?= htmlspecialchars(substr($r['last_out'],11,5) ?? '—') ?></td>
<td>
  <?php
    $mins = (int)$r['work_minutes'];
    if ($mins >= 60) {
        // Mostrar en horas con un decimal (ej. 1.5 h)
        $hours = $mins / 60;
        echo number_format($hours, 1) . ' h';
    } else {
        // Mostrar solo en minutos
        echo $mins . ' min';
    }
  ?>
</td>
<?php
// 🔧 Traducción de estados al español
if (!function_exists('traducirEstado')) {
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
}
?>
<?php
$estadoMap = [
  'present'         => ['PRESENTE','#2196f3'],
  'late'            => ['RETARDO','#ffb300'],
  'absent'          => ['AUSENTE','#f44336'],
  'partial_absent'  => ['PARCIAL','#9c27b0'],
  'rest'            => ['DESCANSO','#607d8b'],
];
list($estadoTxt, $color) = $estadoMap[strtolower($r['status'])] ?? [$r['status'], '#555'];
?>
<td>
  <span class="status" style="background:<?= $color ?>20;color:<?= $color ?>;padding:4px 8px;border-radius:6px;font-weight:600;">
    <?= $estadoTxt ?>
  </span>
</td>




          <!-- Indicadores -->
<td><?= badge_yes($worked) ?></td>
<td><?= badge_yes($late) ?></td>
<td><?= badge_yes($abs) ?></td>
<td><?= badge_yes($rest) ?></td>

          <!-- Editables -->
          <td style="min-width:220px;">
            <input type="text"
                   value="<?= htmlspecialchars($r['incidence_desc'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                   data-cvp="<?= (int)$r['CvPerson'] ?>"
                   data-day="<?= htmlspecialchars($r['work_date'], ENT_QUOTES, 'UTF-8') ?>"
                   data-k="incidence_desc"
                   style="width:100%;padding:6px;border:1px solid #ddd;border-radius:6px;">
          </td>
          <td style="min-width:200px;">
            <input type="text"
                   value="<?= htmlspecialchars($r['sanction_desc'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                   data-cvp="<?= (int)$r['CvPerson'] ?>"
                   data-day="<?= htmlspecialchars($r['work_date'], ENT_QUOTES, 'UTF-8') ?>"
                   data-k="sanction_desc"
                   style="width:100%;padding:6px;border:1px solid #ddd;border-radius:6px;">
          </td>
          <td style="min-width:110px;">
            <input type="number" step="0.01" min="0"
                   value="<?= htmlspecialchars($r['sanction_amount'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                   data-cvp="<?= (int)$r['CvPerson'] ?>"
                   data-day="<?= htmlspecialchars($r['work_date'], ENT_QUOTES, 'UTF-8') ?>"
                   data-k="sanction_amount"
                   style="width:100%;padding:6px;border:1px solid #ddd;border-radius:6px;">
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>



<script>
const CSRF = '<?= $CSRF ?>';

function collectAllRows() {
  const rows = [];
  document.querySelectorAll('tbody tr[data-cvp][data-day]').forEach(tr => {
    const CvPerson = parseInt(tr.getAttribute('data-cvp'), 10);
    const work_date = tr.getAttribute('data-day');

    const rec = { CvPerson, work_date };
    tr.querySelectorAll('input[data-k]').forEach(inp => {
      const k = inp.dataset.k;
      let v = inp.value.trim();
      if (k === 'sanction_amount' && v !== '') v = parseFloat(v);
      rec[k] = v === '' ? null : v;
    });
    rows.push(rec);
  });
  return rows;
}

async function saveAll() {
  const btn = document.getElementById('btnSaveAll');
  try {
    btn.disabled = true;

    const payload = {
      csrf: CSRF,
      rows: collectAllRows()
    };

    const res = await fetch('asistencia_save_meta_bulk.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json; charset=utf-8'
      },
      body: JSON.stringify(payload)
    });

    const j = await res.json();
    if (!j.ok) throw new Error(j.message || 'Error al guardar');

    if (window.Swal) {
      Swal.fire({
        icon: 'success',
        title: 'Guardado',
        text: `Cambios aplicados: ${j.updated} fila(s).`,
        confirmButtonColor: '#0D47A1'
      });
    }
  } catch (e) {
    if (window.Swal) {
      Swal.fire({icon:'error', title:'Error', text:String(e), confirmButtonColor: '#0D47A1'});
    }
  } finally {
    btn.disabled = false;
  }
}

document.getElementById('btnSaveAll')?.addEventListener('click', e => {
  e.preventDefault();
  saveAll();
});

async function recalcAttendance() {
  const del = document.querySelector('input[name="del"]').value;
  const al  = document.querySelector('input[name="al"]').value;
  const emp = document.querySelector('select[name="emp"]').value || 0;

  const params = new URLSearchParams({
    csrf: '<?= $_SESSION['csrf'] ?>',
    del,
    al,
    emp
  });

  const btn = document.getElementById('btnRecalc');
  btn.disabled = true;

  try {
    const res = await fetch('asistencia_recalc.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=utf-8' },
      body: params
    });

    // 🧠 Leemos primero como texto para verificar si viene algo raro
    const raw = await res.text();
    console.log("Respuesta cruda del servidor:", raw);

    // Intentamos parsear el JSON manualmente
    let j;
    try {
      j = JSON.parse(raw);
    } catch (err) {
      throw new Error("Respuesta no válida del servidor. Ver consola para más detalles.");
    }

    // 🚦 Si todo bien, mostramos el resultado con SweetAlert
    if (j.ok) {
      Swal.fire({
        icon: 'success',
        title: 'Listo',
        text: j.message || 'Asistencia recalculada correctamente.',
        confirmButtonColor: '#0D47A1'
      }).then(() => location.reload());
    } else {
      Swal.fire({
        icon: 'error',
        title: 'Error',
        text: j.message || 'Error en recálculo',
        confirmButtonColor: '#0D47A1'
      });
    }

  } catch (e) {
    // Captura errores de conexión o JSON inválido
    Swal.fire({
      icon: 'error',
      title: 'Error inesperado',
      text: String(e.message || e),
      confirmButtonColor: '#0D47A1'
    });
  } finally {
    btn.disabled = false;
  }
}

document.getElementById('btnRecalc')?.addEventListener('click', recalcAttendance);


document.getElementById('btnExport')?.addEventListener('click', ()=>{
  const url = new URL('asistencia_export_csv.php', location.href);
  url.searchParams.set('del', '<?= htmlspecialchars($f_del, ENT_QUOTES) ?>');
  url.searchParams.set('al',  '<?= htmlspecialchars($f_al, ENT_QUOTES) ?>');
  if ('<?= (int)$f_emp ?>' !== '0') url.searchParams.set('emp', '<?= (int)$f_emp ?>');
  window.location = url.toString();
});

document.getElementById('btnPrint')?.addEventListener('click', ()=>{
  window.print();
});


</script>



<script>
(function(){
  const errs = <?= !empty($errores) ? json_encode(implode("\n", $errores), JSON_UNESCAPED_UNICODE) : 'null' ?>;
  if (errs && window.Swal) {
    Swal.fire({icon:'error', title:'Error', text:errs, confirmButtonColor: '#0D47A1'});
  }
})();
</script>


<?php
if (!defined('NO_LAYOUT')) {
  require __DIR__ . '/partials/footer.php';
}