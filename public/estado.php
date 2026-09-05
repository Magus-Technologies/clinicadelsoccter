<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';

// ── Datos de la empresa (config) ─────────────────────────────
$empresa = [];
try {
    $db2  = getDB();
    foreach ($db2->query("SELECT clave, valor FROM configuracion")->fetchAll() as $r) {
        $empresa[$r['clave']] = $r['valor'];
    }
} catch (Exception $e) {}

$nombreEmpresa = $empresa['empresa_nombre']    ?? APP_NAME;
$telEmpresa    = $empresa['empresa_telefono']  ?? '';
$dirEmpresa    = $empresa['empresa_direccion'] ?? '';
$terminosTexto = $empresa['terminos_condiciones'] ?? '';
$monedaSimbolo = $empresa['moneda_simbolo']    ?? 'S/';
$logoUrl = !empty($empresa['print_logo'])   ? UPLOAD_URL . $empresa['print_logo']
         : (!empty($empresa['empresa_logo']) ? UPLOAD_URL . $empresa['empresa_logo'] : '');

// ── Buscar OT por código público ─────────────────────────────
$ot = null; $adjuntos = []; $repuestos = []; $historial = []; $notas = []; $error = '';
$codigo = strtoupper(trim($_GET['codigo'] ?? $_POST['codigo'] ?? ''));

if ($codigo) {
    try {
        $db   = getDB();
        $stmt = $db->prepare("
            SELECT ot.*,
                   c.nombre AS cliente_nombre, c.ruc_dni, c.tipo_doc, c.telefono, c.whatsapp, c.email AS cliente_email,
                   te.nombre AS tipo_equipo, e.marca, e.modelo, e.serial, e.color,
                   s.nombre AS servicio_nombre,
                   CONCAT(u.nombre,' ',u.apellido) AS tecnico_nombre, u.nombre AS tecnico_pila
            FROM ordenes_trabajo ot
            JOIN clientes     c  ON c.id  = ot.cliente_id
            JOIN equipos      e  ON e.id  = ot.equipo_id
            JOIN tipos_equipo te ON te.id = e.tipo_equipo_id
            LEFT JOIN servicios s ON s.id = ot.servicio_id
            LEFT JOIN usuarios  u ON u.id = ot.tecnico_id
            WHERE ot.codigo_publico = ?
            LIMIT 1
        ");
        $stmt->execute([$codigo]);
        $ot = $stmt->fetch();

        if ($ot) {
            // Fotos y videos (todos los adjuntos)
            $a = $db->prepare("SELECT ruta, tipo_archivo, descripcion FROM fotos_ot WHERE ot_id=? ORDER BY (tipo_archivo='video'), id");
            $a->execute([$ot['id']]);
            $adjuntos = $a->fetchAll();

            // Presupuesto (líneas)
            $r = $db->prepare("SELECT descripcion, cantidad, precio_unit, subtotal FROM ot_repuestos WHERE ot_id=? ORDER BY id");
            $r->execute([$ot['id']]);
            $repuestos = $r->fetchAll();

            // Historial de estados
            $h = $db->prepare("SELECT estado_nuevo, comentario, created_at FROM historial_ot WHERE ot_id=? ORDER BY created_at DESC");
            $h->execute([$ot['id']]);
            $historial = $h->fetchAll();

            // Notas visibles al cliente (tabla notas_ot; puede no existir aún)
            try {
                $n = $db->prepare("
                    SELECT n.nota, n.created_at,
                           COALESCE(NULLIF(TRIM(CONCAT(u.nombre,' ',COALESCE(u.apellido,''))),''),'Equipo del taller') AS autor,
                           COALESCE(u.rol,'') AS rol
                    FROM notas_ot n
                    LEFT JOIN usuarios u ON u.id = n.usuario_id
                    WHERE n.ot_id=? AND n.visible_cliente=1
                    ORDER BY n.created_at DESC");
                $n->execute([$ot['id']]);
                $notas = $n->fetchAll();
            } catch (\Throwable $e) { $notas = []; }
        } else {
            $error = 'No encontramos ninguna orden con ese código. Verifica que esté bien escrito.';
        }
    } catch (Exception $e) {
        $error = 'Error de conexión. Intenta más tarde.';
    }
}

// ── Estados del portal: leídos de TU sistema (getEstadosOT → tabla estados_ot) ──
// Así, lo que configures en Configuración → Estados OT aparece aquí solo.
$estadosSis = function_exists('getEstadosOT') ? getEstadosOT() : [];

// Mapa de tus colores Bootstrap → colores del portal [texto, fondo, tinta]
$mapColor = [
    'secondary' => ['#6b7280','#f3f4f6','#374151'],
    'info'      => ['#0284c7','#e0f2fe','#075985'],
    'primary'   => ['#2563eb','#e0edff','#1e3a8a'],
    'warning'   => ['#d97706','#fef3c7','#92400e'],
    'success'   => ['#16a34a','#dcfce7','#0f7a37'],
    'danger'    => ['#dc2626','#fee2e2','#991b1b'],
    'dark'      => ['#374151','#e5e7eb','#111827'],
];
// Iconos feather de tu tabla → emoji para el portal (fallback por defecto)
$mapEmoji = [
    'inbox'=>'📥','search'=>'🔍','tool'=>'🔧','wrench'=>'🔧','check-circle'=>'✅','check'=>'✅',
    'package'=>'📦','truck'=>'🚚','x-circle'=>'❌','x'=>'❌','clipboard'=>'📋','edit'=>'📝',
    'edit-3'=>'📝','clock'=>'⏳','zap'=>'⚡','archive'=>'🗄️','star'=>'⭐','thumbs-up'=>'👍',
    'alert-triangle'=>'⚠️','flag'=>'🏁','settings'=>'⚙️','activity'=>'📈','send'=>'📤',
];
// Descripciones amigables opcionales por clave (si falta, se usa una genérica)
$descPorClave = [
    'ingresado'     => 'Tu equipo ingresó al taller y será revisado pronto.',
    'en_revision'   => 'Nuestro técnico está revisando tu equipo.',
    'en_diagnostico'=> 'Estamos diagnosticando la falla de tu equipo.',
    'en_proforma'   => 'Estamos preparando el presupuesto de tu reparación.',
    'en_reparacion' => 'Tu equipo se encuentra en proceso de reparación.',
    'para_testeo'   => 'Terminando: estamos probando tu equipo antes de entregarlo.',
    'para_detail'   => 'Tu equipo está en detailing / acabado final.',
    'para_recojo'   => '¡Tu equipo está listo! Puedes pasar a recogerlo cuando gustes.',
    'archivado'     => 'Orden finalizada. ¡Gracias por confiar en nosotros!',
    'cancelado'     => 'Esta orden fue cancelada.',
    'duplicado_error'=> 'Esta orden fue marcada como duplicada o con error.',
];

// Secuencia de flujo (estados NO finales, en el orden de tu tabla)
$flujoClaves = [];
foreach ($estadosSis as $k => $e) { if (empty($e['es_final'])) $flujoClaves[] = $k; }
$nPasos = count($flujoClaves);

// Datos del estado actual de la OT
$eData = null; $pasoAct = 0; $fillPct = 0; $esFinal = false; $idxFlujo = -1;
if ($ot) {
    $cur = $estadosSis[$ot['estado']] ?? null;
    if ($cur) {
        [$color,$bg,$ink] = $mapColor[$cur['color']] ?? $mapColor['secondary'];
        $esFinal = !empty($cur['es_final']);
        $idxFlujo = array_search($ot['estado'], $flujoClaves, true);
        $eData = [
            'label' => $cur['label'],
            'emoji' => $mapEmoji[$cur['icon']] ?? ($esFinal ? '🏁' : '🔧'),
            'color' => $color, 'bg' => $bg, 'ink' => $ink,
            'desc'  => $descPorClave[$ot['estado']]
                       ?? ($esFinal ? 'Orden finalizada.' : 'Tu orden está en proceso. Estado actual: '.$cur['label'].'.'),
        ];
        if ($idxFlujo !== false && $idxFlujo >= 0) {
            $pasoAct = $idxFlujo + 1;
            $fillPct = $nPasos > 1 ? round(max(0, min(100, ($idxFlujo / ($nPasos - 1)) * 100)), 1) : 100;
        }
    } else {
        // Estado que no está en la tabla (raro): fallback neutro
        [$color,$bg,$ink] = $mapColor['secondary'];
        $eData = ['label'=>ucfirst(str_replace('_',' ',$ot['estado'])),'emoji'=>'🔧','color'=>$color,'bg'=>$bg,'ink'=>$ink,'desc'=>'Estado actual de tu orden.'];
    }
}

// Presupuesto: líneas que suman al total
$lineasPres = [];
foreach ($repuestos as $rr) {
    $lineasPres[] = ['desc'=>$rr['descripcion'],'cant'=>rtrim(rtrim(number_format((float)$rr['cantidad'],2),'0'),'.'),'monto'=>(float)$rr['subtotal']];
}
if (!$repuestos && $ot && (float)$ot['costo_repuestos'] > 0) $lineasPres[] = ['desc'=>'Repuestos','cant'=>'1','monto'=>(float)$ot['costo_repuestos']];
if ($ot && (float)$ot['costo_mano_obra']   > 0) $lineasPres[] = ['desc'=>'Mano de obra','cant'=>'1','monto'=>(float)$ot['costo_mano_obra']];
if ($ot && (float)$ot['costo_diagnostico'] > 0) $lineasPres[] = ['desc'=>'Diagnóstico','cant'=>'1','monto'=>(float)$ot['costo_diagnostico']];
$descuento = $ot ? (float)$ot['descuento'] : 0;
$totalOT   = $ot ? ((float)$ot['precio_final'] ?: ((float)$ot['costo_total'] ?: max(0, array_sum(array_column($lineasPres,'monto')) - $descuento))) : 0;
$subTotal  = $totalOT + $descuento;   // subtotal − descuento = total (siempre consistente)
$hayPresupuesto = !empty($lineasPres) || $totalOT > 0;

function _sub1(string $s): string { return function_exists('mb_substr') ? mb_substr($s,0,1) : substr($s,0,1); }
function _upper(string $s): string { return function_exists('mb_strtoupper') ? mb_strtoupper($s) : strtoupper($s); }
function iniciales(string $n): string {
    $n = trim($n);
    if ($n === '') return '·';
    $p = preg_split('/\s+/', $n);
    $r = _upper(_sub1($p[0] ?? '') . _sub1($p[1] ?? ''));
    return $r !== '' ? $r : '·';
}
$docLabel = ['dni'=>'DNI','ruc'=>'RUC','carnet'=>'CE','pasaporte'=>'Pasaporte'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Seguimiento · <?= sanitize($nombreEmpresa) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Inter:wght@400;450;500;600&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<style>
  :root{
    --canvas:#eef2f7; --card:#fff; --ink:#0d1526; --body:#475069; --muted:#8a93a6; --line:#e6eaf1;
    --brand:#fb4b57; --brand-deep:#d61f2e; --charge:#ff8a3d;
    --ok:#16a34a; --ok-bg:#e7f7ec; --warn:#d97706; --warn-bg:#fdf1dc;
    --sky:#0284c7; --sky-bg:#e2f2fd; --violet:#7c3aed; --violet-bg:#efe9fe;
    --gray:#6b7280; --gray-bg:#f0f2f6; --danger:#dc2626; --danger-bg:#fdeaea;
    --radius:18px; --shadow:0 1px 2px rgba(13,21,38,.04),0 8px 24px -12px rgba(13,21,38,.14); --shadow-sm:0 1px 2px rgba(13,21,38,.05);
  }
  *{box-sizing:border-box;margin:0;padding:0}
  html{-webkit-text-size-adjust:100%}
  body{font-family:'Inter',system-ui,sans-serif;background:radial-gradient(1200px 500px at 100% -10%,#e7edf8 0%,rgba(231,237,248,0) 60%),var(--canvas);color:var(--ink);line-height:1.5;-webkit-font-smoothing:antialiased;padding-bottom:40px}
  .wrap{max-width:920px;margin:0 auto;padding:0 16px}
  a{color:inherit}

  .topbar{position:sticky;top:0;z-index:20;background:rgba(255,255,255,.82);backdrop-filter:saturate(180%) blur(12px);border-bottom:1px solid var(--line)}
  .topbar-inner{max-width:920px;margin:0 auto;padding:11px 16px;display:flex;align-items:center;gap:12px}
  .brand{display:flex;align-items:center;gap:11px;min-width:0}
  .brand-emblem{width:44px;height:44px;border-radius:12px;flex-shrink:0;background:linear-gradient(135deg,var(--brand),var(--brand-deep));display:grid;place-items:center;color:#fff;box-shadow:0 6px 16px -6px rgba(251,75,87,.6);overflow:hidden}
  .brand-emblem img{width:100%;height:100%;object-fit:contain;background:#fff}
  .brand-emblem svg{width:24px;height:24px}
  .brand-name{font-family:'Space Grotesk';font-weight:700;font-size:1.02rem;letter-spacing:-.01em;line-height:1.1;color:var(--ink)}
  .brand-sub{font-size:.7rem;color:var(--muted);letter-spacing:.14em;text-transform:uppercase;font-weight:500}
  .top-actions{margin-left:auto;display:flex;align-items:center;gap:8px}
  .icon-btn{width:38px;height:38px;border-radius:11px;border:1px solid var(--line);background:#fff;display:grid;place-items:center;color:var(--body);cursor:pointer;transition:.15s}
  .icon-btn:hover{border-color:var(--brand);color:var(--brand)}
  .icon-btn svg{width:18px;height:18px}
  .ghost-btn{display:inline-flex;align-items:center;gap:7px;height:38px;padding:0 14px;border-radius:11px;border:1px solid var(--line);background:#fff;color:var(--body);font-weight:500;font-size:.86rem;cursor:pointer;transition:.15s;white-space:nowrap;text-decoration:none}
  .ghost-btn:hover{border-color:var(--brand);color:var(--brand)}
  .ghost-btn svg{width:16px;height:16px}
  @media(max-width:560px){ .ghost-btn span{display:none} .ghost-btn{padding:0;width:38px;justify-content:center} }

  .eyebrow{font-size:.72rem;letter-spacing:.16em;text-transform:uppercase;color:var(--muted);font-weight:600;margin-bottom:6px}
  .hero{margin-top:22px}
  .hero-head{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:16px}
  .ot-number{font-family:'Space Grotesk';font-weight:700;font-size:1.9rem;letter-spacing:-.02em;line-height:1}
  .status-pill{display:inline-flex;align-items:center;gap:8px;height:38px;padding:0 16px;border-radius:999px;font-weight:600;font-size:.92rem}
  .status-pill .dot{width:9px;height:9px;border-radius:50%;background:currentColor;box-shadow:0 0 0 4px color-mix(in srgb,currentColor 20%,transparent)}
  .hero-card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden}
  .hero-banner{padding:20px 22px;background:linear-gradient(135deg,color-mix(in srgb,var(--state) 14%,#fff),#fff 70%);border-bottom:1px solid var(--line);display:flex;align-items:center;gap:16px}
  .hero-emoji{width:58px;height:58px;border-radius:16px;flex-shrink:0;display:grid;place-items:center;font-size:30px;background:color-mix(in srgb,var(--state) 16%,#fff);border:1px solid color-mix(in srgb,var(--state) 30%,#fff)}
  .hero-banner .txt h2{font-family:'Space Grotesk';font-weight:700;font-size:1.3rem;color:var(--state-ink);letter-spacing:-.01em}
  .hero-banner .txt p{color:var(--body);font-size:.92rem;margin-top:2px}
  .track{padding:26px 22px 8px}
  .track-rail{position:relative;height:3px;background:var(--line);border-radius:3px;margin:0 20px}
  .track-fill{position:absolute;left:0;top:0;height:100%;border-radius:3px;background:linear-gradient(90deg,var(--charge),var(--brand));box-shadow:0 0 12px rgba(251,75,87,.45)}
  .track-steps{display:flex;justify-content:space-between;margin:-13px 0 0}
  .step{display:flex;flex-direction:column;align-items:center;gap:9px;flex:1}
  .step .bead{width:26px;height:26px;border-radius:50%;background:#fff;border:2px solid var(--line);display:grid;place-items:center;font-size:12px;color:var(--muted);z-index:2;transition:.2s}
  .step.done .bead{background:var(--brand);border-color:var(--brand);color:#fff}
  .step.current .bead{background:#fff;border-color:var(--brand);color:var(--brand);transform:scale(1.18);box-shadow:0 0 0 5px color-mix(in srgb,var(--brand) 16%,transparent)}
  .step .lbl{font-size:.72rem;color:var(--muted);text-align:center;font-weight:500;max-width:74px}
  .step.done .lbl,.step.current .lbl{color:var(--ink)}
  .step.current .lbl{color:var(--brand-deep);font-weight:600}
  .track-compact .bead{width:20px;height:20px;font-size:10px}
  .track-compact .step.current .lbl{font-size:.74rem}
  .final-bar{border-radius:12px;padding:11px 16px;font-weight:600;font-size:.95rem;text-align:center;font-family:'Space Grotesk'}
  @media(max-width:560px){ .step .lbl{font-size:.62rem;max-width:56px} .track{padding:24px 8px 6px} }
  .hero-meta{display:grid;grid-template-columns:repeat(3,1fr);border-top:1px solid var(--line)}
  .hero-meta .m{padding:16px 18px;border-right:1px solid var(--line)}
  .hero-meta .m:last-child{border-right:none}
  .hero-meta .m .k{font-size:.7rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);font-weight:600;margin-bottom:5px}
  .hero-meta .m .v{font-weight:600;font-size:.98rem}
  .code-chip{font-family:'Space Mono';font-weight:700;letter-spacing:.06em;color:var(--brand-deep);background:color-mix(in srgb,var(--brand) 8%,#fff);border:1px dashed color-mix(in srgb,var(--brand) 35%,#fff);padding:2px 8px;border-radius:7px;display:inline-block}
  .v-strong{color:var(--brand-deep)}
  @media(max-width:560px){ .hero-meta{grid-template-columns:1fr} .hero-meta .m{border-right:none;border-bottom:1px solid var(--line)} .hero-meta .m:last-child{border-bottom:none} }

  .grid{display:grid;gap:16px;margin-top:16px}
  .grid.two{grid-template-columns:1fr 1fr}
  @media(max-width:680px){ .grid.two{grid-template-columns:1fr} }
  .card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow-sm)}
  .card-h{display:flex;align-items:center;gap:10px;padding:16px 18px;border-bottom:1px solid var(--line)}
  .card-h .ci{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;flex-shrink:0}
  .card-h .ci svg{width:18px;height:18px}
  .card-h h3{font-family:'Space Grotesk';font-weight:600;font-size:1.02rem;letter-spacing:-.01em}
  .card-b{padding:18px}
  .ci.blue{background:var(--brand);color:#fff}
  .ci.soft{background:#fdeeef;color:var(--brand)}

  .equipo-model{font-family:'Space Grotesk';font-weight:700;font-size:1.3rem;letter-spacing:-.01em;line-height:1.1}
  .equipo-tipo{font-size:.82rem;color:var(--muted);margin-top:2px}
  .kv{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
  .kv .cell{flex:1;min-width:120px}
  .kv .k{font-size:.7rem;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);font-weight:600}
  .kv .val{font-weight:600;font-size:.92rem;margin-top:2px}
  .mono{font-family:'Space Mono';letter-spacing:.02em}
  .color-chip{display:inline-flex;align-items:center;gap:7px}
  .color-chip .sw{width:14px;height:14px;border-radius:5px;border:1px solid rgba(0,0,0,.12)}

  .contact-row{display:flex;align-items:center;gap:12px;padding:11px 0;border-bottom:1px solid var(--line)}
  .contact-row:last-child{border-bottom:none;padding-bottom:0}
  .contact-row:first-child{padding-top:0}
  .contact-ic{width:34px;height:34px;border-radius:10px;background:#f3f5fa;display:grid;place-items:center;color:var(--body);flex-shrink:0}
  .contact-ic svg{width:16px;height:16px}
  .contact-row .c-k{font-size:.72rem;color:var(--muted);font-weight:500}
  .contact-row .c-v{font-weight:600;font-size:.94rem;word-break:break-word}

  .field{margin-bottom:16px}
  .field:last-child{margin-bottom:0}
  .field .flabel{font-size:.72rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);font-weight:600;margin-bottom:6px;display:flex;align-items:center;gap:6px}
  .field .flabel svg{width:13px;height:13px}
  .field .ftext{color:var(--body);font-size:.95rem;line-height:1.55;white-space:pre-line}
  .callout{background:#f7f9fc;border:1px solid var(--line);border-left:3px solid var(--brand);border-radius:10px;padding:12px 14px}
  .tech-badge{display:inline-flex;align-items:center;gap:9px;background:#f3f5fa;border:1px solid var(--line);border-radius:999px;padding:5px 12px 5px 5px}
  .tech-badge .av{width:26px;height:26px;border-radius:50%;background:linear-gradient(135deg,var(--brand),var(--brand-deep));color:#fff;display:grid;place-items:center;font-weight:700;font-size:.8rem;font-family:'Space Grotesk'}
  .tech-badge span{font-weight:600;font-size:.9rem}

  .badge{display:inline-flex;align-items:center;gap:6px;font-size:.76rem;font-weight:600;padding:5px 11px;border-radius:999px}
  .badge.ok{background:var(--ok-bg);color:var(--ok)} .badge.pend{background:var(--warn-bg);color:var(--warn)}
  .badge svg{width:13px;height:13px}
  table.budget{width:100%;border-collapse:collapse;margin-top:6px}
  table.budget th{text-align:left;font-size:.7rem;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);font-weight:600;padding:0 0 10px;border-bottom:1px solid var(--line)}
  table.budget th.r,table.budget td.r{text-align:right}
  table.budget td{padding:13px 0;border-bottom:1px solid var(--line);font-size:.94rem;font-weight:500}
  table.budget td.desc{color:var(--ink)} table.budget td.num{font-family:'Space Mono';color:var(--body)}
  .totals{margin-top:14px;margin-left:auto;width:min(300px,100%)}
  .totals .row{display:flex;justify-content:space-between;padding:5px 0;font-size:.92rem;color:var(--body)}
  .totals .row.grand{border-top:2px solid var(--ink);margin-top:8px;padding-top:12px}
  .totals .row.grand .lbl{font-weight:700;color:var(--ink);font-family:'Space Grotesk'}
  .totals .row.grand .amt{font-family:'Space Grotesk';font-weight:700;font-size:1.45rem;color:var(--brand-deep)}
  .totals .amt.mono{font-family:'Space Mono';font-weight:700}
  .empty{color:var(--muted);font-size:.9rem;text-align:center;padding:14px}

  .tabs{display:flex;gap:4px;padding:6px;background:#fbebec;border-radius:13px;border:1px solid var(--line)}
  .tab{flex:1;display:flex;align-items:center;justify-content:center;gap:7px;height:40px;border-radius:9px;border:none;background:transparent;color:var(--body);font-weight:600;font-size:.88rem;cursor:pointer;font-family:'Inter';transition:.15s}
  .tab svg{width:16px;height:16px}
  .tab.active{background:#fff;color:var(--brand-deep);box-shadow:var(--shadow-sm)}
  .tab .count{background:color-mix(in srgb,var(--brand) 12%,#fff);color:var(--brand-deep);font-size:.72rem;font-weight:700;padding:1px 7px;border-radius:999px}
  .panel{display:none;padding-top:16px} .panel.active{display:block}
  .gallery{display:grid;grid-template-columns:repeat(5,1fr);gap:10px}
  @media(max-width:680px){ .gallery{grid-template-columns:repeat(3,1fr)} }
  @media(max-width:420px){ .gallery{grid-template-columns:repeat(2,1fr)} }
  .ph{aspect-ratio:1;border-radius:12px;border:1px solid var(--line);overflow:hidden;position:relative;background:#e7ecf3;cursor:pointer;display:block}
  .ph img{width:100%;height:100%;object-fit:cover;display:block}
  .ph.vid::after{content:'▶';position:absolute;inset:0;display:grid;place-items:center;color:#fff;font-size:16px;background:rgba(13,21,38,.32)}

  .notes{list-style:none}
  .note{background:#fbfcfe;border:1px solid var(--line);border-left:3px solid var(--brand);border-radius:12px;padding:14px 16px;margin-bottom:12px}
  .note:last-child{margin-bottom:0}
  .note-head{display:flex;align-items:center;gap:10px;margin-bottom:8px}
  .note-av{width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,var(--brand),var(--brand-deep));color:#fff;display:grid;place-items:center;font-weight:700;font-size:.78rem;font-family:'Space Grotesk';flex-shrink:0}
  .note-author{font-weight:600;font-size:.9rem;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
  .note-role{font-size:.66rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--brand-deep);background:color-mix(in srgb,var(--brand) 12%,#fff);padding:2px 7px;border-radius:999px}
  .note-date{font-size:.74rem;color:var(--muted);font-family:'Space Mono'}
  .note-text{color:var(--body);font-size:.92rem;line-height:1.55;white-space:pre-line}

  .timeline{list-style:none;position:relative;padding-left:6px}
  .timeline li{position:relative;padding:0 0 22px 30px}
  .timeline li:last-child{padding-bottom:0}
  .timeline li::before{content:'';position:absolute;left:7px;top:22px;bottom:-2px;width:2px;background:var(--line)}
  .timeline li:last-child::before{display:none}
  .tl-dot{position:absolute;left:0;top:2px;width:16px;height:16px;border-radius:50%;background:var(--brand);border:2px solid var(--brand);z-index:2}
  .tl-estado{font-weight:600;font-size:.95rem}
  .tl-time{font-size:.76rem;color:var(--muted);font-family:'Space Mono'}
  .tl-comment{color:var(--body);font-size:.9rem;margin-top:3px}

  .terms-toggle{width:100%;display:flex;align-items:center;justify-content:space-between;background:none;border:none;cursor:pointer;padding:0;font-family:inherit}
  .terms-toggle .chev{transition:.2s;color:var(--muted)}
  .terms-toggle.open .chev{transform:rotate(180deg)}
  .terms-body{max-height:0;overflow:hidden;transition:max-height .3s ease}
  .terms-body.open{max-height:1600px}
  .terms-inner{padding-top:16px;color:var(--body);font-size:.85rem;line-height:1.7;max-height:360px;overflow-y:auto;padding-right:8px;white-space:pre-line}

  .foot{text-align:center;margin-top:30px;color:var(--muted);font-size:.82rem}
  .foot .fc{display:inline-flex;gap:16px;flex-wrap:wrap;justify-content:center;margin-bottom:10px}
  .foot .fc span{display:inline-flex;align-items:center;gap:6px}
  .foot .fc svg{width:14px;height:14px}
  .foot .pw{font-size:.74rem;opacity:.8}
  .foot .credit{margin-top:14px;padding-top:14px;border-top:1px solid var(--line);font-size:.8rem;color:var(--muted);display:flex;align-items:center;justify-content:center;gap:6px;flex-wrap:wrap}
  .foot .credit a{display:inline-flex;align-items:center;gap:6px;color:var(--brand-deep);font-weight:600;text-decoration:none;transition:.15s}
  .foot .credit a:hover{color:var(--brand)}
  .foot .credit a svg{width:15px;height:15px}

  /* Buscador (sin código / no encontrado) */
  .search-wrap{max-width:520px;margin:8vh auto 0;text-align:center}
  .search-emblem{width:74px;height:74px;border-radius:20px;margin:0 auto 18px;background:linear-gradient(135deg,var(--brand),var(--brand-deep));display:grid;place-items:center;color:#fff;box-shadow:0 12px 30px -10px rgba(251,75,87,.6);overflow:hidden}
  .search-emblem img{width:100%;height:100%;object-fit:contain;background:#fff}
  .search-emblem svg{width:40px;height:40px}
  .search-wrap h1{font-family:'Space Grotesk';font-weight:700;font-size:1.7rem;letter-spacing:-.02em}
  .search-wrap p.sub{color:var(--body);margin-top:6px;font-size:.96rem}
  .search-card{background:#fff;border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow);padding:22px;margin-top:24px;text-align:left}
  .search-card label{font-size:.72rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);font-weight:600;display:block;margin-bottom:8px}
  .search-row{display:flex;gap:10px}
  .search-input{flex:1;border:2px solid var(--line);border-radius:12px;padding:13px 16px;font-size:1.15rem;font-weight:700;font-family:'Space Mono';letter-spacing:.12em;text-transform:uppercase;color:var(--ink);outline:none;transition:.15s;min-width:0}
  .search-input:focus{border-color:var(--brand)}
  .search-btn{border:none;background:linear-gradient(135deg,var(--brand),var(--brand-deep));color:#fff;font-weight:600;padding:0 22px;border-radius:12px;cursor:pointer;font-size:.95rem;font-family:'Inter';white-space:nowrap}
  .alert-err{background:var(--danger-bg);border:1px solid color-mix(in srgb,var(--danger) 25%,#fff);color:#991b1b;border-radius:12px;padding:12px 14px;font-size:.9rem;margin-top:16px;text-align:left}

  /* Lightbox */
  .lb{position:fixed;inset:0;background:rgba(9,13,24,.9);display:none;align-items:center;justify-content:center;z-index:50;padding:20px}
  .lb.open{display:flex} .lb img,.lb video{max-width:94vw;max-height:90vh;border-radius:12px}
  .lb-close{position:absolute;top:18px;right:20px;color:#fff;font-size:34px;cursor:pointer;line-height:1;background:none;border:none}
  @media (prefers-reduced-motion: reduce){ *{transition:none!important} }
</style>
</head>
<body style="--state:<?= $eData['color'] ?? 'var(--gray)' ?>;--state-ink:<?= $eData['ink'] ?? '#374151' ?>">

<!-- ===== Topbar ===== -->
<header class="topbar">
  <div class="topbar-inner">
    <div class="brand">
      <div class="brand-emblem">
        <?php if ($logoUrl): ?><img src="<?= sanitize($logoUrl) ?>" alt="<?= sanitize($nombreEmpresa) ?>"/>
        <?php else: ?><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="5" cy="18" r="3"/><circle cx="19" cy="18" r="3"/><path d="M16 18h-4l-1.5-9H8"/><path d="M12 9l6 0"/></svg><?php endif; ?>
      </div>
      <div>
        <div class="brand-name"><?= sanitize($nombreEmpresa) ?></div>
        <div class="brand-sub">Seguimiento en línea</div>
      </div>
    </div>
    <div class="top-actions">
      <?php if ($ot): ?>
      <button class="icon-btn" title="Imprimir" onclick="window.print()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8" rx="1"/></svg></button>
      <?php endif; ?>
      <a class="ghost-btn" href="?"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg><span>Consultar otra orden</span></a>
    </div>
  </div>
</header>

<?php if (!$ot): ?>
<!-- ============ BUSCADOR ============ -->
<div class="wrap">
  <div class="search-wrap">
    <div class="search-emblem">
      <?php if ($logoUrl): ?><img src="<?= sanitize($logoUrl) ?>" alt=""/>
      <?php else: ?><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="5" cy="18" r="3"/><circle cx="19" cy="18" r="3"/><path d="M16 18h-4l-1.5-9H8"/><path d="M12 9l6 0"/></svg><?php endif; ?>
    </div>
    <h1>Sigue tu reparación</h1>
    <p class="sub">Ingresa el código de consulta que aparece en tu comprobante para ver el estado de tu equipo en tiempo real.</p>
    <form class="search-card" method="GET" action="">
      <label for="codigo">Código de consulta</label>
      <div class="search-row">
        <input class="search-input" id="codigo" name="codigo" placeholder="Ej. ABC12345" value="<?= sanitize($codigo) ?>" autocomplete="off" autofocus/>
        <button class="search-btn" type="submit">Buscar</button>
      </div>
      <?php if ($error): ?><div class="alert-err"><?= sanitize($error) ?></div><?php endif; ?>
    </form>
  </div>
</div>

<?php else: ?>
<!-- ============ RESULTADO ============ -->
<div class="wrap">

  <section class="hero">
    <div class="hero-head">
      <div>
        <div class="eyebrow">Orden de trabajo</div>
        <div class="ot-number"><?= sanitize($ot['codigo_ot']) ?></div>
      </div>
      <span class="status-pill" style="background:<?= $eData['bg'] ?>;color:<?= $eData['ink'] ?>"><span class="dot"></span><?= sanitize($eData['label']) ?></span>
    </div>

    <div class="hero-card">
      <div class="hero-banner">
        <div class="hero-emoji"><?= $eData['emoji'] ?></div>
        <div class="txt">
          <h2><?= sanitize($eData['label']) ?></h2>
          <p><?= sanitize($eData['desc']) ?></p>
        </div>
      </div>

      <?php if (!$esFinal && $nPasos > 0): ?>
      <div class="track <?= $nPasos > 5 ? 'track-compact' : '' ?>">
        <div class="track-rail"><div class="track-fill" style="width:<?= $fillPct ?>%"></div></div>
        <div class="track-steps">
          <?php $i=0; foreach ($flujoClaves as $clave): $i++; $cls = $i<$pasoAct ? 'done' : ($i===$pasoAct ? 'current' : ''); ?>
          <div class="step <?= $cls ?>" title="<?= sanitize($estadosSis[$clave]['label'] ?? '') ?>">
            <div class="bead"><?= $i<=$pasoAct ? '✓' : '' ?></div>
            <?php // Con muchos estados, la etiqueta se muestra solo en el paso actual (se ve limpio en móvil) ?>
            <?php if ($nPasos <= 5 || $i === $pasoAct): ?>
            <div class="lbl"><?= sanitize($estadosSis[$clave]['label'] ?? '') ?></div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
      <?php if ($esFinal && $ot['estado'] !== 'cancelado' && $ot['estado'] !== 'duplicado_error'): ?>
      <div class="track"><div class="final-bar" style="background:<?= $eData['bg'] ?>;color:<?= $eData['ink'] ?>"><?= $eData['emoji'] ?> <?= sanitize($eData['label']) ?></div></div>
      <?php endif; ?>

      <div class="hero-meta">
        <div class="m"><div class="k">Código de consulta</div><div class="v"><span class="code-chip"><?= sanitize($ot['codigo_publico']) ?></span></div></div>
        <div class="m"><div class="k">Ingreso</div><div class="v"><?= formatDateTime($ot['fecha_ingreso']) ?></div></div>
        <div class="m"><div class="k">Entrega estimada</div><div class="v v-strong"><?= $ot['fecha_estimada'] ? formatDate($ot['fecha_estimada']) : 'Por confirmar' ?></div></div>
      </div>
    </div>
  </section>

  <!-- Equipo + Cliente -->
  <div class="grid two">
    <div class="card">
      <div class="card-h"><div class="ci blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="5" cy="18" r="3"/><circle cx="19" cy="18" r="3"/><path d="M16 18h-4l-1.5-9H8"/><path d="M12 9l6 0"/></svg></div><h3>Tu equipo</h3></div>
      <div class="card-b">
        <div class="equipo-model"><?= sanitize(trim(($ot['marca'] ?: '').' · '.($ot['modelo'] ?: ''), ' ·')) ?: 'Equipo' ?></div>
        <div class="equipo-tipo"><?= sanitize($ot['tipo_equipo']) ?></div>
        <div class="kv">
          <?php if ($ot['serial']): ?><div class="cell"><div class="k">Serie</div><div class="val mono"><?= sanitize($ot['serial']) ?></div></div><?php endif; ?>
          <?php if ($ot['color']): ?><div class="cell"><div class="k">Color</div><div class="val"><?= sanitize($ot['color']) ?></div></div><?php endif; ?>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-h"><div class="ci soft"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div><h3>Cliente</h3></div>
      <div class="card-b">
        <div class="contact-row"><div class="contact-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div><div><div class="c-k"><?= ($docLabel[$ot['tipo_doc']] ?? 'Doc') . ($ot['ruc_dni'] ? ' '.sanitize($ot['ruc_dni']) : '') ?></div><div class="c-v"><?= sanitize($ot['cliente_nombre']) ?></div></div></div>
        <?php if ($ot['telefono'] || $ot['whatsapp']): ?>
        <div class="contact-row"><div class="contact-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.9.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg></div><div><div class="c-k">Teléfono</div><div class="c-v mono"><?= sanitize($ot['telefono'] ?: $ot['whatsapp']) ?></div></div></div>
        <?php endif; ?>
        <?php if ($ot['cliente_email']): ?>
        <div class="contact-row"><div class="contact-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 5L2 7"/></svg></div><div><div class="c-k">Correo</div><div class="c-v"><?= sanitize($ot['cliente_email']) ?></div></div></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Detalle + Presupuesto -->
  <div class="grid">
    <div class="card">
      <div class="card-h"><div class="ci blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.8 2.8-2.2-2.2 2.8-2.8z"/></svg></div><h3>Detalle del trabajo</h3></div>
      <div class="card-b">
        <div class="field">
          <div class="flabel"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>Trabajo solicitado</div>
          <div class="ftext"><?= sanitize($ot['servicio_nombre'] ?: $ot['problema_reportado']) ?></div>
        </div>
        <?php if (!empty($ot['diagnostico_tecnico'])): ?>
        <div class="field">
          <div class="flabel"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v4M12 17h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>Detalle del estado</div>
          <div class="callout ftext"><?= sanitize($ot['diagnostico_tecnico']) ?></div>
        </div>
        <?php endif; ?>
        <?php if (!empty($ot['tecnico_nombre'])): ?>
        <div class="field">
          <div class="flabel"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>Técnico asignado</div>
          <div class="tech-badge"><span class="av"><?= sanitize(iniciales($ot['tecnico_nombre'])) ?></span><span><?= sanitize($ot['tecnico_nombre']) ?></span></div>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-h" style="justify-content:space-between">
        <div style="display:flex;align-items:center;gap:10px">
          <div class="ci blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 4 5v6c0 5 3.4 8.5 8 10 4.6-1.5 8-5 8-10V5z"/><path d="m9 12 2 2 4-4"/></svg></div>
          <h3>Presupuesto</h3>
        </div>
        <?php if ($hayPresupuesto): ?>
          <?php if ((int)$ot['presupuesto_aprobado'] === 1): ?>
          <span class="badge ok"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6 9 17l-5-5"/></svg>Aprobado</span>
          <?php else: ?>
          <span class="badge pend"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>Pendiente</span>
          <?php endif; ?>
        <?php endif; ?>
      </div>
      <div class="card-b">
        <?php if ($hayPresupuesto): ?>
        <p style="color:var(--body);font-size:.9rem;margin-bottom:6px">Servicios y repuestos aplicados a tu orden:</p>
        <table class="budget">
          <thead><tr><th>Descripción</th><th class="r">Cant.</th><th class="r">Total</th></tr></thead>
          <tbody>
            <?php foreach ($lineasPres as $ln): ?>
            <tr>
              <td class="desc"><?= sanitize($ln['desc']) ?></td>
              <td class="num r"><?= sanitize((string)$ln['cant']) ?></td>
              <td class="num r"><?= $monedaSimbolo ?> <?= number_format((float)$ln['monto'],2) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div class="totals">
          <div class="row"><span>Subtotal</span><span class="amt mono"><?= $monedaSimbolo ?> <?= number_format($subTotal,2) ?></span></div>
          <?php if ($descuento > 0): ?><div class="row"><span>Descuento</span><span class="amt mono">− <?= $monedaSimbolo ?> <?= number_format($descuento,2) ?></span></div><?php endif; ?>
          <div class="row grand"><span class="lbl">Total</span><span class="amt"><?= $monedaSimbolo ?> <?= number_format($totalOT,2) ?></span></div>
        </div>
        <?php else: ?>
        <div class="empty">El presupuesto aún no está disponible. Te avisaremos apenas esté listo.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Fotos / Notas / Seguimiento -->
  <div class="card" style="margin-top:16px">
    <div class="card-b">
      <div class="tabs">
        <button class="tab active" data-tab="fotos"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.1-3.1a2 2 0 0 0-2.8 0L6 21"/></svg>Fotos<?php if ($adjuntos): ?> <span class="count"><?= count($adjuntos) ?></span><?php endif; ?></button>
        <button class="tab" data-tab="notas"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M9 13h6M9 17h4"/></svg>Notas<?php if ($notas): ?> <span class="count"><?= count($notas) ?></span><?php endif; ?></button>
        <button class="tab" data-tab="seg"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>Seguimiento</button>
      </div>

      <div class="panel active" id="tab-fotos">
        <?php if ($adjuntos): ?>
        <div class="gallery">
          <?php foreach ($adjuntos as $ad): $u = UPLOAD_URL . $ad['ruta']; $esVid = ($ad['tipo_archivo'] ?? 'foto') === 'video'; ?>
            <?php if ($esVid): ?>
            <a class="ph vid" href="#" onclick="abrirLB('<?= sanitize($u) ?>',true);return false;"></a>
            <?php else: ?>
            <a class="ph" href="#" onclick="abrirLB('<?= sanitize($u) ?>',false);return false;"><img src="<?= sanitize($u) ?>" alt="Foto del equipo" loading="lazy"/></a>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty">Aún no hay fotos cargadas para esta orden.</div>
        <?php endif; ?>
      </div>

      <div class="panel" id="tab-notas">
        <?php if ($notas): ?>
        <ul class="notes">
          <?php foreach ($notas as $nt): $rolTxt = ucfirst($nt['rol'] ?? ''); ?>
          <li class="note">
            <div class="note-head">
              <span class="note-av"><?= sanitize(iniciales($nt['autor'])) ?></span>
              <div>
                <div class="note-author"><?= sanitize($nt['autor']) ?><?php if ($rolTxt): ?> <span class="note-role"><?= sanitize($rolTxt) ?></span><?php endif; ?></div>
                <div class="note-date"><?= formatDateTime($nt['created_at']) ?></div>
              </div>
            </div>
            <p class="note-text"><?= sanitize($nt['nota']) ?></p>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <div class="empty">Todavía no hay notas para mostrar.</div>
        <?php endif; ?>
      </div>

      <div class="panel" id="tab-seg">
        <?php if ($historial): ?>
        <ul class="timeline">
          <?php foreach ($historial as $hh): $eH = $estadosSis[$hh['estado_nuevo']] ?? ['label'=>ucfirst(str_replace('_',' ',$hh['estado_nuevo']))]; ?>
          <li>
            <span class="tl-dot"></span>
            <div class="tl-estado"><?= sanitize($eH['label'] ?? $hh['estado_nuevo']) ?></div>
            <div class="tl-time"><?= formatDateTime($hh['created_at']) ?></div>
            <?php if (!empty($hh['comentario'])): ?><div class="tl-comment"><?= sanitize($hh['comentario']) ?></div><?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <div class="empty">Aún no hay movimientos registrados.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Términos -->
  <?php if (trim($terminosTexto) !== ''): ?>
  <div class="card" style="margin-top:16px">
    <div class="card-b">
      <button class="terms-toggle" id="termsBtn" type="button">
        <span style="display:flex;align-items:center;gap:10px">
          <span class="ci soft" style="width:34px;height:34px;border-radius:10px;display:grid;place-items:center"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px"><path d="M3 6h18M7 12h10M5 18h14"/><path d="M12 2v4"/></svg></span>
          <span style="font-family:'Space Grotesk';font-weight:600;font-size:1.02rem">Términos y condiciones</span>
        </span>
        <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:20px;height:20px"><path d="m6 9 6 6 6-6"/></svg>
      </button>
      <div class="terms-body" id="termsBody"><div class="terms-inner"><?= sanitize($terminosTexto) ?></div></div>
    </div>
  </div>
  <?php endif; ?>

</div>
<?php endif; ?>

<!-- ===== Footer ===== -->
<div class="wrap">
  <div class="foot">
    <div class="fc">
      <?php if ($dirEmpresa): ?><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 12-9 12s-9-5-9-12a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg><?= sanitize($dirEmpresa) ?></span><?php endif; ?>
      <?php if ($telEmpresa): ?><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.9.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg><?= sanitize($telEmpresa) ?></span><?php endif; ?>
    </div>
    <div class="pw"><?= sanitize($nombreEmpresa) ?> · Servicio técnico especializado en scooters eléctricos</div>
    <div class="credit">
      Diseñado por
      <a href="https://magustechnologies.com/" target="_blank" rel="noopener">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 2 7l10 5 10-5-10-5z"/><path d="m2 17 10 5 10-5"/><path d="m2 12 10 5 10-5"/></svg>
        Magus Technologies
      </a>
    </div>
  </div>
</div>

<!-- Lightbox -->
<div class="lb" id="lb" onclick="cerrarLB(event)">
  <button class="lb-close" onclick="cerrarLB(event)" aria-label="Cerrar">&times;</button>
  <div id="lb-content"></div>
</div>

<script>
  document.querySelectorAll('.tab').forEach(function(t){
    t.addEventListener('click', function(){
      document.querySelectorAll('.tab').forEach(x=>x.classList.remove('active'));
      document.querySelectorAll('.panel').forEach(x=>x.classList.remove('active'));
      t.classList.add('active');
      document.getElementById('tab-'+t.dataset.tab).classList.add('active');
    });
  });
  var tb=document.getElementById('termsBtn'), body=document.getElementById('termsBody');
  if(tb){ tb.addEventListener('click', function(){ tb.classList.toggle('open'); body.classList.toggle('open'); }); }
  function abrirLB(src, esVideo){
    var c=document.getElementById('lb-content');
    c.innerHTML = esVideo
      ? '<video src="'+src+'" controls autoplay playsinline></video>'
      : '<img src="'+src+'" alt="">';
    document.getElementById('lb').classList.add('open');
  }
  function cerrarLB(e){
    if(e.target.id==='lb' || e.target.classList.contains('lb-close') || e.target.tagName==='BUTTON'){
      document.getElementById('lb').classList.remove('open');
      document.getElementById('lb-content').innerHTML='';
    }
  }
  document.addEventListener('keydown',function(e){ if(e.key==='Escape'){document.getElementById('lb').classList.remove('open');document.getElementById('lb-content').innerHTML='';} });
</script>
</body>
</html>
