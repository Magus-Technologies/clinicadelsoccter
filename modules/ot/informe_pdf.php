<?php
/**
 * informe_pdf.php — Informe Técnico de Mantenimiento (imprimible / PDF)
 * Uso: modules/ot/informe_pdf.php?ot_id=123
 * Genera HTML optimizado para imprimir (Ctrl+P → Guardar como PDF), igual que pdf.php.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';
requireLogin();

$db  = getDB();
$id  = (int)($_GET['ot_id'] ?? $_GET['id'] ?? 0);

// ── Config de empresa (proveedor) ────────────────────────────
$cfg = [];
foreach ($db->query("SELECT clave,valor FROM configuracion")->fetchAll() as $r) $cfg[$r['clave']] = $r['valor'];
$provNombre = $cfg['empresa_nombre']        ?? 'La Clínica del Scooter';
$provRazon  = $cfg['empresa_razon_social']  ?? '';
$provRuc    = $cfg['empresa_ruc']           ?? '';
$simb       = $cfg['moneda_simbolo']        ?? 'S/';
$logoUrl    = !empty($cfg['print_logo']) ? UPLOAD_URL . $cfg['print_logo'] : (!empty($cfg['empresa_logo']) ? UPLOAD_URL . $cfg['empresa_logo'] : '');

// ── OT + receptor (cliente) + scooter (equipo) ───────────────
$ot = $db->prepare("
    SELECT ot.*, c.nombre AS cli_nombre, c.razon_social AS cli_razon, c.ruc_dni AS cli_ruc, c.num_doc AS cli_num,
           e.marca, e.modelo, te.nombre AS tipo_equipo
    FROM ordenes_trabajo ot
    JOIN clientes c ON c.id = ot.cliente_id
    JOIN equipos e ON e.id = ot.equipo_id
    JOIN tipos_equipo te ON te.id = e.tipo_equipo_id
    WHERE ot.id = ?");
$ot->execute([$id]);
$ot = $ot->fetch();
if (!$ot) die('OT no encontrada.');

// ── Informe (cabecera manual) ────────────────────────────────
$inf = $db->prepare("SELECT * FROM informes_tecnicos WHERE ot_id=?");
$inf->execute([$id]);
$inf = $inf->fetch() ?: [];

// ── Proforma: editable (proforma_json) o, si no hay, de la OT ─
$prof = [];
if (!empty($inf['proforma_json'])) {
    $prof = json_decode($inf['proforma_json'], true) ?: [];
}
if (!$prof) {
    $rp = $db->prepare("SELECT descripcion, subtotal FROM ot_repuestos WHERE ot_id=? ORDER BY id");
    $rp->execute([$id]);
    foreach ($rp->fetchAll() as $r) $prof[] = ['desc'=>$r['descripcion'],'importe'=>(float)$r['subtotal']];
    if ((float)$ot['costo_mano_obra'] > 0) $prof[] = ['desc'=>'Mano de obra','importe'=>(float)$ot['costo_mano_obra']];
}
$totalProf = 0; foreach ($prof as $p) $totalProf += (float)$p['importe'];

// ── Fotos del Anexo ──────────────────────────────────────────
$fotos = [];
try {
    $f = $db->prepare("SELECT ruta, etiqueta, descripcion FROM informe_fotos WHERE informe_id=? ORDER BY orden, id");
    $f->execute([(int)($inf['id'] ?? 0)]);
    $fotos = $f->fetchAll();
} catch (\Throwable $e) { $fotos = []; }

// Helpers de render
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
// Convierte texto multilínea en <li>. Si la línea tiene "Título: detalle", pone el título en negrita.
function bullets(?string $txt): string {
    $out = '';
    foreach (preg_split('/\r\n|\r|\n/', (string)$txt) as $ln) {
        $ln = trim($ln);
        if ($ln === '') continue;
        if (strpos($ln, ':') !== false && strlen(explode(':', $ln, 2)[0]) <= 60) {
            [$t, $d] = explode(':', $ln, 2);
            $out .= '<li><b>' . e(trim($t)) . ':</b> ' . e(trim($d)) . '</li>';
        } else {
            $out .= '<li>' . e($ln) . '</li>';
        }
    }
    return $out;
}
$receptorLinea = e($ot['cli_nombre']);
$recExtra = [];
if (!empty($ot['cli_razon'])) $recExtra[] = e($ot['cli_razon']);
$recDoc = $ot['cli_ruc'] ?: $ot['cli_num'];
if (!empty($recDoc)) $recExtra[] = 'RUC ' . e($recDoc);
$provExtra = [];
if ($provRazon !== '') $provExtra[] = e($provRazon);
if ($provRuc  !== '') $provExtra[] = 'RUC ' . e($provRuc);

$totalFotos = count($fotos);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"/>
<title>Informe Técnico · <?= e($ot['codigo_ot']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  :root{ --brand:#e8202f; --brand-soft:#fdECEE; --ink:#1a1a1a; --muted:#666; --line:#e3e3e3; }
  *{box-sizing:border-box;margin:0;padding:0}
  body{background:#e9ecef;font-family:'Inter',Arial,sans-serif;color:var(--ink);font-size:12px;line-height:1.5}
  .page{width:210mm;min-height:297mm;background:#fff;margin:0 auto 18px;padding:16mm 15mm;box-shadow:0 4px 20px rgba(0,0,0,.12);position:relative}
  .hdr{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
  .hdr img.logo{height:52px;max-width:170px;object-fit:contain}
  .hdr .logo-ph{width:52px;height:52px;border-radius:50%;background:#1a1a1a;display:flex;align-items:center;justify-content:center;color:#fff;font-size:8px;font-weight:700}
  .hdr .empresa{font-weight:700;font-size:12px}
  .doc-title{text-align:center;font-size:20px;font-weight:800;letter-spacing:.02em;margin:14px 0 4px}
  .doc-rule{height:3px;background:var(--brand);border-radius:2px;margin-bottom:20px}
  h2.sec{color:var(--brand);font-size:14px;font-weight:800;margin:18px 0 8px;padding-bottom:5px;border-bottom:1px solid #eee}
  h2.sec:first-of-type{margin-top:0}
  p{margin-bottom:8px}
  .kv{margin-bottom:5px}
  .kv b{font-weight:700}
  .muted{color:var(--muted)}
  a{color:var(--brand);text-decoration:none}
  ul.bullets{list-style:none;margin:6px 0}
  ul.bullets li{position:relative;padding-left:16px;margin-bottom:8px;text-align:justify}
  ul.bullets li::before{content:'';position:absolute;left:2px;top:6px;width:5px;height:5px;border-radius:50%;background:var(--brand)}
  table.tbl{width:100%;border-collapse:collapse;margin:8px 0}
  table.tbl th{background:var(--brand);color:#fff;text-align:left;padding:9px 12px;font-size:11px;font-weight:700}
  table.tbl th.r,table.tbl td.r{text-align:right}
  table.tbl td{padding:9px 12px;border-bottom:1px solid var(--line);font-size:11.5px}
  table.tbl tr:nth-child(even) td{background:#faf9f9}
  table.tbl tr.total td{background:var(--brand-soft);font-weight:800;border-bottom:none;border-top:2px solid var(--brand)}
  .anexo-intro{color:var(--muted);margin-bottom:12px}
  .grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
  .shot{border:1px solid var(--line);border-radius:8px;overflow:hidden;page-break-inside:avoid}
  .shot .img{aspect-ratio:1;background:#eef1f4;display:flex;align-items:center;justify-content:center;color:#9aa7b5;font-size:11px}
  .shot .img img{width:100%;height:100%;object-fit:cover;display:block}
  .shot .cap{padding:8px 10px;font-size:10.5px;color:#333;border-top:1px solid var(--line);min-height:34px}
  .shot .cap b{color:var(--brand);font-weight:700;display:block;font-size:9px;text-transform:uppercase;letter-spacing:.04em;margin-bottom:2px}
  .foot{position:absolute;left:15mm;right:15mm;bottom:10mm;display:flex;justify-content:space-between;font-size:9px;color:#999;border-top:1px solid #eee;padding-top:6px}
  .foot .b{color:var(--brand);font-weight:700}
  .credit a{color:#999}
  .toolbar{position:fixed;top:12px;right:12px;z-index:99}
  .toolbar button{background:var(--brand);color:#fff;border:none;border-radius:8px;padding:9px 16px;font-family:inherit;font-weight:600;cursor:pointer;font-size:13px}
  /* Pie que se repite al imprimir (uno global, en cada hoja). Oculto en pantalla. */
  .print-footer{display:none}
  @media print{
    body{background:#fff}
    .page{box-shadow:none;margin:0;width:auto;min-height:0;padding:0;page-break-after:always}
    .page:last-child{page-break-after:auto}
    .page .foot{display:none}          /* los pies por-página son solo para el preview en pantalla */
    .toolbar{display:none}
    .print-footer{display:flex;position:fixed;left:0;right:0;bottom:0;justify-content:space-between;
      font-size:9px;color:#999;border-top:1px solid #eee;padding-top:5px;background:#fff}
    .print-footer .b{color:var(--brand);font-weight:700}
    @page{size:A4;margin:14mm 15mm 18mm 15mm}
  }
</style>
</head>
<body>
<div class="toolbar"><button onclick="window.print()">🖨 Imprimir / Guardar PDF</button></div>

<!-- PÁGINA 1 -->
<div class="page">
  <div class="hdr">
    <?php if ($logoUrl): ?><img class="logo" src="<?= e($logoUrl) ?>" alt=""/><?php else: ?><div class="logo-ph">LOGO</div><?php endif; ?>
    <div class="empresa"><?= e($provNombre) ?></div>
  </div>
  <div class="doc-title">INFORME TÉCNICO DE MANTENIMIENTO</div>
  <div class="doc-rule"></div>

  <h2 class="sec">1. Datos legales</h2>
  <div class="kv"><b>Proveedor:</b> <?= e($provNombre) ?><?= $provExtra ? ' <span class="muted">(' . implode(' · ', $provExtra) . ')</span>' : '' ?></div>
  <div class="kv"><b>Receptor:</b> <?= $receptorLinea ?><?= $recExtra ? ' <span class="muted">(' . implode(' · ', $recExtra) . ')</span>' : '' ?></div>

  <h2 class="sec">2. Identificación del Scooter</h2>
  <div class="kv"><b>Marca / Modelo:</b> <?= e(trim(($ot['marca'] ?: '') . ' ' . ($ot['modelo'] ?: ''))) ?: '—' ?></div>
  <div class="kv"><b>Código de O.T.:</b> <?= e($ot['codigo_ot']) ?></div>
  <?php if (!empty($inf['custodia_nombre'])): ?>
  <div class="kv"><b>Custodia de:</b> <?= e($inf['custodia_nombre']) ?><?php
     $cx=[]; if(!empty($inf['custodia_email']))$cx[]=e($inf['custodia_email']); if(!empty($inf['custodia_telefono']))$cx[]=e($inf['custodia_telefono']);
     echo $cx ? ' <span class="muted">(' . implode(' · ',$cx) . ')</span>' : ''; ?></div>
  <?php endif; ?>

  <?php if (!empty(trim((string)($inf['alcance'] ?? '')))): ?>
  <h2 class="sec">3. Alcance de los trabajos / Actividades realizadas</h2>
  <ul class="bullets"><?= bullets($inf['alcance']) ?></ul>
  <?php endif; ?>

  <?php if (!empty(trim((string)($inf['hallazgos'] ?? '')))): ?>
  <h2 class="sec">4. Hallazgos y observaciones</h2>
  <ul class="bullets"><?= bullets($inf['hallazgos']) ?></ul>
  <?php endif; ?>

  <div class="foot"><span><span class="b"><?= e($provNombre) ?></span> · Informe Técnico de Mantenimiento</span><span><?= e($ot['codigo_ot']) ?></span></div>
</div>

<!-- PÁGINA 2 -->
<div class="page">
  <div class="hdr"><?php if ($logoUrl): ?><img class="logo" src="<?= e($logoUrl) ?>" alt=""/><?php else: ?><div class="logo-ph">LOGO</div><?php endif; ?><div class="empresa"><?= e($provNombre) ?></div></div>

  <h2 class="sec">5. Proforma final y trabajos facturados</h2>
  <?php if ($prof): ?>
  <table class="tbl">
    <thead><tr><th>Servicio</th><th class="r">Importe</th></tr></thead>
    <tbody>
      <?php foreach ($prof as $p): ?>
      <tr><td><?= e($p['desc']) ?></td><td class="r"><?= $simb ?> <?= number_format((float)$p['importe'],2) ?></td></tr>
      <?php endforeach; ?>
      <tr class="total"><td>Total</td><td class="r"><?= $simb ?> <?= number_format($totalProf,2) ?></td></tr>
    </tbody>
  </table>
  <?php else: ?><p class="muted">Sin ítems de proforma registrados.</p><?php endif; ?>

  <?php if (!empty(trim((string)($inf['condicion_final'] ?? ''))) || !empty(trim((string)($inf['observaciones_pendientes'] ?? '')))): ?>
  <h2 class="sec">6. Condición final del equipo</h2>
  <?php if (!empty(trim((string)($inf['condicion_final'] ?? '')))): ?>
  <p style="text-align:justify"><?= nl2br(e($inf['condicion_final'])) ?></p>
  <?php endif; ?>
  <?php if (!empty(trim((string)($inf['observaciones_pendientes'] ?? '')))): ?>
  <p style="text-align:justify"><b>Observaciones pendientes para el cliente:</b> <?= nl2br(e($inf['observaciones_pendientes'])) ?></p>
  <?php endif; ?>
  <?php endif; ?>

  <div class="foot"><span><span class="b"><?= e($provNombre) ?></span> · Informe Técnico de Mantenimiento</span><span><?= e($ot['codigo_ot']) ?></span></div>
</div>

<!-- ANEXO -->
<?php if ($fotos): ?>
<div class="page">
  <div class="hdr"><?php if ($logoUrl): ?><img class="logo" src="<?= e($logoUrl) ?>" alt=""/><?php else: ?><div class="logo-ph">LOGO</div><?php endif; ?><div class="empresa"><?= e($provNombre) ?></div></div>
  <h2 class="sec">Anexo I — Registro del servicio</h2>
  <p class="anexo-intro">A continuación se presenta el detalle del servicio tal como se comunica en tiempo real vía grupo de trabajo en WhatsApp.</p>
  <div class="grid">
    <?php foreach ($fotos as $ft): ?>
    <div class="shot">
      <div class="img"><img src="<?= e(UPLOAD_URL . $ft['ruta']) ?>" alt="" loading="lazy"/></div>
      <div class="cap"><?php if (!empty($ft['etiqueta'])): ?><b><?= e($ft['etiqueta']) ?></b><?php endif; ?><?= e($ft['descripcion']) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="foot"><span><span class="b"><?= e($provNombre) ?></span> · Informe Técnico de Mantenimiento</span><span class="credit">Diseñado por <a href="https://magustechnologies.com/" target="_blank" rel="noopener">Magus Technologies</a></span></div>
</div>
<?php endif; ?>

<!-- Pie global repetido en cada hoja al imprimir -->
<div class="print-footer">
  <span><span class="b"><?= e($provNombre) ?></span> · Informe Técnico de Mantenimiento</span>
  <span><?= e($ot['codigo_ot']) ?> · Diseñado por Magus Technologies</span>
</div>

<script>window.addEventListener('load',()=>setTimeout(()=>window.print(),600));</script>
</body>
</html>
