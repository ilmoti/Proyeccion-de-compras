<?php
/**
 * Corregir períodos de stockout para SKU 25120504
 * Basándose en el historial real de movimientos
 */

require_once 'wp-load.php';
global $wpdb;

header('Content-Type: text/html; charset=utf-8');
echo "<pre style='font-family: monospace; font-size: 14px;'>";

$sku = '25120504';
$product_id = 71692;

echo "╔══════════════════════════════════════════════════════════════════════╗\n";
echo "║          CORRIGIENDO PERÍODOS SKU: {$sku}                           ║\n";
echo "╚══════════════════════════════════════════════════════════════════════╝\n\n";

$table_stockouts = $wpdb->prefix . 'fc_stockout_periods';

// 1. Eliminar períodos actuales del producto
echo "1. Eliminando períodos incorrectos...\n";
$deleted = $wpdb->delete($table_stockouts, array('product_id' => $product_id), array('%d'));
echo "   ✅ Eliminados: {$deleted} período(s)\n\n";

// 2. Crear los períodos correctos basados en el historial REAL
// Según el CSV que analizamos:
// - 22-09-2025: Stock llegó a 0
// - 05-11-2025: Ingreso de 25 unidades (cierra período)
// - 10-11-2025: Stock llegó a 0 otra vez
// - 20-11-2025: Ingreso de 50 unidades (cierra período)

echo "2. Creando períodos correctos basados en historial real...\n\n";

// Período 1: 22-09-2025 al 05-11-2025
$start1 = '2025-09-22';
$end1 = '2025-11-05';
$d1 = new DateTime($start1);
$d2 = new DateTime($end1);
$dias1 = $d1->diff($d2)->days;

$wpdb->insert(
    $table_stockouts,
    array(
        'product_id' => $product_id,
        'sku' => $sku,
        'start_date' => $start1,
        'end_date' => $end1,
        'days_out' => $dias1
    ),
    array('%d', '%s', '%s', '%s', '%d')
);
echo "   ✅ Período 1: {$start1} → {$end1} ({$dias1} días)\n";

// Período 2: 10-11-2025 al 20-11-2025
$start2 = '2025-11-10';
$end2 = '2025-11-20';
$d3 = new DateTime($start2);
$d4 = new DateTime($end2);
$dias2 = $d3->diff($d4)->days;

$wpdb->insert(
    $table_stockouts,
    array(
        'product_id' => $product_id,
        'sku' => $sku,
        'start_date' => $start2,
        'end_date' => $end2,
        'days_out' => $dias2
    ),
    array('%d', '%s', '%s', '%s', '%d')
);
echo "   ✅ Período 2: {$start2} → {$end2} ({$dias2} días)\n\n";

// 3. Verificar resultado
echo "3. Verificando resultado...\n\n";

$periodos = $wpdb->get_results($wpdb->prepare("
    SELECT * FROM {$table_stockouts}
    WHERE product_id = %d
    ORDER BY start_date ASC
", $product_id));

$total_dias = 0;
echo "   ┌─────────────────┬─────────────────┬──────────┬──────────┐\n";
echo "   │ Fecha Inicio    │ Fecha Fin       │ Días     │ Estado   │\n";
echo "   ├─────────────────┼─────────────────┼──────────┼──────────┤\n";

foreach ($periodos as $p) {
    $estado = $p->end_date ? 'Cerrado' : '🔴 ACTIVO';
    printf("   │ %-15s │ %-15s │ %8s │ %-8s │\n",
        substr($p->start_date, 0, 10),
        $p->end_date ? substr($p->end_date, 0, 10) : 'ABIERTO',
        $p->days_out,
        $estado
    );
    $total_dias += $p->days_out;
}
echo "   └─────────────────┴─────────────────┴──────────┴──────────┘\n";
echo "\n   📊 Total días sin stock: {$total_dias}\n";

// 4. Recalcular proyección
echo "\n4. Nueva proyección...\n\n";

$dias_analisis = 90;
$fecha_inicio = date('Y-m-d', strtotime("-{$dias_analisis} days"));

// Ventas en el período
$total_vendido = $wpdb->get_var($wpdb->prepare("
    SELECT COALESCE(SUM(oim.meta_value), 0)
    FROM {$wpdb->prefix}wc_orders o
    INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON o.id = oi.order_id
    INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id AND oim.meta_key = '_qty'
    INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oip ON oi.order_item_id = oip.order_item_id AND oip.meta_key = '_product_id'
    WHERE oip.meta_value = %d
    AND o.status IN ('wc-completed', 'wc-processing')
    AND o.date_created_gmt >= %s
", $product_id, $fecha_inicio));

// Días sin stock en el rango
$dias_sin_stock = $wpdb->get_var($wpdb->prepare("
    SELECT COALESCE(SUM(
        DATEDIFF(
            LEAST(IFNULL(end_date, CURDATE()), CURDATE()),
            GREATEST(start_date, %s)
        ) + 1
    ), 0)
    FROM {$table_stockouts}
    WHERE product_id = %d
    AND start_date <= CURDATE()
    AND (end_date IS NULL OR end_date >= %s)
", $fecha_inicio, $product_id, $fecha_inicio));

$dias_sin_stock = max(0, intval($dias_sin_stock));
$dias_con_stock = $dias_analisis - $dias_sin_stock;

echo "   Días totales: {$dias_analisis}\n";
echo "   Días SIN stock: {$dias_sin_stock}\n";
echo "   Días CON stock: {$dias_con_stock}\n";
echo "   Total vendido: {$total_vendido} unidades\n\n";

if ($dias_con_stock > 0 && $total_vendido > 0) {
    $promedio_ajustado = $total_vendido / $dias_con_stock;
    $promedio_sin_ajustar = $total_vendido / $dias_analisis;

    $proyeccion_30_ajustada = ceil($promedio_ajustado * 30);
    $proyeccion_30_sin_ajustar = ceil($promedio_sin_ajustar * 30);

    echo "   📈 Promedio diario AJUSTADO: " . number_format($promedio_ajustado, 2) . " u/día\n";
    echo "   📉 Promedio diario SIN AJUSTAR: " . number_format($promedio_sin_ajustar, 2) . " u/día\n\n";
    echo "   🎯 Proyección 30 días:\n";
    echo "      - CON ajuste: {$proyeccion_30_ajustada} unidades\n";
    echo "      - SIN ajuste: {$proyeccion_30_sin_ajustar} unidades\n";
}

echo "\n╔══════════════════════════════════════════════════════════════════════╗\n";
echo "║                     ✅ CORRECCIÓN COMPLETADA                        ║\n";
echo "╚══════════════════════════════════════════════════════════════════════╝\n";
echo "</pre>";
