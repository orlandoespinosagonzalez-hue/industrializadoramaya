<?php
// marcajes_export_excel.php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/conexion.php';
date_default_timezone_set('America/Mexico_City');

/* ====== Filtros ====== */
$emp = isset($_GET['emp']) ? (int)$_GET['emp'] : 0;
$del = $_GET['del'] ?? date('Y-m-d');
$al  = $_GET['al']  ?? date('Y-m-d');

/* ====== Validaciones ====== */
$valid = function($d){
  $dt = DateTime::createFromFormat('Y-m-d', $d);
  return $dt && $dt->format('Y-m-d') === $d;
};
if (!$valid($del)) $del = date('Y-m-d');
if (!$valid($al))  $al  = date('Y-m-d');
if ($del > $al) { $tmp=$del; $del=$al; $al=$tmp; }

/* ====== Consulta ====== */
$where  = [];
$params = [
  ':d' => $del . ' 00:00:00',
  ':a' => $al  . ' 23:59:59',
];
if ($emp > 0) { $where[] = "p.CvPerson = :p"; $params[':p'] = $emp; }
$where[] = "p.ts BETWEEN :d AND :a";

$sql = "
  SELECT
    p.id,
    p.CvPerson,
    CONCAT(n.DsNombre,' ', ap1.DsApellido,' ', ap2.DsApellido) AS empleado,
    p.ts,
    p.kind,
    p.source,
    d.name AS dispositivo,
    p.ip,
    p.lat,
    p.lng,
    p.notes
  FROM t_punch p
  LEFT JOIN t_device d ON d.id = p.device_id
  JOIN mdtperson mp  ON mp.CvPerson = p.CvPerson
  JOIN cnombre   n   ON n.CvNombre  = mp.CvNombre
  JOIN capellido ap1 ON ap1.CvApellido = mp.CvApePat
  JOIN capellido ap2 ON ap2.CvApellido = mp.CvApeMat
  " . ($where ? " WHERE " . implode(" AND ", $where) : "") . "
  ORDER BY p.ts DESC
";

$st = $conn->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

/* ====== Nombre del archivo ====== */
$fname = "marcajes_{$del}_{$al}" . ($emp > 0 ? "_emp{$emp}" : "") . ".xls";

/* ====== Cabeceras para Excel ====== */
header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"{$fname}\"");
header("Pragma: no-cache");
header("Expires: 0");

/* ====== Estilos simples (HTML interpretado por Excel) ====== */
echo '<meta charset="UTF-8">';
echo '<table border="1" cellspacing="0" cellpadding="5">';
echo '<thead style="background-color:#800020;color:white;font-weight:bold;">';
echo '<tr>
        <th>ID</th>
        <th>CvPerson</th>
        <th>Empleado</th>
        <th>Fecha/Hora</th>
        <th>Tipo</th>
        <th>Fuente</th>
        <th>Dispositivo</th>
        <th>IP</th>
        <th>Latitud</th>
        <th>Longitud</th>
        <th>Notas</th>
      </tr>';
echo '</thead><tbody>';

foreach ($rows as $r) {
  echo '<tr>';
  echo '<td>'.(int)$r['id'].'</td>';
  echo '<td>'.htmlspecialchars($r['CvPerson']).'</td>';
  echo '<td>'.htmlspecialchars($r['empleado']).'</td>';
  echo '<td>'.htmlspecialchars($r['ts']).'</td>';
  echo '<td>'.htmlspecialchars(strtoupper($r['kind'])).'</td>';
  echo '<td>'.htmlspecialchars($r['source']).'</td>';
  echo '<td>'.htmlspecialchars($r['dispositivo']).'</td>';
  echo '<td>'.htmlspecialchars($r['ip']).'</td>';
  echo '<td>'.htmlspecialchars($r['lat']).'</td>';
  echo '<td>'.htmlspecialchars($r['lng']).'</td>';
  echo '<td>'.htmlspecialchars($r['notes']).'</td>';
  echo '</tr>';
}

echo '</tbody></table>';
exit;
?>
