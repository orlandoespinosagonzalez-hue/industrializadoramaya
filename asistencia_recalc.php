<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');
require_once "conexion.php";

date_default_timezone_set('America/Mexico_City');

try {
    // === Validar CSRF ===
    if (empty($_POST['csrf']) || $_POST['csrf'] !== ($_SESSION['csrf'] ?? '')) {
        throw new Exception("Token de seguridad inválido. Vuelve a cargar la página.");
    }

    // === Captura de parámetros ===
    $del = $_POST['del'] ?? null;
    $al  = $_POST['al']  ?? null;
    $emp = isset($_POST['emp']) ? (int)$_POST['emp'] : 0;

    // === Validación básica de fechas ===
    $validDate = function($d){
        $dt = DateTime::createFromFormat('Y-m-d', $d);
        return $dt && $dt->format('Y-m-d') === $d;
    };

    if (!$validDate($del) || !$validDate($al)) {
        throw new Exception("Fechas inválidas.");
    }
    if ($del > $al) {
        $tmp = $del; $del = $al; $al = $tmp;
    }

    // === Evita recalcular días futuros ===
    $hoy = date('Y-m-d');
    if ($al > $hoy) $al = $hoy;

    // === Ejecutar el SP de recálculo ===
    $sql = "CALL sp_process_attendance(:del, :al, :emp)";
    $st = $conn->prepare($sql);
    $st->execute([':del' => $del, ':al' => $al, ':emp' => $emp]);

    echo json_encode([
        'ok' => true,
        'message' => "Asistencia recalculada correctamente del $del al $al" .
                     ($emp > 0 ? " (empleado #$emp)" : " para todos los empleados.")
    ]);
    exit;

} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'message' => $e->getMessage()
    ]);
    exit;
}
