<?php
/**
 * Análisis de SKU 25120504 - Últimos 90 días
 * Para verificar que el sistema de stockout funciona correctamente
 */

require_once 'wp-load.php';
global $wpdb;

header('Content-Type: text/html; charset=utf-8');
echo "<pre style='font-family: monospace; font-size: 14px;'>";

$sku = '25120504';
$dias = 90;

echo "╔══════════════════════════════════════════════════════════════════════╗\n";
echo "║          ANÁLISIS COMPLETO SKU: {$sku}                              ║\n";
echo "║          Últimos {$dias} días (desde " . date('Y-m-d', strtotime("-{$dias} days")) . " hasta " . date('Y-m-d') . ")      ║\n";
echo "╚══════════════════════════════════════════════════════════════════════╝\n\n";

// 1. Buscar el producto
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "1. INFORMACIÓN DEL PRODUCTO\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

$product_id = $wpdb->get_var($wpdb->prepare("
    SELECT post_id FROM {$wpdb->postmeta}
    WHERE meta_key = '_alg_ean' AND meta_value = %s
    LIMIT 1
", $sku));

if (!$product_id) {
    die("❌ ERROR: No se encontró producto con SKU {$sku}\n");
}

$product = wc_get_product($product_id);
$nombre = $product ? $product->get_name() : 'N/A';
$stock_actual = get_post_meta($product_id, '_stock', true);

echo "   Product ID: {$product_id}\n";
echo "   Nombre: {$nombre}\n";
echo "   Stock actual: {$stock_actual}\n\n";

// 2. Períodos de stockout en la base de datos
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "2. PERÍODOS DE STOCKOUT EN BASE DE DATOS\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

$table_stockouts = $wpdb->prefix . 'fc_stockout_periods';
$fecha_inicio = date('Y-m-d', strtotime("-{$dias} days"));

$periodos = $wpdb->get_results($wpdb->prepare("
    SELECT * FROM {$table_stockouts}
    WHERE product_id = %d
    AND (start_date >= %s OR end_date >= %s OR end_date IS NULL)
    ORDER BY start_date ASC
", $product_id, $fecha_inicio, $fecha_inicio));

if (empty($periodos)) {
    echo "   ✅ No hay períodos de stockout registrados en los últimos {$dias} días\n\n";
} else {
    echo "   Encontrados: " . count($periodos) . " período(s)\n\n";
    echo "   ┌─────────────────┬─────────────────┬──────────┬──────────┐\n";
    echo "   │ Fecha Inicio    │ Fecha Fin       │ Días     │ Estado   │\n";
    echo "   ├─────────────────┼─────────────────┼──────────┼──────────┤\n";

    $total_dias_sin_stock = 0;
    foreach ($periodos as $p) {
        $inicio = $p->start_date;
        $fin = $p->end_date ? $p->end_date : 'ABIERTO';
        $dias_out = $p->days_out;
        $estado = $p->end_date ? 'Cerrado' : '🔴 ACTIVO';

        // Calcular días que caen dentro del rango de 90 días
        $start = new DateTime($p->start_date);
        $end = $p->end_date ? new DateTime($p->end_date) : new DateTime();
        $rango_inicio = new DateTime($fecha_inicio);
        $rango_fin = new DateTime();

        $efectivo_inicio = max($start, $rango_inicio);
        $efectivo_fin = min($end, $rango_fin);
        $dias_efectivos = $efectivo_inicio->diff($efectivo_fin)->days;
        if ($efectivo_fin >= $efectivo_inicio) {
            $total_dias_sin_stock += $dias_efectivos;
        }

        printf("   │ %-15s │ %-15s │ %8s │ %-8s │\n", $inicio, $fin, $dias_out, $estado);
    }
    echo "   └─────────────────┴─────────────────┴──────────┴──────────┘\n";
    echo "\n   📊 Total días sin stock en últimos {$dias} días: {$total_dias_sin_stock}\n\n";
}

// 3. Órdenes de venta en los últimos 90 días
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "3. ÓRDENES DE VENTA (últimos {$dias} días)\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

$ordenes = $wpdb->get_results($wpdb->prepare("
    SELECT
        o.id as order_id,
        o.date_created_gmt as fecha,
        oi.order_item_id,
        oim.meta_value as cantidad
    FROM {$wpdb->prefix}wc_orders o
    INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON o.id = oi.order_id
    INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id AND oim.meta_key = '_qty'
    INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oip ON oi.order_item_id = oip.order_item_id AND oip.meta_key = '_product_id'
    WHERE oip.meta_value = %d
    AND o.status IN ('wc-completed', 'wc-processing')
    AND o.date_created_gmt >= %s
    ORDER BY o.date_created_gmt DESC
", $product_id, $fecha_inicio));

$total_vendido = 0;
if (empty($ordenes)) {
    echo "   No hay ventas registradas en los últimos {$dias} días\n\n";
} else {
    echo "   Encontradas: " . count($ordenes) . " venta(s)\n\n";
    echo "   ┌──────────┬─────────────────────┬──────────┐\n";
    echo "   │ Orden ID │ Fecha               │ Cantidad │\n";
    echo "   ├──────────┼─────────────────────┼──────────┤\n";

    foreach ($ordenes as $o) {
        $fecha = date('Y-m-d H:i', strtotime($o->fecha));
        $cant = intval($o->cantidad);
        $total_vendido += $cant;
        printf("   │ %8s │ %-19s │ %8d │\n", $o->order_id, $fecha, $cant);
    }
    echo "   └──────────┴─────────────────────┴──────────┘\n";
    echo "\n   📊 Total vendido: {$total_vendido} unidades\n\n";
}

// 4. Cálculo de proyección
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "4. CÁLCULO DE PROYECCIÓN (Algoritmo actual)\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

// Calcular días sin stock que intersectan con los últimos 90 días
$dias_sin_stock = $wpdb->get_var($wpdb->prepare("
    SELECT COALESCE(SUM(
        DATEDIFF(
            LEAST(IFNULL(end_date, CURDATE()), CURDATE()),
            GREATEST(start_date, %s)
        ) + 1
    ), 0) as total_dias
    FROM {$table_stockouts}
    WHERE product_id = %d
    AND start_date <= CURDATE()
    AND (end_date IS NULL OR end_date >= %s)
", $fecha_inicio, $product_id, $fecha_inicio));

$dias_sin_stock = max(0, intval($dias_sin_stock));
$dias_con_stock = $dias - $dias_sin_stock;

echo "   Días totales analizados: {$dias}\n";
echo "   Días SIN stock: {$dias_sin_stock}\n";
echo "   Días CON stock: {$dias_con_stock}\n\n";

if ($dias_con_stock > 0 && $total_vendido > 0) {
    $promedio_diario = $total_vendido / $dias_con_stock;
    $promedio_diario_raw = $total_vendido / $dias;

    echo "   📈 Promedio diario AJUSTADO (excluyendo días sin stock): " . number_format($promedio_diario, 4) . " u/día\n";
    echo "   📉 Promedio diario SIN AJUSTAR (sobre {$dias} días): " . number_format($promedio_diario_raw, 4) . " u/día\n\n";

    // Proyección a 30 días
    $dias_cobertura = 30;
    $proyeccion_ajustada = ceil($promedio_diario * $dias_cobertura);
    $proyeccion_sin_ajustar = ceil($promedio_diario_raw * $dias_cobertura);

    echo "   🎯 Proyección de compra para {$dias_cobertura} días:\n";
    echo "      - CON ajuste de stockout: {$proyeccion_ajustada} unidades\n";
    echo "      - SIN ajuste de stockout: {$proyeccion_sin_ajustar} unidades\n";
    echo "      - Diferencia: " . ($proyeccion_ajustada - $proyeccion_sin_ajustar) . " unidades\n\n";
} else {
    echo "   ⚠️ No hay suficientes datos para calcular proyección\n\n";
}

// 5. Datos del CSV de movimientos (si existe)
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "5. HISTORIAL DE MOVIMIENTOS (desde CSV)\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

$csv_file = ABSPATH . 'Movimientos-con-SKU.csv';
if (file_exists($csv_file)) {
    $movimientos = [];
    if (($handle = fopen($csv_file, 'r')) !== false) {
        $is_first = true;
        while (($data = fgetcsv($handle, 10000, ';')) !== false) {
            if ($is_first) { $is_first = false; continue; }

            $csv_sku = isset($data[1]) ? trim($data[1]) : '';
            if ($csv_sku === $sku) {
                $fecha = isset($data[2]) ? trim($data[2]) : '';
                $stock = isset($data[7]) ? str_replace(',', '.', trim($data[7])) : '0';

                if (!empty($fecha)) {
                    // Filtrar por últimos 90 días
                    if (strtotime($fecha) >= strtotime($fecha_inicio)) {
                        $movimientos[] = [
                            'fecha' => $fecha,
                            'stock' => floatval($stock)
                        ];
                    }
                }
            }
        }
        fclose($handle);
    }

    if (empty($movimientos)) {
        echo "   No hay movimientos del SKU {$sku} en los últimos {$dias} días en el CSV\n\n";
    } else {
        echo "   Movimientos encontrados: " . count($movimientos) . "\n\n";
        echo "   ┌─────────────────┬──────────┐\n";
        echo "   │ Fecha           │ Stock    │\n";
        echo "   ├─────────────────┼──────────┤\n";

        // Mostrar últimos 15 movimientos
        $ultimos = array_slice($movimientos, -15);
        foreach ($ultimos as $m) {
            $estado = $m['stock'] <= 0 ? ' ⚠️' : '';
            printf("   │ %-15s │ %6.0f%s │\n", $m['fecha'], $m['stock'], $estado);
        }
        echo "   └─────────────────┴──────────┘\n";

        if (count($movimientos) > 15) {
            echo "   (mostrando últimos 15 de " . count($movimientos) . " movimientos)\n";
        }

        // Detectar días con stock 0
        $dias_stock_cero = 0;
        foreach ($movimientos as $m) {
            if ($m['stock'] <= 0) $dias_stock_cero++;
        }
        echo "\n   📊 Movimientos con stock 0 o menos: {$dias_stock_cero}\n";
    }
} else {
    echo "   ⚠️ No se encontró el archivo CSV de movimientos\n";
}

echo "\n";
echo "╔══════════════════════════════════════════════════════════════════════╗\n";
echo "║                     FIN DEL ANÁLISIS                                 ║\n";
echo "╚══════════════════════════════════════════════════════════════════════╝\n";
echo "</pre>";
