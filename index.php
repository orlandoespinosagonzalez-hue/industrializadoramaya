<?php
session_start();
require_once "conexion.php";          // $conn (PDO)
require_once __DIR__ . '/auth_check.php'; // autenticación

/* =======================
   Datos para el dashboard
   ======================= */
$anioActual = date('Y');

$totalEmpleados   = 0;
$vacAprobadasYear = 0;
$solicitudesYear  = 0;
$diasOtorgadosYear= 0;
$proximas         = [];
$byEstado = ['Solicitado'=>0,'Aprobado'=>0,'Rechazado'=>0];

try {
  // Total empleados (tipo 3)
  $totalEmpleados = (int)$conn->query("SELECT COUNT(*) FROM mdtperson WHERE CvTpPerson=3")->fetchColumn();

  // Aprobadas / Solicitadas del año actual
  $st = $conn->prepare("SELECT COUNT(*) FROM mvacaciones WHERE estado='Aprobado' AND YEAR(fecha_inicio)=:y");
  $st->execute([':y'=>$anioActual]);
  $vacAprobadasYear = (int)$st->fetchColumn();

  $st = $conn->prepare("SELECT COUNT(*) FROM mvacaciones WHERE estado='Solicitado' AND YEAR(fecha_inicio)=:y");
  $st->execute([':y'=>$anioActual]);
  $solicitudesYear = (int)$st->fetchColumn();

  // Días otorgados (aprobados) del año actual
  $st = $conn->prepare("
    SELECT COALESCE(SUM(DATEDIFF(fecha_fin, fecha_inicio)+1),0)
    FROM mvacaciones
    WHERE estado='Aprobado' AND YEAR(fecha_inicio)=:y
  ");
  $st->execute([':y'=>$anioActual]);
  $diasOtorgadosYear = (int)$st->fetchColumn();

  // Próximas vacaciones (siguientes 8)
  $st = $conn->query("
    SELECT v.id, v.CvPerson, v.fecha_inicio, v.fecha_fin, v.estado,
           CONCAT(n.DsNombre,' ', ap1.DsApellido,' ', ap2.DsApellido) AS empleado
    FROM mvacaciones v
    JOIN mdtperson p ON p.CvPerson = v.CvPerson
    JOIN cnombre   n   ON p.CvNombre = n.CvNombre
    JOIN capellido ap1 ON p.CvApePat  = ap1.CvApellido
    JOIN capellido ap2 ON p.CvApeMat  = ap2.CvApellido
    WHERE v.fecha_inicio >= CURDATE()
    ORDER BY v.fecha_inicio ASC
    LIMIT 8
  ");
  $proximas = $st->fetchAll(PDO::FETCH_ASSOC);

  // Conteo por estado (año actual)
  $st = $conn->prepare("
    SELECT estado, COUNT(*) c
    FROM mvacaciones
    WHERE YEAR(fecha_inicio)=:y
    GROUP BY estado
  ");
  $st->execute([':y'=>$anioActual]);
  foreach ($st as $r) { $byEstado[$r['estado']] = (int)$r['c']; }
} catch (Exception $e) {
  // deja valores por defecto si algo falla
}

/* ======== HEADER (parcial) ======== */
$PAGE_TITLE = 'Vacaciones — Tablero';
$ACTIVE     = 'inicio'; // para resaltar en la navbar
require __DIR__ . '/partials/header.php';
?>

<!-- Estilos ESPECÍFICOS de esta página -->
<link rel="stylesheet" href="css/index.css">

<!-- 🔹 Botón Salir (colocado arriba, a la derecha) -->
<div class="logout-bar">
  <a href="logout.php" class="logout-btn">
    <i class="fas fa-sign-out-alt"></i> Salir
  </a>
</div>


<div class="main">
  <h2>Tablero general</h2>
  <p class="small">Año: <strong><?= htmlspecialchars($anioActual) ?></strong></p>


  <div class="kpis">
    <div class="card">
      <h3><i class="fas fa-users"></i> Empleados</h3>
      <div class="num"><?= number_format($totalEmpleados) ?></div>
      <div class="hint">Registrados (tipo empleado)</div>
    </div>
    <div class="card">
      <h3><i class="fas fa-check-circle"></i> Vacaciones aprobadas</h3>
      <div class="num"><?= number_format($vacAprobadasYear) ?></div>
      <div class="hint">En <?= $anioActual ?></div>
    </div>
    <div class="card">
      <h3><i class="fas fa-hourglass-half"></i> Solicitudes en espera</h3>
      <div class="num"><?= number_format($solicitudesYear) ?></div>
      <div class="hint">En <?= $anioActual ?></div>
    </div>
    <div class="card">
      <h3><i class="fas fa-suitcase-rolling"></i> Días otorgados</h3>
      <div class="num"><?= number_format($diasOtorgadosYear) ?></div>
      <div class="hint">Aprobados en <?= $anioActual ?></div>
    </div>
  </div>

  <div class="grid-2">
    <div class="card">
      <h3><i class=></i> Próximos periodos</h3>
      <div style="overflow:auto;">
        <table>
          <thead>
            <tr>
              <th>Empleado</th>
              <th>Inicio</th>
              <th>Fin</th>
              <th>Estado</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($proximas)): ?>
            <tr><td colspan="4">No hay periodos próximos.</td></tr>
          <?php else: foreach ($proximas as $r):
            $cls = $r['estado']==='Aprobado' ? 'apr' : ($r['estado']==='Rechazado' ? 'rec' : 'sol'); ?>
            <tr>
              <td><?= htmlspecialchars($r['empleado'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($r['fecha_inicio']) ?></td>
              <td><?= htmlspecialchars($r['fecha_fin']) ?></td>
              <td><span class="badge <?= $cls ?>"><?= htmlspecialchars($r['estado']) ?></span></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      <div class="small" style="margin-top:8px;">
        Estados:
        <span class="badge sol">Solicitado <?= (int)$byEstado['Solicitado'] ?></span>
        <span class="badge apr">Aprobado <?= (int)$byEstado['Aprobado'] ?></span>
        <span class="badge rec">Rechazado <?= (int)$byEstado['Rechazado'] ?></span>
      </div>
    </div>

    <div class="card">
      <h3><i class="fas fa-flag"></i> Días festivos (2025)</h3>
      <ul class="f-list">
        <li><strong>2025-02-03</strong> — Constitución (puente)</li>
        <li><strong>2025-03-17</strong> — Natalicio de Benito Juárez (puente)</li>
        <li><strong>2025-05-01</strong> — Día del Trabajo</li>
        <li><strong>2025-09-16</strong> — Día de la Independencia</li>
        <li><strong>2025-11-17</strong> — Revolución Mexicana (puente)</li>
        <li><strong>2025-12-25</strong> — Navidad</li>
      </ul>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
