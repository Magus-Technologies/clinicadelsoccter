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
            $msg = $gen['mensaje'] ?? 'Error al generar XML.';
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
            $msg = $env['mensaje'] ?? 'Error al enviar a SUNAT.';
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

    private function fetchVenta(int $id): ?array
    {
        $st = $this->db->prepare("SELECT * FROM ventas WHERE id=?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    private function fetchCliente(int $id): array
    {
        if ($id <= 0) return ['nombre' => 'PUBLICO GENERAL', 'tipo_doc' => 'dni', 'num_doc' => '', 'razon_social' => ''];
        $st = $this->db->prepare("SELECT * FROM clientes WHERE id=?");
        $st->execute([$id]);
        return $st->fetch() ?: ['nombre' => 'PUBLICO GENERAL', 'tipo_doc' => 'dni', 'num_doc' => '', 'razon_social' => ''];
    }

    private function fetchItems(int $ventaId): array
    {
        $st = $this->db->prepare("
            SELECT vd.*, p.codigo
            FROM venta_detalle vd
            LEFT JOIN productos p ON vd.producto_id = p.id
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