<?php
/**
 * Clase para manejar gráficos y estadísticas
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

class FC_Charts {
    
    private $filters;
    
    public function __construct($filters) {
        $this->filters = $filters;
    }
    
    // Renderizar gráficos
    public function render() {
        ?>
        <div class="fc-charts-container">
            <h2>Análisis de Tendencias de Ventas</h2>
            
            <div class="fc-chart-controls">
                <a href="?page=forecast-compras" class="button">
                    <span class="dashicons dashicons-list-view"></span> Volver a la tabla
                </a>
                
                <button type="button" class="button" onclick="window.print();">
                    <span class="dashicons dashicons-printer"></span> Imprimir
                </button>
            </div>
            
            <div class="fc-charts-grid">
                <!-- Ventas por mes -->
                <div class="fc-chart-box">
                    <h3>Ventas por Mes (Últimos 6 meses)</h3>
                    <canvas id="chartVentasMes" width="400" height="200"></canvas>
                </div>
                
                <!-- Top productos -->
                <div class="fc-chart-box">
                    <h3>Top 10 Productos Más Vendidos</h3>
                    <canvas id="chartTopProductos" width="400" height="200"></canvas>
                </div>
                
                <!-- Ventas por categoría -->
                <div class="fc-chart-box">
                    <h3>Ventas por Categoría</h3>
                    <canvas id="chartCategorias" width="400" height="200"></canvas>
                </div>
                
                <!-- Tendencia diaria -->
                <div class="fc-chart-box">
                    <h3>Tendencia de Ventas (Últimos 30 días)</h3>
                    <canvas id="chartTendencia" width="400" height="200"></canvas>
                </div>
            </div>
            
            <!-- Estadísticas adicionales -->
            <div class="fc-stats-grid">
                <?php $this->render_statistics(); ?>
            </div>
            
            <!-- Scripts para los gráficos -->
            <?php $this->render_chart_scripts(); ?>
        </div>
        
        <style>
            .fc-charts-container { padding: 20px 0; }
            .fc-chart-controls { margin-bottom: 20px; }
            .fc-charts-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
                gap: 20px;
                margin-bottom: 30px;
            }
            .fc-chart-box {
                background: #f9f9f9;
                border: 1px solid #ddd;
                padding: 20px;
                border-radius: 5px;
            }
            .fc-chart-box h3 {
                margin-top: 0;
                margin-bottom: 15px;
                color: #333;
            }
            .fc-stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 15px;
            }
            .fc-stat-box {
                background: #fff;
                border: 1px solid #ddd;
                padding: 15px;
                text-align: center;
                border-radius: 5px;
            }
            .fc-stat-box .value {
                font-size: 24px;
                font-weight: bold;
                color: #2271b1;
            }
            .fc-stat-box .label {
                color: #666;
                margin-top: 5px;
            }
            @media print {
                .fc-chart-controls { display: none; }
            }
        </style>
        <?php
    }
    
    // Renderizar estadísticas
    private function render_statistics() {
        global $wpdb;
        
        // Calcular estadísticas
        $stats = $this->calculate_statistics();
        
        ?>
        <div class="fc-stat-box">
            <div class="value"><?php echo number_format($stats['total_products']); ?></div>
            <div class="label">Productos Totales</div>
        </div>
        
        <div class="fc-stat-box">
            <div class="value"><?php echo number_format($stats['low_stock']); ?></div>
            <div class="label">Productos con Stock Bajo</div>
        </div>
        
        <div class="fc-stat-box">
            <div class="value"><?php echo number_format($stats['pending_orders']); ?></div>
            <div class="label">Órdenes Pendientes</div>
        </div>
        
        <div class="fc-stat-box">
            <div class="value">$<?php echo number_format($stats['pending_value'], 2); ?></div>
            <div class="label">Valor en Camino</div>
        </div>
        
        <div class="fc-stat-box">
            <div class="value"><?php echo number_format($stats['avg_daily_sales'], 1); ?></div>
            <div class="label">Promedio Ventas Diarias</div>
        </div>
        
        <div class="fc-stat-box">
            <div class="value"><?php echo $stats['growth_rate']; ?>%</div>
            <div class="label">Crecimiento Mensual</div>
        </div>
        <?php
    }
    
    // Calcular estadísticas
    private function calculate_statistics() {
        global $wpdb;
        
        $stats = array();
        
        // Total de productos
        $stats['total_products'] = wp_count_posts('product')->publish;
        
        // Productos con stock bajo (menos de 1 mes)
        $low_stock_query = "
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'product'
            AND p.post_status = 'publish'
            AND pm.meta_key = '_stock'
            AND CAST(pm.meta_value AS SIGNED) < 50
        ";
        $stats['low_stock'] = $wpdb->get_var($low_stock_query);
        
        // Órdenes pendientes
        $table_orders = $wpdb->prefix . 'fc_orders_history';
        $stats['pending_orders'] = $wpdb->get_var("
            SELECT COUNT(DISTINCT arrival_date) 
            FROM $table_orders 
            WHERE status = 'pending'
        ");
        
        // Valor en camino
        $stats['pending_value'] = $wpdb->get_var("
            SELECT SUM(quantity * purchase_price) 
            FROM $table_orders 
            WHERE status = 'pending'
        ") ?: 0;
        
        // Promedio de ventas diarias (últimos 30 días)
        $thirty_days_ago = date('Y-m-d', strtotime('-30 days'));
        $daily_sales = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT p.ID) / 30
            FROM {$wpdb->posts} p
            WHERE p.post_type = 'shop_order'
            AND o.post_status IN ('wc-completed', 'wc-processing', 'wc-shipped')
            AND p.post_date >= %s
        ", $thirty_days_ago));
        $stats['avg_daily_sales'] = $daily_sales ?: 0;
        
        // Tasa de crecimiento
        $current_month_sales = $this->get_month_sales(0);
        $last_month_sales = $this->get_month_sales(1);
        
        if ($last_month_sales > 0) {
            $stats['growth_rate'] = round((($current_month_sales - $last_month_sales) / $last_month_sales) * 100, 1);
        } else {
            $stats['growth_rate'] = 0;
        }
        
        return $stats;
    }
    
    // Obtener ventas de un mes
    private function get_month_sales($months_ago) {
        global $wpdb;
        
        $start_date = date('Y-m-01', strtotime("-{$months_ago} months"));
        $end_date = date('Y-m-t', strtotime("-{$months_ago} months"));
        
        return $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*)
            FROM {$wpdb->posts}
            WHERE post_type = 'shop_order'
            AND post_status IN ('wc-completed', 'wc-processing')
            AND post_date >= %s
            AND post_date <= %s
        ", $start_date, $end_date . ' 23:59:59'));
    }
    
    // Renderizar scripts de gráficos
    private function render_chart_scripts() {
        // Obtener datos para los gráficos
        $chart_data = $this->get_chart_data();
        ?>
        <script>
        jQuery(document).ready(function($) {
            // Configuración global de Chart.js
            Chart.defaults.font.family = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
            
            // Gráfico de ventas por mes
            new Chart(document.getElementById('chartVentasMes'), {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($chart_data['months']); ?>,
                    datasets: [{
                        label: 'Órdenes',
                        data: <?php echo json_encode($chart_data['monthly_sales']); ?>,
                        borderColor: '#2271b1',
                        backgroundColor: 'rgba(34, 113, 177, 0.1)',
                        tension: 0.3
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        y: { beginAtZero: true }
                    }
                }
            });
            
            // Top productos
            new Chart(document.getElementById('chartTopProductos'), {
                type: 'horizontalBar',
                data: {
                    labels: <?php echo json_encode($chart_data['top_products_names']); ?>,
                    datasets: [{
                        label: 'Unidades',
                        data: <?php echo json_encode($chart_data['top_products_sales']); ?>,
                        backgroundColor: '#00a0d2'
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        x: { beginAtZero: true }
                    }
                }
            });
            
            // Más gráficos...
        });
        </script>
        <?php
    }
    
    // Obtener datos para gráficos
    private function get_chart_data() {
        global $wpdb;
        
        $data = array();
        
        // Ventas por mes
        $data['months'] = array();
        $data['monthly_sales'] = array();
        
        for ($i = 5; $i >= 0; $i--) {
            $month_start = date('Y-m-01', strtotime("-$i months"));
            $month_end = date('Y-m-t', strtotime("-$i months"));
            
            $sales = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*)
                FROM {$wpdb->posts}
                WHERE post_type = 'shop_order'
                AND post_status IN ('wc-completed', 'wc-processing')
                AND post_date >= %s
                AND post_date <= %s
            ", $month_start, $month_end . ' 23:59:59'));
            
            $data['months'][] = date('M Y', strtotime($month_start));
            $data['monthly_sales'][] = $sales ?: 0;
        }
        
        // Top productos
        $top_products = $wpdb->get_results("
            SELECT 
                pm.meta_value as product_id,
                SUM(oim.meta_value) as total
            FROM {$wpdb->prefix}woocommerce_order_items oi
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta pm 
                ON oi.order_item_id = pm.order_item_id AND pm.meta_key = '_product_id'
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim 
                ON oi.order_item_id = oim.order_item_id AND oim.meta_key = '_qty'
            INNER JOIN {$wpdb->posts} p ON oi.order_id = p.ID
            WHERE p.post_type = 'shop_order'
            AND p.post_status IN ('wc-completed', 'wc-processing')
            AND p.post_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY pm.meta_value
            ORDER BY total DESC
            LIMIT 10
        ");
        
        $data['top_products_names'] = array();
        $data['top_products_sales'] = array();
        
        foreach ($top_products as $prod) {
            $product = wc_get_product($prod->product_id);
            if ($product) {
                $name = $product->get_name();
                if (strlen($name) > 30) {
                    $name = substr($name, 0, 30) . '...';
                }
                $data['top_products_names'][] = $name;
                $data['top_products_sales'][] = intval($prod->total);
            }
        }
        
        return $data;
    }
}