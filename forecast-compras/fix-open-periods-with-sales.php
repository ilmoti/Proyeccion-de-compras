<?php
/**
 * Corregir períodos abiertos detectando ventas posteriores
 *
 * LÓGICA:
 * Si un período está abierto PERO hubo ventas después del inicio,
 * significa que en algún momento volvió a haber stock.
 *
 * Entonces:
 * 1. Cerrar el período en la fecha de la primera venta
 * 2. Buscar la última venta
 * 3. Abrir un nuevo período desde esa última venta
 */

require_once 'wp-load.php';
global $wpdb;

set_time_limit(600);
ini_set('max_execution_time', 600);
ini_set('memory_limit', '512M');

header('Content-Type: text/plain; charset=utf-8');

echo "========== CORREGIR PERÍODOS CON VENTAS POSTERIORES ==========\n\n";

// Buscar períodos abiertos
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
    ORDER BY sp.start_date ASC
");

echo "Total de períodos abiertos: " . count($open_periods) . "\n\n";

$corregidos = 0;
$correctos = 0;
$procesados = 0;

foreach ($open_periods as $period) {
    $procesados++;
    $stock = intval($period->stock_actual);

    if ($procesados % 10 == 0) {
        echo "\n--- Progreso: {$procesados}/" . count($open_periods) . " ---\n\n";
        flush();
    }

    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "ID: {$period->id} | SKU: {$period->sku}\n";
    echo "Producto: {$period->post_title}\n";
    echo "Período abierto desde: {$period->start_date}\n";
    echo "Stock actual: {$stock}\n";

    // Buscar ventas DESPUÉS del inicio del período
    $ventas = $wpdb->get_results($wpdb->prepare("
        SELECT DATE(p.post_date) as fecha_venta, SUM(oim.meta_value) as cantidad
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
        ORDER BY fecha_venta ASC
    ", $period->start_date, $period->product_id, $period->product_id));

    if (empty($ventas)) {
        echo "✅ CORRECTO - No hay ventas después del inicio del período\n";
        $correctos++;
        continue;
    }

    // Si hay ventas, significa que en algún momento volvió a tener stock
    echo "⚠️ INCORRECTO - Hay " . count($ventas) . " días con ventas después del período\n";
    echo "   Primera venta: {$ventas[0]->fecha_venta} ({$ventas[0]->cantidad} unidades)\n";

    $ultima_venta = end($ventas);
    echo "   Última venta: {$ultima_venta->fecha_venta} ({$ultima_venta->cantidad} unidades)\n\n";

    // Calcular días del primer período (hasta la primera venta)
    $dias_primer_periodo = $wpdb->get_var($wpdb->prepare(
        "SELECT DATEDIFF(%s, %s)",
        $ventas[0]->fecha_venta,
        $period->start_date
    ));

    // 1. CERRAR el período actual en la fecha de la primera venta
    echo "   1. Cerrando período ID {$period->id} en {$ventas[0]->fecha_venta} ({$dias_primer_periodo} días)...\n";

    $wpdb->update(
        $wpdb->prefix . 'fc_stockout_periods',
        array(
            'end_date' => $ventas[0]->fecha_venta,
            'days_out' => $dias_primer_periodo
        ),
        array('id' => $period->id),
        array('%s', '%d'),
        array('%d')
    );

    // 2. Si actualmente NO tiene stock, ABRIR nuevo período desde la última venta
    if ($stock <= 0) {
        echo "   2. Abriendo nuevo período desde {$ultima_venta->fecha_venta}...\n";

        // Verificar que no exista ya un período para esa fecha
        $existe_nuevo = $wpdb->get_var($wpdb->prepare("
            SELECT id
            FROM {$wpdb->prefix}fc_stockout_periods
            WHERE product_id = %d
            AND start_date >= %s
            AND id != %d
        ", $period->product_id, $ultima_venta->fecha_venta, $period->id));

        if (!$existe_nuevo) {
            // Calcular días del nuevo período (desde última venta hasta hoy)
            $dias_nuevo_periodo = $wpdb->get_var($wpdb->prepare(
                "SELECT DATEDIFF(CURDATE(), %s)",
                $ultima_venta->fecha_venta
            ));

            $wpdb->insert(
                $wpdb->prefix . 'fc_stockout_periods',
                array(
                    'product_id' => $period->product_id,
                    'sku' => $period->sku,
                    'start_date' => $ultima_venta->fecha_venta,
                    'end_date' => NULL,
                    'days_out' => $dias_nuevo_periodo
                ),
                array('%d', '%s', '%s', '%s', '%d')
            );

            echo "   ✅ Nuevo período creado (ID: {$wpdb->insert_id}, {$dias_nuevo_periodo} días hasta hoy)\n";
        } else {
            echo "   ℹ️ Ya existe un período posterior\n";
        }
    } else {
        echo "   2. Producto tiene stock ({$stock}), no se abre nuevo período\n";
    }

    echo "   ✅ CORREGIDO\n\n";
    $corregidos++;
}

echo "\n========== ✅ COMPLETADO ==========\n";
echo "Total procesados: " . count($open_periods) . "\n";
echo "Períodos corregidos: {$corregidos}\n";
echo "Períodos correctos: {$correctos}\n";
echo "\n⚠️ PRÓXIMO PASO:\n";
echo "Ve a WordPress → Forecast Dashboard → Configuración\n";
echo "Click en 'Actualizar Métricas'\n";
