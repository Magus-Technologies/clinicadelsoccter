<?php
// config/app.php — Constantes y helpers globales

define('APP_NAME',    'TechRepair Pro');
define('APP_VERSION', '1.0.0');
define('APP_TIMEZONE','America/Lima');

date_default_timezone_set(APP_TIMEZONE);
session_start();

// Roles del sistema
define('ROL_ADMIN',    'admin');
define('ROL_TECNICO',  'tecnico');
define('ROL_VENDEDOR', 'vendedor');

// Estados de OT
// Estados OT — cargados dinámicamente desde BD si existe la tabla, sino fallback estático
function getEstadosOT(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $pdo  = getDB();
        $rows = $pdo->query("SELECT * FROM estados_ot WHERE activo=1 ORDER BY orden ASC")->fetchAll();
        if ($rows) {
            $cache = [];
            foreach ($rows as $r) {
                $cache[$r['clave']] = [
                    'label'    => $r['label'],
                    'color'    => $r['color'],
                    'icon'     => $r['icono'],
                    'es_final' => (bool)$r['es_final'],
                ];
            }
            return $cache;
        }
    } catch (\Throwable $e) { /* tabla aún no existe */ }
    // Fallback estático
    $cache = [
        'ingresado'     => ['label'=>'Ingresado',     'color'=>'secondary','icon'=>'inbox',       'es_final'=>false],
        'en_revision'   => ['label'=>'En revisión',   'color'=>'info',     'icon'=>'search',      'es_final'=>false],
        'en_reparacion' => ['label'=>'En reparación', 'color'=>'warning',  'icon'=>'tool',        'es_final'=>false],
        'listo'         => ['label'=>'Listo',          'color'=>'success',  'icon'=>'check-circle','es_final'=>false],
        'entregado'     => ['label'=>'Entregado',      'color'=>'primary',  'icon'=>'package',     'es_final'=>true],
        'cancelado'     => ['label'=>'Cancelado',      'color'=>'danger',   'icon'=>'x-circle',    'es_final'=>true],
    ];
    return $cache;
}
define('ESTADOS_OT', getEstadosOT());

// ----------------------------------------------------------
// Autenticación
// ----------------------------------------------------------
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . 'modules/auth/login.php');
        exit;
    }
}

function requireRole(array $roles): void {
    requireLogin();
    if (!in_array($_SESSION['user_rol'], $roles)) {
        http_response_code(403);
        die('<div class="alert alert-danger m-4">Acceso denegado.</div>');
    }
}

function currentUser(): array {
    return [
        'id'     => $_SESSION['user_id']     ?? 0,
        'nombre' => $_SESSION['user_nombre'] ?? '',
        'rol'    => $_SESSION['user_rol']    ?? '',
        'email'  => $_SESSION['user_email']  ?? '',
    ];
}

// ----------------------------------------------------------
// Generadores de códigos
// ----------------------------------------------------------
function generarCodigoOT(PDO $db): string {
    $anio = date('Y');
    $stmt = $db->prepare("SELECT COUNT(*) FROM ordenes_trabajo WHERE YEAR(created_at) = ?");
    $stmt->execute([$anio]);
    $n = (int)$stmt->fetchColumn() + 1;
    return 'OT-' . $anio . '-' . str_pad($n, 4, '0', STR_PAD_LEFT);
}

function generarCodigoPublicoOT(): string {
    return strtoupper(substr(md5(uniqid(rand(), true)), 0, 8));
}

function generarCodigoCliente(PDO $db): string {
    $stmt = $db->query("SELECT COUNT(*) FROM clientes");
    $n = (int)$stmt->fetchColumn() + 1;
    return 'CLI-' . str_pad($n, 4, '0', STR_PAD_LEFT);
}

function generarCodigoVenta(PDO $db): string {
    $anio = date('Y');
    $stmt = $db->prepare("SELECT COUNT(*) FROM ventas WHERE YEAR(created_at) = ?");
    $stmt->execute([$anio]);
    $n = (int)$stmt->fetchColumn() + 1;
    return 'VTA-' . $anio . '-' . str_pad($n, 4, '0', STR_PAD_LEFT);
}

// ----------------------------------------------------------
// Helpers
// ----------------------------------------------------------
function sanitize(string $val): string {
    return htmlspecialchars(trim($val), ENT_QUOTES, 'UTF-8');
}

function formatMoney(float $amount): string {
    return 'S/ ' . number_format($amount, 2, '.', ',');
}

function formatDate(string $date): string {
    if (!$date) return '—';
    return date('d/m/Y', strtotime($date));
}

function formatDateTime(string $dt): string {
    if (!$dt) return '—';
    return date('d/m/Y H:i', strtotime($dt));
}

function estadoOTBadge(string $estado): string {
    $e = ESTADOS_OT[$estado] ?? ['label' => $estado, 'color' => 'secondary', 'icon' => 'circle'];
    return '<span class="badge bg-' . $e['color'] . '">' . $e['label'] . '</span>';
}

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

function setFlash(string $tipo, string $mensaje): void {
    $_SESSION['flash'] = ['tipo' => $tipo, 'mensaje' => $mensaje];
}

function getFlash(): ?array {
    if (isset($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

function uploadFoto(array $file, string $subdir = 'ot'): ?string {
    $dir = UPLOAD_PATH . $subdir . '/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg','jpeg','png','webp','gif'];
    if (!in_array($ext, $allowed)) return null;
    if ($file['size'] > 5 * 1024 * 1024) return null; // 5MB max
    $nombre = uniqid('img_', true) . '.' . $ext;
    move_uploaded_file($file['tmp_name'], $dir . $nombre);
    return $subdir . '/' . $nombre;
}

function getConfig(string $clave, PDO $db): string {
    static $cache = [];
    if (!isset($cache[$clave])) {
        $stmt = $db->prepare("SELECT valor FROM configuracion WHERE clave = ?");
        $stmt->execute([$clave]);
        $cache[$clave] = $stmt->fetchColumn() ?? '';
    }
    return $cache[$clave];
}
