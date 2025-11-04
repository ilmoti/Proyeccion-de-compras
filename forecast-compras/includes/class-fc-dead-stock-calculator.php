<?php
/**
 * Calculadora optimizada de métricas para stock muerto
 * Basado en la estrategia de preload del FC_Processor
 */

if (!defined('ABSPATH')) {
    exit;
}

class FC_Dead_Stock_Calculator {
    
    private $batch_size = 100;
    private $cached_data = array();
    private $table_cache;
    private $analysis_days = 30;
    
    public function __construct($analysis_days = 30) {
        global $wpdb;
        $this->table_cache = $wpdb->prefix . 'fc_stock_analysis_cache';
        $this->analysis_days = $analysis_days;
    }
    
    /**
     * Pre-cargar TODOS los datos necesarios en memoria (Una sola vez)
     */
    public function preload_all_data() {
        global $wpdb;
        
        // 1. Obtener IDs de todos los productos (simples y variables)
        $product_ids = $wpdb->get_col("
            SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} sku1 ON p.ID = sku1.post_id AND sku1.meta_key = '_alg_ean'
            LEFT JOIN {$wpdb->postmeta} sku2 ON p.ID = sku2.post_id AND sku2.meta_key = '_sku'
            WHERE p.post_type = 'product'
            AND p.post_status = 'publish'
        ");
        
        // 2. Expandir productos y recopilar todos (simples + variaciones)
        $all_products = array();
        
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
                $variations = $wpdb->get_results($wpdb->prepare("
                    SELECT 
                        v.ID,
                        v.post_title as variation_title,
                        'product_variation' as post_type,
                        p.post_title as parent_title,
                        COALESCE(v_sku.meta_value, p_sku.meta_value) as sku,
                        CAST(v_stock.meta_value AS SIGNED) as stock,
                        COALESCE(v_price.meta_value, p_price.meta_value) as price
                    FROM {$wpdb->posts} v
                    INNER JOIN {$wpdb->posts} p ON v.post_parent = p.ID
                    LEFT JOIN {$wpdb->postmeta} v_stock ON v.ID = v_stock.post_id AND v_stock.meta_key = '_stock'
                    LEFT JOIN {$wpdb->postmeta} v_sku ON v.ID = v_sku.post_id AND v_sku.meta_key = '_alg_ean'
                    LEFT JOIN {$wpdb->postmeta} p_sku ON p.ID = p_sku.post_id AND p_sku.meta_key = '_alg_ean'
                    LEFT JOIN {$wpdb->postmeta} v_price ON v.ID = v_price.post_id AND v_price.meta_key = '_price'
                    LEFT JOIN {$wpdb->postmeta} p_price ON p.ID = p_price.post_id AND p_price.meta_key = '_price'
                    WHERE v.post_parent = %d
                    AND v.post_type = 'product_variation'
                    AND v.post_status = 'publish'
                    AND CAST(v_stock.meta_value AS SIGNED) > 0
                ", $product_id));
                
                foreach ($variations as $variation) {
                    // Excluir SKUs GRT-ITEM
                    if (!empty($variation->sku) && strpos($variation->sku, 'GRT-ITEM') === 0) {
                        continue;
                    }
                    
                    // Construir nombre completo: Padre - Atributos
                    $attributes = $wpdb->get_results($wpdb->prepare(
                        "SELECT meta_key, meta_value 
                         FROM {$wpdb->postmeta} 
                         WHERE post_id = %d 
                         AND meta_key LIKE 'attribute_%%'",
                        $variation->ID
                    ));
                    
                    $attr_values = array();
                    foreach ($attributes as $attr) {
                        if (!empty($attr->meta_value)) {
                            $attr_values[] = $attr->meta_value;
                        }
                    }
                    
                    $variation->post_title = $variation->parent_title;
                    if (!empty($attr_values)) {
                        $variation->post_title .= ' - ' . implode(', ', $attr_values);
                    }
                    
                    $all_products[$variation->ID] = $variation;
                }
            } else {
                // Es simple, verificar si tiene stock
                $simple = $wpdb->get_row($wpdb->prepare("
                    SELECT 
                        p.ID,
                        p.post_title,
                        'product' as post_type,
                        COALESCE(sku1.meta_value, sku2.meta_value) as sku,
                        CAST(stock.meta_value AS SIGNED) as stock,
                        price.meta_value as price
                    FROM {$wpdb->posts} p
                    LEFT JOIN {$wpdb->postmeta} stock ON p.ID = stock.post_id AND stock.meta_key = '_stock'
                    LEFT JOIN {$wpdb->postmeta} sku1 ON p.ID = sku1.post_id AND sku1.meta_key = '_alg_ean'
                    LEFT JOIN {$wpdb->postmeta} sku2 ON p.ID = sku2.post_id AND sku2.meta_key = '_sku'
                    LEFT JOIN {$wpdb->postmeta} price ON p.ID = price.post_id AND price.meta_key = '_price'
                    WHERE p.ID = %d
                    AND CAST(stock.meta_value AS SIGNED) > 0
                ", $product_id));
                
                if ($simple && $simple->stock > 0) {
                    // Excluir SKUs GRT-ITEM
                    if (!empty($simple->sku) && strpos($simple->sku, 'GRT-ITEM') === 0) {
                        continue;
                    }
                    $all_products[$simple->ID] = $simple;
                }
            }
        }
        
        $this->cached_data['products'] = $all_products;
        
        // 3. Cargar TODAS las ventas - IMPORTANTE: usar CASE/WHEN como en el Processor
        $max_days = max(360, $this->analysis_days);
        $date_start = date('Y-m-d', strtotime("-{$max_days} days"));
        $sales_data = $wpdb->get_results("
            SELECT 
                CASE 
                    WHEN woim_var.meta_value IS NOT NULL AND woim_var.meta_value != '' AND woim_var.meta_value != '0'
                    THEN woim_var.meta_value
                    ELSE woim_prod.meta_value
                END as product_id,
                DATE(p.post_date) as sale_date,
                SUM(woim_qty.meta_value) as quantity
            FROM {$wpdb->prefix}woocommerce_order_items woi
            JOIN {$wpdb->prefix}woocommerce_order_itemmeta woim_qty 
                ON woi.order_item_id = woim_qty.order_item_id AND woim_qty.meta_key = '_qty'
            LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta woim_prod 
                ON woi.order_item_id = woim_prod.order_item_id AND woim_prod.meta_key = '_product_id'
            LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta woim_var 
                ON woi.order_item_id = woim_var.order_item_id AND woim_var.meta_key = '_variation_id'
            JOIN {$wpdb->posts} p ON woi.order_id = p.ID
            WHERE p.post_type = 'shop_order'
            AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-shipped')
            AND p.post_date >= '$date_start'
            GROUP BY product_id, sale_date
        ");
        
        // Organizar ventas por producto y periodo
        $this->cached_data['sales'] = array();
        $this->cached_data['last_sale'] = array();
        
        foreach ($sales_data as $sale) {
            $pid = $sale->product_id;
            $sale_time = strtotime($sale->sale_date);
            $days_ago = (int)((time() - $sale_time) / 86400);
            
            // Inicializar si no existe
            if (!isset($this->cached_data['sales'][$pid])) {
                $this->cached_data['sales'][$pid] = array(
                    'last_30' => 0,
                    'last_60' => 0,
                    'last_90' => 0,
                    'last_120' => 0
                );
            }
            
            // Acumular ventas por periodo
            if ($days_ago <= 30) {
                $this->cached_data['sales'][$pid]['last_30'] += $sale->quantity;
            }
            if ($days_ago <= 60) {
                $this->cached_data['sales'][$pid]['last_60'] += $sale->quantity;
            }
            if ($days_ago <= 90) {
                $this->cached_data['sales'][$pid]['last_90'] += $sale->quantity;
            }
            if ($days_ago <= 120) {
                $this->cached_data['sales'][$pid]['last_120'] += $sale->quantity;
            }
            
            // Actualizar última venta
            if (!isset($this->cached_data['last_sale'][$pid]) || 
                $sale->sale_date > $this->cached_data['last_sale'][$pid]) {
                $this->cached_data['last_sale'][$pid] = $sale->sale_date;
            }
        }
        
        // 3. Cargar TODOS los precios de compra y fechas de una sola vez
        $purchase_data = $wpdb->get_results("
            SELECT 
                sku,
                AVG(CASE WHEN purchase_price > 0 THEN purchase_price END) as avg_price,
                MAX(CASE WHEN status = 'received' THEN arrival_date END) as last_purchase
            FROM {$wpdb->prefix}fc_orders_history
            WHERE status IN ('pending', 'received')
            GROUP BY sku
        ", OBJECT_K);
        
        $this->cached_data['purchases'] = array();
        foreach ($purchase_data as $sku => $data) {
            $this->cached_data['purchases'][$sku] = array(
                'avg_price' => $data->avg_price ?: 0,
                'last_purchase' => $data->last_purchase
            );
        }
        
        return true;
    }
    
    /**
     * Procesar todos los productos usando datos pre-cargados
     */
    public function calculate_all_products() {
        // Pre-cargar todos los datos necesarios
        $this->preload_all_data();
        
        // Preparar batch insert
        $batch_data = array();
        $processed = 0;
        
        foreach ($this->cached_data['products'] as $product_id => $product) {
            $metrics = $this->calculate_metrics_from_cache($product);
            
            if ($metrics) {
                $batch_data[] = $metrics;
                $processed++;
                
                // Insertar por lotes
                if (count($batch_data) >= $this->batch_size) {
                    $this->batch_insert($batch_data);
                    $batch_data = array();
                }
            }
        }
        
        // Insertar últimos registros
        if (!empty($batch_data)) {
            $this->batch_insert($batch_data);
        }
        
        return $processed;
    }
    
    /**
     * Calcular mtricas usando solo datos en cache (sin queries)
     */
    private function calculate_metrics_from_cache($product) {
        // NUEVO: Excluir productos GRT-ITEM
        if (!empty($product->sku) && strpos($product->sku, 'GRT-ITEM') === 0) {
            return null; // No procesar este producto
        }
        
        // Para variaciones, construir el nombre completo
        $product_name = $product->post_title;
        if ($product->post_type == 'product_variation' && isset($product->parent_title)) {
            // Obtener atributos de la variacin
            $attributes = array();
            $variation_meta = get_post_meta($product->ID);
            foreach ($variation_meta as $key => $value) {
                if (strpos($key, 'attribute_') === 0) {
                    $attributes[] = $value[0];
                }
            }
            $attr_string = implode(', ', array_filter($attributes));
            $product_name = $product->parent_title . ' - ' . $attr_string;
        }
        
        $data = array(
            'product_id' => $product->ID,
            'product_name' => $product_name,
            'sku' => $product->sku,
            'current_stock' => $product->stock
        );
        
        // Días sin venta
        if (isset($this->cached_data['last_sale'][$product->ID])) {
            $data['last_sale_date'] = $this->cached_data['last_sale'][$product->ID];
            $data['days_without_sale'] = (int)((time() - strtotime($data['last_sale_date'])) / 86400);
        } else {
            $data['days_without_sale'] = 999;
            $data['last_sale_date'] = null;
        }
        
        // Obtener ventas de cache
        $sales = isset($this->cached_data['sales'][$product->ID]) 
            ? $this->cached_data['sales'][$product->ID] 
            : array('last_30' => 0, 'last_60' => 0, 'last_90' => 0, 'last_120' => 0);
        
        // Promedio diario según perodo de análisis
        $period_key = 'last_' . $this->analysis_days;
        $period_sales = isset($sales[$period_key]) ? $sales[$period_key] : 0;
        $avg_daily = $period_sales / $this->analysis_days;
        
        // Meses de stock
        $data['stock_months'] = $avg_daily > 0 ? $data['current_stock'] / ($avg_daily * 30) : 99;
        
        // Tendencia (comparar últimos 30 días vs 30 das anteriores)
        $sales_prev_30d = $sales['last_60'] - $sales['last_30'];
        if ($sales_prev_30d > 0) {
            $data['sales_trend_30d'] = round((($sales['last_30'] - $sales_prev_30d) / $sales_prev_30d) * 100, 2);
        } else {
            $data['sales_trend_30d'] = $sales['last_30'] > 0 ? 100 : 0;
        }
        
        // Precio y valor inmovilizado
        $purchase_info = isset($this->cached_data['purchases'][$product->sku]) 
            ? $this->cached_data['purchases'][$product->sku] 
            : array('avg_price' => 0, 'last_purchase' => null);
        
        $data['avg_purchase_price'] = $purchase_info['avg_price'];
        
        // Usar precio de venta para el valor inmovilizado
        $sale_price = floatval($product->price);
        if ($sale_price <= 0) {
            // Si no hay precio en el objeto, buscarlo directamente
            $sale_price = floatval(get_post_meta($product->ID, '_price', true));
        }
        $data['immobilized_value'] = $data['current_stock'] * $sale_price;
        $data['last_purchase_date'] = $purchase_info['last_purchase'];
        
        // NUEVO: Detectar y guardar cambios de precio
        $this->track_price_changes($product->ID, $product->sku, $sale_price);
        
        // Risk score
        $data['risk_score'] = $this->calculate_risk_score($data);
        
        // Descuento sugerido
        $data['suggested_discount'] = $this->calculate_suggested_discount($data);
        
        $data['last_update'] = current_time('mysql');
        
        return $data;
    }
    
    /**
     * Inserción masiva en lotes
     */
    private function batch_insert($batch_data) {
        global $wpdb;
        
        if (empty($batch_data)) return;
        
        // Construir VALUES para INSERT masivo
        $values = array();
        $placeholders = array();
        
        foreach ($batch_data as $row) {
            $placeholders[] = "(%d, %s, %s, %d, %s, %d, %f, %f, %f, %f, %s, %d, %d, %s, %d, %s)";
            
            array_push($values,
                $row['product_id'],
                $row['product_name'],
                $row['sku'],
                $row['current_stock'],
                $row['last_sale_date'],
                $row['days_without_sale'],
                $row['stock_months'],
                $row['sales_trend_30d'],
                $row['avg_purchase_price'],
                $row['immobilized_value'],
                $row['last_purchase_date'],
                $row['risk_score'],
                $row['suggested_discount'],
                $row['last_update'],
                0, // marked_for_liquidation
                '' // notes
            );
        }
        
        // Query de inserción masiva con REPLACE
        $sql = "REPLACE INTO {$this->table_cache} 
                (product_id, product_name, sku, current_stock, last_sale_date, 
                 days_without_sale, stock_months, sales_trend_30d, avg_purchase_price, 
                 immobilized_value, last_purchase_date, risk_score, suggested_discount, 
                 last_update, marked_for_liquidation, notes) 
                VALUES " . implode(',', $placeholders);
        
        $wpdb->query($wpdb->prepare($sql, $values));
    }
    
    /**
     * Calcular risk score (mtodo sin cambios)
     */
    private function calculate_risk_score($data) {
        $score = 0;
        
        // Días sin venta (0-40 puntos)
        if ($data['days_without_sale'] >= 120) {
            $score += 40;
        } elseif ($data['days_without_sale'] >= 90) {
            $score += 30;
        } elseif ($data['days_without_sale'] >= 60) {
            $score += 20;
        } elseif ($data['days_without_sale'] >= 30) {
            $score += 10;
        }
        
        // Meses de stock (0-30 puntos)
        if ($data['stock_months'] >= 12) {
            $score += 30;
        } elseif ($data['stock_months'] >= 6) {
            $score += 20;
        } elseif ($data['stock_months'] >= 4) {
            $score += 10;
        }
        
        // Tendencia negativa (0-20 puntos)
        if ($data['sales_trend_30d'] <= -50) {
            $score += 20;
        } elseif ($data['sales_trend_30d'] <= -30) {
            $score += 15;
        } elseif ($data['sales_trend_30d'] <= -20) {
            $score += 10;
        }
        
        // Valor inmovilizado (0-10 puntos)
        if ($data['immobilized_value'] > 1000) {
            $score += 10;
        } elseif ($data['immobilized_value'] > 500) {
            $score += 5;
        }
        
        return min($score, 100);
    }
    
    /**
     * Calcular descuento sugerido (método sin cambios)
     */
    private function calculate_suggested_discount($data) {
        $discount = min(50, floor($data['days_without_sale'] / 30) * 10);
        
        if ($data['stock_months'] > 6) {
            $discount = min(50, $discount + 10);
        }
        
        return $discount;
    }
    
    /**
     * Método para actualización incremental (productos específicos)
     */
    public function update_specific_products($product_ids) {
        if (!$this->cached_data || empty($this->cached_data['products'])) {
            $this->preload_all_data();
        }
        
        $batch_data = array();
        foreach ($product_ids as $product_id) {
            if (isset($this->cached_data['products'][$product_id])) {
                $metrics = $this->calculate_metrics_from_cache($this->cached_data['products'][$product_id]);
                if ($metrics) {
                    $batch_data[] = $metrics;
                }
            }
        }
        
        if (!empty($batch_data)) {
            $this->batch_insert($batch_data);
        }
    }
    /**
     * Rastrear cambios de precio automáticamente
     */
    private function track_price_changes($product_id, $sku, $current_price) {
        global $wpdb;
        
        // DEBUG
        error_log("Track price: ID=$product_id, SKU=$sku, Precio=$current_price");
        
        if ($current_price <= 0) {
            error_log("Precio 0 o negativo, saliendo");
            return;
        }
        
        // Obtener el último precio registrado
        $last_record = $wpdb->get_row($wpdb->prepare("
            SELECT new_price 
            FROM {$wpdb->prefix}fc_price_history 
            WHERE product_id = %d 
            ORDER BY change_date DESC 
            LIMIT 1
        ", $product_id));
        
        // Si no hay registro o el precio cambió, guardar
        if (!$last_record || floatval($last_record->new_price) != $current_price) {
            $old_price = $last_record ? floatval($last_record->new_price) : $current_price;
            $change_percent = $old_price > 0 ? round((($current_price - $old_price) / $old_price) * 100, 2) : 0;
            
            error_log("Insertando: old=$old_price, new=$current_price, change=$change_percent%");
            
            $result = $wpdb->insert(
                $wpdb->prefix . 'fc_price_history',
                array(
                    'product_id' => $product_id,
                    'sku' => $sku,
                    'old_price' => $old_price,
                    'new_price' => $current_price,
                    'change_percent' => $change_percent,
                    'change_date' => current_time('mysql')
                ),
                array('%d', '%s', '%f', '%f', '%f', '%s')
            );
            
            // INICIO MODIFICACIÓN - Emitir evento para el gestor de rebajados
            if ($result !== false && $change_percent != 0) {
                do_action('fc_price_change_detected', $product_id, $old_price, $current_price, $change_percent);
            }
            // FIN MODIFICACIÓN
            
            if ($result === false) {
                error_log("Error al insertar: " . $wpdb->last_error);
            } else {
                error_log("Insertado correctamente");
            }
            // INICIO MODIFICACIÓN - Emitir evento para el gestor de rebajados
            if ($result !== false && $change_percent != 0) {
                error_log("REBAJADOS: Disparando evento - Product ID: $product_id, Change: $change_percent%");
                do_action('fc_price_change_detected', $product_id, $old_price, $current_price, $change_percent);
            }
            // FIN MODIFICACIÓN
        } else {
            error_log("No hubo cambio de precio");
        }
    }
}