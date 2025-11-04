<?php
/**
 * Plugin Name: Forecast de Compras WooCommerce
 * Description: Proyección de compras y ventas con gestión de órdenes en camino
 * Version: 5.5
 * Author: WiFix Development
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Definir constantes del plugin
define('FC_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('FC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('FC_PLUGIN_VERSION', '1.0');

// Función para crear/actualizar las tablas
function fc_create_tables() {
    global $wpdb;
    
    // Verificar si la tabla existe y si tiene la columna order_name
    $table_orders = $wpdb->prefix . 'fc_orders_history';
    $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_orders LIKE 'order_name'");
    
    if (empty($column_exists)) {
        // Agregar la columna order_name si no existe
        $wpdb->query("ALTER TABLE $table_orders ADD COLUMN order_name VARCHAR(255) AFTER id");
        
        // Actualizar registros antiguos con un nombre por defecto
        $wpdb->query("UPDATE $table_orders SET order_name = CONCAT('Importacin-', DATE(created_at)) WHERE order_name IS NULL OR order_name = ''");
    }
    
    // NUEVO: Verificar si la columna existe y agregarla
    $table_name = $wpdb->prefix . 'fc_weight_alerts';
    $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table_name} LIKE 'excluded_tags'");
    
    if (empty($column_exists)) {
        $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN excluded_tags TEXT AFTER tags");
    }
    
    // Continuar con la creación normal de tablas
    require_once FC_PLUGIN_PATH . 'includes/class-fc-database.php';
    FC_Database::create_tables();
}

// Activacin del plugin
register_activation_hook(__FILE__, 'fc_create_tables');

// Inicializar el plugin
add_action('init', 'fc_init_plugin');

function fc_init_plugin() {
    // Solo cargar en el admin
    if (!is_admin()) {
        return;
    }
    
    // Verificar si WooCommerce está activo
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
            ?>
            <div class="notice notice-error">
                <p>Forecast de Compras requiere WooCommerce activo para funcionar.</p>
            </div>
            <?php
        });
        return;
    }
    
    // Incluir archivos necesarios
    require_once FC_PLUGIN_PATH . 'includes/fc-functions.php';
    
    // Programar actualización diaria
    if (!wp_next_scheduled('fc_daily_metrics_update')) {
        wp_schedule_event(time(), 'daily', 'fc_daily_metrics_update');
    }
    add_action('fc_daily_metrics_update', 'fc_update_all_metrics');
    
    // Cargar monitor de stock
    require_once FC_PLUGIN_PATH . 'includes/class-fc-stock-monitor.php';
    new FC_Stock_Monitor();
    
    // NUEVO: Cargar monitor de alertas de peso
    require_once FC_PLUGIN_PATH . 'includes/class-fc-weight-monitor.php';
    new FC_Weight_Monitor();
    
    // Cargar monitor de stock muerto
    require_once FC_PLUGIN_PATH . 'includes/class-fc-dead-stock-notifications.php';
    new FC_Dead_Stock_Notifications();
    
    // INICIO MODIFICACIÓN - Cargar gestor de productos rebajados
    require_once FC_PLUGIN_PATH . 'includes/class-fc-rebajados-manager.php';
    // FIN MODIFICACIÓN
    
    // Manejar página de configuración de emails
    if (isset($_GET['page']) && $_GET['page'] == 'fc-dead-stock' && isset($_GET['action']) && $_GET['action'] == 'email_config') {
        FC_Dead_Stock_Notifications::render_email_config();
        exit;
    }
    
    // NUEVO: Cargar exportador de alertas
    require_once FC_PLUGIN_PATH . 'includes/class-fc-weight-export.php';
    new FC_Weight_Export();
    
    // NUEVO: Cargar import handler para procesar acciones POST
    if (is_admin() && (
        (isset($_POST['action']) && in_array($_POST['action'], ['fc_mark_received', 'fc_delete_order'])) ||
        (isset($_GET['page']) && in_array($_GET['page'], ['fc-import-orders', 'fc-orders-history']))
    )) {
        require_once FC_PLUGIN_PATH . 'includes/class-fc-import-temp.php';
        new FC_Import();
    }
    
    // Cargar clases solo cuando se necesiten
    add_action('admin_menu', 'fc_setup_admin_menu');
    
    // Cargar handlers AJAX
    if (defined('DOING_AJAX') && DOING_AJAX) {
        require_once FC_PLUGIN_PATH . 'includes/class-fc-ajax-handler.php';
        new FC_Ajax_Handler();
    }
    
    // Cargar export handler
    if (isset($_POST['action']) && $_POST['action'] == 'fc_export_forecast') {
        require_once FC_PLUGIN_PATH . 'includes/class-fc-export.php';
        new FC_Export();
    }
    // NUEVO: Manejar descarga de Excel generado por AJAX
    if (isset($_POST['action']) && $_POST['action'] == 'fc_download_generated_excel') {
        require_once FC_PLUGIN_PATH . 'includes/class-fc-weight-export.php';
        $export = new FC_Weight_Export();
        $export->download_from_cache();
    }
}

// Configurar menú admin
function fc_setup_admin_menu() {
        // Menú principal - Ahora es el Dashboard
    add_menu_page(
        'Forecast Dashboard',
        'Forecast Dashboard',
        'manage_options',
        'forecast-compras',
        'fc_render_dashboard_page',
        'dashicons-chart-line',
        30
    );
    
    // Submenú para proyección (movido a submen)
    add_submenu_page(
        'forecast-compras',
        'Proyección Detallada',
        'Proyección Detallada',
        'manage_options',
        'fc-projection',
        'fc_render_main_page'
    );
    
    // Submen para importar
    add_submenu_page(
        'forecast-compras',
        'Importar rdenes',
        'Importar rdenes',
        'manage_options',
        'fc-import-orders',
        'fc_render_import_page'
    );
    
    // Submen para histórico
    add_submenu_page(
        'forecast-compras',
        'Histórico de Órdenes',
        'Histórico',
        'manage_options',
        'fc-orders-history',
        'fc_render_history_page'
    );
    
    // Submenú para análisis inicial
    add_submenu_page(
        'forecast-compras',
        'Anlisis de Stock',
        'Anlisis Inicial',
        'manage_options',
        'fc-stock-analysis',
        'fc_render_analysis_page'
    );
    
    // NUEVO: Submenú para alertas de peso
    add_submenu_page(
        'forecast-compras',
        'Alertas de Peso',
        'Alertas Pedidos',
        'manage_options',
        'fc-weight-alerts',
        'fc_render_weight_alerts_page'
    );
    
    // ⭐ AGREGAR ESTO - Submenú para análisis de precios
    add_submenu_page(
        'forecast-compras',
        'Análisis Pre-Compra',
        'Análisis Pre-Compra',
        'manage_options',
        'fc-price-analysis',
        'fc_render_price_analysis_page'
    );
    
    // NUEVO: Configuracin de múltiplos
    add_submenu_page(
        'forecast-compras',
        'Configuración',
        'Configuracin',
        'manage_options',
        'fc-settings',
        'fc_render_settings_page'
    );
    
    // Submenú para análisis de stock muerto
    add_submenu_page(
        'forecast-compras',
        'Stock Sin Rotación',
        'Stock Sin Rotación',
        'manage_options',
        'fc-dead-stock',
        'fc_render_dead_stock_page'
    );
    
    // INICIO MODIFICACIÓN - Submenú para productos rebajados
    add_submenu_page(
        'forecast-compras',
        'Productos Rebajados',
        'Productos Rebajados',
        'manage_options',
        'fc-rebajados',
        'fc_render_rebajados_page'
    );
    // FIN MODIFICACIÓN
}

// NUEVO: Función para renderizar el dashboard
function fc_render_dashboard_page() {
    if (file_exists(FC_PLUGIN_PATH . 'includes/class-fc-dashboard.php')) {
        require_once FC_PLUGIN_PATH . 'includes/class-fc-dashboard.php';
        $dashboard = new FC_Dashboard();
        $dashboard->render_page();
    } else {
        echo '<div class="wrap"><h1>Dashboard</h1><p>Archivo class-fc-dashboard.php no encontrado.</p></div>';
    }
}

// Funciones para renderizar las páginas
function fc_render_main_page() {
    if (file_exists(FC_PLUGIN_PATH . 'includes/class-fc-forecast.php')) {
        require_once FC_PLUGIN_PATH . 'includes/class-fc-forecast.php';
        $forecast = new FC_Forecast();
        $forecast->render_page();
    } else {
        echo '<div class="wrap"><h1>Proyección de Ventas</h1><p>Archivo class-fc-forecast.php no encontrado.</p></div>';
    }
}

function fc_render_import_page() {
    if (file_exists(FC_PLUGIN_PATH . 'includes/class-fc-import-temp.php')) {
        require_once FC_PLUGIN_PATH . 'includes/class-fc-import-temp.php';
        $import = new FC_Import();
        $import->render_page();
    } else {
        echo '<div class="wrap"><h1>Importar</h1><p>Archivo no encontrado.</p></div>';
    }
}

function fc_render_history_page() {
    if (file_exists(FC_PLUGIN_PATH . 'includes/class-fc-import-temp.php')) {
        require_once FC_PLUGIN_PATH . 'includes/class-fc-import-temp.php';
        $import = new FC_Import();
        $import->render_history_page();
    } else {
        echo '<div class="wrap"><h1>Histrico</h1><p>Archivo no encontrado.</p></div>';
    }
}

// NUEVO: Funcin para renderizar pgina de alertas de peso
function fc_render_weight_alerts_page() {
    if (file_exists(FC_PLUGIN_PATH . 'includes/class-fc-weight-alerts.php')) {
        require_once FC_PLUGIN_PATH . 'includes/class-fc-weight-alerts.php';
        $weight_alerts = new FC_Weight_Alerts();
        $weight_alerts->render_page();
    } else {
        echo '<div class="wrap"><h1>Alertas de Peso</h1><p>Archivo class-fc-weight-alerts.php no encontrado.</p></div>';
    }
}

// Pgina de anlisis
function fc_render_analysis_page() {
    global $wpdb;
    $table_stockouts = $wpdb->prefix . 'fc_stockout_periods';
    
    // Procesar cierre manual
    if (isset($_POST['close_period'])) {
        $product_id = intval($_POST['product_id']);
        $wpdb->query($wpdb->prepare(
            "UPDATE $table_stockouts 
            SET end_date = NOW(), days_out = DATEDIFF(NOW(), start_date)
            WHERE product_id = %d AND end_date IS NULL",
            $product_id
        ));
        echo '<div class="notice notice-success"><p>Período cerrado para producto ID: ' . $product_id . '</p></div>';
    }
    
    // Procesar análisis
    if (isset($_POST['run_analysis'])) {
        require_once FC_PLUGIN_PATH . 'includes/class-fc-stock-monitor.php';
        $analyzed = FC_Stock_Monitor::analyze_historical_stockouts();
        echo '<div class="notice notice-success"><p>Análisis completado. ' . $analyzed . ' productos sin stock detectados.</p></div>';
    }
    
    // Obtener períodos abiertos
    $open_periods = $wpdb->get_results("
        SELECT s.*, p.post_title 
        FROM $table_stockouts s
        LEFT JOIN {$wpdb->posts} p ON s.product_id = p.ID
        WHERE s.end_date IS NULL
        ORDER BY s.start_date DESC
    ");
    ?>
    <div class="wrap">
        <h1>Análisis y Control de Stock</h1>
        
        <h2>Perodos Sin Stock Abiertos</h2>
        <?php if ($open_periods): ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Producto</th>
                        <th>SKU</th>
                        <th>Sin stock desde</th>
                        <th>Das sin stock</th>
                        <th>Stock actual</th>
                        <th>Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($open_periods as $period): 
                        $current_stock = get_post_meta($period->product_id, '_stock', true);
                        $dias = round((time() - strtotime($period->start_date)) / 86400);
                    ?>
                        <tr>
                            <td><?php echo esc_html($period->post_title); ?></td>
                            <td><?php echo esc_html($period->sku); ?></td>
                            <td><?php echo date('d/m/Y', strtotime($period->start_date)); ?></td>
                            <td><?php echo $dias; ?> das</td>
                            <td>
                                <?php if ($current_stock > 0): ?>
                                    <span style="color: green;"><?php echo $current_stock; ?></span>
                                <?php else: ?>
                                    <span style="color: red;">0</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($current_stock > 0): ?>
                                    <form method="post" style="display: inline;">
                                        <input type="hidden" name="product_id" value="<?php echo $period->product_id; ?>">
                                        <button type="submit" name="close_period" class="button button-small">
                                            Cerrar Perodo
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span style="color: #666;">Sin stock aún</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>No hay períodos sin stock abiertos.</p>
        <?php endif; ?>
        
        <hr>
        
        <h2>Ejecutar Anlisis Inicial</h2>
        <p>Esta herramienta analizar todos tus productos y detectará cuáles están actualmente sin stock.</p>
        
        <form method="post">
            <button type="submit" name="run_analysis" class="button button-primary">
                Ejecutar Anlisis Inicial
            </button>
        </form>
    </div>
    <?php
}
// NUEVO: Página de configuración
function fc_render_settings_page() {
    // Guardar configuracin
    if (isset($_POST['save_settings'])) {
        $multiples = array();
        if (isset($_POST['multiples']) && is_array($_POST['multiples'])) {
            foreach ($_POST['multiples'] as $cat_id => $data) {
                $multiples[$cat_id] = array(
                    'multiple' => intval($data['multiple']),
                    'min_exact' => intval($data['min_exact'])
                );
            }
        }
        update_option('fc_category_multiples', $multiples);
        
        // Guardar calidades
        if (isset($_POST['qualities'])) {
            $qualities = array_map('sanitize_text_field', $_POST['qualities']);
            $qualities = array_filter($qualities); // Eliminar vacos
            update_option('fc_quality_options', $qualities);
        }
        // Guardar límites de stock
        update_option('fc_stock_critico_dias', intval($_POST['stock_critico_dias']));
        update_option('fc_stock_bajo_dias', intval($_POST['stock_bajo_dias']));
        
        echo '<div class="notice notice-success"><p>Configuración guardada.</p></div>';
    }
    
    $saved_multiples = get_option('fc_category_multiples', array());
    $saved_qualities = get_option('fc_quality_options', array('A+', 'A', 'B', 'C'));
    ?>
    <div class="wrap">
        <h1>Configuración del Sistema</h1>
        
        <form method="post">
            <style>
                .cat-child { padding-left: 30px !important; }
                .cat-parent { background: #f0f0f1; font-weight: bold; }
                .cat-toggle { 
                    cursor: pointer; 
                    user-select: none;
                    transition: transform 0.2s;
                    display: inline-block;
                    width: 20px;
                }
                .cat-toggle.collapsed { transform: rotate(-90deg); }
                .cat-children { transition: all 0.3s ease; }
                .cat-children.hidden { display: none; }
                .cat-search { 
                    margin-bottom: 20px; 
                    padding: 8px 12px;
                    width: 300px;
                    border: 1px solid #ddd;
                }
            </style>
            
            <script>
            jQuery(document).ready(function($) {
                // Funcin para toggle categorías
                $('.cat-toggle').click(function() {
                    var parentId = $(this).data('parent');
                    $(this).toggleClass('collapsed');
                    $('.child-of-' + parentId).toggleClass('hidden');
                });
                
                // Búsqueda en tiempo real
                $('#cat-search').on('input', function() {
                    var searchTerm = $(this).val().toLowerCase();
                    
                    if (searchTerm === '') {
                        // Mostrar todo
                        $('tbody tr').show();
                        $('.cat-children').addClass('hidden');
                        $('.cat-toggle').addClass('collapsed');
                    } else {
                        // Ocultar todo primero
                        $('tbody tr').hide();
                        
                        // Mostrar coincidencias
                        $('tbody tr').each(function() {
                            var catName = $(this).find('td:first').text().toLowerCase();
                            if (catName.indexOf(searchTerm) > -1) {
                                $(this).show();
                                // Si es hijo, mostrar el padre tambin
                                if ($(this).hasClass('cat-children')) {
                                    var parentClass = $(this).attr('class').match(/child-of-(\d+)/);
                                    if (parentClass) {
                                        $('.parent-' + parentClass[1]).show();
                                    }
                                }
                            }
                        });
                    }
                });
                
                // Colapsar todas al inicio
                $('.cat-children').addClass('hidden');
                $('.cat-toggle').addClass('collapsed');
            });
            </script>
            
            <h2>Múltiplos por Categora</h2>
            <p>Configure los mltiplos de pedido para cada categora. Dejar en 1 para no aplicar múltiplos.</p>
            
            <input type="text" id="cat-search" class="cat-search" placeholder=" Buscar categoría...">
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Categoría</th>
                        <th>Mltiplo</th>
                        <th>Cantidad mnima para exacto</th>
                        <th>Ejemplo</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    // Obtener categoras padre
                    $parent_categories = get_terms(array(
                        'taxonomy' => 'product_cat',
                        'hide_empty' => false,
                        'parent' => 0,
                        'orderby' => 'name',
                        'order' => 'ASC'
                    ));
                    
                    foreach ($parent_categories as $parent): 
                        $cat_config = isset($saved_multiples[$parent->term_id]) 
                            ? $saved_multiples[$parent->term_id] 
                            : array('multiple' => 1, 'min_exact' => 0);
                    ?>
                        <tr class="cat-parent parent-<?php echo $parent->term_id; ?>">
                            <td>
                                <span class="cat-toggle" data-parent="<?php echo $parent->term_id; ?>">▼</span>
                                <strong>📁 <?php echo esc_html($parent->name); ?></strong>
                            </td>
                            <td>
                                <input type="number" 
                                       name="multiples[<?php echo $parent->term_id; ?>][multiple]" 
                                       value="<?php echo $cat_config['multiple']; ?>" 
                                       min="1" style="width: 80px;">
                            </td>
                            <td>
                                <input type="number" 
                                       name="multiples[<?php echo $parent->term_id; ?>][min_exact]" 
                                       value="<?php echo $cat_config['min_exact']; ?>" 
                                       min="0" style="width: 80px;">
                                <p class="description">Si es menor o igual, se pide exacto</p>
                            </td>
                            <td>
                                <?php 
                                if ($cat_config['multiple'] > 1) {
                                    echo "Múltiplo de {$cat_config['multiple']}";
                                    if ($cat_config['min_exact'] > 0) {
                                        echo " (exacto si  {$cat_config['min_exact']})";
                                    }
                                }
                                ?>
                            </td>
                        </tr>
                        
                        <?php 
                        // Obtener categoras hijo
                        $child_categories = get_terms(array(
                            'taxonomy' => 'product_cat',
                            'hide_empty' => false,
                            'parent' => $parent->term_id,
                            'orderby' => 'name',
                            'order' => 'ASC'
                        ));
                        
                        foreach ($child_categories as $child): 
                            $child_config = isset($saved_multiples[$child->term_id]) 
                                ? $saved_multiples[$child->term_id] 
                                : array('multiple' => 1, 'min_exact' => 0);
                        ?>
                            <tr class="cat-children child-of-<?php echo $parent->term_id; ?>">
                                <td class="cat-child"> <?php echo esc_html($child->name); ?></td>
                                <td>
                                    <input type="number" 
                                           name="multiples[<?php echo $child->term_id; ?>][multiple]" 
                                           value="<?php echo $child_config['multiple']; ?>" 
                                           min="1" style="width: 80px;">
                                </td>
                                <td>
                                    <input type="number" 
                                           name="multiples[<?php echo $child->term_id; ?>][min_exact]" 
                                           value="<?php echo $child_config['min_exact']; ?>" 
                                           min="0" style="width: 80px;">
                                </td>
                                <td>
                                    <?php 
                                    if ($child_config['multiple'] > 1) {
                                        echo "Múltiplo de {$child_config['multiple']}";
                                        if ($child_config['min_exact'] > 0) {
                                            echo " (exacto si  {$child_config['min_exact']})";
                                        }
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <h2 style="margin-top: 40px;">Lmites de Stock</h2>
                <p>Configure los días de stock para determinar estados crítico y bajo:</p>
                
                <table class="form-table">
                    <tr>
                        <th>Stock Crítico (días)</th>
                        <td>
                            <input type="number" name="stock_critico_dias" 
                                   value="<?php echo get_option('fc_stock_critico_dias', 30); ?>" 
                                   min="1" max="365" style="width: 80px;"> días
                            <p class="description">Productos con stock para menos de estos das serán marcados como críticos</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Stock Bajo (das)</th>
                        <td>
                            <input type="number" name="stock_bajo_dias" 
                                   value="<?php echo get_option('fc_stock_bajo_dias', 60); ?>" 
                                   min="1" max="365" style="width: 80px;"> días
                            <p class="description">Productos con stock para menos de estos días serán marcados como bajos</p>
                        </td>
                    </tr>
                </table>
            <h2 style="margin-top: 40px;">Opciones de Calidad</h2>
            <p>Configure las opciones de calidad disponibles:</p>
            
            <div id="quality-options">
                <?php foreach ($saved_qualities as $i => $quality): ?>
                    <p>
                        <input type="text" name="qualities[]" value="<?php echo esc_attr($quality); ?>" />
                        <button type="button" onclick="this.parentElement.remove()">Eliminar</button>
                    </p>
                <?php endforeach; ?>
            </div>
            
            <p>
                <button type="button" onclick="addQualityField()">+ Agregar opción</button>
            </p>
            
            <script>
            function addQualityField() {
                var div = document.getElementById('quality-options');
                var p = document.createElement('p');
                p.innerHTML = '<input type="text" name="qualities[]" value="" /> ' +
                             '<button type="button" onclick="this.parentElement.remove()">Eliminar</button>';
                div.appendChild(p);
            }
            </script>
            
            <p class="submit">
                <button type="submit" name="save_settings" class="button button-primary">
                    Guardar Configuracin
                </button>
                
                <?php if (isset($_POST['update_metrics'])): ?>
                    <?php 
                    $offset = isset($_POST['metrics_offset']) ? intval($_POST['metrics_offset']) : 0;
                    $procesados = fc_update_all_metrics($offset, 50);
                    $nuevo_offset = $offset + $procesados;
                    ?>
                    <?php if ($procesados > 0): ?>
                        <div class="notice notice-info">
                            <p>Procesados <?php echo $offset; ?> - <?php echo $nuevo_offset; ?> productos...</p>
                        </div>
                        <script>
                        setTimeout(function() {
                            document.getElementById('metrics_offset').value = <?php echo $nuevo_offset; ?>;
                            document.getElementById('update_metrics_btn').click();
                        }, 1000);
                        </script>
                    <?php else: ?>
                        <div class="notice notice-success"><p>¡Todas las métricas actualizadas!</p></div>
                    <?php endif; ?>
                <?php endif; ?>
                
                <input type="hidden" id="metrics_offset" name="metrics_offset" value="0">
                <button type="submit" id="update_metrics_btn" name="update_metrics" class="button">
                    Actualizar Métricas
                </button>
            </p>
        </form>
    </div>
    <?php
}

// Función para renderizar página de análisis
function fc_render_price_analysis_page() {
    if (file_exists(FC_PLUGIN_PATH . 'includes/class-fc-price-analysis.php')) {
        require_once FC_PLUGIN_PATH . 'includes/class-fc-price-analysis.php';
        $analysis = new FC_Price_Analysis();
        $analysis->render_page();
    } else {
        echo '<div class="wrap"><h1>Análisis Pre-Compra</h1><p>Archivo class-fc-price-analysis.php no encontrado.</p></div>';
    }
}

// Función para renderizar página de stock muerto
function fc_render_dead_stock_page() {
    if (file_exists(FC_PLUGIN_PATH . 'includes/class-fc-dead-stock.php')) {
        require_once FC_PLUGIN_PATH . 'includes/class-fc-dead-stock.php';
        $dead_stock = new FC_Dead_Stock();
        $dead_stock->render_page();
    } else {
        echo '<div class="wrap"><h1>Stock Sin Rotación</h1><p>Archivo class-fc-dead-stock.php no encontrado.</p></div>';
    }
}

// INICIO MODIFICACIÓN - Función para renderizar página de rebajados
function fc_render_rebajados_page() {
    require_once FC_PLUGIN_PATH . 'includes/class-fc-rebajados-manager.php';
    $manager = new FC_Rebajados_Manager();
    $manager->render_admin_page();
}
// FIN MODIFICACIÓN