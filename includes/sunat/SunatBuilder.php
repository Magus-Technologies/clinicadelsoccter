<?php
/**
 * SunatBuilder — Construye el payload para la API SUNAT.
 *
 * COPIADO de DentalSys para mantener compatibilidad con api-sunat-laravel.
 */
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
        $emisor   = self::empresa();
        $modo     = sunat_modo();

        // Tipo documento: 01=Factura, 03=Boleta
        $tipoDoc  = ($pago['tipo_doc'] ?? 'boleta') === 'factura' ? '01' : '03';
        $documento = $tipoDoc === '01' ? 'factura' : 'boleta';

        // Fecha
        $fecha = !empty($pago['fecha']) ? date('Y-m-d', strtotime($pago['fecha'])) : date('Y-m-d');

        // Serie y número
        $serie  = $pago['serie'] ?? 'B001';
        $numero = (string)($pago['numero'] ?? 1);

        // Cliente - facturas requieren RUC, boletas usan DNI o "varios"
        $rzSocial = trim(($cliente['nombre'] ?? '') . ' ' . ($cliente['apellido'] ?? ''));
        $rzSocial = preg_replace('/\s+/', ' ', $rzSocial) ?: 'CLIENTE';

        if ($tipoDoc === '01') {
            // Factura → requiere RUC
            $numDocCliente = $cliente['num_doc'] ?? '';
            if (empty($numDocCliente) || strlen($numDocCliente) !== 11) {
                throw new RuntimeException("La factura requiere RUC válido (11 dígitos).");
            }
            $tipoDocCliente = '6';
        } else {
            // Boleta → usa DNI o "varios"
            $numDocCliente = $cliente['num_doc'] ?? '';
            if (!empty($numDocCliente) && strlen($numDocCliente) === 8) {
                $tipoDocCliente = '1';
            } else {
                $tipoDocCliente = '0';
                $numDocCliente = '00000000';
                $rzSocial = $rzSocial ?: 'CLIENTE VARIOS';
            }
        }

        return [
            'endpoint'      => $modo,
            'documento'     => $documento,
            'empresa'       => $emisor,
            'cliente'       => [
                'tipo_doc'   => $tipoDocCliente,
                'num_doc'    => $numDocCliente,
                'rzn_social' => $rzSocial,
                'direccion'  => $cliente['direccion'] ?? '-',
            ],
            'serie'         => $serie,
            'numero'        => $numero,
            'fecha_emision' => $fecha,
            'moneda'        => 'PEN',
            'forma_pago'    => 'contado',
            'detalles'      => self::detalles($items),
            'aplica_igv'    => true,
        ];
    }

    /**
     * Datos del emisor desde la configuración SUNAT.
     */
    private static function empresa(): array
    {
        return [
            'ruc'             => sunat_get_config('empresa_ruc', '20000000001'),
            'usuario'         => sunat_get_config('sunat_usuario_sol', 'MODDATOS'),
            'clave'           => sunat_get_config('sunat_clave_sol', 'MODDATOS'),
            'razon_social'    => sunat_get_config('empresa_nombre', 'EMPRESA DE PRUEBAS S.A.C.'),
            'nombreComercial' => sunat_get_config('empresa_nombre', 'DentalSys'),
            'direccion'       => sunat_get_config('empresa_direccion', 'AV. PRUEBA 123'),
            'ubigueo'         => '150101',
            'distrito'        => 'LIMA',
            'provincia'       => 'LIMA',
            'departamento'   => 'LIMA',
        ];
    }

    /**
     * Detalles del comprobante.
     * El precio se envía CON IGV incluido (el servidor Greenter divide /1.18 internamente).
     */
    private static function detalles(array $items): array
    {
        $out = [];
        foreach ($items as $i => $it) {
            $out[] = [
                'cod_producto' => (string)($it['id'] ?? ($i + 1)),
                'unidad'       => 'NIU',
                'descripcion'  => $it['concepto'] ?? $it['nombre'] ?? 'Producto',
                'cantidad'     => (float)($it['cantidad'] ?? 1),
                'precio'       => (float)($it['precio'] ?? $it['precio_unit'] ?? 0),
                'tipo_igv'     => 'gravado',
            ];
        }
        return $out;
    }
}
