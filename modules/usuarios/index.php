<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';
requireLogin();
requierePermiso('usuarios');
$db   = getDB();
$user = currentUser();

$ROLES = getRolesSistema();
$URL   = BASE_URL . 'modules/usuarios/index.php';

/** Cuenta administradores activos (para no dejar el sistema sin admin) */
function adminsActivos(PDO $db, int $excluirId = 0): int {
    $st = $db->prepare("SELECT COUNT(*) FROM usuarios WHERE rol='admin' AND activo=1 AND id <> ?");
    $st->execute([$excluirId]);
    return (int)$st->fetchColumn();
}

// ── CREAR ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'crear') {
    $nombre   = trim($_POST['nombre']   ?? '');
    $apellido = trim($_POST['apellido'] ?? '');
    $email    = trim($_POST['email']    ?? '');
    $rol      = $_POST['rol'] ?? ROL_TECNICO;
    $pass     = (string)($_POST['password'] ?? '');

    if (!$nombre || !$apellido || !$email || !$pass) {
        setFlash('danger', 'Completa todos los campos obligatorios.');
        redirect($URL);
    }
    if (!isset($ROLES[$rol]))      { setFlash('danger', 'Perfil inválido.');                    redirect($URL); }
    if (strlen($pass) < 6)         { setFlash('danger', 'La contraseña debe tener al menos 6 caracteres.'); redirect($URL); }

    try {
        $db->prepare("INSERT INTO usuarios (nombre,apellido,email,password_hash,rol,telefono) VALUES (?,?,?,?,?,?)")
           ->execute([$nombre, $apellido, $email, password_hash($pass, PASSWORD_BCRYPT), $rol, trim($_POST['telefono'] ?? '')]);
        setFlash('success', 'Usuario "' . $nombre . ' ' . $apellido . '" creado como ' . $ROLES[$rol] . '.');
    } catch (\PDOException $e) {
        setFlash('danger', ((int)$e->getCode() === 23000)
            ? 'Ese correo ya está registrado en otro usuario.'
            : 'No se pudo crear el usuario.');
    }
    redirect($URL);
}

// ── EDITAR ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'editar') {
    $id       = (int)($_POST['id'] ?? 0);
    $nombre   = trim($_POST['nombre']   ?? '');
    $apellido = trim($_POST['apellido'] ?? '');
    $email    = trim($_POST['email']    ?? '');
    $rol      = $_POST['rol'] ?? ROL_TECNICO;

    if (!$id || !$nombre || !$apellido || !$email) {
        setFlash('danger', 'Completa todos los campos obligatorios.');
        redirect($URL);
    }
    if (!isset($ROLES[$rol])) { setFlash('danger', 'Perfil inválido.'); redirect($URL); }

    // Protección: no quitarse a uno mismo el perfil de administrador
    if ($id === (int)$user['id'] && $rol !== ROL_ADMIN && $user['rol'] === ROL_ADMIN) {
        setFlash('danger', 'No puedes quitarte a ti mismo el perfil de Administrador.');
        redirect($URL);
    }
    // Protección: no dejar el sistema sin ningún administrador activo
    $stAnt = $db->prepare("SELECT rol, activo FROM usuarios WHERE id=?");
    $stAnt->execute([$id]);
    $ant = $stAnt->fetch();
    if ($ant && $ant['rol'] === ROL_ADMIN && $rol !== ROL_ADMIN && adminsActivos($db, $id) === 0) {
        setFlash('danger', 'No se puede cambiar el perfil: es el único administrador activo.');
        redirect($URL);
    }

    try {
        $db->prepare("UPDATE usuarios SET nombre=?, apellido=?, email=?, rol=?, telefono=? WHERE id=?")
           ->execute([$nombre, $apellido, $email, $rol, trim($_POST['telefono'] ?? ''), $id]);
        // Si se editó a sí mismo, refrescar sesión
        if ($id === (int)$user['id']) {
            $_SESSION['user_nombre'] = $nombre;
            $_SESSION['user_email']  = $email;
            $_SESSION['user_rol']    = $rol;
        }
        setFlash('success', 'Usuario actualizado.');
    } catch (\PDOException $e) {
        setFlash('danger', ((int)$e->getCode() === 23000)
            ? 'Ese correo ya está registrado en otro usuario.'
            : 'No se pudo actualizar el usuario.');
    }
    redirect($URL);
}

// ── RESETEAR CONTRASEÑA ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'password') {
    $id   = (int)($_POST['id'] ?? 0);
    $pass = (string)($_POST['password'] ?? '');
    if (!$id || strlen($pass) < 6) {
        setFlash('danger', 'La contraseña debe tener al menos 6 caracteres.');
        redirect($URL);
    }
    $db->prepare("UPDATE usuarios SET password_hash=? WHERE id=?")
       ->execute([password_hash($pass, PASSWORD_BCRYPT), $id]);
    setFlash('success', 'Contraseña actualizada correctamente.');
    redirect($URL);
}

// ── ACTIVAR / DESACTIVAR ────────────────────────────────────
if (isset($_GET['toggle'])) {
    $uid = (int)$_GET['toggle'];
    if ($uid === (int)$user['id']) {
        setFlash('danger', 'No puedes desactivar tu propio usuario.');
        redirect($URL);
    }
    $st = $db->prepare("SELECT rol, activo FROM usuarios WHERE id=?");
    $st->execute([$uid]);
    $obj = $st->fetch();
    if ($obj && $obj['rol'] === ROL_ADMIN && (int)$obj['activo'] === 1 && adminsActivos($db, $uid) === 0) {
        setFlash('danger', 'No se puede desactivar: es el único administrador activo.');
        redirect($URL);
    }
    $db->prepare("UPDATE usuarios SET activo = 1-activo WHERE id=? AND id <> ?")->execute([$uid, $user['id']]);
    setFlash('success', 'Estado del usuario actualizado.');
    redirect($URL);
}

// ── LISTADO ─────────────────────────────────────────────────
$filtroRol = $_GET['rol'] ?? '';
$sql = "SELECT u.*,
          (SELECT COUNT(*) FROM ordenes_trabajo WHERE tecnico_id=u.id) AS total_ots,
          (SELECT COUNT(*) FROM ordenes_trabajo WHERE tecnico_id=u.id AND estado='entregado') AS ots_completadas
        FROM usuarios u";
$params = [];
if ($filtroRol && isset($ROLES[$filtroRol])) { $sql .= " WHERE u.rol = ?"; $params[] = $filtroRol; }
$sql .= " ORDER BY u.activo DESC, u.nombre";
$st = $db->prepare($sql); $st->execute($params);
$usuarios = $st->fetchAll();

// Conteo por perfil (tarjetas resumen)
$conteo = [];
foreach ($db->query("SELECT rol, COUNT(*) c FROM usuarios WHERE activo=1 GROUP BY rol")->fetchAll() as $r) {
    $conteo[$r['rol']] = (int)$r['c'];
}

$badgeRol = function(string $rol): string {
    $map = [ROL_ADMIN=>'danger', ROL_TECNICO=>'primary', ROL_VENDEDOR=>'success'];
    return $map[$rol] ?? 'secondary';
};

$pageTitle  = 'Usuarios — ' . APP_NAME;
$breadcrumb = [['label'=>'Administración','url'=>null], ['label'=>'Usuarios','url'=>null]];
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h5 class="fw-bold mb-0">Usuarios del sistema</h5>
    <p class="text-muted small mb-0">Crea usuarios y asígnales un perfil (Administrador, Técnico o Vendedor).</p>
  </div>
  <div class="d-flex gap-2">
    <a href="<?= BASE_URL ?>modules/permisos/index.php" class="btn btn-outline-secondary btn-sm">
      <i data-feather="lock" style="width:14px;height:14px"></i> Permisos por perfil
    </a>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modal-nuevo">
      <i data-feather="user-plus" style="width:14px;height:14px"></i> Nuevo usuario
    </button>
  </div>
</div>

<!-- Resumen por perfil -->
<div class="row g-2 mb-3">
  <?php foreach ($ROLES as $rk => $rlabel): ?>
  <div class="col-6 col-md-4">
    <a href="?rol=<?= $rk ?>" class="text-decoration-none">
      <div class="tr-card h-100 <?= $filtroRol===$rk?'border-primary':'' ?>">
        <div class="tr-card-body d-flex align-items-center gap-3 py-3">
          <span class="badge bg-<?= $badgeRol($rk) ?>" style="width:10px;height:10px;padding:0;border-radius:50%"></span>
          <div>
            <div class="fw-bold" style="font-size:1.3rem;line-height:1"><?= $conteo[$rk] ?? 0 ?></div>
            <div class="text-muted small"><?= $rlabel ?><?= ($conteo[$rk] ?? 0)===1?'':'es' ?> activo<?= ($conteo[$rk] ?? 0)===1?'':'s' ?></div>
          </div>
        </div>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<?php if ($filtroRol): ?>
<div class="mb-2">
  <a href="<?= $URL ?>" class="btn btn-sm btn-outline-secondary">
    <i data-feather="x" style="width:13px;height:13px"></i> Quitar filtro: <?= $ROLES[$filtroRol] ?? '' ?>
  </a>
</div>
<?php endif; ?>

<div class="tr-card">
  <div class="tr-card-body p-0" style="overflow:hidden">
    <div class="table-responsive-wrapper" style="overflow-x:auto;-webkit-overflow-scrolling:touch">
      <table class="tr-table">
        <thead>
          <tr>
            <th>Usuario</th><th>Perfil</th><th>Email</th><th>Teléfono</th>
            <th class="text-center">OTs</th><th class="text-center">Completadas</th>
            <th>Último acceso</th><th>Estado</th><th style="min-width:190px"></th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$usuarios): ?>
          <tr><td colspan="9" class="text-center text-muted py-4">No hay usuarios que mostrar.</td></tr>
        <?php endif; ?>
        <?php foreach ($usuarios as $us): ?>
          <tr class="<?= !$us['activo'] ? 'opacity-50' : '' ?>">
            <td>
              <div class="d-flex align-items-center gap-2">
                <div class="tr-avatar" style="width:30px;height:30px;font-size:12px"><?= strtoupper(substr($us['nombre'],0,1)) ?></div>
                <div>
                  <div class="fw-semibold small"><?= sanitize($us['nombre'].' '.$us['apellido']) ?></div>
                  <?php if ((int)$us['id'] === (int)$user['id']): ?>
                    <div class="text-muted" style="font-size:10px">Tú</div>
                  <?php endif; ?>
                </div>
              </div>
            </td>
            <td><span class="badge bg-<?= $badgeRol($us['rol']) ?>"><?= $ROLES[$us['rol']] ?? ucfirst($us['rol']) ?></span></td>
            <td class="small"><?= sanitize($us['email']) ?></td>
            <td class="small"><?= sanitize($us['telefono'] ?: '—') ?></td>
            <td class="text-center"><?= $us['total_ots'] ?></td>
            <td class="text-center text-success fw-semibold"><?= $us['ots_completadas'] ?></td>
            <td class="small text-muted"><?= $us['ultimo_acceso'] ? formatDateTime($us['ultimo_acceso']) : 'Nunca' ?></td>
            <td><span class="badge bg-<?= $us['activo'] ? 'success' : 'secondary' ?>"><?= $us['activo'] ? 'Activo' : 'Inactivo' ?></span></td>
            <td>
              <div class="d-flex gap-1">
                <button class="btn btn-sm btn-outline-primary btn-editar"
                        data-id="<?= $us['id'] ?>"
                        data-nombre="<?= sanitize($us['nombre']) ?>"
                        data-apellido="<?= sanitize($us['apellido']) ?>"
                        data-email="<?= sanitize($us['email']) ?>"
                        data-telefono="<?= sanitize($us['telefono'] ?? '') ?>"
                        data-rol="<?= $us['rol'] ?>"
                        title="Editar">
                  <i data-feather="edit-2" style="width:13px;height:13px"></i>
                </button>
                <button class="btn btn-sm btn-outline-secondary btn-pass"
                        data-id="<?= $us['id'] ?>"
                        data-nombre="<?= sanitize($us['nombre'].' '.$us['apellido']) ?>"
                        title="Cambiar contraseña">
                  <i data-feather="key" style="width:13px;height:13px"></i>
                </button>
                <?php if ((int)$us['id'] !== (int)$user['id']): ?>
                <a href="?toggle=<?= $us['id'] ?><?= $filtroRol?'&rol='.$filtroRol:'' ?>"
                   class="btn btn-sm btn-outline-<?= $us['activo'] ? 'danger' : 'success' ?>"
                   onclick="return confirm('¿<?= $us['activo'] ? 'Desactivar' : 'Activar' ?> a <?= sanitize($us['nombre']) ?>?');">
                  <?= $us['activo'] ? 'Desactivar' : 'Activar' ?>
                </a>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ── Modal: nuevo usuario ── -->
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
              <label class="tr-form-label">Perfil *</label>
              <select name="rol" class="form-select">
                <?php foreach ($ROLES as $rk => $rlabel): ?>
                <option value="<?= $rk ?>" <?= $rk===ROL_TECNICO?'selected':'' ?>><?= $rlabel ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6"><label class="tr-form-label">Contraseña *</label><input type="password" name="password" class="form-control" minlength="6" required/></div>
          </div>
          <p class="text-muted small mb-0 mt-3">
            <i data-feather="info" style="width:12px;height:12px"></i>
            Lo que puede ver cada perfil se define en
            <a href="<?= BASE_URL ?>modules/permisos/index.php">Permisos por perfil</a>.
          </p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary btn-sm">Crear usuario</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Modal: editar usuario ── -->
<div class="modal fade" id="modal-editar" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" value="editar"/>
        <input type="hidden" name="id" id="e-id"/>
        <div class="modal-header">
          <h6 class="modal-title fw-bold">Editar usuario</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-2">
            <div class="col-md-6"><label class="tr-form-label">Nombre *</label><input type="text" name="nombre" id="e-nombre" class="form-control" required/></div>
            <div class="col-md-6"><label class="tr-form-label">Apellido *</label><input type="text" name="apellido" id="e-apellido" class="form-control" required/></div>
            <div class="col-md-6"><label class="tr-form-label">Email *</label><input type="email" name="email" id="e-email" class="form-control" required/></div>
            <div class="col-md-6"><label class="tr-form-label">Teléfono</label><input type="text" name="telefono" id="e-telefono" class="form-control"/></div>
            <div class="col-md-6">
              <label class="tr-form-label">Perfil *</label>
              <select name="rol" id="e-rol" class="form-select">
                <?php foreach ($ROLES as $rk => $rlabel): ?>
                <option value="<?= $rk ?>"><?= $rlabel ?></option>
                <?php endforeach; ?>
              </select>
            </div>
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

<!-- ── Modal: cambiar contraseña ── -->
<div class="modal fade" id="modal-pass" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" value="password"/>
        <input type="hidden" name="id" id="p-id"/>
        <div class="modal-header">
          <h6 class="modal-title fw-bold">Cambiar contraseña</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="small text-muted mb-2">Usuario: <strong id="p-nombre"></strong></p>
          <label class="tr-form-label">Nueva contraseña *</label>
          <input type="password" name="password" class="form-control" minlength="6" required/>
          <p class="text-muted mb-0 mt-2" style="font-size:11px">Mínimo 6 caracteres.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary btn-sm">Actualizar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$pageScripts = <<<'HTML'
<script>
document.querySelectorAll('.btn-editar').forEach(function(b){
  b.addEventListener('click', function(){
    document.getElementById('e-id').value       = b.dataset.id;
    document.getElementById('e-nombre').value   = b.dataset.nombre;
    document.getElementById('e-apellido').value = b.dataset.apellido;
    document.getElementById('e-email').value    = b.dataset.email;
    document.getElementById('e-telefono').value = b.dataset.telefono || '';
    document.getElementById('e-rol').value      = b.dataset.rol;
    new bootstrap.Modal(document.getElementById('modal-editar')).show();
  });
});
document.querySelectorAll('.btn-pass').forEach(function(b){
  b.addEventListener('click', function(){
    document.getElementById('p-id').value          = b.dataset.id;
    document.getElementById('p-nombre').textContent = b.dataset.nombre;
    new bootstrap.Modal(document.getElementById('modal-pass')).show();
  });
});
</script>
HTML;
require_once __DIR__ . '/../../includes/footer.php';
