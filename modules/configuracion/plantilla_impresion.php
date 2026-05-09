<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';
requireLogin();
requireRole([ROL_ADMIN]);
$db = getDB();

// Cargar config
$rows = $db->query("SELECT clave, valor FROM configuracion WHERE grupo IN ('impresion','empresa')")->fetchAll();
$cfg  = []; foreach ($rows as $r) $cfg[$r['clave']] = $r['valor'];
function cv($k, $cfg) { return htmlspecialchars($cfg[$k] ?? ''); }
function cb($k, $cfg) { return ($cfg[$k] ?? '1') === '1'; }

// Guardar
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $campos = [
        'print_cabecera','print_cuentas','print_msg_inferior','print_despedida',
        'print_mostrar_logo','print_mostrar_qr',
        'print_mostrar_cabecera','print_mostrar_inferior','print_mostrar_despedida',
    ];
    foreach ($campos as $campo) {
        $val = isset($_POST[$campo]) ? (in_array($campo, ['print_mostrar_logo','print_mostrar_qr','print_mostrar_cabecera','print_mostrar_inferior','print_mostrar_despedida']) ? '1' : trim($_POST[$campo])) : '0';
        $chk = $db->prepare("SELECT COUNT(*) FROM configuracion WHERE clave=?");
        $chk->execute([$campo]);
        if ($chk->fetchColumn()) {
            $db->prepare("UPDATE configuracion SET valor=? WHERE clave=?")->execute([$val, $campo]);
        } else {
            $db->prepare("INSERT INTO configuracion (clave,valor,tipo,grupo) VALUES (?,?,?,?)")
               ->execute([$campo, $val, 'texto', 'impresion']);
        }
    }

    // Subir logo
    if (!empty($_FILES['logo_file']['name']) && $_FILES['logo_file']['error']===0) {
        $dir = UPLOAD_PATH . 'logos/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $ext  = strtolower(pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['png','jpg','jpeg','webp','svg'])) {
            $fname = 'logo_empresa.' . $ext;
            if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $dir . $fname)) {
                $chk2 = $db->prepare("SELECT COUNT(*) FROM configuracion WHERE clave='print_logo'");
                $chk2->execute();
                if ($chk2->fetchColumn()) {
                    $db->prepare("UPDATE configuracion SET valor=? WHERE clave='print_logo'")->execute(['logos/'.$fname]);
                } else {
                    $db->prepare("INSERT INTO configuracion(clave,valor,tipo,grupo) VALUES('print_logo',?,'texto','impresion')")->execute(['logos/'.$fname]);
                }
            }
        }
    }

    setFlash('success', 'Plantilla de impresión guardada.');
    redirect(BASE_URL . 'modules/configuracion/plantilla_impresion.php');
}

// Reload after save
$rows = $db->query("SELECT clave, valor FROM configuracion WHERE grupo IN ('impresion','empresa')")->fetchAll();
$cfg  = []; foreach ($rows as $r) $cfg[$r['clave']] = $r['valor'];

$logoPath = $cfg['print_logo'] ?? '';
$logoUrl  = $logoPath ? UPLOAD_URL . $logoPath : '';

$pageTitle  = 'Plantilla de impresión — ' . APP_NAME;
$breadcrumb = [
    ['label'=>'Configuración','url'=>BASE_URL.'modules/configuracion/index.php'],
    ['label'=>'Plantilla de impresión','url'=>null],
];
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h5 class="fw-bold mb-0">🖨 Plantilla de impresión</h5>
  <a href="<?= BASE_URL ?>modules/ot/pdf.php?id=preview" target="_blank"
     class="btn btn-outline-secondary btn-sm">
    <i data-feather="external-link" style="width:14px;height:14px"></i> Abrir vista previa real
  </a>
</div>

<div class="row g-3">
<!-- ══ FORMULARIO ══ -->
<div class="col-lg-5">
<form method="POST" enctype="multipart/form-data" id="form-plantilla">

  <!-- Logo -->
  <div class="tr-card mb-3">
    <div class="tr-card-header">
      <span style="font-size:18px">🏢</span>
      <h6 class="mb-0 small fw-semibold ms-2">Datos de la empresa</h6>
    </div>
    <div class="tr-card-body">
      <div class="row g-2 mb-3">
        <div class="col-12">
          <label class="tr-form-label">RAZÓN SOCIAL / NOMBRE</label>
          <input type="text" class="form-control" value="<?= cv('empresa_nombre',$cfg) ?>" readonly
                 style="background:#f9fafb" title="Editar en Configuración → Datos de la empresa"/>
          <div class="form-text">Para cambiar, ve a <a href="<?= BASE_URL ?>modules/configuracion/index.php">Configuración general</a></div>
        </div>
        <div class="col-md-6">
          <label class="tr-form-label">RUC</label>
          <input type="text" class="form-control" value="<?= cv('empresa_ruc',$cfg) ?>" readonly style="background:#f9fafb"/>
        </div>
        <div class="col-md-6">
          <label class="tr-form-label">TELÉFONO</label>
          <input type="text" class="form-control" value="<?= cv('empresa_telefono',$cfg) ?>" readonly style="background:#f9fafb"/>
        </div>
        <div class="col-12">
          <label class="tr-form-label">DIRECCIÓN</label>
          <input type="text" class="form-control" value="<?= cv('empresa_direccion',$cfg) ?>" readonly style="background:#f9fafb"/>
        </div>
        <div class="col-12">
          <label class="tr-form-label">EMAIL</label>
          <input type="text" class="form-control" value="<?= cv('empresa_email',$cfg) ?>" readonly style="background:#f9fafb"/>
        </div>
      </div>

      <div class="mb-3">
        <label class="tr-form-label">LOGO DE LA EMPRESA</label>
        <div class="d-flex align-items-center gap-3 mb-2">
          <div style="width:72px;height:72px;border:1px solid #e5e7eb;border-radius:8px;
                      display:flex;align-items:center;justify-content:center;background:#f9fafb;overflow:hidden">
            <?php if($logoUrl): ?>
            <img src="<?= $logoUrl ?>" id="logo-preview" style="max-width:100%;max-height:100%;object-fit:contain"/>
            <?php else: ?>
            <span id="logo-preview-ph" style="font-size:10px;color:#9ca3af;text-align:center">Logo</span>
            <?php endif; ?>
          </div>
          <div>
            <label class="btn btn-outline-secondary btn-sm" for="logo_file">
              <i data-feather="upload" style="width:13px;height:13px"></i> Subir logo
            </label>
            <input type="file" name="logo_file" id="logo_file" accept="image/*" style="display:none"
                   onchange="previewLogo(this)"/>
            <div class="form-text mt-1">PNG, JPG · Máx 2MB · Recomendado: fondo blanco</div>
          </div>
        </div>
        <div class="d-flex gap-3">
          <div class="form-check">
            <input type="checkbox" class="form-check-input" name="print_mostrar_logo"
                   id="chk-logo" <?= cb('print_mostrar_logo',$cfg)?'checked':'' ?>
                   onchange="actualizarPreview()"/>
            <label class="form-check-label small" for="chk-logo">Mostrar logo en comprobante</label>
          </div>
          <div class="form-check">
            <input type="checkbox" class="form-check-input" name="print_mostrar_qr"
                   id="chk-qr" <?= cb('print_mostrar_qr',$cfg)?'checked':'' ?>
                   onchange="actualizarPreview()"/>
            <label class="form-check-label small" for="chk-qr">Mostrar código QR</label>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Cabecera -->
  <div class="tr-card mb-3">
    <div class="tr-card-header">
      <span style="font-size:16px">🔤</span>
      <h6 class="mb-0 small fw-semibold ms-2">Mensaje de Cabecera</h6>
      <div class="ms-auto form-check mb-0">
        <input type="checkbox" class="form-check-input" name="print_mostrar_cabecera"
               id="chk-cab" <?= cb('print_mostrar_cabecera',$cfg)?'checked':'' ?>
               onchange="actualizarPreview()"/>
        <label class="form-check-label small" for="chk-cab">Activo</label>
      </div>
    </div>
    <div class="tr-card-body">
      <p class="text-muted small mb-2">Aparece debajo del logo, antes de los datos del cliente.
         Puedes usar texto plano o HTML básico (&lt;b&gt;, &lt;br&gt;, &lt;span style&gt;)</p>
      <textarea name="print_cabecera" class="form-control" rows="4"
                placeholder="Ej: Servicio técnico especializado | Atención Lun-Sab 9am-7pm"
                oninput="actualizarPreview()"><?= cv('print_cabecera',$cfg) ?></textarea>
    </div>
  </div>

  <!-- Cuentas bancarias -->
  <div class="tr-card mb-3">
    <div class="tr-card-header">
      <span style="font-size:16px">🏛</span>
      <h6 class="mb-0 small fw-semibold ms-2">Cuentas Bancarias</h6>
    </div>
    <div class="tr-card-body">
      <p class="text-muted small mb-2">Aparece en la parte inferior izquierda. Una cuenta por línea.</p>
      <textarea name="print_cuentas" class="form-control" rows="4"
                placeholder="BCP SOLES 191-XXXXXXXX-0-XX&#10;INTERBANK SOLES 200-XXXXXXXX-XX"
                oninput="actualizarPreview()"><?= cv('print_cuentas',$cfg) ?></textarea>
    </div>
  </div>

  <!-- Mensaje inferior -->
  <div class="tr-card mb-3">
    <div class="tr-card-header">
      <span style="font-size:16px">📝</span>
      <h6 class="mb-0 small fw-semibold ms-2">Mensaje Inferior</h6>
      <div class="ms-auto form-check mb-0">
        <input type="checkbox" class="form-check-input" name="print_mostrar_inferior"
               id="chk-inf" <?= cb('print_mostrar_inferior',$cfg)?'checked':'' ?>
               onchange="actualizarPreview()"/>
        <label class="form-check-label small" for="chk-inf">Activo</label>
      </div>
    </div>
    <div class="tr-card-body">
      <p class="text-muted small mb-2">Texto que aparece debajo del total</p>
      <textarea name="print_msg_inferior" class="form-control" rows="3"
                placeholder="Ej: La garantía no cubre daños por líquidos o golpes."
                oninput="actualizarPreview()"><?= cv('print_msg_inferior',$cfg) ?></textarea>
    </div>
  </div>

  <!-- Despedida -->
  <div class="tr-card mb-3">
    <div class="tr-card-header">
      <span style="font-size:16px">👋</span>
      <h6 class="mb-0 small fw-semibold ms-2">Mensaje de Despedida</h6>
      <div class="ms-auto form-check mb-0">
        <input type="checkbox" class="form-check-input" name="print_mostrar_despedida"
               id="chk-des" <?= cb('print_mostrar_despedida',$cfg)?'checked':'' ?>
               onchange="actualizarPreview()"/>
        <label class="form-check-label small" for="chk-des">Activo</label>
      </div>
    </div>
    <div class="tr-card-body">
      <p class="text-muted small mb-2">Aparece al final del comprobante (en mayúsculas)</p>
      <input type="text" name="print_despedida" class="form-control"
             value="<?= cv('print_despedida',$cfg) ?>"
             placeholder="¡Gracias por su preferencia!"
             oninput="actualizarPreview()"/>
    </div>
  </div>

  <button type="submit" class="btn btn-primary w-100 btn-lg">
    <i data-feather="save" style="width:16px;height:16px"></i> Guardar cambios
  </button>
</form>
</div>

<!-- ══ PREVIEW ══ -->
<div class="col-lg-7">
  <div class="tr-card" style="position:sticky;top:70px">
    <div class="tr-card-header">
      <span style="font-size:16px">👁</span>
      <h6 class="mb-0 small fw-semibold ms-2">Vista previa del comprobante</h6>
      <div class="ms-auto d-flex gap-2">
        <button type="button" class="btn btn-sm btn-primary" id="tab-a4" onclick="setTab('a4')">A4</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="tab-80" onclick="setTab('80')">Voucher 80mm</button>
      </div>
    </div>
    <div class="tr-card-body" style="background:#e5e7eb;padding:20px;min-height:500px">
      <!-- A4 Preview -->
      <div id="prev-a4" style="background:#fff;max-width:640px;margin:0 auto;
           padding:32px;font-family:Arial,sans-serif;font-size:12px;
           box-shadow:0 2px 12px rgba(0,0,0,.15);border-radius:4px">
        <?= renderPreviewA4($cfg, $logoUrl) ?>
      </div>
      <!-- 80mm Preview -->
      <div id="prev-80" style="display:none;background:#fff;max-width:310px;margin:0 auto;
           padding:16px 14px;font-family:'Courier New',monospace;font-size:11px;
           box-shadow:0 2px 12px rgba(0,0,0,.15);border-radius:4px">
        <?= renderPreview80($cfg, $logoUrl) ?>
      </div>
    </div>
  </div>
</div>
</div>

<?php
function renderPreviewA4(array $cfg, string $logoUrl): string {
    $emp  = htmlspecialchars($cfg['empresa_nombre']   ?? 'Mi Empresa');
    $ruc  = htmlspecialchars($cfg['empresa_ruc']      ?? '');
    $dir  = htmlspecialchars($cfg['empresa_direccion']?? '');
    $tel  = htmlspecialchars($cfg['empresa_telefono'] ?? '');
    $email= htmlspecialchars($cfg['empresa_email']    ?? '');
    $cab  = ($cfg['print_mostrar_cabecera']??'1')==='1' ? ($cfg['print_cabecera']??'') : '';
    $ctas = $cfg['print_cuentas']    ?? '';
    $inf  = ($cfg['print_mostrar_inferior']??'1')==='1' ? htmlspecialchars($cfg['print_msg_inferior']??'') : '';
    $desp = ($cfg['print_mostrar_despedida']??'1')==='1' ? strtoupper(htmlspecialchars($cfg['print_despedida']??'')) : '';
    $showLogo = ($cfg['print_mostrar_logo']??'1')==='1';
    $showQR   = ($cfg['print_mostrar_qr']  ??'1')==='1';

    ob_start(); ?>
    <table style="width:100%;border-collapse:collapse;margin-bottom:12px">
      <tr>
        <td style="width:60%;vertical-align:top">
          <?php if($showLogo && $logoUrl): ?>
          <img src="<?= $logoUrl ?>" id="pv-logo-a4"
               style="max-height:56px;max-width:180px;object-fit:contain;margin-bottom:6px"/>
          <?php elseif($showLogo): ?>
          <div id="pv-logo-a4" style="width:80px;height:40px;border:1px solid #ccc;
               display:flex;align-items:center;justify-content:center;
               font-size:10px;color:#9ca3af;margin-bottom:6px;border-radius:4px">Logo</div>
          <?php endif; ?>
          <div style="font-size:15px;font-weight:bold;margin-bottom:2px"><?= $emp ?></div>
          <?php if($dir):  ?><div style="color:#555"><?= $dir ?></div><?php endif; ?>
          <?php if($tel):  ?><div style="color:#555">Tel: <?= $tel ?></div><?php endif; ?>
          <?php if($email):?><div style="color:#555"><?= $email ?></div><?php endif; ?>
          <?php if($cab):  ?><div style="margin-top:6px;padding:6px;background:#f9fafb;border-radius:4px;font-size:11px"><?= $cab ?></div><?php endif; ?>
        </td>
        <td style="width:40%;vertical-align:top;text-align:right">
          <div style="border:2px solid #111;display:inline-block;padding:6px 12px;text-align:center;min-width:120px">
            <?php if($ruc): ?><div style="font-size:11px">R.U.C.</div>
            <div style="font-weight:bold;font-size:13px"><?= $ruc ?></div><?php endif; ?>
            <div style="background:#111;color:#fff;padding:3px 8px;margin:4px 0;font-weight:bold;font-size:12px">
              BOLETA DE<br>VENTA
            </div>
            <div style="font-weight:bold;font-size:13px">B001-00000001</div>
          </div>
        </td>
      </tr>
    </table>
    <hr style="border:1px solid #333;margin:8px 0"/>
    <table style="width:100%;margin-bottom:8px;font-size:11px">
      <tr>
        <td style="width:50%"><b>CLIENTE:</b> EJEMPLO CLIENTE</td>
        <td style="width:50%"><b>FECHA:</b> <?= date('d/m/Y') ?></td>
      </tr>
      <tr>
        <td><b>DNI:</b> 12345678</td>
        <td><b>MONEDA:</b> SOLES</td>
      </tr>
      <tr>
        <td><b>DIRECCIÓN:</b> —</td>
        <td><b>MÉTODO:</b> EFECTIVO</td>
      </tr>
    </table>
    <hr style="border:1px solid #333;margin:8px 0"/>
    <table style="width:100%;border-collapse:collapse;font-size:11px">
      <thead>
        <tr style="border-bottom:1px solid #333">
          <th style="text-align:left;padding:3px">Nº</th>
          <th style="text-align:center;padding:3px">CANT.</th>
          <th style="text-align:center;padding:3px">UNID.</th>
          <th style="text-align:left;padding:3px">DESCRIPCIÓN</th>
          <th style="text-align:right;padding:3px">V.UNIT.</th>
          <th style="text-align:right;padding:3px">SUBTOTAL</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td style="padding:4px">1</td>
          <td style="text-align:center;padding:4px">1.000</td>
          <td style="text-align:center;padding:4px">NIU</td>
          <td style="padding:4px">Servicio de reparación</td>
          <td style="text-align:right;padding:4px">150.00</td>
          <td style="text-align:right;padding:4px">150.00</td>
        </tr>
      </tbody>
    </table>
    <hr style="border:1px solid #333;margin:8px 0"/>
    <table style="width:100%;font-size:11px">
      <tr>
        <td style="width:55%;vertical-align:top">
          <?php if($inf): ?><div style="padding:6px;border:1px solid #e5e7eb;border-radius:4px;font-size:10px;margin-bottom:6px"><?= $inf ?></div><?php endif; ?>
          <?php if($ctas): ?>
          <div style="font-size:10px;color:#555;white-space:pre-line"><?= htmlspecialchars($ctas) ?></div>
          <?php endif; ?>
        </td>
        <td style="width:45%;text-align:right">
          <div>OP. GRAVADAS: S/ 127.12</div>
          <div>IGV 18.0%: S/ 22.88</div>
          <div style="font-weight:bold;font-size:14px;border-top:2px solid #333;margin-top:4px;padding-top:4px">TOTAL: S/ 150.00</div>
        </td>
      </tr>
    </table>
    <?php if($desp): ?>
    <div style="text-align:center;font-weight:bold;margin-top:12px;font-size:12px;border-top:1px solid #e5e7eb;padding-top:8px">
      <?= $desp ?>
    </div>
    <?php endif; ?>
    <?php if($showQR): ?>
    <div style="text-align:center;margin-top:10px">
      <div style="display:inline-block;border:1px solid #ccc;padding:4px">
        <table style="border-collapse:collapse">
          <tr>
            <td style="width:8px;height:8px;background:#111"></td><td style="width:8px;height:8px"></td>
            <td style="width:8px;height:8px;background:#111"></td><td style="width:8px;height:8px"></td>
            <td style="width:8px;height:8px;background:#111"></td>
          </tr>
          <tr>
            <td style="background:#111"></td><td></td><td></td><td></td><td style="background:#111"></td>
          </tr>
          <tr>
            <td style="background:#111"></td><td></td><td style="background:#111"></td><td></td><td style="background:#111"></td>
          </tr>
          <tr>
            <td style="background:#111"></td><td></td><td></td><td></td><td style="background:#111"></td>
          </tr>
          <tr>
            <td style="background:#111"></td><td></td><td style="background:#111"></td><td style="background:#111"></td><td style="background:#111"></td>
          </tr>
        </table>
      </div>
      <div style="font-size:9px;color:#9ca3af;margin-top:2px">Código QR</div>
    </div>
    <?php endif; ?>
    <?php return ob_get_clean();
}

function renderPreview80(array $cfg, string $logoUrl): string {
    $emp  = $cfg['empresa_nombre']    ?? 'Mi Empresa';
    $ruc  = $cfg['empresa_ruc']       ?? '';
    $dir  = $cfg['empresa_direccion'] ?? '';
    $tel  = $cfg['empresa_telefono']  ?? '';
    $cab  = ($cfg['print_mostrar_cabecera']??'1')==='1' ? ($cfg['print_cabecera']??'') : '';
    $ctas = $cfg['print_cuentas']     ?? '';
    $inf  = ($cfg['print_mostrar_inferior']??'1')==='1' ? ($cfg['print_msg_inferior']??'') : '';
    $desp = ($cfg['print_mostrar_despedida']??'1')==='1' ? strtoupper($cfg['print_despedida']??'') : '';
    $showLogo = ($cfg['print_mostrar_logo']??'1')==='1';
    $showQR   = ($cfg['print_mostrar_qr']  ??'1')==='1';
    ob_start(); ?>
    <div style="text-align:center;margin-bottom:8px">
      <?php if($showLogo && $logoUrl): ?>
      <img src="<?= $logoUrl ?>" id="pv-logo-80"
           style="max-height:50px;max-width:200px;object-fit:contain;margin-bottom:4px"/><br/>
      <?php elseif($showLogo): ?>
      <div id="pv-logo-80" style="width:60px;height:30px;border:1px solid #ccc;
           display:inline-flex;align-items:center;justify-content:center;
           font-size:9px;color:#9ca3af;margin-bottom:4px">Logo</div><br>
      <?php endif; ?>
      <b><?= htmlspecialchars($emp) ?></b><br/>
      <?php if($ruc): ?><?= htmlspecialchars($ruc) ?><br/><?php endif; ?>
      <?php if($dir): ?><?= htmlspecialchars($dir) ?><br/><?php endif; ?>
      <?php if($tel): ?>Tel: <?= htmlspecialchars($tel) ?><br/><?php endif; ?>
      <?php if($cab): ?><div style="margin-top:4px;font-size:10px"><?= $cab ?></div><?php endif; ?>
    </div>
    <div style="text-align:center;font-weight:bold;margin-bottom:6px">
      --- BOLETA DE VENTA ---<br/>
      B001-00000001
    </div>
    <div style="border-top:1px dashed #333;border-bottom:1px dashed #333;padding:4px 0;margin-bottom:6px;font-size:10px">
      CLIENTE : EJEMPLO CLIENTE<br/>
      DNI      : 12345678<br/>
      FECHA    : <?= date('d/m/Y') ?><br/>
      MÉTODO   : EFECTIVO
    </div>
    <table style="width:100%;font-size:10px;border-collapse:collapse">
      <tr style="border-bottom:1px dashed #333">
        <th style="text-align:left">DESCRIPCIÓN</th>
        <th style="text-align:center">CANT</th>
        <th style="text-align:right">TOTAL</th>
      </tr>
      <tr>
        <td style="padding:3px 0">Serv. reparación</td>
        <td style="text-align:center">1</td>
        <td style="text-align:right">150.00</td>
      </tr>
    </table>
    <div style="border-top:1px dashed #333;margin-top:6px;padding-top:6px;font-size:10px">
      <div style="text-align:right">SUBTOTAL: S/ 127.12</div>
      <div style="text-align:right">IGV 18%: S/ 22.88</div>
      <div style="text-align:right;font-weight:bold;font-size:13px">TOTAL: S/ 150.00</div>
    </div>
    <?php if($inf): ?>
    <div style="border-top:1px dashed #333;margin-top:6px;padding-top:6px;font-size:9px;color:#555"><?= htmlspecialchars($inf) ?></div>
    <?php endif; ?>
    <?php if($ctas): ?>
    <div style="border-top:1px dashed #333;margin-top:6px;padding-top:4px;font-size:9px;white-space:pre-line"><?= htmlspecialchars($ctas) ?></div>
    <?php endif; ?>
    <?php if($desp): ?>
    <div style="text-align:center;font-weight:bold;margin-top:8px;font-size:11px"><?= htmlspecialchars($desp) ?></div>
    <?php endif; ?>
    <?php if($showQR): ?>
    <div style="text-align:center;margin-top:8px;font-size:9px;color:#9ca3af">[ Código QR ]</div>
    <?php endif; ?>
    <?php return ob_get_clean();
}
?>

<script>
var tabActual = 'a4';

function setTab(t) {
  tabActual = t;
  document.getElementById('prev-a4').style.display = t==='a4' ? '' : 'none';
  document.getElementById('prev-80').style.display = t==='80' ? '' : 'none';
  document.getElementById('tab-a4').className = t==='a4' ? 'btn btn-sm btn-primary' : 'btn btn-sm btn-outline-secondary';
  document.getElementById('tab-80').className = t==='80' ? 'btn btn-sm btn-primary' : 'btn btn-sm btn-outline-secondary';
}

function previewLogo(input) {
  if (!input.files[0]) return;
  var reader = new FileReader();
  reader.onload = function(e) {
    ['pv-logo-a4','pv-logo-80'].forEach(function(id) {
      var el = document.getElementById(id);
      if (!el) return;
      if (el.tagName === 'IMG') {
        el.src = e.target.result;
      } else {
        // Reemplazar placeholder con img
        var img = document.createElement('img');
        img.src = e.target.result;
        img.id  = id;
        img.style.cssText = el.style.cssText.replace('display:inline-flex','').replace('display:flex','');
        el.parentNode.replaceChild(img, el);
      }
    });
  };
  reader.readAsDataURL(input.files[0]);
}

function actualizarPreview() {
  // Actualizar checkboxes visualmente en el preview
  var showLogo = document.getElementById('chk-logo')?.checked;
  var showQR   = document.getElementById('chk-qr')?.checked;
  ['pv-logo-a4','pv-logo-80'].forEach(function(id) {
    var el = document.getElementById(id);
    if (el) el.style.display = showLogo ? '' : 'none';
  });
  // Recargar el iframe de preview al guardar
  // (la preview completa se actualiza al guardar el formulario)
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
