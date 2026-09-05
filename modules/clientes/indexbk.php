<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';
requireLogin();

$db = getDB();
$q  = trim($_GET['q'] ?? '');
$seg = $_GET['segmento'] ?? '';

$where  = ['c.activo=1'];
$params = [];
if ($q)   { $where[]='(c.nombre LIKE ? OR c.ruc_dni LIKE ? OR c.telefono LIKE ? OR c.email LIKE ?)'; $like='%'.$q.'%'; $params=array_merge($params,[$like,$like,$like,$like]); }
if ($seg) { $where[]='c.segmento=?'; $params[]=$seg; }

$clientes = $db->prepare("
  SELECT c.*,
    (SELECT COUNT(*) FROM ordenes_trabajo WHERE cliente_id=c.id) as total_ots,
    (SELECT COUNT(*) FROM ventas WHERE cliente_id=c.id AND estado='completada') as total_ventas,
    (SELECT COALESCE(SUM(precio_final),0) FROM ordenes_trabajo WHERE cliente_id=c.id AND pagado=1) as gasto_rep,
    (SELECT COALESCE(SUM(total),0) FROM ventas WHERE cliente_id=c.id AND estado='completada') as gasto_ven
  FROM clientes c WHERE ".implode(' AND ',$where)."
  ORDER BY c.nombre LIMIT 300");
$clientes->execute($params);
$clientes = $clientes->fetchAll();

$pageTitle  = 'Clientes — '.APP_NAME;
$breadcrumb = [['label'=>'Clientes','url'=>null]];
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="fw-bold mb-0">Clientes</h5>
  <a href="<?= BASE_URL ?>modules/clientes/nuevo.php" class="btn btn-primary btn-sm">
    <i data-feather="user-plus" style="width:14px;height:14px"></i> Nuevo cliente
  </a>
</div>
<div class="tr-card mb-3">
  <div class="tr-card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-5"><input type="text" name="q" class="form-control form-control-sm" placeholder="Buscar nombre, DNI, teléfono, email..." value="<?= sanitize($q) ?>"/></div>
      <div class="col-md-3">
        <select name="segmento" class="form-select form-select-sm">
          <option value="">Todos los segmentos</option>
          <?php foreach(['nuevo'=>'Nuevo','frecuente'=>'Frecuente','empresa'=>'Empresa','vip'=>'VIP'] as $v=>$l): ?>
          <option value="<?= $v ?>" <?= $seg===$v?'selected':'' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-1"><button type="submit" class="btn btn-primary btn-sm w-100">Filtrar</button></div>
    </form>
  </div>
</div>
<div class="tr-card">
  <div class="tr-card-body p-0" style="overflow:hidden"><div class="table-responsive-wrapper" style="overflow-x:auto;-webkit-overflow-scrolling:touch">
    <table class="tr-table">
      <thead>
        <tr><th>Código</th><th>Cliente</th><th>Contacto</th><th>Segmento</th><th>OTs</th><th>Ventas</th><th>Gasto total</th><th>Desde</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach($clientes as $c): ?>
        <tr>
          <td><code class="small"><?= sanitize($c['codigo']) ?></code></td>
          <td>
            <a href="<?= BASE_URL ?>modules/clientes/ver.php?id=<?= $c['id'] ?>" class="fw-semibold text-decoration-none"><?= sanitize($c['nombre']) ?></a>
            <?php if($c['ruc_dni']): ?><div class="text-muted" style="font-size:11px">DNI/RUC: <?= sanitize($c['ruc_dni']) ?></div><?php endif; ?>
          </td>
          <td class="small">
            <?php if($c['telefono']): ?><div>📞 <?= sanitize($c['telefono']) ?></div><?php endif; ?>
            <?php if($c['whatsapp']): ?><div>💬 <?= sanitize($c['whatsapp']) ?></div><?php endif; ?>
          </td>
          <td>
            <?php $sbadge=['nuevo'=>'secondary','frecuente'=>'primary','empresa'=>'info','vip'=>'warning'];
            echo '<span class="badge bg-'.($sbadge[$c['segmento']]??'secondary').'">'.ucfirst($c['segmento']).'</span>'; ?>
          </td>
          <td class="text-center"><?= $c['total_ots'] ?></td>
          <td class="text-center"><?= $c['total_ventas'] ?></td>
          <td class="fw-semibold"><?= formatMoney($c['gasto_rep']+$c['gasto_ven']) ?></td>
          <td class="text-muted small"><?= formatDate($c['created_at']) ?></td>
          <td>
            <div class="btn-group btn-group-sm">
              <a href="<?= BASE_URL ?>modules/clientes/ver.php?id=<?= $c['id'] ?>" class="btn btn-outline-primary"><i data-feather="eye" style="width:13px;height:13px"></i></a>
              <a href="<?= BASE_URL ?>modules/clientes/editar.php?id=<?= $c['id'] ?>" class="btn btn-outline-secondary"><i data-feather="edit-2" style="width:13px;height:13px"></i></a>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
