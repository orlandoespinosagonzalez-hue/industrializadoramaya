<?php
session_start();
require_once "conexion.php"; // $conn (PDO)

/* ---------------- CSRF ---------------- */
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$CSRF = $_SESSION['csrf'];

/* ---------------- Utilidades ---------------- */
$mensaje = "";
$errores = [];
$estadosValidos = ['Solicitado','Aprobado','Rechazado'];

function isValidDateYmd(string $d): bool {
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d;
}

/* ---------------- Festivos México 2025 ---------------- */
/* Usa exactamente los que compartiste. Se puede extender por año. */
$FESTIVOS_2025 = [
  '2025-02-03' => 'Aniversario de la Constitución (puente)',
  '2025-03-17' => 'Natalicio de Benito Juárez (puente)',
  '2025-05-01' => 'Día del Trabajo',
  '2025-09-16' => 'Día de la Independencia',
  '2025-11-17' => 'Día de la Revolución (puente)',
  '2025-12-25' => 'Navidad',
];

/* Devuelve los festivos que caen dentro de [fi, ff] (ambos inclusive) */
function festivosEnRango(array $festivos, string $fi, string $ff): array {
    if (!isValidDateYmd($fi) || !isValidDateYmd($ff)) return [];
    if ($ff < $fi) return [];
    $enRango = [];
    foreach ($festivos as $d => $desc) {
        if ($d >= $fi && $d <= $ff) $enRango[$d] = $desc;
    }
    return $enRango;
}


/* Traslape inclusive: a <= FF && b >= FI */
function obtenerTraslapes(PDO $conn, int $CvPerson, string $fi, string $ff, ?int $excludeId = null): array {
    try {
        $sql = "SELECT id, fecha_inicio, fecha_fin
                  FROM mvacaciones
                 WHERE CvPerson = :p
                   AND fecha_inicio <= :ff
                   AND fecha_fin >= :fi";

        if (!is_null($excludeId)) {
            $sql .= " AND id <> :id";
        }

        $st = $conn->prepare($sql);

        $params = [
            ':p'  => $CvPerson,
            ':fi' => $fi,
            ':ff' => $ff
        ];
        if (!is_null($excludeId)) {
            $params[':id'] = $excludeId;
        }

        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error en obtenerTraslapes: " . $e->getMessage());
        return [];
    }
}


/* Duplicado exacto: misma persona y mismo rango */
function existeRangoExacto(PDO $conn, int $CvPerson, string $fi, string $ff, ?int $excludeId = null): bool {
    try {
        $sql = "SELECT COUNT(*)
                  FROM mvacaciones
                 WHERE CvPerson = :p
                   AND fecha_inicio = :fi
                   AND fecha_fin = :ff";

        if (!is_null($excludeId)) {
            $sql .= " AND id <> :id";
        }

        $st = $conn->prepare($sql);
        $params = [':p'=>$CvPerson, ':fi'=>$fi, ':ff'=>$ff];
        if (!is_null($excludeId)) {
            $params[':id'] = $excludeId;
        }

        $st->execute($params);
        return (int)$st->fetchColumn() > 0;
    } catch (PDOException $e) {
        error_log("Error en existeRangoExacto: " . $e->getMessage());
        return false;
    }
}


/* ---- Política de días por años (ajusta si tu empresa usa otra) ---- */
function dias_por_anos(int $y): int {
    if ($y <= 0) return 0;
    $tabla = [1=>12, 2=>14, 3=>16, 4=>18, 5=>20, 6=>22, 7=>24, 8=>26, 9=>28, 10=>30];
    if ($y <= 10) return $tabla[$y];
    return 30; // máximo 30 a partir del año 11
}

function getCicloActual(PDO $conn, int $CvPerson): array {
    $sql = "SELECT 
              DATE_ADD(FecIni, INTERVAL TIMESTAMPDIFF(YEAR, FecIni, CURDATE()) YEAR) AS ini,
              DATE_SUB(DATE_ADD(DATE_ADD(FecIni, INTERVAL TIMESTAMPDIFF(YEAR, FecIni, CURDATE()) YEAR), INTERVAL 1 YEAR), INTERVAL 1 DAY) AS fin
            FROM musuario WHERE CvPerson=:p LIMIT 1";
    $st = $conn->prepare($sql); $st->execute([':p'=>$CvPerson]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? [$r['ini'], $r['fin']] : [null, null];
}

function diasDentroDeCiclo(string $fi, string $ff, string $ini, string $fin): int {
    $a = max($fi, $ini); $b = min($ff, $fin);
    return ($b >= $a) ? ((new DateTime($b))->diff(new DateTime($a))->days + 1) : 0;
}

function derechoActual(PDO $conn, int $CvPerson): int {
    $st = $conn->prepare("SELECT fn_dias_vacaciones(fn_anios_laborados(:p))");
    $st->execute([':p'=>$CvPerson]);
    return (int)$st->fetchColumn();
}

function usadosEnCiclo(PDO $conn, int $CvPerson, string $ini, string $fin, ?int $excludeId=null): int {
    try {
        $sql = "SELECT IFNULL(SUM(DATEDIFF(LEAST(v.fecha_fin, :fin), GREATEST(v.fecha_inicio, :ini)) + 1), 0)
                  FROM mvacaciones v
                 WHERE v.CvPerson = :p
                   AND v.estado = 'Aprobado'
                   AND v.fecha_inicio <= :fin
                   AND v.fecha_fin >= :ini";

        if (!is_null($excludeId)) {
            $sql .= " AND v.id <> :id";
        }

        $st = $conn->prepare($sql);

        $params = [
            ':p'   => $CvPerson,
            ':ini' => $ini,
            ':fin' => $fin
        ];
        if (!is_null($excludeId)) {
            $params[':id'] = $excludeId;
        }

        $st->execute($params);
        return (int)$st->fetchColumn();
    } catch (PDOException $e) {
        error_log("Error en usadosEnCiclo: " . $e->getMessage());
        return 0;
    }
}





function empleadoEsVentas(PDO $conn, int $CvPerson): bool {
    try {
        $st = $conn->prepare("
            SELECT UPPER(TRIM(COALESCE(d.nombre,'')))
            FROM mdtperson p
            LEFT JOIN departamento d ON d.id = p.departamento_id
            WHERE p.CvPerson = :p
            LIMIT 1
        ");
        $st->execute([':p'=>$CvPerson]);
        $dep = $st->fetchColumn();
        if (!$dep) return false;
        return ($dep === 'VENTAS' || $dep === 'DEPTO VENTAS' || strpos($dep, 'VENTAS') !== false);
    } catch (Exception $e) {
        return false;
    }
}

function tieneVacacionesTraslapadas(PDO $conn, int $CvPerson, string $fi, string $ff, ?int $excludeId=null): bool {
    try {
        $sql = "SELECT 1
                  FROM mvacaciones
                 WHERE CvPerson = :p
                   AND estado IN ('Solicitado','Aprobado')
                   AND fecha_inicio <= :ff
                   AND fecha_fin >= :fi";

        if (!is_null($excludeId)) {
            $sql .= " AND id <> :id";
        }

        $st = $conn->prepare($sql);
        $params = [':p'=>$CvPerson, ':fi'=>$fi, ':ff'=>$ff];
        if (!is_null($excludeId)) {
            $params[':id'] = $excludeId;
        }

        $st->execute($params);
        return (bool)$st->fetchColumn();
    } catch (PDOException $e) {
        error_log("Error en tieneVacacionesTraslapadas: " . $e->getMessage());
        return false;
    }
}

function estaCubriendoTraslapado(PDO $conn, int $CvPerson, string $fi, string $ff, ?int $excludeId=null): bool {
    try {
        $sql = "SELECT 1
                  FROM mvacaciones
                 WHERE CvPosturero = :p
                   AND estado IN ('Solicitado','Aprobado')
                   AND fecha_inicio <= :ff
                   AND fecha_fin >= :fi";

        if (!is_null($excludeId)) {
            $sql .= " AND id <> :id";
        }

        $st = $conn->prepare($sql);
        $params = [':p'=>$CvPerson, ':fi'=>$fi, ':ff'=>$ff];
        if (!is_null($excludeId)) {
            $params[':id'] = $excludeId;
        }

        $st->execute($params);
        return (bool)$st->fetchColumn();
    } catch (PDOException $e) {
        error_log("Error en estaCubriendoTraslapado: " . $e->getMessage());
        return false;
    }
}



/* ---------------- Empleados (solo tipo Empleado) ---------------- */
$empleados = [];
try {
    $stmtEmp = $conn->query("
        SELECT 
            p.CvPerson,
            CONCAT(n.DsNombre,' ', ap1.DsApellido,' ', ap2.DsApellido) AS nombre
        FROM mdtperson p
        JOIN cnombre   n   ON p.CvNombre = n.CvNombre
        JOIN capellido ap1 ON p.CvApePat = ap1.CvApellido
        JOIN capellido ap2 ON p.CvApeMat = ap2.CvApellido
        WHERE p.CvTpPerson = 3
        ORDER BY nombre ASC
    ");
    $empleados = $stmtEmp->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $errores[] = "Error cargando empleados: ".$e->getMessage();
}

/* ---------------- Editar ---------------- */
$editRow = null;
if (isset($_GET['action'], $_GET['id']) && $_GET['action']==='edit') {
    $id = (int)$_GET['id'];
    $st = $conn->prepare("SELECT * FROM mvacaciones WHERE id=:id LIMIT 1");
    $st->execute([':id'=>$id]);
    $editRow = $st->fetch(PDO::FETCH_ASSOC);
    if (!$editRow) $errores[] = "No se encontró el registro #$id para editar.";
}

/* ---------------- Eliminar ---------------- */
if (isset($_GET['action'], $_GET['id'], $_GET['csrf']) && $_GET['action']==='delete') {
    if (hash_equals($CSRF, $_GET['csrf'])) {
        $id = (int)$_GET['id'];
        try {
            $del = $conn->prepare("DELETE FROM mvacaciones WHERE id = :id");
            $del->execute([':id'=>$id]);
            header("Location: ".$_SERVER['PHP_SELF']."?msg=".urlencode("Registro #$id eliminado correctamente."));
            exit;
        } catch (Exception $e) {
            $errores[] = "No se pudo eliminar: ".$e->getMessage();
        }
    } else {
        $errores[] = "Token CSRF inválido.";
    }
}


// ---------------- Crear / Actualizar ----------------
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Datos base
    $accion       = $_POST['accion'] ?? 'crear';
    $csrfPost     = $_POST['csrf'] ?? '';
    $idPost       = isset($_POST['id']) ? (int)$_POST['id'] : null;
    $CvPerson     = isset($_POST['CvPerson']) ? (int)$_POST['CvPerson'] : 0;
    $fecha_inicio = $_POST['fecha_inicio'] ?? '';
    $fecha_fin    = $_POST['fecha_fin'] ?? '';

    // ⚠️ VALIDAR FECHAS ANTES DE FESTIVOS
    if (!isValidDateYmd($fecha_inicio) || !isValidDateYmd($fecha_fin)) {
        $errores[] = "Debes seleccionar fechas válidas antes de continuar.";
    }
    if ($fecha_inicio > $fecha_fin) {
        $errores[] = "La fecha de fin no puede ser menor que la fecha de inicio.";
    }

    if (!empty($errores)) {
        // Evitar pantalla en blanco
        // NO hacemos return; dejamos que el flujo muestre errores con SweetAlert
    }
    $estado       = $_POST['estado'] ?? 'Solicitado';
    $CvPosturero  = isset($_POST['CvPosturero']) && $_POST['CvPosturero'] !== ''
                    ? (int)$_POST['CvPosturero']
                    : null;








    /* =====================================================
       2) VALIDACIONES NORMALES (ya se confirmó festivos)
       =====================================================*/
    if (!hash_equals($CSRF, $csrfPost)) $errores[] = "Token CSRF inválido.";
    if ($CvPerson <= 0) $errores[] = "Debes seleccionar un empleado.";
    if (!isValidDateYmd($fecha_inicio)) $errores[] = "Fecha de inicio inválida (AAAA-MM-DD).";
    if (!isValidDateYmd($fecha_fin))    $errores[] = "Fecha de fin inválida (AAAA-MM-DD).";
    if ($fecha_fin < $fecha_inicio)     $errores[] = "La fecha fin no puede ser menor que fecha inicio.";
    if (!in_array($estado, $estadosValidos, true))
        $errores[] = "Estado inválido.";

    // Aquí siguen TODAS tus validaciones existentes:
    // duplicados, traslapes, saldo, posturero, etc.

    /* =====================================================
       3) SI NO HAY ERRORES → GUARDAR
       =====================================================*/
    if (empty($errores)) {
        try {

            if ($accion === 'actualizar' && $idPost) {
                $up = $conn->prepare("
                    UPDATE mvacaciones
                    SET CvPerson     = :p,
                        CvPosturero = :pp,
                        fecha_inicio = :fi,
                        fecha_fin    = :ff,
                        estado       = :es
                    WHERE id = :id
                ");
                $up->execute([
                    ':p'  => $CvPerson,
                    ':pp' => $CvPosturero,
                    ':fi' => $fecha_inicio,
                    ':ff' => $fecha_fin,
                    ':es' => $estado,
                    ':id' => $idPost
                ]);

                header("Location: ".$_SERVER['PHP_SELF']."?msg=Registro actualizado.");
                exit;
            }

            // NUEVO
            $ins = $conn->prepare("
                INSERT INTO mvacaciones (CvPerson, CvPosturero, fecha_inicio, fecha_fin, estado)
                VALUES (:p,:pp,:fi,:ff,:es)
            ");

            $ins->execute([
                ':p'  => $CvPerson,
                ':pp' => $CvPosturero,
                ':fi' => $fecha_inicio,
                ':ff' => $fecha_fin,
                ':es' => $estado
            ]);

            header("Location: ".$_SERVER['PHP_SELF']."?msg=Vacaciones registradas.");
            exit;

        } catch (Exception $e) {
            $errores[] = "Error al guardar: ".$e->getMessage();
        }
    }
}












/* ---------------- Mensaje por redirect ---------------- */
if (isset($_GET['msg'])) $mensaje = $_GET['msg'];
/* ---------------- Listado (registros de mvacaciones) ---------------- */
/* ---------------- Filtros (GET) ---------------- */
$f_emp  = isset($_GET['emp'])   ? (int)$_GET['emp'] : 0;               // CvPerson
$f_est  = isset($_GET['estado'])? trim($_GET['estado']) : '';          // 'Solicitado' | 'Aprobado' | 'Rechazado'
$f_del  = isset($_GET['del'])   ? trim($_GET['del']) : '';             // YYYY-MM-DD
$f_al   = isset($_GET['al'])    ? trim($_GET['al'])  : '';             // YYYY-MM-DD

// Sanitiza estado
if ($f_est && !in_array($f_est, $estadosValidos, true)) $f_est = '';

// Valida fechas simples
$validDate = function($d){
  $dt = DateTime::createFromFormat('Y-m-d', $d);
  return $dt && $dt->format('Y-m-d') === $d;
};
if ($f_del && !$validDate($f_del)) $f_del = '';
if ($f_al  && !$validDate($f_al))  $f_al  = '';

/* ---------------- WHERE dinámico (solapamiento de fechas) ----------------
   Si vienen ambas:    v.fecha_inicio <= :al AND v.fecha_fin >= :del
   Solo 'del':         v.fecha_fin >= :del
   Solo 'al':          v.fecha_inicio <= :al
-------------------------------------------------------------------------- */
$where = [];
$params = [];

if ($f_emp > 0) { $where[] = 'v.CvPerson = :emp'; $params[':emp'] = $f_emp; }
if ($f_est !== '') { $where[] = 'v.estado = :est'; $params[':est'] = $f_est; }

if ($f_del && $f_al) {
  $where[] = '(v.fecha_inicio <= :al AND v.fecha_fin >= :del)';
  $params[':del'] = $f_del;
  $params[':al']  = $f_al;
} elseif ($f_del) {
  $where[] = 'v.fecha_fin >= :del';
  $params[':del'] = $f_del;
} elseif ($f_al) {
  $where[] = 'v.fecha_inicio <= :al';
  $params[':al'] = $f_al;
}

$sqlListado = "
  SELECT 
    v.id, v.CvPerson, v.CvPosturero, v.fecha_inicio, v.fecha_fin, v.estado,

    -- Empleado principal
    CONCAT(n.DsNombre,' ', ap1.DsApellido,' ', ap2.DsApellido) AS empleado,

    -- Posturero (si hay)
    CONCAT(n2.DsNombre,' ', ap12.DsApellido,' ', ap22.DsApellido) AS posturero

  FROM mvacaciones v
  JOIN mdtperson p   ON p.CvPerson = v.CvPerson
  JOIN cnombre n     ON n.CvNombre = p.CvNombre
  JOIN capellido ap1 ON ap1.CvApellido = p.CvApePat
  JOIN capellido ap2 ON ap2.CvApellido = p.CvApeMat

  LEFT JOIN mdtperson pp   ON pp.CvPerson = v.CvPosturero
  LEFT JOIN cnombre n2     ON n2.CvNombre = pp.CvNombre
  LEFT JOIN capellido ap12 ON ap12.CvApellido = pp.CvApePat
  LEFT JOIN capellido ap22 ON ap22.CvApellido = pp.CvApeMat
";
if ($where) $sqlListado .= " WHERE " . implode(' AND ', $where);
$sqlListado .= " ORDER BY v.id DESC";



/* ---------------- Ejecutar listado ---------------- */
$listado = [];
try {
  $st = $conn->prepare($sqlListado);
  $st->execute($params);
  $listado = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  $errores[] = "Error consultando listado: ".$e->getMessage();
}

/* ---------------- Map de años (antigüedad) ---------------- */
$mapAnios = [];
try {
  $stmt = $conn->query("
    SELECT p.CvPerson,
           COALESCE(TIMESTAMPDIFF(YEAR, MIN(u.FecIni), CURDATE()), 0) AS anios
    FROM mdtperson p
    LEFT JOIN musuario u ON u.CvPerson = p.CvPerson
    WHERE p.CvTpPerson = 3
    GROUP BY p.CvPerson
  ");
  foreach ($stmt as $r) {
    $mapAnios[(int)$r['CvPerson']] = (int)$r['anios'];
  }
} catch (Exception $e) { /* noop */ }

/* ---------------- Map de empleados que son de Ventas ---------------- */
$mapVentas = [];
try {
  $stmt = $conn->query("
    SELECT p.CvPerson, UPPER(TRIM(COALESCE(d.nombre,''))) AS dep
    FROM mdtperson p
    LEFT JOIN departamento d ON d.id = p.departamento_id
    WHERE p.CvTpPerson = 3
  ");
  foreach ($stmt as $r) {
    $dep = $r['dep'] ?? '';
    $mapVentas[(int)$r['CvPerson']] = (
      $dep === 'VENTAS' || $dep === 'DEPTO VENTAS' || strpos($dep, 'VENTAS') !== false
    ) ? 1 : 0;
  }
} catch (Exception $e) { /* noop */ }


/* ---------------- Map de saldo (derecho/usados/restantes) ---------------- */
$mapSaldo = [];
try {
  $st = $conn->query("SELECT CvPerson, derecho, usados, restantes FROM vw_saldo_vac_actual");
  foreach ($st as $r) {
    $mapSaldo[(int)$r['CvPerson']] = [
      'derecho'   => (int)$r['derecho'],
      'usados'    => (int)$r['usados'],
      'rest'      => (int)$r['restantes'],
    ];
  }
} catch (Exception $e) { /* noop */ }

/* -------- Empleados bloqueados por tener periodo activo/programado -------- */
$mapBloq = [];
try {
  $q = $conn->query("SELECT DISTINCT CvPerson
                       FROM mvacaciones
                      WHERE estado IN ('Solicitado','Aprobado')
                        AND fecha_fin >= CURDATE()");
  foreach ($q as $r) $mapBloq[(int)$r['CvPerson']] = 1;
} catch(Exception $e) { /* noop */ }





/* ---------------- URLs de export e impresión con los mismos filtros ---------------- */
$filters = [];
if ($f_emp > 0)    $filters['emp']    = $f_emp;
if ($f_est !== '') $filters['estado'] = $f_est;
if ($f_del !== '') $filters['del']    = $f_del;
if ($f_al !== '')  $filters['al']     = $f_al;

$qs = http_build_query($filters);
$qs = $qs ? ('?' . $qs) : '';

$URL_EXPORT_CSV  = 'exportvacaciones.php' . ($qs ? $qs . '&' : '?') . 'format=csv';
$URL_EXPORT_XLSX = 'exportvacaciones.php' . ($qs ? $qs . '&' : '?') . 'format=xlsx';
$URL_PRINT       = 'printvacaciones.php'  . $qs;





?>
<?php
// justo antes de empezar a imprimir HTML
$PAGE_TITLE = 'Gestión de Vacaciones - Sistema Agua Maya';
$ACTIVE     = 'vacaciones';
require __DIR__ . '/partials/header.php';   // header + navbar (estilos globales)
?>

<!-- estilos específicos de ESTA página (tabla, botones del listado y form) -->
<link rel="stylesheet" href="css/vacaciones.css">





<div class="main-content">
  <h2>Lista de Periodos de Vacaciones Registrados</h2>

  <!-- Filtros -->
<form method="get" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:10px; align-items:end; margin:10px 0 6px;">
  <div>
    <label for="f_emp">Empleado</label>
    <select id="f_emp" name="emp">
      <option value="">-- Todos --</option>
      <?php foreach ($empleados as $e): ?>
        <option value="<?= (int)$e['CvPerson'] ?>" <?= $f_emp===(int)$e['CvPerson'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($e['nombre'], ENT_QUOTES, 'UTF-8') ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div>
    <label for="f_estado">Estado</label>
    <select id="f_estado" name="estado">
      <option value="">-- Todos --</option>
      <?php foreach ($estadosValidos as $op): ?>
        <option value="<?= htmlspecialchars($op,ENT_QUOTES,'UTF-8') ?>" <?= $f_est===$op ? 'selected' : '' ?>><?= $op ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div>
    <label for="f_del">Desde</label>
    <input type="date" id="f_del" name="del" value="<?= htmlspecialchars($f_del) ?>">
  </div>

  <div>
    <label for="f_al">Hasta</label>
    <input type="date" id="f_al" name="al" value="<?= htmlspecialchars($f_al) ?>">
  </div>

  <div>
    <button class="btn" type="submit"><i class="fas fa-filter"></i> Filtrar</button>
    <a class="btn" href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>"><i class="fas fa-eraser"></i> Limpiar</a>
  </div>
</form>

<!-- Acciones (respetan los filtros actuales) -->
<div style="display:flex; gap:10px; flex-wrap:wrap; margin:8px 0 14px;">
  <a class="btn" href="<?= htmlspecialchars($URL_EXPORT_CSV) ?>"><i class="fas fa-file-csv"></i> Exportar CSV</a>
  <a class="btn" href="<?= htmlspecialchars($URL_EXPORT_XLSX) ?>"><i class="fas fa-file-excel"></i> Exportar Excel</a>
  <button class="btn" type="button" onclick="window.location.href='<?= htmlspecialchars($URL_PRINT) ?>'">
    <i class="fas fa-print"></i> Imprimir
  </button>
</div>



  <!-- Mensajes con SweetAlert -->
  <?php if(!empty($mensaje)): ?>
    <div id="msg-ok" class="hidden" data-msg="<?php echo htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8'); ?>"></div>
  <?php endif; ?>
  <?php if(!empty($errores)): ?>
    <div id="msg-err" class="hidden" data-err="<?php echo htmlspecialchars(implode('\n', $errores), ENT_QUOTES, 'UTF-8'); ?>"></div>
  <?php endif; ?>

  <table>
  <thead>
  <tr>
  <th>#</th>
  <th>Empleado</th>
  <th>Años</th>
  <th>Derecho (días)</th>
  <th>Usados ciclo</th>
  <th>Restantes</th>
  <th>Fecha de Inicio</th>
  <th>Fecha de Fin</th>
  <th>Estado</th>
  <th>Posturero</th>
  <th>Acciones</th>
</tr>

  </thead>

  <tbody>
  <?php if (empty($listado)): ?>
    <tr><td colspan="11">Sin registros.</td></tr>
<?php else: 
  $hoy = date('Y-m-d');
  foreach ($listado as $row):

    $cls = ($row['estado']==='Aprobado' ? 'apr' : ($row['estado']==='Rechazado' ? 'rec' : 'sol'));

    // === NUEVO: definir clase visual por estado/tiempo ===
    $rowClass = '';
    if ($row['estado'] === 'Rechazado') {
      $rowClass = 'vac-rechazada';
    } elseif ($row['estado'] === 'Solicitado') {
      $rowClass = 'vac-espera';
    } elseif ($row['estado'] === 'Aprobado') {
      if ($row['fecha_inicio'] <= $hoy && $row['fecha_fin'] >= $hoy) {
        $rowClass = 'vac-activa'; // dentro del rango
      } elseif ($row['fecha_fin'] < $hoy) {
        $rowClass = 'vac-terminada'; // ya terminó
      } else {
        $rowClass = 'vac-espera'; // aprobada pero futura
      }
    }


    // Antigüedad y derecho
    $anos        = $mapAnios[(int)$row['CvPerson']] ?? 0;
    $diasDerecho = dias_por_anos((int)$anos);

    // Saldo (vista) con fallback
    $saldo = $mapSaldo[(int)$row['CvPerson']] ?? [
      'derecho' => $diasDerecho,
      'usados'  => 0,
      'rest'    => $diasDerecho,
    ];
  ?>
    <tr class="<?= $rowClass ?>"> <!-- ✅ aquí sí se aplica -->
      <td><?= (int)$row['id'] ?></td>
      <td><?= htmlspecialchars($row['empleado'], ENT_QUOTES, 'UTF-8') ?></td>
      <td><?= (int)$anos ?></td>
      <td><?= (int)$saldo['derecho'] ?></td>
      <td><?= (int)$saldo['usados'] ?></td>
      <td><?= max(0,(int)$saldo['rest']) ?></td>
      <td><?= htmlspecialchars($row['fecha_inicio'], ENT_QUOTES, 'UTF-8') ?></td>
      <td><?= htmlspecialchars($row['fecha_fin'], ENT_QUOTES, 'UTF-8') ?></td>
      <td><span class="badge <?= $cls ?>"><?= htmlspecialchars($row['estado'], ENT_QUOTES, 'UTF-8') ?></span></td>
<td><?= htmlspecialchars($row['posturero'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
<td>
  <a class="btn" href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>?action=edit&id=<?= (int)$row['id'] ?>">
    <i class="fas fa-edit"></i> Editar
  </a>
  <button class="btn btn-danger" onclick="confirmDelete(<?= (int)$row['id'] ?>)">
    <i class="fas fa-trash"></i> Eliminar
  </button>
</td>

    </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table>


  <!-- Formulario Crear / Editar -->
  <div class="form-container">
    <h2><?php echo $editRow ? 'Editar Vacaciones' : 'Registrar Nuevas Vacaciones'; ?></h2>
    <form method="POST" action="">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($CSRF, ENT_QUOTES, 'UTF-8'); ?>"/>
      <?php if($editRow): ?>
        <input type="hidden" name="id" value="<?php echo (int)$editRow['id']; ?>"/>
        <input type="hidden" name="accion" value="actualizar"/>
      <?php else: ?>
        <input type="hidden" name="accion" value="crear"/>
      <?php endif; ?>

      <label for="CvPerson">Empleado</label>
<select id="CvPerson" name="CvPerson" required>
  <option value="">-- Selecciona --</option>
  <?php foreach($empleados as $e):
        $sel = ($editRow && (int)$editRow['CvPerson']===(int)$e['CvPerson']) ? 'selected' : ''; ?>
    <option value="<?php echo (int)$e['CvPerson']; ?>" <?php echo $sel; ?>>
      <?php echo htmlspecialchars($e['nombre'], ENT_QUOTES, 'UTF-8'); ?>
    </option>
  <?php endforeach; ?>
</select>

<!-- Badge informativo -->
<div id="infoDerechos" class="badge" style="display:inline-block;margin-top:6px;">
  Selecciona un empleado para ver sus derechos…
</div>

<label for="CvPosturero">Posturero (solo para Ventas)</label>
<select id="CvPosturero" name="CvPosturero" disabled>
  <option value="">-- Selecciona --</option>
  <?php foreach ($empleados as $e):
        $selP = ($editRow && (int)($editRow['CvPosturero'] ?? 0)===(int)$e['CvPerson']) ? 'selected' : ''; ?>
    <option value="<?= (int)$e['CvPerson'] ?>" <?= $selP ?>>
      <?= htmlspecialchars($e['nombre'], ENT_QUOTES, 'UTF-8') ?>
    </option>
  <?php endforeach; ?>
</select>
<small id="ayudaPosturero" style="display:block;color:#555;margin-top:-6px;">
  Se habilitará si el empleado pertenece a Ventas y al escoger fechas válidas.
</small>


      <label for="fecha_inicio">Fecha de Inicio</label>
      <input type="date" id="fecha_inicio" name="fecha_inicio" required
             value="<?php echo $editRow ? htmlspecialchars($editRow['fecha_inicio'], ENT_QUOTES, 'UTF-8') : ''; ?>"/>

      <label for="fecha_fin">Fecha de Fin</label>
      <input type="date" id="fecha_fin" name="fecha_fin" required
             value="<?php echo $editRow ? htmlspecialchars($editRow['fecha_fin'], ENT_QUOTES, 'UTF-8') : ''; ?>"/>

      <label for="estado">Estado</label>
      <select id="estado" name="estado" required>
        <?php
          $estSel = $editRow ? $editRow['estado'] : 'Solicitado';
          foreach ($estadosValidos as $op) {
            $s = ($op === $estSel) ? 'selected' : '';
            echo '<option value="'.htmlspecialchars($op,ENT_QUOTES,'UTF-8').'" '.$s.'>'.$op.'</option>';
          }
        ?>
      </select>

      <button type="submit">
        <i class="fas fa-save"></i> <?php echo $editRow ? 'Actualizar' : 'Registrar Vacaciones'; ?>
      </button>
      <?php if($editRow): ?>
        <a class="btn" href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
          <i class="fas fa-times"></i> Cancelar
        </a>
      <?php endif; ?>
    </form>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>


<script>
/* ---- SweetAlert: Mensajes ---- */
(function(){
  const ok = document.getElementById('msg-ok');
  const er = document.getElementById('msg-err');
  if (ok) {
    const msg = ok.getAttribute('data-msg');
    if (msg) { Swal.fire({icon:'success', title:'Listo', text:msg, confirmButtonColor:'#800020'}); }
  }
  if (er) {
    const msg = er.getAttribute('data-err');
    if (msg) { Swal.fire({icon:'error', title:'Error', text:msg, confirmButtonColor:'#800020'}); }
  }
})();

/* ---- SweetAlert: Confirmar eliminación ---- */
function confirmDelete(id){
  Swal.fire({
    title: '¿Eliminar registro #' + id + '?',
    text: 'Esta acción no se puede deshacer.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: 'Sí, eliminar',
    cancelButtonText: 'Cancelar',
    confirmButtonColor: '#0D47A1',
    cancelButtonColor: '#BDBDBD'
  }).then((result) => {
    if (result.isConfirmed) {
      const url = '<?= htmlspecialchars($_SERVER['PHP_SELF']); ?>?action=delete&id=' + id + '&csrf=<?= $CSRF; ?>';
      window.location.href = url;
    }
  });
}

/* === Festivos (inyectados desde PHP) === */

// Helpers festivos
function isHoliday(dateStr){ return !!FESTIVOS[dateStr]; }
function holidaysInRange(startStr, endStr){
  const out = [];
  if (!startStr || !endStr) return out;
  const a = new Date(startStr), b = new Date(endStr);
  if (isNaN(a) || isNaN(b)) return out;
  for (let d = new Date(a); d <= b; d.setDate(d.getDate()+1)) {
    const s = d.toISOString().slice(0,10);
    if (FESTIVOS[s]) out.push([s, FESTIVOS[s]]);
  }
  return out;
}

// Campos fechas
const fiEl = document.getElementById('fecha_inicio');
const ffEl = document.getElementById('fecha_fin');
const form = document.querySelector('.form-container form');



//fiEl.addEventListener('change', () => { if (validarIndividual(fiEl)) validarRango(); });
//ffEl.addEventListener('change', () => { if (validarIndividual(ffEl)) validarRango(); });




/* ===== Mapas inyectados desde PHP (SIN <script> anidados) ===== */
const MAP_SALDO  = <?= json_encode($mapSaldo,  JSON_UNESCAPED_UNICODE); ?>;
const MAP_ANIOS  = <?= json_encode($mapAnios,  JSON_UNESCAPED_UNICODE); ?>;
const MAP_BLOQ   = <?= json_encode($mapBloq,   JSON_UNESCAPED_UNICODE); ?>;
const MAP_VENTAS = <?= json_encode($mapVentas, JSON_UNESCAPED_UNICODE); ?>; // ← NUEVO
const EDITANDO   = <?= $editRow ? 'true' : 'false'; ?>;




function diasPorAnio(y){
  if (y <= 0) return 0;
  const tabla = [0,12,14,16,18,20,22,24,26,28,30];
  return y <= 10 ? tabla[y] : 30;
}

// Autopropuesta al seleccionar empleado
(function(){
  const sel  = document.getElementById('CvPerson');
  const info = document.getElementById('infoDerechos');
  if (!sel) return;

sel.addEventListener('change', function(){
  const cv = parseInt(this.value || '0', 10);
  if (!cv) return;

  // Si ya tiene periodo activo/programado, NO mostrar modal cuando esté editando
  if (MAP_BLOQ[cv]) {
    if (info) info.textContent = 'Este empleado ya tiene vacaciones activas o programadas.';
    if (!EDITANDO) {
      Swal.fire({
        icon: 'info',
        title: 'No disponible',
        text: 'Ya existe un periodo activo o programado para este empleado.',
        confirmButtonColor:'#0D47A1'
      });
    }
    return;
  }

  // 👇 NUEVO: si es de Ventas, saltar el modal y habilitar Posturero (cuando haya fechas)
  // ✅ Detecta si es de Ventas, pero NO hagas return
const ES_VENTAS = (MAP_VENTAS && MAP_VENTAS[cv] === 1);
if (ES_VENTAS) {
  if (info) info.textContent = 'Empleado de Ventas: selecciona fechas y Posturero.';
} else {
  if (info) info.textContent = 'Empleado NO es de Ventas.';
}


  // Base por antigüedad
  let anos        = MAP_ANIOS[cv] ?? 0;
  let derecho     = diasPorAnio(anos);
  let usados      = 0;
  let restantes   = derecho;

  // Si hay saldo de la vista, úsalo
  if (MAP_SALDO[cv]) {
    derecho   = +MAP_SALDO[cv].derecho;
    usados    = +MAP_SALDO[cv].usados;
    restantes = +MAP_SALDO[cv].rest;
  }

  if (info) info.textContent = `Derecho: ${derecho} — Usados: ${usados} — Restantes: ${Math.max(0,restantes)}`;

  if (derecho <= 0) {
    Swal.fire({
      icon: 'info',
      title: 'Sin derecho aún',
      text: 'Este empleado todavía no acumula días de vacaciones.',
      cconfirmButtonColor:'#0D47A1'
    });
    return;
  }
  if (EDITANDO) return; // no autopropongas en edición

  // ⬇️ Solo si NO es de Ventas se muestra este modal automático
// ✅ Modal con 3 opciones: Automático / Manual / Cancelar
Swal.fire({
  title: 'Asignar vacaciones',
  html: `Este empleado lleva <b>${anos}</b> año(s).<br>
         Derecho: <b>${derecho}</b> día(s).<br>
         Restantes del ciclo: <b>${Math.max(0,restantes)}</b> día(s).<br><br>
         ¿Cómo deseas asignarlas?` + (ES_VENTAS ? `<br><small>Recuerda: para Ventas debes elegir un <b>Posturero</b>.</small>` : ''),
  icon: 'question',
  showDenyButton: true,
  showCancelButton: true,
  confirmButtonText: 'Automático: todos los días',
  denyButtonText: 'Elegir días manualmente',
  cancelButtonText: 'Cancelar',
  confirmButtonColor: '#0D47A1',
  denyButtonColor: '#1565C0',
  cancelButtonColor: '#BDBDBD',
  reverseButtons: true
}).then((res) => {
  if (res.isConfirmed) {
    // === AUTOMÁTICO ===
    const hoy = new Date();
    const inicio = hoy.toISOString().slice(0,10);
    const fin = new Date(hoy);
    const diasAProponer = Math.max(1, (restantes > 0 ? restantes : derecho));
    fin.setDate(hoy.getDate() + (diasAProponer - 1));
    const finStr = fin.toISOString().slice(0,10);



    // Asignar & (si Ventas) habilitar Posturero
    document.getElementById('fecha_inicio').value = inicio;
    document.getElementById('fecha_fin').value    = finStr;
    document.getElementById('estado').value       = 'Solicitado';
    document.querySelector('input[name="accion"]').value = 'crear';

    if (ES_VENTAS) {
      // Llenar combo de posturero según fechas
      tryHabilitarPosturero();
      Swal.fire({
        icon: 'info',
        title: 'Selecciona Posturero',
        text: 'Para Ventas, debes elegir un Posturero disponible antes de guardar.',
        confirmButtonColor:'#0D47A1'
      });
      // NO mandamos submit automático; primero que elijas Posturero
      return;
    }


    } else if (res.isDenied) {
    // === MANUAL ===
    document.querySelector('input[name="accion"]').value = 'crear';
    document.getElementById('estado').value = 'Solicitado';
    const hoy = new Date().toISOString().slice(0,10);
    if (!fiEl.value) fiEl.value = hoy;

    if (ES_VENTAS) tryHabilitarPosturero();

    Swal.fire({
      toast: true,
      position: 'top-end',
      timer: 2500,
      timerProgressBar: true,
      showConfirmButton: false,
      icon: 'info',
      title: ES_VENTAS
        ? 'Elige fechas y Posturero; luego guarda.'
        : 'Elige las fechas; luego guarda.'
    });

    fiEl.focus();

  } else if (res.isDismissed) {
    // === CANCELAR ===
    form.reset(); // limpia todos los campos del formulario
    limpiarPosturero(true); // deshabilita el combo de posturero
    if (info) info.textContent = 'Selección cancelada. El formulario ha sido limpiado.';
  }
});

});



  // Si viene alguien preseleccionado (editar), muestra su badge
  if (sel.value) {
  if (!EDITANDO) {
    sel.dispatchEvent(new Event('change'));
  } else {
    // Solo actualizar el badge, sin mostrar modales
    const cv = parseInt(sel.value, 10);
    if (cv) {
      const s = MAP_SALDO[cv];
      let anos = MAP_ANIOS[cv] ?? 0;
      let derecho = diasPorAnio(anos), usados = 0, restantes = derecho;
      if (s) { derecho = +s.derecho; usados = +s.usados; restantes = +s.rest; }
      if (info) info.textContent = `Derecho: ${derecho} — Usados: ${usados} — Restantes: ${Math.max(0,restantes)}`;
    }
  }
}

// === POSTURERO dinámico (habilitar solo si el empleado es de Ventas y hay fechas válidas) ===
const selEmpleado   = document.getElementById('CvPerson');
const selPosturero  = document.getElementById('CvPosturero');
const ayudaPost     = document.getElementById('ayudaPosturero');

function limpiarPosturero(disabled = true){
  if (!selPosturero) return;
  selPosturero.innerHTML = '<option value="">-- Selecciona --</option>';
  selPosturero.disabled  = disabled;
}

async function tryHabilitarPosturero(){
  if (!selEmpleado || !selPosturero) return;
  const cv = selEmpleado.value ? parseInt(selEmpleado.value,10) : 0;
  const fi = fiEl.value || '';
  const ff = ffEl.value || '';
  if (!cv || !fi || !ff || ff < fi) { limpiarPosturero(true); return; }

  try {
    const url = `ajax_postureros.php?cv=${encodeURIComponent(cv)}&fi=${encodeURIComponent(fi)}&ff=${encodeURIComponent(ff)}<?php if($editRow){ echo '&editId='.(int)$editRow['id']; } ?>`;
    const r   = await fetch(url, {headers:{'Cache-Control':'no-cache'}});
    const js  = await r.json();

    if (!js.ok) { limpiarPosturero(true); return; }

    if (!js.isVentas) {
      limpiarPosturero(true);
      if (ayudaPost) ayudaPost.textContent = 'Solo se habilita para empleados del Departamento de Ventas.';
      return;
    }

    // Es de Ventas: cargar opciones filtradas por disponibilidad
    limpiarPosturero(false);
    if (Array.isArray(js.postureros)) {
      js.postureros.forEach(p => {
        const opt = document.createElement('option');
        opt.value = p.CvPerson;
        opt.textContent = p.nombre;
        selPosturero.appendChild(opt);
      });
      <?php if($editRow && !empty($editRow['CvPosturero'])): ?>
        const cur = String(<?= (int)$editRow['CvPosturero'] ?>);
        const found = [...selPosturero.options].some(o => o.value === cur);
        if (!found) {
          const opt = document.createElement('option');
          opt.value = cur;
          opt.textContent = 'Posturero actual (no disponible para nuevas asignaciones)';
          selPosturero.appendChild(opt);
        }
        selPosturero.value = cur;
      <?php endif; ?>
      if (ayudaPost) ayudaPost.textContent = 'Selecciona al Posturero disponible.';
    } else {
      limpiarPosturero(true);
      if (ayudaPost) ayudaPost.textContent = 'No hay postureros disponibles para ese periodo.';
    }
  } catch(e){
    limpiarPosturero(true);
  }
}

if (selEmpleado)  selEmpleado.addEventListener('change', tryHabilitarPosturero);
if (fiEl)         fiEl.addEventListener('change',     tryHabilitarPosturero);
if (ffEl)         ffEl.addEventListener('change',     tryHabilitarPosturero);

document.addEventListener('DOMContentLoaded', tryHabilitarPosturero);

// Seguridad extra al enviar (si no es de Ventas, limpia el campo)
form.addEventListener('submit', (e)=>{
  const cv = selEmpleado.value ? parseInt(selEmpleado.value,10) : 0;
  const fi = fiEl.value || '', ff = ffEl.value || '';
  if (!cv || !fi || !ff || ff < fi) return;
  if (selPosturero && selPosturero.disabled) selPosturero.value = '';
});


})();
</script>
<script>
// --- FESTIVOS desde PHP (solo UNA definición) ---
const FESTIVOS = {
  '2025-02-03': 'Aniversario de la Constitución (puente)',
  '2025-03-17': 'Natalicio de Benito Juárez (puente)',
  '2025-05-01': 'Día del Trabajo',
  '2025-09-16': 'Día de la Independencia',
  '2025-11-17': 'Día de la Revolución (puente)',
  '2025-12-25': 'Navidad'
};

// --- Detectar festivos dentro del rango ---
function detectarFestivos(fi, ff) {
    const lista = [];
    const a = new Date(fi);
    const b = new Date(ff);
    if (isNaN(a) || isNaN(b)) return lista;

    for (let d = new Date(a); d <= b; d.setDate(d.getDate() + 1)) {
        const s = d.toISOString().slice(0, 10);
        if (FESTIVOS[s]) lista.push([s, FESTIVOS[s]]);
    }
    return lista;
}

document.addEventListener("DOMContentLoaded", () => {
    const form = document.querySelector('.form-container form');
    const fi = document.getElementById('fecha_inicio');
    const ff = document.getElementById('fecha_fin');

    if (!form || !fi || !ff) return; // seguridad

    form.addEventListener('submit', function(e) {
        e.preventDefault();

        const f1 = fi.value;
        const f2 = ff.value;
        if (!f1 || !f2) {
            form.submit();
            return;
        }

        const festivos = detectarFestivos(f1, f2);

        if (festivos.length === 0) {
            form.submit();
            return;
        }

        let lista = "<ul style='text-align:left'>";
        festivos.forEach(f => lista += `<li><b>${f[0]}</b> — ${f[1]}</li>`);
        lista += "</ul>";

        Swal.fire({
            icon: "warning",
            title: "Días Festivos Encontrados",
            html: "El rango contiene:<br>" + lista + "<br>¿Deseas continuar?",
            showCancelButton: true,
            confirmButtonText: "Sí, continuar",
            cancelButtonText: "Cancelar"
        }).then(r => {
            if (r.isConfirmed) form.submit();
        });
    });
});
</script>







</body>
</html>
