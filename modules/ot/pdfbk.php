<?php
/**
 * pdf.php — Comprobante de Orden de Trabajo (imprimible / PDF)
 * Usar: modules/ot/pdf.php?id=123
 * Genera HTML optimizado para imprimir / Ctrl+P / "Guardar como PDF"
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';
requireLogin();

$db = getDB();
$id = (int)($_GET['id'] ?? 0);

$ot = $db->prepare("
    SELECT ot.*,
           c.nombre      AS cliente_nombre,
           c.ruc_dni     AS cliente_dni,
           c.telefono    AS cliente_tel,
           c.whatsapp    AS cliente_wa,
           c.email       AS cliente_email,
           c.direccion   AS cliente_dir,
           te.nombre     AS tipo_equipo,
           e.marca, e.modelo, e.serial, e.color, e.descripcion AS equipo_desc,
           CONCAT(u.nombre,' ',u.apellido) AS tecnico_nombre,
           CONCAT(uc.nombre,' ',uc.apellido) AS creador_nombre,
           s.nombre AS servicio_nombre
    FROM ordenes_trabajo ot
    JOIN clientes     c  ON c.id  = ot.cliente_id
    JOIN equipos      e  ON e.id  = ot.equipo_id
    JOIN tipos_equipo te ON te.id = e.tipo_equipo_id
    LEFT JOIN usuarios u  ON u.id = ot.tecnico_id
    LEFT JOIN usuarios uc ON uc.id= ot.usuario_creador_id
    LEFT JOIN servicios s ON s.id = ot.servicio_id
    WHERE ot.id = ?
");
$ot->execute([$id]);
$ot = $ot->fetch();
if (!$ot) die('OT no encontrada.');

// Repuestos
$repuestos = $db->prepare("SELECT * FROM ot_repuestos WHERE ot_id=? ORDER BY id");
$repuestos->execute([$id]);
$repuestos = $repuestos->fetchAll();

// Checklist
$checklist = $ot['checklist'] ? json_decode($ot['checklist'], true) : [];

// Config empresa
$cfg = [];
$rows = $db->query("SELECT clave,valor FROM configuracion")->fetchAll();
foreach ($rows as $r) $cfg[$r['clave']] = $r['valor'];
$empresa    = $cfg['empresa_nombre']    ?? APP_NAME;
$empresaRuc = $cfg['empresa_ruc']       ?? '';
$empresaTel = $cfg['empresa_telefono']  ?? '';
$empresaDir = $cfg['empresa_direccion'] ?? '';
$empresaEmail=$cfg['empresa_email']     ?? '';
$moneda     = $cfg['moneda_simbolo']    ?? 'S/';

// Plantilla de impresión
$printLogo        = $cfg['print_logo']              ?? '';
$printMostrarLogo = ($cfg['print_mostrar_logo']     ?? '1') === '1';
$printMostrarQR   = ($cfg['print_mostrar_qr']       ?? '1') === '1';
$printCabecera    = ($cfg['print_mostrar_cabecera'] ?? '1')==='1' ? ($cfg['print_cabecera'] ?? '') : '';
$printCuentas     = $cfg['print_cuentas']            ?? '';
$printInferior    = ($cfg['print_mostrar_inferior']  ?? '1')==='1' ? ($cfg['print_msg_inferior'] ?? '') : '';
$printDespedida   = ($cfg['print_mostrar_despedida'] ?? '1')==='1' ? strtoupper($cfg['print_despedida'] ?? '¡GRACIAS POR SU PREFERENCIA!') : '';
$printLogoUrl     = $printLogo ? UPLOAD_URL . $printLogo : '';

// Labels checklist
$checkLabels = [
    'Pantalla sin daños'    => 'Pantalla',
    'Carcasa / chasis'      => 'Carcasa',
    'Teclado funcional'     => 'Teclado',
    'Touchpad / mouse'      => 'Touchpad',
    'Puertos y conexiones'  => 'Puertos',
    'Batería incluida'      => 'Batería',
    'Cargador incluido'     => 'Cargador',
    'Accesorios adicionales'=> 'Accesorios',
    'Cliente respalda datos'=> 'Datos resp.',
];

$estadoLabels = [
    'ingresado'     => 'INGRESADO',
    'en_revision'   => 'EN REVISIÓN',
    'en_reparacion' => 'EN REPARACIÓN',
    'listo'         => 'LISTO',
    'entregado'     => 'ENTREGADO',
    'cancelado'     => 'CANCELADO',
];
$estadoLabel = $estadoLabels[$ot['estado']] ?? strtoupper($ot['estado']);

// Nota legal
$notaLegal = "El equipo deberá ser recogido en un plazo máximo de 90 días luego de emitido el diagnóstico y/o reparación. De otro modo, según lo estipulado en el código civil Art. 1333 y 1123, será considerada mercadería en abandono, liberando a {$empresa} de su pérdida o deterioro.";
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width"/>
<title>Servicio # <?= htmlspecialchars($ot['codigo_ot']) ?></title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');

  * { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: 'Inter', Arial, sans-serif;
    font-size: 12px;
    color: #1a1a1a;
    background: #f0f0f0;
    padding: 20px;
  }

  .page {
    background: #fff;
    width: 210mm;
    min-height: 297mm;
    margin: 0 auto;
    padding: 14mm 14mm 12mm;
    box-shadow: 0 2px 20px rgba(0,0,0,.12);
    position: relative;
  }

  /* ── HEADER ── */
  .doc-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    padding-bottom: 10px;
    border-bottom: 3px solid #1a1a2e;
    margin-bottom: 10px;
  }
  .empresa-info h2 {
    font-size: 20px; font-weight: 800;
    color: #1a1a2e; margin-bottom: 3px;
  }
  .empresa-info p { font-size: 10px; color: #555; line-height: 1.5; }
  .doc-title-block { text-align: right; }
  .doc-title-block .servicio-num {
    font-size: 18px; font-weight: 800;
    color: #1a1a2e;
  }
  .doc-title-block .servicio-label {
    font-size: 10px; color: #888; text-transform: uppercase; letter-spacing: .05em;
  }
  .estado-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 4px;
    font-size: 10px; font-weight: 700;
    letter-spacing: .06em;
    margin-top: 4px;
  }
  .estado-ingresado     { background:#f3f4f6; color:#374151; }
  .estado-en_revision   { background:#dbeafe; color:#1d4ed8; }
  .estado-en_reparacion { background:#fef3c7; color:#b45309; }
  .estado-listo         { background:#dcfce7; color:#15803d; }
  .estado-entregado     { background:#ede9fe; color:#6d28d9; }
  .estado-cancelado     { background:#fee2e2; color:#dc2626; }

  /* ── SECTION TITLE ── */
  .sec-title {
    background: #1a1a2e;
    color: #fff;
    font-size: 10px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .08em;
    padding: 5px 10px;
    margin-bottom: 0;
  }
  .sec-title.light {
    background: #f3f4f6; color: #374151;
  }

  /* ── TABLE ── */
  table { width: 100%; border-collapse: collapse; }
  table td, table th {
    border: 1px solid #d1d5db;
    padding: 5px 8px;
    vertical-align: top;
  }
  table th {
    background: #1a1a2e; color: #fff;
    font-size: 10px; font-weight: 600;
    text-transform: uppercase; letter-spacing: .05em;
  }
  table.light th { background: #f3f4f6; color: #374151; }
  table tr:nth-child(even) td { background: #f9fafb; }

  /* ── INFO ROW ── */
  .info-row {
    display: flex; gap: 0;
    border: 1px solid #d1d5db;
    margin-bottom: 0;
  }
  .info-row + .info-row { border-top: none; }
  .info-cell {
    flex: 1; padding: 5px 8px;
    border-right: 1px solid #d1d5db;
  }
  .info-cell:last-child { border-right: none; }
  .info-cell .lbl {
    font-size: 9px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .05em;
    color: #6b7280; margin-bottom: 2px;
  }
  .info-cell .val { font-size: 12px; font-weight: 600; }

  /* ── CHECKLIST ── */
  .checklist-grid {
    display: flex; flex-wrap: wrap;
    border: 1px solid #d1d5db;
    border-top: none;
  }
  .chk-item {
    width: 25%;
    border-right: 1px solid #d1d5db;
    border-bottom: 1px solid #d1d5db;
    padding: 5px 8px;
  }
  .chk-item:nth-child(4n) { border-right: none; }
  .chk-item .chk-lbl { font-size: 9px; color: #6b7280; font-weight: 600; text-transform: uppercase; }
  .chk-item .chk-val {
    font-size: 11px; font-weight: 700; margin-top: 1px;
  }
  .chk-bueno  { color: #16a34a; }
  .chk-malo   { color: #dc2626; }
  .chk-na     { color: #9ca3af; }

  /* ── OBSERVACIONES / DIAGNÓSTICO ── */
  .text-block {
    border: 1px solid #d1d5db;
    border-top: none;
    padding: 8px 10px;
    min-height: 36px;
    font-size: 11.5px;
    line-height: 1.6;
    color: #374151;
  }

  /* ── SERVICIOS TABLE ── */
  .services-table th:first-child { width: 30px; text-align: center; }
  .services-table td:first-child { text-align: center; font-weight: 600; }
  .services-table .price-col { width: 80px; text-align: right; }

  /* ── TOTALES ── */
  .totales-wrap {
    display: flex;
    justify-content: flex-end;
    margin-top: 0;
  }
  .totales-table {
    width: 220px;
    border: 1px solid #d1d5db;
    border-top: none;
  }
  .totales-table td {
    padding: 4px 10px;
    border: none;
    border-bottom: 1px solid #e5e7eb;
  }
  .totales-table .total-final td {
    background: #1a1a2e; color: #fff;
    font-weight: 800; font-size: 14px;
    border-bottom: none;
  }
  .totales-table .label-col { color: #6b7280; font-size: 10px; text-transform: uppercase; }
  .totales-table .value-col { text-align: right; font-weight: 700; }

  /* ── FIRMA ── */
  .firma-section {
    display: flex;
    gap: 20px;
    margin-top: 10px;
  }
  .firma-box {
    flex: 1;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    padding: 10px;
    text-align: center;
    min-height: 70px;
    position: relative;
  }
  .firma-box .firma-label {
    font-size: 9px; font-weight: 700;
    text-transform: uppercase; color: #6b7280;
    letter-spacing: .06em;
  }
  .firma-box img {
    max-height: 50px; max-width: 100%;
    margin: 4px auto; display: block;
  }
  .firma-box .firma-linea {
    border-top: 1px solid #d1d5db;
    margin: 8px 0 4px;
  }
  .firma-box .firma-nombre { font-size: 10px; font-weight: 600; }

  /* ── CÓDIGO QR / CONSULTA ── */
  .codigo-consulta {
    background: #f3f4f6;
    border: 1px dashed #9ca3af;
    border-radius: 6px;
    padding: 6px 12px;
    text-align: center;
    margin-top: 8px;
    display: inline-block;
  }
  .codigo-consulta .cc-label { font-size: 9px; color: #6b7280; font-weight: 600; text-transform: uppercase; }
  .codigo-consulta .cc-value { font-size: 16px; font-weight: 800; letter-spacing: .12em; color: #1a1a2e; }
  .codigo-consulta .cc-url   { font-size: 9px; color: #6b7280; }

  /* ── NOTA LEGAL ── */
  .nota-legal {
    margin-top: 12px;
    padding: 8px 10px;
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 4px;
    font-size: 9px;
    color: #6b7280;
    line-height: 1.6;
  }
  .nota-legal strong { color: #374151; }

  /* ── WATERMARK ESTADO ── */
  .watermark {
    position: absolute;
    top: 50%; left: 50%;
    transform: translate(-50%,-50%) rotate(-35deg);
    font-size: 80px; font-weight: 900;
    color: rgba(0,0,0,.035);
    letter-spacing: .1em;
    pointer-events: none;
    white-space: nowrap;
    z-index: 0;
  }

  /* ── SEPARADOR ── */
  .mb8  { margin-bottom: 8px; }
  .mt8  { margin-top: 8px; }
  .mt12 { margin-top: 12px; }

  /* ── PRINT ── */
<?php if($printInferior || $printCuentas): ?>
  <div style="margin-top:16px;padding:10px 14px;background:#f9fafb;border-radius:6px;border:1px solid #e5e7eb;font-size:10px;color:#555">
    <?php if($printInferior): ?><div style="margin-bottom:6px"><?= htmlspecialchars($printInferior) ?></div><?php endif; ?>
    <?php if($printCuentas): ?>
    <div style="white-space:pre-line;font-size:9.5px">
      <strong>Cuentas bancarias:</strong><br><?= htmlspecialchars($printCuentas) ?>
    </div>
    <?php endif; ?>
  </div>
<?php endif; ?>
  @media print {
    body { background: #fff; padding: 0; }
    .page { box-shadow: none; padding: 10mm; width: 100%; }
    .no-print { display: none !important; }
  }
</style>
</head>
<body>

<!-- Botón imprimir (no se imprime) -->
<div class="no-print" style="max-width:210mm;margin:0 auto 12px;display:flex;gap:10px">
  <button onclick="window.print()" style="background:#1a1a2e;color:#fff;border:none;border-radius:8px;padding:10px 20px;font-size:14px;font-weight:700;cursor:pointer">
    🖨️ Imprimir / Guardar PDF
  </button>
  <button onclick="window.close()" style="background:#f3f4f6;border:none;border-radius:8px;padding:10px 16px;font-size:14px;cursor:pointer">
    ← Volver
  </button>
</div>

<div class="page">
  <div class="watermark"><?= $estadoLabel ?></div>

  <!-- ══ HEADER ══ -->
  <div class="doc-header">
    <div class="empresa-info">
      <?php if($printMostrarLogo && $printLogoUrl): ?>
      <img src="<?= $printLogoUrl ?>" style="max-height:56px;max-width:180px;object-fit:contain;margin-bottom:6px;display:block"/>
      <?php endif; ?>
      <h2><?= htmlspecialchars($empresa) ?></h2>
      <?php if($empresaRuc): ?><p><strong>R.U.C.:</strong> <?= htmlspecialchars($empresaRuc) ?></p><?php endif; ?>
      <?php if($empresaDir): ?><p><?= htmlspecialchars($empresaDir) ?></p><?php endif; ?>
      <?php if($empresaTel): ?><p>Tel: <?= htmlspecialchars($empresaTel) ?></p><?php endif; ?>
      <?php if($empresaEmail): ?><p><?= htmlspecialchars($empresaEmail) ?></p><?php endif; ?>
      <?php if($printCabecera): ?><div style="margin-top:6px;font-size:10px;color:#444;padding:5px;background:#f9fafb;border-radius:3px"><?= $printCabecera ?></div><?php endif; ?>
    </div>
    <div class="doc-title-block">
      <div class="servicio-label">Orden de Servicio</div>
      <div class="servicio-num"># <?= htmlspecialchars($ot['codigo_ot']) ?></div>
      <div>
        <span class="estado-badge estado-<?= $ot['estado'] ?>"><?= $estadoLabel ?></span>
      </div>
      <div style="font-size:10px;color:#888;margin-top:4px">
        <?= date('d/m/Y H:i', strtotime($ot['fecha_ingreso'])) ?>
      </div>
    </div>
  </div>

  <!-- ══ CLIENTE ══ -->
  <div class="sec-title">Datos del cliente</div>
  <div class="info-row">
    <div class="info-cell" style="flex:0.7">
      <div class="lbl">R.U.C. / DNI</div>
      <div class="val"><?= htmlspecialchars($ot['cliente_dni'] ?: '—') ?></div>
    </div>
    <div class="info-cell" style="flex:2">
      <div class="lbl">Cliente</div>
      <div class="val"><?= htmlspecialchars($ot['cliente_nombre']) ?></div>
    </div>
    <div class="info-cell">
      <div class="lbl">Teléfono</div>
      <div class="val"><?= htmlspecialchars($ot['cliente_tel'] ?: ($ot['cliente_wa'] ?: '—')) ?></div>
    </div>
  </div>
  <?php if($ot['cliente_dir'] || $ot['cliente_email']): ?>
  <div class="info-row">
    <?php if($ot['cliente_dir']): ?>
    <div class="info-cell" style="flex:2">
      <div class="lbl">Dirección</div>
      <div class="val"><?= htmlspecialchars($ot['cliente_dir']) ?></div>
    </div>
    <?php endif; ?>
    <?php if($ot['cliente_email']): ?>
    <div class="info-cell">
      <div class="lbl">Email</div>
      <div class="val"><?= htmlspecialchars($ot['cliente_email']) ?></div>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- ══ EQUIPO ══ -->
  <div class="sec-title mt8">Equipo / Dispositivo</div>
  <div class="info-row">
    <div class="info-cell">
      <div class="lbl">Tipo</div>
      <div class="val"><?= htmlspecialchars($ot['tipo_equipo']) ?></div>
    </div>
    <div class="info-cell">
      <div class="lbl">Marca / Modelo</div>
      <div class="val"><?= htmlspecialchars(trim(($ot['marca']??'').' '.($ot['modelo']??''))) ?: '—' ?></div>
    </div>
    <div class="info-cell">
      <div class="lbl">Serial / N° Serie</div>
      <div class="val" style="font-family:monospace"><?= htmlspecialchars($ot['serial'] ?: '—') ?></div>
    </div>
    <div class="info-cell">
      <div class="lbl">Color</div>
      <div class="val"><?= htmlspecialchars($ot['color'] ?: '—') ?></div>
    </div>
    <?php if($ot['servicio_nombre']): ?>
    <div class="info-cell">
      <div class="lbl">Servicio</div>
      <div class="val"><?= htmlspecialchars($ot['servicio_nombre']) ?></div>
    </div>
    <?php endif; ?>
  </div>
  <?php if($ot['equipo_desc']): ?>
  <div class="info-row">
    <div class="info-cell">
      <div class="lbl">Descripción / Accesorios entregados</div>
      <div class="val"><?= htmlspecialchars($ot['equipo_desc']) ?></div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ══ CHECKLIST FÍSICO ══ -->
  <?php if(!empty($checklist)): ?>
  <div class="sec-title mt8">Estado físico del equipo (al ingreso)</div>
  <div class="checklist-grid">
    <?php
    $obs = '';
    foreach ($checklist as $nombre => $val):
      if ($nombre === '_observacion') { $obs = $val; continue; }
      if ($nombre === 'observacion')  { $obs = $val; continue; }
      $shortName = $checkLabels[$nombre] ?? $nombre;
      $clase = $val==='bueno' ? 'chk-bueno' : ($val==='malo' ? 'chk-malo' : 'chk-na');
      $txt   = $val==='bueno' ? '✔ Bueno' : ($val==='malo' ? '✘ Malo' : '— N/A');
    ?>
    <div class="chk-item">
      <div class="chk-lbl"><?= htmlspecialchars($shortName) ?></div>
      <div class="chk-val <?= $clase ?>"><?= $txt ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php if($obs): ?>
  <div class="text-block" style="border-top:1px solid #d1d5db;font-size:10.5px;color:#555">
    <strong>Obs. checklist:</strong> <?= htmlspecialchars($obs) ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <!-- ══ PROBLEMA REPORTADO ══ -->
  <div class="sec-title mt8">Problema reportado por el cliente</div>
  <div class="text-block">
    <?= nl2br(htmlspecialchars($ot['problema_reportado'])) ?>
  </div>

  <!-- ══ DIAGNÓSTICO ══ -->
  <?php if($ot['diagnostico_inicial'] || $ot['diagnostico_tecnico']): ?>
  <div class="sec-title mt8">Diagnóstico técnico</div>
  <div class="text-block">
    <?php if($ot['diagnostico_tecnico']): ?>
      <?= nl2br(htmlspecialchars($ot['diagnostico_tecnico'])) ?>
    <?php elseif($ot['diagnostico_inicial']): ?>
      <?= nl2br(htmlspecialchars($ot['diagnostico_inicial'])) ?>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- ══ OBSERVACIONES ══ -->
  <?php if($ot['observaciones']): ?>
  <div class="sec-title mt8">Observaciones</div>
  <div class="text-block"><?= nl2br(htmlspecialchars($ot['observaciones'])) ?></div>
  <?php endif; ?>

  <!-- ══ SERVICIOS / REPUESTOS ══ -->
  <div class="sec-title mt8">Servicios y repuestos</div>
  <table class="services-table">
    <thead>
      <tr>
        <th style="width:28px;text-align:center">#</th>
        <th>Descripción del servicio / repuesto</th>
        <th class="price-col">Cant.</th>
        <th class="price-col">P. Unit.</th>
        <th class="price-col">Costo</th>
      </tr>
    </thead>
    <tbody>
      <?php if(!empty($repuestos)): ?>
        <?php foreach($repuestos as $i => $r): ?>
        <tr>
          <td style="text-align:center"><?= $i+1 ?></td>
          <td><?= htmlspecialchars($r['descripcion']) ?></td>
          <td style="text-align:right"><?= number_format($r['cantidad'],2) ?></td>
          <td style="text-align:right"><?= $moneda ?> <?= number_format($r['precio_unit'],2) ?></td>
          <td style="text-align:right;font-weight:700"><?= $moneda ?> <?= number_format($r['subtotal'],2) ?></td>
        </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr>
          <td style="text-align:center">1</td>
          <td>
            <?php
            $desc = '';
            if ($ot['diagnostico_tecnico'])  $desc = $ot['diagnostico_tecnico'];
            elseif ($ot['diagnostico_inicial']) $desc = $ot['diagnostico_inicial'];
            else $desc = $ot['problema_reportado'];
            echo htmlspecialchars(substr($desc, 0, 120));
            ?>
          </td>
          <td style="text-align:right">1</td>
          <td style="text-align:right"><?= $moneda ?> <?= number_format($ot['costo_mano_obra'],2) ?></td>
          <td style="text-align:right;font-weight:700"><?= $moneda ?> <?= number_format($ot['costo_mano_obra'],2) ?></td>
        </tr>
      <?php endif; ?>
      <!-- Fila vacía de relleno -->
      <tr><td>&nbsp;</td><td></td><td></td><td></td><td></td></tr>
    </tbody>
  </table>

  <!-- Totales -->
  <div class="totales-wrap">
    <table class="totales-table">
      <?php if($ot['costo_repuestos'] > 0): ?>
      <tr>
        <td class="label-col">Repuestos:</td>
        <td class="value-col"><?= $moneda ?> <?= number_format($ot['costo_repuestos'],2) ?></td>
      </tr>
      <tr>
        <td class="label-col">Mano de obra:</td>
        <td class="value-col"><?= $moneda ?> <?= number_format($ot['costo_mano_obra'],2) ?></td>
      </tr>
      <?php endif; ?>
      <?php if($ot['descuento'] > 0): ?>
      <tr>
        <td class="label-col" style="color:#dc2626">Descuento:</td>
        <td class="value-col" style="color:#dc2626">-<?= $moneda ?> <?= number_format($ot['descuento'],2) ?></td>
      </tr>
      <?php endif; ?>
      <tr>
        <td class="label-col">Total Abonado:</td>
        <td class="value-col"><?= $moneda ?> <?= $ot['pagado'] ? number_format($ot['precio_final'],2) : '0.00' ?></td>
      </tr>
      <tr class="total-final">
        <td>Total a Pagar:</td>
        <td style="text-align:right"><?= $moneda ?> <?= number_format($ot['precio_final'],2) ?></td>
      </tr>
    </table>
  </div>

  <!-- ══ ESTADO + FECHA ESTIMADA ══ -->
  <div style="margin-top:10px;display:flex;gap:10px;align-items:flex-start">
    <div style="flex:1">
      <div style="font-size:10px;color:#6b7280;font-weight:700;text-transform:uppercase;margin-bottom:3px">Estado</div>
      <div style="font-size:12px;font-weight:700"><?= $estadoLabel ?></div>
      <?php if($ot['fecha_estimada']): ?>
      <div style="font-size:10px;color:#6b7280;margin-top:4px">
        Fecha estimada de entrega: <strong><?= date('d/m/Y', strtotime($ot['fecha_estimada'])) ?></strong>
      </div>
      <?php endif; ?>
      <?php if($ot['tecnico_nombre']): ?>
      <div style="font-size:10px;color:#6b7280;margin-top:2px">
        Técnico: <strong><?= htmlspecialchars($ot['tecnico_nombre']) ?></strong>
      </div>
      <?php endif; ?>
    </div>
    <div style="text-align:center">
      <div class="codigo-consulta">
        <div class="cc-label">Código de consulta en línea</div>
        <div class="cc-value"><?= htmlspecialchars($ot['codigo_publico']) ?></div>
        <div class="cc-url"><?= htmlspecialchars(rtrim(BASE_URL,'/'))?>/public/estado.php</div>
      </div>
    </div>
  </div>

  <!-- ══ FIRMAS ══ -->
  <div class="firma-section mt12">
    <div class="firma-box">
      <div class="firma-label">Firma del cliente</div>
      <?php if($ot['firma_cliente']): ?>
        <img src="<?= $ot['firma_cliente'] ?>" alt="Firma cliente"/>
      <?php else: ?>
        <div style="height:40px"></div>
      <?php endif; ?>
      <div class="firma-linea"></div>
      <div class="firma-nombre"><?= htmlspecialchars($ot['cliente_nombre']) ?></div>
      <div style="font-size:9px;color:#9ca3af">DNI: <?= htmlspecialchars($ot['cliente_dni'] ?: '—') ?></div>
    </div>
    <div class="firma-box">
      <div class="firma-label">Recibido por / Técnico</div>
      <div style="height:40px"></div>
      <div class="firma-linea"></div>
      <div class="firma-nombre"><?= htmlspecialchars($ot['tecnico_nombre'] ?: $ot['creador_nombre']) ?></div>
      <div style="font-size:9px;color:#9ca3af"><?= htmlspecialchars($empresa) ?></div>
    </div>
    <div class="firma-box">
      <div class="firma-label">Entrega del equipo</div>
      <div style="height:40px"></div>
      <div class="firma-linea"></div>
      <div class="firma-nombre">______________________</div>
      <div style="font-size:9px;color:#9ca3af">Fecha: ________________</div>
    </div>
  </div>

  <!-- ══ NOTA LEGAL ══ -->
  <div class="nota-legal mt8">
    <strong>NOTA:</strong> <?= htmlspecialchars($notaLegal) ?>
    <?php if($ot['garantia_dias'] > 0): ?>
    <strong>Garantía de reparación: <?= $ot['garantia_dias'] ?> días</strong> a partir de la fecha de entrega.
    <?php endif; ?>
  </div>

</div><!-- /page -->

<script>
// Auto-imprimir si viene con ?print=1
if (new URLSearchParams(window.location.search).get('print') === '1') {
  window.addEventListener('load', () => setTimeout(() => window.print(), 600));
}
</script>
</body>
</html>
