<?php
/**
 * Limpiar configuración de WP Pusher de la base de datos
 * Ejecutar UNA SOLA VEZ después de eliminar el plugin
 */

require_once 'wp-load.php';

header('Content-Type: text/plain; charset=utf-8');

echo "========== LIMPIAR CONFIGURACIÓN DE WP PUSHER ==========\n\n";

// Eliminar opciones de WP Pusher
$options = [
    'wppusher_packages',
    'wppusher_token',
    'wppusher_settings'
];

foreach ($options as $option) {
    if (delete_option($option)) {
        echo "✅ Eliminado: {$option}\n";
    } else {
        echo "ℹ️ No existe: {$option}\n";
    }
}

echo "\n✅ Limpieza completada\n";
echo "Ahora puedes eliminar este archivo.\n";
