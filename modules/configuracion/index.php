<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';
requireLogin();
requireRole([ROL_ADMIN]);
$db = getDB();

// ─── Guardar configuración ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'guardar') {
    $campos = [
        'empresa_nombre','empresa_ruc','empresa_direccion','empresa_telefono','empresa_email',
        'igv_porcentaje','garantia_defecto_dias','whatsapp_api_token','whatsapp_phone_id',
        'smtp_host','smtp_user','smtp_pass','smtp_port','moneda','moneda_simbolo',
        'sunat_usuario_sol','sunat_clave_sol','sunat_modo',
    ];
    foreach ($campos as $c) {
        if (isset($_POST[$c])) {
            $db->prepare("UPDATE configuracion SET valor=? WHERE clave=?")->execute([trim($_POST[$c]),$c]);
        }
    }

    // Guardar certificado si se envió
    if (!empty($_FILES['certificado']['tmp_name'])) {
        $certData = base64_encode(file_get_contents($_FILES['certificado']['tmp_name']));
        $db->prepare("UPDATE configuracion SET valor=? WHERE clave='sunat_certificado'")->execute([$certData]);
    }

    setFlash('success','Configuración guardada correctamente.');
    redirect(BASE_URL.'modules/configuracion/index.php');
}

// ─── Guardar series ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'guardar_series') {
    foreach (['factura','boleta'] as $tipo) {
        $serie   = trim($_POST["serie_$tipo"] ?? '');
        $activo  = isset($_POST["activo_$tipo"]) ? 1 : 0;
        $existe  = $db->query("SELECT id FROM documentos_empresa WHERE empresa_id=1 AND tipo='$tipo'")->fetch();
        if ($existe) {
            $db->prepare("UPDATE documentos_empresa SET serie=?, activo=? WHERE empresa_id=1 AND tipo=?")
               ->execute([$serie, $activo, $tipo]);
        } else {
            $db->prepare("INSERT INTO documentos_empresa (empresa_id,tipo,serie,numero,activo) VALUES (1,?,?,0,?)")
               ->execute([$tipo, $serie, $activo]);
        }
    }
    setFlash('success','Series guardadas.');
    redirect(BASE_URL.'modules/configuracion/index.php?tab=series');
}

// ─── Cargar datos ─────────────────────────────────────────────
$config = [];
foreach ($db->query("SELECT clave,valor FROM configuracion") as $r) $config[$r['clave']] = $r['valor'];

$series = [];
foreach ($db->query("SELECT tipo,serie,numero,activo FROM documentos_empresa WHERE empresa_id=1") as $s) {
    $series[$s['tipo']] = $s;
}

$tab = $_GET['tab'] ?? 'general';

function cf($key) { global $config; return sanitize($config[$key] ?? ''); }

$pageTitle  = 'Configuración — ' . APP_NAME;
$breadcrumb = [['label' => 'Configuración', 'url' => null]];

require_once __DIR__ . '/../../includes/header.php';
?>

<h5 class="fw-bold mb-3">Configuración del sistema</h5>

<ul class="nav nav-tabs mb-3" role="tablist">
  <li class="nav-item"><button class="nav-link <?= $tab==='general'?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#tab-general" type="button">General</button></li>
  <li class="nav-item"><button class="nav-link <?= $tab==='series'?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#tab-series" type="button">Series</button></li>
</ul>

<div class="tab-content">

<!-- ── TAB GENERAL ── -->
<div class="tab-pane fade <?= $tab==='general'?'show active':'' ?>" id="tab-general">
<form method="POST" enctype="multipart/form-data">
<input type="hidden" name="action" value="guardar">
<div class="row g-3">
  <div class="col-lg-6">
    <div class="tr-card mb-3">
      <div class="tr-card-header"><h6 class="mb-0 small fw-semibold">DATOS DE LA EMPRESA</h6></div>
      <div class="tr-card-body">
        <div class="row g-2">
          <div class="col-12"><label class="tr-form-label">Nombre de la empresa</label><input type="text" name="empresa_nombre" class="form-control" value="<?= cf('empresa_nombre') ?>"/></div>
          <div class="col-md-6"><label class="tr-form-label">RUC</label><input type="text" name="empresa_ruc" class="form-control" value="<?= cf('empresa_ruc') ?>" maxlength="11"/></div>
          <div class="col-md-6"><label class="tr-form-label">Teléfono</label><input type="text" name="empresa_telefono" class="form-control" value="<?= cf('empresa_telefono') ?>"/></div>
          <div class="col-12"><label class="tr-form-label">Dirección</label><input type="text" name="empresa_direccion" class="form-control" value="<?= cf('empresa_direccion') ?>"/></div>
          <div class="col-12"><label class="tr-form-label">Email</label><input type="email" name="empresa_email" class="form-control" value="<?= cf('empresa_email') ?>"/></div>
        </div>
      </div>
    </div>
    <div class="tr-card mb-3">
      <div class="tr-card-header"><h6 class="mb-0 small fw-semibold">FACTURACIÓN</h6></div>
      <div class="tr-card-body">
        <div class="row g-2">
          <div class="col-md-4"><label class="tr-form-label">IGV (%)</label><input type="number" name="igv_porcentaje" class="form-control" value="<?= cf('igv_porcentaje') ?>"/></div>
          <div class="col-md-4"><label class="tr-form-label">Moneda</label><input type="text" name="moneda" class="form-control" value="<?= cf('moneda') ?>"/></div>
          <div class="col-md-4"><label class="tr-form-label">Símbolo</label><input type="text" name="moneda_simbolo" class="form-control" value="<?= cf('moneda_simbolo') ?>"/></div>
          <div class="col-md-6"><label class="tr-form-label">Garantía por defecto (días)</label><input type="number" name="garantia_defecto_dias" class="form-control" value="<?= cf('garantia_defecto_dias') ?>"/></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="tr-card mb-3">
      <div class="tr-card-header">
        <h6 class="mb-0 small fw-semibold">SUNAT</h6>
        <span class="badge bg-<?= (cf('sunat_modo') === 'produccion') ? 'success' : 'warning text-dark' ?>"><?= (cf('sunat_modo') === 'produccion') ? '🚀 Producción' : '🧪 Beta' ?></span>
      </div>
      <div class="tr-card-body">
        <div class="mb-2">
          <label class="tr-form-label">Modo</label>
          <select name="sunat_modo" class="form-select form-select-sm">
            <option value="beta" <?= cf('sunat_modo') !== 'produccion' ? 'selected' : '' ?>>🧪 Beta (pruebas)</option>
            <option value="produccion" <?= cf('sunat_modo') === 'produccion' ? 'selected' : '' ?>>🚀 Producción</option>
          </select>
        </div>
        <div class="mb-2"><label class="tr-form-label">Usuario SOL</label><input type="text" name="sunat_usuario_sol" class="form-control form-control-sm" value="<?= cf('sunat_usuario_sol') ?>"/></div>
        <div class="mb-2"><label class="tr-form-label">Contraseña SOL</label><input type="password" name="sunat_clave_sol" class="form-control form-control-sm" value="<?= cf('sunat_clave_sol') ?>" placeholder="••••••"/></div>
        <?php $cert = cf('sunat_certificado'); ?>
        <?php if (!empty($cert) && strlen($cert) > 100): ?>
          <div class="alert alert-success py-1 small"><i data-feather="check-circle" style="width:13px"></i> Certificado cargado</div>
        <?php else: ?>
          <div class="alert alert-warning py-1 small"><i data-feather="alert-triangle" style="width:13px"></i> Sin certificado — la facturación no funcionará</div>
        <?php endif; ?>
        <div class="mb-2"><label class="tr-form-label">Certificado (.pem)</label><input type="file" name="certificado" class="form-control form-control-sm" accept=".pem"/></div>
      </div>
    </div>
    <div class="tr-card mb-3">
      <div class="tr-card-header">
        <h6 class="mb-0 small fw-semibold">WHATSAPP BUSINESS API</h6>
        <span class="badge bg-warning text-dark">Meta / 360Dialog</span>
      </div>
      <div class="tr-card-body">
        <div class="alert alert-info small py-2">Configura tu token de WhatsApp Business API para enviar notificaciones automáticas.</div>
        <div class="mb-2"><label class="tr-form-label">API Token</label><input type="text" name="whatsapp_api_token" class="form-control form-control-sm" value="<?= cf('whatsapp_api_token') ?>" placeholder="EAAxxxxxx..."/></div>
        <div class="mb-2"><label class="tr-form-label">Phone Number ID</label><input type="text" name="whatsapp_phone_id" class="form-control form-control-sm" value="<?= cf('whatsapp_phone_id') ?>"/></div>
      </div>
    </div>
    <div class="tr-card mb-3">
      <div class="tr-card-header"><h6 class="mb-0 small fw-semibold">CORREO SMTP</h6></div>
      <div class="tr-card-body">
        <div class="row g-2">
          <div class="col-md-8"><label class="tr-form-label">Host SMTP</label><input type="text" name="smtp_host" class="form-control form-control-sm" value="<?= cf('smtp_host') ?>" placeholder="smtp.gmail.com"/></div>
          <div class="col-md-4"><label class="tr-form-label">Puerto</label><input type="text" name="smtp_port" class="form-control form-control-sm" value="<?= cf('smtp_port') ?>"/></div>
          <div class="col-md-6"><label class="tr-form-label">Usuario</label><input type="text" name="smtp_user" class="form-control form-control-sm" value="<?= cf('smtp_user') ?>"/></div>
          <div class="col-md-6"><label class="tr-form-label">Contraseña</label><input type="password" name="smtp_pass" class="form-control form-control-sm" placeholder="••••••••"/></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-12">
    <button type="submit" class="btn btn-primary"><i data-feather="save" style="width:15px;height:15px"></i> Guardar configuración</button>
    <a href="?tab=series" class="btn btn-outline-secondary">Configurar series →</a>
  </div>
</div>
</form>
</div>

<!-- ── TAB SERIES ── -->
<div class="tab-pane fade <?= $tab==='series'?'show active':'' ?>" id="tab-series">
<form method="POST">
<input type="hidden" name="action" value="guardar_series">
<div class="row g-3">
  <div class="col-md-6">
    <div class="tr-card mb-3">
      <div class="tr-card-header"><h6 class="mb-0 small fw-semibold">SERIES Y CORRELATIVOS</h6></div>
      <div class="tr-card-body">
        <div class="mb-3">
          <label class="tr-form-label">Serie Factura</label>
          <div class="d-flex gap-2">
            <input type="text" name="serie_factura" class="form-control" value="<?= sanitize($series['factura']['serie'] ?? 'F001') ?>" maxlength="4" style="width:100px"/>
            <div class="form-check">
              <input type="checkbox" class="form-check-input" name="activo_factura" id="chk-fact" value="1" <?= ($series['factura']['activo'] ?? 1) ? 'checked' : '' ?>/>
              <label class="form-check-label" for="chk-fact">Activa</label>
            </div>
          </div>
          <small class="text-muted">Correlativo actual: <?= (int)($series['factura']['numero'] ?? 0) ?></small>
        </div>
        <div class="mb-3">
          <label class="tr-form-label">Serie Boleta</label>
          <div class="d-flex gap-2">
            <input type="text" name="serie_boleta" class="form-control" value="<?= sanitize($series['boleta']['serie'] ?? 'B001') ?>" maxlength="4" style="width:100px"/>
            <div class="form-check">
              <input type="checkbox" class="form-check-input" name="activo_boleta" id="chk-bol" value="1" <?= ($series['boleta']['activo'] ?? 1) ? 'checked' : '' ?>/>
              <label class="form-check-label" for="chk-bol">Activa</label>
            </div>
          </div>
          <small class="text-muted">Correlativo actual: <?= (int)($series['boleta']['numero'] ?? 0) ?></small>
        </div>
      </div>
    </div>
  </div>
  <div class="col-12">
    <button type="submit" class="btn btn-primary"><i data-feather="save" style="width:15px;height:15px"></i> Guardar series</button>
    <a href="?tab=general" class="btn btn-outline-secondary">← General</a>
  </div>
</div>
</form>
</div>

</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>