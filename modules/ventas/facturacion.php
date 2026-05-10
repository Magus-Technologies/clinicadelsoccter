<?php
/**
 * facturacion.php — Facturación electrónica SUNAT
 *olistra: ver lista, emitir (desde POS), enviar a SUNAT, descargar XML/CDR, PDF.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';
requireLogin();
requireRole([ROL_ADMIN, ROL_VENDEDOR]);

$db = getDB();
$accion = $_GET['accion'] ?? 'lista';

// ─── DESCARGAS XML / CDR ──────────────────────────────────────
if (in_array($accion, ['xml', 'cdr'], true) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $st = $db->prepare("SELECT tipo_doc, serie, numero, sunat_xml, sunat_cdr FROM ventas WHERE id=?");
    $st->execute([$id]);
    $v = $st->fetch();
    if (!$v) { http_response_code(404); echo 'No encontrado.'; exit; }
    if (!in_array($v['tipo_doc'], ['factura', 'boleta'], true)) {
        http_response_code(400); echo 'No es comprobante SUNAT.'; exit;
    }

    $base = strtoupper($v['tipo_doc']) . '-' . ($v['serie'] ?? 'B001') . '-' . str_pad((string)($v['numero'] ?? 0), 8, '0', STR_PAD_LEFT);

    if ($accion === 'xml') {
        if (empty($v['sunat_xml'])) { http_response_code(404); echo 'Sin XML.'; exit; }
        header('Content-Type: application/xml; charset=utf-8');
        if (isset($_GET['dl'])) header('Content-Disposition: attachment; filename="' . $base . '.xml"');
        echo $v['sunat_xml'];
        exit;
    }
    if ($accion === 'cdr') {
        if (empty($v['sunat_cdr'])) { http_response_code(404); echo 'Sin CDR.'; exit; }
        $bin = base64_decode($v['sunat_cdr'], true);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="R-' . $base . '.zip"');
        echo $bin !== false ? $bin : $v['sunat_cdr'];
        exit;
    }
}

// ─── ENVIAR A SUNAT ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    $id = (int)($_POST['id'] ?? 0);

    if ($_POST['accion'] === 'enviar_sunat') {
        require_once __DIR__ . '/../../includes/sunat/SunatService.php';
        $sunat = new SunatService($db);
        $r = $sunat->enviarSunat($id);
        setFlash($r['ok'] ? 'success' : 'error', $r['ok'] ? 'SUNAT aceptó: ' . $r['mensaje'] : 'SUNAT rechazó: ' . $r['mensaje']);
        redirect(BASE_URL . 'modules/ventas/facturacion.php?accion=ver&id=' . $id);
    }

    if ($_POST['accion'] === 'regenerar') {
        require_once __DIR__ . '/../../includes/sunat/SunatService.php';
        $sunat = new SunatService($db);
        $r = $sunat->generarXml($id);
        setFlash($r['ok'] ? 'success' : 'error', $r['ok'] ? 'XML regenerado.' : 'Error: ' . $r['mensaje']);
        redirect(BASE_URL . 'modules/ventas/facturacion.php?accion=ver&id=' . $id);
    }
}

// ─── LISTA ──────────────────────────────────────────────────────
if ($accion === 'lista') {
    $pageTitle = 'Facturación — ' . APP_NAME;
    $breadcrumb = [['label' => 'Ventas', 'url' => BASE_URL . 'modules/ventas/index.php'], ['label' => 'Facturación', 'url' => null]];

    $filtro_fecha_desde = $_GET['desde'] ?? date('Y-m-01');
    $filtro_fecha_hasta = $_GET['hasta'] ?? date('Y-m-d');
    $filtro_tipo = $_GET['tipo'] ?? '';
    $filtro_sunat = $_GET['sunat'] ?? '';
    $filtro_q = trim($_GET['q'] ?? '');

    $where = "WHERE v.tipo_doc IN ('boleta','factura') AND DATE(v.created_at) BETWEEN ? AND ?";
    $params = [$filtro_fecha_desde, $filtro_fecha_hasta];

    if ($filtro_tipo) {
        $where .= " AND v.tipo_doc = ?";
        $params[] = $filtro_tipo;
    }
    if ($filtro_sunat === 'sin_xml') {
        $where .= " AND (v.sunat_xml IS NULL OR v.sunat_xml = '')";
    } elseif ($filtro_sunat) {
        $where .= " AND v.sunat_estado = ?";
        $params[] = $filtro_sunat;
    }
    if ($filtro_q) {
        $where .= " AND (c.nombre LIKE ? OR c.num_doc LIKE ? OR v.codigo LIKE ?)";
        $b = '%' . $filtro_q . '%';
        $params[] = $b;
        $params[] = $b;
        $params[] = $b;
    }

    $st = $db->prepare("
        SELECT v.*, c.nombre AS cliente_nombre, c.num_doc, c.tipo_doc AS cliente_tipo_doc
        FROM ventas v
        LEFT JOIN clientes c ON v.cliente_id = c.id
        $where
        ORDER BY v.created_at DESC
        LIMIT 200
    ");
    $st->execute($params);
    $ventas = $st->fetchAll();

    $kpi = $db->prepare("
        SELECT
            SUM(CASE WHEN tipo_doc = 'factura' THEN 1 ELSE 0 END) AS n_facturas,
            SUM(CASE WHEN tipo_doc = 'boleta'  THEN 1 ELSE 0 END) AS n_boletas,
            SUM(CASE WHEN sunat_estado = 'aceptado' THEN 1 ELSE 0 END) AS n_aceptados,
            SUM(CASE WHEN sunat_estado = 'rechazado' THEN 1 ELSE 0 END) AS n_rechazados,
            SUM(CASE WHEN sunat_estado = 'pendiente' THEN 1 ELSE 0 END) AS n_pendientes,
            COALESCE(SUM(total), 0) AS total_facturado
        FROM ventas
        WHERE tipo_doc IN ('boleta','factura') AND DATE(created_at) BETWEEN ? AND ?
    ");
    $kpi->execute([$filtro_fecha_desde, $filtro_fecha_hasta]);
    $k = $kpi->fetch();

    require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="fw-bold mb-0">Facturación electrónica</h5>
    <a href="<?= BASE_URL ?>modules/ventas/pos.php" class="btn btn-primary btn-sm">
        <i data-feather="plus" style="width:14px;height:14px"></i> Nueva venta
    </a>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="tr-card text-center py-3">
            <div class="text-muted small">Boletas</div>
            <div class="fw-bold fs-4"><?= (int)$k['n_boletas'] ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="tr-card text-center py-3">
            <div class="text-muted small">Facturas</div>
            <div class="fw-bold fs-4"><?= (int)$k['n_facturas'] ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="tr-card text-center py-3">
            <div class="text-muted small">SUNAT aceptados</div>
            <div class="fw-bold fs-4"><?= (int)$k['n_aceptados'] ?>
                <small class="text-muted">/ <?= (int)$k['n_pendientes'] ?> pend · <?= (int)$k['n_rechazados'] ?> rech</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="tr-card text-center py-3">
            <div class="text-muted small">Total facturado</div>
            <div class="fw-bold fs-5"><?= formatMoney((float)$k['total_facturado']) ?></div>
        </div>
    </div>
</div>

<div class="tr-card mb-3">
    <div class="tr-card-body">
        <form method="GET" class="row g-2 align-items-end">
            <input type="hidden" name="accion" value="lista"/>
            <div class="col-md-2"><label class="small text-muted">Desde</label><input type="date" name="desde" class="form-control form-control-sm" value="<?= $filtro_fecha_desde ?>"/></div>
            <div class="col-md-2"><label class="small text-muted">Hasta</label><input type="date" name="hasta" class="form-control form-control-sm" value="<?= $filtro_fecha_hasta ?>"/></div>
            <div class="col-md-2"><label class="small text-muted">Tipo</label>
                <select name="tipo" class="form-select form-select-sm">
                    <option value="">— Todos —</option>
                    <option value="boleta" <?= $filtro_tipo === 'boleta' ? 'selected' : '' ?>>Boleta</option>
                    <option value="factura" <?= $filtro_tipo === 'factura' ? 'selected' : '' ?>>Factura</option>
                </select>
            </div>
            <div class="col-md-2"><label class="small text-muted">SUNAT</label>
                <select name="sunat" class="form-select form-select-sm">
                    <option value="">— Todos —</option>
                    <option value="sin_xml" <?= $filtro_sunat === 'sin_xml' ? 'selected' : '' ?>>Sin XML</option>
                    <option value="pendiente" <?= $filtro_sunat === 'pendiente' ? 'selected' : '' ?>>Pendiente</option>
                    <option value="aceptado" <?= $filtro_sunat === 'aceptado' ? 'selected' : '' ?>>Aceptado</option>
                    <option value="rechazado" <?= $filtro_sunat === 'rechazado' ? 'selected' : '' ?>>Rechazado</option>
                </select>
            </div>
            <div class="col-md-3"><label class="small text-muted">Buscar</label><input type="text" name="q" class="form-control form-control-sm" placeholder="Cliente, código..." value="<?= sanitize($filtro_q) ?>"/></div>
            <div class="col-md-1"><button type="submit" class="btn btn-dk btn-sm w-100"><i data-feather="search" style="width:13px"></i></button></div>
        </form>
    </div>
</div>

<div class="tr-card">
    <div class="table-responsive">
        <table class="table mb-0">
            <thead><tr>
                <th>Comprobante</th>
                <th>Cliente</th>
                <th>Fecha</th>
                <th class="text-end">Total</th>
                <th>Estado</th>
                <th>SUNAT</th>
                <th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($ventas as $v):
                $se = $v['sunat_estado'] ?? '';
                $tc = $v['tipo_doc'];
                $badgeClass = $se === 'aceptado' ? 'bg-success' : ($se === 'rechazado' ? 'bg-danger' : ($se === 'pendiente' ? 'bg-warning text-dark' : 'bg-secondary'));
            ?>
                <tr>
                    <td>
                        <span class="badge <?= $tc === 'factura' ? 'bg-primary' : 'bg-info' ?>"><?= strtoupper($tc) ?></span>
                        <div class="small mon" style="font-size:12px"><?= sanitize($v['serie'] ?? '') ?>-<?= str_pad((string)($v['numero'] ?? ''), 8, '0', STR_PAD_LEFT) ?></div>
                        <small class="text-muted"><?= sanitize($v['codigo']) ?></small>
                    </td>
                    <td>
                        <strong><?= sanitize($v['cliente_nombre'] ?? 'Consumidor Final') ?></strong>
                        <?php if ($tc === 'factura' && !empty($v['num_doc'])): ?>
                            <br><small>RUC: <?= sanitize($v['num_doc']) ?></small>
                        <?php elseif (!empty($v['num_doc'])): ?>
                            <br><small>DNI: <?= sanitize($v['num_doc']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><small><?= formatDateTime($v['created_at']) ?></small></td>
                    <td class="text-end fw-bold mon"><?= formatMoney((float)$v['total']) ?></td>
                    <td><span class="badge <?= $badgeClass ?>"><?= $se ? strtoupper($se) : 'SIN XML' ?></span></td>
                    <td>
                        <?php if (!empty($v['sunat_xml'])): ?>
                            <a href="?accion=xml&id=<?= $v['id'] ?>" target="_blank" class="btn btn-outline-secondary btn-sm" title="Ver XML" style="padding:3px 6px"><i data-feather="code" style="width:13px;height:13px"></i></a>
                            <a href="?accion=xml&id=<?= $v['id'] ?>&dl=1" class="btn btn-outline-secondary btn-sm" title="Descargar XML" style="padding:3px 6px"><i data-feather="download" style="width:13px;height:13px"></i></a>
                        <?php else: ?>
                            <span class="text-muted small">Sin XML</span>
                        <?php endif; ?>
                        <?php if (!empty($v['sunat_cdr'])): ?>
                            <a href="?accion=cdr&id=<?= $v['id'] ?>" class="btn btn-success btn-sm" title="CDR" style="padding:3px 6px"><i data-feather="file-text" style="width:13px;height:13px"></i></a>
                        <?php endif; ?>
                        <?php if (!empty($v['sunat_xml']) && $se !== 'aceptado'): ?>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="accion" value="enviar_sunat"/>
                                <input type="hidden" name="id" value="<?= $v['id'] ?>"/>
                                <button type="submit" class="btn btn-primary btn-sm" title="Enviar a SUNAT" style="padding:3px 6px"><i data-feather="send" style="width:13px;height:13px"></i></button>
                            </form>
                        <?php endif; ?>
                        <?php if (empty($v['sunat_xml']) || $se !== 'aceptado'): ?>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="accion" value="regenerar"/>
                                <input type="hidden" name="id" value="<?= $v['id'] ?>"/>
                                <button type="submit" class="btn btn-outline-warning btn-sm" title="Regenerar" style="padding:3px 6px"><i data-feather="refresh-cw" style="width:13px;height:13px"></i></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$ventas): ?>
                <tr><td colspan="7" class="text-center py-4 text-muted">No hay comprobantes en este período.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php';

// ─── VER DETALLE ────────────────────────────────────────────────
} elseif ($accion === 'ver' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $st = $db->prepare("
        SELECT v.*, c.nombre AS cliente_nombre, c.num_doc, c.tipo_doc AS cliente_tipo_doc,
               c.razon_social AS cliente_razon, c.direccion AS cliente_direccion
        FROM ventas v
        LEFT JOIN clientes c ON v.cliente_id = c.id
        WHERE v.id = ?
    ");
    $st->execute([$id]);
    $venta = $st->fetch();

    if (!$venta || !in_array($venta['tipo_doc'], ['boleta', 'factura'])) {
        setFlash('error', 'Comprobante no encontrado.');
        redirect(BASE_URL . 'modules/ventas/facturacion.php');
    }

    $detalles = $db->prepare("
        SELECT vd.*, p.codigo AS prod_codigo, p.nombre AS prod_nombre
        FROM venta_detalle vd
        LEFT JOIN productos p ON vd.producto_id = p.id
        WHERE vd.venta_id = ?
    ");
    $detalles->execute([$id]);
    $detalles = $detalles->fetchAll();

    $pageTitle = ucfirst($venta['tipo_doc']) . ' ' . ($venta['serie'] ?? '') . '-' . str_pad((string)($venta['numero'] ?? ''), 8, '0', STR_PAD_LEFT) . ' — Facturación';
    $breadcrumb = [
        ['label' => 'Ventas', 'url' => BASE_URL . 'modules/ventas/index.php'],
        ['label' => 'Facturación', 'url' => BASE_URL . 'modules/ventas/facturacion.php'],
        ['label' => 'Ver', 'url' => null],
    ];

    require_once __DIR__ . '/../../includes/header.php';

    $se = $venta['sunat_estado'] ?? '';
    $tc = $venta['tipo_doc'];
    $sunatBadge = $se === 'aceptado' ? 'bg-success' : ($se === 'rechazado' ? 'bg-danger' : ($se === 'pendiente' ? 'bg-warning text-dark' : 'bg-secondary'));
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <span class="badge <?= $tc === 'factura' ? 'bg-primary' : 'bg-info' ?> me-2"><?= strtoupper($tc) ?></span>
        <span class="fw-bold"><?= sanitize($venta['serie'] ?? '') ?>-<?= str_pad((string)($venta['numero'] ?? ''), 8, '0', STR_PAD_LEFT) ?></span>
        <span class="badge <?= $sunatBadge ?> ms-2"><?= $se ? strtoupper($se) : 'SIN XML' ?></span>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>modules/ventas/ticket.php?id=<?= $venta['id'] ?>" target="_blank" class="btn btn-outline-secondary btn-sm"><i data-feather="printer" style="width:14px"></i> Imprimir</a>
        <a href="?accion=lista" class="btn btn-outline-secondary btn-sm"><i data-feather="arrow-left" style="width:14px"></i> Volver</a>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="tr-card mb-3">
            <div class="tr-card-header"><h6 class="mb-0 small fw-semibold">DATOS DEL COMPROBANTE</h6></div>
            <div class="tr-card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <small class="text-muted">Cliente</small>
                        <div class="fw-semibold"><?= sanitize($venta['cliente_nombre'] ?? 'Consumidor Final') ?></div>
                        <?php if ($tc === 'factura' && !empty($venta['num_doc'])): ?>
                            <small>RUC: <?= sanitize($venta['num_doc']) ?></small>
                        <?php elseif (!empty($venta['num_doc'])): ?>
                            <small>DNI: <?= sanitize($venta['num_doc']) ?></small>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6 text-md-end">
                        <small class="text-muted">Fecha</small>
                        <div><?= formatDateTime($venta['created_at']) ?></div>
                        <small class="text-muted">Código: <?= sanitize($venta['codigo']) ?></small>
                    </div>
                </div>
            </div>
        </div>

        <div class="tr-card mb-3">
            <div class="tr-card-body p-0">
                <table class="table mb-0">
                    <thead><tr><th>Producto</th><th class="text-center">Cant.</th><th class="text-end">P. Unit.</th><th class="text-end">Subtotal</th></tr></thead>
                    <tbody>
                    <?php foreach ($detalles as $d): ?>
                        <tr>
                            <td><?= sanitize($d['concepto'] ?? ($d['prod_nombre'] ?? '—')) ?>
                                <?php if ($d['prod_codigo']): ?><br><small class="text-muted"><?= sanitize($d['prod_codigo']) ?></small><?php endif; ?>
                            </td>
                            <td class="text-center mon"><?= (float)$d['cantidad'] ?></td>
                            <td class="text-end mon"><?= formatMoney((float)$d['precio_unit']) ?></td>
                            <td class="text-end mon fw-bold"><?= formatMoney((float)$d['subtotal']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="p-3 rounded" style="background:var(--bg-light, #f8f9fa)">
            <div class="d-flex justify-content-between"><span class="text-muted">Subtotal</span><span class="mon"><?= formatMoney((float)$venta['subtotal']) ?></span></div>
            <?php if ((float)$venta['descuento'] > 0): ?>
                <div class="d-flex justify-content-between"><span class="text-danger">Descuento</span><span class="mon text-danger">-<?= formatMoney((float)$venta['descuento']) ?></span></div>
            <?php endif; ?>
            <div class="d-flex justify-content-between"><span class="text-muted">IGV (18%)</span><span class="mon"><?= formatMoney((float)$venta['igv']) ?></span></div>
            <hr>
            <div class="d-flex justify-content-between align-items-center">
                <strong>TOTAL</strong>
                <span class="mon fw-bold fs-5"><?= formatMoney((float)$venta['total']) ?></span>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="tr-card mb-3">
            <div class="tr-card-header"><h6 class="mb-0 small fw-semibold"><i data-feather="cloud" style="width:14px"></i> SUNAT</h6></div>
            <div class="tr-card-body">
                <?php if (!empty($venta['sunat_mensaje'])): ?>
                    <div class="small mb-2" style="color:var(--text-muted)"><?= sanitize($venta['sunat_mensaje']) ?></div>
                <?php endif; ?>
                <?php if (!empty($venta['sunat_hash'])): ?>
                    <div class="small mb-3 text-muted" style="word-break:break-all;font-size:10px"><strong>Hash:</strong> <?= sanitize($venta['sunat_hash']) ?></div>
                <?php endif; ?>
                <div class="d-grid gap-2">
                    <?php if (!empty($venta['sunat_xml'])): ?>
                        <a href="?accion=xml&id=<?= $id ?>" target="_blank" class="btn btn-dk btn-sm"><i data-feather="code" style="width:13px"></i> Ver XML</a>
                        <a href="?accion=xml&id=<?= $id ?>&dl=1" class="btn btn-outline-secondary btn-sm"><i data-feather="download" style="width:13px"></i> Descargar XML</a>
                    <?php endif; ?>
                    <?php if (!empty($venta['sunat_xml']) && $se !== 'aceptado'): ?>
                        <form method="POST">
                            <input type="hidden" name="accion" value="enviar_sunat"/>
                            <input type="hidden" name="id" value="<?= $id ?>"/>
                            <button type="submit" class="btn btn-primary btn-sm w-100"><i data-feather="send" style="width:13px"></i> Enviar a SUNAT</button>
                        </form>
                    <?php endif; ?>
                    <?php if (!empty($venta['sunat_cdr'])): ?>
                        <a href="?accion=cdr&id=<?= $id ?>" class="btn btn-success btn-sm"><i data-feather="download" style="width:13px"></i> Descargar CDR</a>
                    <?php endif; ?>
                    <?php if (empty($venta['sunat_xml']) || $se !== 'aceptado'): ?>
                        <form method="POST">
                            <input type="hidden" name="accion" value="regenerar"/>
                            <input type="hidden" name="id" value="<?= $id ?>"/>
                            <button type="submit" class="btn btn-outline-secondary btn-sm w-100"><i data-feather="refresh-cw" style="width:13px"></i> Regenerar XML</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="tr-card">
            <div class="tr-card-header"><h6 class="mb-0 small fw-semibold">ACCIONES</h6></div>
            <div class="tr-card-body d-grid gap-2">
                <a href="<?= BASE_URL ?>modules/ventas/pos.php" class="btn btn-primary btn-sm"><i data-feather="plus" style="width:13px"></i> Nueva venta</a>
                <a href="<?= BASE_URL ?>modules/ventas/index.php" class="btn btn-outline-secondary btn-sm"><i data-feather="list" style="width:13px"></i> Ver todas las ventas</a>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php';

} else {
    redirect(BASE_URL . 'modules/ventas/facturacion.php');
}
?>