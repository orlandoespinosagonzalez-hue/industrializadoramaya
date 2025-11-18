<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__.'/conexion.php';
date_default_timezone_set('America/Mexico_City');

$errores = [];
$mensaje = '';

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$CSRF = $_SESSION['csrf'];

/* Modo embebido/AJAX */
$is_embedded = defined('NO_LAYOUT');
$is_ajax = ($_POST['ajax'] ?? '') === '1'
           || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';



/* ===================== POST: registrar marcaje ===================== */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  try {
    if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) throw new Exception('CSRF inválido.');

    $CvPerson = (int)($_POST['CvPerson'] ?? 0);
    $kind     = ($_POST['kind'] ?? 'in') === 'out' ? 'out' : 'in';
    if ($CvPerson <= 0) throw new Exception('Selecciona un empleado.');

    $conn->prepare("
      INSERT INTO t_punch (CvPerson, ts, kind, source)
      VALUES (:p, NOW(), :k, 'web')
    ")->execute([':p'=>$CvPerson, ':k'=>$kind]);

    // Recalcular ayer-hoy
    $d1 = (new DateTime('yesterday'))->format('Y-m-d');
    $d2 = (new DateTime('today'))->format('Y-m-d');
    $conn->prepare("CALL sp_process_attendance(:d1,:d2,:p)")
         ->execute([':d1'=>$d1, ':d2'=>$d2, ':p'=>$CvPerson]);

    if ($is_ajax || $is_embedded) {
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['ok' => true, 'message' => 'Marcaje guardado']);
      exit;
    } else {
      header('Location: kiosk_mark.php?msg='.rawurlencode('Marcaje guardado'));
      exit;
    }

  } catch (Throwable $e) {
    if ($is_ajax || $is_embedded) {
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
      exit;
    } else {
      $errores[] = $e->getMessage();
    }
  }
}


/* ===================== Datos para el formulario ===================== */
try {
  $emps = $conn->query("
    SELECT p.CvPerson,
           CONCAT(n.DsNombre,' ', ap1.DsApellido,' ', ap2.DsApellido) AS empleado
      FROM mdtperson p
      JOIN cnombre   n   ON n.CvNombre   = p.CvNombre
      JOIN capellido ap1 ON ap1.CvApellido = p.CvApePat
      LEFT JOIN capellido ap2 ON ap2.CvApellido = p.CvApeMat
     WHERE p.CvTpPerson = 3
     ORDER BY empleado
  ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  $errores[] = "Error cargando empleados: ".$e->getMessage();
}

/* Mensaje por redirección en modo no embebido */
if (isset($_GET['msg'])) $mensaje = (string)$_GET['msg'];

/* ===================== Layout condicional ===================== */
if (!defined('NO_LAYOUT')) {
  $page_title = 'Kiosco de Asistencia - Sistema Agua Maya';
  $active     = 'asistencia';
  require __DIR__ . '/partials/header.php';
}
?>

<div class="form-container">
  <h2>Registrar marcaje</h2>

  <!-- IMPORTANTE: action explícito para que, estando embebido, el POST vaya a ESTE archivo -->
<form id="kioskForm" method="post" action="<?= htmlspecialchars(basename(__FILE__), ENT_QUOTES, 'UTF-8') ?>" class="form-grid">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF, ENT_QUOTES, 'UTF-8'); ?>"/>

    <div class="card" style="display:grid;grid-template-columns:1fr 220px;gap:10px;align-items:center;">
      <div>
        <label for="CvPerson">Empleado</label>
        <select id="CvPerson" name="CvPerson" required style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;">
          <option value="">-- Selecciona --</option>
          <?php foreach(($emps ?? []) as $e): ?>
            <option value="<?= (int)$e['CvPerson'] ?>">
              <?= htmlspecialchars($e['empleado'], ENT_QUOTES, 'UTF-8') ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label>Tipo de marcaje</label>
        <div class="actions" style="display:flex;gap:12px;">
          <label><input type="radio" name="kind" value="in" checked> Entrada</label>
          <label><input type="radio" name="kind" value="out"> Salida</label>
        </div>
      </div>
    </div>

    <div class="actions" style="margin-top:10px;">
      <button class="btn btn-primary" type="submit"><i class="fas fa-fingerprint"></i> Marcar</button>
      <a class="btn" href="kiosk_mark.php"><i class="fas fa-undo"></i> Limpiar</a>
    </div>
  </form>
</div>

<div class="card" style="margin-top:16px;">
  <p class="badge">Al registrar la marca se procesará la asistencia de ayer y hoy (por turnos que cruzan medianoche).</p>
</div>

<script>
(function(){
  const okMsg = <?= $mensaje !== '' ? json_encode($mensaje, JSON_UNESCAPED_UNICODE) : 'null' ?>;
  const errs  = <?= !empty($errores) ? json_encode(implode("\n", $errores), JSON_UNESCAPED_UNICODE) : 'null' ?>;
  if (okMsg && window.Swal) Swal.fire({icon:'success', title:'Listo', text:okMsg, confirmButtonColor:'#800020'});
  else if (okMsg) alert(okMsg);
  if (errs && window.Swal) Swal.fire({icon:'error', title:'Error', text:errs, confirmButtonColor:'#800020'});
  else if (errs) alert(errs);
})();
</script>

<script>
(function(){
  const form = document.getElementById('kioskForm');
  if (!form) return;

  // Usamos AJAX si el kiosco está embebido (NO_LAYOUT) o si la página contenedora decide
  const useAjax = <?= defined('NO_LAYOUT') ? 'true' : 'false' ?>;

  if (useAjax) {
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(form);
      fd.append('ajax','1'); // para que el backend sepa que es JSON

      const btn = form.querySelector('button[type="submit"]');
      if (btn) btn.disabled = true;

      try {
const res = await fetch(form.action, { method: 'POST', body: fd });
        const j = await res.json();
        if (!j.ok) throw new Error(j.message || 'Error');

        if (window.Swal) {
          await Swal.fire({icon:'success', title:'Listo', text:j.message, confirmButtonColor:'#800020'});
        } else { alert(j.message); }

        // Limpia el form tras guardar
        form.reset();

        // (Opcional) refrescar tablas/resumen de asistencia si existe función global
        if (typeof window.refreshAttendance === 'function') {
          window.refreshAttendance();
        }
      } catch (err) {
        const msg = String(err.message || err);
        if (window.Swal) Swal.fire({icon:'error', title:'Error', text: msg, confirmButtonColor:'#800020'});
        else alert(msg);
      } finally {
        if (btn) btn.disabled = false;
      }
    });
  }
})();
</script>


<?php
if (!defined('NO_LAYOUT')) {
  require __DIR__ . '/partials/footer.php';
}
