<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';
requireLogin();
requireRole([ROL_ADMIN]);
$db = getDB();

$desde = $_GET['desde'] ?? date('Y-m-01');
$hasta = $_GET['hasta'] ?? date('Y-m-d');

// Ventas por día en rango
$ventasDia = $db->prepare("SELECT DATE(created_at) as dia, COUNT(*) as n, SUM(total) as total FROM ventas WHERE DATE(created_at) BETWEEN ? AND ? AND estado='completada' GROUP BY DATE(created_at) ORDER BY dia");
$ventasDia->execute([$desde,$hasta]);
$ventasDia = $ventasDia->fetchAll();

// OTs por estado
$otEstado = $db->query("SELECT estado, COUNT(*) as n FROM ordenes_trabajo GROUP BY estado")->fetchAll();

// Top técnicos (OTs completadas)
$topTec = $db->prepare("SELECT CONCAT(u.nombre,' ',u.apellido) as nombre, COUNT(ot.id) as ots, COALESCE(SUM(ot.precio_final),0) as total FROM ordenes_trabajo ot JOIN usuarios u ON u.id=ot.tecnico_id WHERE ot.estado='entregado' AND DATE(ot.fecha_entrega) BETWEEN ? AND ? GROUP BY u.id ORDER BY ots DESC LIMIT 10");
$topTec->execute([$desde,$hasta]);
$topTec = $topTec->fetchAll();

// Top productos vendidos
$topProd = $db->prepare("SELECT p.nombre, SUM(vd.cantidad) as qty, SUM(vd.subtotal) as total FROM venta_detalle vd JOIN productos p ON p.id=vd.producto_id JOIN ventas v ON v.id=vd.venta_id WHERE DATE(v.created_at) BETWEEN ? AND ? AND v.estado='completada' GROUP BY p.id ORDER BY qty DESC LIMIT 10");
$topProd->execute([$desde,$hasta]);
$topProd = $topProd->fetchAll();

// Resumen financiero
// El ingreso tiene una sola fuente: el comprobante emitido (`ventas`).
// `ordenes_trabajo.precio_final` es presupuesto, no ingreso — sumarlo acá
// contaba dos veces la misma plata cuando la OT se cobraba en una boleta.
$totalIngresos = $db->prepare("SELECT COALESCE(SUM(total),0) FROM ventas WHERE DATE(created_at) BETWEEN ? AND ? AND estado='completada'");
$totalIngresos->execute([$desde,$hasta]);
$totalIngresos = $totalIngresos->fetchColumn();

// Desglose del MISMO ingreso: qué parte vino de servicio técnico y qué
// parte de venta de productos. Son porciones, no sumandos.
$desglose = $db->prepare("
    SELECT COALESCE(SUM(CASE WHEN d.ot_id IS NOT NULL THEN d.subtotal ELSE 0 END),0) AS servicios,
           COALESCE(SUM(CASE WHEN d.ot_id IS NULL     THEN d.subtotal ELSE 0 END),0) AS productos
    FROM venta_detalle d
    JOIN ventas v ON v.id = d.venta_id
    WHERE DATE(v.created_at) BETWEEN ? AND ? AND v.estado='completada'
");
$desglose->execute([$desde,$hasta]);
$desglose  = $desglose->fetch() ?: ['servicios'=>0,'productos'=>0];
$totalRep  = (float)$desglose['servicios'];
$totalProd = (float)$desglose['productos'];

$totalEgr = $db->prepare("SELECT COALESCE(SUM(mv.monto),0) FROM movimientos_caja mv JOIN cajas c ON c.id=mv.caja_id WHERE mv.tipo='egreso' AND c.fecha BETWEEN ? AND ?");
$totalEgr->execute([$desde,$hasta]);
$totalEgr = $totalEgr->fetchColumn();

$utilidad = $totalIngresos - $totalEgr;

$pageTitle  = 'Reportes — '.APP_NAME;
$breadcrumb = [['label'=>'Reportes','url'=>null]];
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="fw-bold mb-0">Reportes y analytics</h5>
  <form method="GET" class="d-flex gap-2 align-items-center">
    <input type="date" name="desde" class="form-control form-control-sm" value="<?= $desde ?>"/>
    <span class="text-muted small">a</span>
    <input type="date" name="hasta" class="form-control form-control-sm" value="<?= $hasta ?>"/>
    <button type="submit" class="btn btn-primary btn-sm">Aplicar</button>
  </form>
</div>

<!-- KPIs resumen -->
<div class="row g-3 mb-4">
  <div class="col-md-3">
    <div class="kpi-card">
      <div class="kpi-value text-primary" style="font-size:20px"><?= formatMoney($totalIngresos) ?></div>
      <div class="kpi-label">Ingresos totales</div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="kpi-card">
      <div class="kpi-value text-success" style="font-size:20px"><?= formatMoney($totalRep) ?></div>
      <div class="kpi-label">Servicio técnico</div>
      <div class="text-muted" style="font-size:11px">
        incluido arriba · productos <?= formatMoney($totalProd) ?>
      </div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="kpi-card">
      <div class="kpi-value text-danger" style="font-size:20px"><?= formatMoney($totalEgr) ?></div>
      <div class="kpi-label">Egresos totales</div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="kpi-card">
      <div class="kpi-value fw-bold" style="font-size:22px;color:<?= $utilidad>=0?'#198754':'#dc3545' ?>"><?= formatMoney($utilidad) ?></div>
      <div class="kpi-label">Utilidad neta</div>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <!-- Gráfico ventas diarias -->
  <div class="col-lg-8">
    <div class="tr-card">
      <div class="tr-card-header"><h6 class="mb-0 small fw-semibold">VENTAS DIARIAS</h6></div>
      <div class="tr-card-body"><canvas id="chart-ventas-dia" height="120"></canvas></div>
    </div>
  </div>
  <!-- OTs por estado -->
  <div class="col-lg-4">
    <div class="tr-card">
      <div class="tr-card-header"><h6 class="mb-0 small fw-semibold">OTs POR ESTADO</h6></div>
      <div class="tr-card-body"><canvas id="chart-ot-estado" height="200"></canvas></div>
    </div>
  </div>
</div>

<div class="row g-3">
  <!-- Top técnicos -->
  <div class="col-lg-6">
    <div class="tr-card">
      <div class="tr-card-header"><h6 class="mb-0 small fw-semibold">TOP TÉCNICOS (PERÍODO)</h6></div>
      <div class="tr-card-body p-0" style="overflow:hidden"><div class="table-responsive-wrapper" style="overflow-x:auto;-webkit-overflow-scrolling:touch">
        <table class="tr-table">
          <thead><tr><th>#</th><th>Técnico</th><th>OTs completadas</th><th>Total facturado</th></tr></thead>
          <tbody>
            <?php foreach($topTec as $i=>$t): ?>
            <tr>
              <td class="text-muted"><?= $i+1 ?></td>
              <td class="fw-semibold"><?= sanitize($t['nombre']) ?></td>
              <td class="text-center"><span class="badge bg-primary"><?= $t['ots'] ?></span></td>
              <td><?= formatMoney($t['total']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($topTec)): ?><tr><td colspan="4" class="text-center text-muted py-3">Sin datos</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <!-- Top productos -->
  <div class="col-lg-6">
    <div class="tr-card">
      <div class="tr-card-header"><h6 class="mb-0 small fw-semibold">PRODUCTOS MÁS VENDIDOS</h6></div>
      <div class="tr-card-body p-0" style="overflow:hidden"><div class="table-responsive-wrapper" style="overflow-x:auto;-webkit-overflow-scrolling:touch">
        <table class="tr-table">
          <thead><tr><th>#</th><th>Producto</th><th>Cant. vendida</th><th>Total</th></tr></thead>
          <tbody>
            <?php foreach($topProd as $i=>$p): ?>
            <tr>
              <td class="text-muted"><?= $i+1 ?></td>
              <td class="fw-semibold small"><?= sanitize($p['nombre']) ?></td>
              <td class="text-center"><?= number_format($p['qty'],0) ?></td>
              <td><?= formatMoney($p['total']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($topProd)): ?><tr><td colspan="4" class="text-center text-muted py-3">Sin datos</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php
$labDias   = json_encode(array_map(fn($r)=>date('d/m',strtotime($r['dia'])), $ventasDia));
$dataDias  = json_encode(array_map(fn($r)=>(float)$r['total'], $ventasDia));
$labEst    = json_encode(array_map(fn($r)=>ESTADOS_OT[$r['estado']]['label']??$r['estado'], $otEstado));
$dataEst   = json_encode(array_map(fn($r)=>(int)$r['n'], $otEstado));
$colEst    = json_encode(['#6c757d','#0dcaf0','#ffc107','#198754','#0d6efd','#dc3545']);

$pageScripts = <<<JS
<script>
new Chart(document.getElementById('chart-ventas-dia'), {
  type:'bar', data:{labels:$labDias, datasets:[{label:'Ventas S/',data:$dataDias,backgroundColor:'rgba(79,70,229,.7)',borderRadius:5}]},
  options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,grid:{color:'#f3f4f6'}},x:{grid:{display:false}}}}
});
new Chart(document.getElementById('chart-ot-estado'), {
  type:'doughnut', data:{labels:$labEst, datasets:[{data:$dataEst,backgroundColor:$colEst}]},
  options:{responsive:true,plugins:{legend:{position:'bottom',labels:{font:{size:11}}}}}
});
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php'; ?>
