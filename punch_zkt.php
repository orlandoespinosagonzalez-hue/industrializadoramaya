<?php
/**
 * punch_zkt.php
 * Adaptador universal para dispositivos ZKTeco (K40, MB160, iFace, etc.)
 * Versión mejorada con:
 *  - Registro local en MySQL
 *  - Log de actividad
 *  - Reenvío automático al API central (punch.php)
 *  - Validación y tolerancia ante fallos
 */

require_once __DIR__ . '/../conexion.php';
date_default_timezone_set('America/Mexico_City');

// === CONFIGURACIÓN ===
$LOG_FILE = __DIR__ . '/log_zkt.txt';   // Archivo de registro
$API_KEY  = 'TU_API_KEY_REAL';          // Cambia por tu clave real
$API_URL  = 'http://localhost/api/punch.php'; // Ruta API central

// === FUNCIONES AUXILIARES ===
function log_event($msg) {
    global $LOG_FILE;
    file_put_contents($LOG_FILE, '[' . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND);
    // Limpieza si excede 2 MB
    if (file_exists($LOG_FILE) && filesize($LOG_FILE) > 2 * 1024 * 1024) {
        file_put_contents($LOG_FILE, '');
    }
}

// === VALIDAR PETICIÓN ===
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "ERROR: Solo se permiten peticiones POST";
    log_event("❌ ERROR: Método inválido {$_SERVER['REQUEST_METHOD']}");
    exit;
}

$data = $_POST;
if (empty($data['UserID']) || empty($data['Time'])) {
    http_response_code(400);
    echo "ERROR: Datos incompletos";
    log_event("⚠️ ERROR: Datos incompletos -> " . json_encode($data));
    exit;
}

// === PROCESAR REGISTRO ===
try {
    $CvPerson  = (int)$data['UserID'];
    $timestamp = date('Y-m-d H:i:s', strtotime($data['Time']));
    $kind      = ($data['AttState'] == '0') ? 'in' : 'out'; // 0=Entrada, 1=Salida

    // === Buscar dispositivo activo ===
    $deviceRow = $conn->query("SELECT id FROM t_device WHERE active=1 ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $device_id = $deviceRow ? (int)$deviceRow['id'] : 1;

    // === Insertar en base local ===
    $stmt = $conn->prepare("
        INSERT INTO t_punch (device_id, CvPerson, ts, kind, ip)
        VALUES (:d, :p, :t, :k, :ip)
    ");
    $stmt->execute([
        ':d' => $device_id,
        ':p' => $CvPerson,
        ':t' => $timestamp,
        ':k' => $kind,
        ':ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);

    echo "OK";
    log_event("✅ OK: UserID={$CvPerson}, Fecha={$timestamp}, Tipo={$kind}");

    // === 🔄 Reenviar a API central ===
    $payload = json_encode([
        'api_key' => $API_KEY,
        'punches' => [[
            'CvPerson' => $CvPerson,
            'ts' => $timestamp,
            'kind' => $kind
        ]]
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 6
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        log_event("⚠️ No se pudo reenviar a API central: $err");
    } else {
        log_event("🔁 Reenvío API OK: " . $resp);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo "ERROR";
    log_event("💥 ERROR BD: " . $e->getMessage());
}
?>
