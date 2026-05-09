<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';
requireLogin();
requireRole([ROL_ADMIN]);
$db = getDB();

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $campos = ['catalogo_nombre','catalogo_whatsapp','catalogo_mensaje_wa',
               'catalogo_color_primario','catalogo_mostrar_precio','catalogo_productos_por_pagina'];
    foreach ($campos as $c) {
        if (isset($_POST[$c])) {
            $existe = $db->prepare("SELECT COUNT(*) FROM configuracion WHERE clave=?"); $existe->execute([$c]);
            if ($existe->fetchColumn()) {
                $db->prepare("UPDATE configuracion SET valor=? WHERE clave=?")->execute([trim($_POST[$c]),$c]);
            } else {
                $db->prepare("INSERT INTO configuracion (clave,valor,tipo,grupo) VALUES (?,?,?,?)")
                   ->execute([$c,trim($_POST[$c]),'texto','catalogo']);
            }
        }
    }
    setFlash('success','Configuración del catálogo guardada.'); redirect(BASE_URL.'modules/catalogo/config.php');
}

$cfg = [];
$rows = $db->query("SELECT clave,valor FROM configuracion WHERE grupo='catalogo'")->fetchAll();
foreach ($rows as $r) $cfg[$r['clave']] = $r['valor'];
function cv($k,$cfg) { return htmlspecialchars($cfg[$k]??''); }

$pageTitle  = 'Config catálogo — '.APP_NAME;
$breadcrumb = [['label'=>'Catálogo','url'=>BASE_URL.'modules/catalogo/index.php'],['label'=>'Configuración','url'=>null]];
require_once __DIR__ . '/../../includes/header.php';
?>
<h5 class="fw-bold mb-4">⚙️ Configuración del catálogo</h5>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="tr-card">
      <div class="tr-card-body">
        <form method="POST">
          <div class="mb-3"><label class="tr-form-label">Nombre del catálogo</label><input type="text" name="catalogo_nombre" class="form-control" value="<?= cv('catalogo_nombre',$cfg) ?>" placeholder="Mi Tienda"/></div>
          <div class="mb-3">
            <label class="tr-form-label">Número WhatsApp de contacto <span class="text-muted small">(con código de país)</span></label>
            <input type="text" name="catalogo_whatsapp" class="form-control" value="<?= cv('catalogo_whatsapp',$cfg) ?>" placeholder="51999888777"/>
            <div class="form-text">El número al que llegarán los pedidos por WhatsApp</div>
          </div>
          <div class="mb-3">
            <label class="tr-form-label">Mensaje por defecto de WhatsApp</label>
            <textarea name="catalogo_mensaje_wa" class="form-control" rows="3"><?= cv('catalogo_mensaje_wa',$cfg) ?></textarea>
            <div class="form-text">Variables: {producto} {precio} {empresa}</div>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-md-4">
              <label class="tr-form-label">Color principal</label>
              <div class="input-group">
                <input type="color" name="catalogo_color_primario" class="form-control form-control-color" value="<?= cv('catalogo_color_primario',$cfg) ?: '#0d9488' ?>"/>
                <input type="text" class="form-control form-control-sm" value="<?= cv('catalogo_color_primario',$cfg) ?: '#0d9488' ?>" readonly style="max-width:90px"/>
              </div>
            </div>
            <div class="col-md-4">
              <label class="tr-form-label">Mostrar precios</label>
              <select name="catalogo_mostrar_precio" class="form-select">
                <option value="1" <?= ($cfg['catalogo_mostrar_precio']??'1')==='1'?'selected':'' ?>>Sí, mostrar precios</option>
                <option value="0" <?= ($cfg['catalogo_mostrar_precio']??'1')==='0'?'selected':'' ?>>No mostrar precios</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="tr-form-label">Productos por página</label>
              <select name="catalogo_productos_por_pagina" class="form-select">
                <?php foreach([8,12,16,20,24] as $n): ?>
                <option value="<?= $n ?>" <?= ($cfg['catalogo_productos_por_pagina']??'12')==$n?'selected':'' ?>><?= $n ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <button type="submit" class="btn btn-primary">Guardar configuración</button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
