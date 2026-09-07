<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';
requireLogin();
requireRole([ROL_ADMIN]);
$db   = getDB();
$user = currentUser();

// Crear usuario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'crear') {
    $pass = password_hash(trim($_POST['password']), PASSWORD_BCRYPT);
    $db->prepare("INSERT INTO usuarios (nombre,apellido,email,password_hash,rol,telefono) VALUES (?,?,?,?,?,?)")
       ->execute([trim($_POST['nombre']),trim($_POST['apellido']),trim($_POST['email']),$pass,$_POST['rol'],trim($_POST['telefono']??'')]);
    setFlash('success','Usuario creado correctamente.');
    redirect(BASE_URL.'modules/tecnicos/index.php');
}

// Cambiar estado
if (isset($_GET['toggle'])) {
    $uid = (int)$_GET['toggle'];
    $db->prepare("UPDATE usuarios SET activo = 1-activo WHERE id=? AND id != ?")->execute([$uid,$user['id']]);
    redirect(BASE_URL.'modules/tecnicos/index.php');
}

// 'entregado' ya no existe en `estados_ot`, así que este contador siempre
// daba 0. Los estados de cierre se leen de la configuración.
[$phOk, $valOk] = phEstadosCompletados();
$usuarios = $db->prepare("
  SELECT u.*,
    (SELECT COUNT(*) FROM ordenes_trabajo WHERE tecnico_id=u.id) as total_ots,
    (SELECT COUNT(*) FROM ordenes_trabajo WHERE tecnico_id=u.id AND estado IN ($phOk)) as ots_completadas
  FROM usuarios u ORDER BY u.activo DESC, u.nombre
");
$usuarios->execute($valOk);
$usuarios = $usuarios->fetchAll();

$pageTitle  = 'Usuarios y técnicos — '.APP_NAME;
$breadcrumb = [['label'=>'Usuarios','url'=>null]];
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="fw-bold mb-0">Usuarios del sistema</h5>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modal-nuevo">
    <i data-feather="user-plus" style="width:14px;height:14px"></i> Nuevo usuario
  </button>
</div>

<div class="tr-card">
  <div class="tr-card-body p-0" style="overflow:hidden"><div class="table-responsive-wrapper" style="overflow-x:auto;-webkit-overflow-scrolling:touch">
    <table class="tr-table">
      <thead><tr><th>Usuario</th><th>Rol</th><th>Email</th><th>Teléfono</th><th>OTs asignadas</th><th>Completadas</th><th>Último acceso</th><th>Estado</th><th></th></tr></thead>
      <tbody>
        <?php foreach($usuarios as $u): ?>
        <tr class="<?= !$u['activo']?'opacity-50':'' ?>">
          <td>
            <div class="d-flex align-items-center gap-2">
              <div class="tr-avatar" style="width:30px;height:30px;font-size:12px"><?= strtoupper(substr($u['nombre'],0,1)) ?></div>
              <div>
                <div class="fw-semibold small"><?= sanitize($u['nombre'].' '.$u['apellido']) ?></div>
              </div>
            </div>
          </td>
          <td><span class="badge bg-<?= $u['rol']==='admin'?'danger':($u['rol']==='tecnico'?'primary':'success') ?>"><?= ucfirst($u['rol']) ?></span></td>
          <td class="small"><?= sanitize($u['email']) ?></td>
          <td class="small"><?= sanitize($u['telefono']??'—') ?></td>
          <td class="text-center"><?= $u['total_ots'] ?></td>
          <td class="text-center text-success fw-semibold"><?= $u['ots_completadas'] ?></td>
          <td class="small text-muted"><?= $u['ultimo_acceso']?formatDateTime($u['ultimo_acceso']):'Nunca' ?></td>
          <td><span class="badge bg-<?= $u['activo']?'success':'secondary' ?>"><?= $u['activo']?'Activo':'Inactivo' ?></span></td>
          <td>
            <?php if($u['id'] != $user['id']): ?>
            <a href="?toggle=<?= $u['id'] ?>" class="btn btn-sm btn-outline-<?= $u['activo']?'danger':'success' ?>" 
               data-confirm="¿<?= $u['activo']?'Desactivar':'Activar' ?> este usuario?">
              <?= $u['activo']?'Desactivar':'Activar' ?>
            </a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal nuevo usuario -->
<div class="modal fade" id="modal-nuevo" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" value="crear"/>
        <div class="modal-header">
          <h6 class="modal-title fw-bold">Nuevo usuario</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-2">
            <div class="col-md-6"><label class="tr-form-label">Nombre *</label><input type="text" name="nombre" class="form-control" required/></div>
            <div class="col-md-6"><label class="tr-form-label">Apellido *</label><input type="text" name="apellido" class="form-control" required/></div>
            <div class="col-md-6"><label class="tr-form-label">Email *</label><input type="email" name="email" class="form-control" required/></div>
            <div class="col-md-6"><label class="tr-form-label">Teléfono</label><input type="text" name="telefono" class="form-control"/></div>
            <div class="col-md-6">
              <label class="tr-form-label">Rol *</label>
              <select name="rol" class="form-select">
                <option value="tecnico">Técnico</option>
                <option value="vendedor">Vendedor</option>
                <option value="admin">Administrador</option>
              </select>
            </div>
            <div class="col-md-6"><label class="tr-form-label">Contraseña *</label><input type="password" name="password" class="form-control" minlength="6" required/></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary btn-sm">Crear usuario</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
