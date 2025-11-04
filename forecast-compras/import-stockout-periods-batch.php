<?php
/**
 * Importar períodos de stockout desde CSV - VERSIÓN POR LOTES
 * Procesa en múltiples ejecuciones para evitar timeout
 */

require_once 'wp-load.php';
global $wpdb;

set_time_limit(120);
ini_set('max_execution_time', 120);
ini_set('memory_limit', '256M');

// Obtener offset desde la URL
$offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
$limit = 50; // Procesar 50 productos por vez

// SIEMPRE usar HTML para que el JavaScript funcione
header('Content-Type: text/html; charset=utf-8');
echo "<pre style='font-family: monospace;'>";

// Si es el primer lote, limpiar la tabla
if ($offset == 0) {
    echo "========== IMPORTAR PERÍODOS DE STOCKOUT (VERSIÓN BATCH) ==========\n\n";
    echo "⚠️ LIMPIANDO TABLA EXISTENTE...\n\n";

    $table_stockouts = $wpdb->prefix . 'fc_stockout_periods';
    $wpdb->query("TRUNCATE TABLE $table_stockouts");

    echo "✅ Tabla limpiada\n\n";
}

echo "========== LOTE {$offset} - " . ($offset + $limit) . " ==========\n\n";

// Buscar archivos CSV
$file_movimientos = __DIR__ . '/Listado de movimientos.csv';
$file_productos = __DIR__ . '/Productosycodigos.csv';

if (!file_exists($file_movimientos)) {
    $file_movimientos = ABSPATH . 'Listado de movimientos.csv';
}
if (!file_exists($file_productos)) {
    $file_productos = ABSPATH . 'Productosycodigos.csv';
}

if (!file_exists($file_movimientos)) {
    die("❌ No se encuentra el archivo: {$file_movimientos}\n");
}

if (!file_exists($file_productos)) {
    die("❌ No se encuentra el archivo: {$file_productos}\n");
}

// 1. LEER MAPEO DE PRODUCTOS -> CÓDIGOS (solo primera vez)
$cache_file = __DIR__ . '/productos_map_cache.json';

if ($offset == 0 || !file_exists($cache_file)) {
    echo "1. Leyendo mapeo de productos...\n";
    $productos_map = array();

    if (($handle = fopen($file_productos, 'r')) !== false) {
        $is_first = true;
        while (($data = fgetcsv($handle, 1000, ';')) !== false) {
            if ($is_first) {
                $is_first = false;
                continue;
            }

            if (count($data) >= 2) {
                $nombre = trim($data[0]);
                $codigo = trim($data[1]);

                if (!empty($nombre) && !empty($codigo)) {
                    $productos_map[$nombre] = $codigo;
                }
            }
        }
        fclose($handle);
    }

    // Guardar en cache
    file_put_contents($cache_file, json_encode($productos_map));
    echo "   ✅ " . count($productos_map) . " productos mapeados\n\n";
} else {
    $productos_map = json_decode(file_get_contents($cache_file), true);
    echo "   ✅ Usando cache: " . count($productos_map) . " productos\n\n";
}

// 2. LEER TODOS LOS PRODUCTOS DEL CSV
echo "2. Leyendo productos del CSV...\n";
$productos = array();
$current_product = null;

if (($handle = fopen($file_movimientos, 'r')) !== false) {
    $is_first = true;

    while (($data = fgetcsv($handle, 10000, ';')) !== false) {
        if ($is_first) {
            $is_first = false;
            continue;
        }

        $nombre = trim($data[0]);
        $fecha = isset($data[1]) ? trim($data[1]) : '';
        $stock = isset($data[6]) ? str_replace(',', '.', trim($data[6])) : '0';

        // Nueva cabecera de producto (sin fecha)
        if (empty($fecha)) {
            if ($current_product !== null) {
                $productos[] = $current_product;
            }

            $current_product = array(
                'nombre' => $nombre,
                'movimientos' => array(
                    array('fecha' => null, 'stock' => floatval($stock))
                )
            );
        } else {
            // Movimiento
            if ($current_product !== null) {
                $current_product['movimientos'][] = array(
                    'fecha' => $fecha,
                    'stock' => floatval($stock)
                );
            }
        }
    }

    // Agregar último producto
    if ($current_product !== null) {
        $productos[] = $current_product;
    }

    fclose($handle);
}

$total_productos = count($productos);
echo "   ✅ {$total_productos} productos encontrados\n\n";

// 3. PROCESAR LOTE ACTUAL
echo "3. Procesando lote (del {$offset} al " . min($offset + $limit, $total_productos) . ")...\n\n";

$table_stockouts = $wpdb->prefix . 'fc_stockout_periods';
$periodos_creados = 0;
$productos_sin_codigo = 0;
$productos_sin_id = 0;

$productos_lote = array_slice($productos, $offset, $limit);

foreach ($productos_lote as $prod) {
    $nombre = $prod['nombre'];
    $movimientos = $prod['movimientos'];

    // Buscar SKU
    $sku = isset($productos_map[$nombre]) ? $productos_map[$nombre] : null;

    if (empty($sku)) {
        $productos_sin_codigo++;
        echo "   ⏭️ Sin código: {$nombre}\n";
        continue;
    }

    // Buscar product_id
    $product_id = $wpdb->get_var($wpdb->prepare("
        SELECT post_id
        FROM {$wpdb->postmeta}
        WHERE meta_key = '_alg_ean'
        AND meta_value = %s
        LIMIT 1
    ", $sku));

    if (!$product_id) {
        $productos_sin_id++;
        echo "   ⏭️ Sin ID en WP: {$nombre} (SKU: {$sku})\n";
        continue;
    }

    // Procesar movimientos
    $stockout_start = null;
    $last_fecha = null;
    $periodos_producto = 0;

    foreach ($movimientos as $mov) {
        $fecha = $mov['fecha'];
        $stock = $mov['stock'];

        if ($stock <= 0) {
            if ($stockout_start === null && $fecha !== null) {
                $stockout_start = $fecha;
            }
        } else {
            if ($stockout_start !== null && $fecha !== null) {
                $d1 = new DateTime($stockout_start);
                $d2 = new DateTime($fecha);
                $dias = $d1->diff($d2)->days;

                $wpdb->insert(
                    $table_stockouts,
                    array(
                        'product_id' => $product_id,
                        'sku' => $sku,
                        'start_date' => $stockout_start,
                        'end_date' => $fecha,
                        'days_out' => $dias
                    ),
                    array('%d', '%s', '%s', '%s', '%d')
                );

                $periodos_creados++;
                $periodos_producto++;
                $stockout_start = null;
            }
        }

        if ($fecha !== null) {
            $last_fecha = $fecha;
        }
    }

    // Período abierto final
    if ($stockout_start !== null && $last_fecha !== null) {
        $d1 = new DateTime($stockout_start);
        $d2 = new DateTime();
        $dias = $d1->diff($d2)->days;

        $wpdb->insert(
            $table_stockouts,
            array(
                'product_id' => $product_id,
                'sku' => $sku,
                'start_date' => $stockout_start,
                'end_date' => null,
                'days_out' => $dias
            ),
            array('%d', '%s', '%s', '%s', '%d')
        );

        $periodos_creados++;
        $periodos_producto++;
    }

    if ($periodos_producto > 0) {
        echo "   ✅ {$nombre}: {$periodos_producto} períodos\n";
    }
}

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "\n========== RESUMEN DEL LOTE ==========\n";
echo "Períodos creados en este lote: {$periodos_creados}\n";
echo "Productos sin código: {$productos_sin_codigo}\n";
echo "Productos sin ID: {$productos_sin_id}\n\n";

$siguiente_offset = $offset + $limit;
$quedan = $total_productos - $siguiente_offset;

if ($quedan > 0) {
    echo "⚠️ QUEDAN {$quedan} PRODUCTOS POR PROCESAR\n\n";
    echo "</pre>"; // Cerrar pre antes del script

    // Usar echo para todo el HTML
    echo "<script>setTimeout(function() { window.location.href = '?offset={$siguiente_offset}'; }, 1000);</script>";
    echo "<p><strong>Redirigiendo automáticamente en 1 segundo...</strong></p>";
    echo "<p><a href='?offset={$siguiente_offset}' style='font-size: 18px; padding: 10px 20px; background: #0073aa; color: white; text-decoration: none; display: inline-block; border-radius: 5px;'>O HAZ CLICK AQUÍ PARA CONTINUAR</a></p>";
} else {
    echo "========== ✅ TODOS LOS PRODUCTOS PROCESADOS ==========\n\n";

    // Limpiar cache
    if (file_exists($cache_file)) {
        unlink($cache_file);
    }

    echo "⚠️ PRÓXIMO PASO:\n";
    echo "Ve a WordPress → Forecast Dashboard → Configuración\n";
    echo "Click en 'Actualizar Métricas'\n";

    echo "</pre>";
}
