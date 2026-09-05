<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';
requireLogin();
// Este módulo lo maneja SOLO el administrador (no es configurable,
// para que nadie pueda quitarse el acceso a la propia pantalla de permisos).
requireRole([ROL_ADMIN]);
$db = getDB();

$URL      = BASE_URL . 'modules/permisos/index.php';
$ROLES    = getRolesSistema();
$MODULOS  = getModulosCatalogo();
// Roles configurables (el admin siempre tiene acceso total)
$EDITABLES = array_filter(array_keys($ROLES), fn($r) => $r !== ROL_ADMIN);

// ── GUARDAR MATRIZ ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'guardar') {
    $enviado = $_POST['perm'] ?? [];   // perm[rol][modulo] = 1
    $ins = $db->prepare(
        "INSERT INTO rol_permisos (rol, modulo, permitido) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE permitido = VALUES(permitido)"
    );
    $db->beginTransaction();
    try {
        foreach ($EDITABLES as $rol) {
            foreach (array_keys($MODULOS) as $mod) {
                $val = isset($enviado[$rol][$mod]) ? 1 : 0;
                $ins->execute([$rol, $mod, $val]);
            }
        }
        // El admin siempre con todo activo
        foreach (array_keys($MODULOS) as $mod) $ins->execute([ROL_ADMIN, $mod, 1]);
        $db->commit();
        setFlash('success', 'Permisos actualizados. Los usuarios verán los cambios al recargar.');
    } catch (\Throwable $e) {
        $db->rollBack();
        setFlash('danger', 'No se pudieron guardar los permisos: ' . $e->getMessage());
    }
    redirect($URL);
}

// ── RESTAURAR VALORES POR DEFECTO ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restaurar') {
    $def = permisosPorDefecto();
    $ins = $db->prepare(
        "INSERT INTO rol_permisos (rol, modulo, permitido) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE permitido = VALUES(permitido)"
    );
    $db->beginTransaction();
    try {
        foreach ($ROLES as $rol => $_) {
            foreach (array_keys($MODULOS) as $mod) {
                $ins->execute([$rol, $mod, in_array($mod, $def[$rol] ?? [], true) ? 1 : 0]);
            }
        }
        $db->commit();
        setFlash('success', 'Se restauraron los permisos por defecto.');
    } catch (\Throwable $e) {
        $db->rollBack();
        setFlash('danger', 'No se pudo restaurar: ' . $e->getMessage());
    }
    redirect($URL);
}

// ── ¿Existe la tabla? ───────────────────────────────────────
$tablaOk = true;
try { $db->query("SELECT 1 FROM rol_permisos LIMIT 1"); }
catch (\Throwable $e) { $tablaOk = false; }

// ── Cargar matriz actual ────────────────────────────────────
$actual = [];
foreach (array_keys($ROLES) as $rol) {
    $perms = getPermisosRol($rol);
    $def   = permisosPorDefecto()[$rol] ?? [];
    foreach (array_keys($MODULOS) as $mod) {
        $actual[$rol][$mod] = array_key_exists($mod, $perms)
            ? $perms[$mod]
            : in_array($mod, $def, true);
    }
}

// Agrupar módulos por grupo
$porGrupo = [];
foreach ($MODULOS as $clave => $m) $porGrupo[$m['grupo']][$clave] = $m;

$badgeRol = function(string $rol): string {
    $map = [ROL_ADMIN=>'danger', ROL_TECNICO=>'primary', ROL_VENDEDOR=>'success'];
    return $map[$rol] ?? 'secondary';
};

$pageTitle  = 'Permisos por perfil — ' . APP_NAME;
$breadcrumb = [['label'=>'Administración','url'=>null], ['label'=>'Permisos','url'=>null]];
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h5 class="fw-bold mb-0">Permisos por perfil</h5>
    <p class="text-muted small mb-0">Marca qué módulos puede ver y usar cada perfil.</p>
  </div>
  <a href="<?= BASE_URL ?>modules/usuarios/index.php" class="btn btn-outline-secondary btn-sm">
    <i data-feather="user-check" style="width:14px;height:14px"></i> Usuarios
  </a>
</div>

<?php if (!$tablaOk): ?>
<div class="alert alert-warning">
  <strong>Falta ejecutar la migración.</strong> La tabla <code>rol_permisos</code> todavía no existe,
  así que se están mostrando los permisos por defecto. Ejecuta el archivo
  <code>permisos.sql</code> en la base de datos para poder guardar cambios.
</div>
<?php endif; ?>

<div class="alert alert-info d-flex gap-2 align-items-start py-2">
  <i data-feather="info" style="width:16px;height:16px;flex-shrink:0;margin-top:2px"></i>
  <div class="small">
    El perfil <strong>Administrador</strong> siempre tiene acceso total y no se puede limitar —
    así nadie queda bloqueado fuera del sistema por error.
    El <strong>Dashboard</strong> tampoco se limita: es la pantalla de inicio de todos.
  </div>
</div>

<form method="POST">
  <input type="hidden" name="action" value="guardar"/>

  <div class="tr-card">
    <div class="tr-card-body p-0" style="overflow:hidden">
      <div class="table-responsive-wrapper" style="overflow-x:auto;-webkit-overflow-scrolling:touch">
        <table class="tr-table align-middle mb-0">
          <thead>
            <tr>
              <th style="min-width:230px">Módulo</th>
              <?php foreach ($ROLES as $rk => $rlabel): ?>
              <th class="text-center" style="min-width:120px">
                <span class="badge bg-<?= $badgeRol($rk) ?>"><?= $rlabel ?></span>
                <?php if ($rk !== ROL_ADMIN): ?>
                <div class="mt-1">
                  <button type="button" class="btn btn-link p-0 btn-col-all"
                          data-rol="<?= $rk ?>" data-val="1" style="font-size:10px;text-decoration:none">Todos</button>
                  <span class="text-muted" style="font-size:10px">/</span>
                  <button type="button" class="btn btn-link p-0 btn-col-all"
                          data-rol="<?= $rk ?>" data-val="0" style="font-size:10px;text-decoration:none">Ninguno</button>
                </div>
                <?php else: ?>
                <div class="text-muted mt-1" style="font-size:10px">Acceso total</div>
                <?php endif; ?>
              </th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($porGrupo as $grupo => $mods): ?>
            <tr class="table-light">
              <td colspan="<?= count($ROLES) + 1 ?>" class="fw-semibold small text-uppercase text-muted"
                  style="letter-spacing:.5px"><?= sanitize($grupo) ?></td>
            </tr>
              <?php foreach ($mods as $clave => $m): ?>
              <tr>
                <td>
                  <div class="d-flex align-items-center gap-2">
                    <i data-feather="<?= $m['icono'] ?>" style="width:15px;height:15px;color:#6b7280"></i>
                    <span class="small fw-semibold"><?= sanitize($m['label']) ?></span>
                  </div>
                </td>
                <?php foreach ($ROLES as $rk => $rlabel): ?>
                <td class="text-center">
                  <?php if ($rk === ROL_ADMIN): ?>
                    <input type="checkbox" class="form-check-input" checked disabled
                           title="El administrador siempre tiene acceso total"/>
                  <?php else: ?>
                    <input type="checkbox" class="form-check-input chk-perm"
                           name="perm[<?= $rk ?>][<?= $clave ?>]" value="1"
                           data-rol="<?= $rk ?>"
                           <?= !empty($actual[$rk][$clave]) ? 'checked' : '' ?>/>
                  <?php endif; ?>
                </td>
                <?php endforeach; ?>
              </tr>
              <?php endforeach; ?>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
    <button type="button" class="btn btn-outline-secondary btn-sm" id="btn-restaurar">
      <i data-feather="rotate-ccw" style="width:14px;height:14px"></i> Restaurar valores por defecto
    </button>
    <button type="submit" class="btn btn-primary" <?= $tablaOk ? '' : 'disabled' ?>>
      <i data-feather="save" style="width:15px;height:15px"></i> Guardar permisos
    </button>
  </div>
</form>

<!-- Form oculto para restaurar -->
<form method="POST" id="form-restaurar" class="d-none">
  <input type="hidden" name="action" value="restaurar"/>
</form>

<?php
$pageScripts = <<<'HTML'
<script>
// Botones "Todos / Ninguno" por columna
document.querySelectorAll('.btn-col-all').forEach(function(b){
  b.addEventListener('click', function(){
    var rol = b.dataset.rol, val = b.dataset.val === '1';
    document.querySelectorAll('.chk-perm[data-rol="'+rol+'"]').forEach(function(c){ c.checked = val; });
  });
});
// Restaurar por defecto
document.getElementById('btn-restaurar').addEventListener('click', function(){
  if (confirm('¿Restaurar los permisos originales de cada perfil?\n\nSe perderán los cambios personalizados.')) {
    document.getElementById('form-restaurar').submit();
  }
});
</script>
HTML;
require_once __DIR__ . '/../../includes/footer.php';
