<?php
// logout.php
session_start();

// Vaciar variables de sesión
$_SESSION = [];

// Borrar cookie de la sesión (si aplica)
if (ini_get('session.use_cookies')) {
  $p = session_get_cookie_params();
  setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}

// Destruir la sesión
session_destroy();

// Volver al login
header('Location: login.php');
exit;
