<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';
requireLogin();
requireRole([ROL_ADMIN, ROL_TECNICO]);
$db   = getDB();
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Generar código si no se proporciona
    $codigo = trim($_POST['codigo'] ?? '');
    if (!$codigo) {
        $n = $db->query("SELECT COUNT(*)+1 FROM productos")->fetchColumn();
        $codigo = 'PRD-' . str_pad($n, 5, '0', STR_PAD_LEFT);
    }
    $stockInicial = (float)($_POST['stock_inicial'] ?? 0);

    $db->prepare("INSERT INTO productos (codigo,nombre,descripcion,categoria_id,marca,modelo,serial,ubicacion,precio_costo,precio_venta,stock_actual,stock_minimo,stock_maximo,unidad) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
       ->execute([
           $codigo, trim($_POST['nombre']), trim($_POST['descripcion']??''),
           (int)$_POST['categoria_id'], trim($_POST['marca']??''), trim($_POST['modelo']??''),
           trim($_POST['serial']??''), trim($_POST['ubicacion']??''),
           (float)$_POST['precio_costo'], (float)$_POST['precio_venta'],
           $stockInicial, (float)$_POST['stock_minimo'],
           (float)($_POST['stock_maximo']??100), trim($_POST['unidad']??'unidad'),
       ]);
    $prodId = $db->lastInsertId();

    // Kardex entrada inicial
    if ($stockInicial > 0) {
        $db->prepare("INSERT INTO kardex (producto_id,tipo,cantidad,stock_antes,stock_despues,precio_unit,motivo,referencia,usuario_id) VALUES (?,?,?,?,?,?,?,?,?)")
           ->execute([$prodId,'entrada',$stockInicial,0,$stockInicial,(float)$_POST['precio_costo'],'Stock inicial','INICIO',$user['id']]);
    }

    setFlash('success','Producto creado con código '.$codigo);
    redirect(BASE_URL.'modules/inventario/index.php');
}

$categorias = $db->query("SELECT * FROM categorias WHERE activo=1 ORDER BY tipo,nombre")->fetchAll();
$servicios  = $db->query("SELECT id, nombre, precio FROM servicios WHERE activo=1 ORDER BY nombre")->fetchAll();

// Cargar tipos dinámicos para el modal de nueva categoría
try {
    $tiposParaModal = $db->query("SELECT nombre FROM categorias_tipos WHERE activo=1 ORDER BY orden,nombre")->fetchAll(PDO::FETCH_COLUMN);
} catch(\Exception $e) {
    $tiposParaModal = ['repuesto','hardware','ofimatica','accesorio','software','servicio','otro'];
}

// API: crear categoría inline (llamada AJAX)
if (isset($_POST['action']) && $_POST['action']==='nueva_categoria') {
    header('Content-Type: application/json');
    $nom = trim($_POST['nombre'] ?? '');
    $tip = $_POST['tipo'] ?? 'repuesto';
    if (!$nom) { echo json_encode(['ok'=>false,'msg'=>'Nombre requerido']); exit; }
    $db->prepare("INSERT INTO categorias (nombre,tipo) VALUES (?,?)")->execute([$nom,$tip]);
    $newId = $db->lastInsertId();
    echo json_encode(['ok'=>true,'id'=>$newId,'nombre'=>$nom,'tipo'=>$tip]);
    exit;
}

$pageTitle  = 'Nuevo producto — '.APP_NAME;
$breadcrumb = [['label'=>'Inventario','url'=>BASE_URL.'modules/inventario/index.php'],['label'=>'Nuevo','url'=>null]];
require_once __DIR__ . '/../../includes/header.php';
?>
<h5 class="fw-bold mb-4">Nuevo producto / repuesto</h5>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="tr-card">
      <div class="tr-card-body">
        <form method="POST">
          <div class="row g-3">
            <div class="col-md-4"><label class="tr-form-label">Código (autogenera si vacío)</label><input type="text" name="codigo" class="form-control" placeholder="PRD-00001"/></div>
            <div class="col-md-8">
              <label class="tr-form-label">Nombre *</label>
              <div class="input-group">
                <input type="text" name="nombre" id="inp-nombre" class="form-control" required autofocus
                       placeholder="Nombre del producto o repuesto"/>
                <button type="button" class="btn btn-outline-info btn-sm" style="white-space:nowrap"
                        data-bs-toggle="modal" data-bs-target="#modal-servicio">
                  <i data-feather="download" style="width:13px;height:13px"></i> Desde servicio
                </button>
              </div>
            </div>
            <div class="col-md-6">
              <label class="tr-form-label">Categoría *</label>
              <div class="input-group">
                <select name="categoria_id" id="sel-categoria" class="form-select" required>
                  <option value="">— Seleccionar —</option>
                  <?php $lastTipo=''; foreach($categorias as $cat):
                    if($cat['tipo']!==$lastTipo){ echo '<option disabled>─ '.strtoupper($cat['tipo']).' ─</option>'; $lastTipo=$cat['tipo']; }
                  ?>
                  <option value="<?= $cat['id'] ?>"><?= sanitize($cat['nombre']) ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-outline-success" title="Nueva categoría"
                        data-bs-toggle="modal" data-bs-target="#modal-cat-nueva">
                  <i data-feather="plus" style="width:14px;height:14px"></i>
                </button>
                <a href="<?= BASE_URL ?>modules/categorias/index.php" target="_blank"
                   class="btn btn-outline-secondary" title="Gestionar categorías">
                  <i data-feather="settings" style="width:14px;height:14px"></i>
                </a>
              </div>
            </div>
            <div class="col-md-3"><label class="tr-form-label">Marca</label><input type="text" name="marca" class="form-control"/></div>
            <div class="col-md-3"><label class="tr-form-label">Modelo</label><input type="text" name="modelo" class="form-control"/></div>
            <div class="col-md-4"><label class="tr-form-label">Serial / Número de serie</label><input type="text" name="serial" class="form-control" placeholder="Importante para hardware"/></div>
            <div class="col-md-4"><label class="tr-form-label">Ubicación en almacén</label><input type="text" name="ubicacion" class="form-control" placeholder="Estante A / Fila 2"/></div>
            <div class="col-md-4"><label class="tr-form-label">Unidad</label>
              <select name="unidad" class="form-select">
                <option value="unidad">Unidad</option>
                <option value="par">Par</option>
                <option value="kit">Kit</option>
                <option value="metro">Metro</option>
                <option value="gramo">Gramo</option>
              </select>
            </div>
            <div class="col-12"><label class="tr-form-label">Descripción</label><textarea name="descripcion" class="form-control" rows="2"></textarea></div>
            <div class="col-md-4"><label class="tr-form-label">Precio costo (S/) *</label><input type="number" name="precio_costo" class="form-control currency-input" step="0.01" required value="0"/></div>
            <div class="col-md-4"><label class="tr-form-label">Precio venta (S/) *</label><input type="number" name="precio_venta" class="form-control currency-input" step="0.01" required value="0"/></div>
            <div class="col-md-4">
              <label class="tr-form-label">Margen</label>
              <div class="form-control bg-light" id="txt-margen">0%</div>
            </div>
            <div class="col-md-4"><label class="tr-form-label">Stock inicial</label><input type="number" name="stock_inicial" class="form-control" step="0.01" value="0" min="0"/></div>
            <div class="col-md-4"><label class="tr-form-label">Stock mínimo *</label><input type="number" name="stock_minimo" class="form-control" step="0.01" value="1" min="0" required/></div>
            <div class="col-md-4"><label class="tr-form-label">Stock máximo</label><input type="number" name="stock_maximo" class="form-control" step="0.01" value="100"/></div>
            <div class="col-12 d-flex gap-2">
              <button type="submit" class="btn btn-primary">Guardar producto</button>
              <a href="<?= BASE_URL ?>modules/inventario/index.php" class="btn btn-outline-secondary">Cancelar</a>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<!-- Modal: Nueva categoría inline -->
<div class="modal fade" id="modal-cat-nueva" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title fw-bold">Nueva categoría</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="tr-form-label">Nombre *</label>
          <input type="text" id="cat-nueva-nombre" class="form-control" placeholder="Ej: Baterías"/>
        </div>
        <div class="mb-3">
          <label class="tr-form-label">Tipo</label>
          <select id="cat-nueva-tipo" class="form-select">
            <?php foreach($tiposParaModal as $tp): ?>
            <option value="<?= htmlspecialchars($tp) ?>"><?= ucfirst(htmlspecialchars($tp)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div id="cat-nueva-msg" class="small text-danger" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-success btn-sm" id="btn-crear-cat">
          Crear y seleccionar
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Importar desde servicio -->
<div class="modal fade" id="modal-servicio" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title fw-bold">Importar desde Servicio</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small mb-2">Selecciona un servicio para cargar su nombre y precio.</p>
        <input type="text" id="buscar-servicio" class="form-control form-control-sm mb-2"
               placeholder="Buscar servicio..."/>
        <div style="max-height:320px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:8px">
          <?php foreach($servicios as $sv): ?>
          <div class="svc-item d-flex justify-content-between align-items-center px-3 py-2"
               style="cursor:pointer;border-bottom:1px solid #f3f4f6;transition:background .1s"
               data-nombre="<?= htmlspecialchars($sv['nombre']) ?>"
               data-precio="<?= number_format((float)$sv['precio'], 2, '.', '') ?>">
            <span class="fw-semibold small"><?= sanitize($sv['nombre']) ?></span>
            <span class="text-success fw-bold ms-3 flex-shrink-0">S/ <?= number_format($sv['precio'],2) ?></span>
          </div>
          <?php endforeach; ?>
          <?php if(empty($servicios)): ?>
          <div class="text-muted text-center py-4 small">Sin servicios disponibles</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<?php
$pageScripts = <<<'JS'
<script>
// ── Margen ───────────────────────────────────────────────
function calcMargen() {
  const costo = parseFloat(document.querySelector('[name=precio_costo]').value)||0;
  const venta = parseFloat(document.querySelector('[name=precio_venta]').value)||0;
  const m = costo>0 ? ((venta-costo)/costo*100).toFixed(1) : 0;
  const color = m>=20?'text-success':(m>=0?'text-warning':'text-danger');
  document.getElementById('txt-margen').innerHTML = `<span class="${color} fw-bold">${m}%</span>`;
}
document.querySelector('[name=precio_costo]').addEventListener('input',calcMargen);
document.querySelector('[name=precio_venta]').addEventListener('input',calcMargen);

// ── Importar desde servicio ──────────────────────────────
document.addEventListener('DOMContentLoaded', function() {

  // Click en item de servicio
  document.querySelectorAll('.svc-item').forEach(function(item) {
    item.addEventListener('mouseenter', function(){ this.style.background='#f0fdf4'; });
    item.addEventListener('mouseleave', function(){ this.style.background=''; });
    item.addEventListener('click', function() {
      var nombre = this.dataset.nombre;
      var precio = this.dataset.precio;
      // Llenar nombre
      var inpNombre = document.getElementById('inp-nombre');
      if (inpNombre) inpNombre.value = nombre;
      // Llenar precio venta
      var inpPrecio = document.querySelector('[name="precio_venta"]');
      if (inpPrecio) { inpPrecio.value = precio; calcMargen(); }
      // Cerrar modal
      var modalEl = document.getElementById('modal-servicio');
      var modal = bootstrap.Modal.getInstance(modalEl);
      if (modal) modal.hide(); else new bootstrap.Modal(modalEl).hide();
    });
  });

  // Filtro búsqueda servicios
  var buscarSvc = document.getElementById('buscar-servicio');
  if (buscarSvc) {
    buscarSvc.addEventListener('input', function() {
      var q = this.value.toLowerCase();
      document.querySelectorAll('.svc-item').forEach(function(item) {
        item.style.display = item.dataset.nombre.toLowerCase().includes(q) ? '' : 'none';
      });
    });
  }

  // ── Crear categoría inline ─────────────────────────────
  var btnCrearCat = document.getElementById('btn-crear-cat');
  if (btnCrearCat) {
    btnCrearCat.addEventListener('click', function() {
      var nombre = document.getElementById('cat-nueva-nombre').value.trim();
      var tipo   = document.getElementById('cat-nueva-tipo').value;
      var msgEl  = document.getElementById('cat-nueva-msg');
      if (!nombre) { msgEl.textContent='Ingresa un nombre'; msgEl.style.display=''; return; }
      msgEl.style.display = 'none';
      btnCrearCat.disabled = true;
      btnCrearCat.textContent = 'Creando...';

      var fd = new FormData();
      fd.append('action', 'nueva_categoria');
      fd.append('nombre', nombre);
      fd.append('tipo',   tipo);

      fetch(window.BASE_URL + 'modules/inventario/nuevo.php', {method:'POST', body:fd})
        .then(function(r){ return r.json(); })
        .then(function(data) {
          btnCrearCat.disabled = false;
          btnCrearCat.textContent = 'Crear y seleccionar';
          if (!data.ok) { msgEl.textContent = data.msg || 'Error'; msgEl.style.display=''; return; }
          var sel = document.getElementById('sel-categoria');
          var opt = document.createElement('option');
          opt.value = data.id;
          opt.textContent = data.nombre;
          opt.selected = true;
          sel.appendChild(opt);
          document.getElementById('cat-nueva-nombre').value = '';
          // Cerrar modal
          var modalEl = document.getElementById('modal-cat-nueva');
          var modal = bootstrap.Modal.getInstance(modalEl);
          if (modal) modal.hide(); else bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl).hide();
          // Highlight select
          sel.style.borderColor = '#22c55e';
          setTimeout(function(){ sel.style.borderColor=''; }, 2000);
        })
        .catch(function(e) {
          btnCrearCat.disabled = false;
          btnCrearCat.textContent = 'Crear y seleccionar';
          msgEl.textContent = 'Error de conexión';
          msgEl.style.display = '';
        });
    });
  }

}); // fin DOMContentLoaded
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php'; ?>
