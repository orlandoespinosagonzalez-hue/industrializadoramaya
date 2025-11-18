<?php
session_start();
require_once __DIR__ . "/conexion.php";
require_once __DIR__ . "/auth_check.php";

$tab = $_GET['tab'] ?? 'resumen';

function tabLink($id, $txt, $current){
  $base = 'asistencia.php?tab=' . $id;
$cls  = $current === $id ? 'class="tab active"' : 'class="tab"';
return "<a href='{$base}' {$cls}>{$txt}</a>";
}

/* Header general del hub: sólo una vez */
$page_title = 'Control de asistencia';
$active     = 'asistencia';
require __DIR__ . "/partials/header.php";
?>
<link rel="stylesheet" href="css/asistencia.css">


<div class="container">
  <h2 style="color:var(--vino);margin:6px 0 12px;">Control de asistencia</h2>

  <div class="tabs">
    <?= tabLink('resumen','Resumen asistencia',$tab) ?>
    <?= tabLink('horarios','Horarios (semanal)',$tab) ?>
    <?= tabLink('marcajes','Marcajes (crudo)',$tab) ?>
    <?= tabLink('importar','Importar CSV',$tab) ?>
    <?= tabLink('kiosco','Kiosco manual',$tab) ?>
    <?= tabLink('enrolar','Enrolar huellas',$tab) ?>
    <?= tabLink('dispositivos','Dispositivos',$tab) ?>
  </div>

  <div class="card">
  <?php
    switch ($tab) {

      case 'horarios':
        if (!defined('NO_LAYOUT')) define('NO_LAYOUT', true);
        $hide_header_banner = true;              // oculta franja vino interna
        require __DIR__ . "/schedule_weekly.php";
        break;

      case 'marcajes':
        if (!defined('NO_LAYOUT')) define('NO_LAYOUT', true);
        $hide_header_banner = true;
        require __DIR__ . "/reporte_marcajes.php";
        break;

      case 'kiosco':
        if (!defined('NO_LAYOUT')) define('NO_LAYOUT', true);
        $hide_header_banner = true;
        require __DIR__ . "/kiosk_mark.php";
        break;

      case 'importar':
        if (!defined('NO_LAYOUT')) define('NO_LAYOUT', true);
        $hide_header_banner = true;
        require __DIR__ . "/importar_marcajes.php";
        break;

      case 'enrolar':
        if (!defined('NO_LAYOUT')) define('NO_LAYOUT', true);
        $hide_header_banner = true;
        require __DIR__ . "/enroll_map.php";
        break;

      case 'dispositivos':
        if (!defined('NO_LAYOUT')) define('NO_LAYOUT', true);
        $hide_header_banner = true;
        require __DIR__ . "/dispositivos.php";
        break;

      case 'resumen':
      default:
        // El resumen puede ser “página completa” o también embebido.
        // Si tu resumen ya respeta NO_LAYOUT, puedes activarlo también:
        if (!defined('NO_LAYOUT')) define('NO_LAYOUT', true);
        $hide_header_banner = true;
        require __DIR__ . "/reporte_asistencia.php";
        break;
    }
  ?>
  </div>
</div>

<?php require __DIR__ . "/partials/footer.php"; ?>
