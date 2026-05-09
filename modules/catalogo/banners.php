<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';
requireLogin();
requireRole([ROL_ADMIN]);
$db = getDB();

// Eliminar banner
if (isset($_GET['del']) && is_numeric($_GET['del'])) {
    $b = $db->prepare("SELECT imagen FROM catalogo_banners WHERE id=?"); $b->execute([(int)$_GET['del']]); $b=$b->fetch();
    if ($b) { @unlink(UPLOAD_PATH.'banners/'.$b['imagen']); $db->prepare("DELETE FROM catalogo_banners WHERE id=?")->execute([(int)$_GET['del']]); }
    setFlash('success','Banner eliminado.'); redirect(BASE_URL.'modules/catalogo/banners.php');
}
// Toggle activo
if (isset($_GET['toggle']) && is_numeric($_GET['toggle'])) {
    $db->prepare("UPDATE catalogo_banners SET activo = 1-activo WHERE id=?")->execute([(int)$_GET['toggle']]);
    redirect(BASE_URL.'modules/catalogo/banners.php');
}
// Guardar nuevo banner
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $dir = UPLOAD_PATH.'banners/';
    if (!is_dir($dir)) mkdir($dir,0755,true);
    $imagen = '';
    if (!empty($_FILES['imagen']['name']) && $_FILES['imagen']['error']===0) {
        $ruta = uploadFoto($_FILES['imagen'],'banners');
        if ($ruta) $imagen = basename($ruta);
    }
    if ($imagen) {
        $maxOrden = $db->query("SELECT COALESCE(MAX(orden),0)+1 FROM catalogo_banners")->fetchColumn();
        $db->prepare("INSERT INTO catalogo_banners (titulo,subtitulo,imagen,url_link,orden) VALUES (?,?,?,?,?)")
           ->execute([trim($_POST['titulo']??''), trim($_POST['subtitulo']??''), $imagen, trim($_POST['url_link']??''), $maxOrden]);
        setFlash('success','Banner agregado.');
    } else {
        setFlash('danger','Debes subir una imagen para el banner.');
    }
    redirect(BASE_URL.'modules/catalogo/banners.php');
}

$banners = $db->query("SELECT * FROM catalogo_banners ORDER BY orden ASC")->fetchAll();
$pageTitle  = 'Banners del catálogo — '.APP_NAME;
$breadcrumb = [['label'=>'Catálogo','url'=>BASE_URL.'modules/catalogo/index.php'],['label'=>'Banners','url'=>null]];
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h5 class="fw-bold mb-0">🖼 Banners del catálogo</h5>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modal-banner">
    <i data-feather="plus" style="width:14px;height:14px"></i> Agregar banner
  </button>
</div>
<div class="alert alert-info small py-2">
  💡 Los banners aparecen como slider en la parte superior del catálogo. Tamaño recomendado: <strong>1200 × 280 px</strong>
</div>
<div class="tr-card">
  <div class="tr-card-body p-0" style="overflow:hidden">
    <table class="tr-table">
      <thead><tr><th>Preview</th><th>Título</th><th>Subtítulo</th><th>Orden</th><th>Estado</th><th></th></tr></thead>
      <tbody>
        <?php foreach($banners as $b): ?>
        <tr>
          <td>
            <img src="<?= UPLOAD_URL ?>banners/<?= htmlspecialchars($b['imagen']) ?>"
                 style="height:52px;border-radius:6px;object-fit:cover;max-width:120px"
                 onerror="this.src='data:image/svg+xml,<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'120\' height=\'52\'><rect fill=\'%23f3f4f6\' width=\'120\' height=\'52\'/><text x=\'50%25\' y=\'55%25\' text-anchor=\'middle\' fill=\'%239ca3af\' font-size=\'11\'>Sin imagen</text></svg>'"/>
          </td>
          <td class="fw-semibold small"><?= sanitize($b['titulo']??'—') ?></td>
          <td class="small text-muted"><?= sanitize(mb_strimwidth($b['subtitulo']??'',0,50,'…')) ?></td>
          <td class="text-center"><?= $b['orden'] ?></td>
          <td>
            <a href="?toggle=<?= $b['id'] ?>" class="badge bg-<?= $b['activo']?'success':'secondary' ?> text-decoration-none">
              <?= $b['activo']?'Activo':'Inactivo' ?>
            </a>
          </td>
          <td>
            <a href="?del=<?= $b['id'] ?>" class="btn btn-sm btn-outline-danger py-0"
               data-confirm="¿Eliminar este banner?">
              <i data-feather="trash-2" style="width:13px;height:13px"></i>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($banners)): ?>
        <tr><td colspan="6" class="text-center text-muted py-4">Sin banners. Agrega el primero.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal nuevo banner -->
<div class="modal fade" id="modal-banner" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" enctype="multipart/form-data">
        <div class="modal-header"><h6 class="modal-title fw-bold">Agregar banner</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-2">
            <label class="tr-form-label">Imagen * <span class="text-muted small">(1200×280px recomendado)</span></label>
            <input type="file" name="imagen" class="form-control" accept="image/*" required/>
          </div>
          <div class="mb-2"><label class="tr-form-label">Título (opcional)</label><input type="text" name="titulo" class="form-control" placeholder="Ej: Reparación de Laptops"/></div>
          <div class="mb-2"><label class="tr-form-label">Subtítulo (opcional)</label><input type="text" name="subtitulo" class="form-control" placeholder="Ej: Diagnóstico · Mantenimiento · Reparación"/></div>
          <div class="mb-2"><label class="tr-form-label">URL al hacer clic (opcional)</label><input type="text" name="url_link" class="form-control" placeholder="https://..."/></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary btn-sm">Subir banner</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
