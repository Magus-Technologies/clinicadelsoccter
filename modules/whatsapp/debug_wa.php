<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';

$db = getDB();
$plantillas = $db->query("SELECT id, nombre, categoria, texto FROM wa_plantillas WHERE activo=1")->fetchAll();
$clientes   = $db->query("SELECT id, nombre, COALESCE(whatsapp,telefono,'') AS wa FROM clientes WHERE activo=1 LIMIT 5")->fetchAll();

echo "<pre>";
echo "=== PLANTILLAS (" . count($plantillas) . ") ===\n";
foreach ($plantillas as $p) {
    echo "ID:{$p['id']} CAT:{$p['categoria']} NOMBRE: " . bin2hex($p['nombre']) . "\n";
    echo "TEXTO bytes: " . strlen($p['texto']) . " — hex primeros 40: " . bin2hex(substr($p['texto'],0,20)) . "\n";
    echo "TEXTO: " . substr($p['texto'],0,80) . "\n\n";
}

echo "\n=== TEST JSON ===\n";
$arr = array_map(fn($p) => ['id'=>(int)$p['id'],'nombre'=>$p['nombre'],'categoria'=>$p['categoria'],'texto'=>$p['texto']], $plantillas);
$json = json_encode($arr, JSON_UNESCAPED_UNICODE);
echo "json_encode result: " . ($json === false ? 'FALSE - ERROR: '.json_last_error_msg() : 'OK len='.strlen($json)) . "\n";
echo "base64: " . base64_encode($json ?: '[]') . "\n";
echo "\n=== TEST CLIENTES ===\n";
$carr = array_map(fn($c) => ['id'=>(int)$c['id'],'nombre'=>$c['nombre'],'wa'=>$c['wa']], $clientes);
$cjson = json_encode($carr, JSON_UNESCAPED_UNICODE);
echo "clientes json: " . ($cjson === false ? 'FALSE - '.json_last_error_msg() : 'OK len='.strlen($cjson)) . "\n";
echo "</pre>";
