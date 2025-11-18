<?php
// importar_marcajes.php — Importador de marcajes desde CSV/TXT
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}require_once __DIR__.'/conexion.php';
date_default_timezone_set('America/Mexico_City');

/* ===== CSRF ===== */
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$CSRF = $_SESSION['csrf'];

$mensaje = "";
$errores = [];

/* ===== Catálogo de dispositivos (para default_device_id) ===== */
$devices = [];
try {
  $devices = $conn->query("SELECT id, name FROM t_device WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  $errores[] = "No se pudieron cargar dispositivos: ".$e->getMessage();
}

/* ===== Utilidades ===== */
function guess_delimiter($headerLine) {
  // intenta con coma, punto y coma o tab
  $candidates = [',',';',"\t","|"];
  $best = ',';
  $max = 0;
  foreach ($candidates as $d) {
    $n = substr_count($headerLine,$d);
    if ($n > $max) { $max = $n; $best = $d; }
  }
  return $best;
}
function norm_key($s){ return strtolower(trim($s)); }
function as_float_or_null($v){ if ($v === '' || $v === null) return null; return (float)$v; }
function as_string_or_null($v){ $v=trim((string)$v); return $v===''?null:$v; }
function is_kind($k){ $k=strtolower(trim($k)); return ($k==='in'||$k==='out')? $k : null; }

/* ===== POST: subir e importar ===== */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  try {
    if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) { throw new Exception('Token CSRF inválido.'); }

    if (!isset($_FILES['csv']) || $_FILES['csv']['error']!==UPLOAD_ERR_OK) {
      throw new Exception('Sube un archivo CSV/TXT válido.');
    }
    $defaultDeviceId = isset($_POST['default_device_id']) ? (int)$_POST['default_device_id'] : 0;

    $tmp = $_FILES['csv']['tmp_name'];
    $size = (int)$_FILES['csv']['size'];
    if ($size <= 0) throw new Exception('Archivo vacío.');

    // Lee contenido (intenta detectar encoding simple)
    $raw = file_get_contents($tmp);
    if ($raw === false) throw new Exception('No se pudo leer el archivo.');
    // Normaliza posibles saltos CRLF
    $raw = str_replace("\r\n","\n",$raw);
    $raw = str_replace("\r","\n",$raw);
    $lines = explode("\n", trim($raw));
    if (count($lines) < 2) throw new Exception('El archivo no tiene datos (¿faltan encabezados o filas?).');

    // Encabezado + delimitador
    $delimiter = guess_delimiter($lines[0]);
    $headers   = array_map('trim', str_getcsv($lines[0], $delimiter));
    $mapIdx = [];
    foreach ($headers as $i => $h) { $mapIdx[norm_key($h)] = $i; }

    // Campos que podríamos encontrar
    $idx = [
      'cvperson'   => $mapIdx['cvperson']   ?? null,
      'fp_uid'     => $mapIdx['fp_uid']     ?? null,
      'kind'       => $mapIdx['kind']       ?? null,
      'ts'         => $mapIdx['ts']         ?? null,
      'device_id'  => $mapIdx['device_id']  ?? null,
      'ip'         => $mapIdx['ip']         ?? null,
      'lat'        => $mapIdx['lat']        ?? null,
      'lng'        => $mapIdx['lng']        ?? null,
      'notes'      => $mapIdx['notes']      ?? null,
    ];
    if ($idx['kind']===null || $idx['ts']===null) {
      throw new Exception('Encabezados mínimos requeridos: kind, ts. (más CvPerson o fp_uid + (device_id o seleccionado en el formulario)).');
    }

    // Preparar queries
    $ins = $conn->prepare("
      INSERT INTO t_punch (CvPerson, ts, kind, source, device_id, ip, lat, lng, notes)
      VALUES (:p, :ts, :k, 'device', :d, :ip, :lat, :lng, :notes)
    ");
    $map = $conn->prepare("
      SELECT CvPerson FROM t_fingerprint_map
      WHERE device_id=:d AND fp_uid=:u
      LIMIT 1
    ");

    $inserted = 0;
    $skipped  = 0;
    $errors   = [];
    // Para procesar asistencia eficientemente: registramos por persona el rango tocado
    $perPersonMin = []; // CvPerson => min date
    $perPersonMax = []; // CvPerson => max date

    for ($ln=1; $ln < count($lines); $ln++) {
      $L = trim($lines[$ln]);
      if ($L==='') continue; // línea en blanco
      $cols = str_getcsv($L, $delimiter);

      try {
        $kind = $idx['kind']!==null ? is_kind($cols[$idx['kind']] ?? '') : null;
        if (!$kind) throw new Exception('kind inválido (in|out)');

        $tsIn = $cols[$idx['ts']] ?? '';
        $dt   = date_create($tsIn);
        if (!$dt) throw new Exception('ts inválido');
        $ts   = $dt->format('Y-m-d H:i:s');
        $workDay = (new DateTime($ts))->format('Y-m-d');

        // Resolver CvPerson
        $CvPerson = null;
        if ($idx['cvperson'] !== null) {
          $CvPerson = (int)$cols[$idx['cvperson']];
          if ($CvPerson <= 0) $CvPerson = null;
        }
        if ($CvPerson === null) {
          // usar fp_uid + (device_id de CSV o default)
          $fp_uid = $idx['fp_uid']!==null ? trim((string)($cols[$idx['fp_uid']] ?? '')) : '';
          if ($fp_uid==='') throw new Exception('Falta CvPerson o fp_uid');

          $devId = null;
          if ($idx['device_id'] !== null && trim((string)$cols[$idx['device_id']])!=='') {
            $devId = (int)$cols[$idx['device_id']];
          } else {
            $devId = $defaultDeviceId ?: null;
          }
          if (!$devId) throw new Exception('Sin device_id (agrega columna o elige uno en el formulario).');

          $map->execute([':d'=>$devId, ':u'=>$fp_uid]);
          $CvPerson = (int)$map->fetchColumn();
          if ($CvPerson <= 0) throw new Exception('fp_uid no mapeado para ese device_id');
        }

        $devId = null;
        if ($idx['device_id'] !== null && trim((string)$cols[$idx['device_id']])!=='') {
          $devId = (int)$cols[$idx['device_id']];
        } else {
          $devId = $defaultDeviceId ?: null;
        }

        $ip    = $idx['ip']!==null   ? as_string_or_null($cols[$idx['ip']] ?? null)   : null;
        $lat   = $idx['lat']!==null  ? as_float_or_null($cols[$idx['lat']] ?? null)   : null;
        $lng   = $idx['lng']!==null  ? as_float_or_null($cols[$idx['lng']] ?? null)   : null;
        $notes = $idx['notes']!==null? mb_substr((string)($cols[$idx['notes']] ?? ''),0,250) : null;

        $ins->execute([
          ':p'=>$CvPerson, ':ts'=>$ts, ':k'=>$kind, ':d'=>$devId,
          ':ip'=>$ip, ':lat'=>$lat, ':lng'=>$lng, ':notes'=>$notes
        ]);
        $inserted++;

        // Actualiza rangos para procesamiento por persona
        if (!isset($perPersonMin[$CvPerson]) || $workDay < $perPersonMin[$CvPerson]) $perPersonMin[$CvPerson] = $workDay;
        if (!isset($perPersonMax[$CvPerson]) || $workDay > $perPersonMax[$CvPerson]) $perPersonMax[$CvPerson] = $workDay;

      } catch (Throwable $exRow) {
        $skipped++;
        $errors[] = "Línea ".($ln+1).": ".$exRow->getMessage();
      }
    }

    // Procesa asistencia por persona en el rango mínimo→máximo (expandido -1 día)
    if (!empty($perPersonMin)) {
      $proc = $conn->prepare("CALL sp_process_attendance(:d1,:d2,:p)");
      foreach ($perPersonMin as $p => $minDay) {
        $maxDay = $perPersonMax[$p] ?? $minDay;
        $d1 = (new DateTime($minDay))->modify('-1 day')->format('Y-m-d');
        $proc->execute([':d1'=>$d1, ':d2'=>$maxDay, ':p'=>$p]);
      }
    }

    $mensaje = "Importación completada. Insertados: $inserted, Omitidos: $skipped";
    if ($errors) {
      // Muestra hasta 10 errores para no saturar
      $errores[] = "Detalles (máx 10):\n".implode("\n", array_slice($errors,0,10));
    }

  } catch (Throwable $e) {
    $errores[] = $e->getMessage();
  }
}

/* ===== Layout: puedes reemplazar por tus parciales si quieres ===== */
if (!defined('NO_LAYOUT')) {
  $page_title = 'Importar marcajes (CSV)';
  $active     = 'Asistencia';
  require __DIR__.'/partials/header.php';
}

?>

<div class="form-container">
  <h2>Importar marcajes desde CSV/TXT</h2>
  <form method="post" enctype="multipart/form-data" style="display:grid;grid-template-columns: repeat(auto-fit,minmax(260px,1fr)); gap:12px; align-items:end;">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF,ENT_QUOTES,'UTF-8') ?>">
    <div>
      <label>Archivo CSV/TXT</label>
      <input type="file" name="csv" accept=".csv,.txt" required>
    </div>
    <div>
      <label>Device por defecto (si el CSV no trae device_id)</label>
      <select name="default_device_id">
        <option value="">— Ninguno —</option>
        <?php foreach ($devices as $d): ?>
          <option value="<?= (int)$d['id'] ?>"><?= htmlspecialchars($d['name'],ENT_QUOTES,'UTF-8') ?> (ID <?= (int)$d['id'] ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <button class="btn btn-primary" type="submit"><i class="fas fa-file-import"></i> Importar</button>
    </div>
  </form>

  <div class="card" style="margin-top:12px;">
    <p><strong>Encabezados soportados:</strong> kind, ts, CvPerson, fp_uid, device_id, ip, lat, lng, notes.</p>
    <p style="margin:6px 0 0;"><strong>Ejemplo CSV:</strong></p>
    <pre style="white-space:pre-wrap;border:1px dashed #ccc;padding:8px;background:#fff;">kind,ts,fp_uid,device_id,ip,lat,lng,notes
in,2025-10-01 07:59:40,FPU123,1,192.168.1.50,16.75,-93.12,Entrada
out,2025-10-01 17:05:10,FPU123,1,192.168.1.50,16.75,-93.12,Salida
in,2025-10-02 08:04:22,FPU123,1,,,,
out,2025-10-02 16:58:02,FPU123,1,,,,
</pre>
    <small>Si tu CSV trae <code>CvPerson</code>, no es necesario <code>fp_uid</code> ni <code>device_id</code>.</small>
  </div>
</div>

<?php if ($mensaje): ?>
<script>Swal && Swal.fire({icon:'success',title:'Listo',text:<?= json_encode($mensaje,JSON_UNESCAPED_UNICODE) ?>,confirmButtonColor:'#800020'});</script>
<?php endif; ?>
<?php if (!empty($errores)): ?>
<script>Swal && Swal.fire({icon:'warning',title:'Avisos',text:<?= json_encode(implode("\n",$errores),JSON_UNESCAPED_UNICODE) ?>,confirmButtonColor:'#800020'});</script>
<?php endif; ?>

<?php if (!defined('NO_LAYOUT')) { require __DIR__.'/partials/footer.php'; } ?>
