<?php
/**
 * Clase para monitorear y calcular peso/CBM de alertas
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

class FC_Weight_Monitor {
    
    private $table_alerts;
    private $table_history;
    
    public function __construct() {
        global $wpdb;
        $this->table_alerts = $wpdb->prefix . 'fc_weight_alerts';
        $this->table_history = $wpdb->prefix . 'fc_alert_history';
        
        // Programar cron job
        add_action('fc_check_weight_alerts', array($this, 'check_all_alerts'));
        
        // Asegurar que el cron está programado
        if (!wp_next_scheduled('fc_check_weight_alerts')) {
            wp_schedule_event(time(), 'daily', 'fc_check_weight_alerts');
        }
    }
    
    // Verificar todas las alertas activas - AHORA OPTIMIZADO
    public function check_all_alerts() {
        global $wpdb;
        
        // Obtener alertas activas
        $alerts = $wpdb->get_results("
            SELECT * FROM {$this->table_alerts} 
            WHERE status IN ('active', 'ready')
        ");
        
        foreach ($alerts as $alert) {
            // Usar el processor como en ajax_fast_init
            require_once FC_PLUGIN_PATH . 'includes/class-fc-processor.php';
            $processor = new FC_Processor($alert, 50);
            
            // Pre-cargar datos
            $processor->preload_data();
            $processor->save_cache_to_transient();
            
            // Contar total y procesar
            $total = $processor->count_products();
            $total_value = 0;
            $offset = 0;
            
            // Procesar todos los productos por batches
            $total_value = 0;
            $total_items = 0;
            $total_units = 0;
            
            while (true) {
                $results = $processor->process_batch($offset);
                
                // Sumar valores de este batch
                $total_value += $results['total_weight'];
                $total_items += $results['total_items'];
                $total_units += $results['total_units'];
                
                // Incrementar offset por los productos procesados
                $offset += $results['processed'];
                
                if (!$results['has_more']) {
                    break;
                }
            }
            
            // Actualizar valor en la alerta
            $wpdb->update(
                $this->table_alerts,
                array(
                    'current_value' => $total_value,
                    'last_check' => current_time('mysql')
                ),
                array('id' => $alert->id)
            );
            
            // Verificar si alcanzó el límite
            if ($alert->status == 'active' && $total_value >= $alert->limit_value) {
                $this->handle_limit_reached($alert, $total_value);
            }
            // Después del while que procesa batches
            error_log("MONITOR FINAL - Alert: " . $alert->id . 
                     ", Total value: " . $total_value);
        }
    }
    
    // REEMPLAZAR TODO EL MÉTODO update_alert_value() POR:
    public function update_alert_value($alert) {
        global $wpdb;
        
        // Usar el processor como en ajax_fast_init
        require_once FC_PLUGIN_PATH . 'includes/class-fc-processor.php';
        $processor = new FC_Processor($alert, 50);
        
        // Pre-cargar datos
        $processor->preload_data();
        $processor->save_cache_to_transient();
        
        // Procesar todos los productos
        $total_value = 0;
        $offset = 0;
        
        while (true) {
            $results = $processor->process_batch($offset);
            $total_value += $results['total_weight'];
            $offset += $results['processed'];
            
            if (!$results['has_more']) {
                break;
            }
        }
        
        // Actualizar valor en la alerta
        $wpdb->update(
            $this->table_alerts,
            array(
                'current_value' => $total_value,
                'last_check' => current_time('mysql')
            ),
            array('id' => $alert->id)
        );
        
        // Verificar si alcanzó el límite
        if ($alert->status == 'active' && $total_value >= $alert->limit_value) {
            $this->handle_limit_reached($alert, $total_value);
        }
    }
    
    // Calcular cantidad a ordenar de un producto
    public function calculate_product_order_quantity($product_id, $analysis_days, $purchase_months) {
        global $wpdb;
        
        $product = wc_get_product($product_id);
        if (!$product) {
            return 0;
        }
        
        // SKU - Para variaciones y productos simples
        $sku = get_post_meta($product_id, '_alg_ean', true);
        if (empty($sku)) {
            $sku = $product->get_sku();
        }
        
        // Si an no hay SKU y es una variación, buscar en el padre
        if (empty($sku) && $product->is_type('variation')) {
            $parent_id = $product->get_parent_id();
            $sku = get_post_meta($parent_id, '_alg_ean', true);
        }
        
        // Stock actual - obtener DIRECTO de meta como el EXPORT
    $stock_actual = intval(get_post_meta($product_id, '_stock', true)) ?: 0;
        
        // NUEVO: Caché de ventas si est disponible
        if (isset($GLOBALS['fc_sales_cache']) && isset($GLOBALS['fc_sales_cache'][$product_id])) {
            $ventas_periodo = $GLOBALS['fc_sales_cache'][$product_id];
        } else {
            // Obtener ventas del perodo
            $ventas_periodo = fc_get_product_sales($product_id, $sku, $analysis_days);
        }
        
        // NUEVO: Ajustar por días sin stock
        $dias_sin_stock = isset($GLOBALS['fc_stockout_cache'][$product_id]) 
            ? intval($GLOBALS['fc_stockout_cache'][$product_id]) 
            : 0;
        
        // Días reales con stock = días totales - das sin stock
        $dias_reales_venta = max(1, $analysis_days - $dias_sin_stock);
        
        // Usar días reales para el promedio
        $promedio_diario = $dias_reales_venta > 0 ? $ventas_periodo / $dias_reales_venta : 0;
        
        // Log para debug (opcional)
        if ($dias_sin_stock > 0) {
            error_log("Producto $product_id: $dias_sin_stock días sin stock de $analysis_days das totales");
        }
        
        // Stock en camino
        $table_orders = $wpdb->prefix . 'fc_orders_history';
        
        // NUEVO: Usar cach si est disponible
        if (isset($GLOBALS['fc_transit_cache']) && isset($GLOBALS['fc_transit_cache'][$sku])) {
            $stock_camino = $GLOBALS['fc_transit_cache'][$sku]->quantity;
        } else {
            $stock_camino = $wpdb->get_var($wpdb->prepare(
                "SELECT SUM(quantity) FROM $table_orders WHERE sku = %s AND status = 'pending'",
                $sku
            )) ?: 0;
        }
        
        
        
        // Calcular necesario
        $necesario = ($promedio_diario * 30 * $purchase_months) - $stock_actual - $stock_camino;
        $cantidad_base = max(0, ceil($necesario));
        
        // NUEVO: Aplicar mltiplos por categoría
        $cantidad_final = $this->apply_category_multiples($product_id, $cantidad_base);
        
        return $cantidad_final;
    }
    
    // NUEVO: Aplicar mltiplos según la categora
    public function apply_category_multiples($product_id, $quantity) {
        if ($quantity == 0) {
            return 0;
        }
        
        // Obtener configuración de mltiplos
        $multiples_config = get_option('fc_category_multiples', array());
        
        // Obtener categorías del producto
        $categories = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'ids'));
        
        // NUEVO: Si es una variación sin categorías, heredar del padre
        $product = wc_get_product($product_id);
        if ($product && $product->is_type('variation') && empty($categories)) {
            $parent_id = $product->get_parent_id();
            $categories = wp_get_post_terms($parent_id, 'product_cat', array('fields' => 'ids'));
            
            // DEBUG
            if (strpos(strtolower($product->get_name()), 'tapa') !== false) {
                error_log('TAPA - Heredando categoras del padre: ' . print_r($categories, true));
            }
        }
        
        // DEBUG para tapas
        $product = wc_get_product($product_id);
        if ($product && strpos(strtolower($product->get_name()), 'tapa') !== false) {
            error_log('TAPA DEBUG - Producto: ' . $product->get_name());
            error_log('TAPA DEBUG - ID: ' . $product_id);
            error_log('TAPA DEBUG - Es variacin: ' . ($product->is_type('variation') ? 'SI' : 'NO'));
            error_log('TAPA DEBUG - Categorías encontradas: ' . print_r($categories, true));
            error_log('TAPA DEBUG - Cantidad base: ' . $quantity);
        }
        
        // NUEVO: Para cada categoría, revisar tambin sus padres
        $all_categories = array();
        foreach ($categories as $cat_id) {
            $all_categories[] = $cat_id;
            // Obtener todos los ancestros (padres, abuelos, etc.)
            $ancestors = get_ancestors($cat_id, 'product_cat');
            $all_categories = array_merge($all_categories, $ancestors);
        }
        
        // Eliminar duplicados
        $all_categories = array_unique($all_categories);
        
        // Revisar configuracin en orden: primero categoras ms especficas
        foreach ($categories as $cat_id) {
            if (isset($multiples_config[$cat_id])) {
                $config = $multiples_config[$cat_id];
                $multiple = intval($config['multiple']);
                $min_exact = intval($config['min_exact']);
                
                if ($multiple > 1) {
                    if ($min_exact > 0 && $quantity <= $min_exact) {
                        return $quantity;
                    }
                    return ceil($quantity / $multiple) * $multiple;
                }
            }
        }
        
        // Si no hay config específica, buscar en categorías padre
        foreach ($all_categories as $cat_id) {
            if (!in_array($cat_id, $categories) && isset($multiples_config[$cat_id])) {
                $config = $multiples_config[$cat_id];
                $multiple = intval($config['multiple']);
                $min_exact = intval($config['min_exact']);
                
                if ($multiple > 1) {
                    if ($min_exact > 0 && $quantity <= $min_exact) {
                        return $quantity;
                    }
                    return ceil($quantity / $multiple) * $multiple;
                }
            }
        }
        
        // Si no hay configuración, devolver la cantidad original
        return $quantity;
    }
    
    // Manejar cuando se alcanza el lmite
    private function handle_limit_reached($alert, $current_value) {
        global $wpdb;
        
        // Cambiar estado a 'ready'
        $wpdb->update(
            $this->table_alerts,
            array(
                'status' => 'ready',
                'last_notification' => current_time('mysql')
            ),
            array('id' => $alert->id)
        );
        
        // Registrar en historial
        $wpdb->insert($this->table_history, array(
            'alert_id' => $alert->id,
            'event_type' => 'limit_reached',
            'value_before' => $alert->current_value,
            'value_after' => $current_value,
            'details' => 'Lmite alcanzado - Pedido listo'
        ));
        
        // Enviar notificación por email
        $this->send_notification($alert, $current_value);
    }
    
    // Enviar notificacin por email
    private function send_notification($alert, $current_value) {
        $unit = $alert->type == 'aereo' ? 'kg' : 'CBM';
        
        $subject = sprintf(
            '[%s] Alerta de Pedido: %s - Límite alcanzado',
            get_bloginfo('name'),
            $alert->name
        );
        
        $message = sprintf(
            "La alerta '%s' ha alcanzado su límite.\n\n" .
            "Tipo de envo: %s\n" .
            "Límite configurado: %s %s\n" .
            "Valor actual: %s %s\n" .
            "Configuracin: Anlisis de %d das para compra de %d meses\n\n" .
            "Puedes gestionar el pedido desde:\n%s\n\n" .
            "Este es un mensaje automtico del sistema de alertas.",
            $alert->name,
            ucfirst($alert->type),
            number_format($alert->limit_value, 2),
            $unit,
            number_format($current_value, 2),
            $unit,
            $alert->analysis_days,
            $alert->purchase_months,
            admin_url('admin.php?page=fc-weight-alerts&action=manage&id=' . $alert->id)
        );
        
        wp_mail($alert->email, $subject, $message);
    }
    
    // Mtodo pblico para actualizar una alerta especfica
    public function update_single_alert($alert_id) {
        global $wpdb;
        
        $alert = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_alerts} WHERE id = %d",
            $alert_id
        ));
        
        if ($alert) {
            $this->update_alert_value($alert);
        }
    }
    
    // Desactivar cron al desinstalar
    public static function deactivate() {
        wp_clear_scheduled_hook('fc_check_weight_alerts');
    }
    
    // NUEVO: Procesar alerta en background
    public static function process_alert_background($alert_id) {
        global $wpdb;
        
        // Aumentar límites para proceso en background
        set_time_limit(0);
        ini_set('memory_limit', '1024M');
        
        $table_alerts = $wpdb->prefix . 'fc_weight_alerts';
        
        // Obtener la alerta
        $alert = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_alerts} WHERE id = %d",
            $alert_id
        ));
        
        if (!$alert) {
            return;
        }
        
        // Procesar
        $monitor = new FC_Weight_Monitor();
        $monitor->update_alert_value($alert);
        
        // Enviar email
        $subject = 'Alerta actualizada: ' . $alert->name;
        $message = sprintf(
            "La alerta '%s' ha sido actualizada.\n\n" .
            "Nuevo valor: %.2f / %.2f %s (%.1f%%)\n\n" .
            "Puedes verla en: %s",
            $alert->name,
            $alert->current_value,
            $alert->limit_value,
            $alert->type == 'aereo' ? 'kg' : 'CBM',
            ($alert->current_value / $alert->limit_value * 100),
            admin_url('admin.php?page=fc-weight-alerts')
        );
        
        wp_mail($alert->email, $subject, $message);
        
        // Limpiar marca de procesamiento
        delete_option('fc_alert_processing_' . $alert_id);
    }
    
    
    // NUEVAS FUNCIONES OPTIMIZADAS - Versin COMPLETA
        public function ajax_fast_init() {
            if (!current_user_can('manage_options')) {
                wp_send_json_error('Sin permisos');
            }
            
            $alert_id = intval($_POST['alert_id']);
            $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 50;
            
            // AGREGAR ESTA LÍNEA AQUÍ:
            delete_transient('fc_processed_skus_' . $alert_id);
            
            global $wpdb;
        
        // Obtener alerta
        $alert = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_alerts} WHERE id = %d",
            $alert_id
        ));
        
        if (!$alert) {
            wp_send_json_error('Alerta no encontrada');
        }
        
        // NUEVO: Usar el processor
        require_once FC_PLUGIN_PATH . 'includes/class-fc-processor.php';
        $processor = new FC_Processor($alert, $batch_size);
        
        // Pre-cargar datos
        $processor->preload_data();
        
        // AGREGAR ESTA LNEA:
        $processor->save_cache_to_transient();
        
        // Contar total
        $total = $processor->count_products();
        
        wp_send_json_success(array(
            'total' => $total,
            'alert_name' => $alert->name
        ));
    }
    
    public function ajax_fast_batch() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Sin permisos');
        }
        
        $alert_id = intval($_POST['alert_id']);
        $offset = intval($_POST['offset']);
        $batch_size = intval($_POST['batch_size']);
        
        // AGREGAR ESTAS LÍNEAS:
        if ($offset == 0) {
            delete_transient('fc_processed_skus_' . $alert->id);
        }

        global $wpdb;
        
        $alert = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_alerts} WHERE id = %d",
            $alert_id
        ));
        
        if (!$alert) {
            wp_send_json_error('Alerta no encontrada');
        }
        
        // NUEVO: Usar el processor
        require_once FC_PLUGIN_PATH . 'includes/class-fc-processor.php';
        $processor = new FC_Processor($alert, $batch_size);
        
        // AGREGAR ESTA LNEA:
        $processor->load_cache_from_transient();
        
        // Procesar batch
        $results = $processor->process_batch($offset);
        
        wp_send_json_success(array(
            'processed' => $results['processed'],
            'batch_value' => $results['total_weight'],
            'has_more' => $results['has_more']
        ));
    }
    
    public function ajax_fast_finish() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Sin permisos');
        }
        
        $alert_id = intval($_POST['alert_id']);
        $total_value = floatval($_POST['total_value']);
        
        global $wpdb;
        
        // Actualizar valor final
        $wpdb->update(
            $this->table_alerts,
            array(
                'current_value' => $total_value,
                'last_check' => current_time('mysql')
            ),
            array('id' => $alert_id)
        );
        
        // Limpiar cache del processor
        $transient_key = 'fc_processor_cache_' . $alert_id;
        delete_transient($transient_key);
        delete_transient('fc_processed_skus_' . $alert_id);

        
        // Verificar si alcanz el lmite
        $alert = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_alerts} WHERE id = %d",
            $alert_id
        ));
        
        if ($alert && $alert->status == 'active' && $total_value >= $alert->limit_value) {
            $this->handle_limit_reached($alert, $total_value);
        }
        
        // Limpiar cache
        delete_transient('fc_transit_cache_' . $alert_id);
        delete_transient('fc_sales_cache_' . $alert_id);
        delete_transient('fc_stockout_cache_' . $alert_id);
        
        wp_send_json_success();
    }
}

// Inicializar el monitor
$fc_monitor = new FC_Weight_Monitor();

// Registrar AJAX handlers
add_action('wp_ajax_fc_update_alert_fast_init', array($fc_monitor, 'ajax_fast_init'));
add_action('wp_ajax_fc_update_alert_fast_batch', array($fc_monitor, 'ajax_fast_batch'));
add_action('wp_ajax_fc_update_alert_fast_finish', array($fc_monitor, 'ajax_fast_finish'));

// Registrar el action para background
add_action('fc_background_update_alert', array('FC_Weight_Monitor', 'process_alert_background'));
