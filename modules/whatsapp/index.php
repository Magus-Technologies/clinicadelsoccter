<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';
requireLogin();

$db = getDB();

// Limpiar texto para uso seguro en atributos HTML y JS
function waLimpiar(string $s): string {
    // Eliminar caracteres de control excepto saltos de línea
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s);
    return $s;
}

$plantillas = $db->query("SELECT id, nombre, categoria, texto FROM wa_plantillas WHERE activo=1 ORDER BY categoria, nombre")->fetchAll();

$clientes = $db->query("
    SELECT c.id, c.nombre,
           COALESCE(c.whatsapp, c.telefono, '') AS wa,
           COALESCE(c.segmento,'nuevo') AS segmento,
           (SELECT ot.codigo_ot      FROM ordenes_trabajo ot WHERE ot.cliente_id=c.id ORDER BY ot.id DESC LIMIT 1) AS ultima_ot,
           (SELECT ot.estado         FROM ordenes_trabajo ot WHERE ot.cliente_id=c.id ORDER BY ot.id DESC LIMIT 1) AS ultimo_estado,
           (SELECT ot.codigo_publico FROM ordenes_trabajo ot WHERE ot.cliente_id=c.id ORDER BY ot.id DESC LIMIT 1) AS codigo_publico
    FROM clientes c
    WHERE c.activo = 1
      AND (COALESCE(c.whatsapp,'') != '' OR COALESCE(c.telefono,'') != '')
    ORDER BY c.nombre
")->fetchAll();

$empresa = getConfig('empresa_nombre', $db) ?: APP_NAME;

$pageTitle  = 'WhatsApp — ' . APP_NAME;
$breadcrumb = [['label'=>'WhatsApp','url'=>null]];
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="fw-bold mb-0"><span style="color:#25D366">●</span> Comunicaciones WhatsApp</h5>
  <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#modal-nueva-plantilla">
    <i data-feather="plus" style="width:14px;height:14px"></i> Nueva plantilla
  </button>
</div>

<div class="row g-3">

  <!-- ══ PLANTILLAS ══ -->
  <div class="col-lg-4">
    <div class="tr-card" style="position:sticky;top:70px">
      <div class="tr-card-header">
        <h6 class="mb-0 small fw-semibold">📋 PLANTILLAS</h6>
      </div>
      <div class="tr-card-body p-2">
        <div class="d-flex gap-1 flex-wrap mb-2">
          <button class="btn btn-sm btn-primary" onclick="filtrarCat('')">Todas</button>
          <button class="btn btn-sm btn-outline-secondary" onclick="filtrarCat('reparacion')">Reparación</button>
          <button class="btn btn-sm btn-outline-secondary" onclick="filtrarCat('venta')">Venta</button>
          <button class="btn btn-sm btn-outline-secondary" onclick="filtrarCat('general')">General</button>
        </div>

        <!-- Plantillas renderizadas directamente en HTML — sin JSON -->
        <div id="lista-plantillas" style="max-height:440px;overflow-y:auto">
          <?php foreach($plantillas as $p):
            $texto  = waLimpiar($p['texto']);
            $preview= mb_substr($texto, 0, 65);
            if (mb_strlen($texto) > 65) $preview .= '…';
            $color  = $p['categoria']==='reparacion' ? 'primary'
                    : ($p['categoria']==='venta'     ? 'success' : 'secondary');
          ?>
          <div class="plantilla-item p-2 mb-1 rounded border"
               style="cursor:pointer"
               data-cat="<?= htmlspecialchars($p['categoria']) ?>"
               data-id="<?= (int)$p['id'] ?>">
            <div class="d-flex justify-content-between align-items-start gap-2">
              <div style="flex:1;min-width:0">
                <div class="fw-semibold small text-truncate">
                  <?= htmlspecialchars($p['nombre']) ?>
                </div>
                <div class="text-muted" style="font-size:11px">
                  <?= htmlspecialchars(str_replace("\n",' ',$preview)) ?>
                </div>
              </div>
              <span class="badge bg-<?= $color ?> flex-shrink-0" style="font-size:10px">
                <?= htmlspecialchars($p['categoria']) ?>
              </span>
            </div>
          </div>
          <?php endforeach; ?>
          <?php if(empty($plantillas)): ?>
          <p class="text-muted text-center small py-3">
            Sin plantillas. Ejecuta <code>sql/fix_plantillas.sql</code> en phpMyAdmin.
          </p>
          <?php endif; ?>
        </div>

        <!-- Textos de plantillas en elementos ocultos — evita JSON completamente -->
        <?php foreach($plantillas as $p): ?>
        <textarea id="ptexto_<?= (int)$p['id'] ?>" style="display:none"><?= htmlspecialchars(waLimpiar($p['texto'])) ?></textarea>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- ══ COMPOSITOR ══ -->
  <div class="col-lg-8">
    <div class="tr-card mb-3">
      <div class="tr-card-header">
        <h6 class="mb-0 small fw-semibold">✉️ REDACTAR Y ENVIAR</h6>
        <span class="badge bg-success">Vía WhatsApp Web</span>
      </div>
      <div class="tr-card-body">

        <div class="row g-2 mb-3">
          <div class="col-md-8">
            <label class="tr-form-label">Buscar cliente</label>
            <div style="position:relative">
              <input type="text" id="buscar-cliente" class="form-control form-control-sm"
                     placeholder="Escribe nombre o número..." autocomplete="off"/>
              <div id="sugerencias"
                   style="position:absolute;top:100%;left:0;right:0;z-index:9999;
                          background:#fff;border:1px solid #e5e7eb;
                          border-radius:0 0 8px 8px;max-height:200px;overflow-y:auto;
                          box-shadow:0 4px 12px rgba(0,0,0,.1);display:none"></div>
            </div>
          </div>
          <div class="col-md-4">
            <label class="tr-form-label">Número WhatsApp</label>
            <input type="text" id="numero-wa" class="form-control form-control-sm"
                   placeholder="51999888777"/>
          </div>
        </div>

        <div id="badge-cliente" class="mb-3" style="display:none">
          <div class="d-flex align-items-center gap-2 p-2 rounded"
               style="background:#f0fdf4;border:1px solid #86efac">
            <span>👤</span>
            <div style="flex:1">
              <div class="fw-semibold small" id="badge-nombre"></div>
              <div class="text-muted" style="font-size:11px" id="badge-info"></div>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary py-0"
                    onclick="limpiarCliente()">✕</button>
          </div>
        </div>

        <div class="mb-3">
          <label class="tr-form-label">Insertar variable</label>
          <div class="d-flex flex-wrap gap-1">
            <?php foreach([
              '{nombre}'=>'👤','{codigo_ot}'=>'📋','{estado}'=>'🔄',
              '{total}'=>'💰','{codigo_consulta}'=>'🔑','{empresa}'=>'🏪',
              '{fecha_estimada}'=>'📅'
            ] as $var=>$ico): ?>
            <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2"
                    style="font-size:12px"
                    onclick="insertarVar('<?= $var ?>')"><?= $ico ?> <?= $var ?></button>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="mb-3">
          <label class="tr-form-label">Mensaje</label>
          <textarea id="mensaje" class="form-control" rows="6"
                    placeholder="Escribe aquí o selecciona una plantilla de la izquierda..."
                    oninput="actualizarContador();actualizarPreview()"></textarea>
          <div class="d-flex justify-content-between mt-1">
            <span class="text-muted" style="font-size:11px">*negrita* _cursiva_ ~tachado~</span>
            <span class="text-muted" style="font-size:11px" id="char-count">0 caracteres</span>
          </div>
        </div>

        <div id="preview-wrap" class="mb-3" style="display:none">
          <label class="tr-form-label">Vista previa</label>
          <div id="preview-msg"
               style="background:#DCF8C6;border-radius:12px 12px 0 12px;
                      padding:14px 16px;font-size:14px;line-height:1.6;
                      max-width:420px;border:1px solid #c3e8ad"></div>
        </div>

        <div class="d-flex gap-2 flex-wrap">
          <button type="button" class="btn btn-success fw-semibold"
                  onclick="abrirWhatsApp()">
            <svg width="15" height="15" fill="currentColor" viewBox="0 0 16 16" class="me-1">
              <path d="M13.601 2.326A7.854 7.854 0 0 0 7.994 0C3.627 0 .068 3.558.064 7.926c0 1.399.366 2.76 1.057 3.965L0 16l4.204-1.102a7.933 7.933 0 0 0 3.79.965h.004c4.368 0 7.926-3.558 7.93-7.93A7.898 7.898 0 0 0 13.6 2.326z"/>
            </svg>
            Abrir WhatsApp Web
          </button>
          <button type="button" class="btn btn-outline-primary"
                  onclick="actualizarPreview()">
            <i data-feather="eye" style="width:14px;height:14px"></i> Previsualizar
          </button>
          <button type="button" class="btn btn-outline-secondary"
                  onclick="limpiarTodo()">
            <i data-feather="refresh-cw" style="width:14px;height:14px"></i> Limpiar
          </button>
        </div>

      </div>
    </div>

    <!-- MASIVO -->
    <div class="tr-card">
      <div class="tr-card-header">
        <h6 class="mb-0 small fw-semibold">📢 COMUNICADO A MÚLTIPLES CLIENTES</h6>
        <span class="badge bg-warning text-dark">Uno a uno vía WhatsApp Web</span>
      </div>
      <div class="tr-card-body">
        <div class="row g-2 mb-3">
          <div class="col-md-6">
            <label class="tr-form-label">Mensaje del comunicado</label>
            <textarea id="msg-masivo" class="form-control form-control-sm" rows="4"
                      placeholder="Hola {nombre}, te escribimos de {empresa}..."></textarea>
          </div>
          <div class="col-md-6">
            <label class="tr-form-label">Filtrar</label>
            <select id="filtro-seg" class="form-select form-select-sm mb-2"
                    onchange="filtrarSegmento()">
              <option value="">Todos los clientes</option>
              <option value="frecuente">Frecuentes</option>
              <option value="empresa">Empresas</option>
              <option value="vip">VIP</option>
            </select>
            <div class="d-flex gap-2">
              <button type="button" class="btn btn-sm btn-outline-secondary"
                      onclick="selTodos()">Selec. todos</button>
              <button type="button" class="btn btn-sm btn-outline-secondary"
                      onclick="deselTodos()">Limpiar</button>
            </div>
          </div>
        </div>

        <div id="lista-masivo"
             style="max-height:220px;overflow-y:auto;border:1px solid #e5e7eb;
                    border-radius:8px;padding:8px">
          <?php foreach($clientes as $cl):
            $estadoColor = ESTADOS_OT[$cl['ultimo_estado']??'']['color']??'secondary';
          ?>
          <div class="form-check cliente-row py-1"
               data-seg="<?= htmlspecialchars($cl['segmento']) ?>"
               data-ot="<?= htmlspecialchars($cl['ultima_ot']??'') ?>">
            <input class="form-check-input chk-cli" type="checkbox"
                   id="chk_<?= (int)$cl['id'] ?>"
                   data-wa="<?= htmlspecialchars($cl['wa']) ?>"
                   data-nombre="<?= htmlspecialchars($cl['nombre']) ?>"
                   onchange="actualizarConteo()"/>
            <label class="form-check-label small" for="chk_<?= (int)$cl['id'] ?>">
              <span class="fw-semibold"><?= sanitize($cl['nombre']) ?></span>
              <span class="text-muted ms-1" style="font-size:11px"><?= sanitize($cl['wa']) ?></span>
              <?php if($cl['ultima_ot']): ?>
              <span class="badge bg-<?= $estadoColor ?> ms-1" style="font-size:10px">
                <?= sanitize($cl['ultima_ot']) ?>
              </span>
              <?php endif; ?>
            </label>
          </div>
          <?php endforeach; ?>
          <?php if(empty($clientes)): ?>
          <p class="text-muted text-center small py-2 mb-0">Sin clientes con número registrado.</p>
          <?php endif; ?>
        </div>

        <div class="d-flex align-items-center gap-2 mt-2">
          <span class="text-muted small" id="conteo">0 seleccionados</span>
          <button type="button" class="btn btn-success btn-sm ms-auto"
                  onclick="enviarMasivo()">
            <svg width="13" height="13" fill="currentColor" viewBox="0 0 16 16" class="me-1">
              <path d="M13.601 2.326A7.854 7.854 0 0 0 7.994 0C3.627 0 .068 3.558.064 7.926c0 1.399.366 2.76 1.057 3.965L0 16l4.204-1.102a7.933 7.933 0 0 0 3.79.965h.004c4.368 0 7.926-3.558 7.93-7.93A7.898 7.898 0 0 0 13.6 2.326z"/>
            </svg>
            Enviar a seleccionados
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal nueva plantilla -->
<div class="modal fade" id="modal-nueva-plantilla" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" action="guardar_plantilla.php">
        <div class="modal-header">
          <h6 class="modal-title fw-bold">Nueva plantilla</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2">
            <label class="tr-form-label">Nombre *</label>
            <input type="text" name="nombre" class="form-control" required/>
          </div>
          <div class="mb-2">
            <label class="tr-form-label">Categoría</label>
            <select name="categoria" class="form-select">
              <option value="reparacion">Reparación</option>
              <option value="venta">Venta</option>
              <option value="general">General</option>
            </select>
          </div>
          <div class="mb-2">
            <label class="tr-form-label">Mensaje *</label>
            <textarea name="texto" class="form-control" rows="5" required></textarea>
            <div class="text-muted small mt-1">Variables: {nombre} {codigo_ot} {estado} {total} {codigo_consulta} {empresa} {fecha_estimada}</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary btn-sm">Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Empresa en elemento oculto — sin JSON -->
<span id="wa-empresa" style="display:none"><?= htmlspecialchars($empresa) ?></span>

<!-- Datos de clientes en tabla oculta — sin JSON -->
<table id="wa-clientes-data" style="display:none">
  <?php foreach($clientes as $cl): ?>
  <tr data-id="<?= (int)$cl['id'] ?>"
      data-nombre="<?= htmlspecialchars($cl['nombre']) ?>"
      data-wa="<?= htmlspecialchars($cl['wa']) ?>"
      data-ot="<?= htmlspecialchars($cl['ultima_ot']??'') ?>"
      data-estado="<?= htmlspecialchars($cl['ultimo_estado']??'') ?>"
      data-codigo="<?= htmlspecialchars($cl['codigo_publico']??'') ?>"></tr>
  <?php endforeach; ?>
</table>

<script>
// ════════════════════════════════════════════════════════
// Sin JSON — todos los datos vienen del DOM directamente
// ════════════════════════════════════════════════════════

var EMPRESA = document.getElementById('wa-empresa').textContent;

// Construir array de clientes desde tabla DOM oculta
var CLIENTES = [];
document.querySelectorAll('#wa-clientes-data tr').forEach(function(tr) {
  CLIENTES.push({
    id:     parseInt(tr.dataset.id),
    nombre: tr.dataset.nombre,
    wa:     tr.dataset.wa,
    ot:     tr.dataset.ot,
    estado: tr.dataset.estado,
    codigo: tr.dataset.codigo
  });
});

var clienteActual = null;

// ── FILTRAR CATEGORÍA ────────────────────────────────────
function filtrarCat(cat) {
  // Actualizar botones
  document.querySelectorAll('#lista-plantillas').forEach(function(container) {
    container.querySelectorAll('.plantilla-item').forEach(function(item) {
      if (!cat || item.dataset.cat === cat) {
        item.style.display = '';
      } else {
        item.style.display = 'none';
      }
    });
  });
  // Estilos de botones
  document.querySelectorAll('[onclick^="filtrarCat"]').forEach(function(b) {
    b.className = 'btn btn-sm btn-outline-secondary';
  });
  event.target.className = 'btn btn-sm btn-primary';
}

// ── CLICK EN PLANTILLA ───────────────────────────────────
document.querySelectorAll('.plantilla-item').forEach(function(item) {
  item.addEventListener('click', function() {
    var pid     = this.dataset.id;
    var txArea  = document.getElementById('ptexto_' + pid);
    if (!txArea) { alert('No se encontró el texto de esta plantilla.'); return; }

    // Obtener texto del textarea oculto
    var texto = txArea.value;

    // Resaltar seleccionada
    document.querySelectorAll('.plantilla-item').forEach(function(x) {
      x.style.background  = '';
      x.style.borderColor = '';
    });
    this.style.background  = '#f0fdf4';
    this.style.borderColor = '#86efac';

    // Poner en el textarea de mensaje
    document.getElementById('mensaje').value = texto;
    actualizarContador();
    actualizarPreview();

    // Scroll al mensaje
    document.getElementById('mensaje').scrollIntoView({behavior:'smooth', block:'nearest'});
  });
});

// ── CONTADOR ─────────────────────────────────────────────
function actualizarContador() {
  var n = document.getElementById('mensaje').value.length;
  document.getElementById('char-count').textContent = n + ' caracteres';
}

// ── RESOLVER VARIABLES ───────────────────────────────────
function resolverVars(txt) {
  var c = clienteActual || {};
  return txt
    .replace(/\{nombre\}/g,         c.nombre  || '(nombre)')
    .replace(/\{codigo_ot\}/g,       c.ot      || '(OT)')
    .replace(/\{estado\}/g,          c.estado  || '(estado)')
    .replace(/\{codigo_consulta\}/g, c.codigo  || '(código)')
    .replace(/\{empresa\}/g,         EMPRESA)
    .replace(/\{total\}/g,           '(total)')
    .replace(/\{equipo\}/g,          '(equipo)')
    .replace(/\{fecha_estimada\}/g,  '(fecha estimada)');
}

// ── PREVISUALIZAR ─────────────────────────────────────────
function actualizarPreview() {
  var raw = document.getElementById('mensaje').value.trim();
  var wrap = document.getElementById('preview-wrap');
  if (!raw) { wrap.style.display = 'none'; return; }

  var txt = resolverVars(raw);
  // Escapar HTML
  var esc = txt.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  // Formato WhatsApp
  esc = esc
    .replace(/\*(.*?)\*/g,  '<strong>$1</strong>')
    .replace(/_(.*?)_/g,    '<em>$1</em>')
    .replace(/~(.*?)~/g,    '<s>$1</s>')
    .replace(/\n/g,         '<br>');

  document.getElementById('preview-msg').innerHTML = esc;
  wrap.style.display = '';
}

// ── LIMPIAR ───────────────────────────────────────────────
function limpiarTodo() {
  document.getElementById('mensaje').value  = '';
  document.getElementById('numero-wa').value = '';
  document.getElementById('buscar-cliente').value = '';
  document.getElementById('char-count').textContent = '0 caracteres';
  document.getElementById('preview-wrap').style.display = 'none';
  document.getElementById('badge-cliente').style.display = 'none';
  clienteActual = null;
  document.querySelectorAll('.plantilla-item').forEach(function(x) {
    x.style.background = ''; x.style.borderColor = '';
  });
}

// ── INSERTAR VARIABLE ─────────────────────────────────────
function insertarVar(v) {
  var ta  = document.getElementById('mensaje');
  var s   = ta.selectionStart;
  var e   = ta.selectionEnd;
  ta.value = ta.value.substring(0, s) + v + ta.value.substring(e);
  ta.selectionStart = ta.selectionEnd = s + v.length;
  ta.focus();
  actualizarContador();
  actualizarPreview();
}

// ── ABRIR WHATSAPP WEB ────────────────────────────────────
function abrirWhatsApp() {
  var num = limpiarNum(document.getElementById('numero-wa').value);
  var msg = document.getElementById('mensaje').value.trim();
  if (!num) { alert('Ingresa un número de WhatsApp.'); return; }
  if (!msg)  { alert('Escribe un mensaje primero.'); return; }
  window.open('https://wa.me/' + num + '?text=' + encodeURIComponent(resolverVars(msg)), '_blank');
}

// ── BUSCAR CLIENTE ────────────────────────────────────────
document.getElementById('buscar-cliente').addEventListener('input', function() {
  var q      = this.value.trim().toLowerCase();
  var divSug = document.getElementById('sugerencias');
  divSug.innerHTML = '';
  divSug.style.display = 'none';
  if (!q) return;

  var matches = CLIENTES.filter(function(c) {
    return c.nombre.toLowerCase().indexOf(q) >= 0 || c.wa.indexOf(q) >= 0;
  }).slice(0, 10);

  if (!matches.length) {
    divSug.innerHTML = '<div style="padding:8px 14px;font-size:13px;color:#9ca3af">Sin resultados</div>';
    divSug.style.display = 'block';
    return;
  }

  divSug.innerHTML = matches.map(function(c) {
    return '<div class="sug-item" data-id="' + c.id
         + '" style="padding:8px 14px;cursor:pointer;border-bottom:1px solid #f3f4f6;font-size:13px">'
         + '<strong>' + esc(c.nombre) + '</strong>'
         + ' <span style="color:#9ca3af;font-size:11px">' + esc(c.wa) + '</span>'
         + (c.ot ? ' <span class="badge bg-secondary" style="font-size:10px">' + esc(c.ot) + '</span>' : '')
         + '</div>';
  }).join('');

  divSug.querySelectorAll('.sug-item').forEach(function(item) {
    item.addEventListener('mouseenter', function(){ this.style.background='#f9fafb'; });
    item.addEventListener('mouseleave', function(){ this.style.background=''; });
    item.addEventListener('click', function() {
      var cid = parseInt(this.dataset.id);
      var c   = CLIENTES.find(function(x){ return x.id === cid; });
      if (c) seleccionarCliente(c);
    });
  });

  divSug.style.display = 'block';
});

document.addEventListener('click', function(e) {
  var inp = document.getElementById('buscar-cliente');
  var sug = document.getElementById('sugerencias');
  if (inp && sug && !inp.contains(e.target) && !sug.contains(e.target)) {
    sug.style.display = 'none';
  }
});

function seleccionarCliente(c) {
  clienteActual = c;
  document.getElementById('buscar-cliente').value = c.nombre;
  document.getElementById('sugerencias').style.display = 'none';
  document.getElementById('numero-wa').value = limpiarNum(c.wa);
  document.getElementById('badge-nombre').textContent = c.nombre;
  var info = [];
  if (c.wa)     info.push('📞 ' + c.wa);
  if (c.ot)     info.push('📋 ' + c.ot);
  if (c.estado) info.push(c.estado);
  document.getElementById('badge-info').textContent = info.join(' · ') || 'Sin OTs';
  document.getElementById('badge-cliente').style.display = '';
  actualizarPreview();
}

function limpiarCliente() {
  clienteActual = null;
  document.getElementById('buscar-cliente').value = '';
  document.getElementById('numero-wa').value = '';
  document.getElementById('badge-cliente').style.display = 'none';
}

// ── MASIVO ────────────────────────────────────────────────
function actualizarConteo() {
  var n = document.querySelectorAll('.chk-cli:checked').length;
  document.getElementById('conteo').textContent = n + ' seleccionado' + (n !== 1 ? 's' : '');
}
function selTodos() {
  document.querySelectorAll('.cliente-row:not([style*="display:none"]) .chk-cli')
    .forEach(function(c){ c.checked = true; });
  actualizarConteo();
}
function deselTodos() {
  document.querySelectorAll('.chk-cli').forEach(function(c){ c.checked = false; });
  actualizarConteo();
}
function filtrarSegmento() {
  var seg = document.getElementById('filtro-seg').value;
  document.querySelectorAll('.cliente-row').forEach(function(row) {
    var ok = !seg || row.dataset.seg === seg;
    row.style.display = ok ? '' : 'none';
  });
  deselTodos();
}

var _idx = 0, _lista = [];
function enviarMasivo() {
  var msg = document.getElementById('msg-masivo').value.trim();
  if (!msg) { alert('Escribe el mensaje del comunicado.'); return; }
  var checks = Array.from(document.querySelectorAll('.chk-cli:checked'));
  if (!checks.length) { alert('Selecciona al menos un cliente.'); return; }
  _lista = checks.map(function(c) { return { nombre: c.dataset.nombre, wa: limpiarNum(c.dataset.wa) }; });
  _idx   = 0;
  if (!confirm('Se abrirá WhatsApp para ' + _lista.length + ' clientes uno a uno.\n¿Continuar?')) return;
  _enviarSig(msg);
}
function _enviarSig(msg) {
  if (_idx >= _lista.length) { alert('✅ Comunicado completado.'); return; }
  var c   = _lista[_idx];
  var txt = msg.replace(/\{nombre\}/g, c.nombre).replace(/\{empresa\}/g, EMPRESA);
  window.open('https://wa.me/' + c.wa + '?text=' + encodeURIComponent(txt), '_blank');
  _idx++;
  if (_idx < _lista.length) {
    setTimeout(function() {
      if (confirm(_idx + '/' + _lista.length + ' enviado. ¿Continuar con ' + _lista[_idx].nombre + '?'))
        _enviarSig(msg);
    }, 1000);
  } else {
    setTimeout(function(){ alert('✅ Listo. Todos enviados.'); }, 600);
  }
}

// ── HELPERS ───────────────────────────────────────────────
function limpiarNum(n) {
  n = (n || '').replace(/\D/g, '');
  if (n && !n.startsWith('51') && n.length <= 9) n = '51' + n;
  return n;
}
function esc(s) {
  return (s || '').toString()
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
