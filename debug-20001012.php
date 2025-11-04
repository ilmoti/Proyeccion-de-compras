<?php
/**
 * Debug detallado del SKU 20001012
 */

require_once 'wp-load.php';
global $wpdb;

header('Content-Type: text/plain; charset=utf-8');

echo "========== DEBUG SKU 20001012 ==========\n\n";

// 1. Buscar el producto
$product = $wpdb->get_row("
    SELECT p.ID, p.post_title, pm.meta_value as stock, pm2.meta_value as sku
    FROM {$wpdb->posts} p
    LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_stock'
    LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_alg_ean'
    WHERE pm2.meta_value = '20001012'
    LIMIT 1
");

if (!$product) {
    die("❌ Producto no encontrado\n");
}

echo "PRODUCTO:\n";
echo "ID: {$product->ID}\n";
echo "Nombre: {$product->post_title}\n";
echo "Stock actual: {$product->stock}\n";
echo "SKU: {$product->sku}\n\n";

// 2. Períodos de stockout
echo "PERÍODOS DE STOCKOUT:\n";
$periods = $wpdb->get_results($wpdb->prepare("
    SELECT *
    FROM {$wpdb->prefix}fc_stockout_periods
    WHERE product_id = %d
    ORDER BY start_date DESC
", $product->ID));

if (empty($periods)) {
    echo "✅ No hay períodos registrados\n\n";
} else {
    foreach ($periods as $p) {
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        echo "ID: {$p->id}\n";
        echo "Inicio: {$p->start_date}\n";
        echo "Fin: " . ($p->end_date ?: 'ABIERTO') . "\n";
        echo "Días: {$p->days_out}\n";
    }
    echo "\n";
}

// 3. Calcular días sin stock en últimos 90 días (método actual del plugin)
$fecha_inicio = date('Y-m-d', strtotime('-90 days'));
$fecha_hoy = date('Y-m-d');

echo "CÁLCULO DE DÍAS SIN STOCK (últimos 90 días):\n";
echo "Rango: {$fecha_inicio} a {$fecha_hoy}\n\n";

$dias_sin_stock = $wpdb->get_var($wpdb->prepare("
    SELECT SUM(
        DATEDIFF(
            LEAST(IFNULL(end_date, %s), %s),
            GREATEST(start_date, %s)
        ) + 1
    ) as total_dias
    FROM {$wpdb->prefix}fc_stockout_periods
    WHERE product_id = %d
    AND start_date <= %s
    AND (end_date IS NULL OR end_date >= %s)
", $fecha_hoy, $fecha_hoy, $fecha_inicio, $product->ID, $fecha_hoy, $fecha_inicio));

echo "Días sin stock calculados: " . ($dias_sin_stock ?: 0) . "\n\n";

// 4. Ventas en últimos 90 días
echo "VENTAS EN ÚLTIMOS 90 DÍAS:\n";
$ventas = $wpdb->get_results($wpdb->prepare("
    SELECT DATE(p.post_date) as fecha, SUM(oim.meta_value) as cantidad
    FROM {$wpdb->prefix}woocommerce_order_items oi
    INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
    INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim2 ON oi.order_item_id = oim2.order_item_id
    INNER JOIN {$wpdb->posts} p ON oi.order_id = p.ID
    WHERE p.post_type = 'shop_order'
        AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-shipped')
        AND p.post_date >= %s
        AND oim.meta_key = '_qty'
        AND (
            (oim2.meta_key = '_product_id' AND oim2.meta_value = %d)
            OR (oim2.meta_key = '_variation_id' AND oim2.meta_value = %d)
        )
    GROUP BY DATE(p.post_date)
    ORDER BY fecha DESC
    LIMIT 20
", $fecha_inicio, $product->ID, $product->ID));

if (empty($ventas)) {
    echo "❌ No hay ventas registradas\n\n";
} else {
    $total_vendido = 0;
    foreach ($ventas as $v) {
        echo "{$v->fecha}: {$v->cantidad} unidades\n";
        $total_vendido += $v->cantidad;
    }
    echo "\nTotal vendido en 90 días: {$total_vendido}\n";

    $dias_con_stock = 90 - ($dias_sin_stock ?: 0);
    $promedio_diario = $dias_con_stock > 0 ? $total_vendido / $dias_con_stock : 0;

    echo "Días con stock: {$dias_con_stock}\n";
    echo "Promedio diario: " . number_format($promedio_diario, 2) . "\n\n";
}

// 5. Verificar datos en fc_product_qualities
echo "DATOS EN fc_product_qualities:\n";
$quality = $wpdb->get_row($wpdb->prepare("
    SELECT *
    FROM {$wpdb->prefix}fc_product_qualities
    WHERE product_id = %d
", $product->ID));

if ($quality) {
    echo "Días sin stock (guardado): {$quality->days_out_of_stock}\n";
    echo "Ventas ajustadas: {$quality->adjusted_sales}\n";
    echo "Ventas diarias promedio: {$quality->avg_daily_sales}\n";
    echo "Fecha actualización: {$quality->last_updated}\n";
} else {
    echo "❌ No hay datos en fc_product_qualities\n";
}

echo "\n========== FIN DEBUG ==========\n";
