<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';
requireLogin();
requireRole([ROL_ADMIN]);
$db = getDB();

$TIPOS = ['repuesto','hardware','ofimatica','accesorio','software','servicio','otro'];

// CREAR
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='crear') {
    $db->prepare("INSERT INTO categorias (nombre,tipo,descripcion) VALUES (?,?,?)")
       ->execute([trim($_POST['nombre']), $_POST['tipo'], trim($_POST['descripcion']??'')]);
    setFlash('success','Categoría creada.');
    redirect(BASE_URL.'modules/categorias/index.php');
}

// EDITAR
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='editar') {
    $db->prepare("UPDATE categorias SET nombre=?,tipo=?,descripcion=?,activo=? WHERE id=?")
       ->execute([trim($_POST['nombre']), $_POST['tipo'], trim($_POST['descripcion']??''),
                  isset($_POST['activo'])?1:0, (int)$_POST['id']]);
    setFlash('success','Categoría actualizada.');
    redirect(BASE_URL.'modules/categorias/index.php');
}

// ELIMINAR
if (isset($_GET['del'])) {
    $id = (int)$_GET['del'];
    $uso = $db->prepare("SELECT COUNT(*) FROM productos WHERE categoria_id=?"); $uso->execute([$id]);
    if ($uso->fetchColumn() > 0) {
        setFlash('danger','No se puede eliminar: tiene productos asociados.');
    } else {
        $db->prepare("DELETE FROM categorias WHERE id=?")->execute([$id]);
        setFlash('success','Categoría eliminada.');
    }
    redirect(BASE_URL.'modules/categorias/index.php');
}

// Toggle activo
if (isset($_GET['toggle'])) {
    $db->prepare("UPDATE categorias SET activo=1-activo WHERE id=?")->execute([(int)$_GET['toggle']]);
    redirect(BASE_URL.'modules/categorias/index.php');
}

$categorias = $db->query("
    SELECT c.*, COUNT(p.id) AS total_productos
    FROM categorias c
    LEFT JOIN productos p ON p.categoria_id=c.id
    GROUP BY c.id
    ORDER BY c.tipo, c.nombre")->fetchAll();

$pageTitle  = 'Categorías — '.APP_NAME;
$breadcrumb = [['label'=>'Inventario','url'=>BASE_URL.'modules/inventario/index.php'],['label'=>'Categorías','url'=>null]];
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h5 class="fw-bold mb-0">🏷 Categorías de productos</h5>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modal-nueva">
    <i data-feather="plus" style="width:14px;height:14px"></i> Nueva categoría
  </button>
</div>

<div class="tr-card">
  <div class="tr-card-body p-0" style="overflow:hidden">
    <table class="tr-table">
      <thead>
        <tr><th>Nombre</th><th>Tipo</th><th>Descripción</th><th>Productos</th><th>Estado</th><th></th></tr>
      </thead>
      <tbody>
      <?php $lastTipo=''; foreach($categorias as $c): ?>
      <?php if($c['tipo']!==$lastTipo): $lastTipo=$c['tipo']; ?>
      <tr style="background:#f8fafc">
        <td colspan="6" class="fw-bold small text-uppercase text-muted py-1 px-4">
          <?= htmlspecialchars($c['tipo']) ?>
        </td>
      </tr>
      <?php endif; ?>
      <tr>
        <td class="fw-semibold"><?= sanitize($c['nombre']) ?></td>
        <td><span class="badge bg-secondary"><?= $c['tipo'] ?></span></td>
        <td class="small text-muted"><?= sanitize(mb_strimwidth($c['descripcion']??'',0,60,'…')) ?></td>
        <td class="text-center">
          <span class="badge bg-<?= $c['total_productos']>0?'primary':'light text-dark border' ?>">
            <?= $c['total_productos'] ?>
          </span>
        </td>
        <td>
          <a href="?toggle=<?= $c['id'] ?>" class="badge bg-<?= $c['activo']?'success':'secondary' ?> text-decoration-none">
            <?= $c['activo']?'Activa':'Inactiva' ?>
          </a>
        </td>
        <td>
          <div class="btn-group btn-group-sm">
            <button class="btn btn-outline-primary"
                    onclick="abrirEditar(<?= htmlspecialchars(json_encode($c),ENT_QUOTES) ?>)">
              <i data-feather="edit-2" style="width:13px;height:13px"></i>
            </button>
            <a href="?del=<?= $c['id'] ?>" class="btn btn-outline-danger"
               data-confirm="¿Eliminar '<?= sanitize($c['nombre']) ?>'? Solo si no tiene productos.">
              <i data-feather="trash-2" style="width:13px;height:13px"></i>
            </a>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if(empty($categorias)): ?>
      <tr><td colspan="6" class="text-center text-muted py-4">Sin categorías</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal Nueva -->
<div class="modal fade" id="modal-nueva" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" value="crear"/>
        <div class="modal-header">
          <h6 class="modal-title fw-bold">Nueva categoría</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="tr-form-label">Nombre *</label>
            <input type="text" name="nombre" class="form-control" required placeholder="Ej: Baterías de litio"/>
          </div>
          <div class="mb-3">
            <label class="tr-form-label">Tipo *</label>
            <select name="tipo" class="form-select" required>
              <?php foreach($TIPOS as $t): ?>
              <option value="<?= $t ?>"><?= ucfirst($t) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="tr-form-label">Descripción</label>
            <textarea name="descripcion" class="form-control" rows="2"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary btn-sm">Crear</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal Editar -->
<div class="modal fade" id="modal-editar" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" value="editar"/>
        <input type="hidden" name="id" id="edit-id"/>
        <div class="modal-header">
          <h6 class="modal-title fw-bold">Editar categoría</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="tr-form-label">Nombre *</label>
            <input type="text" name="nombre" id="edit-nombre" class="form-control" required/>
          </div>
          <div class="mb-3">
            <label class="tr-form-label">Tipo *</label>
            <select name="tipo" id="edit-tipo" class="form-select">
              <?php foreach($TIPOS as $t): ?>
              <option value="<?= $t ?>"><?= ucfirst($t) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="tr-form-label">Descripción</label>
            <textarea name="descripcion" id="edit-desc" class="form-control" rows="2"></textarea>
          </div>
          <div class="form-check">
            <input type="checkbox" class="form-check-input" name="activo" id="edit-activo"/>
            <label class="form-check-label small" for="edit-activo">Activa</label>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary btn-sm">Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function abrirEditar(c) {
  document.getElementById('edit-id').value     = c.id;
  document.getElementById('edit-nombre').value = c.nombre;
  document.getElementById('edit-tipo').value   = c.tipo;
  document.getElementById('edit-desc').value   = c.descripcion || '';
  document.getElementById('edit-activo').checked = c.activo == 1;
  new bootstrap.Modal(document.getElementById('modal-editar')).show();
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
