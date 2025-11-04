<?php
/**
 * Clase para manejar peticiones AJAX
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

class FC_Ajax_Handler {
    
    public function __construct() {
        // Acciones AJAX para usuarios logueados
        add_action('wp_ajax_fc_get_product_history', array($this, 'get_product_history'));
        add_action('wp_ajax_fc_update_quality', array($this, 'update_quality'));
        add_action('wp_ajax_fc_mark_received', array($this, 'mark_received'));
        add_action('wp_ajax_fc_get_sales_data', array($this, 'get_sales_data'));
        add_action('wp_ajax_fc_check_low_stock', array($this, 'check_low_stock'));
        add_action('wp_ajax_fc_delete_items', array($this, 'delete_items'));
        add_action('wp_ajax_fc_count_alert_products', array($this, 'count_alert_products'));
        add_action('wp_ajax_fc_process_export_batch', array($this, 'process_export_batch'));
        add_action('wp_ajax_fc_load_more_alert_products', array($this, 'load_more_alert_products'));
        add_action('wp_ajax_fc_find_similar_products', array($this, 'find_similar_products'));
        add_action('wp_ajax_fc_get_product_history_dead_stock', array($this, 'get_product_history_dead_stock'));
        add_action('wp_ajax_fc_get_price_history', array($this, 'get_price_history'));
    }
    
    // Obtener historial de un producto
    public function get_product_history() {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'], 'fc_ajax_nonce')) {
            wp_die('Seguridad: nonce inválido');
        }
        
        $sku = sanitize_text_field($_POST['sku']);
        
        global $wpdb;
        $table_orders = $wpdb->prefix . 'fc_orders_history';
        
        $history = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM $table_orders 
            WHERE sku = %s 
            ORDER BY arrival_date DESC 
            LIMIT 10
        ", $sku));
        
        if ($history) {
            $response = array(
                'success' => true,
                'data' => array()
            );
            
            foreach ($history as $order) {
                $response['data'][] = array(
                    'fecha' => date('d/m/Y', strtotime($order->arrival_date)),
                    'order_name' => $order->order_name,  // AGREGAR ESTA LNEA
                    'cantidad' => $order->quantity,
                    'precio' => '$' . number_format($order->purchase_price, 2),
                    'calidad' => $order->quality,
                    'estado' => $order->status == 'pending' ? 'Pendiente' : 'Recibido'
                );
            }
            
            // Calcular estadsticas
            $avg_price = $wpdb->get_var($wpdb->prepare("
                SELECT AVG(purchase_price) 
                FROM $table_orders 
                WHERE sku = %s AND purchase_price > 0
            ", $sku));
            
            $total_ordered = $wpdb->get_var($wpdb->prepare("
                SELECT SUM(quantity) 
                FROM $table_orders 
                WHERE sku = %s
            ", $sku));
            
            $response['stats'] = array(
                'precio_promedio' => '$' . number_format($avg_price ?: 0, 2),
                'total_ordenado' => number_format($total_ordered ?: 0),
                'ordenes_totales' => count($history)
            );
            
        } else {
            $response = array(
                'success' => false,
                'message' => 'No hay historial para este producto'
            );
        }
        
        wp_send_json($response);
    }
    
    // Actualizar calidad de un producto
    public function update_quality() {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'], 'fc_ajax_nonce')) {
            wp_die('Seguridad: nonce invlido');
        }
        
        $sku = sanitize_text_field($_POST['sku']);
        $quality = sanitize_text_field($_POST['quality']);
        
        global $wpdb;
        $table_qualities = $wpdb->prefix . 'fc_product_qualities';
        
        $result = $wpdb->replace(
            $table_qualities,
            array(
                'sku' => $sku,
                'quality' => $quality
            ),
            array('%s', '%s')
        );
        
        if ($result !== false) {
            wp_send_json_success(array(
                'message' => 'Calidad actualizada correctamente'
            ));
        } else {
            wp_send_json_error(array(
                'message' => 'Error al actualizar la calidad'
            ));
        }
    }
    
    // Marcar orden como recibida
    public function mark_received() {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'], 'fc_ajax_nonce')) {
            wp_die('Seguridad: nonce invlido');
        }
        
        $order_id = intval($_POST['order_id']);
        
        global $wpdb;
        $table_orders = $wpdb->prefix . 'fc_orders_history';
        
        $result = $wpdb->update(
            $table_orders,
            array(
                'status' => 'received',
                'received_date' => current_time('mysql')
            ),
            array('id' => $order_id),
            array('%s', '%s'),
            array('%d')
        );
        
        if ($result !== false) {
            wp_send_json_success(array(
                'message' => 'Orden marcada como recibida'
            ));
        } else {
            wp_send_json_error(array(
                'message' => 'Error al actualizar la orden'
            ));
        }
    }
    
    // Obtener datos de ventas para gráficos
    public function get_sales_data() {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'], 'fc_ajax_nonce')) {
            wp_die('Seguridad: nonce invlido');
        }
        
        $product_id = intval($_POST['product_id']);
        $days = intval($_POST['days']) ?: 30;
        
        global $wpdb;
        $data = array();
        
        // Obtener ventas diarias
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            
            $sales = $wpdb->get_var($wpdb->prepare("
                SELECT SUM(oim.meta_value)
                FROM {$wpdb->prefix}woocommerce_order_items oi
                INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim 
                    ON oi.order_item_id = oim.order_item_id
                INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim2 
                    ON oi.order_item_id = oim2.order_item_id
                INNER JOIN {$wpdb->posts} p 
                    ON oi.order_id = p.ID
                WHERE p.post_type = 'shop_order'
                AND o.post_status IN ('wc-completed', 'wc-processing', 'wc-shipped')
                AND DATE(p.post_date) = %s
                AND oim.meta_key = '_qty'
                AND oim2.meta_key = '_product_id'
                AND oim2.meta_value = %d
            ", $date, $product_id));
            
            $data[] = array(
                'date' => date('d/m', strtotime($date)),
                'sales' => intval($sales ?: 0)
            );
        }
        
        wp_send_json_success($data);
    }
    
    // Verificar productos con stock bajo
    public function check_low_stock() {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'], 'fc_ajax_nonce')) {
            wp_die('Seguridad: nonce invlido');
        }
        
        global $wpdb;
        
        // Obtener productos con stock bajo
        $low_stock_products = $wpdb->get_results("
            SELECT 
                p.ID,
                p.post_title,
                pm1.meta_value as stock,
                pm2.meta_value as sku
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_stock'
            LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_alg_ean'
            WHERE p.post_type = 'product'
            AND p.post_status = 'publish'
            AND CAST(pm1.meta_value AS SIGNED) > 0
            ORDER BY CAST(pm1.meta_value AS SIGNED) ASC
            LIMIT 20
        ");
        
        $alerts = array();
        
        foreach ($low_stock_products as $product) {
            $product_id = $product->ID;
            $stock = intval($product->stock);
            $sku = $product->sku ?: get_post_meta($product_id, '_sku', true);
            
            // Calcular ventas promedio
            $avg_sales = fc_get_product_sales($product_id, $sku, 30) / 30;
            
            if ($avg_sales > 0) {
                $days_remaining = $stock / $avg_sales;
                
                if ($days_remaining < 30) { // Menos de 1 mes
                    $alerts[] = array(
                        'product_id' => $product_id,
                        'name' => $product->post_title,
                        'sku' => $sku,
                        'stock' => $stock,
                        'avg_daily_sales' => round($avg_sales, 2),
                        'days_remaining' => round($days_remaining, 1),
                        'urgency' => $days_remaining < 7 ? 'critical' : ($days_remaining < 15 ? 'warning' : 'low')
                    );
                }
            }
        }
        
        // Ordenar por urgencia
        usort($alerts, function($a, $b) {
            return $a['days_remaining'] - $b['days_remaining'];
        });
        
        wp_send_json_success($alerts);
    }
    public function delete_items() {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'], 'fc_delete_items')) {
            wp_send_json_error(array('message' => 'Nonce inválido'));
        }
        
        // Verificar permisos
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Sin permisos'));
        }
        
        $items = array_map('intval', $_POST['items']);
        
        if (empty($items)) {
            wp_send_json_error(array('message' => 'No se especificaron items'));
        }
        
        global $wpdb;
        $table_orders = $wpdb->prefix . 'fc_orders_history';
        
        // Construir la consulta de forma segura
        $placeholders = implode(',', array_fill(0, count($items), '%d'));
        
        // Preparar la consulta
        $query = "DELETE FROM $table_orders WHERE id IN ($placeholders) AND status = 'pending'";
        
        // Ejecutar la consulta
        $deleted = $wpdb->query(
            $wpdb->prepare($query, $items)
        );
        
        if ($deleted) {
            wp_send_json_success(array(
                'message' => sprintf('%d items eliminados correctamente.', $deleted)
            ));
        } else {
            wp_send_json_error(array('message' => 'No se pudieron eliminar los items.'));
        }
    }
    // NUEVO: Contar productos de alerta
    public function count_alert_products() {
        if (!wp_verify_nonce($_POST['_ajax_nonce'], 'fc_export_nonce')) {
            wp_send_json_error('Nonce invlido');
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Sin permisos');
        }
        
        $alert_id = intval($_POST['alert_id']);
        
        global $wpdb;
        $alert = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fc_weight_alerts WHERE id = %d",
            $alert_id
        ));
        
        if (!$alert) {
            wp_send_json_error('Alerta no encontrada');
        }
        
        // Contar productos que aplican
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'fields' => 'ids'
        );
        
        // Aplicar filtros
        if (!empty($alert->categories)) {
            $args['tax_query'][] = array(
                'taxonomy' => 'product_cat',
                'field' => 'term_id',
                'terms' => explode(',', $alert->categories)
            );
        }
        
        $query = new WP_Query($args);
        $total = $query->found_posts;
        
        // Considerar variaciones
        $total_with_variations = 0;
        foreach ($query->posts as $product_id) {
            $product = wc_get_product($product_id);
            if ($product && $product->is_type('variable')) {
                $total_with_variations += count($product->get_children());
            } else {
                $total_with_variations++;
            }
        }
        
        wp_send_json_success(array('total' => $total_with_variations));
    }
    
    // NUEVO: Procesar lote de exportación
    public function process_export_batch() {
        if (!wp_verify_nonce($_POST['_ajax_nonce'], 'fc_export_nonce')) {
            wp_send_json_error('Nonce invlido');
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Sin permisos');
        }
        
        $alert_id = intval($_POST['alert_id']);
        $offset = intval($_POST['offset']);
        $batch_size = intval($_POST['batch_size']) ?: 50;
        
        // Aumentar límites
        set_time_limit(120);
        
        // Guardar progreso en transient
        $progress_key = 'fc_export_progress_' . $alert_id;
        $progress = get_transient($progress_key) ?: array('products' => array());
        
        global $wpdb;
        $alert = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fc_weight_alerts WHERE id = %d",
            $alert_id
        ));
        
        if (!$alert) {
            wp_send_json_error('Alerta no encontrada');
        }
        
        // Obtener productos por lote
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => $batch_size,
            'offset' => $offset,
            'post_status' => 'publish',
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC'
        );
        
        // Aplicar filtros de categorías y tags
        if (!empty($alert->categories)) {
            $categories = explode(',', $alert->categories);
            $args['tax_query'][] = array(
                'taxonomy' => 'product_cat',
                'field' => 'term_id',
                'terms' => array_map('intval', $categories),
                'include_children' => true
            );
        }
        
        if (!empty($alert->tags)) {
            $tags = explode(',', $alert->tags);
            $args['tax_query'][] = array(
                'taxonomy' => 'product_tag',
                'field' => 'slug',
                'terms' => $tags
            );
        }
        
        $products = get_posts($args);
        $processed = 0;
        
        require_once FC_PLUGIN_PATH . 'includes/class-fc-weight-monitor.php';
        $monitor = new FC_Weight_Monitor();
        
        foreach ($products as $product_id) {
            $product = wc_get_product($product_id);
            
            if ($product && $product->is_type('variable')) {
                // Procesar variaciones
                $variations = $product->get_children();
                foreach ($variations as $variation_id) {
                    $to_order = $monitor->calculate_product_order_quantity(
                        $variation_id, 
                        $alert->analysis_days, 
                        $alert->purchase_months
                    );
                    
                    if ($to_order > 0) {
                        $variation = wc_get_product($variation_id);
                        $sku = get_post_meta($variation_id, '_alg_ean', true) ?: $variation->get_sku();
                        
                        $progress['products'][] = array(
                            'id' => $variation_id,
                            'sku' => $sku,
                            'name' => $variation->get_name(),
                            'quantity' => $to_order
                        );
                    }
                }
            } else {
                // Producto simple
                $to_order = $monitor->calculate_product_order_quantity(
                    $product_id, 
                    $alert->analysis_days, 
                    $alert->purchase_months
                );
                
                if ($to_order > 0) {
                    $sku = get_post_meta($product_id, '_alg_ean', true) ?: $product->get_sku();
                    
                    $progress['products'][] = array(
                        'id' => $product_id,
                        'sku' => $sku,
                        'name' => $product->get_name(),
                        'quantity' => $to_order
                    );
                }
            }
            $processed++;
        }
        
        // Guardar progreso
        set_transient($progress_key, $progress, HOUR_IN_SECONDS);
        
        wp_send_json_success(array(
            'processed' => $processed,
            'has_more' => count($products) == $batch_size
        ));
    }
    // Cargar ms productos de alerta
    public function load_more_alert_products() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Sin permisos');
        }
        
        $alert_id = intval($_POST['alert_id']);
        $offset = intval($_POST['offset']);
        $per_page = intval($_POST['per_page']) ?: 100;
        
        global $wpdb;
        $table_alerts = $wpdb->prefix . 'fc_weight_alerts';
        
        // Obtener alerta
        $alert = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_alerts} WHERE id = %d",
            $alert_id
        ));
        
        if (!$alert) {
            wp_send_json_error('Alerta no encontrada');
        }
        
        // Usar processor
        require_once FC_PLUGIN_PATH . 'includes/class-fc-processor.php';
        $processor = new FC_Processor($alert, $per_page);
        $processor->preload_data();
        
        // Procesar batch
        $results = $processor->process_batch($offset);
        
        // Formatear productos
        $products = array();
        foreach ($results['products'] as $product) {
            $products[] = array(
                'sku' => esc_html($product['sku']),
                'name' => esc_html($product['name']),
                'stock' => $product['stock'],
                'in_transit' => $product['in_transit'],
                'to_order' => $product['to_order'],
                'unit_value' => $product['unit_value'],
                'total_value' => $product['total_value']
            );
        }
        
        wp_send_json_success(array(
            'products' => $products,
            'has_more' => $results['has_more'],
            'total_loaded' => $offset + count($products)
        ));
    }
    // Agregar como nuevo método
    public function find_similar_products() {
        if (!isset($_POST['product_name'])) {
            wp_die();
        }
        
        global $wpdb;
        
        $product_name = sanitize_text_field($_POST['product_name']);
        
        // Extraer palabras clave principales (más de 3 caracteres)
        $words = preg_split('/[\s\-\_\+\/]+/', $product_name);
        $keywords = array_filter($words, function($word) {
            return strlen($word) > 3 && !in_array(strtolower($word), array('para', 'with', 'plus'));
        });
        
        if (empty($keywords)) {
            echo json_encode(array('message' => 'No se encontraron productos similares'));
            wp_die();
        }
        
        // Buscar productos con palabras similares que SÍ se venden
        $like_conditions = array();
        foreach ($keywords as $keyword) {
            $like_conditions[] = "p.post_title LIKE '%" . esc_sql($keyword) . "%'";
        }
        
        $results = $wpdb->get_results("
            SELECT 
                p.ID,
                p.post_title,
                COUNT(DISTINCT oi.order_id) as ventas_30d
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON p.ID = oim.meta_value AND oim.meta_key = '_product_id'
            LEFT JOIN {$wpdb->prefix}woocommerce_order_items oi ON oim.order_item_id = oi.order_item_id
            LEFT JOIN {$wpdb->posts} orders ON oi.order_id = orders.ID
            WHERE p.post_type IN ('product', 'product_variation')
            AND p.post_status = 'publish'
            AND (" . implode(' OR ', $like_conditions) . ")
            AND orders.post_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            AND orders.post_status IN ('wc-completed', 'wc-processing', 'wc-shipped')
            GROUP BY p.ID
            HAVING ventas_30d > 0
            ORDER BY ventas_30d DESC
            LIMIT 5
        ");
        
        if ($results) {
            $html = '<div style="padding: 10px;"><h3>Productos similares que SÍ se venden:</h3><ul>';
            foreach ($results as $product) {
                $html .= '<li>' . esc_html($product->post_title) . ' - <strong>' . $product->ventas_30d . ' ventas en 30 días</strong></li>';
            }
            $html .= '</ul></div>';
            echo $html;
        } else {
            echo '<div style="padding: 10px;">No se encontraron productos similares con ventas recientes.</div>';
        }
        
        wp_die();
    }
    /**
     * AJAX handler para obtener historial del producto desde dead stock
     */
    public function get_product_history_dead_stock() {
        $product_id = intval($_POST['product_id']);
        
        if (!$product_id) {
            wp_send_json_error('ID de producto inválido');
        }
        
        global $wpdb;
        
        // Obtener datos del producto
        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error('Producto no encontrado');
        }
        
        // Obtener SKU
        $sku = get_post_meta($product_id, '_alg_ean', true);
        if (empty($sku)) {
            $sku = $product->get_sku();
        }
        
        // Obtener ventas de los últimos 6 meses
        $sales_data = $wpdb->get_results($wpdb->prepare("
            SELECT 
                DATE_FORMAT(o.post_date, '%%Y-%%m') as month,
                SUM(qty.meta_value) as quantity
            FROM {$wpdb->prefix}woocommerce_order_items oi
            JOIN {$wpdb->prefix}woocommerce_order_itemmeta qty ON oi.order_item_id = qty.order_item_id AND qty.meta_key = '_qty'
            LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta prod ON oi.order_item_id = prod.order_item_id AND prod.meta_key = '_product_id'
            LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta var ON oi.order_item_id = var.order_item_id AND var.meta_key = '_variation_id'
            JOIN {$wpdb->posts} o ON oi.order_id = o.ID
            WHERE o.post_type = 'shop_order'
            AND o.post_status IN ('wc-completed', 'wc-processing', 'wc-shipped')
            AND o.post_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
            AND (prod.meta_value = %d OR var.meta_value = %d)
            GROUP BY DATE_FORMAT(o.post_date, '%%Y-%%m')
            ORDER BY month ASC
        ", $product_id, $product_id));
        
        // Obtener compras de los últimos 6 meses
        $purchases_data = array();
        if ($sku) {
            $purchases_data = $wpdb->get_results($wpdb->prepare("
                SELECT 
                    DATE_FORMAT(arrival_date, '%%Y-%%m') as month,
                    SUM(quantity) as quantity
                FROM {$wpdb->prefix}fc_orders_history
                WHERE sku = %s
                AND status = 'received'
                AND arrival_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                GROUP BY DATE_FORMAT(arrival_date, '%%Y-%%m')
                ORDER BY month ASC
            ", $sku));
        }
        
        // Preparar datos para el gráfico
        $months = array();
        $sales = array();
        $purchases = array();
        
        // Generar lista de últimos 6 meses
        for ($i = 5; $i >= 0; $i--) {
            $month = date('Y-m', strtotime("-$i months"));
            $months[] = date('M Y', strtotime("-$i months"));
            $sales[$month] = 0;
            $purchases[$month] = 0;
        }
        
        // Llenar datos de ventas
        foreach ($sales_data as $sale) {
            if (isset($sales[$sale->month])) {
                $sales[$sale->month] = intval($sale->quantity);
            }
        }
        
        // Llenar datos de compras
        foreach ($purchases_data as $purchase) {
            if (isset($purchases[$purchase->month])) {
                $purchases[$purchase->month] = intval($purchase->quantity);
            }
        }
        
        // Calcular estadísticas
        $total_sold = array_sum($sales);
        $total_purchased = array_sum($purchases);
        $avg_monthly_sales = round($total_sold / 6, 1);
        
        wp_send_json_success(array(
            'product_name' => $product->get_name(),
            'labels' => $months,
            'sales' => array_values($sales),
            'purchases' => array_values($purchases),
            'total_sold' => $total_sold,
            'total_purchased' => $total_purchased,
            'avg_monthly_sales' => $avg_monthly_sales,
            'current_stock' => $product->get_stock_quantity()
        ));
    }
    /**
     * Obtener historial de precios de un producto
     */
    public function get_price_history() {
        $product_id = intval($_POST['product_id']);
        
        if (!$product_id) {
            wp_send_json_error('ID de producto inválido');
        }
        
        global $wpdb;
        
        // Obtener nombre del producto
        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error('Producto no encontrado');
        }
        
        // Obtener historial completo
        $history = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}fc_price_history 
            WHERE product_id = %d 
            ORDER BY change_date DESC
        ", $product_id));
        
        // Construir HTML de la tabla
        $html = '';
        foreach ($history as $record) {
            $date = date('d/m/Y H:i', strtotime($record->change_date));
            $arrow = $record->change_percent < 0 ? '↓' : '↑';
            $color = $record->change_percent < 0 ? '#dc3545' : '#28a745';
            
            $html .= '<tr>';
            $html .= '<td style="padding: 8px;">' . $date . '</td>';
            $html .= '<td style="padding: 8px; text-align: right;">$' . number_format($record->old_price, 0) . '</td>';
            $html .= '<td style="padding: 8px; text-align: right;">$' . number_format($record->new_price, 0) . '</td>';
            $html .= '<td style="padding: 8px; text-align: center; color: ' . $color . '; font-weight: bold;">';
            $html .= $arrow . ' ' . abs($record->change_percent) . '%</td>';
            $html .= '</tr>';
        }
        
        if (empty($html)) {
            $html = '<tr><td colspan="4" style="padding: 20px; text-align: center;">No hay historial de cambios de precio</td></tr>';
        }
        
        wp_send_json_success(array(
            'product_name' => $product->get_name(),
            'history' => $html
        ));
    }
}
