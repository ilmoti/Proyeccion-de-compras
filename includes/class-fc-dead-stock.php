<?php
/**
 * Clase para manejar análisis de stock muerto
 */

if (!defined('ABSPATH')) {
    exit;
}

class FC_Dead_Stock {
    
    private $per_page = 30;
    private $current_page;
    
    public function __construct() {
        $this->current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        
        // Procesar acciones
        if (isset($_POST['action'])) {
            $this->process_actions();
        }
    }
    
    public function render_page() {
        // Verificar si cambió el período de análisis
        $current_analysis_days = isset($_GET['analysis_days']) ? intval($_GET['analysis_days']) : 30;
        $last_analysis_days = get_option('fc_dead_stock_last_analysis_days', 30);
        
        $should_update = false;
        
        // Determinar si necesitamos actualizar
        if (isset($_GET['force_update']) || (isset($_POST['action']) && $_POST['action'] == 'update_cache')) {
            $should_update = true;
        } elseif ($current_analysis_days != $last_analysis_days) {
            $should_update = true;
            update_option('fc_dead_stock_last_analysis_days', $current_analysis_days);
        }
        
        // Ejecutar actualización UNA SOLA VEZ
        if ($should_update) {
            $this->update_cache();
        }
        
        // Actualizar caché si es necesario (por tiempo)
        $this->maybe_update_cache();
        
        ?>
        <div class="wrap">
            <h1>Análisis de Stock Sin Rotación</h1>
            
            <?php $this->render_summary_boxes(); ?>
            <?php $this->render_filters(); ?>
            <?php $this->render_table(); ?>
            <?php $this->render_actions(); ?>
        </div>
        <!-- Cargar Chart.js desde CDN -->
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        
        <style>
            .dead-stock-summary {
                display: flex;
                gap: 20px;
                margin: 20px 0;
            }
            .summary-box {
                flex: 1;
                background: #fff;
                border: 1px solid #ccd0d4;
                padding: 20px;
                text-align: center;
            }
            .summary-box .number {
                font-size: 32px;
                font-weight: bold;
                margin: 10px 0;
            }
            .risk-high { color: #dc3545; }
            .risk-medium { color: #ffc107; }
            .risk-low { color: #28a745; }
            .trend-down { color: #dc3545; }
            .trend-up { color: #28a745; }
            
            /* Nuevos estilos para ordenamiento */
            .wp-list-table thead th a {
                display: block;
                color: #2c3338;
            }
            .wp-list-table thead th a:hover {
                color: #2271b1;
            }
            
            /* Centrar contenido de la tabla */
            .wp-list-table td {
                text-align: center !important;
                vertical-align: middle !important;
            }
            
            .wp-list-table th {
                text-align: center !important;
            }
            
            /* Mantener el nombre del producto alineado a la izquierda */
            .wp-list-table td:first-child {
                text-align: left !important;
            }
            
            /* Centrar los encabezados pero mantener los enlaces clickeables */
            .wp-list-table thead th a {
                display: block;
                text-align: center;
                color: #2c3338;
            }
            
            /* Centrar botones y formularios en las celdas */
            .wp-list-table td form {
                display: inline-block;
                margin: 0 auto;
            }
            
            .wp-list-table td .button,
            .wp-list-table td .button-small {
                margin: 2px;
            }
            
        </style>
        <?php
    }
    
    private function render_summary_boxes() {
        global $wpdb;
        $table = $wpdb->prefix . 'fc_stock_analysis_cache';
        
        // Construir WHERE clause basado en los mismos filtros de la tabla
        $where = array("current_stock > 0");
        
        // Aplicar búsqueda
        if (isset($_GET['search']) && $_GET['search']) {
            $search_term = sanitize_text_field($_GET['search']);
            $words = preg_split('/\s+/', trim($search_term));
            
            if (count($words) > 1) {
                $name_conditions = array();
                foreach ($words as $word) {
                    if (strlen($word) > 1) {
                        $word_search = '%' . $wpdb->esc_like($word) . '%';
                        $name_conditions[] = $wpdb->prepare("product_name LIKE %s", $word_search);
                    }
                }
                $full_search = '%' . $wpdb->esc_like($search_term) . '%';
                if (!empty($name_conditions)) {
                    $where[] = "(" . implode(' AND ', $name_conditions) . " OR " . 
                               $wpdb->prepare("sku LIKE %s", $full_search) . ")";
                }
            } else {
                $search = '%' . $wpdb->esc_like($search_term) . '%';
                $where[] = $wpdb->prepare(
                    "(product_name LIKE %s OR sku LIKE %s)", 
                    $search, 
                    $search
                );
            }
        }
        
        // Aplicar filtro de días sin venta
        if (isset($_GET['filter_days']) && $_GET['filter_days']) {
            $where[] = "days_without_sale >= " . intval($_GET['filter_days']);
        }
        
        // Aplicar filtro de meses de stock
        if (isset($_GET['filter_stock']) && $_GET['filter_stock']) {
            $where[] = "stock_months >= " . intval($_GET['filter_stock']);
        }
        
        // Aplicar filtro de categoría
        if (isset($_GET['filter_category']) && $_GET['filter_category'] > 0) {
            $cat_id = intval($_GET['filter_category']);
            $product_ids = $this->get_products_by_category($cat_id);
            if (!empty($product_ids)) {
                $where[] = "product_id IN (" . implode(',', $product_ids) . ")";
            }
        }
        
        // Excluir GRT-ITEM
        $where[] = "sku NOT LIKE 'GRT-ITEM%'";
        
        $where_clause = implode(' AND ', $where);
        
        // Obtener estadísticas con los filtros aplicados
        $stats = $wpdb->get_row("
            SELECT 
                COUNT(CASE WHEN days_without_sale >= 30 THEN 1 END) as sin_venta_30,
                COUNT(CASE WHEN days_without_sale >= 90 THEN 1 END) as sin_venta_90,
                SUM(immobilized_value) as valor_total,
                COUNT(CASE WHEN risk_score >= 75 THEN 1 END) as alto_riesgo
            FROM $table
            WHERE $where_clause
        ");
        
        ?>
        <div class="dead-stock-summary">
            <div class="summary-box">
                <div class="title">Sin venta +30 das</div>
                <div class="number risk-medium"><?php echo intval($stats->sin_venta_30); ?></div>
                <div class="label">productos</div>
            </div>
            
            <div class="summary-box">
                <div class="title">Sin venta +90 días</div>
                <div class="number risk-high"><?php echo intval($stats->sin_venta_90); ?></div>
                <div class="label">productos críticos</div>
            </div>
            
            <div class="summary-box">
                <div class="title">Valor Inmovilizado</div>
                <div class="number">$<?php echo number_format($stats->valor_total, 0); ?></div>
                <div class="label">en stock sin rotación</div>
            </div>
            
            <div class="summary-box">
                <div class="title">Alto Riesgo</div>
                <div class="number risk-high"><?php echo intval($stats->alto_riesgo); ?></div>
                <div class="label">productos score 75+</div>
            </div>
        </div>
        <?php
    }
    
    private function render_filters() {
        $current_filter = isset($_GET['filter_days']) ? $_GET['filter_days'] : ''; 
        $current_stock = isset($_GET['filter_stock']) ? $_GET['filter_stock'] : '';
        $current_category = isset($_GET['filter_category']) ? intval($_GET['filter_category']) : 0;
        $current_analysis = isset($_GET['analysis_days']) ? intval($_GET['analysis_days']) : 30;
        $search_term = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : ''; // NUEVA LÍNEA
        ?>
        <form method="get" style="background: #fff; padding: 15px; margin-bottom: 20px;">
            <input type="hidden" name="page" value="fc-dead-stock">
            
            <label>Buscar:
                <input type="text" name="search" value="<?php echo esc_attr($search_term); ?>" 
                       placeholder="Nombre o SKU..." style="width: 200px;">
            </label>
            
            <label>Periodo de analisis:
                <select name="analysis_days">
                    <option value="30" <?php selected($current_analysis, 30); ?>>30 días</option>
                    <option value="60" <?php selected($current_analysis, 60); ?>>60 días</option>
                    <option value="90" <?php selected($current_analysis, 90); ?>>90 días</option>
                    <option value="120" <?php selected($current_analysis, 120); ?>>120 días</option>
                    <option value="150" <?php selected($current_analysis, 150); ?>>150 días</option>
                    <option value="180" <?php selected($current_analysis, 180); ?>>180 días</option>
                    <option value="360" <?php selected($current_analysis, 360); ?>>360 días</option>
                </select>
            </label>
            
            <label>Dias sin venta:
                <select name="filter_days">
                    <option value="">Todos</option>
                    <option value="30" <?php selected($current_filter, '30'); ?>>+30 das</option>
                    <option value="60" <?php selected($current_filter, '60'); ?>>+60 días</option>
                    <option value="90" <?php selected($current_filter, '90'); ?>>+90 das</option>
                    <option value="120" <?php selected($current_filter, '120'); ?>>+120 días</option>
                </select>
            </label>
            
            <label>Meses de stock:
                <select name="filter_stock">
                    <option value="">Todos</option>
                    <option value="4" <?php selected($current_stock, '4'); ?>>+4 meses</option>
                    <option value="6" <?php selected($current_stock, '6'); ?>>+6 meses</option>
                    <option value="12" <?php selected($current_stock, '12'); ?>>+12 meses</option>
                </select>
            </label>
            
            <label>Categoría:
                <?php wp_dropdown_categories(array(
                    'taxonomy' => 'product_cat',
                    'name' => 'filter_category',
                    'show_option_all' => 'Todas',
                    'selected' => $current_category,
                    'hierarchical' => true
                )); ?>
            </label>
            
            <button type="submit" class="button">Filtrar</button>
            <a href="?page=fc-dead-stock" class="button">Limpiar</a>
            <?php if ($search_term): ?>
                <span style="margin-left: 10px; color: #666;">
                    Buscando: "<strong><?php echo esc_html($search_term); ?></strong>"
                </span>
            <?php endif; ?>
            </form>
        <?php
    }
    
    private function process_actions() {
        if (!isset($_POST['fc_nonce']) || !wp_verify_nonce($_POST['fc_nonce'], 'fc_dead_stock_action')) {
            return;
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'fc_stock_analysis_cache';
        
        switch ($_POST['action']) {
            case 'update_discount':
                $wpdb->update(
                    $table,
                    array('suggested_discount' => intval($_POST['discount'])),
                    array('product_id' => intval($_POST['product_id']))
                );
                break;
                
            case 'mark_liquidation':
                $wpdb->update(
                    $table,
                    array('marked_for_liquidation' => 1),
                    array('product_id' => intval($_POST['product_id']))
                );
                break;
                
            case 'save_note':
                $wpdb->update(
                    $table,
                    array('notes' => sanitize_textarea_field($_POST['notes'])),
                    array('product_id' => intval($_POST['product_id']))
                );
                break;
                
                case 'update_cache':
                $this->update_cache();
                break;
        }
        
        echo '<div class="notice notice-success"><p>Accin completada.</p></div>';
    }
    
    private function render_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'fc_stock_analysis_cache';
        
        // Construir WHERE clause basado en filtros
        $where = array("1=1");  // Mostrar todos por defecto
        
        // NUEVO: Agregar búsqueda por nombre o SKU
        if (isset($_GET['search']) && $_GET['search']) {
            $search_term = sanitize_text_field($_GET['search']);
            
            // Dividir la búsqueda en palabras individuales
            $words = preg_split('/\s+/', trim($search_term));
            
            if (count($words) > 1) {
                // Para múltiples palabras, todas deben estar en el nombre del producto
                $name_conditions = array();
                foreach ($words as $word) {
                    if (strlen($word) > 1) { // Permitir palabras desde 2 caracteres
                        $word_search = '%' . $wpdb->esc_like($word) . '%';
                        $name_conditions[] = $wpdb->prepare("product_name LIKE %s", $word_search);
                    }
                }
                
                // También buscar el término completo en SKU
                $full_search = '%' . $wpdb->esc_like($search_term) . '%';
                
                if (!empty($name_conditions)) {
                    $where[] = "(" . implode(' AND ', $name_conditions) . " OR " . 
                               $wpdb->prepare("sku LIKE %s", $full_search) . ")";
                }
            } else {
                // Búsqueda simple de una palabra
                $search = '%' . $wpdb->esc_like($search_term) . '%';
                $where[] = $wpdb->prepare(
                    "(product_name LIKE %s OR sku LIKE %s)", 
                    $search, 
                    $search
                );
            }
        }
        
        if (isset($_GET['filter_days']) && $_GET['filter_days']) {
            $where[] = "days_without_sale >= " . intval($_GET['filter_days']);
        }
        
        if (isset($_GET['filter_stock']) && $_GET['filter_stock']) {
            $where[] = "stock_months >= " . intval($_GET['filter_stock']);
        }
        
        if (isset($_GET['filter_category']) && $_GET['filter_category'] > 0) {
            $cat_id = intval($_GET['filter_category']);
            $product_ids = $this->get_products_by_category($cat_id);
            if (!empty($product_ids)) {
                $where[] = "product_id IN (" . implode(',', $product_ids) . ")";
            }
        }
        
        $where_clause = implode(' AND ', $where);
        
        // Obtener total para paginación
        $total = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE $where_clause");
        // Configurar ordenamiento
        $orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'risk_score';
        $order = isset($_GET['order']) ? sanitize_text_field($_GET['order']) : 'DESC';
        
        // Validar columna de ordenamiento
        $valid_columns = array(
            'product_name', 'days_without_sale', 'last_sale_date', 'current_stock', 
            'stock_months', 'immobilized_value', 'sales_trend_30d', 
            'last_purchase_date', 'risk_score', 'suggested_discount'
        );
        if (!in_array($orderby, $valid_columns)) {
            $orderby = 'risk_score';
        }
        $order = ($order === 'ASC') ? 'ASC' : 'DESC';
        $total_pages = ceil($total / $this->per_page);
        $offset = ($this->current_page - 1) * $this->per_page;
        
        // Obtener productos
        $query = "SELECT * FROM $table 
                 WHERE $where_clause
                 ORDER BY $orderby $order
                 LIMIT $offset, {$this->per_page}";
            
            $products = $wpdb->get_results($query);
        
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 300px;"><?php echo $this->get_sortable_column('product_name', 'Producto'); ?></th>
                    <th><?php echo $this->get_sortable_column('days_without_sale', 'Días sin venta'); ?></th>
                    <th><?php echo $this->get_sortable_column('last_sale_date', 'Última venta'); ?></th>
                    <th><?php echo $this->get_sortable_column('current_stock', 'Stock'); ?></th>
                    <th><?php echo $this->get_sortable_column('stock_months', 'Meses de Stock'); ?></th>
                    <th><?php echo $this->get_sortable_column('immobilized_value', 'Valor inmov.'); ?></th>
                    <th><?php echo $this->get_sortable_column('sales_trend_30d', 'Tendencia 30 Dias'); ?></th>
                    <th><?php echo $this->get_sortable_column('last_purchase_date', 'Última compra'); ?></th>
                    <th><?php echo $this->get_sortable_column('risk_score', 'Riesgo'); ?></th>
                    <th>Historial Precio</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $product): ?>
                    <tr class="<?php echo $product->marked_for_liquidation ? 'marked-liquidation' : ''; ?>">
                        <td>
                            <strong><?php echo esc_html($product->product_name); ?></strong><br>
                            <small>SKU: <?php echo esc_html($product->sku); ?></small>
                            <?php if ($product->notes): ?>
                                <br><small>📝 <?php echo esc_html(substr($product->notes, 0, 50)); ?>...</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="<?php echo $product->days_without_sale >= 90 ? 'risk-high' : ($product->days_without_sale >= 60 ? 'risk-medium' : ''); ?>">
                                <?php echo $product->days_without_sale; ?> días
                            </span>
                        </td>
                        <td>
                            <?php 
                            if ($product->last_sale_date && strtotime($product->last_sale_date) > 0) {
                                $date = date('d/m/Y', strtotime($product->last_sale_date));
                                $days_ago = $product->days_without_sale;
                                
                                // Verificar si es una fecha válida
                                if (strpos($date, '-0001') !== false) {
                                    echo '<span style="color: #999;">Nunca</span>';
                                } else {
                                    // Colorear segn antigüedad
                                    if ($days_ago >= 90) {
                                        echo '<span class="risk-high">' . $date . '</span>';
                                    } elseif ($days_ago >= 60) {
                                        echo '<span class="risk-medium">' . $date . '</span>';
                                    } else {
                                        echo $date;
                                    }
                                }
                            } else {
                                echo '<span style="color: #999;">Nunca</span>';
                            }
                            ?>
                        </td>
                        <td><?php echo $product->current_stock; ?></td>
                        <td>
                            <?php if ($product->stock_months > 12): ?>
                                <span class="risk-high">+12 meses</span>
                            <?php else: ?>
                                <?php echo number_format($product->stock_months, 1); ?> meses
                            <?php endif; ?>
                        </td>
                        <td>$<?php echo number_format($product->immobilized_value, 0); ?></td>
                        <td>
                            <?php if ($product->sales_trend_30d < -20): ?>
                                <span class="trend-down"> <?php echo $product->sales_trend_30d; ?>%</span>
                            <?php elseif ($product->sales_trend_30d > 20): ?>
                                <span class="trend-up"> <?php echo $product->sales_trend_30d; ?>%</span>
                            <?php else: ?>
                                 <?php echo $product->sales_trend_30d; ?>%
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php 
                            if ($product->last_purchase_date && strtotime($product->last_purchase_date) > 0) {
                                $date = date('d/m/Y', strtotime($product->last_purchase_date));
                                // Verificar si es una fecha válida
                                if (strpos($date, '-0001') !== false) {
                                    echo '<span style="color: #999;">Sin compras</span>';
                                } else {
                                    echo $date;
                                }
                            } else {
                                echo '<span style="color: #999;">Sin compras</span>';
                            }
                            ?>
                        </td>
                        <td style="text-align: center;">
                            <div style="width: 60px; height: 20px; background: #e0e0e0; border-radius: 10px; overflow: hidden; margin: 0 auto;">
                                <div style="width: <?php echo $product->risk_score; ?>%; height: 100%; 
                                            background: <?php echo $product->risk_score >= 75 ? '#dc3545' : ($product->risk_score >= 50 ? '#ffc107' : '#28a745'); ?>">
                                </div>
                            </div>
                            <small style="display: block; text-align: center; margin-top: 2px;"><?php echo $product->risk_score; ?>/100</small>
                        </td>
                        <td>
                            <?php
                            // Precio actual de WooCommerce
                            $current_price = get_post_meta($product->product_id, '_price', true);
                            
                            // Obtener el último cambio de precio
                            $last_price_change = $wpdb->get_row($wpdb->prepare("
                                SELECT old_price, new_price, change_percent, change_date 
                                FROM {$wpdb->prefix}fc_price_history 
                                WHERE product_id = %d 
                                ORDER BY change_date DESC 
                                LIMIT 1
                            ", $product->product_id));
                            
                            // Mostrar precio actual prominentemente
                            echo '<div style="font-size: 16px; font-weight: bold; color: #2271b1;">$' . number_format($current_price, 0) . '</div>';
                            
                            if ($last_price_change && $last_price_change->old_price != $last_price_change->new_price) {
                                // Calcular días desde el último cambio
                                $days_since_change = floor((time() - strtotime($last_price_change->change_date)) / 86400);
                                
                                // Determinar color y símbolo según si fue aumento o descuento
                                if ($last_price_change->change_percent < 0) {
                                    // Descuento
                                    $color = '#00a32a'; // Verde
                                    $symbol = '↓';
                                    $text = 'Rebajado';
                                } else {
                                    // Aumento
                                    $color = '#d63638'; // Rojo
                                    $symbol = '↑';
                                    $text = 'Aumento';
                                }
                                
                                // Mostrar variación
                                echo '<div style="font-size: 13px; color: ' . $color . '; font-weight: 500;">';
                                echo $symbol . ' ' . number_format(abs($last_price_change->change_percent), 1) . '% ' . $text;
                                echo '</div>';
                                
                                // Mostrar hace cuántos días
                                echo '<div style="font-size: 11px; color: #666;">';
                                echo 'Hace ' . $days_since_change . ' días';
                                echo '</div>';
                                
                                // Precio anterior (más pequeño)
                                echo '<div style="font-size: 11px; color: #999; text-decoration: line-through;">';
                                echo 'Antes: $' . number_format($last_price_change->old_price, 0);
                                echo '</div>';
                            } else {
                                echo '<div style="font-size: 11px; color: #666;">Sin cambios recientes</div>';
                            }
                        
                            ?>
                        </td>
                        <td>
                            <button class="button button-small" onclick="showProductDetails(<?php echo $product->product_id; ?>)">
                                 Ventas
                            </button>
                            <button class="button button-small" onclick="showPriceHistory(<?php echo $product->product_id; ?>)">
                                💰 Precios
                            </button>
                            <button class="button button-small" onclick="findSimilarProducts('<?php echo esc_js($product->product_name); ?>')">
                                 Similares
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <?php $this->render_pagination($total_pages); ?>
        
        <script>
        function showProductDetails(productId) {
            jQuery.post(ajaxurl, {
                action: 'fc_get_product_history_dead_stock',  // Cambiar aquí el action
                product_id: productId
            }, function(response) {
                if (response.success) {
                    // Crear modal
                    var modal = `
                        <div id="product-history-modal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
                             background: rgba(0,0,0,0.5); z-index: 9999; display: flex; align-items: center; justify-content: center;">
                            <div style="background: white; padding: 30px; width: 80%; max-width: 900px; max-height: 80vh; overflow-y: auto; border-radius: 5px;">
                                <h2 style="margin-top: 0;">${response.data.product_name}</h2>
                                <canvas id="historyChart" width="400" height="200"></canvas>
                                <div style="margin-top: 20px; display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px;">
                                    <div style="text-align: center; padding: 15px; background: #f0f0f0; border-radius: 5px;">
                                        <div style="font-size: 24px; font-weight: bold; color: #2271b1;">${response.data.total_sold}</div>
                                        <div style="color: #666;">Unidades vendidas (6 meses)</div>
                                    </div>
                                    <div style="text-align: center; padding: 15px; background: #f0f0f0; border-radius: 5px;">
                                        <div style="font-size: 24px; font-weight: bold; color: #d63638;">${response.data.total_purchased}</div>
                                        <div style="color: #666;">Unidades compradas (6 meses)</div>
                                    </div>
                                    <div style="text-align: center; padding: 15px; background: #f0f0f0; border-radius: 5px;">
                                        <div style="font-size: 24px; font-weight: bold; color: #00a32a;">${response.data.avg_monthly_sales}</div>
                                        <div style="color: #666;">Promedio mensual ventas</div>
                                    </div>
                                </div>
                                <div style="margin-top: 20px;">
                                    <strong>Stock actual:</strong> ${response.data.current_stock} unidades
                                </div>
                                <button onclick="jQuery('#product-history-modal').remove()" 
                                        class="button button-primary" style="margin-top: 20px;">Cerrar</button>
                            </div>
                        </div>
                    `;
                    
                    jQuery('body').append(modal);
                    
                    // Crear gráfico con Chart.js
                    var ctx = document.getElementById('historyChart').getContext('2d');
                    new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: response.data.labels,
                            datasets: [{
                                label: 'Ventas',
                                data: response.data.sales,
                                borderColor: '#2271b1',
                                backgroundColor: 'rgba(34, 113, 177, 0.1)',
                                tension: 0.1
                            }, {
                                label: 'Compras',
                                data: response.data.purchases,
                                borderColor: '#d63638',
                                backgroundColor: 'rgba(214, 54, 56, 0.1)',
                                tension: 0.1
                            }]
                        },
                        options: {
                            responsive: true,
                            interaction: {
                                mode: 'index',
                                intersect: false
                            },
                            plugins: {
                                title: {
                                    display: true,
                                    text: 'Historial de Movimientos - ltimos 6 Meses'
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        precision: 0
                                    }
                                }
                            }
                        }
                    });
                } else {
                    alert('Error al cargar el historial: ' + response.data);
                }
            });
        }
        
        function showPriceHistory(productId) {
            jQuery.post(ajaxurl, {
                action: 'fc_get_price_history',
                product_id: productId
            }, function(response) {
                if (response.success) {
                    var modal = `
                        <div id="price-history-modal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
                             background: rgba(0,0,0,0.5); z-index: 9999; display: flex; align-items: center; justify-content: center;">
                            <div style="background: white; padding: 30px; width: 700px; max-width: 90%; max-height: 80vh; 
                                 overflow-y: auto; border-radius: 5px;">
                                <h2 style="margin-top: 0;">${response.data.product_name}</h2>
                                <h3>Historial de Precios</h3>
                                <table style="width: 100%; border-collapse: collapse;">
                                    <thead>
                                        <tr style="background: #f0f0f0;">
                                            <th style="padding: 10px; text-align: left;">Fecha</th>
                                            <th style="padding: 10px; text-align: right;">Precio Anterior</th>
                                            <th style="padding: 10px; text-align: right;">Precio Nuevo</th>
                                            <th style="padding: 10px; text-align: center;">Cambio</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${response.data.history}
                                    </tbody>
                                </table>
                                <button onclick="jQuery('#price-history-modal').remove()" 
                                        class="button button-primary" style="margin-top: 20px;">Cerrar</button>
                            </div>
                        </div>
                    `;
                    jQuery('body').append(modal);
                }
            });
        }
        
        function findSimilarProducts(productName) {
            jQuery.post(ajaxurl, {
                action: 'fc_find_similar_products',
                product_name: productName
            }, function(response) {
                // Crear modal para mostrar productos similares
                var modal = `
                    <div id="similar-products-modal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
                         background: rgba(0,0,0,0.5); z-index: 9999; display: flex; align-items: center; justify-content: center;">
                        <div style="background: white; padding: 30px; width: 600px; max-width: 90%; max-height: 80vh; 
                             overflow-y: auto; border-radius: 5px; position: relative;">
                            <button onclick="jQuery('#similar-products-modal').remove()" 
                                    style="position: absolute; top: 10px; right: 10px; font-size: 20px; 
                                    background: none; border: none; cursor: pointer;"></button>
                            <h2 style="margin-top: 0;">Productos Similares</h2>
                            <p style="color: #666;">Búsqueda basada en: "<strong>${productName}</strong>"</p>
                            <div style="margin: 20px 0;">
                                ${response}
                            </div>
                            <button onclick="jQuery('#similar-products-modal').remove()" 
                                    class="button button-primary" style="margin-top: 20px;">Cerrar</button>
                        </div>
                    </div>
                `;
                
                jQuery('body').append(modal);
            }).fail(function() {
                alert('Error al buscar productos similares');
            });
        }
        </script>
        
        <style>
            .marked-liquidation {
                background-color: #fff3cd !important;
            }
        </style>
        <?php
    }
    
    private function render_pagination($total_pages) {
        if ($total_pages <= 1) return;
        
        echo '<div class="tablenav bottom"><div class="tablenav-pages">';
        
        $base_url = admin_url('admin.php?page=fc-dead-stock');
        foreach ($_GET as $key => $value) {
            if ($key != 'paged' && $key != 'page') {
                $base_url .= '&' . $key . '=' . urlencode($value);
            }
        }
        
        echo paginate_links(array(
            'base' => $base_url . '%_%',
            'format' => '&paged=%#%',
            'current' => $this->current_page,
            'total' => $total_pages
        ));
        
        echo '</div></div>';
    }
    
    private function render_actions() {
        ?>
        <div style="margin-top: 20px;">
            <form method="post" style="display: inline;">
                <?php wp_nonce_field('fc_dead_stock_action', 'fc_nonce'); ?>
                <input type="hidden" name="action" value="update_cache">
                <button type="submit" class="button"> Actualizar datos</button>
            </form>
            
            <a href="?page=fc-dead-stock&action=export" class="button"> Exportar Excel</a>
            
            <a href="?page=fc-dead-stock&action=email_config" class="button"> Configurar emails</a>
        </div>
        <?php
    }
    
    public function handle_export() {
        if (!isset($_GET['action']) || $_GET['action'] != 'export') {
            return;
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'fc_stock_analysis_cache';
        
        // Obtener todos los productos problemticos
        $products = $wpdb->get_results("
            SELECT * FROM $table 
            WHERE current_stock > 0 AND (days_without_sale >= 30 OR stock_months >= 4)
            ORDER BY risk_score DESC, immobilized_value DESC
        ");
        
        // Crear CSV
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=stock_sin_rotacion_' . date('Y-m-d') . '.csv');
        
        $output = fopen('php://output', 'w');
        
        // Encabezados
        fputcsv($output, array(
            'SKU',
            'Producto',
            'Das sin venta',
            'Stock actual',
            'Meses de stock',
            'Valor inmovilizado',
            'Tendencia 30d',
            'Risk Score',
            'Descuento sugerido',
            'ltima compra',
            'Notas'
        ));
        
        // Datos
        foreach ($products as $product) {
            fputcsv($output, array(
                $product->sku,
                $product->product_name,
                $product->days_without_sale,
                $product->current_stock,
                $product->stock_months,
                $product->immobilized_value,
                $product->sales_trend_30d . '%',
                $product->risk_score,
                $product->suggested_discount . '%',
                $product->last_purchase_date,
                $product->notes
            ));
        }
        
        fclose($output);
        exit;
    }
    
    private function maybe_update_cache() {
        $last_update = get_option('fc_dead_stock_last_update', 0);
        
        // Actualizar si han pasado más de 6 horas
        if (time() - $last_update > 6 * HOUR_IN_SECONDS) {
            $this->update_cache();
        }
    }
    
    private function update_cache() {
        global $wpdb;
        
        // Limpiar completamente la tabla antes de regenerar
        $table = $wpdb->prefix . 'fc_stock_analysis_cache';
        $wpdb->query("TRUNCATE TABLE $table");
        
        // Obtener el período de análisis actual
        $analysis_days = isset($_GET['analysis_days']) ? intval($_GET['analysis_days']) : 30;
        
        // Regenerar todos los datos con cálculos frescos
        require_once FC_PLUGIN_PATH . 'includes/class-fc-dead-stock-calculator.php';
        $calculator = new FC_Dead_Stock_Calculator($analysis_days);
        $processed = $calculator->calculate_all_products();
        
        update_option('fc_dead_stock_last_update', time());
        update_option('fc_dead_stock_last_analysis_days', $analysis_days);
        
        // Registrar cambios de precio
        $products_in_cache = $wpdb->get_results("
            SELECT product_id, sku FROM $table
        ");
        
        foreach ($products_in_cache as $prod) {
            $current_price = floatval(get_post_meta($prod->product_id, '_price', true));
            if ($current_price <= 0) continue;
            
            $last_record = $wpdb->get_row($wpdb->prepare("
                SELECT new_price FROM {$wpdb->prefix}fc_price_history 
                WHERE product_id = %d 
                ORDER BY change_date DESC 
                LIMIT 1
            ", $prod->product_id));
            
            if (!$last_record) {
                // Primer registro
                $wpdb->insert(
                    $wpdb->prefix . 'fc_price_history',
                    array(
                        'product_id' => $prod->product_id,
                        'sku' => $prod->sku,
                        'old_price' => $current_price,
                        'new_price' => $current_price,
                        'change_percent' => 0,
                        'change_date' => current_time('mysql')
                    )
                );
            } elseif (floatval($last_record->new_price) != $current_price) {
                // Cambio detectado
                $old_price = floatval($last_record->new_price);
                $change_percent = $old_price > 0 ? round((($current_price - $old_price) / $old_price) * 100, 2) : 0;
                
                $wpdb->insert(
                    $wpdb->prefix . 'fc_price_history',
                    array(
                        'product_id' => $prod->product_id,
                        'sku' => $prod->sku,
                        'old_price' => $old_price,
                        'new_price' => $current_price,
                        'change_percent' => $change_percent,
                        'change_date' => current_time('mysql')
                    )
                );
            }
        }
        
        // Mostrar mensaje de xito
        echo '<div class="notice notice-success"><p>Base de datos actualizada con período de ' . $analysis_days . ' días. Procesados: ' . $processed . ' productos</p></div>';
    }
    
    private function get_products_by_category($cat_id) {
        global $wpdb;
        
        // Obtener la categoría y todas sus hijas
        $cat_ids = array($cat_id);
        
        // Obtener categorías hijas
        $children = get_term_children($cat_id, 'product_cat');
        if (!is_wp_error($children) && !empty($children)) {
            $cat_ids = array_merge($cat_ids, $children);
        }
        
        // Convertir a string para la consulta
        $cat_ids_string = implode(',', array_map('intval', $cat_ids));
        
        // Obtener productos de todas las categorías (padre + hijas)
        $product_ids = $wpdb->get_col("
            SELECT DISTINCT p.ID 
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
            INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
            WHERE tt.term_id IN ($cat_ids_string)
            AND tt.taxonomy = 'product_cat'
            AND p.post_type = 'product'
            AND p.post_status = 'publish'
        ");
        
        // Si no hay productos, retornar array vacío
        if (empty($product_ids)) {
            return array();
        }
        
        // Obtener también las variaciones de estos productos
        $variations = $wpdb->get_col("
            SELECT ID 
            FROM {$wpdb->posts}
            WHERE post_parent IN (" . implode(',', $product_ids) . ")
            AND post_type = 'product_variation'
            AND post_status = 'publish'
        ");
        
        // Combinar productos principales y variaciones
        $all_ids = array_merge($product_ids, $variations);
        
        return $all_ids;
    }
    /**
     * AJAX handler para obtener historial del producto
     */
    public function ajax_get_product_history() {
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
        
        // Preparar datos para el grfico
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
     * Generar enlace de ordenamiento para columnas
     */
    private function get_sortable_column($column, $label) {
        $current_orderby = isset($_GET['orderby']) ? $_GET['orderby'] : 'risk_score';
        $current_order = isset($_GET['order']) ? $_GET['order'] : 'DESC';
        
        // Determinar el nuevo orden
        $new_order = 'ASC';
        $arrow = '';
        
        if ($current_orderby === $column) {
            $new_order = ($current_order === 'ASC') ? 'DESC' : 'ASC';
            $arrow = ($current_order === 'ASC') ? ' ' : ' ↓';
        }
        
        // Construir URL manteniendo otros parámetros
        $url = admin_url('admin.php?page=fc-dead-stock');
        foreach ($_GET as $key => $value) {
            if (!in_array($key, array('page', 'orderby', 'order'))) {
                $url .= '&' . $key . '=' . urlencode($value);
            }
        }
        $url .= '&orderby=' . $column . '&order=' . $new_order;
        
        return '<a href="' . esc_url($url) . '" style="text-decoration: none; color: inherit;">' . 
               $label . $arrow . '</a>';
    }
}