<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';
requireLogin();
requireRole([ROL_ADMIN, ROL_VENDEDOR]);

$db   = getDB();
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'procesar_venta') {
    try {
        $items     = json_decode($_POST['items'] ?? '[]', true);
        $clienteId = (int)($_POST['cliente_id'] ?? 0) ?: null;
        $metPago   = $_POST['metodo_pago'] ?? 'efectivo';
        $tipoDoc   = $_POST['tipo_doc']    ?? 'boleta';
        $descGlobal= (float)($_POST['descuento_global'] ?? 0);

        if (empty($items)) {
            header('Content-Type: application/json');
            echo json_encode(['success'=>false,'error'=>'No hay productos']);
            exit;
        }

        $subtotal = 0;
        foreach ($items as $item) {
            $subtotal += (float)$item['precio'] * (float)$item['cantidad'];
        }
        $subtotal -= $descGlobal;
        $total    = round($subtotal, 2);
        $base     = round($total / 1.18, 2);
        $igv      = round($total - $base, 2);

        $codigo = generarCodigoVenta($db);
        $esSunat = in_array($tipoDoc, ['boleta','factura'], true);

        $serie = '';
        $numero = 0;
        $sunatOk = false;
        $sunatMsg = '';

        if ($esSunat) {
            require_once __DIR__ . '/../../includes/sunat/SunatService.php';

            $pdo = getDB();
            $pdo->beginTransaction();
            try {
                $st = $pdo->prepare("SELECT id, serie, numero FROM documentos_empresa WHERE empresa_id=1 AND tipo=? AND activo=1 ORDER BY id ASC LIMIT 1 FOR UPDATE");
                $st->execute([$tipoDoc]);
                $cor = $st->fetch();
                if (!$cor) {
                    throw new Exception("No existe correlativo para $tipoDoc. Configura la serie en Configuracion.");
                }
                $numero = (int)$cor['numero'] + 1;
                $pdo->prepare("UPDATE documentos_empresa SET numero=? WHERE id=?")->execute([$numero, $cor['id']]);
                $serie = $cor['serie'];

                $pdo->prepare("INSERT INTO ventas (codigo,cliente_id,usuario_id,tipo_doc,serie,numero,subtotal,igv,descuento,total,metodo_pago,monto_pagado) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute([$codigo,$clienteId,$user['id'],$tipoDoc,$serie,$numero,$base,$igv,$descGlobal,$total,$metPago,$_POST['monto_pagado']??$total]);
                $ventaId = $pdo->lastInsertId();

                foreach ($items as $item) {
                    $pid  = (int)$item['id'];
                    $cant = (float)$item['cantidad'];
                    $precio = (float)$item['precio'];
                    $subtItem = $cant * $precio;

                    $pdo->prepare("INSERT INTO venta_detalle (venta_id,producto_id,cantidad,precio_unit,subtotal) VALUES (?,?,?,?,?)")
                       ->execute([$ventaId,$pid,$cant,$precio,$subtItem]);

                    $prod = $pdo->prepare("SELECT stock_actual FROM productos WHERE id=?");
                    $prod->execute([$pid]);
                    $antes = (float)$prod->fetchColumn();
                    $despues = $antes - $cant;
                    $pdo->prepare("UPDATE productos SET stock_actual=? WHERE id=?")->execute([$despues,$pid]);
                    $pdo->prepare("INSERT INTO kardex (producto_id,tipo,cantidad,stock_antes,stock_despues,precio_unit,motivo,referencia,usuario_id) VALUES (?,?,?,?,?,?,?,?,?)")
                       ->execute([$pid,'salida',$cant,$antes,$despues,$precio,'Venta',$codigo,$user['id']]);
                }

                $caja = $pdo->prepare("SELECT id FROM cajas WHERE fecha=CURDATE() AND estado='abierta' ORDER BY id DESC LIMIT 1");
                $caja->execute();
                $cajaId = $caja->fetchColumn();
                if ($cajaId) {
                    $pdo->prepare("INSERT INTO movimientos_caja (caja_id,tipo,concepto,monto,referencia,usuario_id) VALUES (?,?,?,?,?,?)")
                       ->execute([$cajaId,'ingreso','Venta '.$codigo,$total,$codigo,$user['id']]);
                }

                $pdo->commit();

                $sunat = new SunatService($pdo);
                $res = $sunat->generarXml((int)$ventaId);
                $sunatOk = $res['ok'];
                $sunatMsg = $res['mensaje'] ?? '';

                if (!$sunatOk) {
                    $pdo2 = getDB();
                    $pdo2->prepare("DELETE FROM movimientos_caja WHERE referencia=?")->execute([$codigo]);
                    $pdo2->prepare("DELETE FROM venta_detalle WHERE venta_id=?")->execute([$ventaId]);
                    $pdo2->prepare("DELETE FROM ventas WHERE id=?")->execute([$ventaId]);
                    $pdo2->prepare("UPDATE documentos_empresa SET numero=numero-1 WHERE id=?")->execute([$cor['id']]);

                    foreach ($items as $item) {
                        $pid = (int)$item['id'];
                        $cant = (float)$item['cantidad'];
                        $pdo2->prepare("UPDATE productos SET stock_actual=stock_actual+? WHERE id=?")->execute([$cant,$pid]);
                    }

                    header('Content-Type: application/json');
                    echo json_encode([
                        'success'=>false,
                        'error'=>'SUNAT rechazo la generacion del XML: '.$sunatMsg,
                        'sunat_reject'=>true,
                    ]);
                    exit;
                }

            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                header('Content-Type: application/json');
                echo json_encode(['success'=>false,'error'=>'Error al procesar venta: '.$e->getMessage()]);
                exit;
            }

            header('Content-Type: application/json');
            echo json_encode([
                'success'=>true,
                'codigo'=>$codigo,
                'total'=>$total,
                'venta_id'=>$ventaId,
                'sunat_xml'=>$sunatOk,
                'sunat_msg'=>$sunatMsg,
                'serie'=>$serie,
                'numero'=>str_pad((string)$numero, 8, '0', STR_PAD_LEFT),
            ]);
            exit;
        }

        $items_productos = array_filter($items, fn($i) => empty($i['es_ot']));
        $items_ot        = array_filter($items, fn($i) => !empty($i['es_ot']));

        $notas_ot = '';
        foreach ($items_ot as $iot) {
            $notas_ot .= '##OT##' . ($iot['nombre'] ?? '') . '##PRECIO##' . number_format((float)$iot['precio'], 2) . '##FIN## ';
        }
        $notas_manual = trim($_POST['notas'] ?? '');
        $notas_final  = trim($notas_ot . $notas_manual) ?: null;

        $db->prepare("INSERT INTO ventas (codigo,cliente_id,usuario_id,tipo_doc,serie_doc,num_doc,subtotal,igv,descuento,total,metodo_pago,monto_pagado,notas) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([
               $codigo, $clienteId, $user['id'], $tipoDoc,
               $serie, $numero ? str_pad((string)$numero, 8, '0', STR_PAD_LEFT) : null,
               $base, $igv, $descGlobal, $total, $metPago,
               $_POST['monto_pagado'] ?? $total,
               $notas_final
           ]);
        $ventaId = $db->lastInsertId();

        foreach ($items_productos as $item) {
            $pid  = (int)$item['id'];
            $cant = (float)$item['cantidad'];
            $precio = (float)$item['precio'];
            $subtItem = $cant * $precio;

            $db->prepare("INSERT INTO venta_detalle (venta_id,producto_id,cantidad,precio_unit,subtotal) VALUES (?,?,?,?,?)")
               ->execute([$ventaId, $pid, $cant, $precio, $subtItem]);

            $prod = $db->prepare("SELECT stock_actual FROM productos WHERE id=?");
            $prod->execute([$pid]);
            $antes = (float)$prod->fetchColumn();
            $despues = $antes - $cant;
            $db->prepare("UPDATE productos SET stock_actual=? WHERE id=?")->execute([$despues,$pid]);
            $db->prepare("INSERT INTO kardex (producto_id,tipo,cantidad,stock_antes,stock_despues,precio_unit,motivo,referencia,usuario_id) VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute([$pid,'salida',$cant,$antes,$despues,$precio,'Venta',$codigo,$user['id']]);
        }

        $caja = $db->prepare("SELECT id FROM cajas WHERE fecha=CURDATE() AND estado='abierta' ORDER BY id DESC LIMIT 1");
        $caja->execute();
        $cajaId = $caja->fetchColumn();
        if ($cajaId) {
            $db->prepare("INSERT INTO movimientos_caja (caja_id,tipo,concepto,monto,referencia,usuario_id) VALUES (?,?,?,?,?,?)")
               ->execute([$cajaId,'ingreso','Venta '.$codigo,$total,$codigo,$user['id']]);
        }

        foreach ($items_ot as $item) {
            if (!empty($item['es_ot']) && !empty($item['ot_id'])) {
                $db->prepare("UPDATE ordenes_trabajo SET pagado=1, fecha_pago=NOW(), metodo_pago=? WHERE id=? AND pagado=0")
                   ->execute([$metPago, (int)$item['ot_id']]);
            }
        }

        header('Content-Type: application/json');
        echo json_encode([
            'success'=>true,
            'codigo'=>$codigo,
            'total'=>$total,
            'venta_id'=>$ventaId,
            'sunat_xml'=>false,
            'sunat_msg'=>'Nota de venta / Ticket / OT - No requiere XML SUNAT',
            'serie'=>'',
            'numero'=>'',
        ]);
        exit;

    } catch (Throwable $e) {
        header('Content-Type: application/json');
        echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
        exit;
    }
}

if (isset($_GET['api']) && $_GET['api'] === 'buscar') {
    header('Content-Type: application/json');
    $q = '%' . trim($_GET['q'] ?? '') . '%';
    $r = $db->prepare("SELECT id,codigo,nombre,precio_venta,stock_actual,unidad FROM productos WHERE activo=1 AND stock_actual>0 AND (nombre LIKE ? OR codigo LIKE ?) LIMIT 20");
    $r->execute([$q,$q]);
    echo json_encode($r->fetchAll());
    exit;
}

if (isset($_GET['api']) && $_GET['api'] === 'buscar_cliente') {
    header('Content-Type: application/json');
    $q = '%' . trim($_GET['q'] ?? '') . '%';
    $r = $db->prepare("SELECT id, nombre, telefono, ruc_dni FROM clientes WHERE activo=1 AND (nombre LIKE ? OR telefono LIKE ? OR ruc_dni LIKE ?) LIMIT 15");
    $r->execute([$q,$q,$q]);
    echo json_encode($r->fetchAll());
    exit;
}

if (isset($_GET['api']) && $_GET['api'] === 'buscar_ot') {
    header('Content-Type: application/json');
    $q = '%' . trim($_GET['q'] ?? '') . '%';
    $r = $db->prepare("
        SELECT ot.id, ot.codigo_ot, ot.precio_final, ot.descuento, ot.costo_mano_obra,
               ot.estado, ot.pagado,
               c.id as cliente_id, c.nombre as cliente_nombre, c.telefono as cliente_tel,
               CONCAT(te.nombre,' ',COALESCE(e.marca,''),' ',COALESCE(e.modelo,'')) as equipo_desc,
               s.nombre as servicio_nombre
        FROM ordenes_trabajo ot
        JOIN clientes c ON c.id = ot.cliente_id
        JOIN equipos e ON e.id = ot.equipo_id
        JOIN tipos_equipo te ON te.id = e.tipo_equipo_id
        LEFT JOIN servicios s ON s.id = ot.servicio_id
        WHERE ot.pagado = 0
          AND ot.estado NOT IN ('cancelado')
          AND (ot.codigo_ot LIKE ? OR c.nombre LIKE ? OR ot.codigo_publico LIKE ?)
        ORDER BY ot.created_at DESC
        LIMIT 15
    ");
    $r->execute([$q,$q,$q]);
    echo json_encode($r->fetchAll());
    exit;
}

$correlativos = [];
$stCorr = $db->query("SELECT tipo, serie, numero FROM documentos_empresa WHERE activo=1");
while ($row = $stCorr->fetch()) {
    $correlativos[$row['tipo']] = [
        'serie' => $row['serie'],
        'numero' => (int)$row['numero'] + 1,
    ];
}

$pageTitle  = 'Punto de venta — ' . APP_NAME;
$breadcrumb = [['label'=>'Ventas','url'=>BASE_URL.'modules/ventas/index.php'],['label'=>'POS','url'=>null]];
require_once __DIR__ . '/../../includes/header.php';
?>

<h5 class="fw-bold mb-3">Punto de venta</h5>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="tr-card mb-3">
      <div class="tr-card-header"><h6 class="mb-0 small fw-semibold">BUSCAR PRODUCTO</h6></div>
      <div class="tr-card-body">
        <div class="input-group mb-3">
          <span class="input-group-text"><i data-feather="search" style="width:16px;height:16px"></i></span>
          <input type="text" id="buscar-producto" class="form-control" placeholder="Nombre o código del producto..." autocomplete="off"/>
        </div>
        <div id="resultados-busqueda" class="list-group"></div>
      </div>
    </div>

    <div class="tr-card">
      <div class="tr-card-header">
        <h6 class="mb-0 small fw-semibold">CARRITO</h6>
        <button class="btn btn-outline-danger btn-sm" onclick="limpiarCarrito()">🗑 Limpiar</button>
      </div>
      <div class="tr-card-body p-0">
        <table class="tr-table" id="tabla-carrito">
          <thead><tr><th>Producto</th><th>Precio</th><th>Cant.</th><th>Subtotal</th><th></th></tr></thead>
          <tbody id="carrito-body">
            <tr id="carrito-vacio"><td colspan="5" class="text-center text-muted py-4">Carrito vacío</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="tr-card">
      <div class="tr-card-header"><h6 class="mb-0 small fw-semibold">RESUMEN DE VENTA</h6></div>
      <div class="tr-card-body">
        <div class="mb-3">
          <label class="tr-form-label">Cliente (opcional)</label>
          <div class="position-relative">
            <div class="input-group input-group-sm">
              <span class="input-group-text"><i data-feather="user" style="width:14px;height:14px"></i></span>
              <input type="text" id="buscar-cliente-input" class="form-control form-control-sm"
                     placeholder="Buscar por nombre, teléfono o doc..." autocomplete="off"/>
              <button type="button" class="btn btn-outline-secondary btn-sm" onclick="limpiarCliente()" title="Quitar cliente" id="btn-limpiar-cliente" style="display:none">
                <i data-feather="x" style="width:13px;height:13px"></i>
              </button>
            </div>
            <div id="lista-clientes" class="list-group position-absolute w-100 shadow-sm" style="z-index:9999; display:none; max-height:200px; overflow-y:auto; top:100%"></div>
          </div>
          <div id="cliente-seleccionado" class="mt-1" style="display:none">
            <span class="badge bg-primary" style="font-size:12px; padding:6px 10px">
              <i data-feather="check" style="width:12px;height:12px"></i>
              <span id="cliente-nombre-badge"></span>
            </span>
          </div>
          <input type="hidden" id="sel-cliente-venta" value=""/>
        </div>

        <div class="mb-3">
          <label class="tr-form-label d-flex align-items-center gap-2">
            <i data-feather="file-text" style="width:14px;height:14px"></i>
            Cargar desde OT (cobranza)
          </label>
          <div class="position-relative">
            <div class="input-group input-group-sm">
              <span class="input-group-text"><i data-feather="search" style="width:14px;height:14px"></i></span>
              <input type="text" id="buscar-ot-input" class="form-control form-control-sm"
                     placeholder="Buscar OT por código o cliente..." autocomplete="off"/>
            </div>
            <div id="lista-ots" class="list-group position-absolute w-100 shadow-sm" style="z-index:9998; display:none; max-height:240px; overflow-y:auto; top:100%"></div>
          </div>
          <div id="ot-cargada" class="mt-2 p-2 rounded" style="display:none; background:rgba(79,70,229,0.07); border:1px solid rgba(79,70,229,0.2)">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <div class="fw-semibold text-primary" style="font-size:13px" id="ot-codigo-badge"></div>
                <div class="text-muted" style="font-size:11px" id="ot-equipo-badge"></div>
                <div class="text-muted" style="font-size:11px" id="ot-servicio-badge"></div>
              </div>
              <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" onclick="limpiarOT()" title="Quitar OT">
                <i data-feather="x" style="width:12px;height:12px"></i>
              </button>
            </div>
          </div>
        </div>

        <div class="mb-2">
          <label class="tr-form-label">Comprobante</label>
          <select id="tipo-doc" class="form-select form-select-sm">
            <option value="boleta" selected>Boleta</option>
            <option value="factura">Factura</option>
            <option value="ticket">Ticket</option>
            <option value="nota_venta">Nota de venta</option>
          </select>
        </div>
        <div id="correlativo-info" class="mb-3 text-primary fw-semibold" style="font-size:15px"></div>

        <div class="mb-3">
          <label class="tr-form-label">Descuento global (S/)</label>
          <input type="number" id="descuento-global" class="form-control form-control-sm currency-input" value="0" step="0.01" min="0"/>
        </div>

        <div class="bg-light rounded p-3 mb-3">
          <div class="d-flex justify-content-between small mb-1"><span>Base imponible:</span><span id="txt-subtotal">S/ 0.00</span></div>
          <div class="d-flex justify-content-between small mb-1"><span>IGV (18%):</span><span id="txt-igv">S/ 0.00</span></div>
          <div class="d-flex justify-content-between small mb-1 text-danger"><span>Descuento:</span><span id="txt-desc">S/ 0.00</span></div>
          <hr class="my-2">
          <div class="d-flex justify-content-between fw-bold fs-5"><span>TOTAL:</span><span id="txt-total">S/ 0.00</span></div>
        </div>

        <div class="mb-3">
          <label class="tr-form-label">Método de pago</label>
          <div class="d-flex gap-2 flex-wrap">
            <?php foreach (['efectivo'=>'💵 Efectivo','yape'=>'💜 Yape','plin'=>'💚 Plin','tarjeta'=>'💳 Tarjeta'] as $val=>$lbl): ?>
            <div>
              <input type="radio" class="btn-check" name="metodo_pago_radio" id="mp_<?= $val ?>" value="<?= $val ?>" <?= $val==='efectivo'?'checked':'' ?>>
              <label class="btn btn-outline-secondary btn-sm" for="mp_<?= $val ?>"><?= $lbl ?></label>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="mb-3" id="bloque-efectivo">
          <label class="tr-form-label">Monto recibido (S/)</label>
          <input type="number" id="monto-pagado" class="form-control form-control-sm currency-input" step="0.01"/>
          <div class="mt-1 small text-success" id="txt-vuelto"></div>
        </div>

        <div class="mb-3">
          <label class="tr-form-label">Notas (opcional)</label>
          <textarea id="pos-notas" class="form-control form-control-sm" rows="2"
                    placeholder="Observaciones, referencias..."></textarea>
        </div>

        <button id="btn-confirmar-venta" class="btn btn-primary w-100 btn-lg" onclick="procesarVenta()">
          <i data-feather="check-circle" style="width:18px;height:18px"></i> Confirmar venta
        </button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="modal-ticket" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title">✅ Venta registrada</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body text-center">
        <div class="fs-1">🎉</div>
        <div id="ticket-codigo" class="fw-bold fs-5 mt-2"></div>
        <div id="ticket-total" class="text-muted"></div>
        <div id="sunat-info-ticket" class="mt-2"></div>
        <div class="d-flex gap-2 justify-content-center mt-3">
          <button class="btn btn-primary btn-sm" onclick="nuevaVenta()">Nueva venta</button>
          <a id="btn-imprimir-ticket" href="#" target="_blank" class="btn btn-outline-secondary btn-sm">Imprimir</a>
        </div>
      </div>
    </div>
  </div>
</div>

<?php
$pageScripts = <<<'JS'
<script>
const BASE_URL_JS = document.querySelector('meta[name=base-url]')?.content || '';
let carrito = [];

let timeoutBusq;
document.getElementById('buscar-producto').addEventListener('input', function() {
  clearTimeout(timeoutBusq);
  const q = this.value.trim();
  if (q.length < 2) { document.getElementById('resultados-busqueda').innerHTML=''; return; }
  timeoutBusq = setTimeout(() => {
    fetch('pos.php?api=buscar&q=' + encodeURIComponent(q))
      .then(r=>r.json()).then(data => {
        const div = document.getElementById('resultados-busqueda');
        if (!data.length) { div.innerHTML='<div class="list-group-item text-muted small">Sin resultados</div>'; return; }
        div.innerHTML = data.map(p => `
          <button type="button" class="list-group-item list-group-item-action d-flex justify-content-between"
                  onclick="agregarCarrito(${JSON.stringify(p).replace(/"/g,'&quot;')})">
            <div>
              <div class="fw-semibold small">${p.nombre}</div>
              <div class="text-muted" style="font-size:11px">${p.codigo}</div>
            </div>
            <div class="text-end">
              <div class="text-primary fw-bold">S/ ${parseFloat(p.precio_venta).toFixed(2)}</div>
              <div class="text-muted small">Stock: ${p.stock_actual}</div>
            </div>
          </button>`).join('');
      });
  }, 300);
});

function agregarCarrito(p) {
  const idx = carrito.findIndex(i=>i.id==p.id);
  if (idx>=0) carrito[idx].cantidad++;
  else carrito.push({id:p.id, nombre:p.nombre, precio:parseFloat(p.precio_venta), cantidad:1, stock:parseFloat(p.stock_actual)});
  renderCarrito();
  document.getElementById('buscar-producto').value='';
  document.getElementById('resultados-busqueda').innerHTML='';
}

function renderCarrito() {
  const tbody = document.getElementById('carrito-body');
  if (!carrito.length) {
    tbody.innerHTML='<tr id="carrito-vacio"><td colspan="5" class="text-center text-muted py-4">Carrito vacío</td></tr>';
    calcularTotales(); return;
  }
  tbody.innerHTML = carrito.map((item,i) => `
    <tr>
      <td class="small">${item.nombre}</td>
      <td><input type="number" class="form-control form-control-sm" style="width:80px" value="${item.precio.toFixed(2)}" step="0.01"
                 onchange="carrito[${i}].precio=parseFloat(this.value)||0;renderCarrito()"/></td>
      <td><input type="number" class="form-control form-control-sm" style="width:65px" value="${item.cantidad}" min="1" max="${item.stock}"
                 onchange="carrito[${i}].cantidad=parseInt(this.value)||1;renderCarrito()"/></td>
      <td class="fw-semibold">S/ ${(item.precio*item.cantidad).toFixed(2)}</td>
      <td><button class="btn btn-sm btn-outline-danger py-0" onclick="carrito.splice(${i},1);renderCarrito()">✕</button></td>
    </tr>`).join('');
  calcularTotales();
}

function calcularTotales() {
  const desc = parseFloat(document.getElementById('descuento-global').value)||0;
  const total = carrito.reduce((s,i)=>s+(i.precio*i.cantidad),0) - desc;
  const base  = total / 1.18;
  const igv   = total - base;
  document.getElementById('txt-subtotal').textContent='S/ '+base.toFixed(2);
  document.getElementById('txt-igv').textContent='S/ '+igv.toFixed(2);
  document.getElementById('txt-desc').textContent='S/ '+desc.toFixed(2);
  document.getElementById('txt-total').textContent='S/ '+total.toFixed(2);
  const pagado = parseFloat(document.getElementById('monto-pagado').value)||0;
  document.getElementById('txt-vuelto').textContent = pagado>0 ? 'Vuelto: S/ '+Math.max(0,pagado-total).toFixed(2) : '';
}

document.getElementById('descuento-global').addEventListener('input', calcularTotales);
document.getElementById('monto-pagado').addEventListener('input', calcularTotales);

function limpiarCarrito() { carrito=[]; renderCarrito(); }

function procesarVenta() {
  if (!carrito.length) {
    const btn = document.getElementById('btn-confirmar-venta');
    btn.classList.add('btn-danger');
    btn.innerHTML = '<i data-feather="alert-circle" style="width:18px;height:18px"></i> Agrega productos primero';
    if (typeof feather !== 'undefined') feather.replace();
    setTimeout(() => {
      btn.classList.remove('btn-danger');
      btn.innerHTML = '<i data-feather="check-circle" style="width:18px;height:18px"></i> Confirmar venta';
      if (typeof feather !== 'undefined') feather.replace();
    }, 2000);
    return;
  }
  const btn = document.getElementById('btn-confirmar-venta');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Procesando...';
  const metodo = document.querySelector('input[name=metodo_pago_radio]:checked').value;
  const payload = new FormData();
  payload.append('action','procesar_venta');
  payload.append('items', JSON.stringify(carrito));
  payload.append('cliente_id', document.getElementById('sel-cliente-venta').value);
  payload.append('tipo_doc', document.getElementById('tipo-doc').value);
  payload.append('metodo_pago', metodo);
  payload.append('descuento_global', document.getElementById('descuento-global').value);
  payload.append('monto_pagado', document.getElementById('monto-pagado').value||document.getElementById('txt-total').textContent.replace('S/ ',''));
  payload.append('notas', document.getElementById('pos-notas').value);

  fetch('pos.php', {method:'POST', body:payload})
    .then(r=>r.json()).then(data=>{
      if (data.sunat_reject) {
        alert('⚠️ RECHAZO DE SUNAT:\n\n' + data.error + '\n\nLa venta NO fue registrada. Corrija los datos e intente nuevamente.');
        return;
      }
      if (data.success){
        document.getElementById('ticket-codigo').textContent=data.codigo;
        document.getElementById('ticket-total').textContent='Total: S/ '+parseFloat(data.total).toFixed(2);
        document.getElementById('btn-imprimir-ticket').href='ticket.php?id='+data.venta_id+'&print=1';
        const sunatDiv = document.getElementById('sunat-info-ticket');
        if (data.sunat_xml) {
          const serieNum = data.serie ? data.serie+'-'+data.numero : '';
          sunatDiv.innerHTML = '<div class="mt-2 p-2 rounded" style="background:rgba(0,200,100,.1);font-size:11px"><i class="bi bi-check-circle" style="color:#00c864"></i> XML generado ' + serieNum + '<br><small class="text-muted">'+data.sunat_msg+'</small></div>';
        } else {
          sunatDiv.innerHTML = '<div class="mt-2 small text-muted">'+data.sunat_msg+'</div>';
        }
        new bootstrap.Modal(document.getElementById('modal-ticket')).show();
        limpiarCarrito();
      } else {
        alert('❌ Error al procesar la venta:\n\n' + (data.error || 'Error desconocido.'));
      }
    })
    .catch(() => alert('Error de conexión.'))
    .finally(() => {
      btn.disabled = false;
      btn.innerHTML = '<i data-feather="check-circle" style="width:18px;height:18px"></i> Confirmar venta';
      if (typeof feather !== 'undefined') feather.replace();
    });
}

function nuevaVenta() {
  bootstrap.Modal.getInstance(document.getElementById('modal-ticket'))?.hide();
  limpiarCarrito();
  limpiarCliente();
  limpiarOT();
  document.getElementById('pos-notas').value = '';
}

let timeoutCliente;
document.getElementById('buscar-cliente-input').addEventListener('input', function() {
  clearTimeout(timeoutCliente);
  const q = this.value.trim();
  const lista = document.getElementById('lista-clientes');
  if (q.length < 2) { lista.style.display='none'; return; }
  timeoutCliente = setTimeout(() => {
    fetch('pos.php?api=buscar_cliente&q=' + encodeURIComponent(q))
      .then(r=>r.json()).then(data => {
        if (!data.length) {
          lista.innerHTML='<div class="list-group-item text-muted small py-2">Sin resultados</div>';
        } else {
          lista.innerHTML = data.map(c => `
            <button type="button" class="list-group-item list-group-item-action py-2"
                    onclick="seleccionarCliente(${c.id}, ${JSON.stringify(c.nombre).replace(/"/g,'&quot;')})">
              <div class="fw-semibold small">${c.nombre}</div>
              <div class="text-muted" style="font-size:11px">${c.telefono||''} ${c.ruc_dni?'· '+c.ruc_dni:''}</div>
            </button>`).join('');
        }
        lista.style.display = 'block';
      });
  }, 280);
});

function seleccionarCliente(id, nombre) {
  document.getElementById('sel-cliente-venta').value = id;
  document.getElementById('buscar-cliente-input').value = '';
  document.getElementById('buscar-cliente-input').placeholder = nombre;
  document.getElementById('cliente-nombre-badge').textContent = nombre;
  document.getElementById('cliente-seleccionado').style.display = 'block';
  document.getElementById('btn-limpiar-cliente').style.display = 'inline-flex';
  document.getElementById('lista-clientes').style.display = 'none';
  if (typeof feather !== 'undefined') feather.replace();
}

function limpiarCliente() {
  document.getElementById('sel-cliente-venta').value = '';
  document.getElementById('buscar-cliente-input').value = '';
  document.getElementById('buscar-cliente-input').placeholder = 'Buscar por nombre, teléfono o doc...';
  document.getElementById('cliente-seleccionado').style.display = 'none';
  document.getElementById('btn-limpiar-cliente').style.display = 'none';
  document.getElementById('lista-clientes').style.display = 'none';
}

document.addEventListener('click', function(e) {
  if (!e.target.closest('#buscar-cliente-input') && !e.target.closest('#lista-clientes')) {
    document.getElementById('lista-clientes').style.display = 'none';
  }
  if (!e.target.closest('#buscar-ot-input') && !e.target.closest('#lista-ots')) {
    document.getElementById('lista-ots').style.display = 'none';
  }
});

let timeoutOT;
document.getElementById('buscar-ot-input').addEventListener('input', function() {
  clearTimeout(timeoutOT);
  const q = this.value.trim();
  const lista = document.getElementById('lista-ots');
  if (q.length < 2) { lista.style.display='none'; return; }
  timeoutOT = setTimeout(() => {
    fetch('pos.php?api=buscar_ot&q=' + encodeURIComponent(q))
      .then(r=>r.json()).then(data => {
        if (!data.length) {
          lista.innerHTML='<div class="list-group-item text-muted small py-2">Sin OTs pendientes de pago</div>';
        } else {
          lista.innerHTML = data.map(ot => {
            const estadoColor = {
              'ingresado':'secondary','en_revision':'info','en_reparacion':'warning',
              'listo':'success','cliente_citado':'primary'
            }[ot.estado] || 'secondary';
            return `<button type="button" class="list-group-item list-group-item-action py-2"
                    onclick="cargarOT(${JSON.stringify(ot).replace(/"/g,'&quot;')})">
              <div class="d-flex justify-content-between align-items-start">
                <div>
                  <span class="fw-semibold text-primary small">${ot.codigo_ot}</span>
                  <span class="badge bg-${estadoColor} ms-1" style="font-size:10px">${ot.estado.replace('_',' ')}</span>
                  <div class="text-truncate" style="max-width:200px">${ot.cliente_nombre}</div>
                  <div class="text-muted" style="font-size:11px">${ot.equipo_desc}${ot.servicio_nombre?' · '+ot.servicio_nombre:''}</div>
                </div>
                <div class="text-end fw-bold text-primary" style="font-size:13px;white-space:nowrap">
                  S/ ${parseFloat(ot.precio_final).toFixed(2)}
                </div>
              </div>
            </button>`;
          }).join('');
        }
        lista.style.display = 'block';
      });
  }, 280);
});

function cargarOT(ot) {
  seleccionarCliente(ot.cliente_id, ot.cliente_nombre);
  limpiarCarrito();
  const desc = parseFloat(ot.precio_final) > 0 ? ot.precio_final : ot.costo_mano_obra;
  const etiqueta = ot.codigo_ot + (ot.servicio_nombre ? ' — ' + ot.servicio_nombre : '') + ' (' + ot.equipo_desc + ')';
  carrito.push({
    id: 0,
    nombre: etiqueta,
    precio: parseFloat(desc),
    cantidad: 1,
    stock: 9999,
    es_ot: true,
    ot_id: ot.id
  });
  renderCarrito();

  document.getElementById('ot-codigo-badge').textContent = ot.codigo_ot + ' — ' + ot.cliente_nombre;
  document.getElementById('ot-equipo-badge').textContent = ot.equipo_desc;
  document.getElementById('ot-servicio-badge').textContent = ot.servicio_nombre || '';
  document.getElementById('ot-cargada').style.display = 'block';

  document.getElementById('buscar-ot-input').value = '';
  document.getElementById('lista-ots').style.display = 'none';
  if (typeof feather !== 'undefined') feather.replace();
}

function limpiarOT() {
  carrito = carrito.filter(i => !i.es_ot);
  renderCarrito();
  document.getElementById('ot-cargada').style.display = 'none';
  document.getElementById('buscar-ot-input').value = '';
}

document.addEventListener('DOMContentLoaded', function() {
  const el = document.getElementById('tipo-doc');
  if (el) el.addEventListener('change', mostrarCorrelativo);
  mostrarCorrelativo();
});
</script>
JS;

$pageScripts .= '<script>
const CORRELATIVOS = ' . json_encode($correlativos) . ';
function mostrarCorrelativo() {
  const el = document.getElementById("tipo-doc");
  if (!el) return;
  const tipo = el.value;
  const info = document.getElementById("correlativo-info");
  if (CORRELATIVOS[tipo]) {
    const c = CORRELATIVOS[tipo];
    info.textContent = "Correlativo: " + c.serie + "-" + String(c.numero).padStart(8, "0");
  } else if (tipo === "ticket") {
    info.textContent = "Ticket";
  } else if (tipo === "nota_venta") {
    info.textContent = "Nota de venta";
  } else {
    info.textContent = "Sin serie configurada";
  }
}
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
