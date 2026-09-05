<?php
/**
 * ajax_documento.php
 * Devuelve HTML del detalle de un documento para mostrarlo en modal.
 * ?tipo=venta&ref=VTA-2024-0001
 * ?tipo=ot&ref=OT-2024-0001
 * ?tipo=compra&ref=COMPRA-001  (por id: ?tipo=compra&id=5)
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';
if (!isLoggedIn()) { echo '<p class="text-danger p-3">No autorizado</p>'; exit; }

$db   = getDB();
$tipo = $_GET['tipo'] ?? '';
$ref  = trim($_GET['ref'] ?? '');
$rid  = (int)($_GET['id']  ?? 0);

// ─────────────────────────────────────────
if ($tipo === 'venta') {
// ─────────────────────────────────────────
    $v = $db->prepare("
        SELECT v.*, c.nombre AS cliente_nombre, c.ruc_dni, c.telefono,
               CONCAT(u.nombre,' ',u.apellido) AS vendedor_nombre
        FROM ventas v
        LEFT JOIN clientes c ON c.id=v.cliente_id
        JOIN usuarios u ON u.id=v.usuario_id
        WHERE v.codigo = ?");
    $v->execute([$ref]);
    $venta = $v->fetch();
    if (!$venta) { echo '<p class="text-danger p-3">Venta no encontrada</p>'; exit; }

    // LEFT JOIN: una línea puede ser una OT y no tener producto asociado.
    $detalle = $db->prepare("
        SELECT vd.*,
               COALESCE(p.nombre, vd.concepto, o.codigo_ot) AS prod_nombre,
               COALESCE(p.codigo, o.codigo_ot, '—')         AS prod_codigo,
               COALESCE(cat.nombre, 'Servicio')             AS cat_nombre
        FROM venta_detalle vd
        LEFT JOIN productos       p   ON p.id   = vd.producto_id
        LEFT JOIN categorias      cat ON cat.id = p.categoria_id
        LEFT JOIN ordenes_trabajo o   ON o.id   = vd.ot_id
        WHERE vd.venta_id=? ORDER BY vd.id");
    $detalle->execute([$venta['id']]);
    $detalle = $detalle->fetchAll();
    ?>
    <!-- Header venta -->
    <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
      <div>
        <span class="fw-bold fs-5"><?= sanitize($venta['codigo']) ?></span>
        <span class="badge bg-<?= $venta['estado']==='completada'?'success':($venta['estado']==='anulada'?'danger':'warning') ?> ms-2">
          <?= ucfirst($venta['estado']) ?>
        </span>
      </div>
      <div class="text-muted small"><?= formatDateTime($venta['created_at']) ?></div>
    </div>

    <!-- Info cliente -->
    <div class="row g-2 mb-3">
      <div class="col-6"><div class="p-2 bg-light rounded small"><div class="text-muted">Cliente</div><div class="fw-semibold"><?= sanitize($venta['cliente_nombre'] ?: 'Consumidor final') ?></div></div></div>
      <div class="col-3"><div class="p-2 bg-light rounded small"><div class="text-muted">Comprobante</div><div class="fw-semibold"><?= ucfirst($venta['tipo_doc']) ?></div></div></div>
      <div class="col-3"><div class="p-2 bg-light rounded small"><div class="text-muted">Método pago</div><div class="fw-semibold"><?= ucfirst($venta['metodo_pago']) ?></div></div></div>
    </div>

    <!-- Detalle de productos -->
    <div class="table-responsive mb-3">
      <table class="table table-sm table-bordered" style="font-size:12px">
        <thead style="background:#1a1a2e;color:#fff">
          <tr><th>#</th><th>Producto</th><th>Categoría</th><th class="text-center">Cant.</th><th class="text-end">P.Unit</th><th class="text-end">Subtotal</th></tr>
        </thead>
        <tbody>
          <?php foreach($detalle as $i=>$d): ?>
          <tr>
            <td class="text-center text-muted"><?= $i+1 ?></td>
            <td><div class="fw-semibold"><?= sanitize($d['prod_nombre']) ?></div><div class="text-muted" style="font-size:10px"><?= sanitize($d['prod_codigo']) ?></div></td>
            <td class="small text-muted"><?= sanitize($d['cat_nombre']) ?></td>
            <td class="text-center"><?= number_format($d['cantidad'],2) ?></td>
            <td class="text-end"><?= formatMoney($d['precio_unit']) ?></td>
            <td class="text-end fw-semibold"><?= formatMoney($d['subtotal']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <?php if($venta['descuento']>0): ?>
          <tr><td colspan="5" class="text-end text-danger small">Descuento:</td><td class="text-end text-danger fw-semibold">-<?= formatMoney($venta['descuento']) ?></td></tr>
          <?php endif; ?>
          <tr><td colspan="5" class="text-end small text-muted">IGV (18%):</td><td class="text-end"><?= formatMoney($venta['igv']) ?></td></tr>
          <tr style="background:#1a1a2e;color:#fff"><td colspan="5" class="text-end fw-bold">TOTAL:</td><td class="text-end fw-bold"><?= formatMoney($venta['total']) ?></td></tr>
        </tfoot>
      </table>
    </div>

    <!-- Pago -->
    <div class="row g-2">
      <div class="col-4"><div class="p-2 rounded text-center" style="background:#f0fdf4;border:1px solid #86efac"><div class="small text-muted">Total</div><div class="fw-bold text-success"><?= formatMoney($venta['total']) ?></div></div></div>
      <div class="col-4"><div class="p-2 rounded text-center" style="background:#eff6ff;border:1px solid #93c5fd"><div class="small text-muted">Recibido</div><div class="fw-bold text-primary"><?= formatMoney($venta['monto_pagado']??$venta['total']) ?></div></div></div>
      <div class="col-4"><div class="p-2 bg-light rounded text-center"><div class="small text-muted">Vuelto</div><div class="fw-bold"><?= formatMoney($venta['vuelto']??0) ?></div></div></div>
    </div>

    <?php if($venta['notas']): ?>
    <div class="mt-2 p-2 bg-light rounded small"><strong>Notas:</strong> <?= sanitize($venta['notas']) ?></div>
    <?php endif; ?>

    <div class="mt-3 d-flex gap-2">
      <a href="<?= BASE_URL ?>modules/ventas/ticket.php?id=<?= $venta['id'] ?>" target="_blank" class="btn btn-outline-secondary btn-sm">
        <i data-feather="printer" style="width:13px;height:13px"></i> Imprimir comprobante
      </a>
    </div>
    <?php

// ─────────────────────────────────────────
} elseif ($tipo === 'ot') {
// ─────────────────────────────────────────
    $o = $db->prepare("
        SELECT ot.*, c.nombre AS cliente_nombre, c.ruc_dni, c.telefono,
               te.nombre AS tipo_equipo, e.marca, e.modelo, e.serial,
               CONCAT(u.nombre,' ',u.apellido) AS tecnico_nombre
        FROM ordenes_trabajo ot
        JOIN clientes c ON c.id=ot.cliente_id
        JOIN equipos e ON e.id=ot.equipo_id
        JOIN tipos_equipo te ON te.id=e.tipo_equipo_id
        LEFT JOIN usuarios u ON u.id=ot.tecnico_id
        WHERE ot.codigo_ot = ?");
    $o->execute([$ref]);
    $ot = $o->fetch();
    if (!$ot) { echo '<p class="text-danger p-3">OT no encontrada</p>'; exit; }

    $repuestos = $db->prepare("SELECT * FROM ot_repuestos WHERE ot_id=?"); $repuestos->execute([$ot['id']]); $repuestos=$repuestos->fetchAll();
    $estados = ESTADOS_OT;
    $eInfo   = $estados[$ot['estado']] ?? ['label'=>$ot['estado'],'color'=>'secondary'];
    ?>
    <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
      <div>
        <span class="fw-bold fs-5"><?= sanitize($ot['codigo_ot']) ?></span>
        <span class="badge bg-<?= $eInfo['color'] ?> ms-2"><?= $eInfo['label'] ?></span>
      </div>
      <div class="text-muted small"><?= formatDateTime($ot['fecha_ingreso']) ?></div>
    </div>

    <div class="row g-2 mb-3">
      <div class="col-6"><div class="p-2 bg-light rounded small"><div class="text-muted">Cliente</div><div class="fw-semibold"><?= sanitize($ot['cliente_nombre']) ?></div><?php if($ot['ruc_dni']): ?><div class="text-muted">DNI: <?= sanitize($ot['ruc_dni']) ?></div><?php endif; ?></div></div>
      <div class="col-6"><div class="p-2 bg-light rounded small"><div class="text-muted">Equipo</div><div class="fw-semibold"><?= sanitize($ot['tipo_equipo'].' '.($ot['marca']??'').' '.($ot['modelo']??'')) ?></div><?php if($ot['serial']): ?><div class="text-muted" style="font-size:10px">S/N: <?= sanitize($ot['serial']) ?></div><?php endif; ?></div></div>
    </div>

    <div class="mb-2 p-2 bg-light rounded small">
      <strong>Problema reportado:</strong><br>
      <?= nl2br(sanitize($ot['problema_reportado'])) ?>
    </div>
    <?php if($ot['diagnostico_tecnico']): ?>
    <div class="mb-2 p-2 bg-light rounded small">
      <strong>Diagnóstico técnico:</strong><br>
      <?= nl2br(sanitize($ot['diagnostico_tecnico'])) ?>
    </div>
    <?php endif; ?>

    <?php if($repuestos): ?>
    <div class="table-responsive mb-3">
      <table class="table table-sm table-bordered" style="font-size:12px">
        <thead style="background:#1a1a2e;color:#fff"><tr><th>Servicio / Repuesto</th><th class="text-center">Cant.</th><th class="text-end">P.Unit</th><th class="text-end">Subtotal</th></tr></thead>
        <tbody>
          <?php foreach($repuestos as $r): ?>
          <tr>
            <td><?= sanitize($r['descripcion']) ?></td>
            <td class="text-center"><?= $r['cantidad'] ?></td>
            <td class="text-end"><?= formatMoney($r['precio_unit']) ?></td>
            <td class="text-end fw-semibold"><?= formatMoney($r['subtotal']) ?></td>
          </tr>
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

    <?php if($ot['tecnico_nombre']): ?>
    <div class="small text-muted mb-2">👨‍🔧 Técnico: <strong><?= sanitize($ot['tecnico_nombre']) ?></strong></div>
    <?php endif; ?>
    <?php if($ot['fecha_estimada']): ?>
    <div class="small text-muted mb-2">📅 Fecha estimada: <strong><?= formatDate($ot['fecha_estimada']) ?></strong></div>
    <?php endif; ?>

    <div class="d-flex gap-2 mt-3">
      <a href="<?= BASE_URL ?>modules/ot/ver.php?id=<?= $ot['id'] ?>" class="btn btn-outline-primary btn-sm">Ver OT completa →</a>
      <a href="<?= BASE_URL ?>modules/ot/pdf.php?id=<?= $ot['id'] ?>" target="_blank" class="btn btn-outline-secondary btn-sm">
        <i data-feather="file-text" style="width:13px;height:13px"></i> PDF
      </a>
    </div>
    <?php

// ─────────────────────────────────────────
} elseif ($tipo === 'compra') {
// ─────────────────────────────────────────
    $cid = $rid ?: (int)$db->prepare("SELECT id FROM compras WHERE nro_doc=? ORDER BY id DESC LIMIT 1")->execute([$ref]) ?: 0;
    if (!$cid && $ref) {
        $s = $db->prepare("SELECT id FROM compras WHERE nro_doc=? ORDER BY id DESC LIMIT 1");
        $s->execute([$ref]); $cid = (int)$s->fetchColumn();
    }
    if (!$cid) { echo '<p class="text-muted p-3 text-center">No se encontró detalle de compra para esta referencia.<br><small>'.$ref.'</small></p>'; exit; }
    $comp = $db->prepare("SELECT co.*, CONCAT(u.nombre,' ',u.apellido) AS usuario_nombre FROM compras co JOIN usuarios u ON u.id=co.usuario_id WHERE co.id=?");
    $comp->execute([$cid]); $comp=$comp->fetch();
    $items = $db->prepare("SELECT cd.*, p.nombre AS prod_nombre, p.codigo FROM compra_detalle cd JOIN productos p ON p.id=cd.producto_id WHERE cd.compra_id=?");
    $items->execute([$cid]); $items=$items->fetchAll();
    ?>
    <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
      <div>
        <span class="fw-bold">Compra a: <?= sanitize($comp['proveedor'] ?: 'Sin proveedor') ?></span>
        <?php if($comp['nro_doc']): ?><span class="badge bg-secondary ms-2"><?= ucfirst($comp['tipo_doc']) ?> <?= sanitize($comp['nro_doc']) ?></span><?php endif; ?>
      </div>
      <div class="text-muted small"><?= formatDateTime($comp['created_at']) ?></div>
    </div>

    <div class="table-responsive mb-3">
      <table class="table table-sm table-bordered" style="font-size:12px">
        <thead style="background:#1a1a2e;color:#fff"><tr><th>Código</th><th>Producto</th><th class="text-center">Cant.</th><th class="text-end">P.Costo</th><th class="text-end">Subtotal</th></tr></thead>
        <tbody>
          <?php foreach($items as $it): ?>
          <tr>
            <td><code class="small"><?= sanitize($it['codigo']) ?></code></td>
            <td class="fw-semibold"><?= sanitize($it['prod_nombre']) ?></td>
            <td class="text-center"><?= number_format($it['cantidad'],2) ?></td>
            <td class="text-end"><?= formatMoney($it['precio_unit']) ?></td>
            <td class="text-end fw-semibold"><?= formatMoney($it['subtotal']) ?></td>
          </tr>
          <?php endforeach; ?>
          <tr style="background:#1a1a2e;color:#fff"><td colspan="4" class="text-end fw-bold">TOTAL:</td><td class="text-end fw-bold"><?= formatMoney($comp['total']) ?></td></tr>
        </tbody>
      </table>
    </div>

    <div class="row g-2">
      <div class="col-4"><div class="p-2 bg-light rounded text-center small"><div class="text-muted">Método pago</div><div class="fw-semibold"><?= ucfirst($comp['metodo_pago']) ?></div></div></div>
      <div class="col-4"><div class="p-2 bg-light rounded text-center small"><div class="text-muted">Registrado por</div><div class="fw-semibold"><?= sanitize($comp['usuario_nombre']) ?></div></div></div>
      <div class="col-4"><div class="p-2 rounded text-center small" style="background:#fef2f2;border:1px solid #fca5a5"><div class="text-muted">Total</div><div class="fw-bold text-danger"><?= formatMoney($comp['total']) ?></div></div></div>
    </div>
    <?php if($comp['notas']): ?><div class="mt-2 p-2 bg-light rounded small"><strong>Notas:</strong> <?= sanitize($comp['notas']) ?></div><?php endif; ?>
    <?php

} else {
    echo '<p class="text-muted p-3">Tipo de documento no reconocido.</p>';
}
?>
