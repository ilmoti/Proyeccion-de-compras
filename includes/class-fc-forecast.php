<?php
/**
 * Clase para manejar la proyección de compras
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<?php

global $wpdb;

class FC_Forecast {
    
    private $filters;
    
    public function __construct() {
        $this->get_filters();
        // AGREGAR ESTA LNEA:
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
    }
    
    public function enqueue_scripts($hook) {
        error_log('Hook actual: ' . $hook);
        if (strpos($hook, 'fc-projection') !== false || strpos($hook, 'forecast') !== false) {
            // Cargar Chart.js
            wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '4.4.0', true);
        }
    }
    
    // Obtener filtros de la URL
    private function get_filters() {
        $this->filters = array(
            'categorias' => isset($_GET['categorias']) ? array_map('intval', (array)$_GET['categorias']) : array(),
            'buscar' => isset($_GET['buscar']) ? sanitize_text_field($_GET['buscar']) : '',
            'periodo' => isset($_GET['periodo']) ? intval($_GET['periodo']) : 30,
            'meses_proyeccion' => isset($_GET['meses_proyeccion']) ? intval($_GET['meses_proyeccion']) : 3,
            'fecha_desde' => isset($_GET['fecha_desde']) ? sanitize_text_field($_GET['fecha_desde']) : '',
            'fecha_hasta' => isset($_GET['fecha_hasta']) ? sanitize_text_field($_GET['fecha_hasta']) : '',
            'tipo_promedio' => isset($_GET['tipo_promedio']) ? sanitize_text_field($_GET['tipo_promedio']) : 'diario',
            'vista' => isset($_GET['vista']) ? sanitize_text_field($_GET['vista']) : 'tabla',
            'paged' => isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1,
            'periodo' => isset($_GET['periodo']) ? intval($_GET['periodo']) : 30,
            'stock_status' => isset($_GET['stock_status']) ? sanitize_text_field($_GET['stock_status']) : '',
            'ventas_status' => isset($_GET['ventas_status']) ? sanitize_text_field($_GET['ventas_status']) : '',
            'solo_sin_stock' => isset($_GET['solo_sin_stock']) ? 1 : 0,
            'solo_stock_critico' => isset($_GET['solo_stock_critico']) ? 1 : 0,
            'solo_stock_bajo' => isset($_GET['solo_stock_bajo']) ? 1 : 0,
        );
    }
    
    // Renderizar la pgina principal
    public function render_page() {
        ?>
        <div class="wrap">
            <h1>Proyección de Ventas y Compras</h1>
            
            <?php $this->render_filters(); ?>
            
            <div style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccc;">
                <?php 
                if ($this->filters['vista'] == 'graficos') {
                    require_once FC_PLUGIN_PATH . 'includes/class-fc-charts.php';
                    $charts = new FC_Charts($this->filters);
                    $charts->render();
                } else {
                    $this->render_forecast_table();
                }
                ?>
            </div>
            
            <?php $this->render_actions(); ?>
        </div>
        <?php
    }
    
    // Renderizar filtros
    private function render_filters() {
        include FC_PLUGIN_PATH . 'templates/filters-form.php';
    }
    
    // Renderizar tabla de proyeccin
    private function render_forecast_table() {
        include FC_PLUGIN_PATH . 'templates/forecast-table.php';
    }
    
    // Renderizar acciones
    private function render_actions() {
        ?>
        <div class="fc-actions">
            <form method="post" style="display: inline;">
                <input type="hidden" name="action" value="fc_export_forecast">
                <?php foreach ($this->filters as $key => $value): ?>
                    <?php if (is_array($value)): ?>
                        <?php foreach ($value as $val): ?>
                            <input type="hidden" name="<?php echo $key; ?>[]" value="<?php echo esc_attr($val); ?>">
                        <?php endforeach; ?>
                    <?php else: ?>
                        <input type="hidden" name="<?php echo $key; ?>" value="<?php echo esc_attr($value); ?>">
                    <?php endif; ?>
                <?php endforeach; ?>
                <button type="submit" class="button button-primary">Exportar Pedido a Excel</button>
            </form>
            
            <a href="?page=forecast-compras&vista=graficos" class="button">Ver Gráficos de Tendencias</a>
        </div>
        <?php
    }
}