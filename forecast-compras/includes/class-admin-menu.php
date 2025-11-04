<?php
class FC_Admin_Menu {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_menu_pages'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }
    
    public function add_menu_pages() {
        // Menú principal
        add_menu_page(
            'Proyeccin Ventas',
            'Proyección Ventas',
            'manage_woocommerce',
            'forecast-compras',
            array($this, 'render_main_page'),
            'dashicons-chart-line',
            30
        );
        
        // Submen para importar órdenes
        add_submenu_page(
            'forecast-compras',
            'Importar Órdenes',
            'Importar Órdenes',
            'manage_woocommerce',
            'fc-import-orders',
            array($this, 'render_import_page')
        );
        
        // Submenú para histórico
        add_submenu_page(
            'forecast-compras',
            'Histórico de Órdenes',
            'Histórico',
            'manage_woocommerce',
            'fc-orders-history',
            array($this, 'render_history_page')
        );
    }
    
    public function enqueue_admin_scripts($hook) {
        // Solo cargar en nuestras páginas
        if (strpos($hook, 'forecast-compras') === false && strpos($hook, 'fc-') === false) {
            return;
        }
        
        wp_enqueue_style('fc-admin-css', FC_PLUGIN_URL . 'assets/css/admin.css');
        wp_enqueue_script('fc-admin-js', FC_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), '1.0', true);
        
        // Para gráficos
        wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '3.9.1', true);
    }
    
    public function render_main_page() {
        ?>
        <div class="wrap">
            <h1>Proyección de Ventas y Compras</h1>
            
            <!-- Filtros -->
            <div class="fc-filters">
                <form method="get" action="">
                    <input type="hidden" name="page" value="forecast-compras">
                    
                    <label>Categoría:
                        <?php 
                        // Dropdown de categorías de WooCommerce
                        wp_dropdown_categories(array(
                            'taxonomy' => 'product_cat',
                            'name' => 'categoria',
                            'show_option_all' => 'Todas las categorías',
                            'selected' => $_GET['categoria'] ?? 0
                        ));
                        ?>
                    </label>
                    
                    <label>Buscar:
                        <input type="text" name="buscar" value="<?php echo esc_attr($_GET['buscar'] ?? ''); ?>" placeholder="SKU o nombre...">
                    </label>
                    
                    <label>Período:
                        <select name="periodo">
                            <option value="30">Últimos 30 días</option>
                            <option value="60">Últimos 60 días</option>
                            <option value="90">Últimos 90 días</option>
                            <option value="180">Últimos 6 meses</option>
                            <option value="365">Último año</option>
                        </select>
                    </label>
                    
                    <label>Proyectar a:
                        <input type="number" name="meses_proyeccion" value="<?php echo $_GET['meses_proyeccion'] ?? 3; ?>" min="1" max="12"> meses
                    </label>
                    
                    <button type="submit" class="button button-primary">Filtrar</button>
                </form>
            </div>
            
            <!-- Tabla de resultados -->
            <div class="fc-results">
                <?php $this->render_forecast_table(); ?>
            </div>
            
            <!-- Botón exportar -->
            <div class="fc-actions">
                <a href="<?php echo wp_nonce_url(admin_url('admin-ajax.php?action=fc_export_order'), 'fc_export'); ?>" 
                   class="button button-primary">Exportar Pedido a Excel</a>
            </div>
        </div>
        <?php
    }
    
    private function render_forecast_table() {
        // Aquí irá la lógica de la tabla
        echo '<p>Tabla de proyección en construcción...</p>';
    }
    
    public function render_import_page() {
        ?>
        <div class="wrap">
            <h1>Importar Órdenes desde Excel</h1>
            
            <?php if (isset($_GET['mensaje'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html($_GET['mensaje']); ?></p>
                </div>
            <?php endif; ?>
            
            <div class="fc-import-form">
                <form method="post" enctype="multipart/form-data" action="<?php echo admin_url('admin-post.php'); ?>">
                    <?php wp_nonce_field('fc_import_excel', 'fc_nonce'); ?>
                    <input type="hidden" name="action" value="fc_import_excel">
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">Archivo Excel</th>
                            <td>
                                <input type="file" name="excel_file" accept=".xlsx,.xls" required>
                                <p class="description">Formato: SKU | Marca | Producto | QTY | Price USD | Quality</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Fecha estimada de llegada</th>
                            <td>
                                <input type="date" name="arrival_date" required>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <button type="submit" class="button button-primary">Importar Órdenes</button>
                    </p>
                </form>
            </div>
            
            <div class="fc-import-help">
                <h3>Formato del archivo Excel</h3>
                <p>El archivo debe tener las siguientes columnas:</p>
                <ul>
                    <li><strong>SKU</strong>: Código del producto</li>
                    <li><strong>Marca</strong>: Marca del producto</li>
                    <li><strong>Producto</strong>: Nombre del producto</li>
                    <li><strong>QTY</strong>: Cantidad pedida</li>
                    <li><strong>Price USD</strong>: Precio unitario en dólares</li>
                    <li><strong>Quality</strong>: Calidad (Incell, Oled, Original, etc.)</li>
                </ul>
            </div>
        </div>
        <?php
    }
    
    public function render_history_page() {
        global $wpdb;
        $table_orders = $wpdb->prefix . 'fc_orders_history';
        
        // Obtener órdenes
        $orders = $wpdb->get_results("
            SELECT * FROM $table_orders 
            ORDER BY created_at DESC 
            LIMIT 100
        ");
        ?>
        <div class="wrap">
            <h1>Histórico de Órdenes</h1>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Producto</th>
                        <th>Cantidad</th>
                        <th>Precio USD</th>
                        <th>Calidad</th>
                        <th>Fecha Llegada</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($orders): ?>
                        <?php foreach ($orders as $order): ?>
                            <tr>
                                <td><?php echo esc_html($order->sku); ?></td>
                                <td><?php echo esc_html($order->product_name); ?></td>
                                <td><?php echo esc_html($order->quantity); ?></td>
                                <td>$<?php echo number_format($order->purchase_price, 2); ?></td>
                                <td><?php echo esc_html($order->quality); ?></td>
                                <td><?php echo esc_html($order->arrival_date); ?></td>
                                <td>
                                    <?php if ($order->status == 'pending'): ?>
                                        <span class="dashicons dashicons-clock"></span> Pendiente
                                    <?php else: ?>
                                        <span class="dashicons dashicons-yes"></span> Recibida
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($order->status == 'pending'): ?>
                                        <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=fc_receive_order&order_id=' . $order->id), 'fc_receive_' . $order->id); ?>" 
                                           class="button button-small">Marcar Recibida</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8">No hay órdenes registradas</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}