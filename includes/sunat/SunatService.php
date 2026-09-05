<?php
/**
 * SunatService — Orchestra el flujo de facturación SUNAT en DOS pasos:
 *
 *   1) generarXml($ventaId)  → llama /generar/comprobante, guarda XML+hash+qr,
 *                               deja sunat_estado = 'pendiente'.
 *   2) enviarSunat($ventaId) → toma el XML guardado, llama /enviar/documento/electronico,
 *                               guarda CDR, deja sunat_estado = 'aceptado' | 'rechazado'.
 */
require_once __DIR__ . '/SunatClient.php';
require_once __DIR__ . '/SunatBuilder.php';
require_once __DIR__ . '/../logger.php';

class SunatService
{
    private PDO         $db;
    private SunatClient $client;

    public function __construct(?PDO $db = null, ?SunatClient $client = null)
    {
        $this->db     = $db ?? getDB();
        $this->client = $client ?? new SunatClient();
    }

    /**
     * PASO 1: Generar el XML del comprobante.
     */
    public function generarXml(int $ventaId): array
    {
        // A proposito NO se validan aca las credenciales SOL: cada
        // despliegue de la API exige campos distintos (el servidor de
        // produccion acepta las credenciales vacias porque las tiene
        // guardadas; el de desarrollo las pide en el payload). Duplicar esa
        // regla del lado nuestro bloquearia emisiones que hoy funcionan.
        // La API es la autoridad; describirError() traduce su respuesta.
        $venta = $this->fetchVenta($ventaId);
        if (!$venta) {
            return ['ok' => false, 'mensaje' => "Venta #$ventaId no encontrada."];
        }
        if (!in_array($venta['tipo_doc'], ['factura', 'boleta'], true)) {
            return ['ok' => false, 'mensaje' => "Tipo '{$venta['tipo_doc']}' no se envía a SUNAT."];
        }
        if (empty($venta['serie']) || empty($venta['numero'])) {
            return ['ok' => false, 'mensaje' => 'La venta no tiene serie/número asignados.'];
        }

        $cliente = $this->fetchCliente((int)($venta['cliente_id'] ?? 0));
        $items   = $this->fetchItems($ventaId);

        app_log('generarXml items fetched: '.count($items), 'DEBUG', [
            'venta_id' => $ventaId,
            'items' => $items,
        ]);

        try {
            $payload = SunatBuilder::buildComprobante($venta, $cliente, $items);
        } catch (Throwable $e) {
            $this->marcarRechazada($ventaId, $e->getMessage());
            return ['ok' => false, 'mensaje' => $e->getMessage()];
        }

        app_log('generarXml payload: '.json_encode($payload, JSON_UNESCAPED_UNICODE), 'DEBUG', [
            'venta_id' => $ventaId,
            'detalles_count' => count($payload['detalles'] ?? []),
        ]);

        $gen = $this->client->generarComprobante($payload);
        if (empty($gen['estado'])) {
            $msg = self::describirError($gen);
            $this->marcarRechazada($ventaId, $msg);
            return ['ok' => false, 'mensaje' => $msg, 'detalle' => $gen];
        }

        $hash   = $gen['data']['hash']          ?? '';
        $qrInfo = $gen['data']['qr_info']       ?? '';
        $xml    = $gen['data']['contenido_xml'] ?? '';

        $this->marcarPendiente($ventaId, $hash, $qrInfo, $xml);

        return [
            'ok'      => true,
            'mensaje' => 'XML generado. Listo para enviar a SUNAT.',
            'hash'    => $hash,
            'qr'      => $qrInfo,
        ];
    }

    /**
     * PASO 2: Enviar el XML a SUNAT.
     */
    public function enviarSunat(int $ventaId): array
    {
        $venta = $this->fetchVenta($ventaId);
        if (!$venta) {
            return ['ok' => false, 'mensaje' => "Venta #$ventaId no encontrada."];
        }
        if (empty($venta['sunat_xml'])) {
            return ['ok' => false, 'mensaje' => 'Esta venta no tiene XML generado.'];
        }
        if ($venta['sunat_estado'] === 'aceptado') {
            return ['ok' => false, 'mensaje' => 'Esta venta ya fue aceptada por SUNAT.'];
        }

        $creds   = sunat_credenciales();
        $modo    = sunat_modo();
        $nombre  = self::nombreArchivo($venta);

        $env = $this->client->enviarDocumento([
            'ruc'                 => $creds['ruc'],
            'usuario'             => $creds['usuario'],
            'clave'               => $creds['clave'],
            'endpoint'            => $modo,
            'nombre_documento'    => $nombre,
            'contenido_documento' => $venta['sunat_xml'],
        ]);

        if (empty($env['estado'])) {
            $msg = self::describirError($env);
            $this->marcarRechazada($ventaId, $msg);
            return ['ok' => false, 'mensaje' => $msg, 'detalle' => $env];
        }

        $cdr = $env['cdr'] ?? '';
        $this->marcarAceptada($ventaId, $cdr);

        return [
            'ok'       => true,
            'mensaje'  => 'SUNAT aceptó el comprobante.',
            'cdr'      => $cdr,
            'nombre'   => 'R-' . $nombre . '.zip',
        ];
    }

    /**
     * Nombre del archivo SUNAT: {RUC}-{TIPO}-{SERIE}-{NUMERO_8}.
     */
    public static function nombreArchivo(array $venta): string
    {
        $ruc  = sunat_emisor()['ruc'] ?? '00000000000';
        $tipo = match ($venta['tipo_doc'] ?? '') {
            'factura' => '01',
            'boleta'  => '03',
            default   => '00',
        };
        $serie  = $venta['serie']  ?? 'B001';
        $numero = str_pad((string)($venta['numero'] ?? '1'), 8, '0', STR_PAD_LEFT);
        return $ruc . '-' . $tipo . '-' . $serie . '-' . $numero;
    }

    // ─── Métodos privados ───────────────────────────────────────

    /**
     * Convierte la respuesta de error de la API en un mensaje accionable.
     *
     * La API responde un titulo generico ("Los datos enviados no son
     * validos.") y aparte un objeto `errores` con el detalle campo por
     * campo. Mostrar solo el titulo deja al usuario sin saber que corregir.
     */
    public static function describirError(array $respuesta): string
    {
        $titulo = trim((string)($respuesta['mensaje'] ?? '')) ?: 'Error al generar el XML.';

        $errores = $respuesta['errores'] ?? ($respuesta['detalle']['errores'] ?? null);
        if (!is_array($errores) || !$errores) {
            return $titulo;
        }

        // Nombres tal como los ve el usuario en la pantalla de Configuración.
        $etiquetas = [
            'empresa.usuario'      => 'Usuario SOL',
            'empresa.clave'        => 'Contraseña SOL',
            'empresa.ruc'          => 'RUC de la empresa',
            'empresa.razon_social' => 'Razón social',
            'empresa.direccion'    => 'Dirección de la empresa',
            'cliente.num_doc'      => 'Documento del cliente',
            'cliente.rzn_social'   => 'Nombre / razón social del cliente',
            'cliente.direccion'    => 'Dirección del cliente',
            'serie'                => 'Serie del comprobante',
            'numero'               => 'Número del comprobante',
            'detalles'             => 'Detalle del comprobante',
        ];

        $lineas = [];
        foreach ($errores as $campo => $mensajes) {
            $texto = is_array($mensajes) ? implode(' ', $mensajes) : (string)$mensajes;
            $nombre = $etiquetas[$campo] ?? $campo;
            $lineas[] = $nombre . ': ' . $texto;
        }

        return $titulo . ' — ' . implode(' | ', $lineas);
    }

    private function fetchVenta(int $id): ?array
    {
        $st = $this->db->prepare("SELECT * FROM ventas WHERE id=?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    private function fetchCliente(int $id): array
    {
        $vacio = ['nombre' => 'PUBLICO GENERAL', 'tipo_doc' => 'dni', 'num_doc' => '', 'razon_social' => ''];
        if ($id <= 0) return $vacio;

        $st = $this->db->prepare("SELECT * FROM clientes WHERE id=?");
        $st->execute([$id]);
        $cliente = $st->fetch();
        if (!$cliente) return $vacio;

        return self::normalizarDocumento($cliente);
    }

    /**
     * Resuelve el documento de identidad del cliente.
     *
     * El sistema guarda el documento en `ruc_dni`, pero una migracion
     * posterior agrego `num_doc` y `tipo_doc` para SUNAT sin actualizar
     * los formularios que crean clientes. Resultado: `num_doc` quedo vacio
     * en casi todos, y `tipo_doc` quedo en 'dni' incluso para RUCs.
     *
     * Aca se toma el documento de donde efectivamente este, y el tipo se
     * deduce de su longitud, que es lo que SUNAT valida.
     */
    public static function normalizarDocumento(array $cliente): array
    {
        $doc = trim((string)($cliente['num_doc'] ?? ''));
        if ($doc === '') $doc = trim((string)($cliente['ruc_dni'] ?? ''));
        $doc = preg_replace('/\D/', '', $doc); // SUNAT solo acepta digitos

        $cliente['num_doc']  = $doc;
        $cliente['tipo_doc'] = strlen($doc) === 11 ? 'ruc'
                             : (strlen($doc) === 8 ? 'dni'
                             : ($cliente['tipo_doc'] ?? 'dni'));

        // Para facturas SUNAT espera la razon social; si no se cargo, el
        // nombre del cliente es el mejor dato disponible.
        if (trim((string)($cliente['razon_social'] ?? '')) === '') {
            $cliente['razon_social'] = trim((string)($cliente['nombre'] ?? ''));
        }

        return $cliente;
    }

    private function fetchItems(int $ventaId): array
    {
        // Una línea puede ser un producto de inventario o una orden de
        // trabajo. `nombre` y `codigo` se resuelven según cuál sea.
        $st = $this->db->prepare("
            SELECT vd.*,
                   COALESCE(p.codigo, o.codigo_ot)              AS codigo,
                   COALESCE(vd.concepto, p.nombre, o.codigo_ot) AS nombre
            FROM venta_detalle vd
            LEFT JOIN productos       p ON vd.producto_id = p.id
            LEFT JOIN ordenes_trabajo o ON vd.ot_id       = o.id
            WHERE vd.venta_id = ?
        ");
        $st->execute([$ventaId]);
        return $st->fetchAll();
    }

    private function marcarPendiente(int $ventaId, string $hash, string $qr, string $xml): void
    {
        $this->db->prepare("
            UPDATE ventas SET
                sunat_xml    = ?,
                sunat_hash   = ?,
                sunat_qr     = ?,
                sunat_estado = 'pendiente'
            WHERE id = ?
        ")->execute([$xml, $hash, $qr, $ventaId]);
    }

    private function marcarRechazada(int $ventaId, string $mensaje): void
    {
        $this->db->prepare("
            UPDATE ventas SET sunat_estado = 'rechazado', sunat_mensaje = ? WHERE id = ?
        ")->execute([$mensaje, $ventaId]);
    }

    private function marcarAceptada(int $ventaId, string $cdr): void
    {
        $this->db->prepare("
            UPDATE ventas SET
                sunat_estado     = 'aceptado',
                sunat_cdr        = ?,
                sunat_enviado_at = NOW(),
                sunat_aceptado_at = NOW()
            WHERE id = ?
        ")->execute([$cdr, $ventaId]);
    }
}