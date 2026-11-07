<?php
/**
 * informe.php — Editor del Informe Técnico de Mantenimiento de una OT
 * Uso: modules/ot/informe.php?ot_id=123
 * Datos del sistema (receptor, scooter, proforma) se precargan; el resto es manual.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';
requireLogin();

$db  = getDB();
$id  = (int)($_GET['ot_id'] ?? 0);

// OT + cliente + equipo
$ot = $db->prepare("
    SELECT ot.*, c.nombre AS cli_nombre, c.razon_social AS cli_razon, c.ruc_dni AS cli_ruc, c.num_doc AS cli_num,
           c.email AS cli_email, c.telefono AS cli_tel,
           e.marca, e.modelo, te.nombre AS tipo_equipo
    FROM ordenes_trabajo ot
    JOIN clientes c ON c.id = ot.cliente_id
    JOIN equipos e ON e.id = ot.equipo_id
    JOIN tipos_equipo te ON te.id = e.tipo_equipo_id
    WHERE ot.id = ?");
$ot->execute([$id]);
$ot = $ot->fetch();
if (!$ot) { setFlash('danger','OT no encontrada'); redirect(BASE_URL.'modules/ot/index.php'); }

// Upsert del informe (crear si no existe) para tener $informeId disponible
$st = $db->prepare("SELECT * FROM informes_tecnicos WHERE ot_id=?");
$st->execute([$id]); $inf = $st->fetch();
if (!$inf) {
    $db->prepare("INSERT INTO informes_tecnicos (ot_id) VALUES (?)")->execute([$id]);
    $st->execute([$id]); $inf = $st->fetch();
}
$informeId = (int)$inf['id'];
$back = BASE_URL.'modules/ot/informe.php?ot_id='.$id;

// Carpeta de subida de extras
$dirRel = 'informes/'.$id.'/';
$dirAbs = UPLOAD_PATH.$dirRel;

// ─────────── ACCIONES (POST/GET independientes) ───────────
$accion = $_POST['accion'] ?? $_GET['accion'] ?? '';

// 1) Guardar datos manuales + proforma
if ($accion === 'guardar_datos' && $_SERVER['REQUEST_METHOD']==='POST') {
    $prof = [];
    $descs = $_POST['pf_desc'] ?? []; $imps = $_POST['pf_importe'] ?? [];
    foreach ($descs as $i => $d) {
        $d = trim($d); if ($d==='') continue;
        $prof[] = ['desc'=>$d, 'importe'=>round((float)($imps[$i] ?? 0),2)];
    }
    $db->prepare("UPDATE informes_tecnicos SET custodia_nombre=?, custodia_email=?, custodia_telefono=?,
                  alcance=?, hallazgos=?, condicion_final=?, observaciones_pendientes=?, proforma_json=? WHERE id=?")
       ->execute([
         trim($_POST['custodia_nombre'] ?? ''), trim($_POST['custodia_email'] ?? ''), trim($_POST['custodia_telefono'] ?? ''),
         trim($_POST['alcance'] ?? ''), trim($_POST['hallazgos'] ?? ''), trim($_POST['condicion_final'] ?? ''),
         trim($_POST['observaciones_pendientes'] ?? ''), json_encode($prof, JSON_UNESCAPED_UNICODE), $informeId
       ]);
    setFlash('success','Informe guardado.');
    redirect($back);
}

// 2) Agregar fotos elegidas de la OT
if ($accion === 'add_ot_fotos' && $_SERVER['REQUEST_METHOD']==='POST') {
    $sel = $_POST['foto_ot'] ?? []; // rutas elegidas
    $ord = (int)$db->query("SELECT COALESCE(MAX(orden),0) FROM informe_fotos WHERE informe_id=$informeId")->fetchColumn();
    $ins = $db->prepare("INSERT INTO informe_fotos (informe_id,ruta,etiqueta,descripcion,origen,orden) VALUES (?,?,?,?, 'ot', ?)");
    foreach ($sel as $ruta) {
        $ruta = trim($ruta); if ($ruta==='') continue;
        $ord++;
        $ins->execute([$informeId, $ruta, trim($_POST['et'][$ruta] ?? ''), trim($_POST['de'][$ruta] ?? ''), $ord]);
    }
    setFlash('success','Fotos agregadas al informe.');
    redirect($back);
}

// 3) Subir extras
if ($accion === 'subir_extra' && $_SERVER['REQUEST_METHOD']==='POST') {
    if (!is_dir($dirAbs)) @mkdir($dirAbs, 0775, true);
    $ord = (int)$db->query("SELECT COALESCE(MAX(orden),0) FROM informe_fotos WHERE informe_id=$informeId")->fetchColumn();
    $ins = $db->prepare("INSERT INTO informe_fotos (informe_id,ruta,etiqueta,descripcion,origen,orden) VALUES (?,?,?,?, 'extra', ?)");
    $files = $_FILES['extras'] ?? null; $n = 0;
    if ($files && is_array($files['name'])) {
        foreach ($files['name'] as $i => $name) {
            if (($files['error'][$i] ?? 1) !== UPLOAD_ERR_OK) continue;
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','webp','gif'])) continue;
            $fn  = 'img_'.uniqid().'.'.$ext;
            if (move_uploaded_file($files['tmp_name'][$i], $dirAbs.$fn)) {
                $ord++;
                $ins->execute([$informeId, $dirRel.$fn, trim($_POST['et_extra'] ?? ''), trim($_POST['de_extra'] ?? ''), $ord]);
                $n++;
            }
        }
    }
    setFlash($n?'success':'warning', $n? "$n foto(s) subida(s)." : 'No se subió ninguna foto.');
    redirect($back);
}

// 4) Editar caption/etiqueta de una foto
if ($accion === 'edit_foto' && $_SERVER['REQUEST_METHOD']==='POST') {
    $db->prepare("UPDATE informe_fotos SET etiqueta=?, descripcion=? WHERE id=? AND informe_id=?")
       ->execute([trim($_POST['etiqueta']??''), trim($_POST['descripcion']??''), (int)$_POST['foto_id'], $informeId]);
    setFlash('success','Foto actualizada.');
    redirect($back);
}

// 5) Eliminar / mover foto
if ($accion === 'del_foto') {
    $db->prepare("DELETE FROM informe_fotos WHERE id=? AND informe_id=?")->execute([(int)$_GET['foto_id'], $informeId]);
    setFlash('success','Foto quitada del informe.');
    redirect($back);
}
if ($accion === 'mov_foto') {
    $fid=(int)$_GET['foto_id']; $dir=($_GET['dir']??'up')==='up'?-1:1;
    $rows=$db->query("SELECT id,orden FROM informe_fotos WHERE informe_id=$informeId ORDER BY orden,id")->fetchAll();
    for($i=0;$i<count($rows);$i++){ if((int)$rows[$i]['id']===$fid){ $j=$i+$dir;
        if($j>=0 && $j<count($rows)){ $a=$rows[$i]['id']; $b=$rows[$j]['id'];
            $db->prepare("UPDATE informe_fotos SET orden=? WHERE id=?")->execute([$j+1,$a]);
            $db->prepare("UPDATE informe_fotos SET orden=? WHERE id=?")->execute([$i+1,$b]); }
        break; } }
    redirect($back);
}

// ─────────── DATOS PARA LA VISTA ───────────
// Proforma actual (json guardado o precarga desde la OT)
$prof = $inf['proforma_json'] ? (json_decode($inf['proforma_json'], true) ?: []) : [];
if (!$prof) {
    $rp = $db->prepare("SELECT descripcion, subtotal FROM ot_repuestos WHERE ot_id=? ORDER BY id"); $rp->execute([$id]);
    foreach ($rp->fetchAll() as $r) $prof[] = ['desc'=>$r['descripcion'],'importe'=>(float)$r['subtotal']];
    if ((float)$ot['costo_mano_obra']>0) $prof[]=['desc'=>'Mano de obra','importe'=>(float)$ot['costo_mano_obra']];
}
// Fotos de la OT disponibles (para elegir) y fotos ya en el informe
$fotosOT = $db->prepare("SELECT ruta, descripcion FROM fotos_ot WHERE ot_id=? AND (tipo_archivo IS NULL OR tipo_archivo='foto') ORDER BY id");
$fotosOT->execute([$id]); $fotosOT = $fotosOT->fetchAll();
$fotosInf = $db->query("SELECT * FROM informe_fotos WHERE informe_id=$informeId ORDER BY orden,id")->fetchAll();
$yaRutas  = array_column($fotosInf,'ruta');
$etiquetas = ['','Hallazgo','Diagnóstico','Proceso','Repuesto','Entrega'];

$pageTitle = 'Informe Técnico · '.$ot['codigo_ot'];
$breadcrumb = [['label'=>'Órdenes','url'=>BASE_URL.'modules/ot/index.php'],['label'=>'Informe Técnico','url'=>null]];
require_once __DIR__ . '/../../includes/header.php';
?>
<style>
  .grid-ot{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:10px}
  .pick{border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;position:relative;cursor:pointer}
  .pick img{width:100%;height:90px;object-fit:cover;display:block}
  .pick input{position:absolute;top:6px;left:6px;transform:scale(1.2)}
  .pick .cap{padding:5px}
  .pick .cap input,.pick .cap select{width:100%;font-size:11px;margin-top:3px}
  .inf-thumb{width:60px;height:60px;object-fit:cover;border-radius:6px}
</style>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h5 class="mb-0">Informe Técnico — <?= sanitize($ot['codigo_ot']) ?></h5>
  <a href="<?= BASE_URL ?>modules/ot/informe_pdf.php?ot_id=<?= $id ?>" target="_blank" class="btn btn-danger btn-sm">
    <i data-feather="printer" style="width:15px;height:15px"></i> Ver / Generar PDF
  </a>
</div>

<!-- Datos del sistema (solo lectura) -->
<div class="tr-card mb-3">
  <div class="tr-card-header"><h6 class="mb-0 small fw-semibold">DATOS DEL SISTEMA (automáticos)</h6></div>
  <div class="tr-card-body small">
    <div class="row g-2">
      <div class="col-md-6"><b>Receptor:</b> <?= sanitize($ot['cli_nombre']) ?><?= $ot['cli_razon']?' — '.sanitize($ot['cli_razon']):'' ?><?= ($ot['cli_ruc']||$ot['cli_num'])?' (RUC '.sanitize($ot['cli_ruc']?:$ot['cli_num']).')':'' ?></div>
      <div class="col-md-3"><b>Scooter:</b> <?= sanitize(trim(($ot['marca']?:'').' '.($ot['modelo']?:''))) ?></div>
      <div class="col-md-3"><b>O.T.:</b> <?= sanitize($ot['codigo_ot']) ?></div>
    </div>
  </div>
</div>

<form method="POST" action="<?= $back ?>">
<input type="hidden" name="accion" value="guardar_datos"/>

<div class="tr-card mb-3">
  <div class="tr-card-header"><h6 class="mb-0 small fw-semibold">CUSTODIA (contacto que recibe/entrega)</h6></div>
  <div class="tr-card-body">
    <div class="row g-2">
      <div class="col-md-5"><label class="tr-form-label">Nombre</label><input class="form-control form-control-sm" name="custodia_nombre" value="<?= sanitize($inf['custodia_nombre']?:'') ?>" placeholder="<?= sanitize($ot['cli_nombre']) ?>"/></div>
      <div class="col-md-4"><label class="tr-form-label">Correo</label><input class="form-control form-control-sm" name="custodia_email" value="<?= sanitize($inf['custodia_email']?:'') ?>" placeholder="<?= sanitize($ot['cli_email']?:'') ?>"/></div>
      <div class="col-md-3"><label class="tr-form-label">Teléfono</label><input class="form-control form-control-sm" name="custodia_telefono" value="<?= sanitize($inf['custodia_telefono']?:'') ?>" placeholder="<?= sanitize($ot['cli_tel']?:'') ?>"/></div>
    </div>
  </div>
</div>

<div class="tr-card mb-3">
  <div class="tr-card-header"><h6 class="mb-0 small fw-semibold">3. ALCANCE DE LOS TRABAJOS (una actividad por línea; usa "Título: detalle")</h6></div>
  <div class="tr-card-body"><textarea class="form-control form-control-sm" name="alcance" rows="4" placeholder="Mantenimiento de equipo: Servicio integral...&#10;Transporte: Recojo y traslado..."><?= sanitize($inf['alcance']?:'') ?></textarea></div>
</div>

<div class="tr-card mb-3">
  <div class="tr-card-header"><h6 class="mb-0 small fw-semibold">4. HALLAZGOS Y OBSERVACIONES (uno por línea)</h6></div>
  <div class="tr-card-body"><textarea class="form-control form-control-sm" name="hallazgos" rows="4"><?= sanitize($inf['hallazgos']?:'') ?></textarea></div>
</div>

<div class="tr-card mb-3">
  <div class="tr-card-header d-flex justify-content-between align-items-center">
    <h6 class="mb-0 small fw-semibold">5. PROFORMA (precargada de la OT, editable)</h6>
    <button type="button" class="btn btn-outline-secondary btn-sm py-0" onclick="addPf()">+ línea</button>
  </div>
  <div class="tr-card-body">
    <table class="table table-sm mb-0" id="tbl-pf">
      <thead><tr><th>Servicio</th><th style="width:130px">Importe</th><th style="width:40px"></th></tr></thead>
      <tbody>
        <?php foreach ($prof as $p): ?>
        <tr>
          <td><input class="form-control form-control-sm" name="pf_desc[]" value="<?= sanitize($p['desc']) ?>"/></td>
          <td><input class="form-control form-control-sm" name="pf_importe[]" type="number" step="0.01" value="<?= number_format((float)$p['importe'],2,'.','') ?>"/></td>
          <td><button type="button" class="btn btn-outline-danger btn-sm py-0" onclick="this.closest('tr').remove()">&times;</button></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="tr-card mb-3">
  <div class="tr-card-header"><h6 class="mb-0 small fw-semibold">6. CONDICIÓN FINAL DEL EQUIPO</h6></div>
  <div class="tr-card-body">
    <label class="tr-form-label">Condición final</label>
    <textarea class="form-control form-control-sm mb-2" name="condicion_final" rows="3"><?= sanitize($inf['condicion_final']?:'') ?></textarea>
    <label class="tr-form-label">Observaciones pendientes para el cliente</label>
    <textarea class="form-control form-control-sm" name="observaciones_pendientes" rows="2"><?= sanitize($inf['observaciones_pendientes']?:'') ?></textarea>
  </div>
</div>

<button type="submit" class="btn btn-primary w-100 mb-3"><i data-feather="save" style="width:15px;height:15px"></i> Guardar informe</button>
</form>

<!-- ANEXO: fotos ya en el informe -->
<div class="tr-card mb-3" id="anexo">
  <div class="tr-card-header"><h6 class="mb-0 small fw-semibold">ANEXO — Fotos en el informe (<?= count($fotosInf) ?>)</h6></div>
  <div class="tr-card-body">
    <?php if (empty($fotosInf)): ?>
      <p class="text-muted small mb-0">Aún no hay fotos. Elige de la OT o sube extras abajo.</p>
    <?php else: ?>
      <div class="d-flex flex-column gap-2">
        <?php foreach ($fotosInf as $k=>$ft): ?>
        <form method="POST" action="<?= $back ?>" class="d-flex align-items-center gap-2 p-2 rounded" style="background:#f8fafc;border:1px solid #eef0f4">
          <input type="hidden" name="accion" value="edit_foto"/><input type="hidden" name="foto_id" value="<?= $ft['id'] ?>"/>
          <img class="inf-thumb" src="<?= sanitize(UPLOAD_URL.$ft['ruta']) ?>" alt=""/>
          <select name="etiqueta" class="form-select form-select-sm" style="max-width:130px">
            <?php foreach ($etiquetas as $e): ?><option <?= $ft['etiqueta']===$e?'selected':'' ?>><?= $e ?: '—' ?></option><?php endforeach; ?>
          </select>
          <input class="form-control form-control-sm" name="descripcion" value="<?= sanitize($ft['descripcion']) ?>" placeholder="Descripción"/>
          <span class="badge bg-<?= $ft['origen']==='ot'?'info':'secondary' ?>"><?= $ft['origen']==='ot'?'de OT':'extra' ?></span>
          <button class="btn btn-outline-primary btn-sm py-0" title="Guardar"><i data-feather="check" style="width:13px;height:13px"></i></button>
          <a class="btn btn-outline-secondary btn-sm py-0" href="<?= $back ?>&accion=mov_foto&foto_id=<?= $ft['id'] ?>&dir=up" title="Subir">↑</a>
          <a class="btn btn-outline-secondary btn-sm py-0" href="<?= $back ?>&accion=mov_foto&foto_id=<?= $ft['id'] ?>&dir=down" title="Bajar">↓</a>
          <a class="btn btn-outline-danger btn-sm py-0" href="<?= $back ?>&accion=del_foto&foto_id=<?= $ft['id'] ?>" onclick="return confirm('¿Quitar del informe?')" title="Quitar"><i data-feather="trash-2" style="width:13px;height:13px"></i></a>
        </form>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Elegir de la OT -->
<div class="tr-card mb-3">
  <div class="tr-card-header"><h6 class="mb-0 small fw-semibold">Agregar fotos de la OT</h6></div>
  <div class="tr-card-body">
    <?php $disp = array_filter($fotosOT, fn($f)=>!in_array($f['ruta'],$yaRutas)); ?>
    <?php if (empty($disp)): ?>
      <p class="text-muted small mb-0">No hay más fotos de la OT para agregar.</p>
    <?php else: ?>
    <form method="POST" action="<?= $back ?>">
      <input type="hidden" name="accion" value="add_ot_fotos"/>
      <div class="grid-ot mb-2">
        <?php foreach ($disp as $f): $r=$f['ruta']; ?>
        <label class="pick">
          <input type="checkbox" name="foto_ot[]" value="<?= sanitize($r) ?>"/>
          <img src="<?= sanitize(UPLOAD_URL.$r) ?>" alt=""/>
          <div class="cap">
            <select name="et[<?= sanitize($r) ?>]" class="form-select form-select-sm"><?php foreach($etiquetas as $e):?><option><?= $e?:'—' ?></option><?php endforeach;?></select>
            <input name="de[<?= sanitize($r) ?>]" class="form-control form-control-sm" placeholder="descripción" value="<?= sanitize($f['descripcion']?:'') ?>"/>
          </div>
        </label>
        <?php endforeach; ?>
      </div>
      <button class="btn btn-primary btn-sm">Agregar seleccionadas</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<!-- Subir extras -->
<div class="tr-card mb-4">
  <div class="tr-card-header"><h6 class="mb-0 small fw-semibold">Subir fotos extra (que no están en la OT)</h6></div>
  <div class="tr-card-body">
    <form method="POST" action="<?= $back ?>" enctype="multipart/form-data">
      <input type="hidden" name="accion" value="subir_extra"/>
      <input type="file" name="extras[]" class="form-control form-control-sm mb-2" accept="image/*" multiple required/>
      <div class="row g-2 mb-2">
        <div class="col-md-4"><select name="et_extra" class="form-select form-select-sm"><?php foreach($etiquetas as $e):?><option><?= $e?:'—' ?></option><?php endforeach;?></select></div>
        <div class="col-md-8"><input name="de_extra" class="form-control form-control-sm" placeholder="Descripción (se aplica a las que subas ahora)"/></div>
      </div>
      <button class="btn btn-primary btn-sm">Subir</button>
    </form>
  </div>
</div>

<script>
function addPf(){
  var t=document.querySelector('#tbl-pf tbody');
  var tr=document.createElement('tr');
  tr.innerHTML='<td><input class="form-control form-control-sm" name="pf_desc[]"/></td>'+
    '<td><input class="form-control form-control-sm" name="pf_importe[]" type="number" step="0.01" value="0.00"/></td>'+
    '<td><button type="button" class="btn btn-outline-danger btn-sm py-0" onclick="this.closest(\'tr\').remove()">&times;</button></td>';
  t.appendChild(tr);
}
if(window.feather) feather.replace();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
