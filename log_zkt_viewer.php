<?php
// log_zkt_viewer.php — visor de logs de comunicación ZKTeco K40
date_default_timezone_set('America/Mexico_City');
$logFile = __DIR__ . '/log_zkt.txt';

if (!file_exists($logFile)) {
    die("<h3 style='font-family:Arial;color:#800020;'>❌ No existe el archivo log_zkt.txt.<br> Aún no se han recibido datos del reloj.</h3>");
}

$lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$lines = array_reverse($lines); // mostrar más recientes primero
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>📋 Log ZKTeco – Últimos registros</title>
<style>
  body {
    font-family: 'Segoe UI', Arial, sans-serif;
    background: #f8f9fa;
    margin: 0;
    padding: 20px;
    color: #333;
  }
  h1 {
    color: #800020;
    border-bottom: 3px solid #800020;
    padding-bottom: 6px;
  }
  .toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
  }
  .btn {
    background: #800020;
    color: white;
    border: none;
    padding: 8px 14px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
  }
  .btn:hover { background: #a0002b; }
  pre {
    background: #fff;
    padding: 12px;
    border: 1px solid #ddd;
    border-radius: 8px;
    font-size: 13px;
    overflow-x: auto;
    white-space: pre-wrap;
    line-height: 1.4em;
  }
  .entry {
    margin-bottom: 10px;
    border-left: 4px solid #800020;
    background: #fff;
    padding: 10px 12px;
    border-radius: 6px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
  }
  .timestamp {
    color: #555;
    font-size: 12px;
  }
</style>
</head>
<body>

<div class="toolbar">
  <h1>📋 Log ZKTeco – Registros Recibidos</h1>
  <button class="btn" onclick="location.reload()">🔄 Actualizar</button>
</div>

<?php if (empty($lines)): ?>
  <p>No hay registros en el log aún.</p>
<?php else: ?>
  <?php foreach ($lines as $line): 
    [$ts, $json] = explode(' => ', $line, 2) + ["",""];
    $pretty = json_encode(json_decode($json, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  ?>
    <div class="entry">
      <div class="timestamp">🕓 <?= htmlspecialchars($ts) ?></div>
      <pre><?= htmlspecialchars($pretty ?: $json) ?></pre>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

</body>
</html>
