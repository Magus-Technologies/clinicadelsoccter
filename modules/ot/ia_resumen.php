<?php
/**
 * modules/ot/ia_resumen.php — Resumen de la OT generado con IA (Gemini).
 * Uso: modules/ot/ia_resumen.php?ot_id=123
 * Genera un mensaje claro para el cliente (apto para WhatsApp). El texto queda
 * editable y se puede copiar o enviar por WhatsApp.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../includes/ia.php';
requireLogin();

$db = getDB();
$id = (int)($_GET['ot_id'] ?? 0);

// OT + datos necesarios (SIN DNI/correo; el teléfono solo para el botón de WhatsApp)
$ot = $db->prepare("
    SELECT ot.codigo_ot, ot.estado, ot.problema_reportado, ot.diagnostico_inicial,
           ot.diagnostico_tecnico, ot.observaciones, ot.fecha_estimada, ot.garantia_dias,
           c.nombre AS cliente_nombre, c.whatsapp, c.telefono,
           e.marca, e.modelo, te.nombre AS tipo_equipo, s.nombre AS servicio_nombre
    FROM ordenes_trabajo ot
    JOIN clientes c ON c.id = ot.cliente_id
    JOIN equipos e ON e.id = ot.equipo_id
    JOIN tipos_equipo te ON te.id = e.tipo_equipo_id
    LEFT JOIN servicios s ON s.id = ot.servicio_id
    WHERE ot.id = ?");
$ot->execute([$id]);
$ot = $ot->fetch();
if (!$ot) { setFlash('danger','OT no encontrada'); redirect(BASE_URL.'modules/ot/index.php'); }

// Repuestos/servicios (descripción, sin exponer costos salvo que se quiera)
$reps = $db->prepare("SELECT descripcion, cantidad FROM ot_repuestos WHERE ot_id=? ORDER BY id");
$reps->execute([$id]);
$reps = $reps->fetchAll();

$estadoLabel = isset(ESTADOS_OT[$ot['estado']]) ? ESTADOS_OT[$ot['estado']]['label'] : ucfirst(str_replace('_',' ',$ot['estado']));
$primerNombre = trim(explode(' ', trim($ot['cliente_nombre']))[0] ?? '');
$telWa = preg_replace('/\D/', '', $ot['whatsapp'] ?: $ot['telefono']);

// ── Armar contexto para la IA (solo datos del equipo/servicio, nada sensible) ──
$ctx  = "Datos de la orden de trabajo:\n";
$ctx .= "- Código: {$ot['codigo_ot']}\n";
$ctx .= "- Cliente (nombre de pila para saludar): {$primerNombre}\n";
$ctx .= "- Equipo: ".trim(($ot['marca'] ?: '').' '.($ot['modelo'] ?: ''))." ({$ot['tipo_equipo']})\n";
$ctx .= "- Estado actual: {$estadoLabel}\n";
if (!empty($ot['servicio_nombre']))     $ctx .= "- Servicio: {$ot['servicio_nombre']}\n";
if (!empty($ot['problema_reportado']))  $ctx .= "- Problema reportado por el cliente: {$ot['problema_reportado']}\n";
if (!empty($ot['diagnostico_tecnico'])) $ctx .= "- Diagnóstico del técnico: {$ot['diagnostico_tecnico']}\n";
elseif (!empty($ot['diagnostico_inicial'])) $ctx .= "- Diagnóstico inicial: {$ot['diagnostico_inicial']}\n";
if (!empty($ot['observaciones']))       $ctx .= "- Observaciones: {$ot['observaciones']}\n";
if ($reps) {
    $ctx .= "- Repuestos/trabajos aplicados: ";
    $ctx .= implode(', ', array_map(fn($r)=>trim($r['descripcion']).(($r['cantidad']>1)?" (x{$r['cantidad']})":''), $reps))."\n";
}
if (!empty($ot['fecha_estimada']))      $ctx .= "- Fecha estimada de entrega: ".formatDate($ot['fecha_estimada'])."\n";
if (!empty($ot['garantia_dias']))       $ctx .= "- Garantía: {$ot['garantia_dias']} días\n";

$system = "Eres el asistente de La Clínica del Scooter, un taller de reparación de scooters eléctricos en Lima, Perú. "
        . "Redacta un mensaje breve y amable para enviar al CLIENTE por WhatsApp, explicando en lenguaje sencillo "
        . "(sin tecnicismos) qué tiene su equipo y en qué estado va la reparación. "
        . "Saluda usando el nombre de pila del cliente. Sé cordial, claro y profesional. Máximo 5 líneas. "
        . "No inventes datos que no estén en la información. No menciones precios. "
        . "Cierra invitando a escribir si tiene alguna duda. Firma como 'La Clínica del Scooter'.";

// Generar (salvo que la IA no esté configurada).
// Nota: los modelos nuevos de Gemini (3.x) gastan parte del presupuesto de tokens
// en "razonamiento" interno, así que damos margen amplio para que la respuesta
// visible no se corte a mitad de frase.
$res = iaGenerar($db, $ctx, $system, 2048);

$pageTitle  = 'Resumen IA — '.$ot['codigo_ot'];
$breadcrumb = [
    ['label'=>'Órdenes de trabajo','url'=>BASE_URL.'modules/ot/index.php'],
    ['label'=>$ot['codigo_ot'],'url'=>BASE_URL.'modules/ot/ver.php?id='.$id],
    ['label'=>'Resumen IA','url'=>null],
];
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h5 class="mb-0">✨ Resumen con IA — <?= sanitize($ot['codigo_ot']) ?></h5>
    <div class="text-muted small mt-1">Mensaje para el cliente (editable). Generado con IA a partir de la OT.</div>
  </div>
  <a href="<?= BASE_URL ?>modules/ot/ver.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm">← Volver a la OT</a>
</div>

<div class="tr-card">
  <div class="tr-card-body">
    <?php if (!$res['ok']): ?>
      <div class="alert alert-warning mb-0">
        <strong>No se pudo generar el resumen.</strong><br>
        <?= sanitize($res['error']) ?>
        <?php if (strpos($res['error'],'API key') !== false): ?>
          <div class="small mt-2">Pega tu API key de Gemini en <strong>Configuración</strong> (clave <code>gemini_api_key</code>) y vuelve a intentar.</div>
        <?php endif; ?>
      </div>
      <a href="<?= BASE_URL ?>modules/ot/ia_resumen.php?ot_id=<?= $id ?>&r=<?= time() ?>" class="btn btn-primary btn-sm mt-3">Reintentar</a>
    <?php else: ?>
      <label class="tr-form-label">Mensaje sugerido</label>
      <textarea id="ia-texto" class="form-control" rows="8" style="font-size:14px;line-height:1.6"><?= sanitize($res['texto']) ?></textarea>
      <div class="d-flex gap-2 flex-wrap mt-3">
        <button type="button" class="btn btn-primary btn-sm" onclick="copiar()"><i data-feather="copy" style="width:14px;height:14px"></i> Copiar</button>
        <?php if ($telWa): ?>
        <button type="button" class="btn btn-success btn-sm" onclick="enviarWa()"><i data-feather="send" style="width:14px;height:14px"></i> Enviar por WhatsApp</button>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>modules/ot/ia_resumen.php?ot_id=<?= $id ?>&r=<?= time() ?>" class="btn btn-outline-secondary btn-sm"><i data-feather="refresh-cw" style="width:14px;height:14px"></i> Regenerar</a>
      </div>
      <div class="text-muted mt-2" style="font-size:11px">Revisa y ajusta el texto antes de enviarlo. La IA puede equivocarse.</div>
    <?php endif; ?>
  </div>
</div>

<script>
function copiar(){
  var t = document.getElementById('ia-texto');
  t.select();
  navigator.clipboard.writeText(t.value).then(function(){
    alert('Copiado. Ya puedes pegarlo donde quieras.');
  }, function(){ document.execCommand('copy'); });
}
function enviarWa(){
  var txt = encodeURIComponent(document.getElementById('ia-texto').value);
  window.open('https://wa.me/<?= $telWa ?>?text=' + txt, '_blank');
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
