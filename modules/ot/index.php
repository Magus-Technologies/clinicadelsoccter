<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';
requireLogin();

$db = getDB();

// Filtros
$f_estado      = $_GET['estado']      ?? '';
$f_tecnico     = $_GET['tecnico']     ?? '';
$f_buscar      = trim($_GET['q']      ?? '');
$f_desde       = $_GET['desde']       ?? '';
$f_hasta       = $_GET['hasta']       ?? '';
$f_servicio_id = (int)($_GET['servicio_id'] ?? 0);

$where  = [];
$params = [];

if ($f_estado)      { $where[] = 'ot.estado = ?';            $params[] = $f_estado; }
if ($f_tecnico)     { $where[] = '(ot.tecnico_id = ? OR EXISTS(SELECT 1 FROM ot_tecnicos ot2 WHERE ot2.ot_id=ot.id AND ot2.tecnico_id=?))'; $params[] = $f_tecnico; $params[] = $f_tecnico; }
if ($f_desde)       { $where[] = 'DATE(ot.created_at) >= ?';  $params[] = $f_desde; }
if ($f_hasta)       { $where[] = 'DATE(ot.created_at) <= ?';  $params[] = $f_hasta; }
if ($f_servicio_id) { $where[] = 'ot.servicio_id = ?';        $params[] = $f_servicio_id; }
if ($f_buscar)  {
    $where[] = '(ot.codigo_ot LIKE ? OR c.nombre LIKE ? OR ot.codigo_publico LIKE ?)';
    $like = '%' . $f_buscar . '%';
    $params = array_merge($params, [$like, $like, $like]);
}

// Nombre del servicio filtrado (para mostrar en encabezado)
$servicio_filtrado = null;
if ($f_servicio_id) {
    $st = $db->prepare("SELECT nombre FROM servicios WHERE id=?");
    $st->execute([$f_servicio_id]);
    $servicio_filtrado = $st->fetchColumn();
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$ots = $db->prepare("
  SELECT ot.*, c.nombre as cliente_nombre, c.telefono as cliente_tel,
         te.nombre as tipo_equipo, e.marca, e.modelo,
         COALESCE(
           (SELECT GROUP_CONCAT(CONCAT(u2.nombre,' ',u2.apellido) ORDER BY ot2.tecnico_id SEPARATOR ' / ')
            FROM ot_tecnicos ot2 JOIN usuarios u2 ON u2.id = ot2.tecnico_id WHERE ot2.ot_id = ot.id),
           CONCAT(u.nombre,' ',u.apellido)
         ) as tecnicos_nombres,
         s.nombre as servicio_nombre, s.categoria as servicio_categoria
  FROM ordenes_trabajo ot
  JOIN clientes c    ON c.id  = ot.cliente_id
  JOIN equipos e     ON e.id  = ot.equipo_id
  JOIN tipos_equipo te ON te.id = e.tipo_equipo_id
  LEFT JOIN usuarios u ON u.id = ot.tecnico_id
  LEFT JOIN servicios s ON s.id = ot.servicio_id
  $whereSQL
  ORDER BY ot.created_at DESC
  LIMIT 200
");
$ots->execute($params);
$lista = $ots->fetchAll();

// Para el filtro de técnicos
$tecnicos = $db->query("SELECT id,CONCAT(nombre,' ',apellido) as nombre FROM usuarios WHERE rol='tecnico' AND activo=1")->fetchAll();
// Para el filtro de servicios
$servicios_lista = $db->query("SELECT id, nombre FROM servicios WHERE activo=1 ORDER BY nombre")->fetchAll();

$pageTitle  = 'Órdenes de trabajo — ' . APP_NAME;
$breadcrumb = [['label'=>'Órdenes de trabajo','url'=>null]];
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h5 class="fw-bold mb-0">Órdenes de trabajo
      <?php if ($servicio_filtrado): ?>
      <span class="badge bg-primary ms-2" style="font-size:13px; vertical-align:middle">
        <?= sanitize($servicio_filtrado) ?>
      </span>
      <?php endif; ?>
    </h5>
    <?php if ($servicio_filtrado): ?>
    <a href="<?= BASE_URL ?>modules/ot/index.php" class="text-muted text-decoration-none" style="font-size:12px">
      <i data-feather="x" style="width:12px;height:12px"></i> Quitar filtro de servicio
    </a>
    <?php endif; ?>
  </div>
  <a href="<?= BASE_URL ?>modules/ot/nueva.php" class="btn btn-primary">
    <i data-feather="plus" style="width:16px;height:16px"></i> Nueva OT
  </a>
</div>

<!-- Filtros -->
<div class="tr-card mb-3">
  <div class="tr-card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-3">
        <input type="text" name="q" class="form-control form-control-sm"
               placeholder="Buscar código, cliente..." value="<?= sanitize($f_buscar) ?>"/>
      </div>
      <div class="col-md-2">
        <select name="estado" class="form-select form-select-sm">
          <option value="">Todos los estados</option>
          <?php foreach (ESTADOS_OT as $k => $v): ?>
          <option value="<?= $k ?>" <?= $f_estado===$k?'selected':'' ?>><?= $v['label'] ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <select name="servicio_id" class="form-select form-select-sm">
          <option value="">Todos los servicios</option>
          <?php foreach ($servicios_lista as $sv): ?>
          <option value="<?= $sv['id'] ?>" <?= $f_servicio_id==$sv['id']?'selected':'' ?>><?= sanitize($sv['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <select name="tecnico" class="form-select form-select-sm">
          <option value="">Todos los técnicos</option>
          <?php foreach ($tecnicos as $t): ?>
          <option value="<?= $t['id'] ?>" <?= $f_tecnico==$t['id']?'selected':'' ?>><?= sanitize($t['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-1">
        <input type="date" name="desde" class="form-control form-control-sm" value="<?= sanitize($f_desde) ?>"/>
      </div>
      <div class="col-md-1">
        <input type="date" name="hasta" class="form-control form-control-sm" value="<?= sanitize($f_hasta) ?>"/>
      </div>
      <div class="col-md-1">
        <button type="submit" class="btn btn-primary btn-sm w-100">Filtrar</button>
      </div>
    </form>
  </div>
</div>

<!-- Tabla -->
<div class="tr-card">
  <div class="tr-card-body p-0" style="overflow:hidden"><div class="table-responsive-wrapper" style="overflow-x:auto;-webkit-overflow-scrolling:touch">
    <table class="tr-table" id="tabla-ots">
      <thead>
        <tr>
          <th>OT</th>
          <th>Cliente</th>
          <th>Equipo</th>
          <th>Servicio</th>
          <th>Técnico</th>
          <th>Estado</th>
          <th>F. Estimada</th>
          <th>Total</th>
          <th>Ingreso</th>
          <th style="width:90px">Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($lista)): ?>
        <tr><td colspan="10" class="text-center text-muted py-4">No se encontraron órdenes</td></tr>
        <?php else: ?>
        <?php
        $cat_badge = [
          'mantenimiento' => 'bg-success',
          'diagnostico'   => 'bg-info',
          'instalacion'   => 'bg-warning text-dark',
          'reparacion'    => 'bg-danger',
        ];
        ?>
        <?php foreach ($lista as $ot): ?>
        <tr>
          <td>
            <a href="<?= BASE_URL ?>modules/ot/ver.php?id=<?= $ot['id'] ?>" class="fw-semibold text-primary text-decoration-none">
              <?= sanitize($ot['codigo_ot']) ?>
            </a>
            <div class="text-muted" style="font-size:10px"><?= sanitize($ot['codigo_publico']) ?></div>
          </td>
          <td>
            <?= sanitize($ot['cliente_nombre']) ?>
            <?php if ($ot['cliente_tel']): ?>
            <div class="text-muted" style="font-size:11px"><?= sanitize($ot['cliente_tel']) ?></div>
            <?php endif; ?>
          </td>
          <td class="small">
            <?= sanitize($ot['tipo_equipo']) ?>
            <?php if ($ot['marca'] || $ot['modelo']): ?>
            <div class="text-muted"><?= sanitize(trim($ot['marca'].' '.$ot['modelo'])) ?></div>
            <?php endif; ?>
          </td>
          <td class="small">
            <?php if ($ot['servicio_nombre']): ?>
            <?php $cb = $cat_badge[$ot['servicio_categoria'] ?? ''] ?? 'bg-secondary'; ?>
            <span class="badge <?= $cb ?>" style="font-size:10px;white-space:normal;text-align:left;line-height:1.3">
              <?= sanitize($ot['servicio_nombre']) ?>
            </span>
            <?php else: ?>
            <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td class="small"><?= sanitize($ot['tecnicos_nombres'] ?? '—') ?></td>
          <td><?= estadoOTBadge($ot['estado']) ?></td>
          <td class="small <?= ($ot['fecha_estimada'] && $ot['fecha_estimada'] < date('Y-m-d') && !(ESTADOS_OT[$ot['estado']]['es_final'] ?? false)) ? 'text-danger fw-semibold' : '' ?>">
            <?= $ot['fecha_estimada'] ? formatDate($ot['fecha_estimada']) : '—' ?>
          </td>
          <td class="fw-semibold"><?= $ot['precio_final'] > 0 ? formatMoney($ot['precio_final']) : '—' ?></td>
          <td class="text-muted small"><?= formatDate($ot['created_at']) ?></td>
          <td>
            <div class="btn-group btn-group-sm">
              <a href="<?= BASE_URL ?>modules/ot/ver.php?id=<?= $ot['id'] ?>"
                 class="btn btn-outline-primary" title="Ver detalle" data-bs-toggle="tooltip">
                <i data-feather="eye" style="width:13px;height:13px"></i>
              </a>
              <a href="<?= BASE_URL ?>modules/ot/editar.php?id=<?= $ot['id'] ?>"
                 class="btn btn-outline-secondary" title="Editar" data-bs-toggle="tooltip">
                <i data-feather="edit-2" style="width:13px;height:13px"></i>
              </a>
              <a href="<?= BASE_URL ?>modules/ot/pdf.php?id=<?= $ot['id'] ?>"
                 class="btn btn-outline-danger" title="PDF" target="_blank" data-bs-toggle="tooltip">
                <i data-feather="file-text" style="width:13px;height:13px"></i>
              </a>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
