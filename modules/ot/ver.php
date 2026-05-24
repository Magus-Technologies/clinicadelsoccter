<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';
requireLogin();

$db  = getDB();
$id  = (int)($_GET['id'] ?? 0);
$user = currentUser();

$ot = $db->prepare("
  SELECT ot.*, c.nombre as cliente_nombre, c.telefono, c.whatsapp, c.email as cliente_email,
         c.ruc_dni, te.nombre as tipo_equipo, e.marca, e.modelo, e.serial, e.color,
         CONCAT(u.nombre,' ',u.apellido) as tecnico_nombre,
         CONCAT(uc.nombre,' ',uc.apellido) as creador_nombre,
         s.nombre as servicio_nombre
  FROM ordenes_trabajo ot
  JOIN clientes c    ON c.id  = ot.cliente_id
  JOIN equipos e     ON e.id  = ot.equipo_id
  JOIN tipos_equipo te ON te.id = e.tipo_equipo_id
  LEFT JOIN usuarios u  ON u.id  = ot.tecnico_id
  LEFT JOIN usuarios uc ON uc.id = ot.usuario_creador_id
  LEFT JOIN servicios s ON s.id  = ot.servicio_id
  WHERE ot.id = ?
");
$ot->execute([$id]);
$ot = $ot->fetch();

if (!$ot) { setFlash('danger','OT no encontrada'); redirect(BASE_URL . 'modules/ot/index.php'); }

// Cambio de estado
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'cambiar_estado') {
        $nuevo  = $_POST['nuevo_estado'] ?? '';
        $coment = trim($_POST['comentario'] ?? '');
        $allowed = array_keys(ESTADOS_OT);
        if (in_array($nuevo, $allowed)) {
            $extra = '';
            $params = [$nuevo];
            if ($nuevo === 'entregado') {
                $extra = ', fecha_entrega = NOW()';
            }
            $db->prepare("UPDATE ordenes_trabajo SET estado = ? $extra WHERE id = ?")->execute([$nuevo, $id]);
            $db->prepare("INSERT INTO historial_ot (ot_id,usuario_id,estado_antes,estado_nuevo,comentario) VALUES (?,?,?,?,?)")
               ->execute([$id, $user['id'], $ot['estado'], $nuevo, $coment]);
            setFlash('success', 'Estado actualizado a: ' . ESTADOS_OT[$nuevo]['label']);
            redirect(BASE_URL . 'modules/ot/ver.php?id=' . $id);
        }
    }
    if ($_POST['action'] === 'aprobar_presupuesto') {
        $db->prepare("UPDATE ordenes_trabajo SET presupuesto_aprobado=1, fecha_aprobacion=NOW(), aprobado_por=? WHERE id=?")
           ->execute([$_POST['metodo_aprobacion'] ?? 'firma', $id]);
        setFlash('success','Presupuesto aprobado.');
        redirect(BASE_URL . 'modules/ot/ver.php?id=' . $id);
    }
    if ($_POST['action'] === 'registrar_pago') {
        $db->prepare("UPDATE ordenes_trabajo SET pagado=1, metodo_pago=?, fecha_pago=NOW() WHERE id=?")
           ->execute([$_POST['metodo_pago'], $id]);
        // Movimiento de caja
        $cajaAbierta = $db->prepare("SELECT id FROM cajas WHERE fecha=CURDATE() AND estado='abierta' ORDER BY id DESC LIMIT 1");
        $cajaAbierta->execute();
        $caja = $cajaAbierta->fetchColumn();
        if ($caja) {
            $db->prepare("INSERT INTO movimientos_caja (caja_id,tipo,concepto,monto,referencia,usuario_id) VALUES (?,?,?,?,?,?)")
               ->execute([$caja,'ingreso','Pago reparación ' . $ot['codigo_ot'], $ot['precio_final'], $ot['codigo_ot'], $user['id']]);
        }
        setFlash('success','Pago registrado correctamente.');
        redirect(BASE_URL . 'modules/ot/ver.php?id=' . $id);
    }
}

// Fotos
// Cargar fotos y videos con fallback si columnas nuevas no existen
try {
    $fotos = $db->prepare("SELECT * FROM fotos_ot WHERE ot_id=? AND (tipo_archivo='foto' OR tipo_archivo IS NULL) ORDER BY created_at");
    $fotos->execute([$id]);
    $fotos = $fotos->fetchAll();
    $videos = $db->prepare("SELECT * FROM fotos_ot WHERE ot_id=? AND tipo_archivo='video' ORDER BY created_at");
    $videos->execute([$id]);
    $videos = $videos->fetchAll();
} catch (\Exception $e) {
    // Columnas nuevas aún no existen en la BD
    $fotos_stmt = $db->prepare("SELECT * FROM fotos_ot WHERE ot_id=? ORDER BY created_at");
    $fotos_stmt->execute([$id]);
    $fotos  = $fotos_stmt->fetchAll();
    $videos = [];
}

// Historial
$historial = $db->prepare("
  SELECT h.*, CONCAT(u.nombre,' ',u.apellido) as usuario_nombre
  FROM historial_ot h
  JOIN usuarios u ON u.id=h.usuario_id
  WHERE h.ot_id=? ORDER BY h.created_at
");
$historial->execute([$id]);
$historial = $historial->fetchAll();

// Repuestos
$repuestos = $db->prepare("SELECT * FROM ot_repuestos WHERE ot_id=?");
$repuestos->execute([$id]);
$repuestos = $repuestos->fetchAll();

// Checklist
$checklist = $ot['checklist'] ? json_decode($ot['checklist'], true) : [];

// Técnicos para reasignar
$tecnicos = $db->query("SELECT id,CONCAT(nombre,' ',apellido) as nombre FROM usuarios WHERE rol='tecnico' AND activo=1")->fetchAll();

$pageTitle  = $ot['codigo_ot'] . ' — ' . APP_NAME;
$breadcrumb = [
    ['label'=>'Órdenes de trabajo','url'=>BASE_URL.'modules/ot/index.php'],
    ['label'=>$ot['codigo_ot'],'url'=>null],
];
require_once __DIR__ . '/../../includes/header.php';

$estado = $ot['estado'];
$eInfo = ESTADOS_OT[$estado] ?? ['label'=>ucfirst(str_replace('_',' ',$estado)),'color'=>'secondary','icon'=>'circle','es_final'=>false];
?>

<!-- Header OT -->
<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
  <div>
    <h4 class="fw-bold mb-0"><?= sanitize($ot['codigo_ot']) ?></h4>
    <div class="d-flex align-items-center gap-2 mt-1">
      <?= estadoOTBadge($estado) ?>
      <span class="text-muted small">Creada <?= formatDateTime($ot['created_at']) ?></span>
      <span class="badge bg-light text-dark border small" title="Código para el cliente">
        🔑 <?= sanitize($ot['codigo_publico']) ?>
      </span>
    </div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a href="<?= BASE_URL ?>modules/ot/editar.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm">
      <i data-feather="edit-2" style="width:14px;height:14px"></i> Editar
    </a>
    <a href="<?= BASE_URL ?>modules/ot/pdf.php?id=<?= $id ?>" target="_blank" class="btn btn-outline-danger btn-sm">
      <i data-feather="file-text" style="width:14px;height:14px"></i> PDF / Imprimir
    </a>
    <?php if ($ot['whatsapp']): ?>
    <a href="https://wa.me/<?= preg_replace('/\D/','',$ot['whatsapp']) ?>?text=Hola+<?= urlencode($ot['cliente_nombre']) ?>%2C+su+equipo+est%C3%A1+<?= urlencode(isset(ESTADOS_OT[$estado]) ? ESTADOS_OT[$estado]['label'] : ucfirst(str_replace('_',' ',$estado))) ?>+%28OT:+<?= $ot['codigo_ot'] ?>%29"
       target="_blank" class="btn btn-success btn-sm">
      <i data-feather="message-circle" style="width:14px;height:14px"></i> WhatsApp
    </a>
    <?php endif; ?>
  </div>
</div>

<div class="row g-3">
  <!-- COLUMNA PRINCIPAL -->
  <div class="col-lg-8">

    <!-- Info cliente y equipo -->
    <div class="row g-3 mb-3">
      <div class="col-md-6">
        <div class="tr-card h-100">
          <div class="tr-card-header"><h6 class="mb-0 small fw-semibold">CLIENTE</h6></div>
          <div class="tr-card-body">
            <div class="fw-semibold"><?= sanitize($ot['cliente_nombre']) ?></div>
            <?php if ($ot['ruc_dni']): ?><div class="text-muted small">DNI/RUC: <?= sanitize($ot['ruc_dni']) ?></div><?php endif; ?>
            <?php if ($ot['telefono']): ?><div class="small">📞 <?= sanitize($ot['telefono']) ?></div><?php endif; ?>
            <?php if ($ot['whatsapp']): ?><div class="small">💬 <?= sanitize($ot['whatsapp']) ?></div><?php endif; ?>
            <?php if ($ot['cliente_email']): ?><div class="small">✉️ <?= sanitize($ot['cliente_email']) ?></div><?php endif; ?>
            <a href="<?= BASE_URL ?>modules/clientes/ver.php?id=<?= $ot['cliente_id'] ?>" class="btn btn-xs btn-outline-primary btn-sm mt-2 py-0">Ver historial</a>
          </div>
        </div>
      </div>
      <div class="col-md-6">
        <div class="tr-card h-100">
          <div class="tr-card-header"><h6 class="mb-0 small fw-semibold">EQUIPO</h6></div>
          <div class="tr-card-body">
            <div class="fw-semibold"><?= sanitize($ot['tipo_equipo']) ?></div>
            <?php if ($ot['marca'] || $ot['modelo']): ?><div><?= sanitize($ot['marca'].' '.$ot['modelo']) ?></div><?php endif; ?>
            <?php if ($ot['serial']): ?><div class="text-muted small">S/N: <code><?= sanitize($ot['serial']) ?></code></div><?php endif; ?>
            <?php if ($ot['color']): ?><div class="text-muted small">Color: <?= sanitize($ot['color']) ?></div><?php endif; ?>
            <?php if ($ot['servicio_nombre']): ?><div class="mt-2"><span class="badge bg-primary"><?= sanitize($ot['servicio_nombre']) ?></span></div><?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Problema y diagnóstico -->
    <div class="tr-card mb-3">
      <div class="tr-card-header"><h6 class="mb-0 small fw-semibold">DIAGNÓSTICO</h6></div>
      <div class="tr-card-body">
        <div class="tr-section-title">Problema reportado</div>
        <p class="mb-3"><?= nl2br(sanitize($ot['problema_reportado'])) ?></p>
        <?php if ($ot['diagnostico_inicial']): ?>
        <div class="tr-section-title">Diagnóstico inicial</div>
        <p class="mb-3"><?= nl2br(sanitize($ot['diagnostico_inicial'])) ?></p>
        <?php endif; ?>
        <?php if ($ot['diagnostico_tecnico']): ?>
        <div class="tr-section-title">Diagnóstico técnico</div>
        <p class="mb-0"><?= nl2br(sanitize($ot['diagnostico_tecnico'])) ?></p>
        <?php endif; ?>
      </div>
    </div>

    <!-- Fotos y Videos -->
    <?php if ($fotos || !empty($videos)): ?>
    <div class="tr-card mb-3">
      <div class="tr-card-header">
        <h6 class="mb-0 small fw-semibold">
          FOTOS Y VIDEOS DEL EQUIPO
          <?php if(!empty($fotos)): ?>
          <span class="badge bg-secondary ms-1"><?= count($fotos) ?> foto(s)</span>
          <?php endif; ?>
          <?php if(!empty($videos)): ?>
          <span class="badge bg-info text-dark ms-1">🎬 <?= count($videos) ?> video(s)</span>
          <?php endif; ?>
        </h6>
      </div>
      <div class="tr-card-body">
        <?php if(!empty($fotos)): ?>
        <div class="foto-preview-grid mb-3">
          <?php foreach ($fotos as $foto): ?>
          <div class="foto-preview-item">
            <a href="<?= UPLOAD_URL . $foto['ruta'] ?>" target="_blank">
              <img src="<?= UPLOAD_URL . $foto['ruta'] ?>"
                   alt="<?= sanitize($foto['descripcion'] ?? 'foto') ?>"
                   onerror="this.src='data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' width='80' height='80'><rect fill='%23f3f4f6' width='80' height='80'/><text x='50%25' y='55%25' text-anchor='middle' fill='%239ca3af' font-size='11'>error</text></svg>'"/>
            </a>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if(!empty($videos)): ?>
        <?php if(!empty($fotos)): ?><hr class="my-3"/><?php endif; ?>
        <div class="row g-3">
          <?php foreach($videos as $vid): ?>
          <div class="col-12 col-md-6">
            <div class="p-2 rounded border" style="background:#f5f3ff">
              <video controls preload="metadata"
                     style="width:100%;border-radius:6px;max-height:240px;background:#000;display:block">
                <source src="<?= UPLOAD_URL . $vid['ruta'] ?>" type="video/mp4"/>
                Tu navegador no soporta video HTML5.
              </video>
              <div class="d-flex justify-content-between align-items-center mt-1 px-1">
                <span class="text-muted" style="font-size:11px">
                  🎬
                  <?php if(!empty($vid['duracion_seg'])): ?>
                  <?= sprintf('%d:%02d', intdiv((int)$vid['duracion_seg'],60), (int)$vid['duracion_seg']%60) ?>
                  <?php endif; ?>
                  <?php if(!empty($vid['tamano_bytes'])): ?>
                  · <?= round($vid['tamano_bytes']/1024/1024, 1) ?> MB
                  <?php endif; ?>
                </span>
                <a href="<?= UPLOAD_URL . $vid['ruta'] ?>" target="_blank" download
                   class="btn btn-outline-secondary btn-sm py-0 px-2" style="font-size:11px">
                  ⬇ Descargar
                </a>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Checklist -->
    <?php if ($checklist): ?>
    <div class="tr-card mb-3">
      <div class="tr-card-header"><h6 class="mb-0 small fw-semibold">CHECKLIST FÍSICO</h6></div>
      <div class="tr-card-body">
        <?php foreach ($checklist as $k => $val):
            if ($k === '_observacion') continue;
            $badge = $val==='bueno'?'success':($val==='malo'?'danger':'secondary');
            $label = $val==='bueno'?'Bueno':($val==='malo'?'Malo':'N/A');
        ?>
        <div class="checklist-item">
          <span class="small"><?= sanitize($k) ?></span>
          <span class="badge bg-<?= $badge ?>"><?= $label ?></span>
        </div>
        <?php endforeach; ?>
        <?php if (!empty($checklist['_observacion'])): ?>
        <div class="mt-2 p-2 bg-light rounded small"><?= sanitize($checklist['_observacion']) ?></div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Repuestos usados -->
    <?php if ($repuestos): ?>
    <div class="tr-card mb-3">
      <div class="tr-card-header"><h6 class="mb-0 small fw-semibold">REPUESTOS UTILIZADOS</h6></div>
      <div class="tr-card-body p-0">
        <table class="tr-table">
          <thead><tr><th>Descripción</th><th>Cant.</th><th>P. Unit.</th><th>Subtotal</th></tr></thead>
          <tbody>
            <?php foreach ($repuestos as $r): ?>
            <tr>
              <td><?= sanitize($r['descripcion']) ?></td>
              <td><?= $r['cantidad'] ?></td>
              <td><?= formatMoney($r['precio_unit']) ?></td>
              <td><?= formatMoney($r['subtotal']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <!-- Timeline -->
    <div class="tr-card">
      <div class="tr-card-header"><h6 class="mb-0 small fw-semibold">HISTORIAL / TIMELINE</h6></div>
      <div class="tr-card-body">
        <div class="ot-timeline">
          <?php foreach ($historial as $h): ?>
          <div class="ot-timeline-item">
            <div class="fw-semibold small">
              <?= isset(ESTADOS_OT[$h['estado_nuevo']]) ? ESTADOS_OT[$h['estado_nuevo']]['label'] : sanitize($h['estado_nuevo']) ?>
            </div>
            <div class="text-muted" style="font-size:12px"><?= sanitize($h['usuario_nombre']) ?> — <?= formatDateTime($h['created_at']) ?></div>
            <?php if ($h['comentario']): ?>
            <div class="small text-muted mt-1"><?= sanitize($h['comentario']) ?></div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

  </div><!-- /col-8 -->

  <!-- COLUMNA DERECHA -->
  <div class="col-lg-4">

    <!-- Resumen financiero -->
    <div class="tr-card mb-3">
      <div class="tr-card-header"><h6 class="mb-0 small fw-semibold">RESUMEN FINANCIERO</h6></div>
      <div class="tr-card-body">
        <div class="d-flex justify-content-between small mb-1"><span>Repuestos:</span><span><?= formatMoney($ot['costo_repuestos']) ?></span></div>
        <div class="d-flex justify-content-between small mb-1"><span>Mano de obra:</span><span><?= formatMoney($ot['costo_mano_obra']) ?></span></div>
        <?php if ($ot['descuento'] > 0): ?>
        <div class="d-flex justify-content-between small mb-1 text-danger"><span>Descuento:</span><span>-<?= formatMoney($ot['descuento']) ?></span></div>
        <?php endif; ?>
        <hr class="my-2">
        <div class="d-flex justify-content-between fw-bold"><span>Total:</span><span><?= formatMoney($ot['precio_final']) ?></span></div>
        <?php if ($ot['pagado']): ?>
        <div class="alert alert-success py-1 mt-2 small mb-0">✅ Pagado — <?= ucfirst($ot['metodo_pago']) ?></div>
        <?php else: ?>
        <div class="alert alert-warning py-1 mt-2 small mb-0">⏳ Pago pendiente</div>
        <?php endif; ?>

        <!-- Presupuesto aprobado? -->
        <?php if (!$ot['presupuesto_aprobado'] && $ot['precio_final'] > 0): ?>
        <form method="POST" class="mt-2">
          <input type="hidden" name="action" value="aprobar_presupuesto"/>
          <select name="metodo_aprobacion" class="form-select form-select-sm mb-2">
            <option value="firma">Firma digital</option>
            <option value="whatsapp">WhatsApp</option>
            <option value="llamada">Llamada</option>
          </select>
          <button type="submit" class="btn btn-sm btn-outline-success w-100">✅ Marcar presupuesto aprobado</button>
        </form>
        <?php elseif ($ot['presupuesto_aprobado']): ?>
        <div class="text-success small mt-1">✅ Presupuesto aprobado <?= $ot['aprobado_por'] ? '('.$ot['aprobado_por'].')' : '' ?></div>
        <?php endif; ?>

        <!-- Registrar pago -->
        <?php if (!$ot['pagado'] && $ot['presupuesto_aprobado']): ?>
        <form method="POST" class="mt-2">
          <input type="hidden" name="action" value="registrar_pago"/>
          <select name="metodo_pago" class="form-select form-select-sm mb-2">
            <option value="efectivo">Efectivo</option>
            <option value="yape">Yape</option>
            <option value="plin">Plin</option>
            <option value="tarjeta">Tarjeta</option>
            <option value="transferencia">Transferencia</option>
          </select>
          <button type="submit" class="btn btn-sm btn-primary w-100">💰 Registrar pago</button>
        </form>
        <?php endif; ?>
      </div>
    </div>

    <!-- Cambiar estado -->
    <?php if (!(isset($eInfo['es_final']) && $eInfo['es_final'])): ?>
    <div class="tr-card mb-3">
      <div class="tr-card-header"><h6 class="mb-0 small fw-semibold">CAMBIAR ESTADO</h6></div>
      <div class="tr-card-body">
        <form method="POST">
          <input type="hidden" name="action" value="cambiar_estado"/>
          <div class="mb-2">
            <select name="nuevo_estado" class="form-select form-select-sm">
              <?php foreach (ESTADOS_OT as $k => $v): if ($k === $estado) continue; ?>
              <option value="<?= $k ?>"><?= $v['label'] ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2">
            <textarea name="comentario" class="form-control form-control-sm" rows="2" placeholder="Comentario opcional..."></textarea>
          </div>
          <button type="submit" class="btn btn-primary btn-sm w-100">Actualizar estado</button>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <!-- Técnico y fechas -->
    <div class="tr-card mb-3">
      <div class="tr-card-header"><h6 class="mb-0 small fw-semibold">ASIGNACIÓN</h6></div>
      <div class="tr-card-body">
        <div class="small mb-1"><strong>Técnico:</strong> <?= sanitize($ot['tecnico_nombre'] ?? 'Sin asignar') ?></div>
        <div class="small mb-1"><strong>Creado por:</strong> <?= sanitize($ot['creador_nombre']) ?></div>
        <div class="small mb-1"><strong>F. ingreso:</strong> <?= formatDateTime($ot['fecha_ingreso']) ?></div>
        <div class="small mb-1"><strong>F. estimada:</strong>
          <?php if ($ot['fecha_estimada']): ?>
            <span class="<?= $ot['fecha_estimada'] < date('Y-m-d') && !(isset($eInfo['es_final']) && $eInfo['es_final']) ? 'text-danger fw-semibold' : '' ?>">
              <?= formatDate($ot['fecha_estimada']) ?>
            </span>
          <?php else: ?> — <?php endif; ?>
        </div>
        <?php if ($ot['fecha_entrega']): ?>
        <div class="small mb-1"><strong>F. entrega:</strong> <?= formatDateTime($ot['fecha_entrega']) ?></div>
        <?php endif; ?>
        <div class="small"><strong>Garantía:</strong> <?= $ot['garantia_dias'] ?> días</div>
      </div>
    </div>

    <!-- Firma -->
    <?php if ($ot['firma_cliente']): ?>
    <div class="tr-card">
      <div class="tr-card-header"><h6 class="mb-0 small fw-semibold">FIRMA DEL CLIENTE</h6></div>
      <div class="tr-card-body firma-preview">
        <img src="<?= $ot['firma_cliente'] ?>" alt="Firma" class="img-fluid"/>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
