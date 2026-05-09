<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';
requireLogin();
requireRole([ROL_ADMIN]);
$db   = getDB();
$user = currentUser();

// Toggle visibilidad del catálogo
if (isset($_GET['toggle']) && is_numeric($_GET['toggle'])) {
    $pid = (int)$_GET['toggle'];
    $db->prepare("UPDATE productos SET visible_catalogo = 1 - visible_catalogo WHERE id = ?")
       ->execute([$pid]);
    redirect(BASE_URL . 'modules/catalogo/index.php' . ($_SERVER['QUERY_STRING'] ? '?' . preg_replace('/&?toggle=\d+/','',$_SERVER['QUERY_STRING']) : ''));
}

// Toggle destacado
if (isset($_GET['destacar']) && is_numeric($_GET['destacar'])) {
    $pid = (int)$_GET['destacar'];
    $db->prepare("UPDATE productos SET destacado = 1 - destacado WHERE id = ?")
       ->execute([$pid]);
    redirect(BASE_URL . 'modules/catalogo/index.php');
}

// Filtros
$f_cat    = (int)($_GET['cat'] ?? 0);
$f_vis    = $_GET['vis']       ?? '';
$f_buscar = trim($_GET['q']    ?? '');

$where  = ['p.activo = 1'];
$params = [];
if ($f_cat)    { $where[] = 'p.categoria_id = ?'; $params[] = $f_cat; }
if ($f_vis === '1') { $where[] = 'p.visible_catalogo = 1'; }
if ($f_vis === '0') { $where[] = 'p.visible_catalogo = 0'; }
if ($f_buscar) { $where[] = 'p.nombre LIKE ?'; $params[] = '%'.$f_buscar.'%'; }

$productos = $db->prepare("
    SELECT p.*, c.nombre AS cat_nombre
    FROM productos p JOIN categorias c ON c.id = p.categoria_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY p.visible_catalogo DESC, p.nombre
    LIMIT 200
");
$productos->execute($params);
$productos = $productos->fetchAll();

$categorias = $db->query("SELECT * FROM categorias WHERE activo=1 ORDER BY nombre")->fetchAll();

// Totales
$stats = $db->query("SELECT
    COUNT(*) AS total,
    SUM(visible_catalogo) AS publicados,
    SUM(destacado) AS destacados
    FROM productos WHERE activo=1")->fetch();

$pageTitle  = 'Catálogo — ' . APP_NAME;
$breadcrumb = [['label'=>'Catálogo','url'=>null]];
require_once __DIR__ . '/../../includes/header.php';

// URL del catálogo público
$catalogoURL = BASE_URL . 'public/catalogo/';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h5 class="fw-bold mb-0">🛍 Gestión del catálogo público</h5>
  <div class="d-flex gap-2">
    <a href="<?= $catalogoURL ?>" target="_blank" class="btn btn-success btn-sm">
      <i data-feather="external-link" style="width:14px;height:14px"></i> Ver catálogo
    </a>
    <a href="<?= BASE_URL ?>modules/catalogo/banners.php" class="btn btn-outline-primary btn-sm">
      <i data-feather="image" style="width:14px;height:14px"></i> Banners
    </a>
    <a href="<?= BASE_URL ?>modules/catalogo/config.php" class="btn btn-outline-secondary btn-sm">
      <i data-feather="settings" style="width:14px;height:14px"></i> Configurar
    </a>
  </div>
</div>

<!-- URL del catálogo -->
<div class="alert alert-info d-flex align-items-center gap-3 mb-3 py-2">
  <i data-feather="link" style="width:16px;height:16px;flex-shrink:0"></i>
  <div class="small">
    URL pública del catálogo:
    <strong><a href="<?= $catalogoURL ?>" target="_blank"><?= $catalogoURL ?></a></strong>
    — Comparte este link con tus clientes
  </div>
  <button class="btn btn-sm btn-outline-primary ms-auto py-0"
          onclick="navigator.clipboard.writeText('<?= $catalogoURL ?>').then(()=>alert('¡Copiado!'))">
    Copiar
  </button>
</div>

<!-- KPIs -->
<div class="row g-3 mb-3">
  <div class="col-4">
    <div class="kpi-card text-center">
      <div class="kpi-value"><?= $stats['total'] ?></div>
      <div class="kpi-label">Productos totales</div>
    </div>
  </div>
  <div class="col-4">
    <div class="kpi-card text-center">
      <div class="kpi-value text-success"><?= $stats['publicados'] ?></div>
      <div class="kpi-label">Publicados en catálogo</div>
    </div>
  </div>
  <div class="col-4">
    <div class="kpi-card text-center">
      <div class="kpi-value text-warning"><?= $stats['destacados'] ?></div>
      <div class="kpi-label">Destacados</div>
    </div>
  </div>
</div>

<!-- Filtros -->
<div class="tr-card mb-3">
  <div class="tr-card-body py-2">
    <form method="GET" class="d-flex flex-wrap gap-2 align-items-end">
      <input type="text" name="q" class="form-control form-control-sm" style="max-width:200px"
             placeholder="Buscar producto..." value="<?= sanitize($f_buscar) ?>"/>
      <select name="cat" class="form-select form-select-sm" style="max-width:180px">
        <option value="">Todas las categorías</option>
        <?php foreach($categorias as $c): ?>
        <option value="<?= $c['id'] ?>" <?= $f_cat==$c['id']?'selected':'' ?>><?= sanitize($c['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="vis" class="form-select form-select-sm" style="max-width:160px">
        <option value="">Todos</option>
        <option value="1" <?= $f_vis==='1'?'selected':'' ?>>✅ Publicados</option>
        <option value="0" <?= $f_vis==='0'?'selected':'' ?>>⭕ Ocultos</option>
      </select>
      <button type="submit" class="btn btn-primary btn-sm">Filtrar</button>
      <a href="?" class="btn btn-outline-secondary btn-sm">Limpiar</a>
    </form>
  </div>
</div>

<!-- Tabla de productos -->
<div class="tr-card">
  <div class="tr-card-body p-0" style="overflow:hidden">
    <div style="overflow-x:auto">
      <table class="tr-table">
        <thead>
          <tr>
            <th style="width:50px">Foto</th>
            <th>Producto</th>
            <th>Categoría</th>
            <th>P. Venta</th>
            <th>P. Oferta</th>
            <th>Stock</th>
            <th>Catálogo</th>
            <th>Destacado</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($productos as $p):
            $fotos = json_decode($p['fotos_catalogo'] ?? '[]', true) ?: [];
            $img   = $fotos[0] ?? null;
          ?>
          <tr>
            <td>
              <?php if($img): ?>
              <img src="<?= UPLOAD_URL ?>catalogo/<?= htmlspecialchars($img) ?>"
                   style="width:40px;height:40px;object-fit:contain;border-radius:6px;border:1px solid #e5e7eb"
                   onerror="this.style.display='none'"/>
              <?php else: ?>
              <div style="width:40px;height:40px;background:#f3f4f6;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:18px">📦</div>
              <?php endif; ?>
            </td>
            <td>
              <div class="fw-semibold small"><?= sanitize($p['nombre']) ?></div>
              <div class="text-muted" style="font-size:11px"><code><?= sanitize($p['codigo']) ?></code></div>
            </td>
            <td class="small"><?= sanitize($p['cat_nombre']) ?></td>
            <td class="fw-semibold"><?= formatMoney($p['precio_venta']) ?></td>
            <td>
              <?php if($p['precio_oferta']): ?>
              <span class="text-danger fw-semibold"><?= formatMoney($p['precio_oferta']) ?></span>
              <?php else: ?>
              <span class="text-muted small">—</span>
              <?php endif; ?>
            </td>
            <td class="<?= $p['stock_actual']<=$p['stock_minimo']?'text-danger fw-bold':'' ?>">
              <?= number_format($p['stock_actual'],0) ?>
            </td>
            <td>
              <a href="?toggle=<?= $p['id'] ?>&cat=<?= $f_cat ?>&vis=<?= $f_vis ?>&q=<?= urlencode($f_buscar) ?>"
                 class="btn btn-sm <?= $p['visible_catalogo']?'btn-success':'btn-outline-secondary' ?> py-0"
                 style="font-size:11px;min-width:70px">
                <?= $p['visible_catalogo'] ? '✅ Público' : '⭕ Oculto' ?>
              </a>
            </td>
            <td>
              <a href="?destacar=<?= $p['id'] ?>"
                 class="btn btn-sm <?= $p['destacado']?'btn-warning':'btn-outline-secondary' ?> py-0"
                 style="font-size:11px">
                <?= $p['destacado'] ? '⭐' : '☆' ?>
              </a>
            </td>
            <td>
              <div class="btn-group btn-group-sm">
                <a href="<?= BASE_URL ?>modules/catalogo/editar_prod.php?id=<?= $p['id'] ?>"
                   class="btn btn-outline-primary" title="Editar datos del catálogo">
                  <i data-feather="edit-2" style="width:13px;height:13px"></i>
                </a>
                <a href="<?= BASE_URL ?>modules/inventario/editar.php?id=<?= $p['id'] ?>"
                   class="btn btn-outline-secondary" title="Editar producto">
                  <i data-feather="package" style="width:13px;height:13px"></i>
                </a>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if(empty($productos)): ?>
          <tr><td colspan="9" class="text-center text-muted py-4">Sin productos</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
