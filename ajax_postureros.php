<?php
// ajax_postureros.php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once "conexion.php"; // $conn (PDO)

function isValidDateYmd($d){
  $dt = DateTime::createFromFormat('Y-m-d', $d);
  return $dt && $dt->format('Y-m-d') === $d;
}

/** ¿El empleado pertenece a Ventas?  (ajusta el nombre de columna si es necesario) */
function empleadoEsVentas(PDO $conn, int $cv): bool {
  $st = $conn->prepare("SELECT departamento FROM vw_empleados WHERE CvPerson=:p LIMIT 1");
  $st->execute([':p'=>$cv]);
  $dep = $st->fetchColumn();
  if (!$dep) return false;
  $dep = mb_strtoupper(trim($dep), 'UTF-8');
  return ($dep === 'VENTAS' || $dep === 'DEPTO VENTAS' || strpos($dep, 'VENTAS') !== false);
}

/** ¿La persona tiene vacaciones (Solicitado/Aprobado) que traslapen el rango? */
function tieneVacacionesTraslapadas(PDO $conn, int $cv, string $fi, string $ff): bool {
  $sql = "SELECT 1 FROM mvacaciones
          WHERE CvPerson=:p
            AND estado IN ('Solicitado','Aprobado')
            AND fecha_inicio <= :ff
            AND fecha_fin    >= :fi
          LIMIT 1";
  $st = $conn->prepare($sql);
  $st->execute([':p'=>$cv, ':fi'=>$fi, ':ff'=>$ff]);
  return (bool)$st->fetchColumn();
}

/** ¿La persona ya está cubriendo a alguien (CvPosturero) en ese rango? */
function estaCubriendoTraslapado(PDO $conn, int $cv, string $fi, string $ff): bool {
  $sql = "SELECT 1 FROM mvacaciones
          WHERE CvPosturero=:p
            AND estado IN ('Solicitado','Aprobado')
            AND fecha_inicio <= :ff
            AND fecha_fin    >= :fi
          LIMIT 1";
  $st = $conn->prepare($sql);
  $st->execute([':p'=>$cv, ':fi'=>$fi, ':ff'=>$ff]);
  return (bool)$st->fetchColumn();
}

$cv     = isset($_GET['cv']) ? (int)$_GET['cv'] : 0;         // empleado que saldrá
$fi     = $_GET['fi'] ?? '';
$ff     = $_GET['ff'] ?? '';
$editId = isset($_GET['editId']) ? (int)$_GET['editId'] : 0; // por si editas (opcional, aquí no lo usamos)

if ($cv <= 0 || !isValidDateYmd($fi) || !isValidDateYmd($ff) || $ff < $fi) {
  echo json_encode(['ok'=>false, 'isVentas'=>false, 'postureros'=>[]]);
  exit;
}

$isVentas = empleadoEsVentas($conn, $cv);
if (!$isVentas) {
  echo json_encode(['ok'=>true, 'isVentas'=>false, 'postureros'=>[]]);
  exit;
}

// Trae todos los empleados (puedes filtrar por tipo si quieres)
$st = $conn->query("
  SELECT p.CvPerson,
         CONCAT(n.DsNombre,' ', ap1.DsApellido,' ', ap2.DsApellido) AS nombre
  FROM mdtperson p
  JOIN cnombre   n   ON p.CvNombre = n.CvNombre
  JOIN capellido ap1 ON p.CvApePat = ap1.CvApellido
  JOIN capellido ap2 ON p.CvApeMat = ap2.CvApellido
  WHERE p.CvTpPerson = 3
  ORDER BY nombre ASC
");
$all = $st->fetchAll(PDO::FETCH_ASSOC);

// Filtra disponibilidad
$out = [];
foreach ($all as $row) {
  $c = (int)$row['CvPerson'];
  if ($c === $cv) continue; // no puede ser la misma persona

  if (tieneVacacionesTraslapadas($conn, $c, $fi, $ff)) continue;
  if (estaCubriendoTraslapado($conn, $c, $fi, $ff))   continue;

  $out[] = ['CvPerson'=>$c, 'nombre'=>$row['nombre']];
}

echo json_encode(['ok'=>true, 'isVentas'=>true, 'postureros'=>$out], JSON_UNESCAPED_UNICODE);
