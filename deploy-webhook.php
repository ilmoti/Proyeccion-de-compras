<?php
/**
 * Auto-Deploy Script para Forecast de Compras
 * Este script se ejecuta cuando GitHub envía un webhook
 */

// Token de seguridad (cámbialo por uno seguro)
define('DEPLOY_TOKEN', '6f59d1e63f55b18b682a876d1dc17d1b780216a7102c98e63761d747d9762dd9');

// Log file
define('DEPLOY_LOG', __DIR__ . '/deploy.log');

// Función para escribir en el log
function deploy_log($message) {
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents(DEPLOY_LOG, "[{$timestamp}] {$message}\n", FILE_APPEND);
}

// Verificar token de seguridad
$token = $_GET['token'] ?? '';
if ($token !== DEPLOY_TOKEN) {
    http_response_code(403);
    deploy_log('ERROR: Token inválido');
    die('Forbidden');
}

deploy_log('=== INICIO DEPLOY ===');

// Verificar que es un evento push de GitHub
$payload = file_get_contents('php://input');
$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';

if ($event !== 'push') {
    deploy_log("Evento ignorado: {$event}");
    http_response_code(200);
    echo json_encode(['status' => 'ignored', 'event' => $event]);
    exit;
}

// Parsear el payload
$data = json_decode($payload, true);
$branch = str_replace('refs/heads/', '', $data['ref'] ?? '');

deploy_log("Push recibido en branch: {$branch}");

// Solo hacer deploy en el branch main
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

// Cambiar al directorio del plugin
chdir($plugin_dir);
deploy_log("Directorio de trabajo: " . getcwd());

// Ejecutar git pull
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
        break;
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
