<?php
/**
 * Auto-Deploy Script para Forecast de Compras v2
 * Maneja correctamente la estructura del repositorio
 */

// Token de seguridad
define('DEPLOY_TOKEN', '6f59d1e63f55b18b682a876d1dc17d1b780216a7102c98e63761d747d9762dd9');
define('DEPLOY_LOG', __DIR__ . '/deploy.log');

function deploy_log($message) {
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents(DEPLOY_LOG, "[{$timestamp}] {$message}\n", FILE_APPEND);
}

// Verificar token
$token = $_GET['token'] ?? '';
if ($token !== DEPLOY_TOKEN) {
    http_response_code(403);
    deploy_log('ERROR: Token inválido');
    die('Forbidden');
}

deploy_log('=== INICIO DEPLOY ===');

// Verificar evento
$payload = file_get_contents('php://input');
$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';

if ($event !== 'push') {
    deploy_log("Evento ignorado: {$event}");
    http_response_code(200);
    echo json_encode(['status' => 'ignored', 'event' => $event]);
    exit;
}

// Parsear payload
$data = json_decode($payload, true);
$branch = str_replace('refs/heads/', '', $data['ref'] ?? '');

deploy_log("Push recibido en branch: {$branch}");

if ($branch !== 'main') {
    deploy_log("Branch ignorado: {$branch}");
    http_response_code(200);
    echo json_encode(['status' => 'ignored', 'branch' => $branch]);
    exit;
}

// Ruta del plugin
$plugin_dir = __DIR__ . '/wp-content/plugins/forecast-compras';

if (!is_dir($plugin_dir)) {
    deploy_log("ERROR: Directorio no existe: {$plugin_dir}");
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Plugin directory not found']);
    exit;
}

chdir($plugin_dir);
deploy_log("Directorio de trabajo: " . getcwd());

// Hacer git pull
$commands = [
    'git fetch origin main 2>&1',
    'git reset --hard origin/main 2>&1',
];

$output = [];
$success = true;

foreach ($commands as $cmd) {
    deploy_log("Ejecutando: {$cmd}");
    exec($cmd, $cmd_output, $return_code);

    $output[] = [
        'command' => $cmd,
        'output' => $cmd_output,
        'return_code' => $return_code
    ];

    deploy_log("Output: " . implode("\n", $cmd_output));
    deploy_log("Return code: {$return_code}");

    if ($return_code !== 0) {
        $success = false;
    }

    $cmd_output = [];
}

// NUEVO: Si se creó carpeta duplicada, mover archivos
$duplicate_dir = $plugin_dir . '/forecast-compras';
if (is_dir($duplicate_dir)) {
    deploy_log("Detectada carpeta duplicada, moviendo archivos...");

    // Mover archivos de la carpeta interna a la raíz
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($duplicate_dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($files as $file) {
        $target_path = $plugin_dir . '/' . substr($file->getPathname(), strlen($duplicate_dir) + 1);

        if ($file->isDir()) {
            if (!is_dir($target_path)) {
                mkdir($target_path, 0755, true);
            }
        } else {
            $target_dir = dirname($target_path);
            if (!is_dir($target_dir)) {
                mkdir($target_dir, 0755, true);
            }
            copy($file->getPathname(), $target_path);
        }
    }

    // Eliminar carpeta duplicada
    function deleteDir($dir) {
        if (!file_exists($dir)) return true;
        if (!is_dir($dir)) return unlink($dir);
        foreach (scandir($dir) as $item) {
            if ($item == '.' || $item == '..') continue;
            if (!deleteDir($dir . DIRECTORY_SEPARATOR . $item)) return false;
        }
        return rmdir($dir);
    }

    if (deleteDir($duplicate_dir)) {
        deploy_log("✅ Carpeta duplicada eliminada y archivos movidos");
    } else {
        deploy_log("⚠️ No se pudo eliminar carpeta duplicada completamente");
    }
}

if ($success) {
    deploy_log('✅ Deploy completado exitosamente');
    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => 'Deploy completed successfully',
        'commands' => $output
    ]);
} else {
    deploy_log('❌ Deploy falló');
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Deploy failed',
        'commands' => $output
    ]);
}

deploy_log('=== FIN DEPLOY ===');
