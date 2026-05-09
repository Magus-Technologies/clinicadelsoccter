<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';
requireLogin();
requireRole([ROL_ADMIN, ROL_TECNICO]);

$db   = getDB();
$user = currentUser();

$accion = $_GET['accion'] ?? 'listar';
$id     = (int)($_GET['id'] ?? 0);

// ── ELIMINAR ─────────────────────────────────────────────
if ($accion === 'eliminar' && $id && $user['rol'] === ROL_ADMIN) {
    $db->prepare("DELETE FROM servicios WHERE id=?")->execute([$id]);
    setFlash('success', 'Servicio eliminado.');
    redirect(BASE_URL . 'modules/servicios/index.php');
}

// ── GUARDAR (crear / editar) ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $datos = [
        trim($_POST['nombre']           ?? ''),
        trim($_POST['descripcion']      ?? ''),
        $_POST['categoria']             ?? 'reparacion',
        (float)($_POST['precio']        ?? 0),
        $_POST['precio_minimo'] !== '' ? (float)$_POST['precio_minimo'] : null,
        $_POST['duracion_estimada'] !== '' ? (int)$_POST['duracion_estimada'] : null,
        (int)($_POST['garantia_dias']   ?? 30),
        isset($_POST['requiere_repuestos']) ? 1 : 0,
        isset($_POST['activo'])          ? 1 : 0,
        trim($_POST['notas']            ?? ''),
    ];

    if ($id) {
        $datos[] = $id;
        $db->prepare("UPDATE servicios SET nombre=?,descripcion=?,categoria=?,precio=?,precio_minimo=?,duracion_estimada=?,garantia_dias=?,requiere_repuestos=?,activo=?,notas=? WHERE id=?")
           ->execute($datos);
        setFlash('success', 'Servicio actualizado.');
    } else {
        $db->prepare("INSERT INTO servicios (nombre,descripcion,categoria,precio,precio_minimo,duracion_estimada,garantia_dias,requiere_repuestos,activo,notas) VALUES (?,?,?,?,?,?,?,?,?,?)")
           ->execute($datos);
        $id = $db->lastInsertId();
        setFlash('success', 'Servicio creado.');
    }

    // Guardar repuestos precargados
    $db->prepare("DELETE FROM servicio_repuestos WHERE servicio_id=?")->execute([$id]);
    $srProds    = $_POST['sr_producto_id']  ?? [];
    $srCants    = $_POST['sr_cantidad']     ?? [];
    $srPrecios  = $_POST['sr_precio']       ?? [];
    $srNombres  = $_POST['sr_nombre']       ?? [];
    foreach ($srProds as $i => $pid) {
        $pid = (int)$pid;
        if (!$pid) continue;
        $cant  = (float)($srCants[$i]   ?? 1);
        $precio= (float)($srPrecios[$i] ?? 0);
        $db->prepare("INSERT INTO servicio_repuestos (servicio_id,producto_id,cantidad,precio_referencial) VALUES (?,?,?,?)")
           ->execute([$id, $pid, $cant, $precio]);
    }

    redirect(BASE_URL . 'modules/servicios/index.php');
}

// ── CARGAR para editar ────────────────────────────────────
$servicio = null;
$servicioRepuestos = [];
if (($accion === 'editar' || $accion === 'nuevo') && $id) {
    $stmt = $db->prepare("SELECT * FROM servicios WHERE id=?");
    $stmt->execute([$id]);
    $servicio = $stmt->fetch();
    $stmt2 = $db->prepare("SELECT sr.*, p.nombre as prod_nombre, p.codigo as prod_codigo FROM servicio_repuestos sr JOIN productos p ON p.id=sr.producto_id WHERE sr.servicio_id=?");
    $stmt2->execute([$id]);
    $servicioRepuestos = $stmt2->fetchAll();
}

// Productos para el buscador de repuestos
$productos = $db->query("SELECT id, codigo, nombre, precio_venta FROM productos WHERE activo=1 ORDER BY nombre")->fetchAll();

// ── LISTAR ────────────────────────────────────────────────
$filtroActivo = $_GET['activo'] ?? '';
$filtroCateg  = $_GET['categoria'] ?? '';
$busqueda     = trim($_GET['q'] ?? '');

$where  = ['1=1'];
$params = [];
if ($filtroActivo !== '') { $where[] = 's.activo=?'; $params[] = (int)$filtroActivo; }
if ($filtroCateg  !== '') { $where[] = 's.categoria=?'; $params[] = $filtroCateg; }
if ($busqueda     !== '') { $where[] = '(s.nombre LIKE ? OR s.descripcion LIKE ?)'; $params[] = "%$busqueda%"; $params[] = "%$busqueda%"; }

$stmt = $db->prepare("SELECT * FROM servicios s WHERE " . implode(' AND ', $where) . " ORDER BY s.nombre");
$stmt->execute($params);
$servicios = $stmt->fetchAll();

$categorias = [
    'diagnostico'  => 'Diagnóstico',
    'reparacion'   => 'Reparación',
    'mantenimiento'=> 'Mantenimiento',
    'instalacion'  => 'Instalación',
    'otro'         => 'Otro',
];

$pageTitle  = 'Servicios — ' . APP_NAME;
$breadcrumb = [['label' => 'Servicios', 'url' => null]];
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <h5 class="fw-bold mb-0">Servicios</h5>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modal-servicio"
          onclick="abrirModalNuevo()">
    <i data-feather="plus" style="width:15px;height:15px"></i> Nuevo servicio
  </button>
</div>

<!-- Filtros -->
<div class="tr-card mb-3">
  <div class="tr-card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-4">
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Buscar por nombre..." value="<?= sanitize($busqueda) ?>"/>
      </div>
      <div class="col-md-3">
        <select name="categoria" class="form-select form-select-sm">
          <option value="">Todas las categorías</option>
          <?php foreach ($categorias as $k => $v): ?>
          <option value="<?= $k ?>" <?= $filtroCateg===$k?'selected':'' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <select name="activo" class="form-select form-select-sm">
          <option value="">Todos</option>
          <option value="1" <?= $filtroActivo==='1'?'selected':'' ?>>Activos</option>
          <option value="0" <?= $filtroActivo==='0'?'selected':'' ?>>Inactivos</option>
        </select>
      </div>
      <div class="col-md-3 d-flex gap-2">
        <button type="submit" class="btn btn-primary btn-sm">Filtrar</button>
        <a href="<?= BASE_URL ?>modules/servicios/index.php" class="btn btn-outline-secondary btn-sm">Limpiar</a>
      </div>
    </form>
  </div>
</div>

<!-- Tabla -->
<div class="tr-card">
  <div class="tr-card-body p-0">
    <table class="tr-table">
      <thead>
        <tr>
          <th>Nombre</th>
          <th>Categoría</th>
          <th>Precio</th>
          <th>Duración</th>
          <th>Garantía</th>
          <th>Repuestos</th>
          <th>Estado</th>
          <th style="width:100px">Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$servicios): ?>
        <tr><td colspan="8" class="text-center text-muted py-4">No hay servicios registrados.</td></tr>
        <?php endif; ?>
        <?php foreach ($servicios as $s): ?>
        <tr>
          <td>
            <div class="fw-semibold"><?= sanitize($s['nombre']) ?></div>
            <?php if ($s['descripcion']): ?>
            <div class="text-muted small text-truncate" style="max-width:220px"><?= sanitize($s['descripcion']) ?></div>
            <?php endif; ?>
          </td>
          <td><span class="badge bg-secondary"><?= sanitize($categorias[$s['categoria']] ?? $s['categoria']) ?></span></td>
          <td>
            <?= formatMoney($s['precio']) ?>
            <?php if ($s['precio_minimo']): ?>
            <div class="text-muted small">Mín: <?= formatMoney($s['precio_minimo']) ?></div>
            <?php endif; ?>
          </td>
          <td><?= $s['duracion_estimada'] ? $s['duracion_estimada'] . ' min' : '—' ?></td>
          <td><?= $s['garantia_dias'] ?> días</td>
          <td><?= $s['requiere_repuestos'] ? '<span class="badge bg-warning text-dark">Sí</span>' : '<span class="badge bg-light text-muted border">No</span>' ?></td>
          <td><?= $s['activo'] ? '<span class="badge bg-success">Activo</span>' : '<span class="badge bg-danger">Inactivo</span>' ?></td>
          <td>
            <button class="btn btn-outline-primary btn-sm py-0 px-1 me-1"
                    onclick='abrirModalEditar(<?= json_encode($s) ?>)'
                    title="Editar">
              <i data-feather="edit-2" style="width:13px;height:13px"></i>
            </button>
            <?php if ($user['rol'] === ROL_ADMIN): ?>
            <a href="<?= BASE_URL ?>modules/servicios/index.php?accion=eliminar&id=<?= $s['id'] ?>"
               class="btn btn-outline-danger btn-sm py-0 px-1"
               onclick="return confirm('¿Eliminar este servicio?')"
               title="Eliminar">
              <i data-feather="trash-2" style="width:13px;height:13px"></i>
            </a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal crear/editar -->
<div class="modal fade" id="modal-servicio" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST" id="form-servicio">
        <input type="hidden" name="id" id="modal-id" value=""/>
        <div class="modal-header py-2">
          <h6 class="modal-title" id="modal-titulo">Nuevo servicio</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">

            <div class="col-md-8">
              <label class="tr-form-label">Nombre *</label>
              <input type="text" name="nombre" id="m-nombre" class="form-control" required placeholder="Ej: Cambio de pantalla, Formateo..."/>
            </div>

            <div class="col-md-4">
              <label class="tr-form-label">Categoría *</label>
              <select name="categoria" id="m-categoria" class="form-select">
                <?php foreach ($categorias as $k => $v): ?>
                <option value="<?= $k ?>"><?= $v ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12">
              <label class="tr-form-label">Descripción</label>
              <textarea name="descripcion" id="m-descripcion" class="form-control" rows="2" placeholder="Descripción breve del servicio..."></textarea>
            </div>

            <div class="col-md-4">
              <label class="tr-form-label">Precio base (S/) *</label>
              <input type="number" name="precio" id="m-precio" class="form-control" step="0.01" min="0" value="0" required/>
            </div>

            <div class="col-md-4">
              <label class="tr-form-label">Precio mínimo (S/)</label>
              <input type="number" name="precio_minimo" id="m-precio-minimo" class="form-control" step="0.01" min="0" placeholder="Opcional"/>
            </div>

            <div class="col-md-4">
              <label class="tr-form-label">Duración estimada (min)</label>
              <input type="number" name="duracion_estimada" id="m-duracion" class="form-control" min="0" placeholder="Ej: 60"/>
            </div>

            <div class="col-md-4">
              <label class="tr-form-label">Garantía (días)</label>
              <input type="number" name="garantia_dias" id="m-garantia" class="form-control" min="0" value="30"/>
            </div>

            <div class="col-md-4 d-flex align-items-end gap-4 pb-1">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="requiere_repuestos" id="m-repuestos" value="1" onchange="toggleRepuestos(this.checked)">
                <label class="form-check-label small" for="m-repuestos">Requiere repuestos</label>
              </div>
            </div>

            <div class="col-md-4 d-flex align-items-end gap-4 pb-1">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="activo" id="m-activo" value="1" checked>
                <label class="form-check-label small" for="m-activo">Activo</label>
              </div>
            </div>

            <div class="col-12">
              <label class="tr-form-label">Notas internas</label>
              <textarea name="notas" id="m-notas" class="form-control" rows="2" placeholder="Notas para el equipo técnico..."></textarea>
            </div>

            <!-- Repuestos precargados -->
            <div class="col-12" id="bloque-repuestos-servicio" style="display:none">
              <label class="tr-form-label">Repuestos precargados</label>
              <div class="input-group input-group-sm mb-2">
                <input type="text" id="buscar-producto-sr" class="form-control" placeholder="Buscar producto por nombre o código..." autocomplete="off"/>
                <div id="dropdown-productos-sr" class="list-group position-absolute shadow-sm" style="display:none;z-index:1060;width:100%;top:100%;left:0"></div>
              </div>
              <table class="table table-sm table-bordered mb-0" id="tabla-sr">
                <thead class="table-light"><tr><th>Producto</th><th style="width:80px">Cant.</th><th style="width:100px">Precio ref. (S/)</th><th style="width:36px"></th></tr></thead>
                <tbody id="tbody-sr"></tbody>
              </table>
              <div class="text-muted small mt-1">Estos repuestos se precargarán automáticamente al seleccionar este servicio en una OT.</div>
            </div>

          </div>
        </div>
        <div class="modal-footer py-2">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary btn-sm">
            <i data-feather="save" style="width:13px;height:13px"></i> Guardar
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// Productos disponibles para el buscador
const _productos = <?= json_encode($productos, JSON_UNESCAPED_UNICODE) ?>;

function toggleRepuestos(checked) {
  document.getElementById('bloque-repuestos-servicio').style.display = checked ? '' : 'none';
}

function abrirModalNuevo() {
  document.getElementById('modal-titulo').textContent = 'Nuevo servicio';
  document.getElementById('form-servicio').reset();
  document.getElementById('modal-id').value = '';
  document.getElementById('m-activo').checked = true;
  document.getElementById('m-repuestos').checked = false;
  document.getElementById('bloque-repuestos-servicio').style.display = 'none';
  document.getElementById('tbody-sr').innerHTML = '';
}

function abrirModalEditar(s) {
  document.getElementById('modal-titulo').textContent = 'Editar servicio';
  document.getElementById('modal-id').value          = s.id;
  document.getElementById('m-nombre').value          = s.nombre;
  document.getElementById('m-categoria').value       = s.categoria;
  document.getElementById('m-descripcion').value     = s.descripcion || '';
  document.getElementById('m-precio').value          = s.precio;
  document.getElementById('m-precio-minimo').value   = s.precio_minimo || '';
  document.getElementById('m-duracion').value        = s.duracion_estimada || '';
  document.getElementById('m-garantia').value        = s.garantia_dias;
  document.getElementById('m-repuestos').checked     = s.requiere_repuestos == 1;
  document.getElementById('m-activo').checked        = s.activo == 1;
  document.getElementById('m-notas').value           = s.notas || '';
  document.getElementById('bloque-repuestos-servicio').style.display = s.requiere_repuestos == 1 ? '' : 'none';

  // Cargar repuestos vía AJAX
  const tbody = document.getElementById('tbody-sr');
  tbody.innerHTML = '';
  fetch('api_servicio.php?id=' + s.id)
    .then(r => r.json())
    .then(data => {
      if (data.ok && data.repuestos && data.repuestos.length > 0) {
        data.repuestos.forEach(r => agregarFilaSR(r.producto_id, r.nombre + (r.codigo ? ' [' + r.codigo + ']' : ''), r.cantidad, r.precio_referencial));
      }
    })
    .catch(() => {});

  new bootstrap.Modal(document.getElementById('modal-servicio')).show();
}

// ── Buscador de productos para repuestos del servicio ────
(function() {
  const input    = document.getElementById('buscar-producto-sr');
  const dropdown = document.getElementById('dropdown-productos-sr');
  if (!input) return;

  input.addEventListener('input', function() {
    const q = this.value.toLowerCase().trim();
    dropdown.innerHTML = '';
    if (q.length < 1) { dropdown.style.display = 'none'; return; }
    const res = _productos.filter(p => p.nombre.toLowerCase().includes(q) || p.codigo.toLowerCase().includes(q)).slice(0, 20);
    if (!res.length) { dropdown.style.display = 'none'; return; }
    res.forEach(p => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'list-group-item list-group-item-action py-1 px-2 small';
      btn.textContent = p.nombre + ' [' + p.codigo + '] — S/ ' + parseFloat(p.precio_venta).toFixed(2);
      btn.addEventListener('mousedown', function(e) {
        e.preventDefault();
        agregarFilaSR(p.id, p.nombre + ' [' + p.codigo + ']', 1, p.precio_venta);
        input.value = '';
        dropdown.style.display = 'none';
      });
      dropdown.appendChild(btn);
    });
    dropdown.style.display = '';
  });

  document.addEventListener('click', function(e) {
    if (!input.contains(e.target) && !dropdown.contains(e.target)) dropdown.style.display = 'none';
  });
})();

function agregarFilaSR(productoId, nombre, cantidad, precio) {
  const tbody = document.getElementById('tbody-sr');
  const tr = document.createElement('tr');
  tr.innerHTML = `
    <td>
      <span class="small">${escHtml(nombre)}</span>
      <input type="hidden" name="sr_producto_id[]" value="${productoId}"/>
      <input type="hidden" name="sr_nombre[]" value="${escHtml(nombre)}"/>
    </td>
    <td><input type="number" name="sr_cantidad[]" class="form-control form-control-sm text-center" value="${cantidad}" min="0.01" step="0.01"/></td>
    <td><input type="number" name="sr_precio[]" class="form-control form-control-sm text-end" value="${parseFloat(precio).toFixed(2)}" min="0" step="0.01"/></td>
    <td><button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" onclick="this.closest('tr').remove()">✕</button></td>`;
  tbody.appendChild(tr);
}

function escHtml(s) {
  return (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
