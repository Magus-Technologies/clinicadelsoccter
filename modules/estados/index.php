<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';
requireLogin();
requireRole([ROL_ADMIN]);
$db = getDB();

$COLORES  = ['secondary','info','primary','warning','success','danger','dark','light'];
$ICONOS   = ['inbox','search','tool','check-circle','package','x-circle','clock','alert-circle','star','zap','shield','truck'];

// ── CREAR ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='crear') {
    $clave = preg_replace('/[^a-z0-9_]/', '_', strtolower(trim($_POST['clave']??'')));
    if (!$clave) { setFlash('danger','La clave es obligatoria.'); redirect(BASE_URL.'modules/estados/index.php'); }
    $maxOrden = (int)$db->query("SELECT COALESCE(MAX(orden),0)+1 FROM estados_ot")->fetchColumn();
    try {
        $db->prepare("INSERT INTO estados_ot (clave,label,color,icono,orden,es_final) VALUES (?,?,?,?,?,?)")
           ->execute([$clave, trim($_POST['label']), $_POST['color']??'secondary', $_POST['icono']??'circle', $maxOrden, isset($_POST['es_final'])?1:0]);
        setFlash('success','Estado creado correctamente.');
    } catch (\Exception $e) {
        setFlash('danger','Error: la clave ya existe o es inválida.');
    }
    redirect(BASE_URL.'modules/estados/index.php');
}

// ── EDITAR ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='editar') {
    $id = (int)$_POST['id'];
    $db->prepare("UPDATE estados_ot SET label=?,color=?,icono=?,es_final=?,activo=? WHERE id=?")
       ->execute([
           trim($_POST['label']),
           $_POST['color']  ?? 'secondary',
           $_POST['icono']  ?? 'circle',
           isset($_POST['es_final']) ? 1 : 0,
           isset($_POST['activo'])   ? 1 : 0,
           $id
       ]);
    setFlash('success','Estado actualizado.');
    redirect(BASE_URL.'modules/estados/index.php');
}

// ── ELIMINAR ────────────────────────────────────────────
if (isset($_GET['del']) && is_numeric($_GET['del'])) {
    $id = (int)$_GET['del'];
    // Verificar que no hay OTs con ese estado
    $clave = $db->prepare("SELECT clave FROM estados_ot WHERE id=?"); $clave->execute([$id]); $clave=$clave->fetchColumn();
    if ($clave) {
        $uso = $db->prepare("SELECT COUNT(*) FROM ordenes_trabajo WHERE estado=?"); $uso->execute([$clave]);
        if ($uso->fetchColumn() > 0) {
            setFlash('danger','No se puede eliminar: hay órdenes de trabajo con este estado.');
        } else {
            $db->prepare("DELETE FROM estados_ot WHERE id=?")->execute([$id]);
            setFlash('success','Estado eliminado.');
        }
    }
    redirect(BASE_URL.'modules/estados/index.php');
}

// ── REORDENAR (AJAX) ────────────────────────────────────
if (isset($_POST['action']) && $_POST['action']==='reordenar') {
    $ids = json_decode($_POST['ids']??'[]', true);
    foreach ($ids as $i => $id) {
        $db->prepare("UPDATE estados_ot SET orden=? WHERE id=?")->execute([$i+1, (int)$id]);
    }
    echo json_encode(['ok'=>true]); exit;
}

$estados = $db->query("SELECT * FROM estados_ot ORDER BY orden ASC")->fetchAll();

$pageTitle  = 'Estados OT — '.APP_NAME;
$breadcrumb = [['label'=>'Configuración','url'=>BASE_URL.'modules/configuracion/index.php'],['label'=>'Estados OT','url'=>null]];
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h5 class="fw-bold mb-0">🏷 Estados de Órdenes de Trabajo</h5>
    <p class="text-muted small mb-0 mt-1">Arrastra para reordenar · Los estados finales bloquean más cambios</p>
  </div>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modal-nuevo">
    <i data-feather="plus" style="width:14px;height:14px"></i> Nuevo estado
  </button>
</div>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="tr-card">
      <div class="tr-card-header">
        <h6 class="mb-0 small fw-semibold">ESTADOS CONFIGURADOS</h6>
        <span class="text-muted small">Arrastra para cambiar el orden</span>
      </div>
      <div class="tr-card-body p-0">
        <ul id="lista-estados" class="list-unstyled mb-0" style="min-height:60px">
          <?php foreach($estados as $e): ?>
          <li class="estado-row d-flex align-items-center gap-3 px-4 py-3 border-bottom"
              data-id="<?= $e['id'] ?>" style="<?= !$e['activo']?'opacity:.5':'' ?>">
            <span style="cursor:grab;color:#9ca3af;font-size:18px" title="Arrastra para reordenar">⠿</span>
            <span class="badge bg-<?= htmlspecialchars($e['color']) ?> px-3 py-2" style="font-size:13px;min-width:120px;text-align:center">
              <i data-feather="<?= htmlspecialchars($e['icono']) ?>" style="width:13px;height:13px" class="me-1"></i>
              <?= sanitize($e['label']) ?>
            </span>
            <code class="small text-muted"><?= sanitize($e['clave']) ?></code>
            <?php if($e['es_final']): ?>
            <span class="badge bg-dark" style="font-size:10px">Final</span>
            <?php endif; ?>
            <?php if(!$e['activo']): ?>
            <span class="badge bg-secondary" style="font-size:10px">Inactivo</span>
            <?php endif; ?>
            <div class="ms-auto d-flex gap-2">
              <button class="btn btn-sm btn-outline-primary py-0"
                      onclick="abrirEditar(<?= htmlspecialchars(json_encode($e)) ?>)"
                      title="Editar">
                <i data-feather="edit-2" style="width:13px;height:13px"></i>
              </button>
              <a href="?del=<?= $e['id'] ?>"
                 class="btn btn-sm btn-outline-danger py-0"
                 data-confirm="¿Eliminar el estado '<?= sanitize($e['label']) ?>'? Solo es posible si no hay OTs con este estado."
                 title="Eliminar">
                <i data-feather="trash-2" style="width:13px;height:13px"></i>
              </a>
            </div>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>

    <div class="alert alert-info small mt-3 py-2">
      💡 <strong>Nota:</strong> Los estados se cargan dinámicamente en el sistema. Después de guardar cambios, se reflejan de inmediato en todas las órdenes de trabajo.
    </div>
  </div>

  <div class="col-lg-4">
    <div class="tr-card">
      <div class="tr-card-header"><h6 class="mb-0 small fw-semibold">COLORES DISPONIBLES</h6></div>
      <div class="tr-card-body">
        <?php foreach($COLORES as $c): ?>
        <span class="badge bg-<?= $c ?> me-1 mb-1 px-3 py-2"><?= $c ?></span>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="tr-card mt-3">
      <div class="tr-card-header"><h6 class="mb-0 small fw-semibold">ESTADÍSTICAS DE USO</h6></div>
      <div class="tr-card-body p-0">
        <table class="tr-table">
          <thead><tr><th>Estado</th><th class="text-end">OTs</th></tr></thead>
          <tbody>
          <?php foreach($estados as $e):
            $n = $db->prepare("SELECT COUNT(*) FROM ordenes_trabajo WHERE estado=?");
            $n->execute([$e['clave']]); $n=$n->fetchColumn();
          ?>
          <tr>
            <td><span class="badge bg-<?= $e['color'] ?>"><?= sanitize($e['label']) ?></span></td>
            <td class="text-end fw-bold"><?= $n ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Modal Nuevo Estado -->
<div class="modal fade" id="modal-nuevo" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" value="crear"/>
        <div class="modal-header">
          <h6 class="modal-title fw-bold">Nuevo estado</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="tr-form-label">Clave interna * <span class="text-muted small">(sin espacios, solo minúsculas)</span></label>
            <input type="text" name="clave" class="form-control" required
                   placeholder="ej: en_espera_repuesto"
                   pattern="[a-zA-Z0-9_]+"
                   title="Solo letras, números y guiones bajos"/>
            <div class="form-text">Se usará internamente en la BD. No se puede cambiar después.</div>
          </div>
          <div class="mb-3">
            <label class="tr-form-label">Etiqueta visible *</label>
            <input type="text" name="label" class="form-control" required
                   placeholder="ej: En espera de repuesto"/>
          </div>
          <div class="mb-3">
            <label class="tr-form-label">Color</label>
            <select name="color" class="form-select" id="sel-color-nuevo"
                    onchange="actualizarPreviewNuevo()">
              <?php foreach($COLORES as $c): ?>
              <option value="<?= $c ?>"><?= $c ?></option>
              <?php endforeach; ?>
            </select>
            <div class="mt-2">
              <span id="preview-nuevo" class="badge bg-secondary px-3 py-2" style="font-size:13px">Vista previa</span>
            </div>
          </div>
          <div class="mb-3">
            <label class="tr-form-label">Ícono <span class="text-muted small">(nombre Feather)</span></label>
            <select name="icono" class="form-select">
              <?php foreach($ICONOS as $ico): ?>
              <option value="<?= $ico ?>"><?= $ico ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-check">
            <input type="checkbox" class="form-check-input" name="es_final" id="esFinalN"/>
            <label class="form-check-label small" for="esFinalN">
              <strong>Estado final</strong> — no permite cambios posteriores
            </label>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary btn-sm">Crear estado</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal Editar Estado -->
<div class="modal fade" id="modal-editar" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" value="editar"/>
        <input type="hidden" name="id" id="edit-id"/>
        <div class="modal-header">
          <h6 class="modal-title fw-bold">Editar estado</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2">
            <label class="tr-form-label">Clave <span class="text-muted small">(no editable)</span></label>
            <input type="text" id="edit-clave" class="form-control bg-light" readonly/>
          </div>
          <div class="mb-3">
            <label class="tr-form-label">Etiqueta visible *</label>
            <input type="text" name="label" id="edit-label" class="form-control" required/>
          </div>
          <div class="mb-3">
            <label class="tr-form-label">Color</label>
            <select name="color" id="edit-color" class="form-select"
                    onchange="actualizarPreviewEditar()">
              <?php foreach($COLORES as $c): ?>
              <option value="<?= $c ?>"><?= $c ?></option>
              <?php endforeach; ?>
            </select>
            <div class="mt-2">
              <span id="preview-editar" class="badge px-3 py-2" style="font-size:13px">Vista previa</span>
            </div>
          </div>
          <div class="mb-3">
            <label class="tr-form-label">Ícono</label>
            <select name="icono" id="edit-icono" class="form-select">
              <?php foreach($ICONOS as $ico): ?>
              <option value="<?= $ico ?>"><?= $ico ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-check mb-2">
            <input type="checkbox" class="form-check-input" name="es_final" id="esFinalE"/>
            <label class="form-check-label small" for="esFinalE">
              <strong>Estado final</strong> — no permite cambios posteriores
            </label>
          </div>
          <div class="form-check">
            <input type="checkbox" class="form-check-input" name="activo" id="activoE" checked/>
            <label class="form-check-label small" for="activoE">Activo</label>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary btn-sm">Guardar cambios</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.2/Sortable.min.js"></script>
<script>
// Drag & drop para reordenar
new Sortable(document.getElementById('lista-estados'), {
  animation: 150,
  handle: 'span[title="Arrastra para reordenar"]',
  onEnd: function() {
    var ids = Array.from(document.querySelectorAll('.estado-row')).map(el => el.dataset.id);
    fetch('', {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: 'action=reordenar&ids=' + encodeURIComponent(JSON.stringify(ids))
    });
  }
});

function abrirEditar(e) {
  document.getElementById('edit-id').value    = e.id;
  document.getElementById('edit-clave').value = e.clave;
  document.getElementById('edit-label').value = e.label;
  document.getElementById('edit-color').value = e.color;
  document.getElementById('edit-icono').value = e.icono;
  document.getElementById('esFinalE').checked = e.es_final == 1;
  document.getElementById('activoE').checked  = e.activo  == 1;
  actualizarPreviewEditar();
  new bootstrap.Modal(document.getElementById('modal-editar')).show();
}

function actualizarPreviewNuevo() {
  var c = document.getElementById('sel-color-nuevo').value;
  var p = document.getElementById('preview-nuevo');
  var label = document.querySelector('[name="label"]')?.value || 'Vista previa';
  p.className = 'badge bg-' + c + ' px-3 py-2';
  p.textContent = label;
}
function actualizarPreviewEditar() {
  var c = document.getElementById('edit-color').value;
  var p = document.getElementById('preview-editar');
  p.className = 'badge bg-' + c + ' px-3 py-2';
  p.textContent = document.getElementById('edit-label').value || 'Vista previa';
}

// Actualizar preview en tiempo real
document.querySelector('[name="label"]')?.addEventListener('input', actualizarPreviewNuevo);
document.getElementById('edit-label')?.addEventListener('input', actualizarPreviewEditar);
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
