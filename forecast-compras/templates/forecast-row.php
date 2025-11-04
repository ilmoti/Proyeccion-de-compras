<?php
/**
 * Template para una fila de la tabla de proyección
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Detectar si estamos procesando una variación
if (isset($GLOBALS['fc_current_variation_id']) && $GLOBALS['fc_current_variation_id']) {
    $product_id = $GLOBALS['fc_current_variation_id'];
    $product = wc_get_product($product_id);
    $parent_product = $GLOBALS['fc_parent_product'];
} else {
    $product_id = get_the_ID();
    $product = wc_get_product($product_id);
    $parent_product = null;
}

// Obtener SKU
$sku = get_post_meta($product_id, '_alg_ean', true);
if (empty($sku)) {
    $sku = $product->get_sku();
}

// Stock actual
$stock_actual = $product->get_stock_quantity();
if ($stock_actual === null) $stock_actual = 0;

// Calcular días reales del período
if (!empty($this->filters['fecha_desde']) && !empty($this->filters['fecha_hasta'])) {
    $dias_reales = (strtotime($this->filters['fecha_hasta']) - strtotime($this->filters['fecha_desde'])) / 86400 + 1;
    $ventas_data = fc_get_adjusted_sales($product_id, $sku, $dias_reales);
} else {
    $dias_reales = $this->filters['periodo'];
    $ventas_data = fc_get_adjusted_sales($product_id, $sku, $this->filters['periodo']);
}

$ventas_periodo = $ventas_data['ventas_reales'];
$ventas_ajustadas = $ventas_data['ventas_ajustadas'];
$dias_sin_stock = $ventas_data['dias_sin_stock'];
$ventas_perdidas = $ventas_data['ventas_perdidas'];

// Usar ventas ajustadas para el cálculo
$promedio_base = $dias_reales > 0 ? $ventas_ajustadas / $dias_reales : 0;

// Calcular promedio segn tipo
if ($this->filters['tipo_promedio'] == 'semanal') {
    $promedio = round($promedio_base * 7, 2);
    $promedio_diario = $promedio_base;
} elseif ($this->filters['tipo_promedio'] == 'mensual') {
    $promedio = round($promedio_base * 30, 2);
    $promedio_diario = $promedio_base;
} else {
    $promedio = round($promedio_base, 2);
    $promedio_diario = $promedio;
}

// Meses de stock
$meses_stock = $promedio_diario > 0 ? round($stock_actual / ($promedio_diario * 30), 1) : 999;

// Stock en camino
$table_orders = $wpdb->prefix . 'fc_orders_history';
$stock_camino = $wpdb->get_var($wpdb->prepare(
    "SELECT SUM(quantity) FROM $table_orders 
     WHERE sku = %s AND status = 'pending'",
    $sku
));
if (!$stock_camino) $stock_camino = 0;

// Calcular cunto comprar
$necesario = ($promedio_diario * 30 * $this->filters['meses_proyeccion']) - $stock_actual - $stock_camino;
$comprar = max(0, ceil($necesario));

// NUEVO: Aplicar múltiplos por categora
if ($comprar > 0) {
    require_once FC_PLUGIN_PATH . 'includes/class-fc-weight-monitor.php';
    $monitor = new FC_Weight_Monitor();
    $comprar = $monitor->apply_category_multiples($product_id, $comprar);
}

// Estado
if ($meses_stock < 1) {
    $estado = '<span style="color: #d63638;">⚠️ Crítico</span>';
} elseif ($meses_stock < 2) {
    $estado = '<span style="color: #f0b849;">⚠️ Bajo</span>';
} else {
    $estado = '<span style="color: #00a32a;"> OK</span>';
}

// Obtener calidad
$table_qualities = $wpdb->prefix . 'fc_product_qualities';
$calidad = $wpdb->get_var($wpdb->prepare(
    "SELECT quality FROM $table_qualities WHERE sku = %s",
    $sku
));

// Obtener historial de precios
$price_history = $wpdb->get_results($wpdb->prepare("
    SELECT 
        order_name,
        purchase_price,
        arrival_date
    FROM $table_orders 
    WHERE sku = %s 
    AND purchase_price > 0
    ORDER BY arrival_date DESC
    LIMIT 3
", $sku));
?>
<tr>
    <td style="text-align: center;">
        <input type="checkbox" class="fc-product-checkbox" value="<?php echo $product_id; ?>">
    </td>
    <td>
        <strong><?php echo esc_html($sku); ?></strong>
    </td>
    <td>
        <?php 
        // Mostrar nombre correcto para variaciones
        if ($product->is_type('variation') && $parent_product) {
            // Obtener el título base del padre
            $parent_title = $parent_product->get_title();
            
            // Obtener SOLO los atributos de la variación actual
            $variation_attributes = $product->get_variation_attributes();
            $formatted_attributes = array();
            
            foreach ($variation_attributes as $attribute_name => $attribute_value) {
                if (!empty($attribute_value)) {
                    $formatted_attributes[] = $attribute_value;
                }
            }
            
            $attributes_string = implode(', ', $formatted_attributes);
            echo esc_html($parent_title . ' - (' . $attributes_string . ')');
        } else {
            echo esc_html(get_the_title());
        }
        ?>
        <?php if ($calidad): ?>
            <br><small style="color: #666;">Calidad: <?php echo esc_html($calidad); ?></small>
        <?php endif; ?>
    </td>
    <td style="text-align: center;">
        <?php echo $stock_actual; ?>
    </td>
    <td style="text-align: center;">
        <?php echo $ventas_periodo; ?>
    </td>
        <td style="text-align: center;">
        <?php if ($dias_sin_stock > 0): ?>
            <span style="color: #d63638; font-weight: bold;">
                <?php echo $dias_sin_stock; ?>
            </span>
        <?php else: ?>
            <span style="color: #00a32a;">0</span>
        <?php endif; ?>
    </td>
    <td style="text-align: center;">
        <?php if ($ventas_perdidas > 0): ?>
            <span style="color: #f0b849;">
                ~<?php echo $ventas_perdidas; ?>
            </span>
        <?php else: ?>
            -
        <?php endif; ?>
    </td>
    <td style="text-align: center;">
        <?php echo $promedio; ?>
    </td>
    <td style="text-align: center;">
        <?php echo $meses_stock == 999 ? '' : $meses_stock; ?>
    </td>
    <td style="text-align: center;">
        <?php echo $stock_camino; ?>
    </td>
    <td style="text-align: center;">
        <strong style="font-size: 14px;"><?php echo $comprar; ?></strong>
    </td>
    <td style="text-align: center;">
        <?php echo $estado; ?>
    </td>
    <td>
        <button type="button" class="button button-small fc-view-history" data-sku="<?php echo esc_attr($sku); ?>">
            <span class="dashicons dashicons-backup"></span> Historial
        </button>
    </td>
</tr>