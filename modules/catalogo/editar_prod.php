<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';
requireLogin();
requireRole([ROL_ADMIN]);
$db   = getDB();
$id   = (int)($_GET['id'] ?? 0);
$user = currentUser();

$prod = $db->prepare("SELECT p.*, c.nombre AS cat_nombre FROM productos p JOIN categorias c ON c.id=p.categoria_id WHERE p.id=?");
$prod->execute([$id]);
$prod = $prod->fetch();
if (!$prod) { setFlash('danger','Producto no encontrado'); redirect(BASE_URL.'modules/catalogo/index.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Subir fotos del catálogo
    $fotosActuales = json_decode($prod['fotos_catalogo'] ?? '[]', true) ?: [];

    if (!empty($_FILES['fotos_cat']['name'][0])) {
        $dir = UPLOAD_PATH . 'catalogo/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        foreach ($_FILES['fotos_cat']['name'] as $i => $fname) {
            if ($_FILES['fotos_cat']['error'][$i] === 0) {
                $ruta = uploadFoto([
                    'name'=>$fname,'type'=>$_FILES['fotos_cat']['type'][$i],
                    'tmp_name'=>$_FILES['fotos_cat']['tmp_name'][$i],'size'=>$_FILES['fotos_cat']['size'][$i]
                ], 'catalogo');
                if ($ruta) $fotosActuales[] = basename($ruta);
            }
        }
    }

    // Eliminar fotos marcadas
    $eliminar = $_POST['eliminar_foto'] ?? [];
    $fotosActuales = array_values(array_filter($fotosActuales, fn($f) => !in_array($f, $eliminar)));

    $db->prepare("UPDATE productos SET
        visible_catalogo  = ?,
        destacado         = ?,
        precio_oferta     = ?,
        descripcion_larga = ?,
        fotos_catalogo    = ?,
        descripcion       = ?
        WHERE id = ?")
       ->execute([
           isset($_POST['visible_catalogo']) ? 1 : 0,
           isset($_POST['destacado'])        ? 1 : 0,
           !empty($_POST['precio_oferta'])   ? (float)$_POST['precio_oferta'] : null,
           trim($_POST['descripcion_larga']  ?? ''),
           json_encode($fotosActuales),
           trim($_POST['descripcion']        ?? ''),
           $id,
       ]);

    setFlash('success', 'Datos del catálogo actualizados.');
    redirect(BASE_URL . 'modules/catalogo/editar_prod.php?id=' . $id);
}

$fotosActuales = json_decode($prod['fotos_catalogo'] ?? '[]', true) ?: [];

$pageTitle  = 'Editar catálogo — '.APP_NAME;
$breadcrumb = [
    ['label'=>'Catálogo','url'=>BASE_URL.'modules/catalogo/index.php'],
    ['label'=>sanitize($prod['nombre']),'url'=>null],
];
require_once __DIR__ . '/../../includes/header.php';
?>

<h5 class="fw-bold mb-1">Editar producto en catálogo</h5>
<p class="text-muted small mb-4"><?= sanitize($prod['nombre']) ?> — <?= sanitize($prod['cat_nombre']) ?></p>

<form method="POST" enctype="multipart/form-data">
<div class="row g-3">
  <div class="col-lg-8">

    <!-- Descripción -->
    <div class="tr-card mb-3">
      <div class="tr-card-header"><h6 class="mb-0 small fw-semibold">DESCRIPCIÓN PÚBLICA</h6></div>
      <div class="tr-card-body">
        <div class="mb-3">
          <label class="tr-form-label">Descripción corta <span class="text-muted small">(se muestra en la tarjeta)</span></label>
          <textarea name="descripcion" class="form-control" rows="2"><?= sanitize($prod['descripcion']??'') ?></textarea>
        </div>
        <div>
          <label class="tr-form-label">Descripción completa <span class="text-muted small">(se muestra en el modal del producto)</span></label>
          <textarea name="descripcion_larga" class="form-control" rows="4"><?= sanitize($prod['descripcion_larga']??'') ?></textarea>
        </div>
      </div>
    </div>

    <!-- Fotos -->
    <div class="tr-card mb-3">
      <div class="tr-card-header"><h6 class="mb-0 small fw-semibold">FOTOS DEL CATÁLOGO</h6></div>
      <div class="tr-card-body">
        <!-- Fotos actuales -->
        <?php if(!empty($fotosActuales)): ?>
        <div class="mb-3">
          <label class="tr-form-label">Fotos actuales</label>
          <div class="d-flex flex-wrap gap-2">
            <?php foreach($fotosActuales as $f): ?>
            <div class="position-relative">
              <img src="<?= UPLOAD_URL ?>catalogo/<?= htmlspecialchars($f) ?>"
                   style="width:80px;height:80px;object-fit:contain;border-radius:8px;border:1px solid #e5e7eb"/>
              <div class="form-check position-absolute" style="top:-4px;right:-4px">
                <input class="form-check-input" type="checkbox"
                       name="eliminar_foto[]" value="<?= htmlspecialchars($f) ?>"
                       id="del_<?= md5($f) ?>" style="background:#ef4444;border-color:#ef4444"/>
                <label class="form-check-label" for="del_<?= md5($f) ?>"
                       style="font-size:10px;color:#ef4444">Eliminar</label>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
        <!-- Agregar fotos -->
        <label class="tr-form-label">Agregar fotos</label>
        <div class="foto-drop-zone" id="foto-drop">
          <i data-feather="upload-cloud" style="width:28px;height:28px;color:#9ca3af"></i>
          <p class="text-muted small mb-0 mt-1">Arrastra o haz clic — JPG, PNG, WEBP — máx. 5MB</p>
          <input type="file" id="input-fotos" name="fotos_cat[]" multiple accept="image/*" style="display:none"/>
        </div>
        <div class="foto-preview-grid mt-2" id="preview-fotos"></div>
      </div>
    </div>

  </div>

  <div class="col-lg-4">

    <!-- Visibilidad y precio -->
    <div class="tr-card mb-3">
      <div class="tr-card-header"><h6 class="mb-0 small fw-semibold">CONFIGURACIÓN</h6></div>
      <div class="tr-card-body">
        <div class="mb-3">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="visible_catalogo" id="chk-visible"
                   <?= $prod['visible_catalogo']?'checked':'' ?>>
            <label class="form-check-label fw-semibold" for="chk-visible">
              Visible en catálogo público
            </label>
          </div>
          <div class="text-muted small mt-1">Si está activado, los clientes podrán ver este producto</div>
        </div>
        <div class="mb-3">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="destacado" id="chk-destacado"
                   <?= $prod['destacado']?'checked':'' ?>>
            <label class="form-check-label fw-semibold" for="chk-destacado">
              ⭐ Producto destacado
            </label>
          </div>
          <div class="text-muted small mt-1">Aparece primero en la lista</div>
        </div>
        <hr/>
        <div class="mb-2">
          <label class="tr-form-label">Precio de venta (S/)</label>
          <input type="text" class="form-control bg-light" value="<?= formatMoney($prod['precio_venta']) ?>" readonly/>
          <div class="text-muted small mt-1">Para cambiar el precio ve a Inventario</div>
        </div>
        <div class="mb-2">
          <label class="tr-form-label">Precio de oferta (S/) <span class="text-muted small">— opcional</span></label>
          <input type="number" name="precio_oferta" class="form-control" step="0.01" min="0"
                 value="<?= $prod['precio_oferta'] ?? '' ?>"
                 placeholder="Dejar vacío para sin oferta"/>
          <div class="text-muted small mt-1">Si es menor al precio normal, mostrará el % de descuento</div>
        </div>
      </div>
    </div>

    <!-- Info del producto -->
    <div class="tr-card mb-3">
      <div class="tr-card-header"><h6 class="mb-0 small fw-semibold">INFO</h6></div>
      <div class="tr-card-body">
        <div class="small mb-1"><strong>Código:</strong> <code><?= sanitize($prod['codigo']) ?></code></div>
        <div class="small mb-1"><strong>Categoría:</strong> <?= sanitize($prod['cat_nombre']) ?></div>
        <div class="small mb-1"><strong>Stock:</strong> <?= number_format($prod['stock_actual'],0) ?> unid.</div>
      </div>
    </div>

    <button type="submit" class="btn btn-primary w-100">
      <i data-feather="save" style="width:15px;height:15px"></i> Guardar cambios
    </button>
    <a href="<?= BASE_URL ?>modules/catalogo/index.php" class="btn btn-outline-secondary w-100 mt-2">Volver</a>
    <a href="<?= BASE_URL ?>public/catalogo/?cat=<?= $prod['categoria_id'] ?>" target="_blank"
       class="btn btn-outline-success w-100 mt-2">
      <i data-feather="external-link" style="width:13px;height:13px"></i> Ver en catálogo
    </a>
  </div>
</div>
</form>

<?php
$pageScripts = <<<'JS'
<script>
initFotoDrop('foto-drop','preview-fotos','input-fotos');
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
