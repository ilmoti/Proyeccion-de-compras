<?php
/**
 * Script para corregir TODOS los períodos de una vez
 * Puede tardar varios minutos
 */

require_once 'wp-load.php';
global $wpdb;

// Aumentar MUCHO el tiempo de ejecución
set_time_limit(600); // 10 minutos
ini_set('max_execution_time', 600);
ini_set('memory_limit', '512M');

header('Content-Type: text/plain; charset=utf-8');

echo "========== CORRIGIENDO TODOS LOS PERÍODOS ==========\n\n";
echo "Esto puede tardar varios minutos...\n\n";

// Buscar TODOS los períodos abiertos
$open_periods = $wpdb->get_results("
    SELECT
        sp.id,
        sp.product_id,
        sp.sku,
        sp.start_date,
        pm.meta_value as stock_actual,
        p.post_title
    FROM {$wpdb->prefix}fc_stockout_periods sp
    INNER JOIN {$wpdb->posts} p ON sp.product_id = p.ID
    LEFT JOIN {$wpdb->postmeta} pm ON sp.product_id = pm.post_id AND pm.meta_key = '_stock'
    WHERE sp.end_date IS NULL
    ORDER BY sp.start_date DESC
");

$total = count($open_periods);
echo "Total de períodos abiertos: {$total}\n\n";

if ($total == 0) {
    echo "✅ No hay períodos para corregir.\n";
    exit;
}

$corregidos = 0;
$correctos = 0;
$procesados = 0;

foreach ($open_periods as $period) {
    $procesados++;
    $stock = intval($period->stock_actual);

    // Mostrar progreso cada 10 productos
    if ($procesados % 10 == 0) {
        echo "\n--- Progreso: {$procesados}/{$total} ---\n";
        flush();
    }

    echo "ID: {$period->id} | SKU: {$period->sku} | Stock: {$stock}\n";

    if ($stock > 0) {
        // Buscar primera venta
        $primera_venta = $wpdb->get_row($wpdb->prepare("
            SELECT MIN(DATE(p.post_date)) as fecha_venta
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
        ", $period->start_date, $period->product_id, $period->product_id));

        if ($primera_venta && $primera_venta->fecha_venta) {
            $dias = $wpdb->get_var($wpdb->prepare(
                "SELECT DATEDIFF(%s, %s)",
                $primera_venta->fecha_venta,
                $period->start_date
            ));

            $wpdb->update(
                $wpdb->prefix . 'fc_stockout_periods',
                array('end_date' => $primera_venta->fecha_venta, 'days_out' => $dias),
                array('id' => $period->id),
                array('%s', '%d'),
                array('%d')
            );

            echo "  ✅ Corregido ({$dias} días)\n";
            $corregidos++;
        } else {
            $wpdb->update(
                $wpdb->prefix . 'fc_stockout_periods',
                array('end_date' => $period->start_date, 'days_out' => 0),
                array('id' => $period->id),
                array('%s', '%d'),
                array('%d')
            );

            echo "  ✅ Corregido (0 días)\n";
            $corregidos++;
        }
    } else {
        echo "  ⏭️ Correcto (sin stock)\n";
        $correctos++;
    }
}

echo "\n\n========== ✅ COMPLETADO ==========\n";
echo "Total procesados: {$total}\n";
echo "Períodos corregidos: {$corregidos}\n";
echo "Períodos correctos: {$correctos}\n";
echo "\n⚠️ PRÓXIMO PASO:\n";
echo "Ve a WordPress → Forecast Dashboard → Configuración\n";
echo "Click en 'Actualizar Métricas'\n";
