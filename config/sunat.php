<?php
/**
 * config/sunat.php — Configuración SUNAT
 *
 * Auto-detecta entorno (LOCAL vs PRODUCCIÓN) por hostname.
 * Datos leídos de la tabla `configuracion`.
 */

$__host = $_SERVER['HTTP_HOST'] ?? gethostname();
$__isLocal = (
    str_contains($__host, 'localhost') ||
    str_contains($__host, '127.0.0.1') ||
    str_contains($__host, '.test')     ||
    str_contains($__host, '.local')
);

if ($__isLocal) {
    define('SUNAT_API_URL', 'http://api-sunat-laravel.test/api/v1');
} else {
    define('SUNAT_API_URL', 'http://84.247.162.204/api-sunat-laravel/api/v1');
}

define('SUNAT_API_TIMEOUT', 60);

/**
 * Obtener config de la tabla configuracion (key-value).
 */
function sunat_get_config(string $key, string $default = ''): string {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            $db = getDB();
            foreach ($db->query("SELECT clave, valor FROM configuracion") as $r) {
                $cache[$r['clave']] = $r['valor'];
            }
        } catch (Throwable $e) { }
    }
    return $cache[$key] ?? $default;
}

/**
 * Modo SUNAT: beta o produccion.
 */
function sunat_modo(): string {
    return sunat_get_config('sunat_modo', 'beta') === 'produccion' ? 'produccion' : 'beta';
}

/**
 * Credenciales SOL.
 */
function sunat_credenciales(): array {
    return [
        'ruc'     => sunat_get_config('empresa_ruc', ''),
        'usuario' => sunat_get_config('sunat_usuario_sol', 'MODDATOS'),
        'clave'   => sunat_get_config('sunat_clave_sol', 'MODDATOS'),
    ];
}

/**
 * Datos del emisor para el XML.
 */
function sunat_emisor(): array {
    return [
        'ruc'              => sunat_get_config('empresa_ruc', ''),
        'razon_social'    => sunat_get_config('empresa_nombre', ''),
        'nombre_comercial'=> sunat_get_config('empresa_nombre', ''),
        'direccion'       => sunat_get_config('empresa_direccion', ''),
        'ubigeo'          => '150101',
        'distrito'        => '',
        'provincia'       => '',
        'departamento'    => '',
    ];
}

/**
 * Contenido del certificado .pem (base64).
 */
function sunat_certificado_base64(): string {
    return sunat_get_config('sunat_certificado', '');
}

/**
 * Guardar certificado .pem en storage.
 * Devuelve la ruta del archivo guardado.
 */
function sunat_guardar_certificado(string $ruc, string $pemBase64): string {
    $dir = BASE_PATH . 'storage/private/sunat/certificados/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $path = $dir . $ruc . '.pem';
    $data = base64_decode($pemBase64);
    if ($data === false) throw new Exception('Certificado base64 inválido');
    file_put_contents($path, $data);
    return $path;
}

/**
 * Ruta del certificado .pem para un RUC dado.
 */
function sunat_cert_path(string $ruc): ?string {
    $storagePath = BASE_PATH . 'storage/private/sunat/certificados/' . $ruc . '.pem';
    if (file_exists($storagePath)) return $storagePath;

    $cfg = sunat_get_config('sunat_certificado', '');
    if (!empty($cfg) && strlen($cfg) > 100) {
        try {
            return sunat_guardar_certificado($ruc, $cfg);
        } catch (Throwable $e) { }
    }
    return null;
}