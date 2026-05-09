<?php
// api_servicio.php — Devuelve datos de un servicio + sus repuestos precargados
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';
header('Content-Type: application/json');
if (!isLoggedIn()) { echo json_encode(['error' => 'No autorizado']); exit; }

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { echo json_encode(['error' => 'ID inválido']); exit; }

$stmt = $db->prepare("SELECT * FROM servicios WHERE id=?");
$stmt->execute([$id]);
$servicio = $stmt->fetch();
if (!$servicio) { echo json_encode(['error' => 'Servicio no encontrado']); exit; }

// Repuestos precargados
$stmt2 = $db->prepare("
    SELECT sr.producto_id, sr.cantidad, sr.precio_referencial, p.nombre, p.codigo
    FROM servicio_repuestos sr
    JOIN productos p ON p.id = sr.producto_id
    WHERE sr.servicio_id = ?
");
$stmt2->execute([$id]);
$repuestos = $stmt2->fetchAll();

echo json_encode([
    'ok'         => true,
    'nombre'     => $servicio['nombre'],
    'precio'     => $servicio['precio'],
    'garantia'   => $servicio['garantia_dias'],
    'requiere'   => (bool)$servicio['requiere_repuestos'],
    'repuestos'  => $repuestos,
]);
