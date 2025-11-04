<?php
/**
 * Clase para manejar el Dashboard principal
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

class FC_Dashboard {
    
    private $active_tab;
    
    public function __construct() {
        $this->active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'stock';
    }
    
    // Renderizar página principal
    public function render_page() {
        ?>
        <div class="wrap">
            <h1>Dashboard - Forecast de Compras</h1>
            
            <p style="text-align: right; color: #666;">
                <span class="dashicons dashicons-update"></span> 
                Última actualización: <?php echo $this->get_last_update_time(); ?>
                <button type="button" class="button button-small" id="refresh-dashboard">Actualizar ahora</button>
            </p>
            
            <!-- Tabs -->
            <h2 class="nav-tab-wrapper">
                <a href="?page=forecast-compras&tab=stock" 
                   class="nav-tab <?php echo $this->active_tab == 'stock' ? 'nav-tab-active' : ''; ?>">
                   📦 Stock
                </a>
                <a href="?page=forecast-compras&tab=ventas" 
                   class="nav-tab <?php echo $this->active_tab == 'ventas' ? 'nav-tab-active' : ''; ?>">
                   💰 Ventas
                </a>
            </h2>
            
            <div class="fc-dashboard-content">
                <?php 
                if ($this->active_tab == 'ventas') {
                    $this->render_ventas_tab();
                } else {
                    $this->render_stock_tab();
                }
                ?>
            </div>
        </div>
        
        <style>
            .fc-dashboard-content {
                margin-top: 20px;
            }
            .fc-widget {
                background: #fff;
                border: 1px solid #ccd0d4;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
                margin-bottom: 20px;
                padding: 20px;
            }
            .fc-widget h3 {
                margin-top: 0;
                border-bottom: 1px solid #eee;
                padding-bottom: 10px;
            }
            .fc-stats-row {
                display: flex;
                gap: 20px;
                margin-bottom: 20px;
            }
            .fc-stat-box {
                flex: 1;
                background: #fff;
                border: 1px solid #ccd0d4;
                padding: 20px;
                text-align: center;
            }
            .fc-stat-box .number {
                font-size: 36px;
                font-weight: bold;
                margin: 10px 0;
            }
            .fc-stat-box.red { border-left: 4px solid #dc3545; }
            .fc-stat-box.yellow { border-left: 4px solid #ffc107; }
            .fc-stat-box.green { border-left: 4px solid #28a745; }
            .fc-progress {
                background: #f0f0f0;
                height: 30px;
                border-radius: 15px;
                overflow: hidden;
                margin: 10px 0;
            }
            .fc-progress-bar {
                background: #0073aa;
                height: 100%;
                text-align: center;
                line-height: 30px;
                color: white;
                font-weight: bold;
            }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            $('#refresh-dashboard').on('click', function() {
                location.reload();
            });
            
            // Auto-refresh cada 2 horas
            setTimeout(function() {
                location.reload();
            }, 2 * 60 * 60 * 1000);
        });
        </script>
        <?php
    }
    
    // Obtener hora de última actualización
    private function get_last_update_time() {
        $last_update = get_transient('fc_dashboard_last_update');
        if (!$last_update) {
            return 'Nunca';
        }
        return date('d/m/Y H:i', $last_update);
    }
    
    // Renderizar pestaña de Stock
    private function render_stock_tab() {
        global $wpdb;
        
        // Actualizar datos si es necesario
        $this->maybe_update_data();
        
        // Obtener datos del caché
        $stock_data = get_transient('fc_dashboard_stock_data');
        if (!$stock_data) {
            $stock_data = $this->calculate_stock_data();
            set_transient('fc_dashboard_stock_data', $stock_data, 2 * HOUR_IN_SECONDS);
        }
        ?>
        
        <!-- Estadísticas principales -->
        <div class="fc-stats-row">
            <div class="fc-stat-box red">
                <div class="title"> Sin Stock</div>
                <div class="number"><?php echo $stock_data['sin_stock']; ?></div>
                <div class="label">productos</div>
                <a href="admin.php?page=fc-projection&periodo=30">Ver lista</a>
            </div>
            
            <div class="fc-stat-box yellow">
                <div class="title"> Stock Crítico</div>
                <div class="number"><?php echo $stock_data['stock_critico']; ?></div>
                <div class="label">productos < 15 días</div>
                <a href="admin.php?page=fc-projection&periodo=30">Ver lista</a>
            </div>
            
            <div class="fc-stat-box green">
                <div class="title">🟢 Stock Óptimo</div>
                <div class="number"><?php echo $stock_data['stock_optimo']; ?></div>
                <div class="label">productos > 30 das</div>
            </div>
        </div>
        
        <!-- Alertas de Pedidos -->
        <div class="fc-widget">
            <h3>Alertas de Pedidos Activas</h3>
            <?php $this->render_alerts_widget(); ?>
        </div>
        
        <!-- Productos Críticos -->
        <div class="fc-widget">
            <h3>Productos que Necesitan Reposicin Urgente</h3>
            <?php $this->render_critical_products_widget(); ?>
        </div>
        
        <!-- Mercadería en Tránsito -->
        <div class="fc-widget">
            <h3>Mercadería en Tránsito</h3>
            <?php $this->render_transit_widget(); ?>
        </div>
        
        <!-- Stock Sin Rotación -->
        <div class="fc-widget">
            <h3>⚠️ Stock Sin Rotación</h3>
            <?php $this->render_dead_stock_widget(); ?>
        </div>
        
        <?php
    }
    
    private function render_dead_stock_widget() {
        global $wpdb;
        $table = $wpdb->prefix . 'fc_stock_analysis_cache';
        
        $critical = $wpdb->get_results("
            SELECT * FROM $table
            WHERE risk_score >= 75
            ORDER BY immobilized_value DESC
            LIMIT 5
        ");
        
        if (empty($critical)) {
            echo '<p>No hay productos críticos sin rotación.</p>';
            return;
        }
        
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Producto</th>
                    <th>Días sin venta</th>
                    <th>Valor inmov.</th>
                    <th>Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($critical as $product): ?>
                    <tr>
                        <td><?php echo esc_html($product->product_name); ?></td>
                        <td style="color: #dc3545;"><?php echo $product->days_without_sale; ?> días</td>
                        <td>$<?php echo number_format($product->immobilized_value, 0); ?></td>
                        <td>
                            <a href="?page=fc-dead-stock&highlight=<?php echo $product->product_id; ?>" 
                               class="button button-small">Ver</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p style="text-align: center; margin-top: 10px;">
            <a href="?page=fc-dead-stock" class="button">Ver todos</a>
        </p>
        <?php
    }
    
    // Renderizar pestaña de Ventas
    private function render_ventas_tab() {
        // Obtener datos del caché
        $ventas_data = get_transient('fc_dashboard_ventas_data');
        if (!$ventas_data) {
            $ventas_data = $this->calculate_ventas_data();
            set_transient('fc_dashboard_ventas_data', $ventas_data, 2 * HOUR_IN_SECONDS);
        }
        ?>
        
        <!-- Estadsticas de Ventas -->
        <div class="fc-stats-row">
            <div class="fc-stat-box">
                <div class="title">Ventas Hoy</div>
                <div class="number"><?php echo number_format($ventas_data['ventas_hoy']); ?></div>
                <div class="label">unidades</div>
            </div>
            
            <div class="fc-stat-box">
                <div class="title">Ventas Semana</div>
                <div class="number"><?php echo number_format($ventas_data['ventas_semana']); ?></div>
                <div class="label">unidades</div>
            </div>
            
            <div class="fc-stat-box">
                <div class="title">Ventas Mes</div>
                <div class="number"><?php echo number_format($ventas_data['ventas_mes']); ?></div>
                <div class="label">unidades</div>
            </div>
        </div>
        
        <!-- Gráfico de Tendencia -->
        <div class="fc-widget">
            <h3>Tendencia de Ventas - Últimos 30 días</h3>
            <canvas id="ventasChart" width="400" height="100"></canvas>
        </div>
        
        <!-- Top 30 Productos -->
        <div class="fc-widget">
            <h3>Top 30 Productos Más Vendidos del Mes</h3>
            <?php $this->render_top_products_widget($ventas_data['top_productos']); ?>
        </div>
        
        <!-- Productos Sin Ventas -->
        <div class="fc-widget">
            <h3>⚠️ Productos Sin Ventas</h3>
            <p>Hay <strong><?php echo $ventas_data['sin_ventas']; ?></strong> productos sin ventas en los últimos 30 días. 
            <a href="admin.php?page=fc-projection&periodo=30">Ver lista completa</a></p>
        </div>
        
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script>
        jQuery(document).ready(function($) {
            var ctx = document.getElementById('ventasChart').getContext('2d');
            var chart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($ventas_data['chart_labels']); ?>,
                    datasets: [{
                        label: 'Ventas diarias',
                        data: <?php echo json_encode($ventas_data['chart_data']); ?>,
                        borderColor: 'rgb(75, 192, 192)',
                        backgroundColor: 'rgba(75, 192, 192, 0.2)',
                        tension: 0.1
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        title: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        });
        </script>
        
        <?php
    }
    // Verificar si hay que actualizar datos
    private function maybe_update_data() {
        $last_update = get_transient('fc_dashboard_last_update');
        if (!$last_update || (time() - $last_update) > 2 * HOUR_IN_SECONDS) {
            set_transient('fc_dashboard_last_update', time(), 24 * HOUR_IN_SECONDS);
        }
    }
    
    // Calcular datos de stock
    private function calculate_stock_data() {
        global $wpdb;
        
        $data = array(
            'sin_stock' => 0,
            'stock_critico' => 0,
            'stock_optimo' => 0
        );
        
        // Query simplificada para contar productos
        $products = $wpdb->get_results("
            SELECT p.ID, pm.meta_value as stock
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_stock'
            WHERE p.post_type IN ('product', 'product_variation')
            AND p.post_status = 'publish'
        ");
        
        foreach ($products as $product) {
            $stock = intval($product->stock);
            
            if ($stock == 0) {
                $data['sin_stock']++;
            } elseif ($stock < 50) { // Asumiendo que menos de 50 es crítico
                $data['stock_critico']++;
            } else {
                $data['stock_optimo']++;
            }
        }
        
        return $data;
    }
    
    // Renderizar widget de alertas
    private function render_alerts_widget() {
        global $wpdb;
        
        $table_alerts = $wpdb->prefix . 'fc_weight_alerts';
        $alerts = $wpdb->get_results("
            SELECT * FROM {$table_alerts} 
            WHERE status IN ('active', 'ready')
            ORDER BY current_value / limit_value DESC
            LIMIT 5
        ");
        
        if (empty($alerts)) {
            echo '<p>No hay alertas activas.</p>';
            return;
        }
        
        foreach ($alerts as $alert) {
            $progress = $alert->limit_value > 0 ? ($alert->current_value / $alert->limit_value * 100) : 0;
            $unit = $alert->type == 'aereo' ? 'kg' : 'CBM';
            ?>
            <div style="margin-bottom: 15px;">
                <strong><?php echo esc_html($alert->name); ?></strong>
                <div class="fc-progress">
                    <div class="fc-progress-bar" style="width: <?php echo min($progress, 100); ?>%">
                        <?php echo round($progress); ?>%
                    </div>
                </div>
                <div style="text-align: right; font-size: 12px;">
                    <?php echo number_format($alert->current_value, 2); ?> / 
                    <?php echo number_format($alert->limit_value, 2); ?> <?php echo $unit; ?>
                    <?php if ($alert->status == 'ready'): ?>
                        <a href="?page=fc-weight-alerts&action=manage&id=<?php echo $alert->id; ?>" 
                           style="margin-left: 10px;">Gestionar</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php
        }
        ?>
        <p style="text-align: center; margin-top: 20px;">
            <a href="?page=fc-weight-alerts" class="button">Ver todas las alertas</a>
        </p>
        <?php
    }
    
    // Renderizar productos críticos
    private function render_critical_products_widget() {
        global $wpdb;
        
        // Obtener productos con stock bajo
        $critical_products = $wpdb->get_results("
            SELECT 
                p.ID,
                p.post_title,
                pm1.meta_value as stock,
                pm2.meta_value as sku
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_stock'
            LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_alg_ean'
            WHERE p.post_type IN ('product', 'product_variation')
            AND p.post_status = 'publish'
            AND CAST(pm1.meta_value AS SIGNED) BETWEEN 1 AND 20
            ORDER BY CAST(pm1.meta_value AS SIGNED) ASC
            LIMIT 10
        ");
        
        if (empty($critical_products)) {
            echo '<p>No hay productos con stock crtico.</p>';
            return;
        }
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Producto</th>
                    <th>SKU</th>
                    <th>Stock</th>
                    <th>Días aprox.</th>
                    <th>Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($critical_products as $product): 
                    // Calcular días aproximados (simplificado)
                    $dias = rand(3, 15); // Por ahora aleatorio, después lo calculamos real
                ?>
                    <tr>
                        <td><?php echo esc_html($product->post_title); ?></td>
                        <td><?php echo esc_html($product->sku); ?></td>
                        <td style="color: #d63638; font-weight: bold;">
                            <?php echo $product->stock; ?>
                        </td>
                        <td><?php echo $dias; ?> das</td>
                        <td>
                            <a href="?page=fc-projection&highlight=<?php echo $product->ID; ?>" 
                               class="button button-small">Ver detalle</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
    
    // Renderizar widget de tránsito
    private function render_transit_widget() {
        global $wpdb;
        
        $table_orders = $wpdb->prefix . 'fc_orders_history';
        
        // Obtener resumen de órdenes en trnsito
        $transit_data = $wpdb->get_row("
            SELECT 
                COUNT(DISTINCT order_name) as ordenes,
                SUM(quantity) as unidades,
                SUM(quantity * purchase_price) as valor,
                MIN(arrival_date) as proxima_llegada
            FROM {$table_orders}
            WHERE status = 'pending'
        ");
        
        if (!$transit_data || $transit_data->ordenes == 0) {
            echo '<p>No hay mercadería en tránsito actualmente.</p>';
            return;
        }
        ?>
        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px;">
            <div>
                <strong>Órdenes en camino:</strong><br>
                <span style="font-size: 24px;"><?php echo $transit_data->ordenes; ?></span>
            </div>
            <div>
                <strong>Total de unidades:</strong><br>
                <span style="font-size: 24px;"><?php echo number_format($transit_data->unidades); ?></span>
            </div>
            <div>
                <strong>Valor total:</strong><br>
                <span style="font-size: 24px;">$<?php echo number_format($transit_data->valor, 2); ?></span>
            </div>
            <div>
                <strong>Próxima llegada:</strong><br>
                <span style="font-size: 24px;">
                    <?php echo $transit_data->proxima_llegada ? date('d/m/Y', strtotime($transit_data->proxima_llegada)) : 'N/A'; ?>
                </span>
            </div>
        </div>
        <p style="text-align: center; margin-top: 20px;">
            <a href="?page=fc-orders-history" class="button">Ver todas las órdenes</a>
        </p>
        <?php
    }
    // Calcular datos de ventas
    private function calculate_ventas_data() {
        global $wpdb;
        
        $data = array(
            'ventas_hoy' => 0,
            'ventas_semana' => 0,
            'ventas_mes' => 0,
            'sin_ventas' => 0,
            'top_productos' => array(),
            'chart_labels' => array(),
            'chart_data' => array()
        );
        
        // Ventas de hoy
        $data['ventas_hoy'] = $wpdb->get_var("
            SELECT COALESCE(SUM(woim.meta_value), 0)
            FROM {$wpdb->prefix}woocommerce_order_items woi
            JOIN {$wpdb->prefix}woocommerce_order_itemmeta woim ON woi.order_item_id = woim.order_item_id
            JOIN {$wpdb->posts} p ON woi.order_id = p.ID
            WHERE p.post_type = 'shop_order'
            AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-shipped')
            AND DATE(p.post_date) = CURDATE()
            AND woim.meta_key = '_qty'
        ");
        
        // Ventas de la semana
        $data['ventas_semana'] = $wpdb->get_var("
            SELECT SUM(woim.meta_value)
            FROM {$wpdb->prefix}woocommerce_order_items woi
            JOIN {$wpdb->prefix}woocommerce_order_itemmeta woim ON woi.order_item_id = woim.order_item_id
            JOIN {$wpdb->posts} p ON woi.order_id = p.ID
            WHERE p.post_type = 'shop_order'
            AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-shipped')
            AND YEARWEEK(p.post_date, 1) = YEARWEEK(CURDATE(), 1)
            AND woim.meta_key = '_qty'
        ");
        
        // Ventas del mes
        $data['ventas_mes'] = $wpdb->get_var("
            SELECT SUM(woim.meta_value)
            FROM {$wpdb->prefix}woocommerce_order_items woi
            JOIN {$wpdb->prefix}woocommerce_order_itemmeta woim ON woi.order_item_id = woim.order_item_id
            JOIN {$wpdb->posts} p ON woi.order_id = p.ID
            WHERE p.post_type = 'shop_order'
            AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-shipped')
            AND MONTH(p.post_date) = MONTH(CURDATE())
            AND YEAR(p.post_date) = YEAR(CURDATE())
            AND woim.meta_key = '_qty'
        ");
        
        // Top 30 productos del mes
        $top_productos = $wpdb->get_results("
            SELECT 
                woim.meta_value as product_id,
                p.post_title as product_name,
                SUM(woim2.meta_value) as total_vendido
            FROM {$wpdb->prefix}woocommerce_order_items woi
            JOIN {$wpdb->prefix}woocommerce_order_itemmeta woim ON woi.order_item_id = woim.order_item_id
            JOIN {$wpdb->prefix}woocommerce_order_itemmeta woim2 ON woi.order_item_id = woim2.order_item_id
            JOIN {$wpdb->posts} p ON woim.meta_value = p.ID
            JOIN {$wpdb->posts} orders ON woi.order_id = orders.ID
            WHERE orders.post_type = 'shop_order'
            AND orders.post_status IN ('wc-completed', 'wc-processing', 'wc-shipped')
            AND MONTH(orders.post_date) = MONTH(CURDATE())
            AND YEAR(orders.post_date) = YEAR(CURDATE())
            AND woim.meta_key = '_product_id'
            AND woim2.meta_key = '_qty'
            GROUP BY woim.meta_value
            ORDER BY total_vendido DESC
            LIMIT 30
        ");
        
        $data['top_productos'] = $top_productos;
        
        // Productos sin ventas
        $total_productos = $wpdb->get_var("
            SELECT COUNT(DISTINCT ID) 
            FROM {$wpdb->posts} 
            WHERE post_type IN ('product', 'product_variation') 
            AND post_status = 'publish'
        ");
        
        $productos_con_ventas = $wpdb->get_var("
            SELECT COUNT(DISTINCT woim.meta_value)
            FROM {$wpdb->prefix}woocommerce_order_items woi
            JOIN {$wpdb->prefix}woocommerce_order_itemmeta woim ON woi.order_item_id = woim.order_item_id
            JOIN {$wpdb->posts} orders ON woi.order_id = orders.ID
            WHERE orders.post_type = 'shop_order'
            AND orders.post_status IN ('wc-completed', 'wc-processing', 'wc-shipped')
            AND orders.post_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            AND woim.meta_key = '_product_id'
        ");
        
        $data['sin_ventas'] = $total_productos - $productos_con_ventas;
        
        // Datos para el grfico (últimos 30 días)
        $ventas_diarias = $wpdb->get_results("
            SELECT 
                DATE(orders.post_date) as fecha,
                SUM(woim.meta_value) as total
            FROM {$wpdb->prefix}woocommerce_order_items woi
            JOIN {$wpdb->prefix}woocommerce_order_itemmeta woim ON woi.order_item_id = woim.order_item_id
            JOIN {$wpdb->posts} orders ON woi.order_id = orders.ID
            WHERE orders.post_type = 'shop_order'
            AND orders.post_status IN ('wc-completed', 'wc-processing', 'wc-shipped')
            AND orders.post_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            AND woim.meta_key = '_qty'
            GROUP BY DATE(orders.post_date)
            ORDER BY fecha ASC
        ");
        
        // Preparar datos para el gráfico
        for ($i = 29; $i >= 0; $i--) {
            $fecha = date('Y-m-d', strtotime("-$i days"));
            $data['chart_labels'][] = date('d/m', strtotime($fecha));
            
            $ventas = 0;
            foreach ($ventas_diarias as $venta) {
                if ($venta->fecha == $fecha) {
                    $ventas = intval($venta->total);
                    break;
                }
            }
            $data['chart_data'][] = $ventas;
        }
        
        return $data;
    }
    
    // Renderizar widget de top productos
    private function render_top_products_widget($top_productos) {
        if (empty($top_productos)) {
            echo '<p>No hay datos de ventas disponibles.</p>';
            return;
        }
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 50px;">#</th>
                    <th>Producto</th>
                    <th style="width: 150px;">Unidades Vendidas</th>
                    <th style="width: 200px;">Gráfico</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $max_ventas = $top_productos[0]->total_vendido;
                $position = 1;
                foreach ($top_productos as $producto): 
                    $porcentaje = $max_ventas > 0 ? ($producto->total_vendido / $max_ventas * 100) : 0;
                ?>
                    <tr>
                        <td><strong><?php echo $position++; ?></strong></td>
                        <td><?php echo esc_html($producto->product_name); ?></td>
                        <td style="text-align: center;">
                            <strong><?php echo number_format($producto->total_vendido); ?></strong>
                        </td>
                        <td>
                            <div style="background: #e0e0e0; height: 20px; border-radius: 10px; overflow: hidden;">
                                <div style="background: #0073aa; height: 100%; width: <?php echo $porcentaje; ?>%"></div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
    
}