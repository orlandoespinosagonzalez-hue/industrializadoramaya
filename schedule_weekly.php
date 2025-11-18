<?php
// Al principio del archivo
$embebido = defined('NO_LAYOUT'); // true si lo carga asistencia.php
$self = 'schedule_weekly.php'; // fijo, sin tab para evitar recargas rotas

$clear_url = $self . (strpos($self, '?') !== false ? '&' : '?') . 'reset=1';

// ⚙️ Agregado: variable JS para detectar modo embebido
echo "<script>const NO_LAYOUT = " . ($embebido ? 'true' : 'false') . ";</script>";

// schedule_weekly.php — Asignación de turnos semanales por empleado
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__.'/conexion.php';

date_default_timezone_set('America/Mexico_City');

/* ====== Layout ====== */
$page_title = 'Horario semanal';
$active     = 'horarios';
$hide_header_banner = true; // si se abre solo, oculta la franja (si tu header lo respeta)

if (!defined('NO_LAYOUT')) {
  require __DIR__ . '/partials/header.php';
}


if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$CSRF = $_SESSION['csrf'];

$errores = []; $mensaje = "";

/* === Catálogo: empleados === */
$empleados = [];
try {
  $empleados = $conn->query("
  SELECT p.CvPerson,
         TRIM(CONCAT(n.DsNombre,' ', ap1.DsApellido,' ', IFNULL(ap2.DsApellido,''))) AS nombre
  FROM mdtperson p
  JOIN cnombre   n   ON n.CvNombre   = p.CvNombre
  JOIN capellido ap1 ON ap1.CvApellido = p.CvApePat
  LEFT JOIN capellido ap2 ON ap2.CvApellido = p.CvApeMat
  WHERE p.CvTpPerson=3
  ORDER BY nombre
")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  $errores[] = "No se pudieron cargar empleados: ".$e->getMessage();
}

/* === Catálogo: turnos activos === */
$turnos = [];
try {
  $turnos = $conn->query("
    SELECT id, name, start_time, end_time, crosses_midnight
    FROM t_shift
    WHERE active=1
    ORDER BY name
  ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  $errores[] = "No se pudieron cargar turnos: ".$e->getMessage();
}

/* === Filtros y defaults === */
$reset = isset($_GET['reset']);

$emp = $reset ? 0 : (int)($_GET['emp'] ?? 0);
$valid_from = $reset ? '' : ($_GET['from'] ?? date('Y-m-d'));
$valid_to   = $reset ? '' : ($_GET['to'] ?? '');


/* Validación fecha simple */
$checkYmd = function($d){
  $dt = DateTime::createFromFormat('Y-m-d', $d);
  return $dt && $dt->format('Y-m-d') === $d;
};
if ($valid_from && !$checkYmd($valid_from)) $valid_from = date('Y-m-d');
if ($valid_to !== '' && !$checkYmd($valid_to)) $valid_to = '';

/* === Cargar horario vigente más reciente (para prellenar) === */
$pre = array_fill(1, 7, null); // weekday 1..7
if ($emp > 0) {
  try {
    // El último bloque por weekday (el más reciente que aplique o el último creado)
$st = $conn->prepare("
  SELECT weekday, start_1, end_1, start_2, end_2
  FROM t_schedule_weekly
  WHERE CvPerson = :p
    AND (
         (valid_to IS NULL AND valid_from <= CURDATE())
         OR (valid_to IS NOT NULL AND CURDATE() BETWEEN valid_from AND valid_to)
        )
  ORDER BY weekday
");
$st->execute([':p' => $emp]);
foreach ($st as $r) {
  $pre[(int)$r['weekday']] = [
    'start1'=>$r['start_1'],
    'end1'=>$r['end_1'],
    'start2'=>$r['start_2'],
    'end2'=>$r['end_2']
  ];
}

  } catch (Exception $e) {
    $errores[] = "No se pudo leer horario previo: ".$e->getMessage();
  }
}

/* === POST: Guardar horario semanal === */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $is_ajax = isset($_POST['ajax']) && $_POST['ajax'] === '1';
  try {
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) throw new Exception('CSRF inválido.');
    $emp = (int)($_POST['emp'] ?? 0);
    $valid_from = trim($_POST['from'] ?? '');
    $valid_to   = trim($_POST['to']   ?? '');

    if ($emp <= 0) throw new Exception('Selecciona un empleado.');
    if (!$checkYmd($valid_from)) throw new Exception('Fecha “Desde” inválida.');
    if ($valid_to !== '' && !$checkYmd($valid_to)) throw new Exception('Fecha “Hasta” inválida.');
    if ($valid_to !== '' && $valid_to < $valid_from)
      throw new Exception('La fecha “Hasta” no puede ser menor que “Desde”.');

    // Lee turnos por día (1..7)
    $week = [];
    for ($d=1; $d<=7; $d++) {
      $sid = $_POST['wd_'.$d] ?? '';
      $week[$d] = ($sid === '' ? null : (int)$sid); // null = descanso
    }

$conn->beginTransaction();

// Preparar consultas una sola vez
$closeSql = "
  UPDATE t_schedule_weekly
     SET valid_to = DATE_SUB(:fromX, INTERVAL 1 DAY)
   WHERE CvPerson = :p
     AND weekday = :w
     AND (valid_to IS NULL OR valid_to >= :fromY)
     AND valid_from <= :fromX
";

$close = $conn->prepare($closeSql);

// 🔧 Nueva estructura sin shift_id
$ins = $conn->prepare("
  INSERT INTO t_schedule_weekly 
    (CvPerson, weekday, start_1, end_1, start_2, end_2, valid_from, valid_to)
  VALUES 
    (:p, :w, :s1, :e1, :s2, :e2, :f, :t)
");

$updSameFrom = $conn->prepare("
  UPDATE t_schedule_weekly
     SET start_1=:s1, end_1=:e1, start_2=:s2, end_2=:e2, valid_to=:t
   WHERE CvPerson=:p AND weekday=:w AND valid_from=:f
");

// Recorre los 7 días y actualiza o inserta
for ($w = 1; $w <= 7; $w++) {
  $isRest = isset($_POST["descanso_$w"]);
  $start1 = $_POST["start1_$w"] ?? null;
  $end1   = $_POST["end1_$w"] ?? null;
  $start2 = $_POST["start2_$w"] ?? null;
  $end2   = $_POST["end2_$w"] ?? null;

  if ($isRest) {
    $start1 = $end1 = $start2 = $end2 = null;
  }

  // Verificar si ya existe registro
  $check = $conn->prepare("SELECT id FROM t_schedule_weekly WHERE CvPerson=:p AND weekday=:w AND valid_from=:f");
  $check->execute([':p'=>$emp, ':w'=>$w, ':f'=>$valid_from]);
  $exists = $check->fetchColumn();

  if ($exists) {
    $updSameFrom->execute([
      ':s1'=>$start1, ':e1'=>$end1,
      ':s2'=>$start2, ':e2'=>$end2,
      ':t'=>($valid_to===''? null : $valid_to),
      ':p'=>$emp, ':w'=>$w, ':f'=>$valid_from
    ]);
  } else {
    $ins->execute([
      ':p'=>$emp, ':w'=>$w,
      ':s1'=>$start1, ':e1'=>$end1,
      ':s2'=>$start2, ':e2'=>$end2,
      ':f'=>$valid_from, ':t'=>($valid_to===''? null : $valid_to)
    ]);
  }
}

$conn->commit();


    $mensaje = "Horario semanal guardado para el empleado #$emp con vigencia desde $valid_from"
              .($valid_to!==''?" hasta $valid_to":" (abierto)").".";

    if ($is_ajax) {
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['ok'=>true,'message'=>$mensaje]);
      exit;
    }

  } catch (Throwable $e) {
    if (isset($is_ajax) && $is_ajax) {
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);
      exit;
    }
    if ($conn->inTransaction()) $conn->rollBack();
    $errores[] = $e->getMessage();
    $errores[] = "Debug: " . $e->getTraceAsString();

  }
}




?>
<style>
  /* ====== Filtros: grid robusto y responsivo ====== */
  .filters-grid{
    display:grid;
    grid-template-columns: minmax(380px, 2fr) minmax(180px,1fr) minmax(180px,1fr) auto auto;
    gap:14px;
    align-items:end;
  }
  .filters-grid .field{
    display:flex;
    flex-direction:column;
    min-width:0;
  }
  .filters-grid .field label{
    margin-bottom:6px;
    white-space:nowrap;
    font-weight:600;
  }
  .filters-grid .field select,
  .filters-grid .field input[type="date"]{
    width:100%;
  }

  /* “Empleado” más ancho para evitar colisiones */
  .filters-grid .field--wide{
    grid-column: 1 / span 2; /* ocupa dos columnas en desktop */
  }

  /* Botonera */
  .filters-grid .actions{
    display:flex;
    gap:8px;
    justify-content:flex-start;
  }

  /* ====== Responsivo ====== */
  @media (max-width: 1024px){
    .filters-grid{
      grid-template-columns: minmax(280px,1.2fr) minmax(180px,1fr) minmax(180px,1fr);
    }
    .filters-grid .field--wide{
      grid-column: 1 / -1; /* “Empleado” ocupa fila completa */
    }
    .filters-grid .actions{
      grid-column: 1 / -1;
    }
  }
  @media (max-width: 640px){
    .filters-grid{
      grid-template-columns: 1fr;
    }
    .filters-grid .actions{
      justify-content:stretch;
      flex-wrap:wrap;
    }
    .filters-grid .actions .btn{
      flex:1 1 auto;
    }
  }
</style>




<!-- Filtros -->
<div class="form-container">
  <h2>Horario semanal por empleado</h2>

<?php if ($emp > 0): ?>
  <div style="margin-bottom:10px;">
    <button type="button" class="btn" onclick="volverAlFormulario()">
      ⬅ Retroceder
    </button>
  </div>
<?php endif; ?>





  <form id="swFilters" method="get" action="<?= $self ?>" class="filters-grid">
    <div class="field field--wide">
      <label for="swEmp">Empleado</label>
      <select id="swEmp" name="emp">
        <option value="">— Selecciona —</option>
        <?php foreach($empleados as $e): ?>
          <option value="<?= (int)$e['CvPerson'] ?>" <?= $emp===(int)$e['CvPerson']?'selected':'' ?>>
            <?= htmlspecialchars($e['nombre'],ENT_QUOTES,'UTF-8') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label for="swFrom">Desde</label>
      <input id="swFrom" type="date" name="from" value="<?= htmlspecialchars($valid_from,ENT_QUOTES) ?>">
    </div>

    <div class="field">
      <label for="swTo">Hasta (opcional)</label>
      <input id="swTo" type="date" name="to" value="<?= htmlspecialchars($valid_to,ENT_QUOTES) ?>">
    </div>

<div class="actions">
  <button type="button" class="btn" id="btnClear">
    <i class="fas fa-undo"></i> Limpiar
  </button>
</div>
  </form>
</div>

<?php if ($emp > 0): ?>

<?php endif; ?>



</div>






<?php if ($emp > 0): ?>



  <form id="swEdit" method="post" action="<?= $self ?>"
        class="card" data-ajax="<?= $embebido ? '1' : '0' ?>"
        style="margin:12px 0; padding:12px;">

  <input type="hidden" name="csrf" form="swEdit" value="<?= htmlspecialchars($CSRF,ENT_QUOTES) ?>">
  <input type="hidden" name="emp"  form="swEdit" value="<?= (int)$emp ?>">
  <input type="hidden" name="from" form="swEdit" value="<?= htmlspecialchars($valid_from,ENT_QUOTES) ?>">
  <input type="hidden" name="to"   form="swEdit" value="<?= htmlspecialchars($valid_to,ENT_QUOTES) ?>">

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;">
<?php
  $dias = [1=>'Lunes',2=>'Martes',3=>'Miércoles',4=>'Jueves',5=>'Viernes',6=>'Sábado',7=>'Domingo'];
  for($w=1;$w<=7;$w++):
    $p = $pre[$w] ?? [];
?>
  <div class="day-block" style="border:1px solid #ccc;border-radius:8px;padding:8px;">
    <label><b><?= $dias[$w] ?></b></label>

    <div style="display:flex;gap:6px;align-items:center;margin-bottom:4px;">
      <input type="time" name="start1_<?= $w ?>" value="<?= $p['start1'] ?? '' ?>">
      <span>—</span>
      <input type="time" name="end1_<?= $w ?>" value="<?= $p['end1'] ?? '' ?>">
    </div>

    <div style="display:flex;gap:6px;align-items:center;margin-bottom:4px;">
      <input type="time" name="start2_<?= $w ?>" value="<?= $p['start2'] ?? '' ?>">
      <span>—</span>
      <input type="time" name="end2_<?= $w ?>" value="<?= $p['end2'] ?? '' ?>">
    </div>

    <label style="font-size:0.85em;">
      <input type="checkbox" name="descanso_<?= $w ?>" value="1"
        <?= (empty($p['start1']) && empty($p['end1']) && empty($p['start2']) && empty($p['end2'])) ? 'checked' : '' ?>>
      Día de descanso
    </label>
  </div>
<?php endfor; ?>
</div>


  <div style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap;">
    <button type="button" class="btn" onclick="copyMondayToAll()">Copiar Lunes a toda la semana</button>
    <button type="button" class="btn" onclick="setOfficeWeek()">Oficina Lun-Vie / Descanso S-D</button>
  </div>

    <div style="margin-top:12px; display:flex; gap:8px; justify-content:flex-end;">
    <button type="button" id="btnRecalc" class="btn">
      <i class="fas fa-sync"></i> Recalcular asistencia
    </button>
    <button class="btn btn-primary" type="submit" form="swEdit">
      <i class="fas fa-save"></i> Guardar horario
    </button>
  </div>
</form>
<?php endif; ?>

<script>
function copyMondayToAll() {
  // 🔹 Tomar horario actual del lunes
  const start1 = document.querySelector('input[name="start1_1"]').value || '';
  const end1   = document.querySelector('input[name="end1_1"]').value || '';
  const start2 = document.querySelector('input[name="start2_1"]').value || '';
  const end2   = document.querySelector('input[name="end2_1"]').value || '';
  const descansoLunes = document.querySelector('input[name="descanso_1"]').checked;

  // 🔹 Copiar lunes a todos los días (lunes incluido)
  for (let d = 1; d <= 7; d++) {
    document.querySelector(`input[name="start1_${d}"]`).value = start1;
    document.querySelector(`input[name="end1_${d}"]`).value   = end1;
    document.querySelector(`input[name="start2_${d}"]`).value = start2;
    document.querySelector(`input[name="end2_${d}"]`).value   = end2;
    document.querySelector(`input[name="descanso_${d}"]`).checked = descansoLunes;
  }

  // 🔹 Confirmación visual
  if (window.Swal) {
    Swal.fire({
      icon: 'success',
      title: 'Horario copiado',
      text: 'Se aplicó el horario del lunes a todos los días.',
      timer: 2000,
      showConfirmButton: false
    });
  }
}


function setOfficeWeek() {
  // 🔹 Tomar el horario que ya tiene el lunes
  const start1 = document.querySelector('input[name="start1_1"]').value || '';
  const end1   = document.querySelector('input[name="end1_1"]').value || '';
  const start2 = document.querySelector('input[name="start2_1"]').value || '';
  const end2   = document.querySelector('input[name="end2_1"]').value || '';
  const descansoLunes = document.querySelector('input[name="descanso_1"]').checked;

  // 🔹 Aplicar el mismo horario de lunes a viernes
  for (let d = 2; d <= 5; d++) {
    document.querySelector(`input[name="start1_${d}"]`).value = start1;
    document.querySelector(`input[name="end1_${d}"]`).value   = end1;
    document.querySelector(`input[name="start2_${d}"]`).value = start2;
    document.querySelector(`input[name="end2_${d}"]`).value   = end2;
    document.querySelector(`input[name="descanso_${d}"]`).checked = descansoLunes;
  }

  // 🔹 Sábado y domingo → descanso siempre
  for (let d = 6; d <= 7; d++) {
    document.querySelector(`input[name="start1_${d}"]`).value = '';
    document.querySelector(`input[name="end1_${d}"]`).value   = '';
    document.querySelector(`input[name="start2_${d}"]`).value = '';
    document.querySelector(`input[name="end2_${d}"]`).value   = '';
    document.querySelector(`input[name="descanso_${d}"]`).checked = true;
  }

  // 🔹 Mensaje visual
  if (window.Swal) {
    Swal.fire({
      icon: 'success',
      title: 'Horario copiado',
      text: 'Se aplicó el horario del lunes a toda la semana (excepto sábado y domingo).',
      timer: 2000,
      showConfirmButton: false
    });
  }
}



// Recalcular asistencia del empleado/rango actual
document.getElementById('btnRecalc')?.addEventListener('click', async ()=>{
  const params = new URLSearchParams({
    csrf: '<?= $CSRF ?>',
    del:  '<?= htmlspecialchars($valid_from,ENT_QUOTES) ?>',
    al:   '<?= htmlspecialchars($valid_to!==''?$valid_to:$valid_from,ENT_QUOTES) ?>',
    emp:  '<?= (int)$emp ?>'
  });
  try{
    const btn = document.getElementById('btnRecalc');
    btn.disabled = true;
    const res = await fetch('asistencia_recalc.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=utf-8' },
      body: params
    });
    const j = await res.json();
    if(!j.ok) throw new Error(j.message || 'Error en recálculo');
    if (window.Swal) Swal.fire({icon:'success',title:'Listo',text:j.message,confirmButtonColor:'#800020'});
  }catch(e){
    if (window.Swal) Swal.fire({icon:'error',title:'Error',text:String(e),confirmButtonColor:'#800020'});
  }finally{
    document.getElementById('btnRecalc').disabled = false;
  }
});



</script>



<script>
// ==========================================================
// BOTÓN "LIMPIAR" — Limpia filtros y formulario de horarios
// ==========================================================
document.getElementById('btnClear')?.addEventListener('click', () => {
  // 🔹 Obtener la fecha actual (formato YYYY-MM-DD)
  const hoy = new Date().toISOString().split('T')[0];

  // 🔹 1. Limpiar los filtros superiores (Empleado, Desde, Hasta)
  const filters = document.getElementById('swFilters');
  if (filters) {
    const empSel = filters.querySelector('#swEmp');
    const fromInput = filters.querySelector('#swFrom');
    const toInput = filters.querySelector('#swTo');

    if (empSel) empSel.value = '';
    if (fromInput) fromInput.value = hoy; // ← Evita error de fecha inválida
    if (toInput) toInput.value = '';
  }

  // 🔹 2. Limpiar formulario de horarios (si existe)
  const horarios = document.getElementById('swEdit');
  if (horarios) {
    // Vaciar horas
    horarios.querySelectorAll('input[type="time"]').forEach(inp => inp.value = '');

    // Marcar todos los días como descanso
    horarios.querySelectorAll('input[type="checkbox"][name^="descanso_"]').forEach(chk => chk.checked = true);

    // ⚠️ Actualiza los inputs ocultos de fechas (clave para evitar el error)
    const hiddenFrom = horarios.querySelector('input[name="from"]');
    const hiddenTo = horarios.querySelector('input[name="to"]');
    if (hiddenFrom) hiddenFrom.value = hoy; // ✅ Corrige el error
    if (hiddenTo) hiddenTo.value = '';
  }

  // 🔹 3. Mostrar confirmación visual
  if (window.Swal) {
    Swal.fire({
      icon: 'info',
      title: 'Formulario limpiado',
      text: 'Filtros y horarios vaciados. La fecha “Desde” se reinició al día actual para evitar errores.',
      confirmButtonColor: '#800020'
    });
  }
});




</script>

<script>

// ==========================================================
// 🔧 Parche: asegurar que 'from' tenga siempre una fecha válida
// ==========================================================
document.addEventListener('submit', (ev) => {
  const form = ev.target;
  if (form.id === 'swEdit') {
    const hiddenFrom = form.querySelector('input[name="from"]');
    const visibleFrom = document.getElementById('swFrom');
    if (hiddenFrom && visibleFrom) {
      const val = visibleFrom.value.trim();
      // Si no hay fecha o es inválida, usa la fecha actual
      hiddenFrom.value = val.match(/^\d{4}-\d{2}-\d{2}$/)
        ? val
        : new Date().toISOString().split('T')[0];
    }
  }
}, true);

// ==========================================================
// 👥 CAMBIO DE EMPLEADO → recargar dentro del embebido (si aplica)
// ==========================================================
document.getElementById('swEmp')?.addEventListener('change', async function () {
  const emp = this.value.trim() || 0;
  const IS_EMBEBIDO = typeof NO_LAYOUT !== 'undefined' && NO_LAYOUT;

  // 🔹 Mostrar aviso rápido
  if (window.Swal) {
    Swal.fire({
      icon: 'info',
      title: 'Empleado seleccionado',
      text: 'Cargando horario...',
      timer: 1500,
      showConfirmButton: false
    });
  }

  // Si no hay empleado, limpiar formulario
  if (!emp) {
    const form = document.getElementById('swEdit');
    if (form) {
      form.querySelectorAll('input[type="time"]').forEach(el => el.value = '');
      form.querySelectorAll('input[type="checkbox"][name^="descanso_"]').forEach(chk => chk.checked = true);
    }
    return;
  }

  const from = document.getElementById('swFrom')?.value || new Date().toISOString().split('T')[0];
  const to = document.getElementById('swTo')?.value || '';
  const params = new URLSearchParams({ emp, from, to });
  const targetUrl = `schedule_weekly.php?${params.toString()}`;

  if (IS_EMBEBIDO) {
    // ✅ Si está embebido, solo recarga el contenido del tab sin salir
    const contenedor = document.querySelector('#tab-horarios, #horarios-iframe, .tab-content.active');
    if (contenedor) {
      contenedor.innerHTML = '<div style="padding:20px;text-align:center;color:#800020;"><i class="fas fa-spinner fa-spin"></i> Cargando horario...</div>';
      try {
        const res = await fetch(targetUrl);
        const html = await res.text();
        contenedor.innerHTML = html;
      } catch (err) {
        console.error('Error al recargar embebido:', err);
        Swal.fire({
          icon: 'error',
          title: 'Error',
          text: 'No se pudo recargar el horario dentro del embebido.',
          confirmButtonColor: '#800020'
        });
      }
    } else {
      // Fallback si no encuentra contenedor
      window.location.href = targetUrl;
    }
  } else {
    // 🔹 Si no está embebido, carga normal
    window.location.href = `asistencia.php?tab=horarios&${params.toString()}`;
  }
});


// ==========================================================
// 🔧 CONFIGURACIÓN SEGÚN MODO EMBEBIDO
// ==========================================================
const IS_EMBEBIDO = typeof NO_LAYOUT !== 'undefined' && NO_LAYOUT;

// ==========================================================
// 🔄 FUNCIÓN CENTRAL: Recargar solo el módulo de horarios
// ==========================================================
function recargarHorario(emp = 0) {
  const from = document.getElementById('swFrom')?.value || new Date().toISOString().split('T')[0];
  const to = document.getElementById('swTo')?.value || '';

  const params = new URLSearchParams({
    emp: emp,
    from: from,
    to: to
  });

  const targetUrl = `schedule_weekly.php?${params.toString()}`;

  if (IS_EMBEBIDO) {
    // 🔹 Si está embebido, recarga solo el contenedor del horario
    const contenedor = document.querySelector('#tab-horarios, #horarios-iframe, .tab-content.active');
    if (contenedor) {
      fetch(targetUrl)
        .then(r => r.text())
        .then(html => {
          contenedor.innerHTML = html;
        })
        .catch(err => {
          console.error('Error al recargar horario:', err);
          Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'No se pudo recargar el módulo de horarios.',
            confirmButtonColor: '#800020'
          });
        });
    } else {
      // Fallback en caso de no encontrar el contenedor
      window.location.href = targetUrl;
    }
  } else {
    // 🔹 Si no está embebido, recarga la página completa
    window.location.href = targetUrl;
  }
}










document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('swEdit');
  if (!form) return;

  const isAjax = form.dataset.ajax === '1';

  if (isAjax) {
    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const fd = new FormData(form);
      fd.append('ajax', '1'); // señal al backend

      const btn = form.querySelector('button[type="submit"]');
      if (btn) btn.disabled = true;

      try {
        const res = await fetch(form.action, { method: 'POST', body: fd });
        const j = await res.json();

        if (j.ok) {
          if (window.Swal)
            Swal.fire({icon:'success', title:'Listo', text:j.message, confirmButtonColor:'#800020'});
          // notifica al contenedor padre (opcional)
          document.dispatchEvent(new CustomEvent('horario:guardado', {detail:j}));
        } else {
          throw new Error(j.message || 'Error al guardar');
        }
      } catch (e) {
        if (window.Swal)
          Swal.fire({icon:'error', title:'Error', text:String(e), confirmButtonColor:'#800020'});
      } finally {
        if (btn) btn.disabled = false;
      }
    });
  }
});



</script>

<script>
function volverAlFormulario() {
  // Si detecta que NO_LAYOUT existe y es true, vuelve al tab de asistencia
  if (typeof NO_LAYOUT !== 'undefined' && NO_LAYOUT === true) {
    window.location.href = 'asistencia.php?tab=horarios';
  } else {
    // 🔧 Caso extra: si se abrió directo, intenta ir igualmente a asistencia.php (tab=horarios)
    // Esto evita quedarse en vista aislada
    window.location.href = 'asistencia.php?tab=horarios';
  }
}

</script>

<script>
// ==========================================================
// 🔍 PRUEBA TEMPORAL: detección de modo embebido
// ==========================================================
document.addEventListener('DOMContentLoaded', () => {
  console.log("🔧 Prueba de modo embebido iniciada...");
  console.log("NO_LAYOUT detectado:", typeof NO_LAYOUT !== 'undefined' ? NO_LAYOUT : "No definido");

  // Muestra también un indicador visual en pantalla
  const banner = document.createElement('div');
  banner.style.position = 'fixed';
  banner.style.bottom = '10px';
  banner.style.right = '10px';
  banner.style.padding = '8px 14px';
  banner.style.borderRadius = '8px';
  banner.style.fontSize = '14px';
  banner.style.zIndex = '9999';
  banner.style.fontWeight = 'bold';
  banner.style.color = '#fff';
  banner.style.background = (typeof NO_LAYOUT !== 'undefined' && NO_LAYOUT) ? '#157347' : '#dc3545';
  banner.textContent = (typeof NO_LAYOUT !== 'undefined' && NO_LAYOUT)
    ? '🟢 EMBEBIDO detectado (OK)'
    : '🔴 NO embebido (vista independiente)';
  document.body.appendChild(banner);
});
</script>



<?php
// Toasts
if ($mensaje) {
  echo "<script>window.Swal&&Swal.fire({icon:'success',title:'OK',text:" .
        json_encode($mensaje, JSON_UNESCAPED_UNICODE) .
       ",confirmButtonColor:'#800020'});</script>";
}

if (!empty($errores)) {
  $errText = implode("\n", $errores);
  echo "<script>window.Swal&&Swal.fire({icon:'error',title:'Error',text:" .
        json_encode($errText, JSON_UNESCAPED_UNICODE) .
       ",confirmButtonColor:'#800020'});</script>";
}

if (!defined('NO_LAYOUT')) {
  require __DIR__ . '/partials/footer.php';
}

