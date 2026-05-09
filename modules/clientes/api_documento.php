<?php
header('Content-Type: application/json');

$TOKEN = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJlbWFpbCI6InN5c3RlbWNyYWZ0LnBlQGdtYWlsLmNvbSJ9.yuNS5hRaC0hCwymX_PjXRoSZJWLNNBeOdlLRSUGlHGA';

$doc = preg_replace('/\D/', '', $_GET['doc'] ?? '');

if (strlen($doc) === 8) {
    $url = "https://dniruc.apisperu.com/api/v1/dni/{$doc}?token={$TOKEN}";
} elseif (strlen($doc) === 11) {
    $url = "https://dniruc.apisperu.com/api/v1/ruc/{$doc}?token={$TOKEN}";
} else {
    echo json_encode(['error' => 'Documento inválido']);
    exit;
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT        => 5,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$response || $httpCode !== 200) {
    echo json_encode(['error' => 'No encontrado']);
    exit;
}

$data = json_decode($response, true);

if (strlen($doc) === 8) {
    // DNI
    if (empty($data['nombres'])) {
        echo json_encode(['error' => 'No encontrado']);
        exit;
    }
    $nombre = trim($data['nombres'] . ' ' . $data['apellidoPaterno'] . ' ' . $data['apellidoMaterno']);
    echo json_encode(['ok' => true, 'nombre' => $nombre, 'tipo' => 'persona']);

} else {
    // RUC
    if (empty($data['razonSocial'])) {
        echo json_encode(['error' => 'No encontrado']);
        exit;
    }
    $razon = trim($data['razonSocial']);

    if (substr($doc, 0, 2) === '10') {
        // Persona natural: formato "APEPAT APEMAT NOMBRE1 NOMBRE2"
        $partes = explode(' ', $razon);
        if (count($partes) >= 3) {
            $apePat = $partes[0] ?? '';
            $apeMat = $partes[1] ?? '';
            $nombres = implode(' ', array_slice($partes, 2));
            $nombre = trim($nombres . ' ' . $apePat . ' ' . $apeMat);
        } else {
            $nombre = $razon;
        }
        $tipo = 'persona';
    } else {
        // Empresa (RUC 20)
        $nombre = $razon;
        $tipo   = 'empresa';
    }

    echo json_encode(['ok' => true, 'nombre' => $nombre, 'tipo' => $tipo]);
}
