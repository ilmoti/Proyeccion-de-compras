<?php
/**
 * Funciones auxiliares del plugin
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Función para obtener ventas de un producto en un período
function fc_get_product_sales($product_id, $sku, $days) {
    global $wpdb;
    
    // Fecha de inicio
    $start_date = date('Y-m-d', strtotime("-{$days} days"));
    
    // Buscar por SKU en los pedidos de WooCommerce
    $query = "
        SELECT SUM(oim.meta_value) as total_vendido
        FROM {$wpdb->prefix}woocommerce_order_items oi
        INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim 
            ON oi.order_item_id = oim.order_item_id
        INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim2 
            ON oi.order_item_id = oim2.order_item_id
        INNER JOIN {$wpdb->posts} p 
            ON oi.order_id = p.ID
        WHERE p.post_type = 'shop_order'
        AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-shipped')
        AND p.post_date >= %s
        AND oim.meta_key = '_qty'
        AND (
            (oim2.meta_key = '_product_id' AND oim2.meta_value = %d)
            OR (oim2.meta_key = '_variation_id' AND oim2.meta_value = %d)
        )
    ";
    
    $total = $wpdb->get_var($wpdb->prepare($query, $start_date, $product_id, $product_id));
    
    return $total ? intval($total) : 0;
}

// Función para obtener ventas por fechas especficas
function fc_get_product_sales_by_dates($product_id, $sku, $fecha_desde, $fecha_hasta) {
    global $wpdb;
    
    // Ajustar fecha hasta para incluir todo el día
    $fecha_hasta_completa = $fecha_hasta . ' 23:59:59';
    
    $query = "
        SELECT SUM(oim.meta_value) as total_vendido
        FROM {$wpdb->prefix}woocommerce_order_items oi
        INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim 
            ON oi.order_item_id = oim.order_item_id
        INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim2 
            ON oi.order_item_id = oim2.order_item_id
        INNER JOIN {$wpdb->posts} p 
            ON oi.order_id = p.ID
        WHERE p.post_type = 'shop_order'
        AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-shipped')
        AND p.post_date >= %s
        AND p.post_date <= %s
        AND oim.meta_key = '_qty'
        AND (
            (oim2.meta_key = '_product_id' AND oim2.meta_value = %d)
            OR (oim2.meta_key = '_variation_id' AND oim2.meta_value = %d)
        )
    ";
    
    $total = $wpdb->get_var($wpdb->prepare($query, $fecha_desde, $fecha_hasta_completa, $product_id, $product_id));
    
    return $total ? intval($total) : 0;
}

// Función para obtener ventas ajustadas por días sin stock
function fc_get_adjusted_sales($product_id, $sku, $days) {
    global $wpdb;

    // Obtener ventas normales
    $ventas_reales = fc_get_product_sales($product_id, $sku, $days);

    // Obtener días sin stock en el período
    $table_stockouts = $wpdb->prefix . 'fc_stockout_periods';
    $fecha_inicio = date('Y-m-d', strtotime("-{$days} days"));
    $fecha_hoy = date('Y-m-d');

    // NUEVO CÁLCULO CORREGIDO: Calcula solo los días SIN STOCK dentro del rango
    $dias_sin_stock = $wpdb->get_var($wpdb->prepare("
        SELECT SUM(
            DATEDIFF(
                LEAST(IFNULL(end_date, %s), %s),
                GREATEST(start_date, %s)
            ) + 1
        ) as total_dias
        FROM $table_stockouts
        WHERE product_id = %d
        AND start_date <= %s
        AND (end_date IS NULL OR end_date >= %s)
    ", $fecha_hoy, $fecha_hoy, $fecha_inicio, $product_id, $fecha_hoy, $fecha_inicio));

    $dias_sin_stock = max(0, intval($dias_sin_stock ?: 0));

    // Asegurar que no supere el total de días
    if ($dias_sin_stock > $days) {
        $dias_sin_stock = $days;
    }

    $dias_con_stock = $days - $dias_sin_stock;

    // Calcular proyección ajustada
    if ($dias_con_stock > 0) {
        $ventas_ajustadas = ($ventas_reales / $dias_con_stock) * $days;
    } else {
        // Si no hubo días con stock, no podemos proyectar
        $ventas_ajustadas = $ventas_reales;
    }

    return array(
        'ventas_reales' => $ventas_reales,
        'dias_sin_stock' => $dias_sin_stock,
        'dias_con_stock' => $dias_con_stock,
        'ventas_ajustadas' => round($ventas_ajustadas),
        'ventas_perdidas' => round($ventas_ajustadas - $ventas_reales)
    );
}
// Actualizar métricas de un producto
function fc_update_product_metrics($product_id) {
    global $wpdb;
    
    $product = wc_get_product($product_id);
    if (!$product) return;
    
    $sku = $product->get_sku();
    $stock_actual = $product->get_stock_quantity() ?: 0;
    $ventas_data = fc_get_adjusted_sales($product_id, $sku, 30);
    $promedio_diario = $ventas_data['ventas_ajustadas'] / 30;
    $meses_stock = $promedio_diario > 0 ? $stock_actual / ($promedio_diario * 30) : 999;
    
    $wpdb->replace(
        $wpdb->prefix . 'fc_product_metrics',
        array(
            'product_id' => $product_id,
            'stock_actual' => $stock_actual,
            'ventas_30_dias' => $ventas_data['ventas_ajustadas'],
            'promedio_diario' => $promedio_diario,
            'meses_stock' => $meses_stock,
            'ultima_actualizacion' => current_time('mysql')
        )
    );
}

// Actualizar todas las métricas por lotes
function fc_update_all_metrics($offset = 0, $limit = 50) {
    $products = wc_get_products(array(
        'limit' => $limit,
        'offset' => $offset,
        'status' => 'publish',
        'return' => 'ids'
    ));
    
    if (empty($products)) {
        return 0; // No hay más productos
    }
    
    foreach ($products as $product_id) {
        fc_update_product_metrics($product_id);
    }
    
    return count($products); // Retornar cuántos procesó
}