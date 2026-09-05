<?php
// modules/ot/notas.php
// Alta y baja de notas de OT (visibles o no al cliente en el portal).
// Endpoint independiente para NO interferir con el guardado principal de la OT.
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';
requireLogin();
// Si tienes el módulo de permisos, descomenta la línea siguiente:
// requierePermiso('ot');

$db   = getDB();
$user = currentUser();
$id   = (int)($_POST['ot_id'] ?? $_GET['ot_id'] ?? 0);
$back = BASE_URL . 'modules/ot/editar.php?id=' . $id . '#notas';

if ($id <= 0) { redirect(BASE_URL . 'modules/ot/index.php'); }

// Agregar nota
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'agregar') {
    $texto   = trim($_POST['nota'] ?? '');
    $visible = isset($_POST['visible_cliente']) ? 1 : 0;
    if ($texto === '') {
        setFlash('warning', 'La nota está vacía.');
        redirect($back);
    }
    try {
        $db->prepare("INSERT INTO notas_ot (ot_id, usuario_id, nota, visible_cliente) VALUES (?,?,?,?)")
           ->execute([$id, (int)$user['id'], $texto, $visible]);
        setFlash('success', $visible ? 'Nota agregada (visible para el cliente).' : 'Nota interna agregada.');
    } catch (\Throwable $e) {
        setFlash('danger', 'No se pudo guardar la nota. ¿Ejecutaste la migración notas_ot.sql?');
    }
    redirect($back);
}

// Cambiar visibilidad
if (($_GET['toggle'] ?? '') !== '') {
    $nid = (int)$_GET['toggle'];
    try {
        $db->prepare("UPDATE notas_ot SET visible_cliente = 1 - visible_cliente WHERE id=? AND ot_id=?")->execute([$nid, $id]);
        setFlash('success', 'Visibilidad de la nota actualizada.');
    } catch (\Throwable $e) { setFlash('danger', 'No se pudo actualizar la nota.'); }
    redirect($back);
}

// Eliminar nota
if (($_GET['eliminar'] ?? '') !== '') {
    $nid = (int)$_GET['eliminar'];
    try {
        $db->prepare("DELETE FROM notas_ot WHERE id=? AND ot_id=?")->execute([$nid, $id]);
        setFlash('success', 'Nota eliminada.');
    } catch (\Throwable $e) { setFlash('danger', 'No se pudo eliminar la nota.'); }
    redirect($back);
}

redirect($back);
