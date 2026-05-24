<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';
requireLogin();

$db  = getDB();
$hoy = date('Y-m-d');

// KPIs
$kpi = [];

// OTs activas (no entregadas ni canceladas)
$s = $db->query("SELECT COUNT(*) FROM ordenes_trabajo WHERE estado NOT IN ('entregado','cancelado')");
$kpi['ot_activas'] = $s->fetchColumn();

// Listas para entregar hoy o antes
$s = $db->prepare("SELECT COUNT(*) FROM ordenes_trabajo WHERE estado='listo' AND fecha_estimada <= ?");
$s->execute([$hoy]);
$kpi['listas'] = $s->fetchColumn();

// Ingresos del día
$s = $db->prepare("SELECT COALESCE(SUM(total),0) FROM ventas WHERE DATE(created_at)=? AND estado='completada'");
$s->execute([$hoy]);
$kpi['ventas_hoy'] = $s->fetchColumn();

// Ingresos reparaciones del día
$s = $db->prepare("SELECT COALESCE(SUM(precio_final),0) FROM ordenes_trabajo WHERE DATE(fecha_pago)=? AND pagado=1");
$s->execute([$hoy]);
$kpi['reparaciones_hoy'] = $s->fetchColumn();

// Productos con stock bajo
$s = $db->query("SELECT COUNT(*) FROM productos WHERE stock_actual <= stock_minimo AND activo=1");
$kpi['stock_bajo'] = $s->fetchColumn();

// OTs del mes
$mes = date('Y-m');
$s = $db->prepare("SELECT COUNT(*) FROM ordenes_trabajo WHERE DATE_FORMAT(created_at,'%Y-%m')=?");
$s->execute([$mes]);
$kpi['ot_mes'] = $s->fetchColumn();

// OTs por estado para kanban
$stmt = $db->query("
  SELECT ot.id, ot.codigo_ot, ot.estado, ot.fecha_estimada,
         CONCAT(c.nombre) as cliente_nombre,
         CONCAT(te.nombre,' ',COALESCE(e.marca,''),' ',COALESCE(e.modelo,'')) as equipo_desc,
         CONCAT(u.nombre,' ',u.apellido) as tecnico_nombre
  FROM ordenes_trabajo ot
  JOIN clientes c ON c.id = ot.cliente_id
  JOIN equipos e ON e.id = ot.equipo_id
  JOIN tipos_equipo te ON te.id = e.tipo_equipo_id
  LEFT JOIN usuarios u ON u.id = ot.tecnico_id
  WHERE ot.estado NOT IN ('entregado','cancelado')
  ORDER BY ot.fecha_ingreso DESC
  LIMIT 60
");
$ots_raw = $stmt->fetchAll();

// Agrupar por estado
$kanban = [];
foreach (ESTADOS_OT as $key => $info) {
    if (in_array($key, ['entregado','cancelado'])) continue;
    $kanban[$key] = ['info' => $info, 'items' => []];
}
foreach ($ots_raw as $ot) {
    $kanban[$ot['estado']]['items'][] = $ot;
}

// Últimas 5 OTs creadas
$recientes = $db->query("
  SELECT ot.codigo_ot, ot.estado, ot.created_at,
         c.nombre as cliente, te.nombre as tipo_equipo
  FROM ordenes_trabajo ot
  JOIN clientes c ON c.id=ot.cliente_id
  JOIN equipos e ON e.id=ot.equipo_id
  JOIN tipos_equipo te ON te.id=e.tipo_equipo_id
  ORDER BY ot.created_at DESC LIMIT 8
")->fetchAll();

// Ventas últimos 7 días (para mini chart)
$ventas7 = $db->query("
  SELECT DATE(created_at) as fecha, SUM(total) as total
  FROM ventas WHERE created_at >= DATE_SUB(CURDATE(),INTERVAL 6 DAY)
    AND estado='completada'
  GROUP BY DATE(created_at) ORDER BY fecha
")->fetchAll();

$pageTitle  = 'Dashboard — ' . APP_NAME;
$breadcrumb = [['label'=>'Dashboard','url'=>null]];
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- KPIs -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-4 col-lg-2">
    <div class="kpi-card">
      <div class="d-flex align-items-center gap-3">
        <div class="kpi-icon bg-primary bg-opacity-10">
          <i data-feather="clipboard" class="text-primary" style="width:22px;height:22px"></i>
        </div>
        <div>
          <div class="kpi-value"><?= $kpi['ot_activas'] ?></div>
          <div class="kpi-label">OTs activas</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-4 col-lg-2">
    <div class="kpi-card">
      <div class="d-flex align-items-center gap-3">
        <div class="kpi-icon bg-success bg-opacity-10">
          <i data-feather="check-circle" class="text-success" style="width:22px;height:22px"></i>
        </div>
        <div>
          <div class="kpi-value text-success"><?= $kpi['listas'] ?></div>
          <div class="kpi-label">Listas p/ entregar</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-4 col-lg-2">
    <div class="kpi-card">
      <div class="d-flex align-items-center gap-3">
        <div class="kpi-icon bg-info bg-opacity-10">
          <i data-feather="dollar-sign" class="text-info" style="width:22px;height:22px"></i>
        </div>
        <div>
          <div class="kpi-value" style="font-size:18px">S/<?= number_format($kpi['ventas_hoy'],0) ?></div>
          <div class="kpi-label">Ventas hoy</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-4 col-lg-2">
    <div class="kpi-card">
      <div class="d-flex align-items-center gap-3">
        <div class="kpi-icon bg-warning bg-opacity-10">
          <i data-feather="tool" class="text-warning" style="width:22px;height:22px"></i>
        </div>
        <div>
          <div class="kpi-value" style="font-size:18px">S/<?= number_format($kpi['reparaciones_hoy'],0) ?></div>
          <div class="kpi-label">Reparaciones hoy</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-4 col-lg-2">
    <div class="kpi-card">
      <div class="d-flex align-items-center gap-3">
        <div class="kpi-icon bg-danger bg-opacity-10">
          <i data-feather="alert-triangle" class="text-danger" style="width:22px;height:22px"></i>
        </div>
        <div>
          <div class="kpi-value text-danger"><?= $kpi['stock_bajo'] ?></div>
          <div class="kpi-label">Stock mínimo</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-4 col-lg-2">
    <div class="kpi-card">
      <div class="d-flex align-items-center gap-3">
        <div class="kpi-icon bg-secondary bg-opacity-10">
          <i data-feather="calendar" class="text-secondary" style="width:22px;height:22px"></i>
        </div>
        <div>
          <div class="kpi-value"><?= $kpi['ot_mes'] ?></div>
          <div class="kpi-label">OTs este mes</div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Kanban board -->
<div class="tr-card mb-4">
  <div class="tr-card-header">
    <h6 class="mb-0 fw-semibold"><i data-feather="trello" class="me-2" style="width:17px;height:17px"></i>Estado de órdenes activas</h6>
    <a href="<?= BASE_URL ?>modules/ot/nueva.php" class="btn btn-primary btn-sm">
      <i data-feather="plus" style="width:14px;height:14px"></i> Nueva OT
    </a>
  </div>
  <div class="tr-card-body">
    <div class="kanban-board">
      <?php foreach ($kanban as $estado => $col): ?>
      <div class="kanban-col">
        <div class="kanban-col-header bg-<?= $col['info']['color'] ?> bg-opacity-10 text-<?= $col['info']['color'] ?>">
          <span><?= $col['info']['label'] ?></span>
          <span class="badge bg-<?= $col['info']['color'] ?>"><?= count($col['items']) ?></span>
        </div>
        <div class="kanban-items" id="kanban-<?= $estado ?>">
          <?php foreach ($col['items'] as $ot): ?>
          <div class="kanban-card" onclick="window.location='<?= BASE_URL ?>modules/ot/ver.php?id=<?= $ot['id'] ?>'">
            <div class="fw-semibold text-primary small"><?= sanitize($ot['codigo_ot']) ?></div>
            <div class="text-truncate"><?= sanitize($ot['cliente_nombre']) ?></div>
            <div class="text-muted small text-truncate"><?= sanitize($ot['equipo_desc']) ?></div>
            <?php if ($ot['fecha_estimada']): ?>
            <div class="mt-1">
              <span class="badge bg-<?= $ot['fecha_estimada'] < $hoy ? 'danger' : 'light text-dark' ?> small">
                📅 <?= formatDate($ot['fecha_estimada']) ?>
              </span>
            </div>
            <?php endif; ?>
            <?php if ($ot['tecnico_nombre']): ?>
            <div class="text-muted" style="font-size:11px">👤 <?= sanitize($ot['tecnico_nombre']) ?></div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- Últimas OTs + Mini gráfico -->
<div class="row g-3">
  <div class="col-lg-7">
    <div class="tr-card h-100">
      <div class="tr-card-header">
        <h6 class="mb-0 fw-semibold">Últimas órdenes creadas</h6>
        <a href="<?= BASE_URL ?>modules/ot/index.php" class="btn btn-sm btn-outline-secondary">Ver todas</a>
      </div>
      <div class="tr-card-body p-0">
        <table class="tr-table">
          <thead>
            <tr>
              <th>Código</th><th>Cliente</th><th>Equipo</th><th>Estado</th><th>Fecha</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recientes as $r): ?>
            <tr>
              <td><a href="<?= BASE_URL ?>modules/ot/ver.php?codigo=<?= urlencode($r['codigo_ot']) ?>" class="fw-semibold text-primary text-decoration-none"><?= sanitize($r['codigo_ot']) ?></a></td>
              <td><?= sanitize($r['cliente']) ?></td>
              <td class="text-muted small"><?= sanitize($r['tipo_equipo']) ?></td>
              <td><?= estadoOTBadge($r['estado']) ?></td>
              <td class="text-muted small"><?= formatDateTime($r['created_at']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="tr-card h-100">
      <div class="tr-card-header">
        <h6 class="mb-0 fw-semibold">Ventas últimos 7 días</h6>
      </div>
      <div class="tr-card-body">
        <canvas id="chart-ventas" height="180"></canvas>
      </div>
    </div>
  </div>
</div>

<?php
$chartLabels = json_encode(array_map(fn($r) => date('d/m', strtotime($r['fecha'])), $ventas7));
$chartData   = json_encode(array_map(fn($r) => (float)$r['total'], $ventas7));
$pageScripts = <<<JS
<script>
const ctx = document.getElementById('chart-ventas');
if (ctx) {
  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: $chartLabels,
      datasets: [{
        label: 'Ventas S/',
        data: $chartData,
        backgroundColor: 'rgba(79,70,229,0.7)',
        borderRadius: 6,
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true, grid: { color: '#f3f4f6' } }, x: { grid: { display: false } } }
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
