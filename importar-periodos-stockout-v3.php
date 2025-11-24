<?php
/**
 * Importar períodos de stockout desde movimientos-con-sku-actualizado.csv
 * VERSIÓN 3 - Con procesamiento en batches
 *
 * Detecta automáticamente períodos de stockout analizando historial de movimientos
 */

require_once 'wp-load.php';
global $wpdb;

set_time_limit(120);
ini_set('max_execution_time', 120);
ini_set('memory_limit', '512M');

// Obtener offset desde la URL
$offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
$limit = 50; // Procesar 50 productos por vez

// SIEMPRE usar HTML para que el JavaScript funcione
header('Content-Type: text/html; charset=utf-8');
echo "<pre style='font-family: monospace;'>";

// Si es el primer lote, limpiar la tabla
if ($offset == 0) {
    echo "╔══════════════════════════════════════════════════════════════════════╗\n";
    echo "║     IMPORTAR PERÍODOS STOCKOUT V3 (desde movimientos últimos 90d)   ║\n";
    echo "╚══════════════════════════════════════════════════════════════════════╝\n\n";
    echo "⚠️ LIMPIANDO TABLA EXISTENTE...\n\n";

    $table_stockouts = $wpdb->prefix . 'fc_stockout_periods';
    $wpdb->query("TRUNCATE TABLE $table_stockouts");

    echo "✅ Tabla limpiada\n\n";
}

echo "========== LOTE {$offset} - " . ($offset + $limit) . " ==========\n\n";

// Buscar archivo CSV
$file_movimientos = __DIR__ . '/movimientos-con-sku-actualizado.csv';

if (!file_exists($file_movimientos)) {
    die("❌ No se encuentra el archivo: {$file_movimientos}\n");
}

// 1. Leer TODOS los movimientos y agrupar por SKU
echo "1. Leyendo movimientos del CSV...\n";

$productos_map = array(); // SKU => array de movimientos

if (($handle = fopen($file_movimientos, 'r')) !== false) {
    $is_first = true;

    while (($data = fgetcsv($handle, 10000, ';')) !== false) {
        if ($is_first) {
            $is_first = false;
            continue;
        }

        $sku = isset($data[0]) ? trim($data[0]) : '';
        $producto = isset($data[1]) ? trim($data[1]) : '';
        $fecha = isset($data[2]) ? trim($data[2]) : '';
        $ingreso = isset($data[3]) ? str_replace(',', '.', trim($data[3])) : '0';
        $egreso = isset($data[4]) ? str_replace(',', '.', trim($data[4])) : '0';
        $stock = isset($data[5]) ? str_replace(',', '.', trim($data[5])) : '0';

        // Ignorar líneas sin SKU
        if (empty($sku)) {
            continue;
        }

        // Ignorar líneas sin fecha (stock actual al 28/08/2025)
        if (empty($fecha)) {
            continue;
        }

        // Inicializar array si no existe
        if (!isset($productos_map[$sku])) {
            $productos_map[$sku] = array(
                'nombre' => $producto,
                'movimientos' => array()
            );
        }

        // Agregar movimiento
        $productos_map[$sku]['movimientos'][] = array(
            'fecha' => $fecha,
            'ingreso' => floatval($ingreso),
            'egreso' => floatval($egreso),
            'stock' => floatval($stock)
        );
    }
    fclose($handle);
}

$total_productos = count($productos_map);
echo "   ✅ {$total_productos} productos únicos con movimientos\n\n";

// 2. Ordenar movimientos por fecha para cada producto
echo "2. Ordenando movimientos por fecha...\n";
foreach ($productos_map as $sku => &$data) {
    usort($data['movimientos'], function($a, $b) {
        return strtotime($a['fecha']) - strtotime($b['fecha']);
    });
}
unset($data);
echo "   ✅ Movimientos ordenados\n\n";

// 3. Procesar lote actual
echo "3. Procesando lote (del {$offset} al " . min($offset + $limit, $total_productos) . ")...\n\n";

$table_stockouts = $wpdb->prefix . 'fc_stockout_periods';
$periodos_creados = 0;
$productos_sin_id = 0;

$skus = array_keys($productos_map);
$skus_lote = array_slice($skus, $offset, $limit);

foreach ($skus_lote as $sku) {
    $data = $productos_map[$sku];
    $nombre = $data['nombre'];
    $movimientos = $data['movimientos'];

    // Buscar product_id en WordPress
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

    // Detectar períodos de stockout
    $periodos = array();
    $stockout_start = null;
    $stock_anterior = null;

    foreach ($movimientos as $mov) {
        $fecha = $mov['fecha'];
        $stock = $mov['stock'];

        // TRANSICIÓN: De con stock a sin stock
        if ($stock <= 0 && ($stock_anterior === null || $stock_anterior > 0)) {
            $stockout_start = $fecha;
        }
        // TRANSICIÓN: De sin stock a con stock
        elseif ($stock > 0 && $stock_anterior !== null && $stock_anterior <= 0 && $stockout_start !== null) {
            // Cerrar período
            $periodos[] = array(
                'start' => $stockout_start,
                'end' => $fecha
            );
            $stockout_start = null;
        }

        $stock_anterior = $stock;
    }

    // Si quedó un período abierto (último movimiento con stock=0)
    if ($stockout_start !== null && $stock_anterior <= 0) {
        $periodos[] = array(
            'start' => $stockout_start,
            'end' => null // Período abierto
        );
    }

    // Insertar períodos en la base de datos
    if (count($periodos) > 0) {
        foreach ($periodos as $periodo) {
            $start = $periodo['start'];
            $end = $periodo['end'];

            if ($end !== null) {
                $d1 = new DateTime($start);
                $d2 = new DateTime($end);
                $dias = $d1->diff($d2)->days;
            } else {
                $d1 = new DateTime($start);
                $d2 = new DateTime();
                $dias = $d1->diff($d2)->days;
            }

            $wpdb->insert(
                $table_stockouts,
                array(
                    'product_id' => $product_id,
                    'sku' => $sku,
                    'start_date' => $start,
                    'end_date' => $end,
                    'days_out' => $dias
                ),
                array('%d', '%s', '%s', '%s', '%d')
            );

            $periodos_creados++;
        }

        $estado = $periodo['end'] === null ? '🔴' : '';
        echo "   ✅ {$nombre}: " . count($periodos) . " período(s) {$estado}\n";
    }
}

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "\n========== RESUMEN DEL LOTE ==========\n";
echo "Períodos creados en este lote: {$periodos_creados}\n";
echo "Productos sin ID en WP: {$productos_sin_id}\n\n";

$siguiente_offset = $offset + $limit;
$quedan = $total_productos - $siguiente_offset;

if ($quedan > 0) {
    echo "⚠️ QUEDAN {$quedan} PRODUCTOS POR PROCESAR\n\n";
    echo "</pre>";

    echo "<script>setTimeout(function() { window.location.href = '?offset={$siguiente_offset}'; }, 2000);</script>";
    echo "<p><strong>Redirigiendo automáticamente en 2 segundos...</strong></p>";
    echo "<p><a href='?offset={$siguiente_offset}' style='font-size: 18px; padding: 10px 20px; background: #0073aa; color: white; text-decoration: none; display: inline-block; border-radius: 5px;'>O HAZ CLICK AQUÍ PARA CONTINUAR</a></p>";
} else {
    echo "========== ✅ TODOS LOS PRODUCTOS PROCESADOS ==========\n\n";
    echo "⚠️ PRÓXIMO PASO:\n";
    echo "Ve a WordPress → Forecast Dashboard → Configuración\n";
    echo "Click en 'Actualizar Métricas'\n";
    echo "</pre>";
}
