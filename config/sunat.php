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

// Entorno de ejecucion, expuesto como constante para que el resto del
// codigo no vuelva a re-deducirlo. NO es una frontera de seguridad:
// HTTP_HOST lo controla el cliente. Solo decide comodidades de desarrollo.
define('SUNAT_ENTORNO_LOCAL', $__isLocal);

if ($__isLocal) {
    define('SUNAT_API_URL', 'http://api-sunat-laravel.test/api/v1');
} else {
   define('SUNAT_API_URL', 'https://magus-qa.com/api-sunat-laravel/api/v1');

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
    // Con `??` el default solo aplicaba si la clave NO existia. Pero las
    // filas de configuracion existen creadas y vacias, asi que devolvia ''
    // y los valores por defecto nunca entraban en juego. Una clave vacia
    // es una clave sin configurar.
    $valor = trim((string)($cache[$key] ?? ''));
    return $valor !== '' ? $valor : $default;
}

/**
 * Modo SUNAT: beta o produccion.
 */
function sunat_modo(): string {
    return sunat_get_config('sunat_modo', 'beta') === 'produccion' ? 'produccion' : 'beta';
}

/**
 * Credenciales SOL.
 *
 * Las credenciales de prueba de SUNAT (MODDATOS) se completan solas SOLO
 * cuando se dan las dos condiciones a la vez:
 *
 *   1) la app corre en un host local, y
 *   2) `sunat_modo` esta en beta.
 *
 * Las dos son necesarias a proposito. `sunat_modo` vive en la tabla
 * `configuracion`, que viaja en el mismo dump entre local y el servidor:
 * si el default dependiera solo de ese flag, el servidor real tambien
 * empezaria a mandar MODDATOS al emitir. Exigir ademas host local deja el
 * comportamiento del servidor exactamente como estaba.
 *
 * Fuera de local nunca se inventa nada: si faltan, sunat_config_faltante()
 * lo dice con nombre y apellido.
 *
 * El RUC se toma siempre del configurado. No se fuerza el RUC de prueba
 * porque el certificado digital esta emitido para el RUC real, y un
 * comprobante firmado con un RUC distinto al del certificado es invalido.
 */
function sunat_credenciales(): array {
    $usarPrueba = SUNAT_ENTORNO_LOCAL && sunat_modo() === 'beta';
    return [
        'ruc'     => sunat_get_config('empresa_ruc', $usarPrueba ? '20000000001' : ''),
        'usuario' => sunat_get_config('sunat_usuario_sol', $usarPrueba ? 'MODDATOS' : ''),
        'clave'   => sunat_get_config('sunat_clave_sol',   $usarPrueba ? 'MODDATOS' : ''),
    ];
}

/**
 * Revisa que este cargado lo minimo para emitir. Devuelve la lista de
 * campos faltantes, con el nombre que ve el usuario en Configuracion.
 */
function sunat_config_faltante(): array {
    $cred    = sunat_credenciales();
    $falta   = [];

    if (trim($cred['ruc']) === '')     $falta[] = 'RUC de la empresa';
    if (trim($cred['usuario']) === '') $falta[] = 'Usuario SOL';
    if (trim($cred['clave']) === '')   $falta[] = 'Contraseña SOL';
    if (trim(sunat_get_config('empresa_nombre')) === '') $falta[] = 'Razón social de la empresa';

    return $falta;
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
