<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';
requireLogin();

$db   = getDB();
$user = currentUser();
$id   = (int)($_GET['id'] ?? 0);

// API: buscar productos del inventario
if (isset($_GET['api']) && $_GET['api'] === 'buscar_producto') {
    header('Content-Type: application/json');
    $q = '%' . trim($_GET['q'] ?? '') . '%';
    $r = $db->prepare("
        SELECT p.id, p.codigo, p.nombre, p.precio_venta, p.stock_actual, p.marca, p.modelo
        FROM productos p
        WHERE p.activo = 1 AND p.stock_actual > 0
          AND (p.nombre LIKE ? OR p.codigo LIKE ? OR p.marca LIKE ?)
        ORDER BY p.nombre LIMIT 15
    ");
    $r->execute([$q, $q, $q]);
    echo json_encode($r->fetchAll());
    exit;
}

// Cargar OT
$ot = $db->prepare("
    SELECT ot.*, c.nombre AS cliente_nombre, c.ruc_dni, c.telefono, c.whatsapp, c.email AS cliente_email,
           te.nombre AS tipo_equipo, e.tipo_equipo_id, e.marca, e.modelo, e.serial, e.color, e.descripcion AS equipo_desc,
           s.nombre AS servicio_nombre
    FROM ordenes_trabajo ot
    JOIN clientes c ON c.id = ot.cliente_id
    JOIN equipos e ON e.id = ot.equipo_id
    JOIN tipos_equipo te ON te.id = e.tipo_equipo_id
    LEFT JOIN servicios s ON s.id = ot.servicio_id
    WHERE ot.id = ?");
$ot->execute([$id]);
$ot = $ot->fetch();
if (!$ot) { setFlash('danger','OT no encontrada'); redirect(BASE_URL.'modules/ot/index.php'); }

// Guardar cambios
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── GUARDA ANTI-PÉRDIDA DE DATOS ──────────────────────────────────────
    // Si el cuerpo del POST supera post_max_size, PHP DESCARTA $_POST y $_FILES
    // por completo (quedan vacíos), pero REQUEST_METHOD sigue siendo 'POST'.
    // Sin esta guarda, el UPDATE de abajo sobrescribiría la OT con vacíos/ceros
    // → "se borran todos los datos de la OT". Abortamos ANTES de tocar la BD.
    $contentLen = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if (empty($_POST) && $contentLen > 0) {
        $mb = round($contentLen / 1048576, 1);
        setFlash('danger',
            'El envío ('.$mb.' MB) superó el límite del servidor y fue descartado. '
          . 'NO se modificó la OT. Sube los videos con el cargador por partes (arrastra '
          . 'el video a la zona de video), no como adjunto del formulario.');
        redirect(BASE_URL.'modules/ot/editar.php?id='.$id);
    }
    // ──────────────────────────────────────────────────────────────────────

    // Actualizar OT
    $costoRep   = (float)($_POST['costo_repuestos'] ?? 0);
    $costoMO    = (float)($_POST['costo_mano_obra']  ?? 0);
    $desc       = (float)($_POST['descuento']         ?? 0);
    $total      = round($costoRep + $costoMO - $desc, 2);

    // Técnicos seleccionados (múltiples)
    $tecnicos_ids = array_map('intval', $_POST['tecnicos_ids'] ?? []);
    $tecnico_principal = $tecnicos_ids[0] ?? null; // el primero marcado es el principal

    $servicioId = $_POST['servicio_id'] ? (int)$_POST['servicio_id'] : null;
    $estado     = $_POST['estado'] ?? $ot['estado'];

    $db->prepare("
        UPDATE ordenes_trabajo SET
            tecnico_id          = ?,
            servicio_id         = ?,
            estado              = ?,
            problema_reportado  = ?,
            diagnostico_inicial = ?,
            diagnostico_tecnico = ?,
            observaciones       = ?,
            costo_repuestos     = ?,
            costo_mano_obra     = ?,
            descuento           = ?,
            costo_total         = ?,
            precio_final        = ?,
            fecha_estimada      = ?,
            garantia_dias       = ?
        WHERE id = ?
    ")->execute([
        $tecnico_principal,
        $servicioId,
        $estado,
        trim($_POST['problema_reportado']  ?? ''),
        trim($_POST['diagnostico_inicial'] ?? ''),
        trim($_POST['diagnostico_tecnico'] ?? ''),
        trim($_POST['observaciones']       ?? ''),
        $costoRep, $costoMO, $desc, $total, $total,
        $_POST['fecha_estimada'] ?: null,
        (int)($_POST['garantia_dias'] ?? 30),
        $id,
    ]);

    // Guardar técnicos múltiples en tabla pivot
    try {
        $db->prepare("DELETE FROM ot_tecnicos WHERE ot_id = ?")->execute([$id]);
        foreach ($tecnicos_ids as $i => $tid) {
            if ($tid > 0) {
                $db->prepare("INSERT IGNORE INTO ot_tecnicos (ot_id, tecnico_id) VALUES (?,?)")
                   ->execute([$id, $tid]);
            }
        }
    } catch (\Throwable $e) {
        // Si la tabla aún no existe, ignorar silenciosamente
    }

    // Actualizar equipo
    $db->prepare("UPDATE equipos SET tipo_equipo_id=?, marca=?, modelo=?, serial=?, color=?, descripcion=? WHERE id=?")
       ->execute([
           (int)($_POST['tipo_equipo_id'] ?? $ot['tipo_equipo_id'] ?? 1),
           trim($_POST['equipo_marca']  ?? ''),
           trim($_POST['equipo_modelo'] ?? ''),
           trim($_POST['equipo_serial'] ?? ''),
           trim($_POST['equipo_color']  ?? ''),
           trim($_POST['equipo_desc']   ?? ''),
           $ot['equipo_id'],
       ]);

    // Subir nuevas fotos
    if (!empty($_FILES['fotos']['name'][0])) {
        foreach ($_FILES['fotos']['name'] as $i => $fname) {
            if ($_FILES['fotos']['error'][$i] === 0) {
                $ruta = uploadFoto([
                    'name'=>$fname,'type'=>$_FILES['fotos']['type'][$i],
                    'tmp_name'=>$_FILES['fotos']['tmp_name'][$i],'size'=>$_FILES['fotos']['size'][$i]
                ], 'ot/'.$id);
                if ($ruta) $db->prepare("INSERT INTO fotos_ot (ot_id,ruta,tipo) VALUES (?,?,'proceso')")->execute([$id,$ruta]);
            }
        }
    }

    // Videos: se suben por chunks vía AJAX (upload_video_chunk.php) ANTES del
    // submit, así NUNCA viajan en el POST principal (evita reventar post_max_size).
    // El endpoint los guardó en fotos_ot con ot_id=NULL; aquí solo reasignamos.
    if (!empty($_POST['video_chunk_ids'])) {
        $vids = array_filter(array_map('intval', explode(',', $_POST['video_chunk_ids'])));
        foreach ($vids as $vidId) {
            $db->prepare("UPDATE fotos_ot SET ot_id=?, tipo='proceso' WHERE id=? AND ot_id IS NULL")
               ->execute([$id, $vidId]);
        }
    }

    // Registrar repuestos (borrar y reinsertar)
    $db->prepare("DELETE FROM ot_repuestos WHERE ot_id=?")->execute([$id]);
    $descs  = $_POST['rep_desc']   ?? [];
    $cants  = $_POST['rep_cant']   ?? [];
    $precios= $_POST['rep_precio'] ?? [];
    foreach ($descs as $i => $desc2) {
        $d = trim($desc2); $c = (float)($cants[$i]??1); $p = (float)($precios[$i]??0);
        if (!$d) continue;
        $db->prepare("INSERT INTO ot_repuestos (ot_id,descripcion,cantidad,precio_unit,subtotal) VALUES (?,?,?,?,?)")
           ->execute([$id, $d, $c, $p, round($c*$p,2)]);
    }

    setFlash('success', 'OT actualizada correctamente.');
    redirect(BASE_URL.'modules/ot/ver.php?id='.$id);
}

$tiposEquipo = $db->query("SELECT * FROM tipos_equipo WHERE activo=1 ORDER BY nombre")->fetchAll();
$tecnicos = $db->query("SELECT id, CONCAT(nombre,' ',apellido) AS nombre FROM usuarios WHERE rol='tecnico' AND activo=1")->fetchAll();

// Técnicos ya asignados a esta OT (tabla pivot)
try {
    $st_tec = $db->prepare("SELECT tecnico_id FROM ot_tecnicos WHERE ot_id = ? ");
    $st_tec->execute([$id]);
    $ids_asignados = $st_tec->fetchAll(PDO::FETCH_COLUMN);
} catch (\Throwable $e) {
    $ids_asignados = [];
}
// Fallback al campo clásico si la tabla aún no existe o está vacía
if (empty($ids_asignados) && $ot['tecnico_id']) {
    $ids_asignados = [$ot['tecnico_id']];
}
$repuestos   = $db->prepare("SELECT * FROM ot_repuestos WHERE ot_id=? ORDER BY id"); $repuestos->execute([$id]); $repuestos=$repuestos->fetchAll();

// Notas de la OT (para el panel de notas; la tabla puede no existir aún → try/catch)
$notasOT = [];
try {
    $qn = $db->prepare("SELECT n.*, COALESCE(NULLIF(TRIM(CONCAT(u.nombre,' ',COALESCE(u.apellido,''))),''),'—') AS autor
                        FROM notas_ot n LEFT JOIN usuarios u ON u.id=n.usuario_id
                        WHERE n.ot_id=? ORDER BY n.created_at DESC");
    $qn->execute([$id]);
    $notasOT = $qn->fetchAll();
} catch (\Throwable $e) { $notasOT = []; }
// Separar fotos y videos
try {
    $fotos  = $db->prepare("SELECT * FROM fotos_ot WHERE ot_id=? AND (tipo_archivo='foto' OR tipo_archivo IS NULL) ORDER BY id");
    $fotos->execute([$id]);
    $fotos  = $fotos->fetchAll();
    $videos_existentes = $db->prepare("SELECT * FROM fotos_ot WHERE ot_id=? AND tipo_archivo='video' ORDER BY id");
    $videos_existentes->execute([$id]);
    $videos_existentes = $videos_existentes->fetchAll();
} catch (\Exception $e) {
    $fotos = $db->prepare("SELECT * FROM fotos_ot WHERE ot_id=? ORDER BY id");
    $fotos->execute([$id]);
    $fotos = $fotos->fetchAll();
    $videos_existentes = [];
}

$pageTitle  = 'Editar OT '.$ot['codigo_ot'].' — '.APP_NAME;
$breadcrumb = [
    ['label'=>'Órdenes de trabajo','url'=>BASE_URL.'modules/ot/index.php'],
    ['label'=>$ot['codigo_ot'],'url'=>BASE_URL.'modules/ot/ver.php?id='.$id],
    ['label'=>'Editar','url'=>null],
];
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <div>
    <h4 class="fw-bold mb-0">Editar OT</h4>
    <div class="text-muted small mt-1"><?= sanitize($ot['codigo_ot']) ?> — <?= sanitize($ot['cliente_nombre']) ?></div>
  </div>
  <div class="d-flex gap-2">
    <a href="<?= BASE_URL ?>modules/ot/ver.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm">← Volver al detalle</a>
    <a href="<?= BASE_URL ?>modules/ot/pdf.php?id=<?= $id ?>" target="_blank" class="btn btn-outline-danger btn-sm">PDF</a>
  </div>
</div>

<form method="POST" enctype="multipart/form-data">
<div class="row g-3">

  <!-- Columna principal -->
  <div class="col-lg-8">

    <!-- Datos del equipo -->
    <div class="tr-card mb-3">
      <div class="tr-card-header"><h6 class="mb-0 small fw-semibold"><i data-feather="cpu" class="me-2" style="width:15px;height:15px"></i>EQUIPO</h6></div>
      <div class="tr-card-body">
        <div class="row g-2">
          <div class="col-md-4">
            <label class="tr-form-label">Tipo de equipo</label>
            <select name="tipo_equipo_id" class="form-select">
              <?php foreach($tiposEquipo as $t): ?>
              <option value="<?= $t['id'] ?>" <?= $ot['tipo_equipo_id']==$t['id']?'selected':'' ?>><?= sanitize($t['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="small text-muted mt-1">Puedes cambiar el tipo de equipo si aplica</div>
          </div>
          <div class="col-md-4">
            <label class="tr-form-label">Marca</label>
            <input type="text" name="equipo_marca" class="form-control" value="<?= sanitize($ot['marca']??'') ?>"/>
          </div>
          <div class="col-md-4">
            <label class="tr-form-label">Modelo</label>
            <input type="text" name="equipo_modelo" class="form-control" value="<?= sanitize($ot['modelo']??'') ?>"/>
          </div>
          <div class="col-md-4">
            <label class="tr-form-label">Serial</label>
            <input type="text" name="equipo_serial" class="form-control" value="<?= sanitize($ot['serial']??'') ?>"/>
          </div>
          <div class="col-md-2">
            <label class="tr-form-label">Color</label>
            <input type="text" name="equipo_color" class="form-control" value="<?= sanitize($ot['color']??'') ?>"/>
          </div>
          <div class="col-md-6">
            <label class="tr-form-label">Descripción</label>
            <input type="text" name="equipo_desc" class="form-control" value="<?= sanitize($ot['equipo_desc']??'') ?>"/>
          </div>
        </div>
      </div>
    </div>

    <!-- Diagnóstico -->
    <div class="tr-card mb-3">
      <div class="tr-card-header"><h6 class="mb-0 small fw-semibold"><i data-feather="search" class="me-2" style="width:15px;height:15px"></i>DIAGNÓSTICO</h6></div>
      <div class="tr-card-body">
        <div class="mb-3">
          <label class="tr-form-label">Problema reportado por el cliente *</label>
          <textarea name="problema_reportado" class="form-control" rows="3" required><?= sanitize($ot['problema_reportado']) ?></textarea>
        </div>
        <div class="mb-3">
          <label class="tr-form-label">Diagnóstico inicial</label>
          <textarea name="diagnostico_inicial" class="form-control" rows="2"><?= sanitize($ot['diagnostico_inicial']??'') ?></textarea>
        </div>
        <div class="mb-3">
          <label class="tr-form-label">Diagnóstico técnico detallado <span class="badge bg-primary ms-1">Aparece en el comprobante</span></label>
          <textarea name="diagnostico_tecnico" class="form-control" rows="3"><?= sanitize($ot['diagnostico_tecnico']??'') ?></textarea>
        </div>
        <div>
          <label class="tr-form-label">Observaciones</label>
          <textarea name="observaciones" class="form-control" rows="2"><?= sanitize($ot['observaciones']??'') ?></textarea>
        </div>
      </div>
    </div>

    <!-- Repuestos y servicios -->
    <div class="tr-card mb-3">
      <div class="tr-card-header">
        <h6 class="mb-0 small fw-semibold"><i data-feather="tool" class="me-2" style="width:15px;height:15px"></i>REPUESTOS Y SERVICIOS</h6>
        <button type="button" class="btn btn-outline-success btn-sm" onclick="agregarRepuesto()">
          <i data-feather="plus" style="width:13px;height:13px"></i> Agregar ítem
        </button>
      </div>
      <!-- Buscador de inventario -->
      <div style="padding:8px 12px; border-bottom:1px solid #f1f5f9; background:#fafbfc">
        <div class="position-relative">
          <div class="input-group input-group-sm">
            <span class="input-group-text" style="background:#fff">
              <i data-feather="search" style="width:13px;height:13px;color:#94a3b8"></i>
            </span>
            <input type="text" id="buscar-inventario" class="form-control form-control-sm"
                   placeholder="Buscar repuesto del inventario por nombre o código..."
                   autocomplete="off"/>
          </div>
          <div id="lista-inventario" class="list-group position-absolute w-100 shadow"
               style="z-index:9999; display:none; max-height:220px; overflow-y:auto; top:100%; left:0"></div>
        </div>
        <div style="font-size:10px; color:#94a3b8; margin-top:3px">
          Selecciona un producto para agregarlo automáticamente con su precio
        </div>
      </div>
      <div class="tr-card-body p-0">
        <div id="contenedor-repuestos">
          <?php if(empty($repuestos)): ?>
          <div id="fila-vacia-rep" class="text-center text-muted py-3 small">Sin repuestos — usa el botón para agregar</div>
          <?php else: ?>
          <?php foreach($repuestos as $r):
            $sub = round($r['cantidad'] * $r['precio_unit'], 2);
          ?>
          <div class="rep-item" style="border-bottom:1px solid #f1f5f9; padding:10px 12px">
            <div class="mb-2">
              <input type="text" name="rep_desc[]" class="form-control form-control-sm" value="<?= sanitize($r['descripcion']) ?>" required placeholder="Descripción del servicio o repuesto"/>
            </div>
            <div class="d-flex gap-2 align-items-center">
              <div style="flex:0 0 80px">
                <div style="font-size:10px;color:#94a3b8;font-weight:600;margin-bottom:2px">CANT.</div>
                <input type="number" name="rep_cant[]" class="form-control form-control-sm text-center rep-cant" value="<?= $r['cantidad'] ?>" min="0.01" step="0.01" onchange="recalcRep(this)"/>
              </div>
              <div style="flex:0 0 90px">
                <div style="font-size:10px;color:#94a3b8;font-weight:600;margin-bottom:2px">P. UNIT (S/)</div>
                <input type="number" name="rep_precio[]" class="form-control form-control-sm text-end rep-precio" value="<?= $r['precio_unit'] ?>" min="0" step="0.01" onchange="recalcRep(this)"/>
              </div>
              <div style="flex:1; text-align:right">
                <div style="font-size:10px;color:#94a3b8;font-weight:600;margin-bottom:2px">SUBTOTAL</div>
                <div class="rep-subtotal fw-bold" style="font-size:14px;color:#1e293b">S/ <?= number_format($sub,2) ?></div>
              </div>
              <div style="flex:0 0 30px; text-align:right; padding-top:16px">
                <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" onclick="eliminarRep(this)">✕</button>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Fotos adicionales -->
    <div class="tr-card mb-3">
      <div class="tr-card-header"><h6 class="mb-0 small fw-semibold"><i data-feather="camera" class="me-2" style="width:15px;height:15px"></i>FOTOS EXISTENTES Y NUEVAS</h6></div>
      <div class="tr-card-body">
        <?php if($fotos): ?>
        <div class="foto-preview-grid mb-3">
          <?php foreach($fotos as $f): ?>
          <div class="foto-preview-item">
            <a href="<?= UPLOAD_URL.$f['ruta'] ?>" target="_blank">
              <img src="<?= UPLOAD_URL.$f['ruta'] ?>" alt="foto"
                   onerror="this.src='data:image/svg+xml,<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'80\' height=\'80\'><rect fill=\'%23f3f4f6\' width=\'80\' height=\'80\'/><text x=\'50%25\' y=\'55%25\' text-anchor=\'middle\' fill=\'%239ca3af\' font-size=\'10\'>foto</text></svg>'"/>
            </a>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if(!empty($videos_existentes)): ?>
        <div class="mb-3">
          <div class="fw-semibold small mb-2">
            <i data-feather="video" style="width:14px;height:14px"></i>
            Videos existentes (<?= count($videos_existentes) ?>)
          </div>
          <div class="row g-2">
            <?php foreach($videos_existentes as $vid): ?>
            <div class="col-md-6">
              <div class="p-2 rounded border" style="background:#f5f3ff">
                <video controls preload="metadata"
                       style="width:100%;border-radius:6px;max-height:160px;background:#000;display:block">
                  <source src="<?= UPLOAD_URL.$vid['ruta'] ?>" type="video/mp4"/>
                </video>
                <div class="d-flex justify-content-between mt-1">
                  <span class="text-muted" style="font-size:10px">
                    🎬 <?= !empty($vid['duracion_seg']) ? sprintf('%d:%02d',intdiv((int)$vid['duracion_seg'],60),(int)$vid['duracion_seg']%60) : '' ?>
                    <?= !empty($vid['tamano_bytes']) ? ' · '.round($vid['tamano_bytes']/1024/1024,1).' MB' : '' ?>
                  </span>
                  <a href="<?= UPLOAD_URL.$vid['ruta'] ?>" target="_blank" download
                     class="btn btn-outline-secondary btn-sm py-0" style="font-size:10px">⬇</a>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
        <div class="foto-drop-zone" id="foto-drop">
          <i data-feather="upload-cloud" style="width:28px;height:28px;color:#9ca3af"></i>
          <p class="text-muted small mb-0 mt-1">Agregar más fotos (proceso/reparación)</p>
          <input type="file" id="input-fotos" name="fotos[]" multiple accept="image/*" style="display:none"/>
        </div>
        <div class="foto-preview-grid mt-2" id="preview-fotos"></div>

        <hr class="my-3"/>
        <div class="d-flex align-items-center justify-content-between mb-2">
          <div><i data-feather="video" style="width:16px;height:16px" class="me-1"></i>
            <span class="fw-semibold small">Agregar videos</span>
          </div>
          <span class="badge bg-info text-dark" style="font-size:10px">🎬 Máx 10 MB por video · se comprime automáticamente</span>
        </div>
        <div class="video-drop-zone" id="video-drop"
             style="border:2px dashed #c7d2fe;border-radius:10px;padding:18px;
                    text-align:center;cursor:pointer;background:#f5f3ff">
          <i data-feather="film" style="width:26px;height:26px;color:#818cf8"></i>
          <p class="mb-0 mt-2 small fw-semibold" style="color:#6366f1">Arrastra videos o haz clic</p>
          <p class="mb-0 mt-1" style="font-size:11px;color:#94a3b8">Solo videos de hasta 10 MB</p>
          <!-- SIN name: el video NO viaja en el POST principal, se sube por chunks -->
          <input type="file" id="input-videos" multiple
                 accept="video/mp4,video/quicktime,video/avi,video/webm,.mp4,.mov,.avi,.mkv,.webm,.3gp"
                 style="display:none"/>
        </div>
        <div class="video-preview-list mt-2" id="preview-videos"></div>
        <!-- IDs de fotos_ot (videos ya subidos por chunks) a reasignar en submit -->
        <input type="hidden" name="video_chunk_ids" id="video-chunk-ids" value=""/>
      </div>
    </div>

  </div><!-- /col-8 -->

  <!-- Columna derecha -->
  <div class="col-lg-4">

    <!-- Asignación -->
    <div class="tr-card mb-3">
      <div class="tr-card-header"><h6 class="mb-0 small fw-semibold"><i data-feather="settings" class="me-2" style="width:15px;height:15px"></i>ASIGNACIÓN</h6></div>
      <div class="tr-card-body">
        <div class="mb-2">
          <label class="tr-form-label">Estado</label>
          <select name="estado" class="form-select form-select-sm">
            <?php foreach(ESTADOS_OT as $k => $v): ?>
            <option value="<?= $k ?>" <?= $ot['estado']===$k?'selected':'' ?>><?= $v['label'] ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-2">
          <label class="tr-form-label">Técnicos asignados</label>
          <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:8px;max-height:160px;overflow-y:auto">
            <?php if(empty($tecnicos)): ?>
            <div class="text-muted small">No hay técnicos registrados</div>
            <?php else: ?>
            <?php foreach($tecnicos as $t): ?>
            <div class="form-check mb-1">
              <input class="form-check-input" type="checkbox" name="tecnicos_ids[]"
                     value="<?= $t['id'] ?>" id="tec_<?= $t['id'] ?>"
                     <?= in_array($t['id'], $ids_asignados) ? 'checked' : '' ?>>
              <label class="form-check-label small" for="tec_<?= $t['id'] ?>">
                <?= sanitize($t['nombre']) ?>
                <?php if(isset($ids_asignados[0]) && $ids_asignados[0] == $t['id']): ?>
                <span class="badge bg-primary ms-1" style="font-size:9px">Principal</span>
                <?php endif; ?>
              </label>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
          </div>
          <div class="text-muted small mt-1">El primero marcado será el técnico principal</div>
          <!-- Hidden para compatibilidad con código existente -->
          <input type="hidden" name="tecnico_id" id="tecnico_id_hidden" value="<?= $ot['tecnico_id'] ?>"/>
        </div>
        <div class="mb-2">
          <label class="tr-form-label">Fecha estimada de entrega</label>
          <input type="date" name="fecha_estimada" class="form-control form-control-sm" value="<?= $ot['fecha_estimada']??'' ?>"/>
        </div>
        <div class="mb-2">
          <label class="tr-form-label">Garantía (días)</label>
          <input type="number" name="garantia_dias" class="form-control form-control-sm" value="<?= $ot['garantia_dias']??30 ?>" min="0"/>
        </div>
      </div>
    </div>

    <!-- Presupuesto -->
    <div class="tr-card mb-3">
      <div class="tr-card-header"><h6 class="mb-0 small fw-semibold"><i data-feather="dollar-sign" class="me-2" style="width:15px;height:15px"></i>PRESUPUESTO</h6></div>
      <div class="tr-card-body">
        <div class="mb-2">
          <label class="tr-form-label">Servicio asignado</label>
          <?php if($ot['servicio_nombre']): ?>
          <div class="mb-1">
            <span class="badge bg-primary" style="font-size:12px">✓ <?= sanitize($ot['servicio_nombre']) ?></span>
          </div>
          <?php endif; ?>
          <input type="hidden" name="servicio_id" id="hidden-servicio-id" value="<?= $ot['servicio_id'] ?? '' ?>"/>
          <select id="sel-servicio-editar" class="form-select form-select-sm" onchange="cargarServicioEditar(this.value)">
            <option value="">— Cambiar / cargar servicio —</option>
            <?php
            $svsEdit = $db->query("SELECT id, nombre, precio, garantia_dias, requiere_repuestos FROM servicios WHERE activo=1 ORDER BY nombre")->fetchAll();
            foreach ($svsEdit as $sv): ?>
            <option value="<?= $sv['id'] ?>" <?= $ot['servicio_id']==$sv['id']?'selected':'' ?>><?= sanitize($sv['nombre']) ?> — <?= formatMoney($sv['precio']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="text-muted small mt-1">Al seleccionar, se precargará el precio y los repuestos del servicio.</div>
        </div>
        <div class="mb-2">
          <label class="tr-form-label">Costo repuestos (S/)</label>
          <input type="number" id="costo_repuestos" name="costo_repuestos" class="form-control form-control-sm currency-input" step="0.01" value="<?= $ot['costo_repuestos'] ?>"/>
        </div>
        <div class="mb-2">
          <label class="tr-form-label">Mano de obra (S/)</label>
          <input type="number" id="costo_mano_obra" name="costo_mano_obra" class="form-control form-control-sm currency-input" step="0.01" value="<?= $ot['costo_mano_obra'] ?>"/>
        </div>
        <div class="mb-2">
          <label class="tr-form-label">Descuento (S/)</label>
          <input type="number" id="descuento" name="descuento" class="form-control form-control-sm currency-input" step="0.01" value="<?= $ot['descuento']??0 ?>"/>
        </div>
        <div class="p-2 bg-light rounded text-end">
          <span class="small text-muted">Total:</span>
          <span class="fw-bold fs-5 ms-2" id="total_display"><?= formatMoney($ot['precio_final']) ?></span>
          <input type="hidden" name="precio_final" id="precio_final" value="<?= $ot['precio_final'] ?>"/>
        </div>
      </div>
    </div>

    <!-- Info no editable -->
    <div class="tr-card">
      <div class="tr-card-header"><h6 class="mb-0 small fw-semibold">INFO</h6></div>
      <div class="tr-card-body">
        <div class="small mb-1"><strong>Cliente:</strong> <?= sanitize($ot['cliente_nombre']) ?></div>
        <div class="small mb-1"><strong>Código OT:</strong> <?= sanitize($ot['codigo_ot']) ?></div>
        <div class="small mb-1"><strong>Código cliente:</strong> <code><?= sanitize($ot['codigo_publico']) ?></code></div>
        <div class="small mb-1"><strong>Ingreso:</strong> <?= formatDate($ot['fecha_ingreso']) ?></div>
      </div>
    </div>

    <button type="submit" class="btn btn-primary w-100 btn-lg mt-3">
      <i data-feather="save" style="width:16px;height:16px"></i> Guardar cambios
    </button>
    <a href="<?= BASE_URL ?>modules/ot/ver.php?id=<?= $id ?>" class="btn btn-outline-secondary w-100 mt-2">Cancelar</a>

  </div>
</div>
</form>

<!-- ══ NOTAS DE LA OT (form independiente → NO afecta el guardado de la OT) ══ -->
<div class="tr-card mb-3" id="notas">
  <div class="tr-card-header">
    <h6 class="mb-0 small fw-semibold"><i data-feather="message-square" class="me-2" style="width:15px;height:15px"></i>NOTAS</h6>
  </div>
  <div class="tr-card-body">
    <p class="text-muted small mb-2">Las notas marcadas como visibles aparecen en el seguimiento del cliente.</p>
    <form method="POST" action="<?= BASE_URL ?>modules/ot/notas.php">
      <input type="hidden" name="accion" value="agregar"/>
      <input type="hidden" name="ot_id" value="<?= $id ?>"/>
      <textarea name="nota" class="form-control form-control-sm" rows="2" maxlength="1000" placeholder="Escribe una nota sobre esta orden…"></textarea>
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mt-2">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="visible_cliente" id="nota-vis" value="1" checked/>
          <label class="form-check-label small" for="nota-vis">Visible para el cliente en el portal</label>
        </div>
        <button type="submit" class="btn btn-primary btn-sm"><i data-feather="plus" style="width:14px;height:14px"></i> Agregar nota</button>
      </div>
    </form>

    <hr class="my-3"/>

    <?php if (empty($notasOT)): ?>
      <p class="text-muted small mb-0">Aún no hay notas en esta orden.</p>
    <?php else: ?>
      <div class="d-flex flex-column gap-2">
        <?php foreach ($notasOT as $n): ?>
        <div class="d-flex gap-2 p-2 rounded" style="background:#f8fafc;border:1px solid #eef0f4">
          <div class="flex-grow-1" style="min-width:0">
            <div class="small" style="white-space:pre-line"><?= sanitize($n['nota']) ?></div>
            <div class="text-muted mt-1" style="font-size:11px">
              <?= sanitize($n['autor']) ?> · <?= formatDateTime($n['created_at']) ?>
              <?php if ((int)$n['visible_cliente'] === 1): ?>
                <span class="badge bg-success ms-1">Visible al cliente</span>
              <?php else: ?>
                <span class="badge bg-secondary ms-1">Interna</span>
              <?php endif; ?>
            </div>
          </div>
          <div class="d-flex flex-column gap-1">
            <a href="<?= BASE_URL ?>modules/ot/notas.php?ot_id=<?= $id ?>&toggle=<?= $n['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-1" title="Cambiar visibilidad"><i data-feather="eye" style="width:13px;height:13px"></i></a>
            <a href="<?= BASE_URL ?>modules/ot/notas.php?ot_id=<?= $id ?>&eliminar=<?= $n['id'] ?>" class="btn btn-sm btn-outline-danger py-0 px-1" title="Eliminar" onclick="return confirm('¿Eliminar esta nota?');"><i data-feather="trash-2" style="width:13px;height:13px"></i></a>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php
$pageScripts = <<<'JS'
<script>
initFotoDrop('foto-drop','preview-fotos','input-fotos');

// ── Cargador de VIDEO por chunks (igual que en "Nueva OT") ────────────────
// El video se sube por partes a upload_video_chunk.php ANTES del submit, así
// NUNCA viaja en el POST principal → no revienta post_max_size ni borra la OT.
(function() {
  var dropZone   = document.getElementById('video-drop');
  var input      = document.getElementById('input-videos');
  var previewDiv = document.getElementById('preview-videos');
  var chunkIdsEl = document.getElementById('video-chunk-ids');
  var submitBtn  = document.querySelector('form button[type="submit"]');
  if (!dropZone || !input || !previewDiv) return;

  var CHUNK_SIZE   = 1 * 1024 * 1024;  // 1MB por chunk (bajo el límite de 2MB)
  var MAX_VIDEO_MB = 10;               // límite máximo por video
  var uploadedIds  = [];
  var uploading    = 0;
  var btnHtml     = submitBtn ? submitBtn.innerHTML : '';

  dropZone.addEventListener('click',    function(){ input.click(); });
  dropZone.addEventListener('dragover', function(e){ e.preventDefault(); dropZone.style.borderColor='#6366f1'; dropZone.style.background='#eef2ff'; });
  dropZone.addEventListener('dragleave',function(){ dropZone.style.borderColor='#c7d2fe'; dropZone.style.background='#f5f3ff'; });
  dropZone.addEventListener('drop',     function(e){ e.preventDefault(); dropZone.style.borderColor='#c7d2fe'; dropZone.style.background='#f5f3ff'; uploadVideos(e.dataTransfer.files); });
  input.addEventListener('change',      function(){ uploadVideos(this.files); input.value=''; });

  function lockBtn(){ if(submitBtn){ submitBtn.disabled=true; submitBtn.textContent='Esperando videos...'; } }
  function unlockBtn(){ if(submitBtn && uploading<=0){ submitBtn.disabled=false; submitBtn.innerHTML=btnHtml; if(window.feather) feather.replace(); } }

  function uploadVideos(files) {
    var validExts = ['mp4','mov','avi','mkv','webm','3gp','wmv','m4v'];
    Array.from(files).forEach(function(file) {
      var ext = file.name.split('.').pop().toLowerCase();
      if (!validExts.includes(ext)) { alert('Formato no válido: ' + file.name); return; }
      var mb = file.size / 1024 / 1024;
      if (mb > MAX_VIDEO_MB) {
        alert('El video "' + file.name + '" pesa ' + mb.toFixed(1) + ' MB.\n\n'
            + 'Solo se permiten videos de hasta ' + MAX_VIDEO_MB + ' MB. '
            + 'Recórtalo o comprímelo antes de subirlo.');
        return;
      }
      startChunkUpload(file);
    });
  }

  function startChunkUpload(file) {
    var fileId      = 'f' + Date.now() + Math.random().toString(36).substr(2,6);
    var totalChunks = Math.ceil(file.size / CHUNK_SIZE);
    var mb          = (file.size / 1024 / 1024).toFixed(1);

    var card = document.createElement('div');
    card.id  = 'vc_' + fileId;
    card.style.cssText = 'display:flex;align-items:center;gap:10px;padding:10px 12px;'
      + 'background:#f5f3ff;border:1px solid #c7d2fe;border-radius:8px;margin-bottom:8px';
    card.innerHTML =
      '<span style="font-size:22px">🎬</span>'
      + '<div style="flex:1;min-width:0">'
      + '<div class="fw-semibold small text-truncate">' + esc(file.name) + '</div>'
      + '<div style="font-size:11px;color:#6b7280" id="vstatus_'+fileId+'">Preparando...</div>'
      + '<div class="progress mt-1" style="height:5px;border-radius:3px;background:#e0d9ff">'
      + '<div id="vprog_'+fileId+'" class="progress-bar" style="width:0%;background:#6366f1;transition:width .3s"></div>'
      + '</div></div>'
      + '<span id="vcheck_'+fileId+'" style="font-size:18px;display:none">✅</span>'
      + '<span id="verr_'+fileId+'"  style="font-size:18px;display:none;color:#dc2626">❌</span>';
    previewDiv.appendChild(card);

    uploading++;
    lockBtn();
    uploadChunk(file, fileId, 0, totalChunks, mb);
  }

  function uploadChunk(file, fileId, chunkIndex, totalChunks, mb) {
    var start = chunkIndex * CHUNK_SIZE;
    var end   = Math.min(start + CHUNK_SIZE, file.size);
    var chunk = file.slice(start, end);

    var statusEl = document.getElementById('vstatus_' + fileId);
    var progEl   = document.getElementById('vprog_'   + fileId);
    var pct      = Math.round((chunkIndex / totalChunks) * 100);
    if (statusEl) statusEl.textContent = 'Subiendo... ' + pct + '% (' + mb + ' MB)';
    if (progEl)   progEl.style.width   = pct + '%';

    var fd = new FormData();
    fd.append('chunk',       chunk, file.name);
    fd.append('chunkIndex',  chunkIndex);
    fd.append('totalChunks', totalChunks);
    fd.append('fileId',      fileId);
    fd.append('fileName',    file.name);
    fd.append('otId',        0); // se reasigna al guardar la OT

    fetch(window.BASE_URL + 'modules/ot/upload_video_chunk.php', { method:'POST', body:fd })
      .then(function(r){ return r.json(); })
      .then(function(data) {
        if (data.error) { onVideoError(fileId, data.error); return; }
        if (data.status === 'chunk_ok')      { uploadChunk(file, fileId, chunkIndex + 1, totalChunks, mb); }
        else if (data.status === 'complete') { onVideoComplete(fileId, data, mb); }
      })
      .catch(function(err){ onVideoError(fileId, 'Error de red: ' + err.message); });
  }

  function onVideoComplete(fileId, data, mb) {
    var statusEl = document.getElementById('vstatus_' + fileId);
    var progEl   = document.getElementById('vprog_'   + fileId);
    var checkEl  = document.getElementById('vcheck_'  + fileId);
    if (progEl) progEl.style.width = '100%';
    var finalMb = data.final_size_mb || mb;
    if (statusEl) statusEl.textContent = (data.compressed ? '✓ Comprimido: ' : '✓ Guardado: ') + finalMb + ' MB';
    if (checkEl)  checkEl.style.display = '';

    if (data.fotos_ot_id) {
      uploadedIds.push(data.fotos_ot_id);
      if (chunkIdsEl) chunkIdsEl.value = uploadedIds.join(',');
    }
    uploading--;
    unlockBtn();
  }

  function onVideoError(fileId, msg) {
    var statusEl = document.getElementById('vstatus_' + fileId);
    var errEl    = document.getElementById('verr_'    + fileId);
    if (statusEl) { statusEl.textContent = 'Error: ' + msg; statusEl.style.color = '#dc2626'; }
    if (errEl)    errEl.style.display = '';
    uploading--;
    unlockBtn();
  }

  function esc(s){ return (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
})();

// Cargar servicio → precargar repuestos en editar OT
function cargarServicioEditar(id) {
  if (!id) return;
  // Actualizar el hidden con el servicio seleccionado
  document.getElementById('hidden-servicio-id').value = id;

  if (!confirm('¿Precargar precio y repuestos del servicio? Se agregarán a los existentes.')) return;

  fetch(window.BASE_URL + 'modules/servicios/api_servicio.php?id=' + id)
    .then(r => r.json())
    .then(data => {
      if (!data.ok) return;
      const mo = document.getElementById('costo_mano_obra');
      if (mo) { mo.value = parseFloat(data.precio).toFixed(2); calcularTotalOT(); }
      const gar = document.querySelector('input[name="garantia_dias"]');
      if (gar) gar.value = data.garantia;

      if (data.requiere && data.repuestos.length > 0) {
        const vacia = document.getElementById('fila-vacia-rep');
        if (vacia) vacia.remove();
        data.repuestos.forEach(r => {
          const desc = r.nombre + (r.codigo ? ' ['+r.codigo+']' : '');
          agregarRepuestoConDatos(desc, r.cantidad, r.precio_referencial);
        });
        calcTotalesRep();
      }
      // NO resetear el select — mantener el servicio visible
    })
    .catch(() => {});
}

function agregarRepuestoConDatos(desc, cant, precio) {
  const sub = ((parseFloat(cant)||1) * (parseFloat(precio)||0)).toFixed(2);
  _insertarRepItem(escH(desc||''), parseFloat(cant)||1, parseFloat(precio)||0, sub);
  calcTotalesRep();
}

function _insertarRepItem(desc, cant, precio, sub) {
  const cont  = document.getElementById('contenedor-repuestos');
  const vacia = document.getElementById('fila-vacia-rep');
  if (vacia) vacia.remove();
  const div = document.createElement('div');
  div.className = 'rep-item';
  div.style.cssText = 'border-bottom:1px solid #f1f5f9; padding:10px 12px';
  div.innerHTML = `
    <div class="mb-2">
      <input type="text" name="rep_desc[]" class="form-control form-control-sm" value="${desc}" required placeholder="Descripción del servicio o repuesto"/>
    </div>
    <div class="d-flex gap-2 align-items-center">
      <div style="flex:0 0 80px">
        <div style="font-size:10px;color:#94a3b8;font-weight:600;margin-bottom:2px">CANT.</div>
        <input type="number" name="rep_cant[]" class="form-control form-control-sm text-center rep-cant" value="${cant}" min="0.01" step="0.01" onchange="recalcRep(this)"/>
      </div>
      <div style="flex:0 0 90px">
        <div style="font-size:10px;color:#94a3b8;font-weight:600;margin-bottom:2px">P. UNIT (S/)</div>
        <input type="number" name="rep_precio[]" class="form-control form-control-sm text-end rep-precio" value="${parseFloat(precio).toFixed(2)}" min="0" step="0.01" onchange="recalcRep(this)"/>
      </div>
      <div style="flex:1; text-align:right">
        <div style="font-size:10px;color:#94a3b8;font-weight:600;margin-bottom:2px">SUBTOTAL</div>
        <div class="rep-subtotal fw-bold" style="font-size:14px;color:#1e293b">S/ ${parseFloat(sub).toFixed(2)}</div>
      </div>
      <div style="flex:0 0 30px; text-align:right; padding-top:16px">
        <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" onclick="eliminarRep(this)">✕</button>
      </div>
    </div>`;
  cont.appendChild(div);
}

function escH(s) {
  return (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function agregarRepuesto() {
  _insertarRepItem('', 1, 0, '0.00');
  const items = document.querySelectorAll('.rep-item input[name="rep_desc[]"]');
  if (items.length) items[items.length-1].focus();
}

function eliminarRep(btn) {
  btn.closest('.rep-item').remove();
  calcTotalesRep();
}

function recalcRep(inp) {
  const item   = inp.closest('.rep-item');
  const c      = parseFloat(item.querySelector('.rep-cant').value)   || 0;
  const p      = parseFloat(item.querySelector('.rep-precio').value) || 0;
  item.querySelector('.rep-subtotal').textContent = 'S/ ' + (c * p).toFixed(2);
  calcTotalesRep();
}

function calcTotalesRep() {
  let total = 0;
  document.querySelectorAll('.rep-item').forEach(item => {
    const c = parseFloat(item.querySelector('.rep-cant')?.value)   || 0;
    const p = parseFloat(item.querySelector('.rep-precio')?.value) || 0;
    total += c * p;
  });
  const crep = document.getElementById('costo_repuestos');
  if (crep) crep.value = total.toFixed(2);
  calcularTotalOT();
}

// ── Buscador de inventario ────────────────────────────────────
let timerInv;
document.getElementById('buscar-inventario').addEventListener('input', function() {
  clearTimeout(timerInv);
  const q = this.value.trim();
  const lista = document.getElementById('lista-inventario');
  if (q.length < 2) { lista.style.display = 'none'; return; }
  timerInv = setTimeout(() => {
    fetch('editar.php?id=<?= $id ?>&api=buscar_producto&q=' + encodeURIComponent(q))
      .then(r => r.json()).then(data => {
        if (!data.length) {
          lista.innerHTML = '<div class="list-group-item text-muted small py-2">Sin resultados en inventario</div>';
        } else {
          lista.innerHTML = data.map(p => `
            <button type="button" class="list-group-item list-group-item-action py-2"
                    onclick="agregarDesdeInventario(${JSON.stringify(p).replace(/"/g,'&quot;')})">
              <div class="d-flex justify-content-between align-items-start">
                <div>
                  <div class="fw-semibold small">${p.nombre}</div>
                  <div class="text-muted" style="font-size:11px">
                    ${p.codigo}${p.marca ? ' · '+p.marca : ''}${p.modelo ? ' '+p.modelo : ''}
                  </div>
                </div>
                <div class="text-end ms-2" style="white-space:nowrap">
                  <div class="fw-bold text-primary small">S/ ${parseFloat(p.precio_venta).toFixed(2)}</div>
                  <div class="text-muted" style="font-size:10px">Stock: ${p.stock_actual}</div>
                </div>
              </div>
            </button>`).join('');
        }
        lista.style.display = 'block';
      });
  }, 280);
});

function agregarDesdeInventario(p) {
  agregarRepuestoConDatos(p.nombre + (p.marca ? ' ' + p.marca : '') + (p.modelo ? ' ' + p.modelo : ''), 1, p.precio_venta);
  document.getElementById('buscar-inventario').value = '';
  document.getElementById('lista-inventario').style.display = 'none';
}

// Cerrar dropdown al hacer clic afuera
document.addEventListener('click', function(e) {
  if (!e.target.closest('#buscar-inventario') && !e.target.closest('#lista-inventario')) {
    document.getElementById('lista-inventario').style.display = 'none';
  }
});
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
