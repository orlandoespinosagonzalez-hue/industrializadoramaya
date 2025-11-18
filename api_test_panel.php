<?php
// api_test_panel.php
// Panel de pruebas para api_enroll.php y api/punch.php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/conexion.php'; // ajusta ruta si tu conexion.php está en otro lugar
date_default_timezone_set('America/Mexico_City');

// Carga empleados y dispositivos para los selects
$empleados = $devices = [];
try {
  $empleados = $conn->query("
    SELECT p.CvPerson, CONCAT(n.DsNombre,' ',ap1.DsApellido,' ',ap2.DsApellido) AS nombre
    FROM mdtperson p
    JOIN cnombre n ON n.CvNombre = p.CvNombre
    JOIN capellido ap1 ON ap1.CvApellido = p.CvApePat
    JOIN capellido ap2 ON ap2.CvApellido = p.CvApeMat
    WHERE p.CvTpPerson = 3
    ORDER BY nombre
  ")->fetchAll(PDO::FETCH_ASSOC);

  // devices: toma api_key y name
  $devices = $conn->query("SELECT id, name, api_key, allowed_ip, active FROM t_device ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  $errLoad = $e->getMessage();
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>API Test Panel — ENROLL / PUNCH</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
  body{font-family:Inter,Arial,Helvetica,sans-serif;background:#f7f7f8;color:#222;margin:18px;}
  .card{background:#fff;border-radius:8px;padding:16px;box-shadow:0 6px 18px rgba(0,0,0,.06);margin-bottom:14px}
  h1{color:#800020;margin:0 0 8px}
  label{display:block;margin:8px 0 4px;font-weight:600}
  select,input{width:100%;padding:8px;border-radius:6px;border:1px solid #ddd}
  .row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  .actions{display:flex;gap:10px;margin-top:12px}
  button{background:#800020;color:white;border:0;padding:8px 12px;border-radius:8px;cursor:pointer}
  button.alt{background:#6c757d}
  pre{background:#111;color:#e6e6e6;padding:12px;border-radius:6px;overflow:auto}
  .small{font-size:13px;color:#666;margin-top:6px}
  .ok{color:green;font-weight:700}
  .err{color:#b00020;font-weight:700}
  @media(max-width:700px){ .row{grid-template-columns:1fr} }
</style>
</head>
<body>

<h1>Panel de pruebas — ENROLL / PUNCH</h1>

<?php if (!empty($errLoad)): ?>
  <div class="card"><div class="err">Error cargando catálogos: <?=htmlspecialchars($errLoad)?></div></div>
<?php endif; ?>

<div class="card">
  <strong>Estado DB:</strong>
  <div class="small">Empleados cargados: <?=count($empleados)?> — Dispositivos: <?=count($devices)?></div>
</div>

<!-- Formulario principal -->
<div class="card">
  <label>API Key (puedes pegar el api_key desde la tabla de t_device)</label>
  <input id="api_key" placeholder="API Key...">

  <div class="row" style="margin-top:10px">
    <div>
      <label>Empleado (CvPerson)</label>
      <select id="CvPerson">
        <option value="0">-- Selecciona --</option>
        <?php foreach($empleados as $e): ?>
          <option value="<?= (int)$e['CvPerson'] ?>"><?= htmlspecialchars($e['nombre']) ?> (<?= (int)$e['CvPerson'] ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label>UID / Código de huella</label>
      <input id="fp_uid" placeholder="Ej. FP001">
      <div class="small">Si tu dispositivo envía ?uid=XXX a la URL, pega aquí ese valor para simular.</div>
    </div>
  </div>

  <div style="margin-top:12px" class="row">
    <div>
      <label>Simular fecha/hora</label>
      <input id="ts" type="datetime-local" value="<?= date('Y-m-d\TH:i') ?>">
      <div class="small">Formato local; para pruebas puede usarse la hora actual o personalizada.</div>
    </div>
    <div>
      <label>Tipo (kind) para PUNCH</label>
      <select id="kind">
        <option value="in">Entrada (in)</option>
        <option value="out">Salida (out)</option>
      </select>
    </div>
  </div>

<div class="actions">
  <button id="btnEnroll"><i class="fas fa-fingerprint"></i> Probar ENROLL</button>
  <button id="btnPunch" class="alt"><i class="fas fa-clock"></i> Probar PUNCH</button>
  <button id="btnPing" class="alt"><i class="fas fa-satellite-dish"></i> Probar conexión</button>
  <button id="btnSimDay" class="alt" title="Simula IN 08:00 y OUT 16:00">Simular Día (08:00 → 16:00)</button>
  <button id="btnClear" style="background:#fff;color:#800020;border:1px solid #800020">Limpiar salida</button>
</div>



<!-- Dispositivos list (para copiar api_key fácilmente) -->
<div class="card">
  <strong>Dispositivos (copiar API Key):</strong>
  <div class="small" style="margin-top:6px">
    <table style="width:100%;border-collapse:collapse">
      <thead><tr style="text-align:left"><th>#</th><th>Nombre</th><th>api_key</th><th>allowed_ip</th><th>active</th></tr></thead>
      <tbody>
      <?php foreach($devices as $d): ?>
        <tr>
          <td style="padding:6px;border-top:1px solid #eee"><?= (int)$d['id'] ?></td>
          <td style="padding:6px;border-top:1px solid #eee"><?= htmlspecialchars($d['name']) ?></td>
          <td style="padding:6px;border-top:1px solid #eee"><code style="font-size:12px"><?= htmlspecialchars($d['api_key']) ?></code>
            <button class="alt" style="margin-left:8px;padding:4px 8px;font-size:12px" onclick="copyKey('<?= addslashes($d['api_key']) ?>')">Copiar</button>
          </td>
          <td style="padding:6px;border-top:1px solid #eee"><?= htmlspecialchars($d['allowed_ip'] ?? '') ?></td>
          <td style="padding:6px;border-top:1px solid #eee"><?= (int)$d['active']===1 ? '<span class="ok">ON</span>' : '<span class="err">OFF</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Resultado -->
<div class="card">
  <strong>Resultado ENROLL</strong>
  <pre id="outEnroll">{ }</pre>
</div>

<div class="card">
  <strong>Resultado PUNCH</strong>
  <pre id="outPunch">{ }</pre>
</div>

<script>
const $ = id => document.getElementById(id);

function copyKey(key){
  navigator.clipboard?.writeText(key).then(()=>alert('Copiado al portapapeles'));
  $('api_key').value = key;
}

/* Helper para POST JSON usando fetch */
async function postJSON(url, payload){
  const res = await fetch(url, {
    method: 'POST',
    headers:{ 'Content-Type':'application/json; charset=utf-8' },
    body: JSON.stringify(payload)
  });
  const text = await res.text();
  try { return { status: res.status, json: JSON.parse(text) }; }
  catch(e){ return { status: res.status, raw: text }; }
}

/* ENROLL */
$('btnEnroll').addEventListener('click', async ()=>{
  const api_key = $('api_key').value.trim();
  const fp_uid = $('fp_uid').value.trim();
  const CvPerson = parseInt($('CvPerson').value || 0,10);
  if(!api_key || !fp_uid || !CvPerson){ alert('API Key, UID y Empleado son requeridos'); return; }

  const payload = { api_key, fp_uid, CvPerson };
  $('outEnroll').textContent = 'Enviando...';
  const r = await postJSON('api/enroll_map.php', payload); // ✅ ruta corregida
  $('outEnroll').textContent = JSON.stringify(r, null, 2);
});

/* PUNCH simple (un evento) */
$('btnPunch').addEventListener('click', async ()=>{
  const api_key = $('api_key').value.trim();
  const fp_uid = $('fp_uid').value.trim();
  const CvPerson = parseInt($('CvPerson').value || 0,10);
  const kind = $('kind').value;
  const tsLocal = $('ts').value;
  if(!api_key){ alert('API Key requerida'); return; }
  if(!fp_uid && !CvPerson){ alert('UID o CvPerson es requerido'); return; }

  const event = { kind, ts: tsLocal ? new Date(tsLocal).toISOString() : 'now' };
  if(CvPerson>0) event.CvPerson = CvPerson; else event.fp_uid = fp_uid;

  const payload = { api_key, punches: [event] };
  $('outPunch').textContent = 'Enviando...';
  const r = await postJSON('api/punch.php', payload);
  $('outPunch').textContent = JSON.stringify(r, null, 2);
});

/* Simulación día: IN 08:00 and OUT 16:00 */
$('btnSimDay').addEventListener('click', async ()=>{
  const api_key = $('api_key').value.trim();
  const fp_uid = $('fp_uid').value.trim();
  const CvPerson = parseInt($('CvPerson').value || 0,10);
  if(!api_key){ alert('API Key requerida'); return; }
  if(!fp_uid && !CvPerson){ alert('UID o CvPerson requerido'); return; }

  let base = $('ts').value ? new Date($('ts').value) : new Date();
  base.setHours(0,0,0,0);
  const dY = base.toISOString().slice(0,10);

  const inDt = new Date(dY + 'T08:00:00');
  const outDt = new Date(dY + 'T16:00:00');

  const punches = [
    { kind:'in', ts: inDt.toISOString() },
    { kind:'out', ts: outDt.toISOString() }
  ];
  punches.forEach(p=>{ if(CvPerson>0) p.CvPerson = CvPerson; else p.fp_uid = fp_uid; });

  const payload = { api_key, punches };
  $('outPunch').textContent = 'Enviando simulación día...';
  const r = await postJSON('api/punch.php', payload);
  $('outPunch').textContent = JSON.stringify(r, null, 2);
});

/* Limpiar salidas */
$('btnClear').addEventListener('click', ()=>{
  $('outEnroll').textContent = '{ }';
  $('outPunch').textContent = '{ }';
});

/* PING (probar conexión de dispositivo) */
$('btnPing').addEventListener('click', async ()=>{
  const api_key = $('api_key').value.trim();
  if(!api_key){ alert('API Key requerida'); return; }

  $('outEnroll').textContent = 'Verificando conexión...';
  const res = await fetch('api/ping.php?api_key=' + encodeURIComponent(api_key));
  const txt = await res.text();

  try {
    const json = JSON.parse(txt);
    $('outEnroll').textContent = JSON.stringify(json, null, 2);
  } catch {
    $('outEnroll').textContent = txt;
  }
});

</script>

</body>
</html>
