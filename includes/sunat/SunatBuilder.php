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
        // Para RUC, SUNAT espera la razon social; si no se cargo se usa el nombre.
        $rzSocial = trim((string)($cliente['razon_social'] ?? ''));
        if ($rzSocial === '') {
            $rzSocial = trim(($cliente['nombre'] ?? '') . ' ' . ($cliente['apellido'] ?? ''));
        }
        $rzSocial = preg_replace('/\s+/', ' ', $rzSocial) ?: 'CLIENTE';

        if ($tipoDoc === '01') {
            // Factura → requiere RUC
            $numDocCliente = preg_replace('/\D/', '', (string)($cliente['num_doc'] ?? ''));

            // Los mensajes dicen qué se recibió: un error de datos del
            // cliente no debe parecer una falla del servicio SUNAT.
            if (strlen($numDocCliente) !== 11) {
                throw new RuntimeException(sprintf(
                    'La factura requiere un RUC de 11 dígitos. El cliente "%s" tiene "%s" (%d dígitos). '
                    . 'Corregí el documento del cliente o emití una boleta.',
                    $rzSocial,
                    $numDocCliente !== '' ? $numDocCliente : '(vacío)',
                    strlen($numDocCliente)
                ));
            }
            // Un RUC peruano siempre empieza en 10, 15, 17 o 20. Un número
            // de 11 dígitos con otro prefijo suele ser carné de extranjería
            // o un error de tipeo, y SUNAT lo rechaza con un error opaco.
            if (!in_array(substr($numDocCliente, 0, 2), ['10', '15', '17', '20'], true)) {
                throw new RuntimeException(sprintf(
                    'El documento "%s" del cliente "%s" tiene 11 dígitos pero no es un RUC válido '
                    . '(debe empezar en 10, 15, 17 o 20). Verificá el dato o emití una boleta.',
                    $numDocCliente,
                    $rzSocial
                ));
            }
            $tipoDocCliente = '6';
        } else {
            // Boleta → usa DNI o "varios"
            $numDocCliente = preg_replace('/\D/', '', (string)($cliente['num_doc'] ?? ''));
            if (strlen($numDocCliente) === 8) {
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
        // Una sola fuente de verdad para las credenciales: sunat_credenciales()
        // ya resuelve los valores de prueba segun el modo (beta / produccion).
        $cred = sunat_credenciales();
        return [
            'ruc'             => $cred['ruc'],
            'usuario'         => $cred['usuario'],
            'clave'           => $cred['clave'],
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
                // `codigo` es el código real del producto o de la OT.
                // Antes se enviaba `id`, que es el autonumérico de la fila
                // de venta_detalle y no identifica nada para SUNAT.
                'cod_producto' => (string)($it['codigo'] ?? $it['id'] ?? ($i + 1)),
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
