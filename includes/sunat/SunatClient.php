<?php
/**
 * SunatClient — Cliente HTTP para la API de SUNAT (api-sunat-laravel).
 *
 * Endpoints:
 *  POST /generar/comprobante  → genera XML firmado
 *  POST /enviar/documento/electronico → envía XML a SUNAT
 */
require_once __DIR__ . '/../../config/sunat.php';

class SunatClient
{
    private string $baseUrl;
    private int    $timeout;

    public function __construct(?string $baseUrl = null, ?int $timeout = null)
    {
        $this->baseUrl = $baseUrl ?? SUNAT_API_URL;
        $this->timeout = $timeout ?? SUNAT_API_TIMEOUT;
    }

    /**
     * Genera el XML de un comprobante (factura/boleta).
     * Payload: see SunatBuilder::buildComprobante()
     */
    public function generarComprobante(array $payload): array
    {
        return $this->post('/generar/comprobante', $payload);
    }

    /**
     * Envía un XML a SUNAT.
     * Payload: [
     *   'ruc' => string,
     *   'usuario' => string,
     *   'clave' => string,
     *   'endpoint' => 'beta'|'produccion',
     *   'nombre_documento' => string,
     *   'contenido_documento' => string (XML)
     * ]
     */
    public function enviarDocumento(array $payload): array
    {
        return $this->post('/enviar/documento/electronico', $payload);
    }

    private function post(string $endpoint, array $data): array
    {
        $url = $this->baseUrl . $endpoint;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST          => true,
            CURLOPT_POSTFIELDS    => json_encode($data),
            CURLOPT_RETURNTRANSFER=> true,
            CURLOPT_TIMEOUT       => $this->timeout,
            CURLOPT_HTTPHEADER    => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return [
                'estado'  => false,
                'mensaje' => 'Error de conexión: ' . $error,
            ];
        }

        $decoded = json_decode($response, true);
        if ($decoded === null) {
            return [
                'estado'  => false,
                'mensaje' => 'Respuesta inválida del servidor: ' . substr($response, 0, 200),
            ];
        }

        return $decoded;
    }
}