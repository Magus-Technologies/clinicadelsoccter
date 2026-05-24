<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';
requireLogin();
$db = getDB();

// Acepta: ?id=123  O  ?codigo=VTA-2024-0001  O  ?ref=VTA-2024-0001
$id     = (int)($_GET['id']     ?? 0);
$codigo = trim($_GET['codigo']  ?? $_GET['ref'] ?? '');

// Si vino un código en lugar de id, buscar el id real
if (!$id && $codigo) {
    $busca = $db->prepare("SELECT id FROM ventas WHERE codigo = ? LIMIT 1");
    $busca->execute([$codigo]);
    $id = (int)$busca->fetchColumn();
}

$venta = $db->prepare("
    SELECT v.*, c.nombre as cliente_nombre, c.ruc_dni, c.telefono,
           CONCAT(u.nombre,' ',u.apellido) as vendedor_nombre
    FROM ventas v
    LEFT JOIN clientes c ON c.id=v.cliente_id
    JOIN usuarios u ON u.id=v.usuario_id
    WHERE v.id=?");
$venta->execute([$id]);
$venta = $venta->fetch();
if (!$venta) { setFlash('danger','Venta no encontrada (id='.$id.', codigo='.$codigo.')'); redirect(BASE_URL.'modules/ventas/index.php'); }

$detalle = $db->prepare("
    SELECT vd.*, p.nombre as prod_nombre, p.codigo as prod_codigo, p.marca
    FROM venta_detalle vd
    JOIN productos p ON p.id=vd.producto_id
    WHERE vd.venta_id=? ORDER BY vd.id");
$detalle->execute([$id]);
$detalle = $detalle->fetchAll();

// Anular venta
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'anular') {
    $user = currentUser();
    $db->prepare("UPDATE ventas SET estado='anulada' WHERE id=?")->execute([$id]);
    foreach ($detalle as $d) {
        $s = $db->prepare("SELECT stock_actual FROM productos WHERE id=?"); $s->execute([$d['producto_id']]);
        $antes = (float)$s->fetchColumn();
        $despues = $antes + $d['cantidad'];
        $db->prepare("UPDATE productos SET stock_actual=? WHERE id=?")->execute([$despues,$d['producto_id']]);
        $db->prepare("INSERT INTO kardex (producto_id,tipo,cantidad,stock_antes,stock_despues,precio_unit,motivo,referencia,usuario_id) VALUES (?,?,?,?,?,?,?,?,?)")
           ->execute([$d['producto_id'],'devolucion',$d['cantidad'],$antes,$despues,$d['precio_unit'],'Venta anulada',$venta['codigo'],$user['id']]);
    }
    setFlash('success','Venta anulada y stock restaurado.');
    redirect(BASE_URL.'modules/ventas/detalle.php?id='.$id);
}

$pageTitle  = $venta['codigo'].' — '.APP_NAME;
$breadcrumb = [
    ['label'=>'Historial ventas','url'=>BASE_URL.'modules/ventas/index.php'],
    ['label'=>$venta['codigo'],'url'=>null],
];
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h4 class="fw-bold mb-0"><?= sanitize($venta['codigo']) ?></h4>
    <span class="badge bg-<?= $venta['estado']==='completada'?'success':($venta['estado']==='anulada'?'danger':'warning') ?>"><?= ucfirst($venta['estado']) ?></span>
    <span class="text-muted small ms-2"><?= formatDateTime($venta['created_at']) ?></span>
  </div>
  <div class="d-flex gap-2">
    <?php if($venta['estado']==='completada'): ?>
    <form method="POST" class="d-inline">
      <input type="hidden" name="action" value="anular"/>
      <button type="submit" class="btn btn-outline-danger btn-sm"
              data-confirm="¿Anular esta venta? Se restaurará el stock.">Anular venta</button>
    </form>
    <?php endif; ?>
    <a href="<?= BASE_URL ?>modules/ventas/ticket.php?id=<?= $id ?>" target="_blank" class="btn btn-primary btn-sm">
      <i data-feather="printer" style="width:14px;height:14px"></i> Imprimir comprobante
    </a>
    <a href="<?= BASE_URL ?>modules/ventas/index.php" class="btn btn-outline-secondary btn-sm">← Volver</a>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="tr-card mb-3">
      <div class="tr-card-header"><h6 class="mb-0 small fw-semibold">PRODUCTOS VENDIDOS</h6></div>
      <div class="tr-card-body p-0">
        <table class="tr-table">
          <thead><tr><th>Código</th><th>Producto</th><th>Marca</th><th>Cantidad</th><th>P. Unit.</th><th>Descuento</th><th>Subtotal</th></tr></thead>
          <tbody>
            <?php foreach($detalle as $d): ?>
            <tr>
              <td><code class="small"><?= sanitize($d['prod_codigo']) ?></code></td>
              <td class="fw-semibold"><?= sanitize($d['prod_nombre']) ?></td>
              <td class="small text-muted"><?= sanitize($d['marca'] ?? '—') ?></td>
              <td><?= number_format($d['cantidad'],2) ?></td>
              <td><?= formatMoney($d['precio_unit']) ?></td>
              <td><?= ($d['descuento']??0)>0 ? formatMoney($d['descuento']) : '—' ?></td>
              <td class="fw-semibold"><?= formatMoney($d['subtotal']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($detalle)): ?>
            <tr><td colspan="7" class="text-center text-muted py-3">Sin detalle de productos</td></tr>
            <?php endif; ?>
          </tbody>
          <tfoot>
            <tr class="table-light">
              <td colspan="5"></td>
              <td class="small text-muted">Subtotal:</td>
              <td class="fw-semibold"><?= formatMoney($venta['subtotal']) ?></td>
            </tr>
            <?php if($venta['descuento']>0): ?>
            <tr class="table-light">
              <td colspan="5"></td>
              <td class="small text-danger">Descuento:</td>
              <td class="fw-semibold text-danger">-<?= formatMoney($venta['descuento']) ?></td>
            </tr>
            <?php endif; ?>
            <tr class="table-light">
              <td colspan="5"></td>
              <td class="small text-muted">IGV (18%):</td>
              <td class="fw-semibold"><?= formatMoney($venta['igv']) ?></td>
            </tr>
            <tr class="table-warning">
              <td colspan="5"></td>
              <td class="fw-bold">TOTAL:</td>
              <td class="fw-bold fs-5"><?= formatMoney($venta['total']) ?></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="tr-card">
      <div class="tr-card-header"><h6 class="mb-0 small fw-semibold">DATOS DE LA VENTA</h6></div>
      <div class="tr-card-body">
        <?php $rows = [
            'Código'       => $venta['codigo'],
            'Vendedor'     => $venta['vendedor_nombre'],
            'Cliente'      => $venta['cliente_nombre'] ?? '— Consumidor final —',
            'DNI/RUC'      => $venta['ruc_dni']    ?? '—',
            'Teléfono'     => $venta['telefono']   ?? '—',
            'Comprobante'  => ucfirst($venta['tipo_doc']),
            'Método pago'  => ucfirst($venta['metodo_pago']),
            'Monto pagado' => formatMoney($venta['monto_pagado'] ?? $venta['total']),
            'Vuelto'       => formatMoney($venta['vuelto'] ?? 0),
            'Fecha'        => formatDateTime($venta['created_at']),
        ];
        foreach($rows as $label => $val): ?>
        <div class="d-flex justify-content-between small mb-2 pb-1 border-bottom">
          <span class="text-muted"><?= $label ?></span>
          <span class="fw-semibold text-end"><?= sanitize((string)$val) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
