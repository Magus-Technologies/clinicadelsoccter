<?php
/**
 * includes/ia.php — Helper de IA (Google Gemini) para Clínica del Scooter.
 * La API key y el modelo se leen de Configuración (tabla `configuracion`).
 * Uso:
 *   require_once __DIR__.'/../../includes/ia.php';
 *   $r = iaGenerar($db, "texto del usuario", "instrucción de sistema");
 *   if ($r['ok']) echo $r['texto']; else echo $r['error'];
 */

if (!function_exists('iaConfig')) {

function iaConfig(PDO $db): array {
    return [
        'key'    => trim(getConfig('gemini_api_key', $db)),
        'modelo' => getConfig('ia_modelo', $db) ?: 'gemini-2.5-flash',
        'activa' => getConfig('ia_activa', $db) !== '0',
    ];
}

function iaDisponible(PDO $db): bool {
    $c = iaConfig($db);
    return $c['activa'] && $c['key'] !== '';
}

/**
 * Genera texto con Gemini.
 * @return array ['ok'=>bool, 'texto'=>string, 'error'=>string]
 */
function iaGenerar(PDO $db, string $userPrompt, string $systemPrompt = '', int $maxTokens = 800): array {
    $c = iaConfig($db);
    if (!$c['activa'])    return ['ok'=>false, 'texto'=>'', 'error'=>'La IA está desactivada en Configuración.'];
    if ($c['key'] === '') return ['ok'=>false, 'texto'=>'', 'error'=>'Falta la API key de Gemini en Configuración.'];

    $url  = 'https://generativelanguage.googleapis.com/v1beta/models/'
          . rawurlencode($c['modelo']) . ':generateContent';

    $body = [
        'contents'         => [[ 'parts' => [[ 'text' => $userPrompt ]] ]],
        'generationConfig' => [ 'temperature' => 0.4, 'maxOutputTokens' => $maxTokens ],
    ];
    if ($systemPrompt !== '') {
        $body['systemInstruction'] = [ 'parts' => [[ 'text' => $systemPrompt ]] ];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        // 'Expect:' vacío desactiva el header "Expect: 100-continue" que cURL agrega
        // en POST con cuerpo > ~1KB. Algunos intermediarios no responden el "100
        // Continue" y la conexión se queda colgada hasta el timeout (0 bytes recibidos).
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-goog-api-key: ' . $c['key'], 'Expect:'],
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 15,
        // Muchos VPS tienen IPv6 mal ruteado y la conexión se cuelga hasta el timeout.
        // Forzar IPv4 evita ese bloqueo al salir hacia Google.
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
    ]);
    $resp = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($resp === false) return ['ok'=>false, 'texto'=>'', 'error'=>'No se pudo conectar con la IA: ' . $cerr];

    $json = json_decode($resp, true);
    if ($http === 429) {
        return ['ok'=>false, 'texto'=>'', 'error'=>'Se alcanzó el límite gratuito por ahora. Intenta en unos minutos.'];
    }
    if ($http !== 200) {
        $msg = $json['error']['message'] ?? ('Error HTTP ' . $http);
        return ['ok'=>false, 'texto'=>'', 'error'=>'La IA respondió con error: ' . $msg];
    }
    // Respuesta correcta: candidates[0].content.parts[*].text
    $texto = '';
    if (!empty($json['candidates'][0]['content']['parts'])) {
        foreach ($json['candidates'][0]['content']['parts'] as $p) {
            if (isset($p['text'])) $texto .= $p['text'];
        }
    }
    $texto = trim($texto);
    if ($texto === '') {
        // Puede venir bloqueado por filtros de seguridad
        $block = $json['promptFeedback']['blockReason'] ?? ($json['candidates'][0]['finishReason'] ?? '');
        return ['ok'=>false, 'texto'=>'', 'error'=>'La IA no devolvió texto' . ($block ? " ($block)" : '') . '. Intenta de nuevo.'];
    }
    return ['ok'=>true, 'texto'=>$texto, 'error'=>''];
}

} // fin if function_exists
