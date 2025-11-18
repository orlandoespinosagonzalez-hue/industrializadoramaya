<?php
// partials/header.php
if (!isset($page_title)) $page_title = 'Sistema Agua Maya';
if (!isset($active))     $active     = ''; // valores: 'inicio','vacaciones','empleados','asistencia','cuenta'
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title><?= htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css"/>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="stylesheet" href="css/header.css">

</head>
<body>

<div class="header"><h1><?= htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8') ?></h1></div>

<div class="navbar">
  <a href="index.php"            class="<?= $active==='inicio'     ? 'active' : '' ?>">Inicio</a>
  <a href="vacacionesMaya.php"   class="<?= $active==='vacaciones' ? 'active' : '' ?>">Vacaciones</a>
  <a href="empleados.php"        class="<?= $active==='empleados'  ? 'active' : '' ?>">Empleados</a>
  <a href="asistencia.php"       class="<?= $active==='asistencia' ? 'active' : '' ?>">Asistencia</a>
  <a href="Capacitacion.php"           class="<?= $active==='cuenta'     ? 'active' : '' ?>">Capacitacion</a>
</div>

<div class="main-content">
