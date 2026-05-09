<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';
requireLogin();
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $codigo = generarCodigoCliente($db);
    $db->prepare("INSERT INTO clientes (codigo,tipo,nombre,ruc_dni,email,telefono,whatsapp,direccion,distrito,segmento,notas) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
       ->execute([
           $codigo,
           $_POST['tipo'] ?? 'persona',
           trim($_POST['nombre']),
           trim($_POST['ruc_dni']    ?? ''),
           trim($_POST['email']      ?? ''),
           trim($_POST['telefono']   ?? ''),
           trim($_POST['whatsapp']   ?? ''),
           trim($_POST['direccion']  ?? ''),
           trim($_POST['distrito']   ?? ''),
           $_POST['segmento']        ?? 'nuevo',
           trim($_POST['notas']      ?? ''),
       ]);
    setFlash('success', 'Cliente registrado con código '.$codigo);
    redirect(BASE_URL . 'modules/clientes/index.php');
}

$pageTitle  = 'Nuevo cliente — '.APP_NAME;
$breadcrumb = [['label'=>'Clientes','url'=>BASE_URL.'modules/clientes/index.php'],['label'=>'Nuevo','url'=>null]];
require_once __DIR__ . '/../../includes/header.php';
?>
<h5 class="fw-bold mb-4">Nuevo cliente</h5>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="tr-card">
      <div class="tr-card-body">
        <form method="POST">
          <div class="row g-3">

            <!-- Tipo -->
            <div class="col-md-3">
              <label class="tr-form-label">Tipo *</label>
              <select name="tipo" id="campo-tipo" class="form-select">
                <option value="persona">Persona</option>
                <option value="empresa">Empresa</option>
              </select>
            </div>

            <!-- DNI / RUC (ahora antes que nombre) -->
            <div class="col-md-4">
              <label class="tr-form-label">DNI / RUC</label>
              <div class="input-group">
                <input type="text" name="ruc_dni" id="campo-doc" class="form-control" maxlength="11" inputmode="numeric" autocomplete="off"/>
                <span class="input-group-text" id="doc-spinner" style="display:none;">
                  <span class="spinner-border spinner-border-sm" role="status"></span>
                </span>
              </div>
              <div id="doc-msg" class="form-text" style="min-height:1.2em;"></div>
            </div>

            <!-- Nombre / Razón social -->
            <div class="col-md-5">
              <label class="tr-form-label">Nombre / Razón social *</label>
              <input type="text" name="nombre" id="campo-nombre" class="form-control" required/>
            </div>

            <div class="col-md-4">
              <label class="tr-form-label">Teléfono</label>
              <input type="text" name="telefono" class="form-control"/>
            </div>
            <div class="col-md-4">
              <label class="tr-form-label">WhatsApp</label>
              <input type="text" name="whatsapp" class="form-control" placeholder="51999..."/>
            </div>
            <div class="col-md-6">
              <label class="tr-form-label">Correo electrónico</label>
              <input type="email" name="email" class="form-control"/>
            </div>
            <div class="col-md-6">
              <label class="tr-form-label">Segmento</label>
              <select name="segmento" class="form-select">
                <option value="nuevo">Nuevo</option>
                <option value="frecuente">Frecuente</option>
                <option value="empresa">Empresa</option>
                <option value="vip">VIP</option>
              </select>
            </div>
            <div class="col-md-8">
              <label class="tr-form-label">Dirección</label>
              <input type="text" name="direccion" class="form-control"/>
            </div>
            <div class="col-md-4">
              <label class="tr-form-label">Distrito</label>
              <input type="text" name="distrito" class="form-control"/>
            </div>
            <div class="col-12">
              <label class="tr-form-label">Notas internas</label>
              <textarea name="notas" class="form-control" rows="2"></textarea>
            </div>
            <div class="col-12 d-flex gap-2">
              <button type="submit" class="btn btn-primary">Guardar cliente</button>
              <a href="<?= BASE_URL ?>modules/clientes/index.php" class="btn btn-outline-secondary">Cancelar</a>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  const campoDoc    = document.getElementById('campo-doc');
  const campoNombre = document.getElementById('campo-nombre');
  const campoTipo   = document.getElementById('campo-tipo');
  const spinner     = document.getElementById('doc-spinner');
  const msg         = document.getElementById('doc-msg');
  let debounceTimer = null;

  // Solo dígitos
  campoDoc.addEventListener('keydown', function (e) {
    const allowed = ['Backspace','Delete','ArrowLeft','ArrowRight','Tab','Enter'];
    if (!allowed.includes(e.key) && !/^\d$/.test(e.key)) {
      e.preventDefault();
    }
  });

  campoDoc.addEventListener('input', function () {
    // Limpiar no-dígitos por si acaso (paste)
    this.value = this.value.replace(/\D/g, '');

    clearTimeout(debounceTimer);
    msg.textContent = '';
    spinner.style.display = 'none';

    const len = this.value.length;
    if (len !== 8 && len !== 11) return;

    debounceTimer = setTimeout(() => consultarDoc(this.value), 400);
  });

  function consultarDoc(doc) {
    spinner.style.display = '';
    msg.textContent = '';

    fetch('api_documento.php?doc=' + encodeURIComponent(doc))
      .then(r => r.json())
      .then(data => {
        spinner.style.display = 'none';
        if (data.ok) {
          campoNombre.value = data.nombre;
          campoTipo.value   = data.tipo;
          msg.textContent   = 'Encontrado';
          msg.style.color   = 'green';
        } else {
          msg.textContent = 'No encontrado';
          msg.style.color = 'red';
        }
      })
      .catch(() => {
        spinner.style.display = 'none';
        msg.textContent = 'No encontrado';
        msg.style.color = 'red';
      });
  }
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
