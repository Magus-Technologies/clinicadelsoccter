<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';
requireLogin();
requireRole([ROL_ADMIN]);
$db = getDB();

// Cargar tipos dinámicos (con fallback si tabla no existe)
function getTipos(PDO $db): array {
    try {
        return $db->query("SELECT nombre FROM categorias_tipos WHERE activo=1 ORDER BY orden,nombre")->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Exception $e) {
        return ['repuesto','hardware','ofimatica','accesorio','software','servicio','otro'];
    }
}

// ── ACCIONES TIPOS ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='crear_tipo') {
    $nom = strtolower(trim($_POST['nombre_tipo'] ?? ''));
    $nom = preg_replace('/[^a-z0-9áéíóúñ\s]/u', '', $nom);
    $nom = trim($nom);
    if ($nom) {
        try {
            $max = (int)$db->query("SELECT COALESCE(MAX(orden),0)+1 FROM categorias_tipos")->fetchColumn();
            $db->prepare("INSERT INTO categorias_tipos (nombre,orden) VALUES (?,?)")->execute([$nom,$max]);
            setFlash('success', 'Tipo "'.htmlspecialchars($nom).'" creado.');
        } catch(\Exception $e) { setFlash('danger','Error: ese tipo ya existe.'); }
    }
    redirect(BASE_URL.'modules/categorias/index.php?tab=tipos');
}
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='editar_tipo') {
    $id  = (int)$_POST['id'];
    $nom = strtolower(trim($_POST['nombre_tipo'] ?? ''));
    if ($nom) {
        // Actualizar también en categorias que usen el tipo viejo
        $viejo = $db->prepare("SELECT nombre FROM categorias_tipos WHERE id=?"); $viejo->execute([$id]); $viejo=$viejo->fetchColumn();
        $db->prepare("UPDATE categorias_tipos SET nombre=? WHERE id=?")->execute([$nom,$id]);
        if ($viejo) $db->prepare("UPDATE categorias SET tipo=? WHERE tipo=?")->execute([$nom,$viejo]);
        setFlash('success','Tipo actualizado.');
    }
    redirect(BASE_URL.'modules/categorias/index.php?tab=tipos');
}
if (isset($_GET['del_tipo'])) {
    $id   = (int)$_GET['del_tipo'];
    $nom  = $db->prepare("SELECT nombre FROM categorias_tipos WHERE id=?"); $nom->execute([$id]); $nom=$nom->fetchColumn();
    $uso  = $db->prepare("SELECT COUNT(*) FROM categorias WHERE tipo=?"); $uso->execute([$nom]);
    if ($uso->fetchColumn() > 0) {
        setFlash('danger','No se puede eliminar: hay categorías usando este tipo.');
    } else {
        $db->prepare("DELETE FROM categorias_tipos WHERE id=?")->execute([$id]);
        setFlash('success','Tipo eliminado.');
    }
    redirect(BASE_URL.'modules/categorias/index.php?tab=tipos');
}

// ── ACCIONES CATEGORÍAS ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='crear') {
    $db->prepare("INSERT INTO categorias (nombre,tipo,descripcion) VALUES (?,?,?)")
       ->execute([trim($_POST['nombre']), $_POST['tipo'], trim($_POST['descripcion']??'')]);
    setFlash('success','Categoría creada.');
    redirect(BASE_URL.'modules/categorias/index.php');
}
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='editar') {
    $db->prepare("UPDATE categorias SET nombre=?,tipo=?,descripcion=?,activo=? WHERE id=?")
       ->execute([trim($_POST['nombre']), $_POST['tipo'], trim($_POST['descripcion']??''),
                  isset($_POST['activo'])?1:0, (int)$_POST['id']]);
    setFlash('success','Categoría actualizada.');
    redirect(BASE_URL.'modules/categorias/index.php');
}
if (isset($_GET['del'])) {
    $id  = (int)$_GET['del'];
    $uso = $db->prepare("SELECT COUNT(*) FROM productos WHERE categoria_id=?"); $uso->execute([$id]);
    if ($uso->fetchColumn() > 0) {
        setFlash('danger','No se puede eliminar: tiene productos asociados.');
    } else {
        $db->prepare("DELETE FROM categorias WHERE id=?")->execute([$id]);
        setFlash('success','Categoría eliminada.');
    }
    redirect(BASE_URL.'modules/categorias/index.php');
}
if (isset($_GET['toggle'])) {
    $db->prepare("UPDATE categorias SET activo=1-activo WHERE id=?")->execute([(int)$_GET['toggle']]);
    redirect(BASE_URL.'modules/categorias/index.php');
}

$tipos = getTipos($db);
$tiposConId = [];
try {
    $tiposConId = $db->query("SELECT id,nombre,orden FROM categorias_tipos WHERE activo=1 ORDER BY orden,nombre")->fetchAll();
} catch(\Exception $e) {}

$categorias = $db->query("
    SELECT c.*, COUNT(p.id) AS total_productos
    FROM categorias c
    LEFT JOIN productos p ON p.categoria_id=c.id
    GROUP BY c.id
    ORDER BY c.tipo, c.nombre")->fetchAll();

$tab = $_GET['tab'] ?? 'categorias';

$pageTitle  = 'Categorías — '.APP_NAME;
$breadcrumb = [['label'=>'Inventario','url'=>BASE_URL.'modules/inventario/index.php'],['label'=>'Categorías','url'=>null]];
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h5 class="fw-bold mb-0">🏷 Categorías de productos</h5>
  <button class="btn btn-primary btn-sm"
          data-bs-toggle="modal"
          data-bs-target="<?= $tab==='tipos' ? '#modal-nuevo-tipo' : '#modal-nueva' ?>">
    <i data-feather="plus" style="width:14px;height:14px"></i>
    <?= $tab==='tipos' ? 'Nuevo tipo' : 'Nueva categoría' ?>
  </button>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4">
  <li class="nav-item">
    <a class="nav-link <?= $tab==='categorias'?'active':'' ?>"
       href="?tab=categorias">Categorías</a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $tab==='tipos'?'active':'' ?>"
       href="?tab=tipos">Tipos de categoría</a>
  </li>
</ul>

<?php if($tab==='categorias'): ?>
<!-- ── CATEGORÍAS ── -->
<div class="tr-card">
  <div class="tr-card-body p-0" style="overflow:hidden">
    <table class="tr-table">
      <thead>
        <tr><th>Nombre</th><th>Tipo</th><th>Descripción</th><th class="text-center">Productos</th><th>Estado</th><th></th></tr>
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
      <tr style="<?= !$c['activo']?'opacity:.55':'' ?>">
        <td class="fw-semibold"><?= sanitize($c['nombre']) ?></td>
        <td><span class="badge bg-secondary" style="font-size:11px"><?= htmlspecialchars($c['tipo']) ?></span></td>
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
                    onclick="abrirEditarCat(<?= htmlspecialchars(json_encode($c),ENT_QUOTES) ?>)">
              <i data-feather="edit-2" style="width:13px;height:13px"></i>
            </button>
            <a href="?del=<?= $c['id'] ?>" class="btn btn-outline-danger"
               data-confirm="¿Eliminar '<?= sanitize($c['nombre']) ?>'?">
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

<?php else: ?>
<!-- ── TIPOS ── -->
<div class="alert alert-info small py-2 mb-3">
  💡 Los tipos agrupan las categorías. Puedes crear tipos personalizados como "Scooter", "Movilidad eléctrica", etc.
</div>
<div class="tr-card">
  <div class="tr-card-body p-0" style="overflow:hidden">
    <table class="tr-table">
      <thead>
        <tr><th>Nombre del tipo</th><th class="text-center">Categorías</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach($tiposConId as $t):
        $nCats = $db->prepare("SELECT COUNT(*) FROM categorias WHERE tipo=?");
        $nCats->execute([$t['nombre']]); $nCats=$nCats->fetchColumn();
      ?>
      <tr>
        <td class="fw-semibold text-capitalize"><?= htmlspecialchars($t['nombre']) ?></td>
        <td class="text-center">
          <span class="badge bg-<?= $nCats>0?'primary':'light text-dark border' ?>"><?= $nCats ?></span>
        </td>
        <td>
          <div class="btn-group btn-group-sm">
            <button class="btn btn-outline-primary"
                    onclick="abrirEditarTipo(<?= $t['id'] ?>,'<?= htmlspecialchars($t['nombre']) ?>')">
              <i data-feather="edit-2" style="width:13px;height:13px"></i>
            </button>
            <a href="?del_tipo=<?= $t['id'] ?>&tab=tipos" class="btn btn-outline-danger"
               data-confirm="¿Eliminar tipo '<?= htmlspecialchars($t['nombre']) ?>'?">
              <i data-feather="trash-2" style="width:13px;height:13px"></i>
            </a>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if(empty($tiposConId)): ?>
      <tr><td colspan="3" class="text-center text-muted py-4">
        Sin tipos. <a href="?tab=tipos">Ejecuta primero el SQL de migración.</a>
      </td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Modal Nueva Categoría -->
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
              <?php foreach($tipos as $t): ?>
              <option value="<?= htmlspecialchars($t) ?>"><?= ucfirst(htmlspecialchars($t)) ?></option>
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

<!-- Modal Editar Categoría -->
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
              <?php foreach($tipos as $t): ?>
              <option value="<?= htmlspecialchars($t) ?>"><?= ucfirst(htmlspecialchars($t)) ?></option>
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

<!-- Modal Nuevo Tipo -->
<div class="modal fade" id="modal-nuevo-tipo" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" value="crear_tipo"/>
        <div class="modal-header">
          <h6 class="modal-title fw-bold">Nuevo tipo</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <label class="tr-form-label">Nombre del tipo *</label>
          <input type="text" name="nombre_tipo" class="form-control" required
                 placeholder="Ej: scooter, eléctrico..."/>
          <div class="form-text">Se guardará en minúsculas</div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary btn-sm">Crear tipo</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal Editar Tipo -->
<div class="modal fade" id="modal-editar-tipo" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" value="editar_tipo"/>
        <input type="hidden" name="id" id="tipo-edit-id"/>
        <div class="modal-header">
          <h6 class="modal-title fw-bold">Editar tipo</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <label class="tr-form-label">Nombre *</label>
          <input type="text" name="nombre_tipo" id="tipo-edit-nombre" class="form-control" required/>
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
function abrirEditarCat(c) {
  document.getElementById('edit-id').value      = c.id;
  document.getElementById('edit-nombre').value  = c.nombre;
  document.getElementById('edit-tipo').value     = c.tipo;
  document.getElementById('edit-desc').value     = c.descripcion || '';
  document.getElementById('edit-activo').checked = c.activo == 1;
  new bootstrap.Modal(document.getElementById('modal-editar')).show();
}
function abrirEditarTipo(id, nombre) {
  document.getElementById('tipo-edit-id').value     = id;
  document.getElementById('tipo-edit-nombre').value = nombre;
  new bootstrap.Modal(document.getElementById('modal-editar-tipo')).show();
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
