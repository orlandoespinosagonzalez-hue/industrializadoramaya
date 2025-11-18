<?php
// test_api.php — Prueba de APIs biométricas (con logging)
date_default_timezone_set('America/Mexico_City');

// === CONFIGURACIÓN DE ENDPOINTS ===
$api_url_enroll = "http://localhost/MAYA/api/enroll_map.php";
$api_url_punch  = "http://localhost/MAYA/api/punch.php";

// ⚙️ Tu API Key (ajusta con la real del módulo Dispositivos)
$API_KEY = "TU_API_KEY_REAL"; // <-- cambia esto

// Datos de ejemplo
$CvPerson = 1001;
$fp_uid   = "FP001";
$kind     = "in";

// === CHEQUEO DE CONEXIÓN A LA BD ===
$db_status = "❌ Error de conexión";
try {
  require_once __DIR__ . "/conexion.php";
  if (isset($conn) && $conn instanceof PDO) {
    $db_status = "✅ Conexión exitosa a MySQL (" . $conn->getAttribute(PDO::ATTR_DRIVER_NAME) . ")";
  }
} catch (Throwable $e) {
  $db_status = "❌ " . $e->getMessage();
}

// === FUNCIÓN: ENVIAR REQUEST A API ===
function call_api($url, $payload, $logfile) {
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
  $response = curl_exec($ch);
  $error = curl_error($ch);
  curl_close($ch);

  // Registrar log
  $timestamp = date('Y-m-d H:i:s');
  $log_entry = "\n==============================\n".
               "🕒 Fecha: $timestamp\n".
               "URL: $url\n".
               "Payload: ".json_encode($payload, JSON_UNESCAPED_UNICODE)."\n".
               "Respuesta: ".($response ?: $error)."\n";
  file_put_contents($logfile, $log_entry, FILE_APPEND);

  if ($error) return ['ok'=>false, 'error'=>$error];
  $decoded = json_decode($response, true);
  return $decoded ?: ['ok'=>false, 'error'=>'Respuesta inválida: '.$response];
}

// === ARCHIVO DE LOG ===
$logfile = __DIR__ . "/api_test_log.txt";
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Prueba de APIs Biométricas</title>
<style>
body {
  font-family: "Segoe UI", Arial, sans-serif;
  background: #f9f9f9;
  margin: 40px;
  color: #333;
}
h1 { color: #800020; }
fieldset {
  background: #fff;
  border: 2px solid #800020;
  border-radius: 10px;
  padding: 20px;
  margin-bottom: 25px;
}
legend { color: #800020; font-weight: bold; }
pre {
  background: #1e1e1e;
  color: #dcdcdc;
  padding: 10px;
  border-radius: 6px;
  overflow-x: auto;
}
button {
  background: #800020;
  color: white;
  border: none;
  padding: 8px 14px;
  border-radius: 6px;
  cursor: pointer;
  font-weight: bold;
}
button:hover { background: #a0002a; }
.status {
  font-weight: bold;
  font-size: 15px;
  padding: 8px 12px;
  border-radius: 6px;
  display: inline-block;
}
.status.ok { background: #e6ffee; color: #007a1f; border: 1px solid #007a1f; }
.status.error { background: #ffe6e6; color: #a0002a; border: 1px solid #a0002a; }
.log-link { font-size: 14px; color: #800020; text-decoration: none; }
.log-link:hover { text-decoration: underline; }
</style>
</head>
<body>

<h1>🧠 Prueba de APIs Biométricas</h1>

<p><strong>Estado de conexión a la base de datos:</strong><br>
<span class="status <?= strpos($db_status, '✅') === 0 ? 'ok' : 'error' ?>">
  <?= htmlspecialchars($db_status) ?>
</span></p>

<form method="post">
  <fieldset>
    <legend>📍 Datos de prueba</legend>

    <label>🔑 API Key:</label><br>
    <input type="text" name="api_key" value="<?= htmlspecialchars($API_KEY) ?>" style="width:400px;"><br><br>

    <label>👤 Empleado (CvPerson):</label><br>
    <input type="number" name="CvPerson" value="<?= htmlspecialchars($CvPerson) ?>"><br><br>

    <label>🧬 UID / Código de huella:</label><br>
    <input type="text" name="fp_uid" value="<?= htmlspecialchars($fp_uid) ?>"><br><br>

    <label>⏱ Tipo de marcaje:</label><br>
    <select name="kind">
      <option value="in" <?= $kind=="in"?"selected":"" ?>>Entrada (in)</option>
      <option value="out" <?= $kind=="out"?"selected":"" ?>>Salida (out)</option>
    </select><br><br>

    <button type="submit" name="action" value="enroll">🧩 Probar ENROLL</button>
    <button type="submit" name="action" value="punch">🕒 Probar PUNCH</button>
  </fieldset>
</form>

<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $api_key = $_POST['api_key'] ?? '';
  $CvPerson = (int)($_POST['CvPerson'] ?? 0);
  $fp_uid = trim($_POST['fp_uid'] ?? '');
  $kind = $_POST['kind'] ?? 'in';

  echo "<hr>";

  if ($_POST['action'] === 'enroll') {
    echo "<h2>🧩 Resultado ENROLL</h2>";
    $res = call_api($api_url_enroll, [
      'api_key' => $api_key,
      'fp_uid' => $fp_uid,
      'CvPerson' => $CvPerson
    ], $logfile);
    echo "<pre>".json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."</pre>";
  }

  if ($_POST['action'] === 'punch') {
    echo "<h2>🕒 Resultado PUNCH</h2>";
    $res = call_api($api_url_punch, [
      'api_key' => $api_key,
      'fp_uid' => $fp_uid,
      'kind' => $kind
    ], $logfile);
    echo "<pre>".json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."</pre>";
  }

  echo "<p><a class='log-link' href='api_test_log.txt' target='_blank'>📜 Ver registro de pruebas (api_test_log.txt)</a></p>";
}
?>

</body>
</html>
