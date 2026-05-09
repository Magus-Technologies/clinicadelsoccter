<?php
/**
 * SunatBuilder — Construye el payload para la API SUNAT.
 *
 * Genera el array estructurado que recibe la API api-sunat-laravel
 * en el endpoint POST /generar/comprobante
 */
require_once __DIR__ . '/../../config/sunat.php';

class SunatBuilder
{
    /**
     * Construye el payload para una factura o boleta.
     *
     * @param array $pago    Registro de la tabla ventas
     * @param array $cliente Registro del cliente (con tipo_doc, num_doc, razon_social)
     * @param array $items   Array de items del comprobante
     * @return array Payload listo para enviar a la API
     */
    public static function buildComprobante(array $pago, array $cliente, array $items): array
    {
        $emisor   = sunat_emisor();
        $modo     = sunat_modo();

        // Tipo documento: 01=Factura, 03=Boleta
        $tipoDoc  = ($pago['tipo_doc'] ?? 'boleta') === 'factura' ? '01' : '03';

        // Moneda
        $moneda   = $pago['moneda'] ?? 'PEN';

        // Fecha
        $fecha    = !empty($pago['fecha']) ? date('Y-m-d', strtotime($pago['fecha'])) : date('Y-m-d');

        // Subtotal (sin IGV)
        $subtotal = (float)($pago['subtotal'] ?? 0);
        $igv      = (float)($pago['igv'] ?? 0);
        $total    = (float)($pago['total'] ?? 0);
        $desc     = (float)($pago['descuento'] ?? 0);

        // Serie y número
        $serie    = $pago['serie'] ?? 'B001';
        $numero   = str_pad((string)($pago['numero'] ?? 1), 8, '0', STR_PAD_LEFT);

        // Cliente
        $tipoDocCliente = match (strtolower($cliente['tipo_doc'] ?? 'dni')) {
            'ruc'     => '6',
            'dni'    => '1',
            'carnet' => '4',
            default  => '1',
        };
        $numDocCliente  = $cliente['num_doc'] ?? '';
        $rzSocial       = ($tipoDoc === '01')
            ? ($cliente['razon_social'] ?: trim(($cliente['nombre'] ?? '')))
            : trim(($cliente['nombre'] ?? ''));

        // Detalles
        $detalles = [];
        foreach ($items as $item) {
            $cantidad     = (float)($item['cantidad'] ?? 1);
            $precioUnit   = (float)($item['precio_unit'] ?? $item['precio'] ?? 0);
            $baseIgv      = round($precioUnit / 1.18, 2);
            $igvItem      = round($precioUnit - $baseIgv, 2);
            $codProducto  = $item['codigo'] ?? ($item['producto_id'] ?? '');

            $detalles[] = [
                'cod_producto'      => (string)$codProducto,
                'descripcion'       => $item['concepto'] ?? $item['nombre'] ?? 'Producto',
                'cantidad'          => $cantidad,
                'unidad'            => $item['unidad'] ?? 'NIU',
                'mtoValorVenta'    => round($baseIgv * $cantidad, 2),
                'mtoValorUnitario' => $baseIgv,
                'mtoBaseIgv'       => round($baseIgv * $cantidad, 2),
                'porcentajeIgv'    => 18.0,
                'igv'              => round($igvItem * $cantidad, 2),
                'tipAfeIgv'        => '10',
                'totalImpuestos'   => round($igvItem * $cantidad, 2),
                'mtoPrecioUnitario'=> $precioUnit,
            ];
        }

        $payload = [
            'documento'      => ($tipoDoc === '01') ? 'factura' : 'boleta',
            'ublVersion'     => '2.1',
            'tipoOperacion'  => '0101',
            'tipoDoc'        => $tipoDoc,
            'serie'          => $serie,
            'numero'         => $numero,
            'fecha_emision'  => $fecha,
            'tipoMoneda'     => $moneda,
            'empresa'        => $emisor,
            'cliente'        => [
                'tipo_doc'   => $tipoDocCliente,
                'num_doc'    => $numDocCliente,
                'rzn_social' => $rzSocial,
            ],
            'detalles'       => $detalles,
            'mtoOperGravadas'=> round($subtotal, 2),
            'mtoIGV'         => round($igv, 2),
            'totalImpuestos' => round($igv, 2),
            'valorVenta'     => round($subtotal, 2),
            'subTotal'       => round($total, 2),
            'mtoImpVenta'    => round($total, 2),
            'formaPago'      => [
                'tipo' => 'contado',
            ],
            'endpoint'       => $modo,
        ];

        if ($desc > 0) {
            $payload['descuentosGlobales'] = [[
                'monto' => round($desc, 2),
            ]];
        }

        return $payload;
    }
}