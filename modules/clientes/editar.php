<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';
requireLogin();
$db = getDB();
$id = (int)($_GET['id'] ?? 0);

$c = $db->prepare("SELECT * FROM clientes WHERE id=?");
$c->execute([$id]);
$c = $c->fetch();
if (!$c) { setFlash('danger','Cliente no encontrado'); redirect(BASE_URL.'modules/clientes/index.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ruc_dni = trim($_POST['ruc_dni'] ?? '');

    // Validar DNI/RUC duplicado (excluyendo el cliente actual)
    if ($ruc_dni !== '') {
        $dup = $db->prepare("SELECT id, nombre FROM clientes WHERE ruc_dni = ? AND id != ? AND activo = 1 LIMIT 1");
        $dup->execute([$ruc_dni, $id]);
        $dup = $dup->fetch();
        if ($dup) {
            setFlash('danger', 'Ya existe un cliente con el DNI/RUC <strong>' . sanitize($ruc_dni) . '</strong>: <strong>' . sanitize($dup['nombre']) . '</strong>. No se guardaron los cambios.');
            redirect(BASE_URL . 'modules/clientes/editar.php?id=' . $id);
        }
    }

    $db->prepare("UPDATE clientes SET tipo=?,nombre=?,ruc_dni=?,email=?,telefono=?,whatsapp=?,direccion=?,distrito=?,segmento=?,notas=?,activo=? WHERE id=?")
       ->execute([
           $_POST['tipo']     ?? 'persona',
           trim($_POST['nombre']),
           $ruc_dni,
           trim($_POST['email']     ?? ''),
           trim($_POST['telefono']  ?? ''),
           trim($_POST['whatsapp']  ?? ''),
           trim($_POST['direccion'] ?? ''),
           trim($_POST['distrito']  ?? ''),
           $_POST['segmento']       ?? 'nuevo',
           trim($_POST['notas']     ?? ''),
           isset($_POST['activo'])  ? 1 : 0,
           $id,
       ]);
    setFlash('success','Cliente actualizado correctamente.');
    redirect(BASE_URL.'modules/clientes/ver.php?id='.$id);
}

$pageTitle  = 'Editar cliente — '.APP_NAME;
$breadcrumb = [
    ['label'=>'Clientes','url'=>BASE_URL.'modules/clientes/index.php'],
    ['label'=>sanitize($c['nombre']),'url'=>BASE_URL.'modules/clientes/ver.php?id='.$id],
    ['label'=>'Editar','url'=>null],
];
require_once __DIR__ . '/../../includes/header.php';
?>
<h5 class="fw-bold mb-4">Editar cliente</h5>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="tr-card">
      <div class="tr-card-body">
        <form method="POST">
          <div class="row g-3">
            <div class="col-md-3">
              <label class="tr-form-label">Tipo</label>
              <select name="tipo" class="form-select">
                <option value="persona"  <?= $c['tipo']==='persona'?'selected':'' ?>>Persona</option>
                <option value="empresa"  <?= $c['tipo']==='empresa'?'selected':'' ?>>Empresa</option>
              </select>
            </div>
            <div class="col-md-9">
              <label class="tr-form-label">Nombre / Razón social *</label>
              <input type="text" name="nombre" class="form-control" value="<?= sanitize($c['nombre']) ?>" required autofocus/>
            </div>
            <div class="col-md-4"><label class="tr-form-label">DNI / RUC</label><input type="text" name="ruc_dni" class="form-control" value="<?= sanitize($c['ruc_dni']??'') ?>" maxlength="20"/></div>
            <div class="col-md-4"><label class="tr-form-label">Teléfono</label><input type="text" name="telefono" class="form-control" value="<?= sanitize($c['telefono']??'') ?>"/></div>
            <div class="col-md-4"><label class="tr-form-label">WhatsApp</label><input type="text" name="whatsapp" class="form-control" value="<?= sanitize($c['whatsapp']??'') ?>" placeholder="51999..."/></div>
            <div class="col-md-6"><label class="tr-form-label">Correo electrónico</label><input type="email" name="email" class="form-control" value="<?= sanitize($c['email']??'') ?>"/></div>
            <div class="col-md-6">
              <label class="tr-form-label">Segmento</label>
              <select name="segmento" class="form-select">
                <?php foreach(['nuevo'=>'Nuevo','frecuente'=>'Frecuente','empresa'=>'Empresa','vip'=>'VIP'] as $v=>$l): ?>
                <option value="<?= $v ?>" <?= $c['segmento']===$v?'selected':'' ?>><?= $l ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-8"><label class="tr-form-label">Dirección</label><input type="text" name="direccion" class="form-control" value="<?= sanitize($c['direccion']??'') ?>"/></div>
            <div class="col-md-4"><label class="tr-form-label">Distrito</label><input type="text" name="distrito" class="form-control" value="<?= sanitize($c['distrito']??'') ?>"/></div>
            <div class="col-12"><label class="tr-form-label">Notas internas</label><textarea name="notas" class="form-control" rows="2"><?= sanitize($c['notas']??'') ?></textarea></div>
            <div class="col-12">
              <div class="form-check"><input class="form-check-input" type="checkbox" name="activo" id="chk-activo" <?= $c['activo']?'checked':'' ?>><label class="form-check-label" for="chk-activo">Cliente activo</label></div>
            </div>
            <div class="col-12 d-flex gap-2">
              <button type="submit" class="btn btn-primary">Guardar cambios</button>
              <a href="<?= BASE_URL ?>modules/clientes/ver.php?id=<?= $id ?>" class="btn btn-outline-secondary">Cancelar</a>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
