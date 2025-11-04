<?php
/**
 * Clase temporal para importación
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

class FC_Import {
    
    public function __construct() {
        // Manejar acciones POST
        add_action('admin_init', array($this, 'handle_mark_received'));
        add_action('admin_init', array($this, 'handle_delete_order'));
        add_action('admin_init', array($this, 'handle_fix_order_names'));
    }
    
    // Renderizar página de importación
    public function render_page() {
        // Procesar si se envió el formulario
        if (isset($_POST['submit']) && isset($_FILES['excel_file'])) {
            $this->process_import();
        }
        ?>
        <div class="wrap">
            <h1>Importar Órdenes desde Excel</h1>
            
            <form method="post" enctype="multipart/form-data">
                <?php if (isset($_GET['alert_id'])): ?>
                    <input type="hidden" name="alert_id" value="<?php echo intval($_GET['alert_id']); ?>">
                <?php endif; ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Archivo Excel</th>
                        <td>
                            <input type="file" name="excel_file" accept=".xlsx,.xls,.csv" required>
                            <p class="description">Formato: SKU | Marca | Producto | QTY | Price USD | Quality</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Nombre de la Orden</th>
                        <td>
                            <input type="text" name="order_name" required placeholder="Ej: Orden Samsung Enero 2025" style="width: 300px;">
                            <p class="description">Identificador para esta importación</p>
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
                    <input type="submit" name="submit" class="button button-primary" value="Importar Órdenes">
                </p>
            </form>
        </div>
        <?php
    }
    
    // Renderizar página de histórico
   public function render_history_page() {
        global $wpdb;
        $table_orders = $wpdb->prefix . 'fc_orders_history';
        
        // Procesar eliminación si se envi el formulario
        if (isset($_POST['fc_delete_nonce']) && wp_verify_nonce($_POST['fc_delete_nonce'], 'delete_order')) {
            if (isset($_POST['delete_order_name'])) {
                $order_name = sanitize_text_field($_POST['delete_order_name']);
                
                $deleted = $wpdb->delete(
                    $table_orders,
                    array('order_name' => $order_name, 'status' => 'pending'),
                    array('%s', '%s')
                );
                
                if ($deleted) {
                    echo '<div class="notice notice-success is-dismissible">';
                    echo '<p>Orden eliminada correctamente (' . $deleted . ' items).</p>';
                    echo '</div>';
                }
            }
        }
        
        // Primero, verificar si necesitamos agregar la columna order_name
        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_orders LIKE 'order_name'");
        if (!$column_exists) {
            $wpdb->query("ALTER TABLE $table_orders ADD COLUMN order_name VARCHAR(255) DEFAULT NULL AFTER id");
        }
        
        // Contar registros sin order_name
        $sin_nombre = $wpdb->get_var("SELECT COUNT(*) FROM $table_orders WHERE order_name IS NULL OR order_name = ''");
        
        // Si hay registros sin nombre, actualizarlos automáticamente
        if ($sin_nombre > 0) {
            $wpdb->query("
                UPDATE $table_orders 
                SET order_name = CONCAT('Importación ', DATE_FORMAT(created_at, '%d/%m/%Y'))
                WHERE order_name IS NULL OR order_name = ''
            ");
        }
        
        // Ahora sí, obtener las órdenes agrupadas
        $orders = $wpdb->get_results("
            SELECT 
                order_name,
                COUNT(*) as items,
                SUM(quantity) as total_qty,
                SUM(quantity * purchase_price) as total_value,
                arrival_date,
                status,
                MIN(created_at) as created_at
            FROM $table_orders
            GROUP BY IFNULL(order_name, DATE(created_at)), arrival_date, status
            ORDER BY created_at DESC
            LIMIT 20
        ");
        ?>
        <div class="wrap">
            <h1>Histórico de Órdenes</h1>
            <?php if ($count_total > 0): ?>
            <?php 
            $sin_nombre = $wpdb->get_var("SELECT COUNT(*) FROM $table_orders WHERE order_name IS NULL OR order_name = ''");
            if ($sin_nombre > 0):
            ?>
                <div class="notice notice-warning">
                    <p>Hay <?php echo $sin_nombre; ?> registros sin nombre de orden.</p>
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="action" value="fc_fix_order_names">
                        <button type="submit" class="button">Asignar nombres automticos</button>
                    </form>
                </div>
            <?php endif; ?>
        <?php endif; ?>
            
            <?php if (isset($_GET['mensaje'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html($_GET['mensaje']); ?></p>
                </div>
            <?php endif; ?>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Nombre de Orden</th>
                        <th>Fecha Llegada</th>
                        <th>Items</th>
                        <th>Cantidad Total</th>
                        <th>Valor Total</th>
                        <th>Estado</th>
                        <th style="width: 300px;">Acciones</th> <!-- MÁS ANCHA -->
                    </tr>
                </thead>
                <tbody>
                    <?php if ($orders): ?>
                        <?php foreach ($orders as $order): ?>
                            <tr>
                                <td><strong><?php echo esc_html($order->order_name); ?></strong></td>
                                <td><?php echo date('d/m/Y', strtotime($order->arrival_date)); ?></td>
                                <td><?php echo number_format($order->items); ?></td>
                                <td><?php echo number_format($order->total_qty); ?></td>
                                <td>$<?php echo number_format($order->total_value, 2); ?></td>
                                <td>
                                    <?php if ($order->status == 'pending'): ?>
                                        <span style="color: orange;">⏱ Pendiente</span>
                                    <?php else: ?>
                                        <span style="color: green;">✅ Recibida</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <!-- BOTÓN VER DETALLES -->
                                    <a href="?page=fc-orders-history&view=details&order_name=<?php echo urlencode($order->order_name); ?>" 
                                       class="button button-small">Ver Detalles</a>
                                    
                                    <?php if ($order->status == 'pending'): ?>
                                        <!-- BOTÓN MARCAR RECIBIDA -->
                                        <form method="post" style="display: inline;">
                                            <input type="hidden" name="action" value="fc_mark_received">
                                            <input type="hidden" name="order_name" value="<?php echo esc_attr($order->order_name); ?>">
                                            <button type="submit" class="button button-small">Marcar Recibida</button>
                                        </form>
                                    <?php else: ?>
                                        <span style="color: green;">✅ Recibida</span>
                                    <?php endif; ?>
                                    
                                    <!-- BOTÓN ELIMINAR (siempre visible) -->
                                    <form method="post" style="display: inline;" 
                                          onsubmit="return confirm('¿Eliminar la orden <?php echo esc_js($order->order_name); ?>?');">
                                        <?php wp_nonce_field('delete_order', 'fc_delete_nonce'); ?>
                                        <input type="hidden" name="delete_order_name" value="<?php echo esc_attr($order->order_name); ?>">
                                        <button type="submit" class="button button-small" style="color: #d63638;">Eliminar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7">No hay órdenes registradas. <a href="?page=fc-import-orders">Importar primera orden</a></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <?php
            // Mostrar detalles si se solicita
            if (isset($_GET['view']) && $_GET['view'] == 'details' && isset($_GET['order_name'])) {
                $this->show_order_details($_GET['order_name']);
            }
            ?>
        </div>
        <?php
    }
    
    // Procesar importación
    private function process_import() {
        if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
            echo '<div class="notice notice-error"><p>Error al subir el archivo.</p></div>';
            return;
        }
        
        $file = $_FILES['excel_file']['tmp_name'];
        $order_name = sanitize_text_field($_POST['order_name']);
        $arrival_date = sanitize_text_field($_POST['arrival_date']);
        $file_ext = strtolower(pathinfo($_FILES['excel_file']['name'], PATHINFO_EXTENSION));
        
        if (in_array($file_ext, ['xlsx', 'xls'])) {
            // Verificar si existe SimpleXLSX
            $simplexlsx_path = FC_PLUGIN_PATH . 'includes/SimpleXLSX.php';
            if (file_exists($simplexlsx_path)) {
                require_once $simplexlsx_path;
                
                if ($xlsx = \Shuchkin\SimpleXLSX::parse($file)) {
                    $this->import_from_xlsx($xlsx, $order_name, $arrival_date);
                } else {
                    echo '<div class="notice notice-error"><p>Error al leer el archivo Excel.</p></div>';
                }
            } else {
                echo '<div class="notice notice-error"><p>SimpleXLSX.php no encontrado.</p></div>';
            }
        } else {
            echo '<div class="notice notice-error"><p>Solo se aceptan archivos Excel.</p></div>';
        }
    }
    
    // Importar desde XLSX
    private function import_from_xlsx($xlsx, $order_name, $arrival_date) {
        global $wpdb;
        $table_orders = $wpdb->prefix . 'fc_orders_history';
        
        // Procesar eliminación si se envió el formulario
        if (isset($_POST['fc_delete_nonce']) && wp_verify_nonce($_POST['fc_delete_nonce'], 'delete_order')) {
            if (isset($_POST['delete_order_name'])) {
                $order_name = sanitize_text_field($_POST['delete_order_name']);
                
                $deleted = $wpdb->delete(
                    $table_orders,
                    array('order_name' => $order_name, 'status' => 'pending'),
                    array('%s', '%s')
                );
                
                if ($deleted) {
                    echo '<div class="notice notice-success"><p>Orden eliminada correctamente.</p></div>';
                }
            }
        }
        
        $table_qualities = $wpdb->prefix . 'fc_product_qualities';
        
        $imported = 0;
        $errors = 0;
        $first_row = true;
        
        foreach ($xlsx->rows() as $row) {
            if ($first_row) {
                $first_row = false;
                continue;
            }
            
            if (count($row) >= 6) {
                $sku = trim($row[0]);
                $marca = trim($row[1]);
                $producto = trim($row[2]);
                $qty = intval($row[3]);
                $price_text = str_replace(',', '.', trim($row[4]));
                $price = floatval($price_text);
                $quality = trim($row[5]);
                
                if (!empty($sku)) {
                    $result = $wpdb->insert(
                        $table_orders,
                        array(
                            'order_name' => $order_name,
                            'sku' => $sku,
                            'product_name' => $marca . ' ' . $producto,
                            'quantity' => $qty,
                            'purchase_price' => $price,
                            'quality' => $quality,
                            'arrival_date' => $arrival_date,
                            'status' => 'pending'
                        )
                    );
                    
                    if ($result) {
                        $imported++;
                        
                        // Actualizar calidad
                        $wpdb->replace(
                            $table_qualities,
                            array(
                                'sku' => $sku,
                                'quality' => $quality
                            )
                        );
                    } else {
                        $errors++;
                    }
                }
            }
        }
        
        echo '<div class="notice notice-success"><p>';
        echo sprintf('Importación completada: %d productos importados, %d errores.', $imported, $errors);
        echo '</p></div>';
        
        // NUEVO: Si venimos de una alerta específica, finalizarla
        if (isset($_POST['alert_id']) && $imported > 0) {
            $alert_id = intval($_POST['alert_id']);
            $this->finalize_specific_alert($alert_id, $order_name);
        }
        
        // NUEVO: Verificar y reactivar alertas relacionadas
        if ($imported > 0) {
            $this->check_and_reactivate_alerts($order_name);
        }
    }
    
    // Mostrar detalles de orden
    private function show_order_details($order_name) {
        global $wpdb;
        $table_orders = $wpdb->prefix . 'fc_orders_history';
        
        // Si el order_name empieza con "Importación", buscar por fecha
        if (strpos($order_name, 'Importacin ') === 0) {
            // Extraer la fecha
            $date = str_replace('Importación ', '', $order_name);
            $date_mysql = date('Y-m-d', strtotime(str_replace('/', '-', $date)));
            
            $items = $wpdb->get_results($wpdb->prepare("
                SELECT * FROM $table_orders 
                WHERE DATE(created_at) = %s 
                ORDER BY sku
            ", $date_mysql));
        } else {
            $items = $wpdb->get_results($wpdb->prepare("
                SELECT * FROM $table_orders 
                WHERE order_name = %s 
                ORDER BY sku
            ", $order_name));
        }
        
        if (!$items) return;
        ?>
        <div style="margin-top: 30px; background: white; padding: 20px; border: 1px solid #ccc;">
            <h3>Detalle de: <?php echo esc_html($order_name); ?></h3>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Producto</th>
                        <th>Cantidad</th>
                        <th>Precio Unit.</th>
                        <th>Total</th>
                        <th>Calidad</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $total = 0;
                    foreach ($items as $item): 
                        $subtotal = $item->quantity * $item->purchase_price;
                        $total += $subtotal;
                    ?>
                        <tr>
                            <td><?php echo esc_html($item->sku); ?></td>
                            <td><?php echo esc_html($item->product_name); ?></td>
                            <td><?php echo $item->quantity; ?></td>
                            <td>$<?php echo number_format($item->purchase_price, 2); ?></td>
                            <td>$<?php echo number_format($subtotal, 2); ?></td>
                            <td><?php echo esc_html($item->quality); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="4">Total:</th>
                        <th>$<?php echo number_format($total, 2); ?></th>
                        <th></th>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php
    }
    
    // Finalizar una alerta específica
    private function finalize_specific_alert($alert_id, $order_name) {
        global $wpdb;
        $table_alerts = $wpdb->prefix . 'fc_weight_alerts';
        $table_history = $wpdb->prefix . 'fc_alert_history';

        // Obtener la alerta
        $alert = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_alerts} WHERE id = %d AND status = 'ordered'",
            $alert_id
        ));
        if ($alert) {

            // Finalizar la alerta
            $wpdb->update(
                $table_alerts,
                array(
                    'status' => 'completed',
                    'cycles_completed' => $alert->cycles_completed + 1
                ),
                array('id' => $alert_id)
            );

            // Registrar en historial
            $wpdb->insert(
                $table_history,
                array(
                    'alert_id' => $alert_id,
                    'event_type' => 'completed',
                    'details' => 'Finalizada por importación: ' . $order_name
                )
            );

            echo '<div class="notice notice-success"><p>';
            echo 'La alerta "' . esc_html($alert->name) . '" ha sido finalizada.';
            echo ' <a href="?page=fc-weight-alerts">Ver alertas</a>';
            echo '</p></div>';
        }
    }
    
    // Manejar marcar como recibida
    public function handle_mark_received() {
        if (isset($_POST['action']) && $_POST['action'] == 'fc_mark_received') {
            global $wpdb;
            $table_orders = $wpdb->prefix . 'fc_orders_history';
            $order_name = sanitize_text_field($_POST['order_name']);
            
            $result = $wpdb->update(
                $table_orders,
                array(
                    'status' => 'received',
                    'received_date' => current_time('mysql')
                ),
                array('order_name' => $order_name),
                array('%s', '%s'),
                array('%s')
            );
            
            if ($result !== false) {
                wp_redirect(admin_url('admin.php?page=fc-orders-history&mensaje=Orden marcada como recibida'));
                exit;
            }
        }
    }
    // Manejar eliminación de orden
    public function handle_delete_order() {
            error_log('FC DEBUG: handle_delete_order ejecutándose');
            error_log('FC DEBUG: POST data: ' . print_r($_POST, true));
            
            if (!isset($_POST['fc_delete_nonce'])) {
                error_log('FC DEBUG: No hay nonce');
                return;
            }
        
        if (!wp_verify_nonce($_POST['fc_delete_nonce'], 'delete_order')) {
            return;
        }
        
        if (isset($_POST['delete_order_name'])) {
            global $wpdb;
            $table_orders = $wpdb->prefix . 'fc_orders_history';
            $order_name = sanitize_text_field($_POST['delete_order_name']);
            
            $deleted = $wpdb->delete(
                $table_orders,
                array('order_name' => $order_name),
                array('%s')
            );
            
            wp_redirect(admin_url('admin.php?page=fc-orders-history&mensaje=Orden eliminada'));
            exit;
        }
    }
    
    
    // Agregar este método en la clase
    public function handle_fix_order_names() {
        if (isset($_POST['action']) && $_POST['action'] == 'fc_fix_order_names') {
            global $wpdb;
            $table_orders = $wpdb->prefix . 'fc_orders_history';
            
            // Actualizar registros sin order_name
            $updated = $wpdb->query("
                UPDATE $table_orders 
                SET order_name = CONCAT('Importación ', DATE_FORMAT(created_at, '%d/%m/%Y'))
                WHERE order_name IS NULL OR order_name = ''
            ");
            
            if ($updated !== false) {
                wp_redirect(admin_url('admin.php?page=fc-orders-history&mensaje=Se actualizaron ' . $updated . ' registros'));
                exit;
            }
        }
    }
    // Verificar y reactivar alertas despus de importar
    private function check_and_reactivate_alerts($order_name) {
        global $wpdb;
        
        // Obtener alertas en estado "ordered"
        $table_alerts = $wpdb->prefix . 'fc_weight_alerts';
        $table_manual = $wpdb->prefix . 'fc_alert_manual_products';
        $table_history = $wpdb->prefix . 'fc_alert_history';
        
        $alerts = $wpdb->get_results("
            SELECT * FROM {$table_alerts} 
            WHERE status = 'ordered'
        ");
        
        if (empty($alerts)) {
            return;
        }
        
        $reactivated = 0;
        
        foreach ($alerts as $alert) {
            // Verificar si esta importacin contiene productos de la alerta
            $has_products = $this->import_contains_alert_products($alert, $order_name);
            
            if ($has_products) {
            // CAMBIO: Finalizar la alerta en lugar de reactivarla
                $wpdb->update(
                    $table_alerts,
                    array(
                        'status' => 'completed',
                        'cycles_completed' => $alert->cycles_completed + 1
                    ),
                    array('id' => $alert->id)
                );
                
                // Registrar en historial
                $wpdb->insert(
                    $table_history,
                    array(
                        'alert_id' => $alert->id,
                        'event_type' => 'completed',
                        'details' => 'Finalizada por importación: ' . $order_name
                    )
                );
                
                $reactivated++;
            }
        }
        
        if ($reactivated > 0) {
            echo '<div class="notice notice-success"><p>';
            echo sprintf('Se finalizaron %d alertas de pedidos relacionadas.', $reactivated);
            echo '</p></div>';
        }
    }
    
    // Verificar si la importación contiene productos de la alerta
    private function import_contains_alert_products($alert, $order_name) {
        global $wpdb;
        
        // Si no hay filtros, asumir que sí está relacionada
        if (empty($alert->categories) && empty($alert->tags)) {
            return true;
        }
        
        // Obtener SKUs de esta importación
        $table_orders = $wpdb->prefix . 'fc_orders_history';
        $imported_skus = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT sku FROM {$table_orders} WHERE order_name = %s",
            $order_name
        ));
        
        if (empty($imported_skus)) {
            return false;
        }
        
        // Buscar si algún SKU corresponde a productos con las categorías/tags de la alerta
        $placeholders = array_fill(0, count($imported_skus), '%s');
        $placeholders_str = implode(',', $placeholders);
        
        // Buscar productos por SKU
        $query = $wpdb->prepare("
            SELECT DISTINCT p.ID 
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'product' 
            AND p.post_status = 'publish'
            AND (
                (pm.meta_key = '_alg_ean' AND pm.meta_value IN ($placeholders_str))
                OR (pm.meta_key = '_sku' AND pm.meta_value IN ($placeholders_str))
            )
        ", $imported_skus);
        
        $product_ids = $wpdb->get_col($query);
        
        if (empty($product_ids)) {
            return false;
        }
        
        // Verificar si alguno cumple con los filtros de la alerta
        foreach ($product_ids as $product_id) {
            $matches = true;
            
            // Verificar categorías
            if (!empty($alert->categories)) {
                $categories = explode(',', $alert->categories);
                $has_category = false;
                foreach ($categories as $cat_id) {
                    if (has_term($cat_id, 'product_cat', $product_id)) {
                        $has_category = true;
                        break;
                    }
                }
                if (!$has_category) {
                    $matches = false;
                }
            }
            
            // Verificar tags
            if ($matches && !empty($alert->tags)) {
                $tags = explode(',', $alert->tags);
                $has_tag = false;
                foreach ($tags as $tag) {
                    if (has_term($tag, 'product_tag', $product_id)) {
                        $has_tag = true;
                        break;
                    }
                }
                if (!$has_tag) {
                    $matches = false;
                }
            }
            
            if ($matches) {
                return true;
            }
        }
        
        return false;
    }
}