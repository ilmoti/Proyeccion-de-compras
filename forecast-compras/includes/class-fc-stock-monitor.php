<?php
/**
 * Monitor de stock para detectar períodos sin stock
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

class FC_Stock_Monitor {
    
    public function __construct() {
        // Hook directo cuando se guarda cualquier meta de producto
        add_action('updated_post_meta', array($this, 'check_stock_meta_change'), 10, 4);
        
        // NUEVO: Hook cuando se guarda CUALQUIER producto
        add_action('save_post_product', array($this, 'force_check_stock'), 10, 3);
        add_action('save_post_product_variation', array($this, 'force_check_stock'), 10, 3);
        
        // NUEVO: Hook en el proceso de actualización de stock de WooCommerce
        add_action('woocommerce_update_product_stock', array($this, 'on_stock_update'), 10, 4);
        
        // Hook cuando se completa una orden
        add_action('woocommerce_order_status_completed', array($this, 'check_order_stock'));
        add_action('woocommerce_order_status_processing', array($this, 'check_order_stock'));
        
        // Agregar cron para verificación diaria
        add_action('fc_daily_stock_check', array($this, 'daily_stock_check'));
        
        // Programar el cron si no existe
        if (!wp_next_scheduled('fc_daily_stock_check')) {
            wp_schedule_event(time(), 'daily', 'fc_daily_stock_check');
        }
    }
    
    // Detectar cambios en el meta _stock
    public function check_stock_meta_change($meta_id, $post_id, $meta_key, $meta_value) {
        // Solo si es el campo _stock
        if ($meta_key !== '_stock') {
            return;
        }
        
        // Verificar que sea un producto
        $post_type = get_post_type($post_id);
        if ($post_type !== 'product' && $post_type !== 'product_variation') {
            return;
        }
        
        global $wpdb;
        $table_stockouts = $wpdb->prefix . 'fc_stockout_periods';
        
        // Obtener SKU
        $sku = get_post_meta($post_id, '_alg_ean', true);
        if (empty($sku)) {
            $sku = get_post_meta($post_id, '_sku', true);
        }
        
        $stock = intval($meta_value);
        
        // Si hay stock, cerrar período
        if ($stock > 0) {
            $result = $wpdb->query($wpdb->prepare(
                "UPDATE $table_stockouts 
                SET end_date = NOW(), days_out = DATEDIFF(NOW(), start_date)
                WHERE product_id = %d AND end_date IS NULL",
                $post_id
            ));
        }
        // Si no hay stock, abrir período
        else {
            $existe = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table_stockouts WHERE product_id = %d AND end_date IS NULL",
                $post_id
            ));
            
            if (!$existe) {
                $wpdb->insert(
                    $table_stockouts,
                    array(
                        'product_id' => $post_id,
                        'sku' => $sku,
                        'start_date' => current_time('mysql')
                    )
                );
            }
        }
    }
    
    // Verificar cambios de stock
    public function check_stock_change($product_id, $stock_status, $product) {
        global $wpdb;
        
        // Obtener el stock actual
        $stock = $product->get_stock_quantity();
        $sku = $product->get_sku();
        
        // Si no hay SKU, usar el del EAN
        if (empty($sku)) {
            $sku = get_post_meta($product_id, '_alg_ean', true);
        }
        
        $table_stockouts = $wpdb->prefix . 'fc_stockout_periods';
        
        if ($stock <= 0 || $stock_status === 'outofstock') {
            // Sin stock - crear período si no existe
            $existe = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table_stockouts WHERE product_id = %d AND end_date IS NULL",
                $product_id
            ));
            
            if (!$existe) {
                $wpdb->insert(
                    $table_stockouts,
                    array(
                        'product_id' => $product_id,
                        'sku' => $sku,
                        'start_date' => current_time('mysql')
                    )
                );
            }
        } else {
            // Con stock - cerrar período si existe
            $wpdb->query($wpdb->prepare(
                "UPDATE $table_stockouts 
                SET end_date = NOW(), days_out = DATEDIFF(NOW(), start_date)
                WHERE product_id = %d AND end_date IS NULL",
                $product_id
            ));
        }
    }
    
    // Verificar stock despus de una orden
    public function check_order_stock($order_id) {
        $order = wc_get_order($order_id);
        
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && $product->managing_stock()) {
                $this->check_stock_change($product);
            }
        }
    }
    
    // Verificación diaria de todos los productos
    public function daily_stock_check() {
        global $wpdb;
        
        // Buscar productos con stock 0 que no tengan período abierto
        $products = $wpdb->get_results("
            SELECT p.ID, pm.meta_value as stock
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type IN ('product', 'product_variation')
            AND p.post_status = 'publish'
            AND pm.meta_key = '_stock'
            AND CAST(pm.meta_value AS SIGNED) <= 0
        ");
        
        foreach ($products as $prod) {
            $product = wc_get_product($prod->ID);
            if ($product) {
                $this->check_stock_change($product);
            }
        }
        
        error_log("FC Monitor: Verificación diaria completada. " . count($products) . " productos sin stock.");
    }
    
    // Análisis retroactivo (para ejecutar una sola vez)
    public static function analyze_historical_stockouts() {
        global $wpdb;
        
        $table_stockouts = $wpdb->prefix . 'fc_stockout_periods';
        
        // Obtener todos los productos
        $products = $wpdb->get_results("
            SELECT DISTINCT p.ID, pm.meta_value as sku
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sku'
            WHERE p.post_type IN ('product', 'product_variation')
            AND p.post_status = 'publish'
        ");
        
        $analyzed = 0;
        
        foreach ($products as $product) {
            $product_id = $product->ID;
            $current_stock = get_post_meta($product_id, '_stock', true);
            
            // Si actualmente está sin stock, crear período abierto
            if ($current_stock !== '' && intval($current_stock) <= 0) {
                // Verificar si ya existe período abierto
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $table_stockouts 
                    WHERE product_id = %d AND end_date IS NULL",
                    $product_id
                ));
                
                if (!$exists) {
                    $sku = get_post_meta($product_id, '_alg_ean', true);
                    if (empty($sku)) {
                        $sku = $product->sku;
                    }
                    
                    $wpdb->insert(
                        $table_stockouts,
                        array(
                            'product_id' => $product_id,
                            'sku' => $sku,
                            'start_date' => current_time('mysql')
                        ),
                        array('%d', '%s', '%s')
                    );
                    $analyzed++;
                }
            }
        }
        
        return $analyzed;
    }
    // Nueva función para detectar cambios al guardar
    public function check_stock_on_save($product, $updated_props) {
        // Solo si se actualizó el stock
        if (in_array('stock_quantity', $updated_props) || in_array('stock_status', $updated_props)) {
            global $wpdb;
            
            $product_id = $product->get_id();
            $stock = $product->get_stock_quantity();
            $sku = $product->get_sku();
            
            if (empty($sku)) {
                $sku = get_post_meta($product_id, '_alg_ean', true);
            }
            
            $table_stockouts = $wpdb->prefix . 'fc_stockout_periods';
            
            // Si ahora hay stock, cerrar cualquier período abierto
            if ($stock > 0) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE $table_stockouts 
                    SET end_date = NOW(), days_out = DATEDIFF(NOW(), start_date)
                    WHERE product_id = %d AND end_date IS NULL",
                    $product_id
                ));
            }
            // Si no hay stock, abrir período si no existe
            elseif ($stock <= 0) {
                $existe = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $table_stockouts WHERE product_id = %d AND end_date IS NULL",
                    $product_id
                ));
                
                if (!$existe) {
                    $wpdb->insert(
                        $table_stockouts,
                        array(
                            'product_id' => $product_id,
                            'sku' => $sku,
                            'start_date' => current_time('mysql')
                        )
                    );
                }
            }
        }
    }
    // FORZAR verificacin cuando se guarda un producto
public function force_check_stock($post_id, $post, $update) {
    if (!$update) return;
    
    $stock = get_post_meta($post_id, '_stock', true);
    $this->check_stock_meta_change(0, $post_id, '_stock', $stock);
}

    // Hook directo de WooCommerce para cambios de stock
    public function on_stock_update($product, $stock_quantity, $operation, $original_stock) {
        global $wpdb;
        
        $product_id = $product->get_id();
        $sku = $product->get_sku();
        
        if (empty($sku)) {
            $sku = get_post_meta($product_id, '_alg_ean', true);
        }
        
        $table_stockouts = $wpdb->prefix . 'fc_stockout_periods';
        
        // Si el stock nuevo es mayor a 0 y el original era 0 o menos
        if ($stock_quantity > 0 && $original_stock <= 0) {
            // CERRAR período
            $result = $wpdb->query($wpdb->prepare(
                "UPDATE $table_stockouts 
                SET end_date = NOW(), days_out = DATEDIFF(NOW(), start_date)
                WHERE product_id = %d AND end_date IS NULL",
                $product_id
            ));
            
            if ($result) {
                error_log("FC Monitor AUTO: Cerrado período para producto $product_id - Stock: $stock_quantity");
            }
        }
        // Si el stock nuevo es 0 y antes tenía stock
        elseif ($stock_quantity <= 0 && $original_stock > 0) {
            // ABRIR perodo
            $existe = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table_stockouts WHERE product_id = %d AND end_date IS NULL",
                $product_id
            ));
            
            if (!$existe) {
                $wpdb->insert(
                    $table_stockouts,
                    array(
                        'product_id' => $product_id,
                        'sku' => $sku,
                        'start_date' => current_time('mysql')
                    )
                );
                error_log("FC Monitor AUTO: Abierto período para producto $product_id");
            }
        }
    }
}