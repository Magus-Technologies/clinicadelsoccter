<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';
requireLogin();

$db = getDB();
$id = (int)($_GET['id'] ?? 0);

echo "<pre style='font-size:12px;padding:20px'>";
echo "=== DEBUG VER OT id=$id ===\n\n";

// Test 1: OT query
echo "1. Cargando OT...\n";
try {
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
      WHERE ot.id = ?");
    $ot->execute([$id]);
    $ot = $ot->fetch();
    if ($ot) {
        echo "   OK - estado: " . $ot['estado'] . "\n";
    } else {
        echo "   OT no encontrada\n";
    }
} catch(Exception $e) {
    echo "   ERROR: " . $e->getMessage() . "\n";
}

// Test 2: ESTADOS_OT
echo "\n2. ESTADOS_OT:\n";
echo "   Tipo: " . gettype(ESTADOS_OT) . "\n";
echo "   Claves: " . implode(', ', array_keys(ESTADOS_OT)) . "\n";
if ($ot) {
    echo "   Estado OT: " . $ot['estado'] . "\n";
    echo "   Existe en ESTADOS_OT: " . (isset(ESTADOS_OT[$ot['estado']]) ? 'SI' : 'NO - AQUI FALLA') . "\n";
}

// Test 3: estados_ot table
echo "\n3. Tabla estados_ot:\n";
try {
    $rows = $db->query("SELECT clave, label, es_final FROM estados_ot ORDER BY orden")->fetchAll();
    foreach($rows as $r) {
        echo "   clave='{$r['clave']}' label='{$r['label']}' es_final={$r['es_final']}\n";
    }
} catch(Exception $e) {
    echo "   ERROR: " . $e->getMessage() . "\n";
}

// Test 4: fotos_ot
echo "\n4. Fotos/Videos OT:\n";
try {
    $cols = array_column($db->query("SHOW COLUMNS FROM fotos_ot")->fetchAll(), 'Field');
    echo "   Columnas: " . implode(', ', $cols) . "\n";
    $f = $db->prepare("SELECT id,tipo_archivo,ruta FROM fotos_ot WHERE ot_id=? LIMIT 10");
    $f->execute([$id]);
    foreach($f->fetchAll() as $r) {
        echo "   id={$r['id']} tipo={$r['tipo_archivo']} ruta={$r['ruta']}\n";
    }
} catch(Exception $e) {
    echo "   ERROR: " . $e->getMessage() . "\n";
}

// Test 5: repuestos
echo "\n5. Tabla ot_repuestos:\n";
try {
    $cols = array_column($db->query("SHOW COLUMNS FROM ot_repuestos")->fetchAll(), 'Field');
    echo "   Columnas: " . implode(', ', $cols) . "\n";
} catch(Exception $e) {
    echo "   ERROR: " . $e->getMessage() . "\n";
}

// Test 6: historial
echo "\n6. Tabla historial_ot:\n";
try {
    $h = $db->prepare("SELECT id,estado_antes,estado_nuevo FROM historial_ot WHERE ot_id=? LIMIT 5");
    $h->execute([$id]);
    foreach($h->fetchAll() as $r) {
        echo "   {$r['estado_antes']} -> {$r['estado_nuevo']}\n";
    }
} catch(Exception $e) {
    echo "   ERROR: " . $e->getMessage() . "\n";
}

echo "\n=== FIN DEBUG ===\n";
echo "</pre>";
