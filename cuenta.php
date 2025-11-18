<?php
// cuenta.php
session_start();
require_once "conexion.php"; // $conn (PDO)

// Proteger ruta
if (empty($_SESSION['auth']) || empty($_SESSION['user_id'])) {
  header("Location: login.php?next=" . urlencode($_SERVER['REQUEST_URI'] ?? 'index.php'));
  exit;
}

$userId = (int)$_SESSION['user_id'];

// CSRF
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$CSRF = $_SESSION['csrf'];

$mensaje = '';
$error = '';

function valid_username($u) {
  // 4-60 chars, letras/números/punto/guion/guion_bajo
  return (bool)preg_match('/^[a-z0-9._-]{4,60}$/', $u);
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $token = $_POST['csrf'] ?? '';
  if (!hash_equals($CSRF, $token)) {
    $error = 'Token inválido. Recarga la página.';
  } else {
    $nuevoUser = strtolower(trim($_POST['nuevo_user'] ?? ''));
    $pwdActual = $_POST['pwd_actual'] ?? '';
    $pwdNueva  = $_POST['pwd_nueva'] ?? '';
    $pwdRep    = $_POST['pwd_rep'] ?? '';

    if ($nuevoUser==='' && $pwdNueva==='') {
      $error = 'No hay cambios para guardar.';
    } else {
      try {
        // 1) Traer usuario actual
        $st = $conn->prepare("SELECT username, password_hash FROM app_user WHERE id=:id LIMIT 1");
        $st->execute([':id'=>$userId]);
        $dbUser = $st->fetch(PDO::FETCH_ASSOC);
        if (!$dbUser) throw new Exception('Usuario no encontrado.');

        // 2) Validar contraseña actual siempre que vaya a cambiar algo
        if (!password_verify($pwdActual, $dbUser['password_hash'])) {
          throw new Exception('La contraseña actual no es correcta.');
        }

        // 3) Validar y preparar cambios
        $set = [];
        $params = [':id'=>$userId];

        // Cambiar usuario
        if ($nuevoUser !== '') {
          if (!valid_username($nuevoUser)) {
            throw new Exception('Usuario inválido. Usa 4-60 caracteres: letras/números/._-');
          }
          // ¿Existe ya en otro id?
          $ch = $conn->prepare("SELECT COUNT(*) FROM app_user WHERE username=:u AND id<>:id");
          $ch->execute([':u'=>$nuevoUser, ':id'=>$userId]);
          if ((int)$ch->fetchColumn() > 0) {
            throw new Exception('El usuario ya está en uso. Elige otro.');
          }
          $set[] = "username=:u";
          $params[':u'] = $nuevoUser;
        }

        // Cambiar contraseña
        if ($pwdNueva !== '') {
          if (strlen($pwdNueva) < 6) {
            throw new Exception('La nueva contraseña debe tener al menos 6 caracteres.');
          }
          if ($pwdNueva !== $pwdRep) {
            throw new Exception('La confirmación de la nueva contraseña no coincide.');
          }
          $set[] = "password_hash=:h";
          $params[':h'] = password_hash($pwdNueva, PASSWORD_DEFAULT);
        }

        if (empty($set)) {
          throw new Exception('No hay cambios válidos.');
        }

        // 4) Update
        $sql = "UPDATE app_user SET ".implode(',', $set)." WHERE id=:id";
        $up = $conn->prepare($sql);
        $up->execute($params);

        // Si cambió usuario, refrescar sesión
        if (!empty($params[':u'])) {
          $_SESSION['user'] = $nuevoUser;
        }

        $mensaje = 'Cambios guardados correctamente.';
      } catch (Exception $ex) {
        $error = $ex->getMessage();
      }
    }
  }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Mi cuenta</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css"/>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
:root{ --vino:#800020; --vino-dark:#5a0017; --borde:#4a0013; --texto:#222; --blanco:#fff; --gris:#f4f4f4; }
*{box-sizing:border-box}
body{ font-family:Arial, sans-serif; background:var(--blanco); color:var(--texto); margin:0; }
.header{ background:var(--vino); color:#fff; padding:20px; text-align:center; border-bottom:4px solid var(--vino-dark); }
.navbar{ background:var(--vino); text-align:center; }
.navbar a{ color:#fff; padding:14px 20px; text-decoration:none; display:inline-block; font-size:18px; }
.navbar a:hover{ background:var(--vino-dark); }

.main{ padding:20px; max-width:720px; margin:0 auto; }
.card{ background:#fff; border:1px solid #eee; border-radius:12px; padding:18px; box-shadow:0 1px 3px rgba(0,0,0,.05); }
h2{ color:var(--vino); margin:0 0 12px; }
label{ font-weight:600; font-size:14px; }
input{ width:100%; padding:10px 12px; margin:8px 0 16px; border:1px solid #ddd; border-radius:8px; font-size:15px; }
button{ padding:12px 16px; border-radius:10px; border:1px solid var(--vino); background:var(--vino); color:#fff; font-weight:700; cursor:pointer; }
button:hover{ background:var(--vino-dark); }

.pwd-wrap{ position:relative; }
.pwd-wrap input{ padding-right:44px; }
.pwd-wrap .toggle-vis{
  position:absolute; right:12px; top:50%; transform:translateY(-50%);
  border:none; background:transparent; padding:0 6px; cursor:pointer; color:#666; line-height:1;
}

.footer{ background:var(--vino); color:#fff; padding:12px 10px; text-align:center; width:100%; margin-top:40px; border-top:4px solid var(--vino-dark); }
</style>
</head>
<body>

<div class="header"><h1>Gestión de Vacaciones - Sistema Agua Maya</h1></div>
<div class="navbar">
  <a href="index.php">Inicio</a>
  <a href="vacacionesMaya.php">Vacaciones</a>
  <a href="cuenta.php"><strong>Mi cuenta</strong></a>
  <a href="logout.php">Salir</a>
</div>

<div class="main">
  <div class="card">
    <h2><i class="fas fa-user-cog"></i> Mi cuenta</h2>
    <form method="post" action="">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF, ENT_QUOTES, 'UTF-8') ?>"/>

      <label>Nuevo usuario (opcional)</label>
      <input name="nuevo_user" type="text" placeholder="Deja en blanco si no lo quieres cambiar (4-60: a-z 0-9 . _ -)" pattern="[a-z0-9._-]{4,60}" />

      <label>Nueva contraseña (opcional)</label>
      <div class="pwd-wrap">
        <input id="pwd_nueva" name="pwd_nueva" type="password" placeholder="Deja en blanco si no la quieres cambiar (>= 6 caracteres)"/>
        <button type="button" class="toggle-vis" data-target="#pwd_nueva"><i class="far fa-eye"></i></button>
      </div>

      <label>Repetir nueva contraseña</label>
      <div class="pwd-wrap">
        <input id="pwd_rep" name="pwd_rep" type="password" placeholder="Repite la nueva contraseña"/>
        <button type="button" class="toggle-vis" data-target="#pwd_rep"><i class="far fa-eye"></i></button>
      </div>

      <label>Contraseña actual <span style="color:#b00">*</span></label>
      <div class="pwd-wrap">
        <input id="pwd_actual" name="pwd_actual" type="password" required placeholder="Requerida para confirmar cambios"/>
        <button type="button" class="toggle-vis" data-target="#pwd_actual"><i class="far fa-eye"></i></button>
      </div>

      <button type="submit"><i class="fas fa-save"></i> Guardar cambios</button>
    </form>
  </div>
</div>

<div class="footer">
  <p>Industrializadora Maya S.A de C.V —
    Jardín Pantaleón Domínguez 1, Miguel Alemán, 30090 Comitán de Domínguez, Chis. | Tel: 963 632 1</p>
</div>

<?php if ($mensaje): ?>
<script>Swal.fire({icon:'success', title:'Listo', text:'<?= htmlspecialchars($mensaje, ENT_QUOTES, "UTF-8") ?>', confirmButtonColor:'#800020'});</script>
<?php endif; ?>
<?php if ($error): ?>
<script>Swal.fire({icon:'error', title:'No se pudo guardar', text:'<?= htmlspecialchars($error, ENT_QUOTES, "UTF-8") ?>', confirmButtonColor:'#800020'});</script>
<?php endif; ?>

<script>
// Ojos para ver contraseñas
document.querySelectorAll('.toggle-vis').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    const target = document.querySelector(btn.dataset.target);
    if (!target) return;
    const show = target.type === 'password';
    target.type = show ? 'text' : 'password';
    btn.innerHTML = show ? '<i class="far fa-eye-slash"></i>' : '<i class="far fa-eye"></i>';
    target.focus({preventScroll:true});
  });
});
</script>
</body>
</html>
