<?php
/**
 * upload_video_chunk.php — Recibe chunks de video y los ensambla
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';

header('Content-Type: application/json');

// Log para debug
function logChunk(string $msg): void {
    $f = UPLOAD_PATH . 'chunk_debug.log';
    file_put_contents($f, date('[H:i:s] ') . $msg . "\n", FILE_APPEND);
}

if (!isLoggedIn()) {
    echo json_encode(['error' => 'No autorizado']); exit;
}

$chunkIndex  = (int)($_POST['chunkIndex']  ?? 0);
$totalChunks = (int)($_POST['totalChunks'] ?? 1);
$fileId      = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['fileId'] ?? '');
$fileName    = basename($_POST['fileName']  ?? 'video.mp4');
$otId        = (int)($_POST['otId']        ?? 0);

logChunk("chunk=$chunkIndex/$totalChunks fileId=$fileId fileName=$fileName");

if (!$fileId) {
    echo json_encode(['error' => 'fileId inválido']); exit;
}

// Directorio temporal por sesión
$tmpDir = sys_get_temp_dir() . '/ot_chunks_' . $fileId . '/';
if (!is_dir($tmpDir)) {
    $ok = mkdir($tmpDir, 0750, true);
    logChunk("mkdir tmpDir=$tmpDir ok=" . ($ok?'1':'0'));
}

// Guardar chunk
if (empty($_FILES['chunk']) || $_FILES['chunk']['error'] !== 0) {
    $err = $_FILES['chunk']['error'] ?? 'no file';
    logChunk("ERROR chunk file error=$err");
    echo json_encode(['error' => "Chunk no recibido (error=$err)"]); exit;
}

$chunkPath = $tmpDir . 'chunk_' . str_pad($chunkIndex, 5, '0', STR_PAD_LEFT);
$moved = move_uploaded_file($_FILES['chunk']['tmp_name'], $chunkPath);
logChunk("chunk saved=$chunkPath moved=" . ($moved?'1':'0') . " size=" . $_FILES['chunk']['size']);

if (!$moved) {
    echo json_encode(['error' => 'No se pudo guardar chunk en ' . $chunkPath]); exit;
}

// Verificar chunks recibidos
$chunks = glob($tmpDir . 'chunk_*') ?: [];
logChunk("chunks recibidos=" . count($chunks) . " total=$totalChunks");

if (count($chunks) < $totalChunks) {
    echo json_encode([
        'status'   => 'chunk_ok',
        'received' => count($chunks),
        'total'    => $totalChunks,
        'progress' => round(count($chunks) / $totalChunks * 100),
    ]);
    exit;
}

// Todos los chunks — ensamblar
logChunk("Ensamblando archivo...");
$ext      = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
$uid      = uniqid('vid_', true);
$assembled = $tmpDir . 'assembled.' . $ext;

sort($chunks);
$fp = fopen($assembled, 'wb');
foreach ($chunks as $chunk) {
    fwrite($fp, file_get_contents($chunk));
    unlink($chunk);
}
fclose($fp);

$assembledSize = filesize($assembled);
logChunk("Ensamblado: $assembled size=$assembledSize");

// Preparar directorio de salida
$subdir = 'ot/temp';
$outDir = UPLOAD_PATH . $subdir . '/';
if (!is_dir($outDir)) {
    mkdir($outDir, 0755, true);
    logChunk("mkdir outDir=$outDir");
}

$outName = $uid . '.mp4';
$outPath = $outDir . $outName;

// Comprimir con ffmpeg
$ffmpegPaths = ['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/bin/ffmpeg'];
$ffmpeg = null;
foreach ($ffmpegPaths as $p) {
    if (is_executable($p)) { $ffmpeg = $p; break; }
}
logChunk("ffmpeg=" . ($ffmpeg ?: 'NOT FOUND'));

$result = ['status' => 'complete'];

if ($ffmpeg) {
    $scaleFilter = 'scale=trunc(min(iw\,1280)/2)*2:trunc(min(ih\,720)/2)*2';
    $logFile = $tmpDir . 'ffmpeg.log';
    $cmd = sprintf(
        '%s -i %s -c:v libx264 -crf 28 -preset fast -vf %s -c:a aac -b:a 96k -movflags +faststart -y %s > %s 2>&1',
        escapeshellarg($ffmpeg),
        escapeshellarg($assembled),
        escapeshellarg($scaleFilter),
        escapeshellarg($outPath),
        escapeshellarg($logFile)
    );
    logChunk("Ejecutando ffmpeg...");
    exec($cmd, $out, $ret);
    $ffmpegLog = file_exists($logFile) ? substr(file_get_contents($logFile), -500) : '';
    logChunk("ffmpeg ret=$ret outExists=" . (file_exists($outPath)?'1':'0') . " outSize=" . (file_exists($outPath)?filesize($outPath):0));

    if ($ret !== 0 || !file_exists($outPath) || filesize($outPath) === 0) {
        logChunk("ffmpeg FALLÓ — usando original. Log: " . $ffmpegLog);
        rename($assembled, $outPath);
        $result['compressed'] = false;
        $result['ffmpeg_log'] = $ffmpegLog;
    } else {
        @unlink($assembled);
        $result['compressed']    = true;
        $result['original_size'] = $assembledSize;
        $result['final_size']    = filesize($outPath);
    }
} else {
    logChunk("Sin ffmpeg — copiando original");
    rename($assembled, $outPath);
    $result['compressed'] = false;
}

// Limpiar tmpDir
@rmdir($tmpDir);

// Verificar archivo final
if (!file_exists($outPath) || filesize($outPath) === 0) {
    logChunk("ERROR: archivo final no existe o vacío: $outPath");
    echo json_encode(['error' => 'El archivo final no se pudo generar en ' . $outPath]);
    exit;
}

logChunk("Archivo final OK: $outPath size=" . filesize($outPath));

// Duración con ffprobe
$duracion = null;
if ($ffmpeg) {
    $ffprobe = str_replace('ffmpeg', 'ffprobe', $ffmpeg);
    if (!is_executable($ffprobe)) $ffprobe = '/usr/bin/ffprobe';
    if (is_executable($ffprobe)) {
        $dur = trim((string)shell_exec(sprintf(
            '%s -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s 2>/dev/null',
            $ffprobe, escapeshellarg($outPath)
        )));
        $duracion = is_numeric($dur) ? (int)round((float)$dur) : null;
    }
}

// Guardar en BD siempre con ot_id=0
$ruta = $subdir . '/' . $outName;
try {
    $db = getDB();

    // Limpiar huérfanos viejos (>2h)
    $huerfanos = $db->query(
        "SELECT id, ruta FROM fotos_ot WHERE ot_id IS NULL AND tipo_archivo='video' AND created_at < NOW() - INTERVAL 2 HOUR"
    )->fetchAll();
    foreach ($huerfanos as $h) {
        $hp = UPLOAD_PATH . $h['ruta'];
        if (file_exists($hp)) @unlink($hp);
        $db->prepare("DELETE FROM fotos_ot WHERE id=?")->execute([$h['id']]);
    }

    // NULL en ot_id para evitar FK constraint (se reasigna al crear la OT)
    $db->prepare("INSERT INTO fotos_ot (ot_id,ruta,tipo_archivo,duracion_seg,tamano_bytes,tipo) VALUES (NULL,?,'video',?,?,'ingreso')")
       ->execute([$ruta, $duracion, filesize($outPath)]);
    $newId = $db->lastInsertId();
    $result['saved_db']    = true;
    $result['fotos_ot_id'] = $newId;
    logChunk("Guardado en BD: id=$newId ruta=$ruta");
} catch (Exception $e) {
    $result['saved_db'] = false;
    $result['db_error'] = $e->getMessage();
    logChunk("ERROR BD: " . $e->getMessage());
}

$result['ruta']          = $ruta;
$result['duracion']      = $duracion;
$result['final_size']    = file_exists($outPath) ? filesize($outPath) : 0;
$result['final_size_mb'] = round($result['final_size'] / 1024 / 1024, 2);

logChunk("Respuesta: " . json_encode($result));
echo json_encode($result);
