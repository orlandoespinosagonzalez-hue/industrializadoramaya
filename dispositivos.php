<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once "conexion.php";

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$CSRF = $_SESSION['csrf'];

$mensaje = "";
$errores = [];

/* ====== Crear dispositivo ====== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
      throw new Exception('CSRF inválido');
    }
    $name = trim($_POST['name'] ?? '');
    $allowed_ip = trim($_POST['allowed_ip'] ?? '');

    if ($name === '') throw new Exception('Nombre requerido');

    // Generar api_key (32 hex)
    $api_key = bin2hex(random_bytes(16));

    $st = $conn->prepare("
      INSERT INTO t_device (name, api_key, allowed_ip)
      VALUES (:n, :k, :ip)
    ");
    $st->execute([
      ':n'  => $name,
      ':k'  => $api_key,
      ':ip' => ($allowed_ip !== '' ? $allowed_ip : null),
    ]);

    $mensaje = "Dispositivo creado. API Key: $api_key";
  } catch (Exception $e) {
    $errores[] = $e->getMessage();
  }
}

/* ====== Listado de dispositivos ====== */
/* OJO: NO pedimos 'active' porque esa columna no existe en tu tabla t_device */
$devs = [];
try {
  $devs = $conn->query("
    SELECT id, name, api_key, allowed_ip
    FROM t_device
    ORDER BY id DESC
  ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  $errores[] = "Error listando dispositivos: ".$e->getMessage();
}

/* ====== Layout condicional (como en kiosk_mark) ====== */
if (!defined('NO_LAYOUT')) {
  $page_title = 'Dispositivos biométricos';
  $active = 'dispositivos';
  require __DIR__ . '/partials/header.php';
}
?>

<div class="form-container">
  <h2>Nuevo dispositivo</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF, ENT_QUOTES, 'UTF-8') ?>">
    <label>Nombre</label>
    <input name="name" required placeholder="Ej. Checador Oficina">
    <label>IP permitida (opcional)</label>
    <input name="allowed_ip" placeholder="192.168.1.50">
    <button class="btn btn-primary" type="submit">
      <i class="fas fa-plus"></i> Crear
    </button>
  </form>
</div>

<h2 style="margin-top:18px;">Listado</h2>
<div style="overflow:auto;">
  <table>
    <thead>
      <tr>
        <th>ID</th>
        <th>Nombre</th>
        <th>API Key</th>
        <th>IP Permitida</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($devs)): ?>
        <tr><td colspan="4">Sin dispositivos registrados.</td></tr>
      <?php else: foreach ($devs as $d): ?>
        <tr>
          <td><?= (int)$d['id'] ?></td>
          <td><?= htmlspecialchars($d['name'], ENT_QUOTES, 'UTF-8') ?></td>
          <td><code><?= htmlspecialchars($d['api_key'], ENT_QUOTES, 'UTF-8') ?></code></td>
          <td><?= htmlspecialchars($d['allowed_ip'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<script>
(function(){
  const ok  = <?= $mensaje ? json_encode($mensaje, JSON_UNESCAPED_UNICODE) : 'null' ?>;
  const err = <?= !empty($errores) ? json_encode(implode("\n", $errores), JSON_UNESCAPED_UNICODE) : 'null' ?>;
  if (ok)  Swal.fire({icon:'success', title:'Listo',  text:ok,  confirmButtonColor:'#800020'});
  if (err) Swal.fire({icon:'error',   title:'Error', text:err, confirmButtonColor:'#800020'});
})();
</script>

<?php
if (!defined('NO_LAYOUT')) {
  require __DIR__ . '/partials/footer.php';
}
