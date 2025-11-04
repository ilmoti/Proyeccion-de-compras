<?php
/**
 * Procesador central para cálculos de alertas
 * Usado tanto por Monitor como por Export
 */

if (!defined('ABSPATH')) {
    exit;
}

class FC_Processor {
    
    private $alert;
    private $batch_size;
    private $cached_data = array();
    private $table_alerts;
    
    /**
     * Constructor
     */
    public function __construct($alert, $batch_size = 50) {
        global $wpdb;
        $this->alert = $alert;
        $this->batch_size = $batch_size;
        $this->table_alerts = $wpdb->prefix . 'fc_weight_alerts';

    }
    
    /**
     * Pre-cargar todos los datos en memoria
     */
    public function preload_data() {
        global $wpdb;
        
        // 1. Stock en tránsito
        $transit_stock = $wpdb->get_results("
            SELECT sku, SUM(quantity) as quantity 
            FROM {$wpdb->prefix}fc_orders_history 
            WHERE status = 'pending' 
            GROUP BY sku
        ", OBJECT_K);
        $this->cached_data['transit'] = $transit_stock;
        
        // 2. Ventas
        $days = $this->alert->analysis_days;
        $start_date = date('Y-m-d', strtotime("-{$days} days"));
        $start_date_safe = esc_sql($start_date);
        $sales_data = $wpdb->get_results("
            SELECT 
                CASE 
                    WHEN variation_id.meta_value IS NOT NULL 
                        AND variation_id.meta_value != '' 
                        AND variation_id.meta_value != '0' 
                    THEN variation_id.meta_value 
                    ELSE product_id.meta_value 
                END as product_id,
                SUM(qty.meta_value) as total_sales
            FROM {$wpdb->prefix}woocommerce_order_items oi
            JOIN {$wpdb->prefix}woocommerce_order_itemmeta qty ON oi.order_item_id = qty.order_item_id AND qty.meta_key = '_qty'
            LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta product_id ON oi.order_item_id = product_id.order_item_id AND product_id.meta_key = '_product_id'
            LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta variation_id ON oi.order_item_id = variation_id.order_item_id AND variation_id.meta_key = '_variation_id'
            JOIN {$wpdb->posts} o ON oi.order_id = o.ID
            WHERE o.post_type = 'shop_order'
            AND o.post_status IN ('wc-completed', 'wc-processing', 'wc-shipped')
            AND o.post_date >= '$start_date_safe'
            AND qty.meta_value > 0
            GROUP BY CASE 
                WHEN variation_id.meta_value IS NOT NULL 
                    AND variation_id.meta_value != '' 
                    AND variation_id.meta_value != '0' 
                THEN variation_id.meta_value 
                ELSE product_id.meta_value 
            END
        ", OBJECT_K);

        $sales_cache = array();
        foreach ($sales_data as $id => $data) {
            // Guardar tanto como string como número para compatibilidad
            $sales_cache[$data->product_id] = $data->total_sales;
            $sales_cache[intval($data->product_id)] = $data->total_sales;
        }
        $this->cached_data['sales'] = $sales_cache;
        
        // 3. Días sin stock
        $stockout_data = $wpdb->get_results($wpdb->prepare("
            SELECT 
                product_id,
                SUM(CASE 
                    WHEN end_date IS NULL THEN DATEDIFF(NOW(), start_date)
                    WHEN start_date >= %s THEN DATEDIFF(end_date, start_date)
                    WHEN end_date >= %s THEN DATEDIFF(end_date, %s)
                    ELSE 0
                END) as days_out
            FROM {$wpdb->prefix}fc_stockout_periods
            WHERE start_date <= NOW()
            AND (end_date IS NULL OR end_date >= %s)
            GROUP BY product_id
        ", $start_date, $start_date, $start_date, $start_date), OBJECT_K);
        
        $stockout_cache = array();
        foreach ($stockout_data as $id => $data) {
            $stockout_cache[$id] = $data->days_out;
        }
        $this->cached_data['stockout'] = $stockout_cache;
    
        // 4. NUEVO: Cargar productos con descuentos recientes
        $risk_products = $wpdb->get_results("
            SELECT DISTINCT 
                ph.product_id,
                ph.change_percent as last_discount,
                ph.change_date as discount_date,
                pm.meta_value as current_price,
                ph.old_price as price_before_discount
            FROM {$wpdb->prefix}fc_price_history ph
            INNER JOIN {$wpdb->postmeta} pm ON ph.product_id = pm.post_id AND pm.meta_key = '_price'
            WHERE ph.change_percent < -10
            AND ph.change_date >= DATE_SUB(NOW(), INTERVAL 90 DAY)
            AND ph.product_id IN (
                SELECT MAX(ph2.product_id) FROM {$wpdb->prefix}fc_price_history ph2
                WHERE ph2.product_id = ph.product_id
                GROUP BY ph2.product_id
                HAVING MAX(ph2.change_date) = ph.change_date
            )
        ");
        
        $risk_cache = array();
        foreach ($risk_products as $data) {
            $margin_lost = round((1 - ($data->current_price / $data->price_before_discount)) * 100, 1);
            
            $risk_cache[$data->product_id] = array(
                'discount' => abs($data->last_discount),
                'discount_date' => $data->discount_date,
                'margin_lost' => $margin_lost,
                'original_price' => $data->price_before_discount,
                'current_price' => $data->current_price
            );
        }
        $this->cached_data['risk_products'] = $risk_cache;
    
        // 5. Configuracin de múltiplos
        $this->cached_data['multiples'] = get_option('fc_category_multiples', array());
        
        return true;
    }
    
    /**
     * Guardar datos en transient para usar entre batches
     */
    public function save_cache_to_transient() {
        $transient_key = 'fc_processor_cache_' . $this->alert->id;
        set_transient($transient_key, $this->cached_data, 3600); // 1 hora
    }
    
    /**
     * Cargar datos desde transient
     */
    public function load_cache_from_transient() {
        $transient_key = 'fc_processor_cache_' . $this->alert->id;
        $cached = get_transient($transient_key);
        if ($cached) {
            $this->cached_data = $cached;
            return true;
        }
        return false;
    }
    
    /**
     * Contar total de productos
     */
    public function count_products() {
        global $wpdb;
        
        $sql = $this->build_base_query(true);
        $count = intval($wpdb->get_var($sql));
        
        return $count;
    }
    
    /**
     * Procesar un lote de productos
     */
        public function process_batch($offset = 0) {
        global $wpdb;
        
        // Cargar cache si no está cargado
        if (empty($this->cached_data['risk_products'])) {
            $this->load_cache_from_transient();
        }
        
        // Control de duplicados - usar transient para persistir entre batches
        $transient_key = 'fc_processed_skus_' . $this->alert->id;
        
        if ($offset == 0) {
            // Reset en el primer batch
            delete_transient($transient_key);
            $processed_skus = array();
        } else {
            // Cargar SKUs ya procesados
            $processed_skus = get_transient($transient_key);
            if (!is_array($processed_skus)) {
                $processed_skus = array();
            }
        }
        
        // Construir query para obtener solo IDs
        $sql = $this->build_base_query(false);
        $sql .= $wpdb->prepare(" ORDER BY p.ID LIMIT %d OFFSET %d", $this->batch_size, $offset);
        
        // Obtener IDs de productos
        $product_ids = $wpdb->get_col($sql);
        
        
        // NUEVO: Expandir productos variables para incluir sus variaciones
        $expanded_ids = array();
        $debug_expanded_count = 0;
        
        foreach ($product_ids as $product_id) {
            // Verificar si tiene variaciones
            $has_variations = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} 
                 WHERE post_parent = %d 
                 AND post_type = 'product_variation' 
                 AND post_status = 'publish'",
                $product_id
            ));
            
            if ($has_variations > 0) {

                // Es variable, obtener sus variaciones
                $variations = $wpdb->get_col($wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} 
                     WHERE post_parent = %d 
                     AND post_type = 'product_variation' 
                     AND post_status = 'publish'",
                    $product_id
                ));
                
                // Agregar las variaciones
                foreach ($variations as $var_id) {
                    $expanded_ids[] = $var_id;
                    $debug_expanded_count++;
                }
            } else {
                // Es simple o ya es una variación
                $expanded_ids[] = $product_id;
            }
        }
        
        // Usar los IDs expandidos
        $product_ids = $expanded_ids;
       
        // NUEVO: Cargar ventas para las variaciones expandidas que no estn en caché
        $new_ids_for_sales = array();
        foreach ($product_ids as $pid) {
            if (!isset($this->cached_data['sales'][$pid])) {
                $new_ids_for_sales[] = $pid;
            }
        }
        
        if (!empty($new_ids_for_sales)) {
            $days = $this->alert->analysis_days;
            $start_date = date('Y-m-d', strtotime("-{$days} days"));
            $ids_string = implode(',', $new_ids_for_sales);
            
            
            // Construir la query sin prepare para los IDs
            $sales_query = "
                SELECT 
                    COALESCE(product_id.meta_value, variation_id.meta_value) as product_id,
                    SUM(qty.meta_value) as total_sales
                FROM {$wpdb->prefix}woocommerce_order_items oi
                JOIN {$wpdb->prefix}woocommerce_order_itemmeta qty ON oi.order_item_id = qty.order_item_id AND qty.meta_key = '_qty'
                LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta product_id ON oi.order_item_id = product_id.order_item_id AND product_id.meta_key = '_product_id'
                LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta variation_id ON oi.order_item_id = variation_id.order_item_id AND variation_id.meta_key = '_variation_id'
                JOIN {$wpdb->posts} o ON oi.order_id = o.ID
                WHERE o.post_type = 'shop_order'
                AND o.post_status IN ('wc-completed', 'wc-processing', 'wc-shipped')
                AND o.post_date >= '$start_date'
                AND COALESCE(product_id.meta_value, variation_id.meta_value) IN ($ids_string)
                GROUP BY product_id
            ";
            
            $additional_sales = $wpdb->get_results($sales_query, OBJECT_K);

            // AGREGAR ESTO:
            foreach ($additional_sales as $id => $data) {
                $this->cached_data['sales'][$data->product_id] = $data->total_sales;
                $this->cached_data['sales'][intval($data->product_id)] = $data->total_sales;
            }
            
        }
        
        // Resultados
        $results = array(
            'products' => array(),
            'total_weight' => 0,
            'total_items' => 0,
            'total_units' => 0,
            'processed' => 0,
            'has_more' => false
        );

        // Procesar cada producto
        foreach ($product_ids as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product) continue;
            
            // Crear objeto con datos necesarios
            $product_data = new stdClass();
            $product_data->ID = $product_id;
            $product_data->post_type = $product->get_type();
            // SKU - Para variaciones y productos simples
            $sku = get_post_meta($product_id, '_alg_ean', true);
            if (empty($sku)) {
                $sku = $product->get_sku();
            }
            
            // Si aún no hay SKU y es una variación, buscar en el padre
            if (empty($sku) && $product->is_type('variation')) {
                $parent_id = $product->get_parent_id();
                $sku = get_post_meta($parent_id, '_alg_ean', true);
                if (empty($sku)) {
                    $parent_product = wc_get_product($parent_id);
                    if ($parent_product) {
                        $sku = $parent_product->get_sku();
                    }
                }
            }
            
            $product_data->sku = $sku;
            $product_data->post_title = $product->get_name();
            $product_data->parent_title = '';
            $product_data->attributes = '';
            
            // Si es variacin, obtener datos del padre
            if ($product->is_type('variation')) {
                $parent = wc_get_product($product->get_parent_id());
                if ($parent) {
                    $product_data->parent_title = $parent->get_title();
                    // Obtener atributos de la variacin
                    $attributes = $product->get_variation_attributes();
                    $attr_strings = array();
                    foreach ($attributes as $attr => $value) {
                        if ($value) {
                            $attr_strings[] = $value;
                        }
                    }
                    $product_data->attributes = implode(', ', $attr_strings);
                }
            }
            $product_data->stock = $product->get_stock_quantity();
            $product_data->weight = get_post_meta($product_id, '_weight', true);
            $product_data->length = get_post_meta($product_id, '_length', true);
            $product_data->width = get_post_meta($product_id, '_width', true);
            $product_data->height = get_post_meta($product_id, '_height', true);
            
            // Datos adicionales para export
            $product_data->last_price = $wpdb->get_var($wpdb->prepare(
                "SELECT purchase_price FROM {$wpdb->prefix}fc_orders_history 
                 WHERE sku = %s AND purchase_price > 0 
                 ORDER BY created_at DESC LIMIT 1",
                $product_data->sku
            ));
            
            $product_data->quality = $wpdb->get_var($wpdb->prepare(
                "SELECT quality FROM {$wpdb->prefix}fc_product_qualities 
                 WHERE sku = %s LIMIT 1",
                $product_data->sku
            ));
            
            $product_result = $this->process_single_product($product_data);

            if ($product_result && $product_result['to_order'] > 0) {
                // CONTROL DE DUPLICADOS AQUÍ
                if (isset($processed_skus[$product_data->sku])) {
                    $debug_sin_pedir++;
                } else {
                    $processed_skus[$product_data->sku] = true;
                    $debug_agregados++;
                    $results['products'][] = $product_result;
                    $results['total_weight'] += $product_result['total_value'];
                    $results['total_items']++;
                    $results['total_units'] += $product_result['to_order'];
                }
            } else {
                $debug_sin_pedir++;
            }
            
            
            $results['processed']++;
        }

        // Guardar SKUs procesados para el siguiente batch
        set_transient($transient_key, $processed_skus, 3600);
        
        // Verificar si hay ms productos
        $total_products = $this->count_products();
        $results['has_more'] = ($offset + $this->batch_size) < $total_products;
        
        // Justo antes de: return $results;
        error_log("PROCESSOR BATCH - Total items: " . $results['total_items'] . 
                 ", Total weight: " . $results['total_weight'] . 
                 ", Processed: " . $results['processed']);
        
        return $results;
    }
    
    /**
     * Construir query base UNIFICADA
     */
    private function build_base_query($count_only = false) {
        global $wpdb;
        
        if ($count_only) {
            $select = "SELECT COUNT(DISTINCT p.ID)";
        } else {
            $select = "SELECT DISTINCT p.ID";
        }
        
        // Query modificada: incluir productos variables Y productos con SKU
        $sql = "$select
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm_type ON p.ID = pm_type.post_id AND pm_type.meta_key = '_product_type'
            LEFT JOIN {$wpdb->postmeta} sku1 ON p.ID = sku1.post_id AND sku1.meta_key = '_alg_ean'
            LEFT JOIN {$wpdb->postmeta} sku2 ON p.ID = sku2.post_id AND sku2.meta_key = '_sku'
            WHERE p.post_status = 'publish'
            AND (
                (p.post_type = 'product' AND (pm_type.meta_value = 'variable' OR pm_type.meta_value IS NULL))
                OR (p.post_type IN ('product', 'product_variation') AND COALESCE(sku1.meta_value, sku2.meta_value, '') != '')
            )";
        
        // Aplicar filtros de categoras
        if (!empty($this->alert->categories)) {
            $cats_array = array_map('intval', explode(',', $this->alert->categories));
            $all_cats = array();
            foreach ($cats_array as $cat_id) {
                $all_cats[] = $cat_id;
                $children = get_term_children($cat_id, 'product_cat');
                if (!is_wp_error($children)) {
                    $all_cats = array_merge($all_cats, $children);
                }
            }
            $all_cats = array_unique($all_cats);
            $cats = implode(',', $all_cats);
            
            $sql .= " AND (
                p.ID IN (
                    SELECT object_id FROM {$wpdb->term_relationships} tr
                    JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                    WHERE tt.taxonomy = 'product_cat' AND tt.term_id IN ($cats)
                ) OR p.post_parent IN (
                    SELECT object_id FROM {$wpdb->term_relationships} tr
                    JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                    WHERE tt.taxonomy = 'product_cat' AND tt.term_id IN ($cats)
                )
            )";
        }
        
        // Aplicar filtros de tags
        if (!empty($this->alert->tags)) {
            $tags = explode(',', $this->alert->tags);
            $tags_placeholders = array();
            foreach ($tags as $tag) {
                $tags_placeholders[] = "'" . esc_sql(trim($tag)) . "'";
            }
            $tags_in = implode(',', $tags_placeholders);
            
            $sql .= " AND (
                p.ID IN (
                    SELECT tr.object_id FROM {$wpdb->term_relationships} tr
                    INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                    INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
                    WHERE tt.taxonomy = 'product_tag' AND t.slug IN ($tags_in)
                ) OR p.post_parent IN (
                    SELECT tr.object_id FROM {$wpdb->term_relationships} tr
                    INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                    INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
                    WHERE tt.taxonomy = 'product_tag' AND t.slug IN ($tags_in)
                )
            )";
        }
        
        // Tags excluidos
        if (!empty($this->alert->excluded_tags)) {
            $excluded_tags = explode(',', $this->alert->excluded_tags);
            $excluded_placeholders = array();
            foreach ($excluded_tags as $tag) {
                $excluded_placeholders[] = "'" . esc_sql(trim($tag)) . "'";
            }
            $excluded_in = implode(',', $excluded_placeholders);
            
            $sql .= " AND p.ID NOT IN (
                SELECT tr.object_id FROM {$wpdb->term_relationships} tr
                INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
                WHERE tt.taxonomy = 'product_tag' AND t.slug IN ($excluded_in)
            ) AND (p.post_parent IS NULL OR p.post_parent NOT IN (
                SELECT tr.object_id FROM {$wpdb->term_relationships} tr
                INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
                WHERE tt.taxonomy = 'product_tag' AND t.slug IN ($excluded_in)
            ))";
        }
        
        return $sql;
    }
    
    /**
     * Procesar un producto individual
     */
    private function process_single_product($product_data) {
        // SKU
        $sku = $product_data->sku;
        if (empty($sku)) {
            return null;
        }
        
        // Stock actual
        $stock_actual = intval($product_data->stock) ?: 0;
        
        // Ventas desde cache - buscar como número y como string
        $ventas = 0;
        if (isset($this->cached_data['sales'][$product_data->ID])) {
            $ventas = $this->cached_data['sales'][$product_data->ID];
        } elseif (isset($this->cached_data['sales'][strval($product_data->ID)])) {
            $ventas = $this->cached_data['sales'][strval($product_data->ID)];
        }
        
        // Días sin stock desde cache
        $dias_sin_stock = isset($this->cached_data['stockout'][$product_data->ID]) 
            ? $this->cached_data['stockout'][$product_data->ID] 
            : 0;
        
        // Stock en trnsito desde cache
        $stock_transito = 0;
        if (isset($this->cached_data['transit'][$sku])) {
            $stock_transito = intval($this->cached_data['transit'][$sku]->quantity);
        }
        
        // Calcular das reales
        $dias_reales = max(1, $this->alert->analysis_days - $dias_sin_stock);
        
        // Clculo
        $promedio_diario = $ventas / $dias_reales;
        $necesario = ($promedio_diario * 30 * $this->alert->purchase_months) - $stock_actual - $stock_transito;
        $cantidad_base = max(0, ceil($necesario));
        
        // Aplicar múltiplos
        $to_order = $this->apply_category_multiples($product_data->ID, $cantidad_base);

        if ($to_order <= 0) {
            return null;
        }
        
        // Construir nombre del producto
        $product_name = $product_data->post_title;
        if ($product_data->post_type == 'product_variation' && $product_data->parent_title) {
            $product_name = $product_data->parent_title . ' - ' . $product_data->attributes;
        }
        
        // Resultado base
        $result = array(
            'id' => $product_data->ID,
            'sku' => $sku,
            'name' => $product_name,
            'stock' => $stock_actual,
            'in_transit' => $stock_transito,
            'to_order' => $to_order,
            'unit_value' => 0,
            'total_value' => 0,
            'price' => $product_data->last_price ?: '0',
            'quality' => $product_data->quality ?: 'Sin definir',
            'weight' => $product_data->weight ?: '0',
            'has_risk_discount' => false,
            'risk_info' => null
        );
        
        // NUEVO: Verificar si tiene descuento por riesgo
        if (isset($this->cached_data['risk_products'][$product_data->ID])) {
            $risk_data = $this->cached_data['risk_products'][$product_data->ID];
            $result['has_risk_discount'] = true;
            $result['risk_info'] = array(
                'discount_percent' => $risk_data['discount'],
                'days_since_discount' => floor((time() - strtotime($risk_data['discount_date'])) / 86400),
                'margin_lost' => $risk_data['margin_lost']
            );
        }
        
        // Calcular peso o CBM segn el tipo
        if ($this->alert->type == 'aereo') {
            $weight = floatval($product_data->weight);
            $result['unit_value'] = $weight;
            $result['total_value'] = $weight * $to_order; // Usar cantidad_base, no to_order
        } else {
            $length = floatval($product_data->length);
            $width = floatval($product_data->width);
            $height = floatval($product_data->height);
            
            if ($length > 0 && $width > 0 && $height > 0) {
                $cbm = ($length/100) * ($width/100) * ($height/100);
                $result['unit_value'] = $cbm;
                $result['total_value'] = $cbm * $to_order; // Usar cantidad_base, no to_order
            }
        }
        return $result;
    }
    
    /**
     * Aplicar mltiplos por categora (copiado del monitor)
     */
    public function apply_category_multiples($product_id, $quantity) {
        if ($quantity == 0) {
            return 0;
        }
        
        $multiples_config = $this->cached_data['multiples'];
        
        // Obtener categoras del producto
        $categories = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'ids'));
        
        // Si es una variacin sin categorías, heredar del padre
        $product = wc_get_product($product_id);
        if ($product && $product->is_type('variation') && empty($categories)) {
            $parent_id = $product->get_parent_id();
            $categories = wp_get_post_terms($parent_id, 'product_cat', array('fields' => 'ids'));
        }
        
        // Para cada categora, revisar tambin sus padres
        $all_categories = array();
        foreach ($categories as $cat_id) {
            $all_categories[] = $cat_id;
            $ancestors = get_ancestors($cat_id, 'product_cat');
            $all_categories = array_merge($all_categories, $ancestors);
        }
        $all_categories = array_unique($all_categories);
        
        // Revisar configuracin
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
        
        // Si no hay config especfica, buscar en categoras padre
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
        
        return $quantity;
    }
}