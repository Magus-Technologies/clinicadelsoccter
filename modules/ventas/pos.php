<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';
requireLogin();
requireRole([ROL_ADMIN, ROL_VENDEDOR]);

$db   = getDB();
$user = currentUser();

// Procesar venta
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

        $serie = '';
        $numero = 0;
        if (in_array($tipoDoc, ['boleta','factura'], true)) {
            try {
                $pdo = getDB();
                $pdo->beginTransaction();
                $st = $pdo->prepare("SELECT id, serie, numero FROM documentos_empresa WHERE empresa_id=1 AND tipo=? AND activo=1 ORDER BY id ASC LIMIT 1 FOR UPDATE");
                $st->execute([$tipoDoc]);
                $cor = $st->fetch();
                if ($cor) {
                    $numero = (int)$cor['numero'] + 1;
                    $pdo->prepare("UPDATE documentos_empresa SET numero=? WHERE id=?")->execute([$numero, $cor['id']]);
                    $serie = $cor['serie'];
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $serie = '';
                $numero = 0;
            }
        }

        $db->prepare("INSERT INTO ventas (codigo,cliente_id,usuario_id,tipo_doc,serie,numero,subtotal,igv,descuento,total,metodo_pago,monto_pagado) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$codigo,$clienteId,$user['id'],$tipoDoc,$serie,$numero,$base,$igv,$descGlobal,$total,$metPago,$_POST['monto_pagado']??$total]);
        $ventaId = $db->lastInsertId();

        foreach ($items as $item) {
            $pid  = (int)$item['id'];
            $cant = (float)$item['cantidad'];
            $precio = (float)$item['precio'];
            $subtItem = $cant * $precio;

            $db->prepare("INSERT INTO venta_detalle (venta_id,producto_id,cantidad,precio_unit,subtotal) VALUES (?,?,?,?,?)")
               ->execute([$ventaId,$pid,$cant,$precio,$subtItem]);

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

        $sunatOk = false;
        $sunatMsg = '';
        if (in_array($tipoDoc, ['boleta','factura'], true) && $serie && $numero) {
            try {
                require_once __DIR__ . '/../../includes/sunat/SunatService.php';
                $sunat = new SunatService($db);
                $res = $sunat->generarXml((int)$ventaId);
                $sunatOk = $res['ok'];
                $sunatMsg = $res['mensaje'] ?? '';
            } catch (Throwable $e) {
                $sunatMsg = 'SUNAT: ' . $e->getMessage();
                $sunatOk = false;
            }
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
            'numero'=>$numero ? str_pad((string)$numero, 8, '0', STR_PAD_LEFT) : '',
        ]);
        exit;

    } catch (Throwable $e) {
        header('Content-Type: application/json');
        echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
        exit;
    }
}

// Buscar productos (API)
if (isset($_GET['api']) && $_GET['api'] === 'buscar') {
    header('Content-Type: application/json');
    $q = '%' . trim($_GET['q'] ?? '') . '%';
    $r = $db->prepare("SELECT id,codigo,nombre,precio_venta,stock_actual,unidad FROM productos WHERE activo=1 AND stock_actual>0 AND (nombre LIKE ? OR codigo LIKE ?) LIMIT 20");
    $r->execute([$q,$q]);
    echo json_encode($r->fetchAll());
    exit;
}

$clientes = $db->query("SELECT id,codigo,nombre FROM clientes WHERE activo=1 ORDER BY nombre LIMIT 500")->fetchAll();

// Obtener correlativos actuales para mostrar en UI
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
  <!-- Buscador de productos -->
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

    <!-- Carrito -->
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

  <!-- Resumen y pago -->
  <div class="col-lg-5">
    <div class="tr-card">
      <div class="tr-card-header"><h6 class="mb-0 small fw-semibold">RESUMEN DE VENTA</h6></div>
      <div class="tr-card-body">
        <!-- Cliente -->
        <div class="mb-3">
          <label class="tr-form-label">Cliente (opcional)</label>
          <select id="sel-cliente-venta" class="form-select form-select-sm">
            <option value="">— Sin cliente —</option>
            <?php foreach ($clientes as $c): ?>
            <option value="<?= $c['id'] ?>"><?= sanitize($c['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
<!-- Tipo comprobante -->
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
        <!-- Descuento -->
        <div class="mb-3">
          <label class="tr-form-label">Descuento global (S/)</label>
          <input type="number" id="descuento-global" class="form-control form-control-sm currency-input" value="0" step="0.01" min="0"/>
        </div>
        <!-- Totales -->
        <div class="bg-light rounded p-3 mb-3">
          <div class="d-flex justify-content-between small mb-1"><span>Base imponible:</span><span id="txt-subtotal">S/ 0.00</span></div>
          <div class="d-flex justify-content-between small mb-1"><span>IGV (18%):</span><span id="txt-igv">S/ 0.00</span></div>
          <div class="d-flex justify-content-between small mb-1 text-danger"><span>Descuento:</span><span id="txt-desc">S/ 0.00</span></div>
          <hr class="my-2">
          <div class="d-flex justify-content-between fw-bold fs-5"><span>TOTAL:</span><span id="txt-total">S/ 0.00</span></div>
        </div>
        <!-- Método pago -->
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
        <!-- Monto pagado (efectivo) -->
        <div class="mb-3" id="bloque-efectivo">
          <label class="tr-form-label">Monto recibido (S/)</label>
          <input type="number" id="monto-pagado" class="form-control form-control-sm currency-input" step="0.01"/>
          <div class="mt-1 small text-success" id="txt-vuelto"></div>
        </div>

        <button id="btn-confirmar-venta" class="btn btn-primary w-100 btn-lg" onclick="procesarVenta()">
          <i data-feather="check-circle" style="width:18px;height:18px"></i> Confirmar venta
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Modal ticket -->
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

// Buscar productos
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

  fetch('pos.php', {method:'POST', body:payload})
    .then(r=>r.json()).then(data=>{
      if(data.success){
        document.getElementById('ticket-codigo').textContent=data.codigo;
        document.getElementById('ticket-total').textContent='Total: S/ '+parseFloat(data.total).toFixed(2);
        document.getElementById('btn-imprimir-ticket').href='ticket.php?id='+data.venta_id+'&print=1';
        // Mostrar info SUNAT
        const sunatDiv = document.getElementById('sunat-info-ticket');
        if (data.sunat_xml) {
          const serieNum = data.serie ? data.serie+'-'+data.numero : '';
          sunatDiv.innerHTML = '<div class="mt-2 p-2 rounded" style="background:rgba(0,200,100,.1);font-size:11px"><i class="bi bi-check-circle" style="color:#00c864"></i> XML generado ' + serieNum + '<br><small class="text-muted">'+data.sunat_msg+'</small></div>';
        } else {
          sunatDiv.innerHTML = '<div class="mt-2 small text-muted">Sin comprobante SUNAT</div>';
        }
        new bootstrap.Modal(document.getElementById('modal-ticket')).show();
        limpiarCarrito();
      } else {
        alert(data.error || 'Error al procesar la venta.');
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
