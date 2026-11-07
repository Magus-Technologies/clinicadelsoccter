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
 * Valida un RUC por formato y digito verificador (modulo 11).
 * Sirve tambien para descartar numeros de 11 digitos que no son RUC.
 */
function sunat_ruc_valido(string $ruc): bool {
    if (!preg_match('/^(10|15|16|17|20)\d{9}$/', $ruc)) return false;
    $pesos = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
    $suma  = 0;
    for ($i = 0; $i < 10; $i++) $suma += (int)$ruc[$i] * $pesos[$i];
    return ((11 - ($suma % 11)) % 10) === (int)$ruc[10];
}

/**
 * Lee un certificado .pem y devuelve lo que se puede verificar de el.
 *
 * - 'valido'  : false si no hay un certificado X.509 legible.
 * - 'rucs'    : RUC(s) encontrados en el sujeto / SAN del certificado.
 *               Cada emisora (ECEP) lo pone en un campo distinto
 *               (serialNumber, OU, CN...), por eso se busca en todos.
 * - 'vence'   : fecha de vencimiento (d/m/Y) y 'vencido' (bool).
 * - 'clave'   : true/false si el .pem trae clave privada que corresponde
 *               al certificado; null si no trae clave o esta cifrada.
 */
function sunat_cert_info(string $pem): array {
    $info = [
        'valido' => false, 'error' => null, 'rucs' => [], 'sujeto' => '',
        'vence' => null, 'vencido' => false, 'clave' => null,
    ];

    if (!function_exists('openssl_x509_read')) {
        $info['error'] = 'La extensión openssl de PHP no está habilitada en este servidor.';
        return $info;
    }
    if (!preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem, $m) || empty($m[0])) {
        $info['error'] = 'El archivo no contiene un certificado X.509 en formato PEM (falta el bloque BEGIN CERTIFICATE).';
        return $info;
    }

    // Si el .pem trae clave privada, se usa para elegir el certificado hoja
    // (un .pem puede incluir tambien certificados intermedios).
    $clave = @openssl_pkey_get_private($pem);
    $elegido = null;
    foreach ($m[0] as $bloque) {
        $x509 = @openssl_x509_read($bloque);
        if ($x509 === false) continue;
        if ($elegido === null) $elegido = $x509;
        if ($clave !== false && @openssl_x509_check_private_key($x509, $clave)) {
            $elegido = $x509;
            $info['clave'] = true;
            break;
        }
    }
    if ($elegido === null) {
        $info['error'] = 'No se pudo leer el certificado: el bloque PEM está dañado.';
        return $info;
    }
    if ($clave !== false && $info['clave'] === null) $info['clave'] = false;

    $parsed = openssl_x509_parse($elegido);
    if (!is_array($parsed)) {
        $info['error'] = 'No se pudo interpretar el certificado.';
        return $info;
    }

    // Texto donde buscar el RUC: todos los campos del sujeto + SAN.
    $partes  = [];
    $sujeto  = $parsed['subject'] ?? [];
    array_walk_recursive($sujeto, function ($v) use (&$partes) { $partes[] = (string)$v; });
    $info['sujeto'] = (string)($parsed['name'] ?? '');
    $partes[] = (string)($parsed['extensions']['subjectAltName'] ?? '');
    $texto = implode(' | ', $partes);

    if (preg_match_all('/(?<!\d)(?:10|15|16|17|20)\d{9}(?!\d)/', $texto, $r)) {
        foreach (array_unique($r[0]) as $cand) {
            if (sunat_ruc_valido($cand)) $info['rucs'][] = $cand;
        }
    }

    if (!empty($parsed['validTo_time_t'])) {
        $info['vence']   = date('d/m/Y', (int)$parsed['validTo_time_t']);
        $info['vencido'] = (int)$parsed['validTo_time_t'] < time();
    }

    $info['valido'] = true;
    return $info;
}

/**
 * Compara el certificado con el RUC de la empresa.
 * Devuelve el mensaje de error, o null si el certificado es utilizable.
 *
 * Si no se logra leer ningun RUC dentro del certificado no se bloquea
 * (no hay forma de verificar): la UI lo muestra como advertencia.
 */
function sunat_cert_error(array $info, string $rucEmpresa): ?string {
    if (!$info['valido']) return $info['error'] ?? 'Certificado inválido.';
    if ($info['clave'] === false) {
        return 'La clave privada del archivo no corresponde al certificado.';
    }
    if ($info['rucs'] && $rucEmpresa !== '' && !in_array($rucEmpresa, $info['rucs'], true)) {
        return 'El certificado pertenece al RUC ' . implode(' / ', $info['rucs'])
             . ' pero la empresa tiene configurado el RUC ' . $rucEmpresa
             . '. Corrija el RUC de la empresa o cargue el certificado correcto.';
    }
    return null;
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
