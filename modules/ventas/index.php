<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';
requireLogin();
requireRole([ROL_ADMIN, ROL_VENDEDOR]);
$db   = getDB();
$user = currentUser();
$tab  = $_GET['tab'] ?? 'historial';

// ─── DESCARGAS XML / CDR ─────────────────────────────────────
if (isset($_GET['accion']) && in_array($_GET['accion'], ['xml','cdr'], true) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $st = $db->prepare("SELECT tipo_doc,serie_doc,num_doc,sunat_xml,sunat_cdr FROM ventas WHERE id=?");
    $st->execute([$id]);
    $v = $st->fetch();
    if (!$v) { http_response_code(404); exit('No encontrado.'); }
    $base = strtoupper($v['tipo_doc']).'-'.($v['serie_doc']??'B001').'-'.($v['num_doc']??'00000000');
    if ($_GET['accion'] === 'xml') {
        if (empty($v['sunat_xml'])) { http_response_code(404); exit('Sin XML.'); }
        header('Content-Type: application/xml; charset=utf-8');
        if (isset($_GET['dl'])) header('Content-Disposition: attachment; filename="'.$base.'.xml"');
        echo $v['sunat_xml']; exit;
    }
    if ($_GET['accion'] === 'cdr') {
        if (empty($v['sunat_cdr'])) { http_response_code(404); exit('Sin CDR.'); }
        $bin = base64_decode($v['sunat_cdr'], true);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="R-'.$base.'.zip"');
        echo $bin !== false ? $bin : $v['sunat_cdr']; exit;
    }
}

// ─── ACCIONES POST SUNAT ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    $id = (int)($_POST['id'] ?? 0);
    require_once __DIR__ . '/../../includes/sunat/SunatService.php';
    $sunat = new SunatService($db);
    if ($_POST['accion'] === 'enviar_sunat') {
        $r = $sunat->enviarSunat($id);
        setFlash($r['ok'] ? 'success' : 'error', $r['ok'] ? 'SUNAT aceptó: '.$r['mensaje'] : 'SUNAT rechazó: '.$r['mensaje']);
    } elseif ($_POST['accion'] === 'regenerar') {
        $r = $sunat->generarXml($id);
        setFlash($r['ok'] ? 'success' : 'error', $r['ok'] ? 'XML regenerado.' : 'Error: '.$r['mensaje']);
    }
    redirect(BASE_URL.'modules/ventas/index.php?tab=sunat');
}

// ─── DATOS TAB HISTORIAL ─────────────────────────────────────
$h_desde = $_GET['desde'] ?? date('Y-m-d');
$h_hasta = $_GET['hasta'] ?? date('Y-m-d');
$h_q     = trim($_GET['q'] ?? '');
$h_where = ['DATE(v.created_at) BETWEEN ? AND ?'];
$h_params = [$h_desde, $h_hasta];
if ($h_q) { $h_where[] = '(v.codigo LIKE ? OR c.nombre LIKE ?)'; $like='%'.$h_q.'%'; $h_params=array_merge($h_params,[$like,$like]); }
$st = $db->prepare("SELECT v.*,c.nombre as cliente_nombre,CONCAT(u.nombre,' ',u.apellido) as vendedor FROM ventas v LEFT JOIN clientes c ON c.id=v.cliente_id JOIN usuarios u ON u.id=v.usuario_id WHERE ".implode(' AND ',$h_where)." ORDER BY v.created_at DESC LIMIT 300");
$st->execute($h_params);
$historial = $st->fetchAll();
$totalPeriodo = array_sum(array_map(fn($v)=>$v['estado']==='completada'?(float)$v['total']:0, $historial));

// ─── DATOS TAB SUNAT ─────────────────────────────────────────
$s_desde = $_GET['s_desde'] ?? date('Y-m-01');
$s_hasta = $_GET['s_hasta'] ?? date('Y-m-d');
$s_tipo  = $_GET['s_tipo']  ?? '';
$s_est   = $_GET['s_est']   ?? '';
$s_q     = trim($_GET['s_q'] ?? '');
$s_where = "WHERE v.tipo_doc IN ('boleta','factura') AND DATE(v.created_at) BETWEEN ? AND ?";
$s_params = [$s_desde, $s_hasta];
if ($s_tipo) { $s_where .= " AND v.tipo_doc=?"; $s_params[] = $s_tipo; }
if ($s_est === 'sin_xml') { $s_where .= " AND (v.sunat_xml IS NULL OR v.sunat_xml='')"; }
elseif ($s_est) { $s_where .= " AND v.sunat_estado=?"; $s_params[] = $s_est; }
if ($s_q) { $s_where .= " AND (c.nombre LIKE ? OR v.codigo LIKE ?)"; $b='%'.$s_q.'%'; $s_params[]=$b; $s_params[]=$b; }
$st = $db->prepare("SELECT v.*,c.nombre AS cliente_nombre,COALESCE(c.num_doc,c.ruc_dni,'') AS cliente_ruc_dni FROM ventas v LEFT JOIN clientes c ON v.cliente_id=c.id $s_where ORDER BY v.created_at DESC LIMIT 200");
$st->execute($s_params);
$comprobantes = $st->fetchAll();
$kpi = $db->prepare("SELECT SUM(tipo_doc='factura') n_facturas,SUM(tipo_doc='boleta') n_boletas,SUM(sunat_estado='aceptado') n_acept,SUM(sunat_estado='rechazado') n_rech,SUM(sunat_estado='pendiente') n_pend,COALESCE(SUM(total),0) total FROM ventas WHERE tipo_doc IN ('boleta','factura') AND DATE(created_at) BETWEEN ? AND ?");
$kpi->execute([$s_desde, $s_hasta]);
$k = $kpi->fetch();

$pageTitle  = 'Ventas — '.APP_NAME;
$breadcrumb = [['label'=>'Ventas','url'=>null]];
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="fw-bold mb-0">Ventas</h5>
  <a href="<?= BASE_URL ?>modules/ventas/pos.php" class="btn btn-primary btn-sm">
    <i data-feather="shopping-cart" style="width:14px;height:14px"></i> Punto de venta
  </a>
</div>

<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><button class="nav-link <?= $tab==='historial'?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#tab-historial" type="button">Historial</button></li>
  <?php if($user['rol']===ROL_ADMIN): ?>
  <li class="nav-item"><button class="nav-link <?= $tab==='sunat'?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#tab-sunat" type="button">Comprobantes SUNAT</button></li>
  <?php endif; ?>
</ul>

<div class="tab-content">

<!-- ── HISTORIAL ── -->
<div class="tab-pane fade <?= $tab==='historial'?'show active':'' ?>" id="tab-historial">
  <div class="tr-card mb-3">
    <div class="tr-card-body py-2">
      <form method="GET" class="row g-2 align-items-end">
        <input type="hidden" name="tab" value="historial"/>
        <div class="col-md-3"><input type="text" name="q" class="form-control form-control-sm" placeholder="Código o cliente..." value="<?= sanitize($h_q) ?>"/></div>
        <div class="col-md-2"><input type="date" name="desde" class="form-control form-control-sm" value="<?= $h_desde ?>"/></div>
        <div class="col-md-2"><input type="date" name="hasta" class="form-control form-control-sm" value="<?= $h_hasta ?>"/></div>
        <div class="col-md-1"><button type="submit" class="btn btn-primary btn-sm w-100">Filtrar</button></div>
        <div class="col-md-4 text-end small">Total período: <span class="fw-bold text-success"><?= formatMoney($totalPeriodo) ?></span></div>
      </form>
    </div>
  </div>
  <div class="tr-card">
    <div class="tr-card-body p-0"><div style="overflow-x:auto">
      <table class="tr-table">
        <thead><tr><th>Código</th><th>Cliente</th><th>Tipo doc.</th><th>Base</th><th>IGV</th><th>Total</th><th>Método</th><th>Vendedor</th><th>Estado</th><th>Fecha</th><th></th></tr></thead>
        <tbody>
          <?php foreach($historial as $v): ?>
          <tr>
            <td><span class="fw-semibold small text-primary"><?= sanitize($v['codigo']) ?></span></td>
            <td class="small"><?= sanitize($v['cliente_nombre'] ?? '— Consumidor final —') ?></td>
            <td><span class="badge bg-secondary"><?= ucfirst($v['tipo_doc']) ?></span></td>
            <td class="small"><?= formatMoney($v['subtotal']) ?></td>
            <td class="small text-muted"><?= formatMoney($v['igv']) ?></td>
            <td class="fw-bold"><?= formatMoney($v['total']) ?></td>
            <td class="small"><?= ucfirst($v['metodo_pago']) ?></td>
            <td class="small text-muted"><?= sanitize($v['vendedor']) ?></td>
            <td><span class="badge bg-<?= $v['estado']==='completada'?'success':($v['estado']==='anulada'?'danger':'warning') ?>"><?= ucfirst($v['estado']) ?></span></td>
            <td class="small text-muted"><?= formatDateTime($v['created_at']) ?></td>
            <td>
              <div class="btn-group btn-group-sm">
                <a href="<?= BASE_URL ?>modules/ventas/detalle.php?id=<?= $v['id'] ?>" class="btn btn-outline-primary" title="Ver"><i data-feather="eye" style="width:13px;height:13px"></i></a>
                <a href="<?= BASE_URL ?>modules/ventas/ticket.php?id=<?= $v['id'] ?>" target="_blank" class="btn btn-outline-secondary" title="Imprimir"><i data-feather="printer" style="width:13px;height:13px"></i></a>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if(empty($historial)): ?><tr><td colspan="11" class="text-center text-muted py-4">Sin ventas en el período</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div></div>
  </div>
</div>

<!-- ── SUNAT ── -->
<?php if($user['rol']===ROL_ADMIN): ?>
<div class="tab-pane fade <?= $tab==='sunat'?'show active':'' ?>" id="tab-sunat">
  <div class="row g-3 mb-3">
    <?php foreach([['Boletas',(int)$k['n_boletas']],['Facturas',(int)$k['n_facturas']],['Aceptados',(int)$k['n_acept']],['Total facturado',formatMoney((float)$k['total'])]] as [$lbl,$val]): ?>
    <div class="col-6 col-md-3">
      <div class="tr-card text-center py-3">
        <div class="text-muted small"><?= $lbl ?></div>
        <div class="fw-bold fs-5"><?= $val ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="tr-card mb-3">
    <div class="tr-card-body py-2">
      <form method="GET" class="row g-2 align-items-end">
        <input type="hidden" name="tab" value="sunat"/>
        <div class="col-md-2"><input type="date" name="s_desde" class="form-control form-control-sm" value="<?= $s_desde ?>"/></div>
        <div class="col-md-2"><input type="date" name="s_hasta" class="form-control form-control-sm" value="<?= $s_hasta ?>"/></div>
        <div class="col-md-2">
          <select name="s_tipo" class="form-select form-select-sm">
            <option value="">— Tipo —</option>
            <option value="boleta" <?= $s_tipo==='boleta'?'selected':'' ?>>Boleta</option>
            <option value="factura" <?= $s_tipo==='factura'?'selected':'' ?>>Factura</option>
          </select>
        </div>
        <div class="col-md-2">
          <select name="s_est" class="form-select form-select-sm">
            <option value="">— Estado SUNAT —</option>
            <option value="sin_xml" <?= $s_est==='sin_xml'?'selected':'' ?>>Sin XML</option>
            <option value="pendiente" <?= $s_est==='pendiente'?'selected':'' ?>>Pendiente</option>
            <option value="aceptado" <?= $s_est==='aceptado'?'selected':'' ?>>Aceptado</option>
            <option value="rechazado" <?= $s_est==='rechazado'?'selected':'' ?>>Rechazado</option>
          </select>
        </div>
        <div class="col-md-3"><input type="text" name="s_q" class="form-control form-control-sm" placeholder="Cliente o código..." value="<?= sanitize($s_q) ?>"/></div>
        <div class="col-md-1"><button type="submit" class="btn btn-primary btn-sm w-100">Filtrar</button></div>
      </form>
    </div>
  </div>
  <div class="tr-card">
    <div class="tr-card-body p-0"><div style="overflow-x:auto">
      <table class="tr-table">
        <thead><tr><th>Comprobante</th><th>Cliente</th><th>Fecha</th><th class="text-end">Total</th><th>SUNAT</th><th>Acciones</th></tr></thead>
        <tbody>
          <?php foreach($comprobantes as $v):
            $se = $v['sunat_estado'] ?? '';
            $tc = $v['tipo_doc'];
            $bc = $se==='aceptado'?'bg-success':($se==='rechazado'?'bg-danger':($se==='pendiente'?'bg-warning text-dark':'bg-secondary'));
          ?>
          <tr>
            <td>
              <span class="badge <?= $tc==='factura'?'bg-primary':'bg-info' ?>"><?= strtoupper($tc) ?></span>
              <?php
                $s_doc = $v['serie_doc'] ?? $v['serie'] ?? '';
                $n_doc = $v['num_doc']   ?? ($v['numero'] ? str_pad((string)$v['numero'], 8, '0', STR_PAD_LEFT) : '');
              ?>
              <div class="small" style="font-size:11px"><?= !empty($s_doc) && !empty($n_doc) ? sanitize($s_doc).'-'.sanitize($n_doc) : sanitize($s_doc) ?></div>
              <small class="text-muted"><?= sanitize($v['codigo']) ?></small>
            </td>
            <td class="small"><?= sanitize($v['cliente_nombre'] ?? 'Consumidor Final') ?><?php if(!empty($v['cliente_ruc_dni'])): ?><br><span class="text-muted"><?= sanitize($v['cliente_ruc_dni']) ?></span><?php endif; ?></td>
            <td class="small text-muted"><?= formatDateTime($v['created_at']) ?></td>
            <td class="text-end fw-bold"><?= formatMoney((float)$v['total']) ?></td>
            <td><span class="badge <?= $bc ?>"><?= $se ? strtoupper($se) : 'SIN XML' ?></span></td>
            <td>
              <div class="d-flex gap-1 flex-wrap">
                <a href="<?= BASE_URL ?>modules/ventas/ticket.php?id=<?= $v['id'] ?>" target="_blank" class="btn btn-outline-secondary btn-sm py-0 px-1" title="Ver PDF"><i data-feather="printer" style="width:13px;height:13px"></i></a>
                <?php if(!empty($v['sunat_xml'])): ?>
                  <a href="?accion=xml&id=<?= $v['id'] ?>" target="_blank" class="btn btn-outline-secondary btn-sm py-0 px-1" title="Ver XML"><i data-feather="code" style="width:13px;height:13px"></i></a>
                  <a href="?accion=xml&id=<?= $v['id'] ?>&dl=1" class="btn btn-outline-secondary btn-sm py-0 px-1" title="Descargar XML"><i data-feather="download" style="width:13px;height:13px"></i></a>
                <?php endif; ?>
                <?php if(!empty($v['sunat_cdr'])): ?>
                  <a href="?accion=cdr&id=<?= $v['id'] ?>" class="btn btn-success btn-sm py-0 px-1" title="CDR"><i data-feather="file-text" style="width:13px;height:13px"></i></a>
                <?php endif; ?>
                <?php if($se === 'pendiente' && !empty($v['sunat_xml'])): ?>
                  <form method="POST" style="display:inline"><input type="hidden" name="accion" value="enviar_sunat"/><input type="hidden" name="id" value="<?= $v['id'] ?>"/><button class="btn btn-primary btn-sm py-0 px-1" title="Enviar a SUNAT"><i data-feather="send" style="width:13px;height:13px"></i></button></form>
                <?php endif; ?>
                <?php if(empty($v['sunat_xml']) || $se === 'rechazado'): ?>
                  <form method="POST" style="display:inline"><input type="hidden" name="accion" value="regenerar"/><input type="hidden" name="id" value="<?= $v['id'] ?>"/><button class="btn btn-outline-warning btn-sm py-0 px-1" title="Regenerar XML"><i data-feather="refresh-cw" style="width:13px;height:13px"></i></button></form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if(empty($comprobantes)): ?><tr><td colspan="6" class="text-center text-muted py-4">Sin comprobantes en el período</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div></div>
  </div>
</div>
<?php endif; ?>

</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
