<?php
// includes/clientes.php — Consistencia del documento de identidad del cliente
//
// El sistema guarda el documento en `clientes.ruc_dni`. Una migracion
// posterior (actualizacion_servidor_facturacion.sql) agrego `num_doc`,
// `tipo_doc` y `razon_social` para SUNAT, hizo un backfill de una sola vez
// y ahi quedo: ningun formulario escribe esas columnas.
//
// Consecuencia: SunatBuilder lee `num_doc`, lo encuentra vacio y rechaza
// toda factura, tenga el cliente RUC o no.
//
// Esta funcion se llama despues de crear o editar un cliente para que las
// dos representaciones no se separen otra vez.

/**
 * Sincroniza `num_doc` y `tipo_doc` a partir del documento cargado.
 *
 * El tipo se deduce de la longitud, que es lo que SUNAT valida:
 * 11 digitos = RUC, 8 = DNI. Cualquier otra longitud se deja como estaba
 * para no inventar un tipo sobre un dato que probablemente este mal.
 */
function sincronizarDocumentoCliente(PDO $db, int $clienteId): void {
    if ($clienteId <= 0) return;

    $st = $db->prepare("SELECT ruc_dni, num_doc, tipo_doc, nombre, razon_social FROM clientes WHERE id = ?");
    $st->execute([$clienteId]);
    $c = $st->fetch();
    if (!$c) return;

    $doc = trim((string)($c['ruc_dni'] ?? ''));
    if ($doc === '') $doc = trim((string)($c['num_doc'] ?? ''));
    $doc = preg_replace('/\D/', '', $doc);

    $tipo = strlen($doc) === 11 ? 'ruc'
          : (strlen($doc) === 8 ? 'dni' : ($c['tipo_doc'] ?: 'dni'));

    // La razon social solo se completa si esta vacia: si alguien la cargo
    // a mano con el nombre legal correcto, no se pisa.
    $razon = trim((string)($c['razon_social'] ?? ''));
    if ($razon === '' && $tipo === 'ruc') {
        $razon = trim((string)($c['nombre'] ?? ''));
    }

    $db->prepare("UPDATE clientes SET num_doc = ?, tipo_doc = ?, razon_social = ? WHERE id = ?")
       ->execute([$doc, $tipo, $razon, $clienteId]);
}
