<?php
/**
 * includes/logger.php — Sistema de logging centralizado
 *
 * Guarda errores en storage/logs/error.log con timestamp, nivel y contexto.
 * Usar en lugar de error_log() para tener trazabilidad.
 *
 * Uso:
 *   require_once __DIR__ . '/../../includes/logger.php';
 *   app_log('Mensaje de error', 'ERROR', ['context' => 'datos extras']);
 *   app_log('Venta creada OK', 'INFO');
 */

if (!function_exists('app_log')) {
    function app_log(string $message, string $level = 'ERROR', array $context = []): void {
        $logDir = BASE_PATH . 'storage/logs/';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $logFile = $logDir . 'error.log';

        $timestamp = date('Y-m-d H:i:s');
        $userId    = $_SESSION['user_id'] ?? 'guest';
        $uri       = $_SERVER['REQUEST_URI'] ?? 'cli';

        $contextStr = '';
        if (!empty($context)) {
            $contextStr = ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }

        $line = "[{$timestamp}] [{$level}] [user:{$userId}] [{$uri}] {$message}{$contextStr}" . PHP_EOL;

        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }
}
