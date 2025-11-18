<?php
// login.php
session_start();

// Si ya está logueado, manda al index
$home = 'index.php';
if (!empty($_SESSION['auth'])) {
  header("Location: $home");
  exit;
}

// CSRF
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$CSRF = $_SESSION['csrf'];

/* ======= CREDENCIALES DE PRUEBA =======
   ⚠️ Cuando termines de probar, vuelve a poner
   una contraseña fuerte (y guarda el cambio).
*/
define('APP_USER', 'admin');
define('APP_PASS', 'maya123'); // <- password sencilla temporal

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $u = $_POST['user'] ?? '';
  $p = $_POST['pass'] ?? '';
  $t = $_POST['csrf'] ?? '';

  if (!hash_equals($CSRF, $t)) {
    $err = 'Token inválido. Recarga la página.';
  } else {
    $okUser = hash_equals(APP_USER, $u);
    $okPass = hash_equals(APP_PASS, $p);
    if ($okUser && $okPass) {
      $_SESSION['auth'] = true;
      $_SESSION['user'] = APP_USER;
      $next = $_GET['next'] ?? $home;
      header('Location: ' . $next);
      exit;
    } else {
      $err = 'Usuario o contraseña incorrectos.';
    }
  }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Ingresar — Vacaciones</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css"/>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="stylesheet" href="css/login.css">

</head>
<body>

<div class="header"><h1>Gestión de Vacaciones y asistencia - Sistema Agua Maya</h1></div>

<div class="wrap">
  <h2><i class="fas fa-lock"></i> Iniciar sesión</h2>
  <form method="post" action="">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF, ENT_QUOTES, 'UTF-8') ?>"/>

    <label for="user">Usuario</label>
    <input id="user" name="user" type="text" required autocomplete="username" placeholder="Usuario" />

    <label for="pass">Contraseña</label>
<div class="pwd-wrap">
  <input id="pass" name="pass" type="password" required
         autocomplete="current-password" placeholder="Contraseña"/>
  <button type="button" class="toggle-vis" aria-label="Mostrar contraseña" aria-pressed="false">
    <i class="far fa-eye"></i>
  </button>
</div>


    <button type="submit"><i class="fas fa-sign-in-alt"></i> Entrar</button>


<div class="footer">
  <p>Industrializadora Maya S.A de C.V —
    Jardín Pantaleón Domínguez 1, Miguel Alemán, 30090 Comitán de Domínguez, Chis. | Tel: 963 632 1</p>
</div>

<?php if ($err): ?>
<script>
Swal.fire({
  icon: 'error',
  title: 'No se pudo ingresar',
  text: '<?= htmlspecialchars($err, ENT_QUOTES, "UTF-8") ?>',
  confirmButtonColor: '#0D47A1'
});
</script>
<?php endif; ?>

<script>
  const pass   = document.getElementById('pass');
  const toggle = document.querySelector('.toggle-vis');

  toggle.addEventListener('click', () => {
    const isText = pass.type === 'text';
    pass.type = isText ? 'password' : 'text';
    toggle.setAttribute('aria-pressed', String(!isText));
    toggle.innerHTML = isText ? '<i class="far fa-eye"></i>' : '<i class="far fa-eye-slash"></i>';
    pass.focus({ preventScroll: true });
  });
</script>




</body>
</html>
