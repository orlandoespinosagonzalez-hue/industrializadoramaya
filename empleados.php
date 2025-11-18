<?php
/* empleados.php — Alta/Baja/Reingreso de trabajadores */
session_start();
require_once "conexion.php"; // $conn (PDO)
// require_once "auth_check.php"; // <- descomenta si ya lo tienes

/* ============== CSRF ============== */
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$CSRF = $_SESSION['csrf'];

/* ============== Utiles ============== */
function isYmd($d){ $x=DateTime::createFromFormat('Y-m-d',$d); return $x && $x->format('Y-m-d')===$d; }
function j($s){ return htmlspecialchars($s,ENT_QUOTES,'UTF-8'); }

$mensaje=""; $errores=[];

/* ============== Catálogos para selects ============== */
$departamentos=[]; $puestos=[];
try{
  // DEBUG: verifica que realmente usas amaya_rrhh (luego puedes quitarlo)
  error_log('[empleados.php] DB conectada: '.$conn->query('SELECT DATABASE()')->fetchColumn());
  $departamentos = $conn->query("SELECT id,nombre FROM departamento ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
  $puestos       = $conn->query("SELECT id,departamento_id,nombre FROM puesto ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
}catch(Exception $e){ $errores[]="Error cargando catálogos: ".$e->getMessage(); }

/* ============== Acciones POST ============== */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $accion  = $_POST['accion'] ?? '';
  $token   = $_POST['csrf'] ?? '';
  if (!hash_equals($CSRF,$token)) { $errores[]="CSRF inválido"; $accion=''; }

  try {
    if ($accion==='alta') {
      $nombres = trim($_POST['nombres'] ?? '');
      $ap1     = trim($_POST['ap1'] ?? '');
      $ap2     = trim($_POST['ap2'] ?? '');
      $depto   = (int)($_POST['departamento_id'] ?? 0);
      $puesto  = (int)($_POST['puesto_id'] ?? 0);
      $fecIni  = trim($_POST['fecini'] ?? '');
      $fecIMSS = trim($_POST['fecimss'] ?? '');                      // NUEVO


      if ($nombres===''||$ap1===''||$ap2==='') $errores[]="Nombre y apellidos son obligatorios.";
      if ($depto<=0) $errores[]="Selecciona un departamento.";
      if ($puesto<=0) $errores[]="Selecciona un puesto.";
      if (!isYmd($fecIni)) $errores[]="Fecha de inicio inválida.";
      if ($fecIMSS !== '' && !isYmd($fecIMSS))                      // NUEVO
  $errores[]="Fecha alta IMSS inválida.";

      // Validar que el puesto pertenece al depto seleccionado
      if ($puesto>0) {
        $st=$conn->prepare("SELECT COUNT(*) FROM puesto WHERE id=:p AND departamento_id=:d");
        $st->execute([':p'=>$puesto,':d'=>$depto]);
        if ((int)$st->fetchColumn()===0) $errores[]="El puesto no pertenece al departamento.";
      }

      // No duplicar persona exacta (nombre+apellidos)
      $dupId=null;
      if (empty($errores)) {
        $st=$conn->prepare("
          SELECT p.CvPerson
          FROM mdtperson p
          JOIN cnombre n   ON n.CvNombre=p.CvNombre AND n.DsNombre=:n
          JOIN capellido a1 ON a1.CvApellido=p.CvApePat AND a1.DsApellido=:ap1
          JOIN capellido a2 ON a2.CvApellido=p.CvApeMat AND a2.DsApellido=:ap2
          LIMIT 1
        ");
        $st->execute([':n'=>$nombres, ':ap1'=>$ap1, ':ap2'=>$ap2]);
        $dupId = $st->fetchColumn();
        if ($dupId) {
          // ¿está dado de baja y quiere reingresar?
          $st2=$conn->prepare("SELECT Activo FROM musuario WHERE CvPerson=:id");
          $st2->execute([':id'=>$dupId]);
          $activo = $st2->fetchColumn();
          if ($activo==='0' || $activo===0) {
            $errores[]="Ya existe la persona con esos datos pero está dada de baja. Usa el botón REACTIVAR en la lista.";
          } else {
            $errores[]="Ya existe una persona con los mismos nombres y apellidos (CvPerson #$dupId).";
          }
        }
      }

      if (empty($errores)) {
        // Inserta/normaliza catálogos y persona
        $conn->beginTransaction();

        // Upsert nombre
        $st=$conn->prepare("INSERT INTO cnombre (DsNombre) VALUES (:n)
                            ON DUPLICATE KEY UPDATE CvNombre=LAST_INSERT_ID(CvNombre)");
        $st->execute([':n'=>$nombres]);
        $CvNombre = (int)$conn->lastInsertId();

        // Upsert apellidos
        $st=$conn->prepare("INSERT INTO capellido (DsApellido) VALUES (:a)
                            ON DUPLICATE KEY UPDATE CvApellido=LAST_INSERT_ID(CvApellido)");
        $st->execute([':a'=>$ap1]);  $CvApePat=(int)$conn->lastInsertId();
        $st->execute([':a'=>$ap2]);  $CvApeMat=(int)$conn->lastInsertId();

        // Persona (empleado tipo=3)
        $st=$conn->prepare("
          INSERT INTO mdtperson (CvNombre,CvApePat,CvApeMat,CvTpPerson,departamento_id,puesto_id)
          VALUES (:n,:ap1,:ap2,3,:d,:p)
          ON DUPLICATE KEY UPDATE
            CvTpPerson=VALUES(CvTpPerson),
            departamento_id=VALUES(departamento_id),
            puesto_id=VALUES(puesto_id),
            CvPerson=LAST_INSERT_ID(CvPerson)
        ");
        $st->execute([':n'=>$CvNombre,':ap1'=>$CvApePat,':ap2'=>$CvApeMat,':d'=>$depto,':p'=>$puesto]);
        $CvPerson=(int)$conn->lastInsertId();

        // musuario (alta / re-alta)
        // DESPUÉS
$st=$conn->prepare("
  INSERT INTO musuario (CvPerson,FecIni,FecAltaIMSS,Activo,BajaTemporal,FecBaja)
  VALUES (:id,:fi,:fimss,1,0,NULL)
  ON DUPLICATE KEY UPDATE 
    FecIni=VALUES(FecIni),
    FecAltaIMSS=VALUES(FecAltaIMSS),
    Activo=1, BajaTemporal=0, FecBaja=NULL
");
$st->execute([':id'=>$CvPerson, ':fi'=>$fecIni, ':fimss'=>($fecIMSS?:null)]);


        $conn->commit();
        $mensaje="Empleado dado de alta correctamente (CvPerson #$CvPerson).";
      }      
    }

    if ($accion==='baja') {
      $id  = (int)($_POST['CvPerson'] ?? 0);
      $fb  = trim($_POST['fecbaja'] ?? '');
      if ($id<=0) $errores[]="Empleado inválido.";
      if (!isYmd($fb)) $errores[]="Fecha de baja inválida.";

      // Validar que no tenga vacaciones aprobadas futuras
      if (empty($errores)) {
        $st=$conn->prepare("SELECT COUNT(*) FROM mvacaciones WHERE CvPerson=:p AND estado='Aprobado' AND fecha_inicio>=CURDATE()");
        $st->execute([':p'=>$id]);
        if ((int)$st->fetchColumn()>0) {
          $errores[]="No se puede dar de baja: tiene vacaciones aprobadas en el futuro. Cancélalas o cámbialas primero.";
        }
      }

      if (empty($errores)) {
        // DESPUÉS
$st=$conn->prepare("UPDATE musuario SET Activo=0, BajaTemporal=1, FecBaja=:fb WHERE CvPerson=:p");
$st->execute([':fb'=>$fb, ':p'=>$id]);
        $mensaje="Empleado dado de baja correctamente.";
      }
    }

    if ($accion==='reactivar') {
      $id  = (int)($_POST['CvPerson'] ?? 0);
      $fi  = trim($_POST['fecini'] ?? '');
      if ($id<=0) $errores[]="Empleado inválido.";
      if (!isYmd($fi)) $errores[]="Fecha de reingreso inválida.";

      if (empty($errores)) {
        // DESPUÉS
$st=$conn->prepare("UPDATE musuario SET Activo=1, BajaTemporal=0, FecIni=:fi, FecBaja=NULL WHERE CvPerson=:p");
$st->execute([':fi'=>$fi, ':p'=>$id]);
        $mensaje="Empleado reactivado correctamente.";
      }
    }
        /* ===== PUNTO 12: NUEVO - EDITAR EMPLEADO ===== */
if ($accion==='editar') {
  $id  = (int)($_POST['CvPerson'] ?? 0);
  $nombres = trim($_POST['nombres'] ?? '');
  $ap1     = trim($_POST['ap1'] ?? '');
  $ap2     = trim($_POST['ap2'] ?? '');
  $depto   = (int)($_POST['departamento_id'] ?? 0);
  $puesto  = (int)($_POST['puesto_id'] ?? 0);
  $fecIni  = trim($_POST['fecini'] ?? '');
  $fecIMSS = trim($_POST['fecimss'] ?? '');

  if ($id<=0) $errores[]="Empleado inválido.";
  if ($nombres===''||$ap1===''||$ap2==='') $errores[]="Nombre y apellidos son obligatorios.";
  if ($depto<=0) $errores[]="Selecciona un departamento.";
  if ($puesto<=0) $errores[]="Selecciona un puesto.";
  if (!isYmd($fecIni)) $errores[]="Fecha de inicio inválida.";
  if ($fecIMSS!=='' && !isYmd($fecIMSS)) $errores[]="Fecha alta IMSS inválida.";

  if ($puesto>0) {
    $st=$conn->prepare("SELECT COUNT(*) FROM puesto WHERE id=:p AND departamento_id=:d");
    $st->execute([':p'=>$puesto,':d'=>$depto]);
    if ((int)$st->fetchColumn()===0) $errores[]="El puesto no pertenece al departamento.";
  }

  if (empty($errores)) {
    $conn->beginTransaction();

    // Upsert nombre y apellidos
    $st=$conn->prepare("INSERT INTO cnombre (DsNombre) VALUES (:n)
                        ON DUPLICATE KEY UPDATE CvNombre=LAST_INSERT_ID(CvNombre)");
    $st->execute([':n'=>$nombres]); $CvNombre=(int)$conn->lastInsertId();

    $st=$conn->prepare("INSERT INTO capellido (DsApellido) VALUES (:a)
                        ON DUPLICATE KEY UPDATE CvApellido=LAST_INSERT_ID(CvApellido)");
    $st->execute([':a'=>$ap1]); $CvApePat=(int)$conn->lastInsertId();
    $st->execute([':a'=>$ap2]); $CvApeMat=(int)$conn->lastInsertId();

    // Actualizar persona
    $st=$conn->prepare("UPDATE mdtperson 
                        SET CvNombre=:n, CvApePat=:ap1, CvApeMat=:ap2, departamento_id=:d, puesto_id=:p
                        WHERE CvPerson=:id");
    $st->execute([':n'=>$CvNombre,':ap1'=>$CvApePat,':ap2'=>$CvApeMat,':d'=>$depto,':p'=>$puesto,':id'=>$id]);

    // Actualizar datos de usuario
    $st=$conn->prepare("UPDATE musuario SET FecIni=:fi, FecAltaIMSS=:fimss WHERE CvPerson=:id");
    $st->execute([':fi'=>$fecIni, ':fimss'=>($fecIMSS?:null), ':id'=>$id]);

    $conn->commit();
    $mensaje="Empleado actualizado correctamente.";
  }
}

/* ===== PUNTO 13: NUEVO - ELIMINAR EMPLEADO ===== */
if ($accion==='eliminar') {
  $id = (int)($_POST['CvPerson'] ?? 0);
  if ($id<=0) $errores[]="Empleado inválido.";

  if (empty($errores)) {
    $conn->beginTransaction();

    // Borra primero musuario (child), luego mdtperson (parent)
    $st=$conn->prepare("DELETE FROM musuario WHERE CvPerson=:p");
    $st->execute([':p'=>$id]);

    $st=$conn->prepare("DELETE FROM mdtperson WHERE CvPerson=:p");
    $st->execute([':p'=>$id]);

    $conn->commit();
    $mensaje="Empleado eliminado permanentemente.";
  }
}



  } catch (Exception $e) {
  if ($conn->inTransaction()) $conn->rollBack();
  $errores[]="Error en operación: ".$e->getMessage();
  error_log('[empleados.php] '.$e->getMessage()); // ← agrega esto
}

}

/* ============== Filtros (GET) ============== */
$q       = trim($_GET['q'] ?? '');
$f_act   = $_GET['activo'] ?? ''; // '', '1', '0'
$f_depto = (int)($_GET['depto'] ?? 0);
$f_puesto= (int)($_GET['puesto'] ?? 0);

$where=[]; $params=[];
$where[] = "p.CvTpPerson=3";

if ($q!=='') {
  $where[]="CONCAT(n.DsNombre,' ',ap1.DsApellido,' ',ap2.DsApellido) LIKE :q";
  $params[':q']="%$q%";
}
if ($f_act==='0' || $f_act==='1') { $where[]="u.Activo=:a"; $params[':a']=(int)$f_act; }
if ($f_depto>0) { $where[]="p.departamento_id=:d"; $params[':d']=$f_depto; }
if ($f_puesto>0){ $where[]="p.puesto_id=:pu";     $params[':pu']=$f_puesto; }

// DESPUÉS
$sql="
  SELECT p.CvPerson,
         CONCAT(n.DsNombre,' ',ap1.DsApellido,' ',ap2.DsApellido) AS empleado,
         n.DsNombre               AS nombres,        -- + para el editor
         ap1.DsApellido           AS ap1,            -- + ap. paterno
         ap2.DsApellido           AS ap2,            -- + ap. materno
         p.departamento_id        AS departamento_id,-- + id depto
         p.puesto_id              AS puesto_id,      -- + id puesto
         d.nombre                 AS departamento,
         pu.nombre                AS puesto,
         u.FecIni,
         u.FecBaja,
         u.Activo,
         u.FecAltaIMSS,                               -- + fecha IMSS
         u.BajaTemporal                               -- + bandera baja temp
  FROM mdtperson p
  JOIN cnombre n     ON n.CvNombre   = p.CvNombre
  JOIN capellido ap1 ON ap1.CvApellido = p.CvApePat
  JOIN capellido ap2 ON ap2.CvApellido = p.CvApeMat
  LEFT JOIN departamento d ON d.id = p.departamento_id
  LEFT JOIN puesto pu      ON pu.id = p.puesto_id
  LEFT JOIN musuario u     ON u.CvPerson = p.CvPerson
";

if ($where) $sql .= " WHERE ".implode(" AND ",$where);
$sql .= " ORDER BY empleado ASC";

$lista=[];
try{
  $st=$conn->prepare($sql);
  $st->execute($params);
  $lista=$st->fetchAll(PDO::FETCH_ASSOC);
}catch(Exception $e){ $errores[]="Error listando empleados: ".$e->getMessage(); }

/* ============== JSON puestos por depto para el form ============== */
$PUESTOS_MAP=[];
foreach($puestos as $p){ $PUESTOS_MAP[$p['departamento_id']][]=['id'=>$p['id'],'nombre'=>$p['nombre']]; }

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Empleados — Altas y Bajas</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css"/>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="stylesheet" href="css/empleados.css">

</head>
<?php
  // justo antes de imprimir cualquier HTML del body:
  $PAGE_TITLE = 'Empleados — Altas y Bajas';
  $ACTIVE     = 'empleados';  // opciones: index | vacaciones | asistencia | empleados | cuenta
  require __DIR__ . '/partials/header.php';
?>

<body>



<div class="main">
  <h2>Altas y Bajas de Empleados</h2>

  <!-- Mensajes -->
  <?php if($mensaje): ?>
    <div id="msg-ok" data-msg="<?= j($mensaje) ?>"></div>
  <?php endif; ?>
  <?php if(!empty($errores)): ?>
    <div id="msg-err" data-err="<?= j(implode("\n",$errores)) ?>"></div>
  <?php endif; ?>

  <!-- Alta de empleado -->
  <div class="form">
    <h3 style="margin:0 0 8px;">Dar de alta (nuevo ingreso)</h3>
    <form method="post" action="">
      <input type="hidden" name="csrf" value="<?= j($CSRF) ?>">
      <input type="hidden" name="accion" value="alta">
      <div class="row">
        <div><label>Nombres</label><input name="nombres" required placeholder="Ej. CARLOS ENRIQUE"></div>
        <div><label>Apellido paterno</label><input name="ap1" required placeholder="Ej. PEREZ"></div>
        <div><label>Apellido materno</label><input name="ap2" required placeholder="Ej. LOPEZ"></div>
        <div>
          <label>Departamento</label>
          <select id="alta_depto" name="departamento_id" required>
            <option value="">-- Selecciona --</option>
            <?php foreach($departamentos as $d): ?>
              <option value="<?= (int)$d['id'] ?>"><?= j($d['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Puesto</label>
          <select id="alta_puesto" name="puesto_id" required>
            <option value="">-- Selecciona --</option>
          </select>
        </div>
        <div><label>Fecha de inicio</label><input type="date" name="fecini" required></div>
        <div><label>Fecha alta IMSS</label><input type="date" name="fecimss"></div>

      </div>
      <button type="submit"><i class="fas fa-user-plus"></i> Dar de alta</button>
    </form>
  </div>

  <!-- Filtros -->
  <div class="form" style="margin-top:12px;">
    <h3 style="margin:0 0 8px;">Buscar / Filtrar</h3>
    <form method="get" class="row">
      <div><label>Nombre contiene</label><input name="q" value="<?= j($q) ?>" placeholder="Buscar por nombre..."></div>
      <div>
        <label>Estado</label>
        <select name="activo">
          <option value="">-- Todos --</option>
          <option value="1" <?= $f_act==='1'?'selected':'' ?>>Activos</option>
          <option value="0" <?= $f_act==='0'?'selected':'' ?>>Baja</option>
        </select>
      </div>
      <div>
        <label>Departamento</label>
        <select id="f_depto" name="depto">
          <option value="">-- Todos --</option>
          <?php foreach($departamentos as $d): ?>
            <option value="<?= (int)$d['id'] ?>" <?= $f_depto===$d['id']?'selected':'' ?>><?= j($d['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Puesto</label>
        <select id="f_puesto" name="puesto">
          <option value="">-- Todos --</option>
          <?php foreach($puestos as $p): ?>
            <option data-depto="<?= (int)$p['departamento_id'] ?>" value="<?= (int)$p['id'] ?>" <?= $f_puesto===$p['id']?'selected':'' ?>><?= j($p['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><button class="btn" type="submit"><i class="fas fa-filter"></i> Aplicar</button>
          <a class="btn" href="empleados.php"><i class="fas fa-eraser"></i> Limpiar</a></div>
    </form>
  </div>

  <!-- Listado -->
  <div style="overflow:auto;">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Empleado</th>
          <th>Departamento</th>
          <th>Puesto</th>
          <th>Inicio</th>
          <th>Baja</th>
          <th>Estado</th>
          <th style="min-width:220px;">Acciones</th>
        </tr>
      </thead>
      <tbody>
      <?php if(empty($lista)): ?>
        <tr><td colspan="8">Sin resultados.</td></tr>
      <?php else: foreach($lista as $r): ?>
        <tr>
          <td><?= (int)$r['CvPerson'] ?></td>
          <td><?= j($r['empleado']) ?></td>
          <td><?= j($r['departamento'] ?? '') ?></td>
          <td><?= j($r['puesto'] ?? '') ?></td>
          <td><?= j($r['FecIni']) ?></td>
          <td><?= j($r['FecBaja'] ?? '') ?></td>
          <!-- DESPUÉS -->
<td>
  <button class="btn" onclick="editar(
    <?= (int)$r['CvPerson'] ?>,
    '<?= j($r['nombres']) ?>',
    '<?= j($r['ap1']) ?>',
    '<?= j($r['ap2']) ?>',
    <?= (int)($r['departamento_id'] ?? 0) ?>,
    <?= (int)($r['puesto_id'] ?? 0) ?>,
    '<?= j($r['FecIni'] ?? '') ?>',
    '<?= j($r['FecAltaIMSS'] ?? '') ?>'
  )"><i class="fas fa-edit"></i> Editar</button>

  
  <button class="btn" onclick="eliminar(<?= (int)$r['CvPerson'] ?>,'<?= j($r['empleado']) ?>')">
    <i class="fas fa-trash-alt"></i> Eliminar
  </button>
</td>

          <td>
            <?php if((int)$r['Activo']===1): ?>
              <button class="btn" onclick="baja(<?= (int)$r['CvPerson'] ?>,'<?= j($r['empleado']) ?>')">
                <i class="fas fa-user-times"></i> Dar de baja
              </button>
            <?php else: ?>
              <button class="btn" onclick="reactivar(<?= (int)$r['CvPerson'] ?>,'<?= j($r['empleado']) ?>')">
                <i class="fas fa-user-check"></i> Reactivar
              </button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>




<!-- Formularios ocultos para baja/reactivar -->
<form id="form-baja" method="post" action="" style="display:none;">
  <input type="hidden" name="csrf" value="<?= j($CSRF) ?>">
  <input type="hidden" name="accion" value="baja">
  <input type="hidden" name="CvPerson" id="baja_id">
  <input type="date"  name="fecbaja"  id="baja_fecha">
</form>
<form id="form-react" method="post" action="" style="display:none;">
  <input type="hidden" name="csrf" value="<?= j($CSRF) ?>">
  <input type="hidden" name="accion" value="reactivar">
  <input type="hidden" name="CvPerson" id="react_id">
  <input type="date"  name="fecini"   id="react_fecha">
</form>
<form id="form-edit" method="post" action="" style="display:none;">
  <input type="hidden" name="csrf" value="<?= j($CSRF) ?>">
  <input type="hidden" name="accion" value="editar">
  <input type="hidden" name="CvPerson" id="edit_id">
  <input type="hidden" name="nombres" id="edit_nombres">
  <input type="hidden" name="ap1" id="edit_ap1">
  <input type="hidden" name="ap2" id="edit_ap2">
  <input type="hidden" name="departamento_id" id="edit_depto">
  <input type="hidden" name="puesto_id" id="edit_puesto">
  <input type="hidden" name="fecini" id="edit_fecini">
  <input type="hidden" name="fecimss" id="edit_fecimss">
</form>

<form id="form-del" method="post" action="" style="display:none;">
  <input type="hidden" name="csrf" value="<?= j($CSRF) ?>">
  <input type="hidden" name="accion" value="eliminar">
  <input type="hidden" name="CvPerson" id="del_id">
</form>


<script>
/* ==============================
   Mensajes de éxito / error
   ============================== */
(function(){
  const ok = document.getElementById('msg-ok');
  if (ok) {
    Swal.fire({
      icon: 'success',
      title: 'Listo',
      text: ok.getAttribute('data-msg'),
      confirmButtonColor: '#0D47A1'
    });
  }

  const er = document.getElementById('msg-err');
  if (er) {
    Swal.fire({
      icon: 'error',
      title: 'Error',
      text: er.getAttribute('data-err'),
      confirmButtonColor: '#0D47A1'
    });
  }
})();

/* ==============================
   Carga dependiente de puestos en el alta
   ============================== */
const DEPTOS = <?= json_encode($departamentos, JSON_UNESCAPED_UNICODE) ?>;
const PUESTOS_MAP = <?= json_encode($PUESTOS_MAP, JSON_UNESCAPED_UNICODE) ?>;
const altaDepto = document.getElementById('alta_depto');
const altaPuesto = document.getElementById('alta_puesto');

function fillPuestos(selectDepto, selectPuesto) {
  const did = parseInt(selectDepto.value || '0', 10);
  selectPuesto.innerHTML = '<option value="">-- Selecciona --</option>';
  if (!did || !PUESTOS_MAP[did]) return;
  for (const p of PUESTOS_MAP[did]) {
    const opt = document.createElement('option');
    opt.value = p.id;
    opt.textContent = p.nombre;
    selectPuesto.appendChild(opt);
  }
}

if (altaDepto && altaPuesto) {
  altaDepto.addEventListener('change', () => fillPuestos(altaDepto, altaPuesto));
}

/* ==============================
   Dar de baja
   ============================== */
function baja(id, nombre) {
  Swal.fire({
    title: 'Dar de baja',
    html: `Empleado: <b>${nombre}</b><br><br>
           <div style="text-align:left">Fecha de baja:</div>
           <input id="fbaja" type="date" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;">`,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: 'Confirmar',
    cancelButtonText: 'Cancelar',
    confirmButtonColor: '#0D47A1',
    cancelButtonColor: '#BDBDBD',
    preConfirm: () => {
      const v = document.getElementById('fbaja').value;
      if (!v) {
        Swal.showValidationMessage('Selecciona fecha de baja');
        return false;
      }
      return v;
    }
  }).then(res => {
    if (!res.isConfirmed) return;
    document.getElementById('baja_id').value = String(id);
    document.getElementById('baja_fecha').value = res.value;
    document.getElementById('form-baja').submit();
  });
}

/* ==============================
   Reactivar empleado
   ============================== */
function reactivar(id, nombre) {
  Swal.fire({
    title: 'Reactivar empleado',
    html: `Empleado: <b>${nombre}</b><br><br>
           <div style="text-align:left">Fecha de reingreso:</div>
           <input id="fini" type="date" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;">`,
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: 'Reactivar',
    cancelButtonText: 'Cancelar',
    confirmButtonColor: '#0D47A1',
    cancelButtonColor: '#BDBDBD',
    preConfirm: () => {
      const v = document.getElementById('fini').value;
      if (!v) {
        Swal.showValidationMessage('Selecciona fecha de reingreso');
        return false;
      }
      return v;
    }
  }).then(res => {
    if (!res.isConfirmed) return;
    document.getElementById('react_id').value = String(id);
    document.getElementById('react_fecha').value = res.value;
    document.getElementById('form-react').submit();
  });
}

/* ==============================
   Editar empleado
   ============================== */
function editar(id, nombres, ap1, ap2, deptoId, puestoId, fecIni, fecIMSS) {
  const deptOptions = ['<option value="">-- Selecciona --</option>']
    .concat((DEPTOS || []).map(d => `<option value="${d.id}" ${String(d.id) === String(deptoId) ? 'selected' : ''}>${d.nombre}</option>`))
    .join('');

  const puestos = PUESTOS_MAP[deptoId] || [];
  const puestoOptions = ['<option value="">-- Selecciona --</option>']
    .concat(puestos.map(p => `<option value="${p.id}" ${String(p.id) === String(puestoId) ? 'selected' : ''}>${p.nombre}</option>`))
    .join('');

  Swal.fire({
    title: 'Editar empleado',
    html: `
      <div style="text-align:left">
        <label>Nombres</label>
        <input id="e_nombres" class="swal2-input" style="width:100%" value="${nombres || ''}">
        <label>Apellido paterno</label>
        <input id="e_ap1" class="swal2-input" style="width:100%" value="${ap1 || ''}">
        <label>Apellido materno</label>
        <input id="e_ap2" class="swal2-input" style="width:100%" value="${ap2 || ''}">
        <label>Departamento</label>
        <select id="e_depto" class="swal2-input" style="width:100%">${deptOptions}</select>
        <label>Puesto</label>
        <select id="e_puesto" class="swal2-input" style="width:100%">${puestoOptions}</select>
        <label>Fecha inicio</label>
        <input id="e_fi" type="date" class="swal2-input" style="width:100%" value="${fecIni || ''}">
        <label>Fecha alta IMSS</label>
        <input id="e_fimss" type="date" class="swal2-input" style="width:100%" value="${fecIMSS || ''}">
      </div>
    `,
    focusConfirm: false,
    showCancelButton: true,
    confirmButtonText: 'Guardar',
    cancelButtonText: 'Cancelar',
    confirmButtonColor: '#0D47A1',
    cancelButtonColor: '#BDBDBD',
    didOpen: () => {
      const depSel = document.getElementById('e_depto');
      const pueSel = document.getElementById('e_puesto');
      depSel.addEventListener('change', () => {
        const did = parseInt(depSel.value || '0', 10);
        const arr = PUESTOS_MAP[did] || [];
        pueSel.innerHTML = '<option value="">-- Selecciona --</option>' +
          arr.map(p => `<option value="${p.id}">${p.nombre}</option>`).join('');
      });
    },
    preConfirm: () => {
      const data = {
        nombres: document.getElementById('e_nombres').value.trim(),
        ap1: document.getElementById('e_ap1').value.trim(),
        ap2: document.getElementById('e_ap2').value.trim(),
        depto: document.getElementById('e_depto').value,
        puesto: document.getElementById('e_puesto').value,
        fi: document.getElementById('e_fi').value,
        fimss: document.getElementById('e_fimss').value
      };
      if (!data.nombres || !data.ap1 || !data.ap2) {
        Swal.showValidationMessage('Completa nombres y apellidos');
        return false;
      }
      if (!data.depto || !data.puesto) {
        Swal.showValidationMessage('Selecciona departamento y puesto');
        return false;
      }
      if (!data.fi) {
        Swal.showValidationMessage('Fecha de inicio requerida');
        return false;
      }
      return data;
    }
  }).then(res => {
    if (!res.isConfirmed) return;
    document.getElementById('edit_id').value = String(id);
    document.getElementById('edit_nombres').value = res.value.nombres;
    document.getElementById('edit_ap1').value = res.value.ap1;
    document.getElementById('edit_ap2').value = res.value.ap2;
    document.getElementById('edit_depto').value = res.value.depto;
    document.getElementById('edit_puesto').value = res.value.puesto;
    document.getElementById('edit_fecini').value = res.value.fi;
    document.getElementById('edit_fecimss').value = res.value.fimss;
    document.getElementById('form-edit').submit();
  });
}

/* ==============================
   Eliminar empleado
   ============================== */
function eliminar(id, nombre) {
  Swal.fire({
    title: 'Eliminar empleado',
    html: `<div style="text-align:left">
            Esta acción es permanente.<br>
            Empleado: <b>${nombre}</b>
           </div>`,
    icon: 'error',
    showCancelButton: true,
    confirmButtonText: 'Eliminar',
    cancelButtonText: 'Cancelar',
    confirmButtonColor: '#0D47A1',
    cancelButtonColor: '#BDBDBD'
  }).then(res => {
    if (!res.isConfirmed) return;
    document.getElementById('del_id').value = String(id);
    document.getElementById('form-del').submit();
  });
}

/* ==============================
   Filtrar puestos por departamento
   ============================== */
(function(){
  const fDepto = document.getElementById('f_depto');
  const fPuesto = document.getElementById('f_puesto');

  function applyFilter() {
    const d = parseInt(fDepto.value || '0', 10);
    for (const opt of fPuesto.options) {
      const belongs = !d || !opt.dataset.depto || parseInt(opt.dataset.depto, 10) === d;
      opt.hidden = !belongs && opt.value !== ''; // deja visible la opción vacía
    }
  }

  if (fDepto && fPuesto) {
    fDepto.addEventListener('change', applyFilter);
    applyFilter();
  }
})();
</script>

</body>
</html>
