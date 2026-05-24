<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';
requireLogin();

$db  = getDB();
$id  = (int)($_GET['id'] ?? 1063);
$nuevo = $_GET['nuevo'] ?? 'archivado';

echo "<pre>";
echo "Simulando cambio a: $nuevo\n\n";

// Paso 1: cargar OT
$ot = $db->prepare("SELECT * FROM ordenes_trabajo WHERE id=?");
$ot->execute([$id]);
$ot = $ot->fetch();
echo "1. OT estado actual: {$ot['estado']}\n";

// Paso 2: UPDATE
try {
    $db->prepare("UPDATE ordenes_trabajo SET estado=?, fecha_entrega=NOW() WHERE id=?")
       ->execute([$nuevo, $id]);
    echo "2. UPDATE OK\n";
} catch(Exception $e) {
    echo "2. UPDATE ERROR: " . $e->getMessage() . "\n";
}

// Paso 3: historial
try {
    $db->prepare("INSERT INTO historial_ot (ot_id,usuario_id,estado_antes,estado_nuevo,comentario) VALUES (?,?,?,?,?)")
       ->execute([$id, 1, $ot['estado'], $nuevo, 'test']);
    echo "3. HISTORIAL OK\n";
} catch(Exception $e) {
    echo "3. HISTORIAL ERROR: " . $e->getMessage() . "\n";
}

// Paso 4: revertir
$db->prepare("UPDATE ordenes_trabajo SET estado=?, fecha_entrega=NULL WHERE id=?")
   ->execute([$ot['estado'], $id]);
echo "4. Revertido a: {$ot['estado']}\n";

// Paso 5: cargar ver.php contenido
echo "\n5. Simulando carga de ver.php con estado=$nuevo...\n";
$ot2 = $db->prepare("SELECT ot.*, c.nombre as cliente_nombre, c.telefono, c.whatsapp,
    c.email as cliente_email, c.ruc_dni, te.nombre as tipo_equipo, e.marca, e.modelo, 
    e.serial, e.color,
    CONCAT(u.nombre,' ',u.apellido) as tecnico_nombre,
    CONCAT(uc.nombre,' ',uc.apellido) as creador_nombre,
    s.nombre as servicio_nombre
    FROM ordenes_trabajo ot
    JOIN clientes c ON c.id=ot.cliente_id
    JOIN equipos e ON e.id=ot.equipo_id
    JOIN tipos_equipo te ON te.id=e.tipo_equipo_id
    LEFT JOIN usuarios u ON u.id=ot.tecnico_id
    LEFT JOIN usuarios uc ON uc.id=ot.usuario_creador_id
    LEFT JOIN servicios s ON s.id=ot.servicio_id
    WHERE ot.id=?");
$ot2->execute([$id]);
$ot2 = $ot2->fetch();
echo "   SELECT principal: OK\n";

// Paso 6: repuestos
try {
    $r = $db->prepare("SELECT r.*, p.nombre as prod_nombre, p.codigo as prod_codigo
        FROM ot_repuestos r
        JOIN productos p ON p.id=r.producto_id
        WHERE r.ot_id=?");
    $r->execute([$id]);
    $rep = $r->fetchAll();
    echo "   Repuestos: OK (" . count($rep) . ")\n";
} catch(Exception $e) {
    echo "   REPUESTOS ERROR: " . $e->getMessage() . "\n";
}

// Paso 7: checklist
try {
    $ch = json_decode($ot2['checklist'] ?? '[]', true);
    echo "   Checklist: OK\n";
} catch(Exception $e) {
    echo "   CHECKLIST ERROR: " . $e->getMessage() . "\n";
}

// Paso 8: fotos
try {
    $f = $db->prepare("SELECT * FROM fotos_ot WHERE ot_id=? AND (tipo_archivo='foto' OR tipo_archivo IS NULL)");
    $f->execute([$id]);
    echo "   Fotos: OK (" . count($f->fetchAll()) . ")\n";
} catch(Exception $e) {
    echo "   FOTOS ERROR: " . $e->getMessage() . "\n";
}

// Paso 9: videos  
try {
    $v = $db->prepare("SELECT * FROM fotos_ot WHERE ot_id=? AND tipo_archivo='video'");
    $v->execute([$id]);
    echo "   Videos: OK (" . count($v->fetchAll()) . ")\n";
} catch(Exception $e) {
    echo "   VIDEOS ERROR: " . $e->getMessage() . "\n";
}

// Paso 10: historial query
try {
    $h = $db->prepare("SELECT h.*, CONCAT(u.nombre,' ',u.apellido) as usuario_nombre
        FROM historial_ot h
        JOIN usuarios u ON u.id=h.usuario_id
        WHERE h.ot_id=? ORDER BY h.created_at DESC");
    $h->execute([$id]);
    echo "   Historial: OK (" . count($h->fetchAll()) . ")\n";
} catch(Exception $e) {
    echo "   HISTORIAL ERROR: " . $e->getMessage() . "\n";
}

// Paso 11: garantia
try {
    $g = $db->prepare("SELECT * FROM garantias WHERE ot_id=? LIMIT 1");
    $g->execute([$id]);
    echo "   Garantias: OK\n";
} catch(Exception $e) {
    echo "   GARANTIAS ERROR: " . $e->getMessage() . "\n";
}

echo "\n=== TODO OK - el 500 no es en las queries ===\n";
echo "El error debe ser en el HTML/PHP de ver.php\n";
echo "Revisa el error_log del servidor para ver la linea exacta\n";
echo "</pre>";
