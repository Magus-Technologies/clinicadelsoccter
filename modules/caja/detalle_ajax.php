<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';
if (!isLoggedIn()) { echo '<p class="text-danger p-3">No autorizado</p>'; exit; }

$db  = getDB();
$id  = (int)($_GET['id'] ?? 0);

$caja = $db->prepare("
    SELECT ca.*, CONCAT(u.nombre,' ',u.apellido) AS usuario_nombre
    FROM cajas ca JOIN usuarios u ON u.id = ca.usuario_id
    WHERE ca.id = ?");
$caja->execute([$id]);
$caja = $caja->fetch();
if (!$caja) { echo '<p class="text-danger p-3">Caja no encontrada</p>'; exit; }

$movs = $db->prepare("
    SELECT mv.*, CONCAT(u.nombre,' ',u.apellido) AS usuario_nombre
    FROM movimientos_caja mv
    JOIN usuarios u ON u.id = mv.usuario_id
    WHERE mv.caja_id = ?
    ORDER BY mv.created_at ASC");
$movs->execute([$id]);
$movs = $movs->fetchAll();

// Cargar detalle de ventas y OTs referenciadas
$ventasDetalle = [];
$otsDetalle    = [];
foreach ($movs as $mv) {
    $ref = trim($mv['referencia'] ?? '');
    if (str_starts_with($ref, 'VTA-') && !isset($ventasDetalle[$ref])) {
        $v = $db->prepare("
            SELECT v.id, v.codigo, v.total, v.subtotal, v.igv, v.descuento,
                   v.metodo_pago, v.tipo_doc, v.estado, v.monto_pagado, v.vuelto,
                   v.notas, v.created_at,
                   c.nombre AS cliente_nombre, c.ruc_dni, c.telefono,
                   CONCAT(u.nombre,' ',u.apellido) AS vendedor_nombre
            FROM ventas v
            LEFT JOIN clientes c ON c.id = v.cliente_id
            JOIN usuarios u ON u.id = v.usuario_id
            WHERE v.codigo = ? LIMIT 1");
        $v->execute([$ref]);
        $ventasDetalle[$ref] = $v->fetch();

        if ($ventasDetalle[$ref]) {
            $vid = $ventasDetalle[$ref]['id'];
            // LEFT JOIN: una línea puede ser una OT y no tener producto asociado.
            $d = $db->prepare("
                SELECT vd.*,
                       COALESCE(p.nombre, vd.concepto, o.codigo_ot) AS prod_nombre,
                       COALESCE(p.codigo, o.codigo_ot, '—')         AS prod_codigo,
                       COALESCE(cat.nombre, 'Servicio')             AS cat_nombre
                FROM venta_detalle vd
                LEFT JOIN productos       p   ON p.id   = vd.producto_id
                LEFT JOIN categorias      cat ON cat.id = p.categoria_id
                LEFT JOIN ordenes_trabajo o   ON o.id   = vd.ot_id
                WHERE vd.venta_id = ? ORDER BY vd.id");
            $d->execute([$vid]);
            $ventasDetalle[$ref]['items'] = $d->fetchAll();
        }
    }
    if (str_starts_with($ref, 'OT-') && !isset($otsDetalle[$ref])) {
        $o = $db->prepare("
            SELECT ot.id, ot.codigo_ot, ot.estado, ot.problema_reportado,
                   ot.diagnostico_tecnico, ot.costo_repuestos, ot.costo_mano_obra,
                   ot.precio_final, ot.metodo_pago, ot.fecha_ingreso,
                   c.nombre AS cliente_nombre, c.ruc_dni, c.telefono,
                   te.nombre AS tipo_equipo, e.marca, e.modelo, e.serial,
                   CONCAT(u.nombre,' ',u.apellido) AS tecnico_nombre
            FROM ordenes_trabajo ot
            JOIN clientes c ON c.id = ot.cliente_id
            JOIN equipos e ON e.id = ot.equipo_id
            JOIN tipos_equipo te ON te.id = e.tipo_equipo_id
            LEFT JOIN usuarios u ON u.id = ot.tecnico_id
            WHERE ot.codigo_ot = ? LIMIT 1");
        $o->execute([$ref]);
        $otsDetalle[$ref] = $o->fetch();
        if ($otsDetalle[$ref]) {
            $oid = $otsDetalle[$ref]['id'];
            $r = $db->prepare("SELECT * FROM ot_repuestos WHERE ot_id=?"); $r->execute([$oid]);
            $otsDetalle[$ref]['repuestos'] = $r->fetchAll();
        }
    }
}

$ing    = array_sum(array_map(fn($m) => $m['tipo']==='ingreso' ? (float)$m['monto'] : 0, $movs));
$egr    = array_sum(array_map(fn($m) => $m['tipo']==='egreso'  ? (float)$m['monto'] : 0, $movs));
$sfCalc = round((float)$caja['saldo_inicial'] + $ing - $egr, 2);
$dif    = (float)($caja['diferencia_cierre'] ?? 0);

$billetes = [200, 100, 50, 20, 10];
$monedas  = [5.00, 2.00, 1.00, 0.50, 0.20, 0.10];
function dkAjax($tipo, $v) { return $tipo==='bil' ? 'bil_'.(int)$v : 'mon_'.str_replace('.','_',number_format((float)$v,2,'_','')); }

$densAp = json_decode($caja['denominaciones_apertura'] ?? '{}', true) ?: [];
$densCi = json_decode($caja['denominaciones_cierre']   ?? '{}', true) ?: [];

$ESTADOS_OT = [
    'ingresado'     => ['label'=>'Ingresado',     'color'=>'secondary'],
    'en_revision'   => ['label'=>'En revisión',   'color'=>'info'],
    'en_reparacion' => ['label'=>'En reparación', 'color'=>'warning'],
    'listo'         => ['label'=>'Listo',          'color'=>'success'],
    'entregado'     => ['label'=>'Entregado',      'color'=>'primary'],
    'cancelado'     => ['label'=>'Cancelado',      'color'=>'danger'],
];
?>
<style>
.det-panel { display:none; }
.det-panel.active { display:block; }
.mov-card { border-left:4px solid #e5e7eb; padding:10px 12px; margin-bottom:8px; background:#fff; border-radius:0 8px 8px 0; }
.mov-card.ingreso { border-left-color:#16a34a; }
.mov-card.egreso  { border-left-color:#dc2626; }
.doc-inline { background:#f0f9ff; border:1px solid #bae6fd; border-radius:8px; padding:10px 12px; margin-top:8px; }
.btn-back { font-size:12px; padding:3px 10px; }
</style>

<!-- ══ PANEL PRINCIPAL ══ -->
<div id="panel-main" class="det-panel active">

  <!-- Resumen financiero -->
  <div class="row g-2 mb-3">
    <div class="col-6 col-md-3">
      <div class="p-2 rounded text-center" style="background:#f0fdf4;border:1px solid #86efac">
        <div class="small text-muted">Saldo inicial</div>
        <div class="fw-bold text-success"><?= formatMoney($caja['saldo_inicial']) ?></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="p-2 rounded text-center" style="background:#eff6ff;border:1px solid #93c5fd">
        <div class="small text-muted">Ingresos</div>
        <div class="fw-bold text-primary"><?= formatMoney($ing) ?></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="p-2 rounded text-center" style="background:#fef2f2;border:1px solid #fca5a5">
        <div class="small text-muted">Egresos</div>
        <div class="fw-bold text-danger"><?= formatMoney($egr) ?></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="p-2 rounded text-center <?= $dif==0?'':'border-warning' ?>"
           style="background:<?= $dif==0?'#f5f3ff':($dif>0?'#fef9c3':'#fee2e2') ?>;border:1px solid <?= $dif==0?'#c4b5fd':($dif>0?'#fde047':'#fca5a5') ?>">
        <div class="small text-muted"><?= $caja['estado']==='cerrada' ? 'S.Final / Dif.' : 'S. esperado' ?></div>
        <div class="fw-bold" style="color:#7c3aed"><?= formatMoney($caja['estado']==='cerrada' ? (float)$caja['saldo_final'] : $sfCalc) ?></div>
        <?php if($caja['estado']==='cerrada' && $dif != 0): ?>
        <div class="small <?= $dif>0?'text-warning':'text-danger' ?> fw-semibold"><?= $dif>0?'⚠️ Sobrante':'❌ Faltante' ?>: <?= formatMoney(abs($dif)) ?></div>
        <?php elseif($caja['estado']==='cerrada'): ?><div class="small text-success">✅ Cuadra</div><?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Tabs -->
  <ul class="nav nav-tabs mb-3" style="font-size:13px">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#ctab-movs">
      📋 Movimientos <span class="badge bg-secondary ms-1"><?= count($movs) ?></span>
    </button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#ctab-ventas">
      🛒 Ventas <span class="badge bg-primary ms-1"><?= count(array_filter($movs, fn($m)=>$m['tipo']==='ingreso' && (str_starts_with($m['referencia']??'','VTA-')||str_starts_with($m['referencia']??'','OT-')))) ?></span>
    </button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#ctab-egresos">
      📦 Egresos <span class="badge bg-danger ms-1"><?= count(array_filter($movs, fn($m)=>$m['tipo']==='egreso')) ?></span>
    </button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#ctab-dens">
      💰 Denominaciones <?php if($caja['estado']==='cerrada'): ?><span class="badge bg-<?= $dif==0?'success':($dif>0?'warning':'danger') ?> ms-1"><?= $dif==0?'✓':formatMoney($dif) ?></span><?php endif; ?>
    </button></li>
  </ul>

  <div class="tab-content">

    <!-- ── TAB MOVIMIENTOS ── -->
    <div class="tab-pane fade show active" id="ctab-movs">
      <div style="max-height:380px;overflow-y:auto;padding-right:4px">
        <?php foreach($movs as $mv):
          $ref    = trim($mv['referencia'] ?? '');
          $esVta  = str_starts_with($ref,'VTA-');
          $esOT   = str_starts_with($ref,'OT-');
          $vDet   = $esVta ? ($ventasDetalle[$ref] ?? null) : null;
          $oDet   = $esOT  ? ($otsDetalle[$ref]    ?? null) : null;
        ?>
        <div class="mov-card <?= $mv['tipo'] ?>">
          <div class="d-flex justify-content-between align-items-start">
            <div style="flex:1;min-width:0">
              <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="badge bg-<?= $mv['tipo']==='ingreso'?'success':'danger' ?>" style="font-size:10px">
                  <?= $mv['tipo']==='ingreso'?'↑ Ingreso':'↓ Egreso' ?>
                </span>
                <span class="fw-semibold small"><?= sanitize($mv['concepto']) ?></span>
                <?php if($ref): ?>
                <span class="badge bg-light text-dark border" style="font-size:10px;font-family:monospace"><?= sanitize($ref) ?></span>
                <?php endif; ?>
              </div>
              <div class="text-muted" style="font-size:11px;margin-top:2px">
                <?= date('d/m/Y H:i', strtotime($mv['created_at'])) ?> — <?= sanitize($mv['usuario_nombre']) ?>
              </div>

              <!-- Doc preview inline -->
              <?php if($vDet): ?>
              <div class="doc-inline">
                <div class="d-flex justify-content-between align-items-start">
                  <div>
                    <div class="fw-semibold small">🛒 <?= sanitize($vDet['cliente_nombre'] ?: 'Consumidor final') ?></div>
                    <div class="text-muted" style="font-size:11px">
                      <?= ucfirst($vDet['tipo_doc']) ?> · <?= ucfirst($vDet['metodo_pago']) ?>
                      <?php if($vDet['items']): ?>
                       · <?= count($vDet['items']) ?> producto(s)
                      <?php endif; ?>
                    </div>
                    <?php if(!empty($vDet['items'])): ?>
                    <div class="text-muted mt-1" style="font-size:11px">
                      <?= sanitize(implode(', ', array_map(fn($i)=>$i['prod_nombre'], array_slice($vDet['items'],0,3)))) ?>
                      <?= count($vDet['items'])>3?' +'.(count($vDet['items'])-3).' más':'' ?>
                    </div>
                    <?php endif; ?>
                  </div>
                  <button class="btn btn-sm btn-outline-primary ms-2 flex-shrink-0"
                          style="font-size:11px;padding:2px 8px"
                          data-panel="panel-venta-<?= sanitize($ref) ?>" onclick="window.mostrarPanel && window.mostrarPanel(this.dataset.panel)">
                    Ver detalle →
                  </button>
                </div>
              </div>
              <?php elseif($oDet): ?>
              <div class="doc-inline" style="background:#f0fdf4;border-color:#86efac">
                <div class="d-flex justify-content-between align-items-start">
                  <div>
                    <div class="fw-semibold small">🔧 <?= sanitize($oDet['cliente_nombre']) ?></div>
                    <div class="text-muted" style="font-size:11px">
                      <?= sanitize(trim($oDet['tipo_equipo'].' '.($oDet['marca']??'').' '.($oDet['modelo']??''))) ?>
                    </div>
                    <?php $eOT = $ESTADOS_OT[$oDet['estado']] ?? ['label'=>$oDet['estado'],'color'=>'secondary']; ?>
                    <span class="badge bg-<?= $eOT['color'] ?>" style="font-size:10px"><?= $eOT['label'] ?></span>
                  </div>
                  <button class="btn btn-sm btn-outline-success ms-2 flex-shrink-0"
                          style="font-size:11px;padding:2px 8px"
                          data-panel="panel-ot-<?= sanitize($ref) ?>" onclick="window.mostrarPanel && window.mostrarPanel(this.dataset.panel)">
                    Ver OT →
                  </button>
                </div>
              </div>
              <?php endif; ?>
            </div>
            <div class="fw-bold ms-2 <?= $mv['tipo']==='ingreso'?'text-success':'text-danger' ?>"
                 style="font-size:15px;white-space:nowrap">
              <?= $mv['tipo']==='ingreso'?'+':'-' ?><?= formatMoney($mv['monto']) ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if(empty($movs)): ?>
        <p class="text-muted text-center py-3">Sin movimientos en esta caja.</p>
        <?php endif; ?>
      </div>
      <div class="d-flex justify-content-end gap-3 pt-2 border-top mt-2 small">
        <span class="text-success fw-bold">↑ <?= formatMoney($ing) ?></span>
        <span class="text-danger fw-bold">↓ <?= formatMoney($egr) ?></span>
        <span class="fw-bold">= <?= formatMoney($ing-$egr) ?></span>
      </div>
    </div>

    <!-- ── TAB VENTAS/REPARACIONES ── -->
    <div class="tab-pane fade" id="ctab-ventas">
      <div style="max-height:380px;overflow-y:auto">
        <?php
        $movsIng = array_filter($movs, fn($m) => $m['tipo']==='ingreso');
        if(empty($movsIng)):
        ?>
        <p class="text-muted text-center py-3">Sin ingresos en esta caja.</p>
        <?php else: ?>
        <table class="table table-sm table-hover" style="font-size:12px">
          <thead style="background:#1a1a2e;color:#fff;position:sticky;top:0">
            <tr><th>Código</th><th>Tipo</th><th>Cliente</th><th>Concepto</th><th class="text-end">Monto</th><th></th></tr>
          </thead>
          <tbody>
          <?php foreach($movsIng as $mv):
            $ref   = trim($mv['referencia'] ?? '');
            $esVta = str_starts_with($ref,'VTA-');
            $esOT  = str_starts_with($ref,'OT-');
            $vDet  = $esVta ? ($ventasDetalle[$ref] ?? null) : null;
            $oDet  = $esOT  ? ($otsDetalle[$ref]    ?? null) : null;
          ?>
          <tr>
            <td><code style="font-size:11px"><?= sanitize($ref ?: '—') ?></code></td>
            <td><span class="badge bg-<?= $esVta?'primary':($esOT?'warning text-dark':'secondary') ?>" style="font-size:10px">
              <?= $esVta?'Venta':($esOT?'OT':'Ingreso') ?>
            </span></td>
            <td class="small"><?= $vDet ? sanitize($vDet['cliente_nombre']?:'Consumidor') : ($oDet ? sanitize($oDet['cliente_nombre']) : '—') ?></td>
            <td class="small text-muted"><?= sanitize(mb_strimwidth($mv['concepto'],0,35,'…')) ?></td>
            <td class="text-end fw-bold text-success">+<?= formatMoney($mv['monto']) ?></td>
            <td>
              <?php if($vDet): ?>
              <button class="btn btn-outline-primary btn-sm py-0 px-1" style="font-size:11px"
                      data-panel="panel-venta-<?= sanitize($ref) ?>" onclick="window.mostrarPanel && window.mostrarPanel(this.dataset.panel)">Ver →</button>
              <?php elseif($oDet): ?>
              <button class="btn btn-outline-success btn-sm py-0 px-1" style="font-size:11px"
                      data-panel="panel-ot-<?= sanitize($ref) ?>" onclick="window.mostrarPanel && window.mostrarPanel(this.dataset.panel)">Ver →</button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <tr style="background:#1a1a2e;color:#fff;font-weight:700">
            <td colspan="4" class="text-end">Total ingresos:</td>
            <td class="text-end">+<?= formatMoney($ing) ?></td><td></td>
          </tr>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── TAB EGRESOS ── -->
    <div class="tab-pane fade" id="ctab-egresos">
      <div style="max-height:380px;overflow-y:auto">
        <?php $movsEgr = array_filter($movs, fn($m)=>$m['tipo']==='egreso'); ?>
        <?php if(empty($movsEgr)): ?>
        <p class="text-muted text-center py-3">Sin egresos en esta caja.</p>
        <?php else: ?>
        <table class="table table-sm table-hover" style="font-size:12px">
          <thead style="background:#1a1a2e;color:#fff;position:sticky;top:0">
            <tr><th>Hora</th><th>Concepto</th><th>Referencia</th><th>Usuario</th><th class="text-end">Monto</th></tr>
          </thead>
          <tbody>
          <?php foreach($movsEgr as $mv): ?>
          <tr>
            <td class="text-muted"><?= date('H:i',strtotime($mv['created_at'])) ?></td>
            <td><?= sanitize($mv['concepto']) ?></td>
            <td><code style="font-size:11px"><?= sanitize($mv['referencia']?:'—') ?></code></td>
            <td class="text-muted small"><?= sanitize($mv['usuario_nombre']) ?></td>
            <td class="text-end fw-bold text-danger">-<?= formatMoney($mv['monto']) ?></td>
          </tr>
          <?php endforeach; ?>
          <tr style="background:#1a1a2e;color:#fff;font-weight:700">
            <td colspan="4" class="text-end">Total egresos:</td>
            <td class="text-end">-<?= formatMoney($egr) ?></td>
          </tr>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── TAB DENOMINACIONES ── -->
    <div class="tab-pane fade" id="ctab-dens">
      <?php if($caja['estado']==='cerrada'): ?>
      <div class="alert alert-<?= $dif==0?'success':($dif>0?'warning':'danger') ?> py-2 small mb-3">
        <?= $dif==0?'✅ La caja cuadra perfectamente.':($dif>0?'⚠️ Sobrante de '.formatMoney(abs($dif)):'❌ Faltante de '.formatMoney(abs($dif))) ?>
        <?php if($dif!=0): ?> — Saldo esperado: <?= formatMoney($sfCalc) ?> | Contado: <?= formatMoney($caja['saldo_final']) ?><?php endif; ?>
      </div>
      <?php endif; ?>
      <div class="table-responsive">
      <table class="table table-sm table-bordered" style="font-size:12px">
        <thead style="background:#1a1a2e;color:#fff">
          <tr>
            <th>Denominación</th>
            <th class="text-center">Cant. Apertura</th>
            <th class="text-end">Subtotal Ap.</th>
            <?php if($caja['estado']==='cerrada'): ?>
            <th class="text-center">Cant. Cierre</th>
            <th class="text-end">Subtotal Ci.</th>
            <th class="text-end">Diferencia</th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <tr><td colspan="<?= $caja['estado']==='cerrada'?6:3 ?>" class="fw-bold small" style="background:#f3f4f6">💵 Billetes</td></tr>
          <?php foreach($billetes as $b):
            $k='bil_'.(int)$b; $cAp=(int)($densAp[$k]??0); $cCi=(int)($densCi[$k]??0);
            $sAp=round($cAp*$b,2); $sCi=round($cCi*$b,2); $dif2=round($sCi-$sAp,2);
            if(!$cAp && !$cCi) continue;
          ?>
          <tr>
            <td>S/ <?= number_format($b,2) ?></td>
            <td class="text-center"><?= $cAp ?></td>
            <td class="text-end"><?= $cAp?formatMoney($sAp):'—' ?></td>
            <?php if($caja['estado']==='cerrada'): ?>
            <td class="text-center"><?= $cCi ?></td>
            <td class="text-end"><?= $cCi?formatMoney($sCi):'—' ?></td>
            <td class="text-end fw-semibold <?= $dif2==0?'text-success':($dif2>0?'text-warning':'text-danger') ?>">
              <?= $dif2==0?'✓':($dif2>0?'+':'').formatMoney($dif2) ?>
            </td>
            <?php endif; ?>
          </tr>
          <?php endforeach; ?>
          <tr><td colspan="<?= $caja['estado']==='cerrada'?6:3 ?>" class="fw-bold small" style="background:#f3f4f6">🪙 Monedas</td></tr>
          <?php foreach($monedas as $m):
            $k='mon_'.str_replace('.','_',number_format($m,2,'_','')); $cAp=(int)($densAp[$k]??0); $cCi=(int)($densCi[$k]??0);
            $sAp=round($cAp*$m,2); $sCi=round($cCi*$m,2); $dif2=round($sCi-$sAp,2);
            if(!$cAp && !$cCi) continue;
          ?>
          <tr>
            <td>S/ <?= number_format($m,2) ?></td>
            <td class="text-center"><?= $cAp ?></td>
            <td class="text-end"><?= $cAp?formatMoney($sAp):'—' ?></td>
            <?php if($caja['estado']==='cerrada'): ?>
            <td class="text-center"><?= $cCi ?></td>
            <td class="text-end"><?= $cCi?formatMoney($sCi):'—' ?></td>
            <td class="text-end fw-semibold <?= $dif2==0?'text-success':($dif2>0?'text-warning':'text-danger') ?>">
              <?= $dif2==0?'✓':($dif2>0?'+':'').formatMoney($dif2) ?>
            </td>
            <?php endif; ?>
          </tr>
          <?php endforeach; ?>
          <?php
          $totAp=0; $totCi=0;
          foreach($billetes as $b){$k='bil_'.(int)$b; $totAp+=(int)($densAp[$k]??0)*$b; $totCi+=(int)($densCi[$k]??0)*$b;}
          foreach($monedas as $m){$k='mon_'.str_replace('.','_',number_format($m,2,'_','')); $totAp+=round((int)($densAp[$k]??0)*$m,2); $totCi+=round((int)($densCi[$k]??0)*$m,2);}
          $difTot=round($totCi-$totAp,2);
          ?>
          <tr style="background:#1a1a2e;color:#fff;font-weight:700">
            <td>TOTAL</td><td></td><td class="text-end">S/ <?= number_format($totAp,2) ?></td>
            <?php if($caja['estado']==='cerrada'): ?>
            <td></td><td class="text-end">S/ <?= number_format($totCi,2) ?></td>
            <td class="text-end" style="color:<?= $difTot==0?'#4ade80':($difTot>0?'#fde047':'#f87171') ?>">
              <?= $difTot==0?'✅':($difTot>0?'+':'').formatMoney($difTot) ?>
            </td>
            <?php endif; ?>
          </tr>
        </tbody>
      </table>
      </div>
    </div>

  </div><!-- /tab-content -->
</div><!-- /panel-main -->

<!-- ══ PANELES DE DOCUMENTOS (ocultos, se muestran al hacer clic) ══ -->

<?php foreach($ventasDetalle as $ref => $venta): if(!$venta) continue; ?>
<div id="panel-venta-<?= sanitize($ref) ?>" class="det-panel">
  <button class="btn btn-outline-secondary btn-back mb-3" data-panel="panel-main" onclick="window.mostrarPanel && window.mostrarPanel(this.dataset.panel)">
    ← Volver al resumen de caja
  </button>
  <h6 class="fw-bold mb-3">🛒 Detalle de venta — <?= sanitize($ref) ?></h6>
  <!-- Info -->
  <div class="row g-2 mb-3">
    <div class="col-6"><div class="p-2 bg-light rounded small"><div class="text-muted">Cliente</div><div class="fw-semibold"><?= sanitize($venta['cliente_nombre']?:'Consumidor final') ?></div><?php if($venta['ruc_dni']): ?><div class="text-muted">DNI: <?= sanitize($venta['ruc_dni']) ?></div><?php endif; ?></div></div>
    <div class="col-3"><div class="p-2 bg-light rounded small text-center"><div class="text-muted">Comprobante</div><div class="fw-semibold"><?= ucfirst($venta['tipo_doc']) ?></div></div></div>
    <div class="col-3"><div class="p-2 bg-light rounded small text-center"><div class="text-muted">Pago</div><div class="fw-semibold"><?= ucfirst($venta['metodo_pago']) ?></div></div></div>
  </div>
  <!-- Productos -->
  <div class="table-responsive mb-3">
    <table class="table table-sm table-bordered" style="font-size:12px">
      <thead style="background:#1a1a2e;color:#fff"><tr><th>#</th><th>Producto</th><th>Categoría</th><th class="text-center">Cant.</th><th class="text-end">P.Unit</th><th class="text-end">Subtotal</th></tr></thead>
      <tbody>
        <?php foreach(($venta['items']??[]) as $i=>$d): ?>
        <tr>
          <td class="text-center text-muted"><?= $i+1 ?></td>
          <td><div class="fw-semibold"><?= sanitize($d['prod_nombre']) ?></div><div class="text-muted" style="font-size:10px"><?= sanitize($d['prod_codigo']) ?></div></td>
          <td class="text-muted small"><?= sanitize($d['cat_nombre']) ?></td>
          <td class="text-center"><?= number_format($d['cantidad'],2) ?></td>
          <td class="text-end"><?= formatMoney($d['precio_unit']) ?></td>
          <td class="text-end fw-semibold"><?= formatMoney($d['subtotal']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <?php if($venta['descuento']>0): ?><tr><td colspan="5" class="text-end text-danger small">Descuento:</td><td class="text-end text-danger fw-semibold">-<?= formatMoney($venta['descuento']) ?></td></tr><?php endif; ?>
        <tr><td colspan="5" class="text-end text-muted small">IGV (18%):</td><td class="text-end"><?= formatMoney($venta['igv']) ?></td></tr>
        <tr style="background:#1a1a2e;color:#fff"><td colspan="5" class="text-end fw-bold">TOTAL:</td><td class="text-end fw-bold"><?= formatMoney($venta['total']) ?></td></tr>
      </tfoot>
    </table>
  </div>
  <!-- Pago -->
  <div class="row g-2 mb-3">
    <div class="col-4"><div class="p-2 rounded text-center" style="background:#f0fdf4;border:1px solid #86efac"><div class="small text-muted">Total</div><div class="fw-bold text-success"><?= formatMoney($venta['total']) ?></div></div></div>
    <div class="col-4"><div class="p-2 rounded text-center" style="background:#eff6ff;border:1px solid #93c5fd"><div class="small text-muted">Recibido</div><div class="fw-bold text-primary"><?= formatMoney($venta['monto_pagado']??$venta['total']) ?></div></div></div>
    <div class="col-4"><div class="p-2 bg-light rounded text-center"><div class="small text-muted">Vuelto</div><div class="fw-bold"><?= formatMoney($venta['vuelto']??0) ?></div></div></div>
  </div>
  <a href="<?= BASE_URL ?>modules/ventas/ticket.php?id=<?= $venta['id'] ?>" target="_blank" class="btn btn-outline-secondary btn-sm">
    <i data-feather="printer" style="width:13px;height:13px"></i> Imprimir comprobante
  </a>
</div>
<?php endforeach; ?>

<?php foreach($otsDetalle as $ref => $ot): if(!$ot) continue;
  $eOT = $ESTADOS_OT[$ot['estado']] ?? ['label'=>$ot['estado'],'color'=>'secondary'];
?>
<div id="panel-ot-<?= sanitize($ref) ?>" class="det-panel">
  <button class="btn btn-outline-secondary btn-back mb-3" data-panel="panel-main" onclick="window.mostrarPanel && window.mostrarPanel(this.dataset.panel)">
    ← Volver al resumen de caja
  </button>
  <h6 class="fw-bold mb-3">🔧 Detalle de OT — <?= sanitize($ref) ?></h6>
  <div class="row g-2 mb-3">
    <div class="col-6"><div class="p-2 bg-light rounded small"><div class="text-muted">Cliente</div><div class="fw-semibold"><?= sanitize($ot['cliente_nombre']) ?></div><?php if($ot['ruc_dni']): ?><div class="text-muted">DNI: <?= sanitize($ot['ruc_dni']) ?></div><?php endif; ?></div></div>
    <div class="col-6"><div class="p-2 bg-light rounded small"><div class="text-muted">Equipo</div><div class="fw-semibold"><?= sanitize(trim($ot['tipo_equipo'].' '.($ot['marca']??'').' '.($ot['modelo']??''))) ?></div><?php if($ot['serial']): ?><div class="text-muted" style="font-size:10px">S/N: <?= sanitize($ot['serial']) ?></div><?php endif; ?></div></div>
  </div>
  <div class="mb-2"><span class="badge bg-<?= $eOT['color'] ?>"><?= $eOT['label'] ?></span></div>
  <div class="mb-2 p-2 bg-light rounded small"><strong>Problema:</strong> <?= nl2br(sanitize($ot['problema_reportado'])) ?></div>
  <?php if($ot['diagnostico_tecnico']): ?><div class="mb-2 p-2 bg-light rounded small"><strong>Diagnóstico:</strong> <?= nl2br(sanitize($ot['diagnostico_tecnico'])) ?></div><?php endif; ?>
  <?php if(!empty($ot['repuestos'])): ?>
  <div class="table-responsive mb-3">
    <table class="table table-sm table-bordered" style="font-size:12px">
      <thead style="background:#1a1a2e;color:#fff"><tr><th>Repuesto / Servicio</th><th class="text-center">Cant.</th><th class="text-end">P.Unit</th><th class="text-end">Subtotal</th></tr></thead>
      <tbody>
        <?php foreach($ot['repuestos'] as $r): ?>
        <tr><td><?= sanitize($r['descripcion']) ?></td><td class="text-center"><?= $r['cantidad'] ?></td><td class="text-end"><?= formatMoney($r['precio_unit']) ?></td><td class="text-end fw-semibold"><?= formatMoney($r['subtotal']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <div class="row g-2 mb-3">
    <div class="col-4"><div class="p-2 bg-light rounded text-center small"><div class="text-muted">Repuestos</div><div class="fw-semibold"><?= formatMoney($ot['costo_repuestos']) ?></div></div></div>
    <div class="col-4"><div class="p-2 bg-light rounded text-center small"><div class="text-muted">Mano de obra</div><div class="fw-semibold"><?= formatMoney($ot['costo_mano_obra']) ?></div></div></div>
    <div class="col-4"><div class="p-2 rounded text-center small" style="background:#1a1a2e;color:#fff"><div>TOTAL</div><div class="fw-bold fs-5"><?= formatMoney($ot['precio_final']) ?></div></div></div>
  </div>
  <a href="<?= BASE_URL ?>modules/ot/pdf.php?id=<?= $ot['id'] ?>" target="_blank" class="btn btn-outline-secondary btn-sm">
    <i data-feather="file-text" style="width:13px;height:13px"></i> Imprimir OT
  </a>
</div>
<?php endforeach; ?>