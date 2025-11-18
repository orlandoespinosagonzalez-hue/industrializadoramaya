<?php
// capacitacion.php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/auth_check.php';
date_default_timezone_set('America/Mexico_City');

$PAGE_TITLE = 'Capacitación — Repositorio';
$ACTIVE     = 'capacitacion';

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$CSRF = $_SESSION['csrf'];

$UPLOAD_DIR = __DIR__ . '/uploads/capacitacion';          // ruta real
$PUBLIC_BASE = 'uploads/capacitacion';                    // base pública (para previews de media/imágenes)

// Asegura carpeta
if (!is_dir($UPLOAD_DIR)) mkdir($UPLOAD_DIR, 0775, true);

// ====== Helpers ======
function allowedExts() {
  return [
    // Documentos
    'pdf','doc','docx','xls','xlsx','ppt','pptx','txt',
    // Imágenes
    'jpg','jpeg','png','gif','webp',
    // Audio
    'mp3','wav','ogg','m4a',
    // Video
    'mp4','webm','mov','mkv'
  ];
}
function extFromName($name) {
  $p = strrpos($name,'.');
  return $p === false ? '' : strtolower(substr($name,$p+1));
}
function safeFileName($len=40){
  return bin2hex(random_bytes($len/2));
}
function fmtSize($bytes){
  $u=['B','KB','MB','GB','TB']; $i=0;
  while($bytes>=1024 && $i<count($u)-1){$bytes/=1024;$i++;}
  return number_format($bytes, ($i?1:0)).' '.$u[$i];
}
$ERRORS=[]; $OK=null;

// ====== POST: Subida ======
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'upload') {
  try {
    if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) throw new Exception('CSRF inválido.');
    if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
      throw new Exception('Archivo no recibido.');
    }

    $titulo      = trim($_POST['titulo'] ?? '');
    $categoria   = trim($_POST['categoria'] ?? 'General');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $expires_at  = trim($_POST['expires_at'] ?? '');
    if ($titulo==='') $titulo = '(sin título)';

    $orig = $_FILES['archivo']['name'];
    $ext  = extFromName($orig);
    if ($ext === '' || !in_array($ext, allowedExts(), true)) {
      throw new Exception('Extensión no permitida: '.$ext);
    }

    // límites (p.ej. 256MB)
    $size = (int)$_FILES['archivo']['size'];
    if ($size <= 0 || $size > 268435456) throw new Exception('Tamaño excede el límite (256MB).');

    $stored = safeFileName().'.'.$ext;
    $mime   = mime_content_type($_FILES['archivo']['tmp_name']) ?: 'application/octet-stream';
    $dest   = $UPLOAD_DIR . '/' . $stored;

    if (!move_uploaded_file($_FILES['archivo']['tmp_name'], $dest)) {
      throw new Exception('No se pudo guardar el archivo.');
    }

    // Inserta registro
    $uploaderId   = $_SESSION['usuario']['id'] ?? null;
    $uploaderName = $_SESSION['usuario']['nombre_completo'] ?? ($_SESSION['usuario']['username'] ?? 'desconocido');
    $expiresSQL   = ($expires_at!=='' ? $expires_at : null);

    $sql = "INSERT INTO capacitacion_archivos
            (titulo,categoria,descripcion,original_name,stored_name,mime_type,ext,size_bytes,uploader_id,uploader_name,expires_at)
            VALUES (:ti,:ca,:de,:on,:sn,:mi,:ex,:sz,:uid,:uname,:exp)";
    $st = $conn->prepare($sql);
    $st->execute([
      ':ti'=>$titulo, ':ca'=>$categoria, ':de'=>$descripcion,
      ':on'=>$orig, ':sn'=>$stored, ':mi'=>$mime, ':ex'=>$ext, ':sz'=>$size,
      ':uid'=>$uploaderId, ':uname'=>$uploaderName, ':exp'=>$expiresSQL
    ]);

    $OK = "Archivo subido correctamente.";

  } catch (Throwable $e) {
    $ERRORS[] = $e->getMessage();
  }
}

// ====== POST: Eliminar (soft delete) ======
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'delete') {
  try{
    if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) throw new Exception('CSRF inválido.');
    $id = (int)($_POST['id'] ?? 0);
    if ($id<=0) throw new Exception('ID inválido.');

    $st = $conn->prepare("UPDATE capacitacion_archivos SET is_deleted=1 WHERE id=:id");
    $st->execute([':id'=>$id]);
    $OK = 'Archivo eliminado (ya no visible).';
  }catch(Throwable $e){
    $ERRORS[] = $e->getMessage();
  }
}

// ====== Filtros de listado ======
$q    = trim($_GET['q'] ?? '');
$cat  = trim($_GET['cat'] ?? '');
$showExpired = isset($_GET['expired']) ? (int)$_GET['expired'] : 0;

$where = "is_deleted=0";
$params = [];
if ($q!==''){ $where .= " AND (titulo LIKE :q OR descripcion LIKE :q OR original_name LIKE :q)"; $params[':q']="%$q%";}
if ($cat!==''){ $where .= " AND categoria=:c"; $params[':c']=$cat; }
if (!$showExpired){ $where .= " AND (expires_at IS NULL OR expires_at >= CURDATE())"; }

// Paginación simple
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 25; $offset = ($page-1)*$limit;

// totales
$st = $conn->prepare("SELECT COUNT(*) FROM capacitacion_archivos WHERE $where");
$st->execute($params);
$total = (int)$st->fetchColumn();

// datos
$st = $conn->prepare("SELECT * FROM capacitacion_archivos WHERE $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// categorías disponibles
$cats = $conn->query("SELECT DISTINCT categoria FROM capacitacion_archivos WHERE is_deleted=0 ORDER BY categoria")->fetchAll(PDO::FETCH_COLUMN);

require __DIR__ . '/partials/header.php';
?>
<link rel="stylesheet" href="css/capacitacion.css">


<div class="cap-wrap">
  <h2 style="color:#800020;margin:6px 0 12px;">Repositorio de capacitación</h2>

  <?php if ($OK): ?>
    <div class="card" style="border-color:#cfe9cf;background:#f5fff5"><?= htmlspecialchars($OK,ENT_QUOTES,'UTF-8') ?></div>
  <?php endif; ?>
  <?php if ($ERRORS): ?>
    <div class="card" style="border-color:#f1c0c0;background:#fff6f6"><?= htmlspecialchars(implode("\n",$ERRORS),ENT_QUOTES,'UTF-8') ?></div>
  <?php endif; ?>

  <div class="grid">
    <!-- Subida -->
    <div class="card">
      <h3>Subir nuevo recurso</h3>
      <form method="post" enctype="multipart/form-data" class="row">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
        <input type="hidden" name="action" value="upload">
        <div class="col-6">
          <label>Título</label>
          <input class="pill" type="text" name="titulo" placeholder="p.ej. Inducción SST" required>
        </div>
        <div class="col-3">
          <label>Categoría</label>
          <input class="pill" type="text" name="categoria" list="catlist" placeholder="General">
          <datalist id="catlist">
            <?php foreach($cats as $c) echo '<option value="'.htmlspecialchars($c,ENT_QUOTES,'UTF-8').'">'; ?>
          </datalist>
        </div>
        <div class="col-3">
          <label>Caduca (opcional)</label>
          <input class="pill" type="date" name="expires_at">
        </div>
        <div class="col-12">
          <label>Descripción</label>
          <textarea class="pill" name="descripcion" rows="2" placeholder="Breve descripción, objetivos o notas"></textarea>
        </div>
        <div class="col-12">
          <label>Archivo (máx 256MB) — PDF, DOCX, PPTX, XLSX, imágenes, audio, video</label>
          <input class="pill" type="file" name="archivo" required>
        </div>
        <div class="col-12" style="display:flex;gap:8px;justify-content:flex-end">
          <button class="btn btn-primary" type="submit"><i class="fas fa-cloud-upload-alt"></i> Subir</button>
        </div>
      </form>
      <p class="muted">Sugerencias: usa categorías como <em>Inducción</em>, <em>Seguridad</em>, <em>Producción</em>, <em>Ventas</em>, etc.</p>
    </div>

    <!-- Listado / filtros -->
    <div class="card">
      <h3>Recursos</h3>

      <form method="get" class="toolbar">
        <div>
          <label>Búsqueda</label><br>
          <input class="pill" type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="título, nombre original, descripción">
        </div>
        <div>
          <label>Categoría</label><br>
          <select class="pill" name="cat">
            <option value="">— Todas —</option>
            <?php foreach($cats as $c): ?>
              <option value="<?= htmlspecialchars($c,ENT_QUOTES) ?>" <?= $cat===$c?'selected':'' ?>>
                <?= htmlspecialchars($c) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Opciones</label><br>
          <label class="pill" style="display:inline-flex;gap:6px;align-items:center">
            <input type="checkbox" name="expired" value="1" <?= $showExpired?'checked':'' ?>> Mostrar caducados
          </label>
        </div>
        <div style="margin-left:auto">
          <button class="btn" type="submit"><i class="fas fa-filter"></i> Filtrar</button>
          <a class="btn" href="capacitacion.php"><i class="fas fa-undo"></i> Limpiar</a>
        </div>
      </form>

      <div style="overflow:auto;margin-top:10px">
        <table>
          <thead>
            <tr>
              <th>Título</th>
              <th>Archivo</th>
              <th>Categoría</th>
              <th>Tamaño</th>
              <th>Subido por</th>
              <th>Fecha</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="7">No hay archivos.</td></tr>
          <?php else: foreach($rows as $r): ?>
            <tr>
              <td>
                <strong><?= htmlspecialchars($r['titulo']) ?></strong>
                <?php if ($r['expires_at'] && $r['expires_at'] < date('Y-m-d')): ?>
                  <span class="badge" title="Caducado" style="background:#ffe5e5;color:#900">caducado</span>
                <?php endif; ?>
                <div class="muted"><?= nl2br(htmlspecialchars($r['descripcion'])) ?></div>
              </td>
              <td>
                <div><?= htmlspecialchars($r['original_name']) ?></div>
                <div class="muted"><?= htmlspecialchars($r['mime_type']) ?></div>
                <?php
                  $isImg = str_starts_with($r['mime_type'],'image/');
                  $isPdf = $r['mime_type']==='application/pdf';
                  $isAud = str_starts_with($r['mime_type'],'audio/');
                  $isVid = str_starts_with($r['mime_type'],'video/');
                  $publicUrl = $PUBLIC_BASE . '/' . rawurlencode($r['stored_name']);
                  if ($isImg): ?>
                    <img class="preview-embed" src="<?= $publicUrl ?>" alt="" style="max-height:160px;border:1px solid #eee;border-radius:8px;margin-top:4px">
                  <?php elseif($isPdf): ?>
                    <iframe class="preview-embed" src="capacitacion_get.php?id=<?= (int)$r['id'] ?>&mode=inline" style="height:180px;border:1px solid #eee;border-radius:8px;margin-top:4px"></iframe>
                  <?php elseif($isAud): ?>
                    <audio class="preview-embed" controls src="capacitacion_get.php?id=<?= (int)$r['id'] ?>&mode=inline" style="margin-top:4px"></audio>
                  <?php elseif($isVid): ?>
                    <video class="preview-embed" controls src="capacitacion_get.php?id=<?= (int)$r['id'] ?>&mode=inline" style="margin-top:4px"></video>
                  <?php endif; ?>
              </td>
              <td><span class="badge"><?= htmlspecialchars($r['categoria']) ?></span></td>
              <td><?= fmtSize((int)$r['size_bytes']) ?></td>
              <td><?= htmlspecialchars($r['uploader_name'] ?? '') ?></td>
              <td>
                <div><?= htmlspecialchars(substr($r['created_at'],0,16)) ?></div>
                <div class="muted"><?= (int)$r['download_count'] ?> descargas</div>
              </td>
              <td class="actions">
                <a class="btn" href="capacitacion_get.php?id=<?= (int)$r['id'] ?>&mode=download">
                  <i class="fas fa-download"></i> Descargar
                </a>
                <form method="post" onsubmit="return confirm('¿Eliminar este archivo del listado?');">
                  <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button class="btn btn-danger" type="submit"><i class="fas fa-trash"></i> Eliminar</button>
                </form>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <?php
        $pages = max(1, ceil($total/$limit));
        if ($pages>1):
      ?>
        <div class="actions" style="margin-top:10px">
          <?php for($p=1;$p<=$pages;$p++):
            $qs = $_GET; $qs['page']=$p; $href = 'capacitacion.php?'.http_build_query($qs);
          ?>
            <a class="btn <?= $p===$page?'btn-primary':'' ?>" href="<?= $href ?>"><?= $p ?></a>
          <?php endfor; ?>
        </div>
      <?php endif; ?>

    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
