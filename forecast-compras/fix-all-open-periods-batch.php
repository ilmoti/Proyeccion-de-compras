<?php
/**
 * Script para corregir períodos sin stock - VERSION POR LOTES
 * No da timeout porque procesa de a 10 productos
 */

require_once 'wp-load.php';
global $wpdb;

// Aumentar tiempo de ejecución
set_time_limit(300);
ini_set('max_execution_time', 300);

// Obtener offset desde la URL
$offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
$limit = 10; // Procesar 10 productos por vez

// Si no es el primer lote, usar content-type text/html para que funcione el redirect
if ($offset == 0) {
    header('Content-Type: text/plain; charset=utf-8');
} else {
    header('Content-Type: text/html; charset=utf-8');
    echo "<pre style='font-family: monospace;'>";
}

echo "========== CORRIGIENDO PERÍODOS (Lote desde {$offset}) ==========\n\n";

// Buscar períodos abiertos (limitado)
$open_periods = $wpdb->get_results($wpdb->prepare("
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
    LIMIT %d, %d
", $offset, $limit));

$total_open = $wpdb->get_var("
    SELECT COUNT(*)
    FROM {$wpdb->prefix}fc_stockout_periods
    WHERE end_date IS NULL
");

echo "Total de períodos abiertos: {$total_open}\n";
echo "Procesando lote: {$offset} a " . ($offset + $limit) . "\n\n";

if (empty($open_periods)) {
    echo "\n========== ✅ TODOS LOS PERÍODOS PROCESADOS ==========\n";
    echo "Ya no hay más períodos para corregir.\n";
    echo "\n⚠️ PRÓXIMO PASO:\n";
    echo "Ve a WordPress → Forecast Dashboard → Configuración\n";
    echo "Click en 'Actualizar Métricas' para recalcular las proyecciones.\n";

    if ($offset > 0) echo "</pre>";
    exit;
}

$corregidos = 0;
$correctos = 0;

foreach ($open_periods as $period) {
    $stock = intval($period->stock_actual);

    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "ID: {$period->id} | SKU: {$period->sku}\n";
    echo "Producto: {$period->post_title}\n";
    echo "Stock actual: {$stock}\n";
    echo "Período abierto desde: {$period->start_date}\n";

    if ($stock > 0) {
        echo "❌ INCORRECTO - Tiene stock pero período abierto\n";

        // Buscar primera venta después del período
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
            echo "   Primera venta: {$primera_venta->fecha_venta}\n";
            echo "   Cerrando período en esa fecha...\n";

            $dias = $wpdb->get_var($wpdb->prepare(
                "SELECT DATEDIFF(%s, %s)",
                $primera_venta->fecha_venta,
                $period->start_date
            ));

            $result = $wpdb->update(
                $wpdb->prefix . 'fc_stockout_periods',
                array(
                    'end_date' => $primera_venta->fecha_venta,
                    'days_out' => $dias
                ),
                array('id' => $period->id),
                array('%s', '%d'),
                array('%d')
            );

            if ($result !== false) {
                echo "   ✅ Corregido ({$dias} días sin stock)\n";
                $corregidos++;
            } else {
                echo "   ❌ Error: {$wpdb->last_error}\n";
            }
        } else {
            echo "   ⚠️ No hay ventas después del período\n";
            echo "   Cerrando con fecha de inicio (0 días)...\n";

            $result = $wpdb->update(
                $wpdb->prefix . 'fc_stockout_periods',
                array(
                    'end_date' => $period->start_date,
                    'days_out' => 0
                ),
                array('id' => $period->id),
                array('%s', '%d'),
                array('%d')
            );

            if ($result !== false) {
                echo "   ✅ Corregido (0 días)\n";
                $corregidos++;
            }
        }
    } else {
        echo "✅ CORRECTO - Realmente está sin stock\n";
        $correctos++;
    }

    echo "\n";
    flush(); // Enviar output al navegador inmediatamente
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "\n========== RESUMEN DE ESTE LOTE ==========\n";
echo "Períodos corregidos: {$corregidos}\n";
echo "Períodos correctos: {$correctos}\n";
echo "Total procesados en este lote: " . count($open_periods) . "\n\n";

$siguiente_offset = $offset + $limit;
$quedan = $total_open - $siguiente_offset;

if ($quedan > 0) {
    echo "⚠️ QUEDAN {$quedan} PERÍODOS POR PROCESAR\n\n";
    echo "Redirigiendo al siguiente lote...\n";

    if ($offset > 0) echo "</pre>";

    // JavaScript redirect que SÍ funciona
    ?>
    <script>
        setTimeout(function() {
            window.location.href = '?offset=<?php echo $siguiente_offset; ?>';
        }, 1000);
    </script>
    <p><a href="?offset=<?php echo $siguiente_offset; ?>">O haz click aquí para continuar manualmente</a></p>
    <?php
} else {
    echo "========== ✅ TODOS LOS PERÍODOS CORREGIDOS ==========\n";
    echo "\n⚠️ IMPORTANTE: Ahora recalcula las métricas\n";
    echo "Ve a WordPress → Forecast Dashboard → Configuración\n";
    echo "Click en 'Actualizar Métricas'\n";

    if ($offset > 0) echo "</pre>";
}
