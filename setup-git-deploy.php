<?php
/**
 * Script de inicialización - Ejecutar UNA SOLA VEZ
 * Convierte la carpeta del plugin en un repositorio git
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(300); // 5 minutos

header('Content-Type: text/plain; charset=utf-8');

echo "========== CONFIGURAR AUTO-DEPLOY CON GIT ==========\n\n";

// Ruta del plugin
$plugin_dir = __DIR__ . '/wp-content/plugins/forecast-compras';

if (!is_dir($plugin_dir)) {
    die("ERROR: Directorio no existe: {$plugin_dir}\n");
}

echo "Directorio del plugin: {$plugin_dir}\n\n";

// Cambiar al directorio del plugin
chdir($plugin_dir);

// Comandos para configurar git
$commands = [
    'git init',
    'git remote add origin https://github.com/ilmoti/Proyeccion-de-compras.git',
    'git fetch origin main',
    'git checkout -b main',
    'git reset --hard origin/main',
    'git config core.sparseCheckout true',
];

// Crear archivo de sparse-checkout
$sparse_file = $plugin_dir . '/.git/info/sparse-checkout';
$sparse_dir = dirname($sparse_file);

if (!is_dir($sparse_dir)) {
    mkdir($sparse_dir, 0755, true);
}

file_put_contents($sparse_file, "forecast-compras/*\n");
echo "✅ Archivo sparse-checkout creado\n";

// Ejecutar comandos
foreach ($commands as $cmd) {
    echo "\n--- Ejecutando: {$cmd} ---\n";

    exec($cmd . ' 2>&1', $output, $return_code);

    foreach ($output as $line) {
        echo $line . "\n";
    }

    if ($return_code !== 0) {
        echo "⚠️ Comando retornó código: {$return_code}\n";
        // Continuar de todos modos, algunos comandos pueden dar error si ya existen
    } else {
        echo "✅ Comando exitoso\n";
    }

    $output = []; // Limpiar para el siguiente comando
}

// Hacer pull final para sincronizar
echo "\n--- Pull final ---\n";
exec('git pull origin main 2>&1', $output, $return_code);

foreach ($output as $line) {
    echo $line . "\n";
}

if ($return_code === 0) {
    echo "\n\n========== ✅ CONFIGURACIÓN COMPLETADA ==========\n";
    echo "\nPRÓXIMOS PASOS:\n";
    echo "1. Sube 'deploy-webhook.php' a la raíz de WordPress\n";
    echo "2. Configura el webhook en GitHub con la URL:\n";
    echo "   https://wifixargentina.com.ar/deploy-webhook.php?token=6f59d1e63f55b18b682a876d1dc17d1b780216a7102c98e63761d747d9762dd9\n";
    echo "\n3. Haz un commit de prueba\n";
    echo "4. Verifica el log en: {$plugin_dir}/deploy.log\n";
    echo "\n¡IMPORTANTE! Borra este archivo después de ejecutarlo.\n";
} else {
    echo "\n\n========== ❌ ERROR EN LA CONFIGURACIÓN ==========\n";
    echo "Revisa los errores arriba y corrígelos.\n";
}
