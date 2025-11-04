<?php
/**
 * Clase para manejar las alertas de peso/CBM
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

class FC_Weight_Alerts {
    
    private $table_alerts;
    private $table_manual_products;
    private $table_history;
    
    public function __construct() {
        global $wpdb;
        $this->table_alerts = $wpdb->prefix . 'fc_weight_alerts';
        $this->table_manual_products = $wpdb->prefix . 'fc_alert_manual_products';
        $this->table_history = $wpdb->prefix . 'fc_alert_history';
    }
    
    // Renderizar página principal
    public function render_page() {
        // Procesar acciones (crear, editar, eliminar)
        $this->handle_actions();
        
        // Mostrar vista según el parámetro
        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : 'list';
        
        ?>
        <div class="wrap">
            <h1>Alertas de Pedidos 
                <?php if ($action == 'list'): ?>
                    <a href="?page=fc-weight-alerts&action=new" class="page-title-action">Añadir nueva</a>
                <?php endif; ?>
            </h1>
            
            <?php
            // Mostrar mensajes
            if (isset($_GET['message'])) {
                $message = '';
                switch ($_GET['message']) {
                    case 'created':
                        $message = 'Alerta creada exitosamente.';
                        break;
                    case 'updated':
                        $message = 'Alerta actualizada exitosamente.';
                        break;
                    case 'deleted':
                        $message = 'Alerta eliminada exitosamente.';
                        break;
                    case 'duplicated':
                        $message = 'Alerta duplicada exitosamente. Puedes editar el nombre y otros detalles.';
                        break;
                }
                if ($message) {
                    echo '<div class="notice notice-success is-dismissible"><p>' . $message . '</p></div>';
                }
            }
            
            switch ($action) {
                case 'new':
                case 'edit':
                    $this->render_form();
                    break;
                case 'manage':
                    $this->render_manage_page();
                    break;
                case 'force':
                    $this->handle_force_export();
                    break;
                case 'duplicate':
                    $this->handle_duplicate();
                    break;
                default:
                    $this->render_list();
            }
            ?>
        </div>
        <?php
    }
    // Manejar acciones POST
    private function handle_actions() {
        if (!isset($_POST['fc_action'])) {
            return;
        }
        
        // Verificar nonce y permisos
        if (!current_user_can('manage_options')) {
            wp_die('No tienes permisos para realizar esta acción');
        }
        
        $action = sanitize_text_field($_POST['fc_action']);
        
        switch ($action) {
            case 'create_alert':
                $this->create_alert();
                break;
            case 'update_alert':
                $this->update_alert();
                break;
            case 'delete_alert':
                $this->delete_alert();
                break;
            case 'update_single_alert':
                $this->update_single_alert();
                break;
            case 'background_update':
                $this->start_background_update();
                break;
            case 'add_manual_product':
                $this->add_manual_product();
                break;
            case 'delete_manual_product':
                $this->delete_manual_product();
                break;
            case 'mark_as_ordered':
                $this->mark_as_ordered();
                break;
        }
    }
    
    // Renderizar lista de alertas
    private function render_list() {
        global $wpdb;
        $alerts = $wpdb->get_results("SELECT * FROM {$this->table_alerts} ORDER BY created_at DESC");
        
        ?>
        <style>
            .alert-progress {
                width: 200px;
                height: 20px;
                background: #f0f0f0;
                border-radius: 10px;
                overflow: hidden;
                position: relative;
            }
            .alert-progress-bar {
                height: 100%;
                background: #0073aa;
                transition: width 0.3s;
            }
            .alert-progress-text {
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                text-align: center;
                line-height: 20px;
                font-size: 11px;
                color: #333;
            }
            .status-badge {
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: bold;
            }
            .status-active { background: #d4edda; color: #155724; }
            .status-ready { background: #fff3cd; color: #856404; }
            .status-ordered { background: #cce5ff; color: #004085; }
            .status-paused { background: #f8d7da; color: #721c24; }
            .status-completed { background: #d4edda; color: #155724; }
            /* NUEVOS ESTILOS PARA EL MODAL */
            .fc-progress-modal {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0,0,0,0.8);
                z-index: 999999;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .fc-progress-content {
                background: white;
                padding: 30px;
                border-radius: 5px;
                width: 500px;
            }
            .fc-progress-bar {
                height: 30px;
                background: #f0f0f0;
                border-radius: 15px;
                overflow: hidden;
                margin: 20px 0;
            }
            .fc-progress-fill {
                height: 100%;
                background: #0073aa;
                width: 0;
                transition: width 0.3s;
            }
        </style>
        <p class="description" style="margin-bottom: 20px;">
            Actualización automática: 
            <?php 
            $last_check = wp_next_scheduled('fc_check_weight_alerts');
            if ($last_check) {
                echo 'Próxima en ' . human_time_diff($last_check, current_time('timestamp')) ;
            } else {
                echo 'No programada';
            }
            ?>
            | Usa el botón 🔄 en cada alerta para actualizar individualmente
        </p>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Tipo</th>
                    <th>Configuración</th>
                    <th>Progreso</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($alerts)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center;">
                            No hay alertas creadas. 
                            <a href="?page=fc-weight-alerts&action=new">Crear primera alerta</a>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($alerts as $alert): 
                        $progress = $alert->limit_value > 0 ? ($alert->current_value / $alert->limit_value * 100) : 0;
                        $unit = $alert->type == 'aereo' ? 'kg' : 'CBM';
                    ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($alert->name); ?></strong><br>
                                <small><?php echo esc_html($alert->email); ?></small>
                            </td>
                            <td><?php echo ucfirst($alert->type); ?></td>
                            <td>
                                <small>
                                    Ventas: <?php echo $alert->analysis_days; ?> das<br>
                                    Stock: <?php echo $alert->purchase_months; ?> meses<br>
                                    <!-- DEBUG TEMPORAL -->
                                    Excluir: <?php echo $alert->excluded_tags ?: 'ninguno'; ?>
                                </small>
                            </td>
                            <td>
                                <div class="alert-progress">
                                    <div class="alert-progress-bar" style="width: <?php echo min($progress, 100); ?>%"></div>
                                    <div class="alert-progress-text">
                                        <?php echo number_format($alert->current_value, 2); ?> / 
                                        <?php echo number_format($alert->limit_value, 2); ?> <?php echo $unit; ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="status-badge status-<?php echo $alert->status; ?>">
                                    <?php echo $this->get_status_label($alert->status); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($alert->status != 'paused' && $alert->status != 'completed'): ?>
                                    <a href="?page=fc-weight-alerts&action=manage&id=<?php echo $alert->id; ?>" 
                                       class="button button-primary button-small">Gestionar</a>
                                <?php endif; ?>
                                
                                <?php if ($alert->status != 'completed'): ?>
                                <a href="?page=fc-weight-alerts&action=edit&id=<?php echo $alert->id; ?>" 
                                   class="button button-small">Editar</a>
                                
                                <button type="button" class="button button-small fc-ajax-update" 
                                        data-alert-id="<?php echo $alert->id; ?>" 
                                        data-alert-name="<?php echo esc_attr($alert->name); ?>"
                                        title="Actualizar esta alerta">
                                    🔄
                                </button>
                                <?php endif; ?>
                                
                                <?php if ($alert->status == 'active' && $alert->current_value > 0): ?>
                                    <a href="?page=fc-weight-alerts&action=force&id=<?php echo $alert->id; ?>" 
                                       class="button button-small" title="Forzar envo"
                                       onclick="return confirm('Forzar el envío sin alcanzar el lmite?')">⚡</a>
                                <?php endif; ?>
                                
                                <a href="?page=fc-weight-alerts&action=duplicate&id=<?php echo $alert->id; ?>" 
                                   class="button button-small" title="Duplicar alerta">📋</a>
                                
                                <form method="post" style="display: inline-block; margin-left: 5px;"
                                      onsubmit="return confirm('¿Ests seguro de eliminar esta alerta?');">
                                    <input type="hidden" name="fc_action" value="delete_alert">
                                    <input type="hidden" name="alert_id" value="<?php echo $alert->id; ?>">
                                    <button type="submit" class="button button-small button-link-delete">Eliminar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
            
            <script>
            jQuery(document).ready(function($) {
                $('.fc-ajax-update').on('click', function() {
                    var button = $(this);
                    
                    // AGREGAR ESTAS LÍNEAS:
                    if (button.data('processing')) {
                        return;
                    }
                    button.data('processing', true);
                    
                    var alertId = button.data('alert-id');
                    var alertName = button.data('alert-name');
                    var batchSize = 50;
                    
                    button.prop('disabled', true);
                    
                    // HTML del progreso
                    var progressHtml = '<div id="update-progress-container" style="display:none; position:fixed; top:32px; left:0; right:0; ' +
                        'background:#fff; border-bottom:2px solid #0073aa; padding:20px; box-shadow:0 2px 5px rgba(0,0,0,0.2); z-index:9999;">' +
                        '<div style="max-width:800px; margin:0 auto;">' +
                        '<h3 style="margin:0 0 10px 0;">🔄 Actualizando: ' + alertName + '</h3>' +
                        '<div style="background:#f0f0f0; height:30px; border-radius:15px; overflow:hidden; position:relative;">' +
                        '<div id="update-progress-bar" style="background:#0073aa; height:100%; width:0%; transition:width 0.3s;"></div>' +
                        '<div style="position:absolute; top:0; left:0; right:0; text-align:center; line-height:30px; font-weight:bold;">' +
                        '<span id="update-progress-text">0%</span>' +
                        '</div></div>' +
                        '<p id="update-status" style="margin:10px 0 0 0;">Preparando actualización...</p>' +
                        '</div></div>';
                    
                    $('body').append(progressHtml);
                    $('#update-progress-container').slideDown();
                    
                    var totalValue = 0;
                    var currentBatch = 0;
                    var totalProducts = 0;
                    
                    // Inicializar
                    $.post(ajaxurl, {
                        action: 'fc_update_alert_fast_init',
                        alert_id: alertId,
                        batch_size: batchSize
                    }).done(function(response) {
                        if (!response.success) {
                            showError('Error al iniciar: ' + (response.data || 'Error desconocido'));
                            return;
                        }
                        
                        totalProducts = response.data.total;
                        $('#update-status').text('Procesando ' + totalProducts + ' productos...');
                        processBatch(0);
                    }).fail(function() {
                        showError('Error de conexión');
                    });
                    
                    function processBatch(offset) {
                        $.post(ajaxurl, {
                            action: 'fc_update_alert_fast_batch',
                            alert_id: alertId,
                            offset: offset,
                            batch_size: batchSize
                        }).done(function(response) {
                            if (!response.success) {
                                showError('Error: ' + (response.data || 'Error desconocido'));
                                return;
                            }
                            
                            currentBatch += response.data.processed;
                            totalValue += response.data.batch_value;
                            
                            var progress = Math.min(100, Math.round((currentBatch / totalProducts) * 100));
                            $('#update-progress-bar').css('width', progress + '%');
                            $('#update-progress-text').text(progress + '%');
                            
                            // AGREGAR ESTAS LÍNEAS para mostrar peso y cantidad:
                            $('#update-status').html(
                                'Procesados: <strong>' + currentBatch + '</strong> de ' + totalProducts + 
                                ' | Peso/CBM actual: <strong>' + totalValue.toFixed(2) + '</strong>'
                            );
                            
                            if (response.data.has_more) {
                                setTimeout(function() {
                                    processBatch(offset + batchSize);  // USAR offset + batchSize
                                }, 10);
                            } else {
                                finishUpdate();
                            }
                        }).fail(function() {
                            setTimeout(function() {
                                processBatch(offset);
                            }, 2000);
                        });
                    }
                    
                    function finishUpdate() {
                        $('#update-status').text('Guardando...');
                        
                        $.post(ajaxurl, {
                            action: 'fc_update_alert_fast_finish',
                            alert_id: alertId,
                            total_value: totalValue
                        }).done(function() {
                            $('#update-progress-bar').css('width', '100%');
                            $('#update-status').html(
                                '<strong style="color:green;">✅ Actualización completada! Valor final: ' + 
                                totalValue.toFixed(2) + ' kg/CBM</strong>'
                            );

                            setTimeout(function() {
                                location.reload();
                                button.data('processing', false);
                            }, 2000);
                        }).fail(function() {
                            showError('Error al guardar');
                        });
                    }
                    
                    function showError(message) {
                        $('#update-status').html('<strong style="color:red;">❌ ' + message + '</strong>');
                        setTimeout(function() {
                            $('#update-progress-container').remove();
                            button.prop('disabled', false);
                            button.data('processing', false);
                        }, 3000);
                    }
                });
            });
            </script>
        <?php
    }
    // Obtener etiqueta de estado
    private function get_status_label($status) {
        $labels = array(
            'active' => 'Activa',
            'ready' => 'Pedido Listo',
            'ordered' => 'Ordenado',
            'paused' => 'Pausada',
            'completed' => 'Finalizada'
        );
        return isset($labels[$status]) ? $labels[$status] : $status;
    }
    
    // Renderizar formulario de creacin/edicin
    private function render_form() {
        global $wpdb;
        
        $alert = null;
        $is_edit = false;
        
        if (isset($_GET['id'])) {
            $is_edit = true;
            $alert_id = intval($_GET['id']);
            $alert = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->table_alerts} WHERE id = %d",
                $alert_id
            ));
        }
        
        // Obtener categorías
        $categories = get_terms(array(
            'taxonomy' => 'product_cat',
            'hide_empty' => false
        ));
        
        // Obtener etiquetas
        $tags = get_terms(array(
            'taxonomy' => 'product_tag',
            'hide_empty' => false
        ));
        
        ?>
        <style>
            .form-table th { width: 200px; }
            .checkbox-list { 
                max-height: 200px; 
                overflow-y: auto; 
                border: 1px solid #ddd; 
                padding: 10px;
                background: #f9f9f9;
            }
            .checkbox-list label { 
                display: block; 
                margin-bottom: 5px;
            }
            .config-box {
                background: #f0f0f1;
                border: 1px solid #c3c4c7;
                padding: 15px;
                margin: 10px 0;
                border-radius: 4px;
            }
        </style>
        
        <form method="post" action="">
            <input type="hidden" name="fc_action" value="<?php echo $is_edit ? 'update_alert' : 'create_alert'; ?>">
            <?php if ($is_edit): ?>
                <input type="hidden" name="alert_id" value="<?php echo $alert->id; ?>">
            <?php endif; ?>
            
            <h2><?php echo $is_edit ? 'Editar Alerta' : 'Nueva Alerta'; ?></h2>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="name">Nombre de la alerta</label></th>
                    <td>
                        <input type="text" id="name" name="name" class="regular-text" required
                               value="<?php echo $is_edit ? esc_attr($alert->name) : ''; ?>">
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><label for="email">Email de notificacin</label></th>
                    <td>
                        <input type="email" id="email" name="email" class="regular-text" required
                               value="<?php echo $is_edit ? esc_attr($alert->email) : get_option('admin_email'); ?>">
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">Tipo de envio</th>
                    <td>
                        <label>
                            <input type="radio" name="type" value="aereo" required
                                   <?php echo (!$is_edit || $alert->type == 'aereo') ? 'checked' : ''; ?>>
                            Areo (lmite en KG)
                        </label><br>
                        <label>
                            <input type="radio" name="type" value="maritimo"
                                   <?php echo ($is_edit && $alert->type == 'maritimo') ? 'checked' : ''; ?>>
                            Marítimo (lmite en CBM)
                        </label>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><label for="limit_value">Lmite</label></th>
                    <td>
                        <input type="number" id="limit_value" name="limit_value" step="0.01" required
                               value="<?php echo $is_edit ? $alert->limit_value : ''; ?>">
                        <span class="description">Valor en KG para aéreo o CBM para martimo</span>
                    </td>
                </tr>
            </table>
            
            <div class="config-box">
                <h3>Configuracin de Clculo</h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="analysis_days">Analizar ventas de</label></th>
                        <td>
                            <select id="analysis_days" name="analysis_days">
                                <?php 
                                $days_options = array(7, 15, 30, 60, 90, 180, 365);
                                $current_days = $is_edit ? $alert->analysis_days : 30;
                                foreach ($days_options as $days): 
                                ?>
                                    <option value="<?php echo $days; ?>" 
                                            <?php selected($current_days, $days); ?>>
                                        <?php echo $days; ?> das
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><label for="purchase_months">Comprar para</label></th>
                        <td>
                            <select id="purchase_months" name="purchase_months">
                                <?php 
                                $months_options = array(1, 2, 3, 4, 6, 12);
                                $current_months = $is_edit ? $alert->purchase_months : 3;
                                foreach ($months_options as $months): 
                                ?>
                                    <option value="<?php echo $months; ?>" 
                                            <?php selected($current_months, $months); ?>>
                                        <?php echo $months; ?> <?php echo $months == 1 ? 'mes' : 'meses'; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>
            </div>
            <h3>Filtros</h3>
            <table class="form-table">
                <tr>
                    <th scope="row">Categorías</th>
                    <td>
                        <div class="checkbox-list">
                            <?php 
                            $selected_cats = $is_edit ? explode(',', $alert->categories) : array();
                            foreach ($categories as $cat): 
                            ?>
                                <label>
                                    <input type="checkbox" name="categories[]" value="<?php echo $cat->term_id; ?>"
                                           <?php checked(in_array($cat->term_id, $selected_cats)); ?>>
                                    <?php echo esc_html($cat->name); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">Etiquetas (Proveedores)</th>
                    <td>
                        <div class="checkbox-list">
                            <?php 
                            $selected_tags = $is_edit ? explode(',', $alert->tags) : array();
                            foreach ($tags as $tag): 
                            ?>
                                <label>
                                    <input type="checkbox" name="tags[]" value="<?php echo $tag->slug; ?>"
                                           <?php checked(in_array($tag->slug, $selected_tags)); ?>>
                                    <?php echo esc_html($tag->name); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="description">Si no selecciona etiquetas, se generar un nico Excel</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Excluir Tags</th>
                    <td>
                        <div class="checkbox-list" style="background: #fff5f5;">
                            <?php 
                            $excluded_tags = $is_edit ? explode(',', $alert->excluded_tags) : array();
                            foreach ($tags as $tag): 
                            ?>
                                <label>
                                    <input type="checkbox" name="excluded_tags[]" value="<?php echo $tag->slug; ?>"
                                           <?php checked(in_array($tag->slug, $excluded_tags)); ?>>
                                    <?php echo esc_html($tag->name); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="description" style="color: #d63638;"> Los productos con estos tags sern EXCLUIDOS del pedido</p>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <button type="submit" class="button button-primary">
                    <?php echo $is_edit ? 'Actualizar Alerta' : 'Crear Alerta'; ?>
                </button>
                <a href="?page=fc-weight-alerts" class="button">Cancelar</a>
            </p>
        </form>
        <?php
    }
    
    // Crear nueva alerta
    private function create_alert() {
        global $wpdb;
        
        $data = array(
            'name' => sanitize_text_field($_POST['name']),
            'email' => sanitize_email($_POST['email']),
            'type' => sanitize_text_field($_POST['type']),
            'categories' => isset($_POST['categories']) ? implode(',', array_map('intval', $_POST['categories'])) : '',
            'tags' => isset($_POST['tags']) ? implode(',', array_map('sanitize_text_field', $_POST['tags'])) : '',
            'excluded_tags' => isset($_POST['excluded_tags']) ? implode(',', array_map('sanitize_text_field', $_POST['excluded_tags'])) : '',
            'limit_value' => floatval($_POST['limit_value']),
            'analysis_days' => intval($_POST['analysis_days']),
            'purchase_months' => intval($_POST['purchase_months']),
            'status' => 'active'
        );
        
        $wpdb->insert($this->table_alerts, $data);
        
        wp_redirect(add_query_arg(array(
            'page' => 'fc-weight-alerts',
            'message' => 'created'
        ), admin_url('admin.php')));
        exit;
    }
    
    // Actualizar alerta
    private function update_alert() {
        global $wpdb;
        
        $alert_id = intval($_POST['alert_id']);
        
        $data = array(
            'name' => sanitize_text_field($_POST['name']),
            'email' => sanitize_email($_POST['email']),
            'type' => sanitize_text_field($_POST['type']),
            'categories' => isset($_POST['categories']) ? implode(',', array_map('intval', $_POST['categories'])) : '',
            'tags' => isset($_POST['tags']) ? implode(',', array_map('sanitize_text_field', $_POST['tags'])) : '',
            'excluded_tags' => isset($_POST['excluded_tags']) ? implode(',', array_map('sanitize_text_field', $_POST['excluded_tags'])) : '',
            'limit_value' => floatval($_POST['limit_value']),
            'analysis_days' => intval($_POST['analysis_days']),
            'purchase_months' => intval($_POST['purchase_months'])
        );
        
        $wpdb->update($this->table_alerts, $data, array('id' => $alert_id));
        
        wp_redirect(add_query_arg(array(
            'page' => 'fc-weight-alerts',
            'message' => 'updated'
        ), admin_url('admin.php')));
        exit;
    }
    
    // Eliminar alerta
    private function delete_alert() {
        global $wpdb;
        
        $alert_id = intval($_POST['alert_id']);
        $wpdb->delete($this->table_alerts, array('id' => $alert_id));
        
        wp_redirect(add_query_arg(array(
            'page' => 'fc-weight-alerts',
            'message' => 'deleted'
        ), admin_url('admin.php')));
        exit;
    }
    
// Renderizar pgina de gestin
    private function render_manage_page() {
        global $wpdb;
        
        $alert_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        $alert = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_alerts} WHERE id = %d",
            $alert_id
        ));
        
        if (!$alert) {
            echo '<div class="notice notice-error"><p>Alerta no encontrada.</p></div>';
            return;
        }
        
        // Mostrar mensaje si es envo forzado
        if (isset($_GET['forced'])) {
            echo '<div class="notice notice-warning"><p>';
            echo '⚡ <strong>Envío Forzado:</strong> Este pedido se está generando sin alcanzar el límite configurado.';
            echo '</p></div>';
        }
        
        // Obtener productos que se incluiran
        $products_data = $this->get_alert_products($alert);
        require_once FC_PLUGIN_PATH . 'includes/class-fc-processor.php';
        $processor = new FC_Processor($alert);
        $total_products = $processor->count_products();
        $unit = $alert->type == 'aereo' ? 'kg' : 'CBM';
        
        // Obtener productos manuales
        $manual_products = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_manual_products} WHERE alert_id = %d",
            $alert_id
        ));
        
        ?>
        <style>
            .manage-section {
                background: #fff;
                padding: 20px;
                margin: 20px 0;
                border: 1px solid #ccc;
            }
            .products-table {
                width: 100%;
                border-collapse: collapse;
                margin: 15px 0;
            }
            .products-table th,
            .products-table td {
                border: 1px solid #ddd;
                padding: 8px;
                text-align: left;
            }
            .products-table th {
                background: #f5f5f5;
            }
            .section-summary {
                background: #f0f0f1;
                padding: 15px;
                margin: 10px 0;
                border-left: 4px solid #0073aa;
            }
            .manual-product-form {
                background: #f9f9f9;
                padding: 15px;
                border: 1px solid #ddd;
                margin: 15px 0;
            }
            .status-badge-large {
                display: inline-block;
                padding: 5px 15px;
                background: #fff3cd;
                color: #856404;
                border-radius: 3px;
                font-weight: bold;
                margin-left: 10px;
            }
        </style>
        
        <h2>
            Gestin de Pedido: <?php echo esc_html($alert->name); ?>
            <span class="status-badge-large"><?php echo $this->get_status_label($alert->status); ?></span>
        </h2>
        <div class="section-summary">
            <strong>Análisis de ventas:</strong> Últimos <?php echo $alert->analysis_days; ?> días<br>
            <strong>Comprar para:</strong> <?php echo $alert->purchase_months; ?> meses de stock<br>
            <strong>Tipo de envío:</strong> <?php echo ucfirst($alert->type); ?><br>
            
            <?php
            // Mostrar filtros aplicados
            $filters_info = array();
            
            // Categorías
            if (!empty($alert->categories)) {
                $cat_ids = explode(',', $alert->categories);
                $cat_names = array();
                foreach ($cat_ids as $cat_id) {
                    $term = get_term($cat_id, 'product_cat');
                    if ($term && !is_wp_error($term)) {
                        $cat_names[] = $term->name;
                    }
                }
                if (!empty($cat_names)) {
                    echo '<strong>Categorías:</strong> ' . implode(', ', $cat_names) . '<br>';
                }
            }
            
            // Tags incluidos
            if (!empty($alert->tags)) {
                $tags = explode(',', $alert->tags);
                echo '<strong>Tags incluidos:</strong> ' . implode(', ', $tags) . '<br>';
            }
            
            // Tags excluidos
            if (!empty($alert->excluded_tags)) {
                $excluded = explode(',', $alert->excluded_tags);
                echo '<strong style="color: #d63638;">Tags excluidos:</strong> ' . implode(', ', $excluded) . '<br>';
            }
            ?>
            
            <strong>Peso/Volumen alcanzado:</strong> 
            <?php echo number_format($alert->current_value, 2); ?> / 
            <?php echo number_format($alert->limit_value, 2); ?> <?php echo $unit; ?>
            (<?php echo round($alert->current_value / $alert->limit_value * 100, 1); ?>%)<br>
            <strong>Total de items en la alerta:</strong>
            <?php 
            // Contar productos del sistema que se pedirán
            $system_items = 0;
            $system_units = 0;
            foreach ($products_data['products'] as $product) {
                if ($product['to_order'] > 0) {
                    $system_items++;
                    $system_units += $product['to_order'];
                }
            }
            
            // Sumar productos manuales
            $manual_items = count($manual_products);
            $manual_units = array_sum(array_column($manual_products, 'quantity'));
            
            $total_items = $system_items + $manual_items;
            $total_units = $system_units + $manual_units;
            
            echo '<strong>' . $total_items . ' productos a pedir</strong>, ' . 
                 '<strong>' . number_format($total_units) . ' unidades totales</strong>';
            ?>
            </div>
        
        <!-- Productos del sistema -->
        <div class="manage-section">
            <h3>Productos del Sistema 
                (Mostrando <?php echo count($products_data['products']); ?> de <?php echo $total_products; ?> productos totales, estos son los items que se requieren hacer reposicion)
            </h3>
            <?php if (count($products_data['products']) < $total_products): ?>
                <p class="description">
                    Mostrando productos que requieren pedido. 
                </p>
            <?php endif; ?>
            <?php if (!empty($products_data['products'])): ?>
                <table class="products-table">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Producto</th>
                            <th>Stock Actual</th>
                            <th>En Camino</th>
                            <th>Cantidad a Pedir</th>
                            <th><?php echo $alert->type == 'aereo' ? 'Peso Unit.' : 'CBM Unit.'; ?></th>
                            <th><?php echo $alert->type == 'aereo' ? 'Peso Total' : 'CBM Total'; ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products_data['products'] as $product): ?>
                            <tr>
                                <td><?php echo esc_html($product['sku']); ?></td>
                                <td>
                                    <?php echo esc_html($product['name']); ?>
                                    <?php if (isset($product['has_risk_discount']) && $product['has_risk_discount'] && isset($product['risk_info'])): ?>
                                        <div style="margin-top: 5px; padding: 5px; background: #fff3cd; border: 1px solid #ffeeba; border-radius: 3px;">
                                            <strong style="color: #856404;">⚠️ Margen Reducido</strong><br>
                                            <small style="color: #856404;">
                                                Descuento aplicado: -<?php echo $product['risk_info']['discount_percent']; ?>% 
                                                (hace <?php echo $product['risk_info']['days_since_discount']; ?> días)
                                            </small>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $product['stock']; ?></td>
                                <td><?php echo $product['in_transit']; ?></td>
                                <td><strong><?php echo $product['to_order']; ?></strong></td>
                                <td><?php echo number_format($product['unit_value'], 3); ?></td>
                                <td><?php echo number_format($product['total_value'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="6" style="text-align: right;">Total:</th>
                            <th><?php echo number_format($products_data['total_value'], 2); ?> <?php echo $unit; ?></th>
                        </tr>
                    </tfoot>
                </table>
            <?php else: ?>
                <p>No se encontraron productos que necesiten reposición con los filtros configurados.</p>
            <?php endif; ?>
        </div>
        
        <!-- Productos manuales -->
        <div class="manage-section">
            <h3>Productos Manuales Agregados (<?php echo count($manual_products); ?> items)</h3>
            
            <?php if ($alert->status != 'ordered' && $alert->status != 'paused' && $alert->status != 'completed'): ?>
                <div class="manual-product-form">
                    <h4>Agregar Producto Nuevo</h4>
                    <form method="post" action="">
                        <input type="hidden" name="fc_action" value="add_manual_product">
                        <input type="hidden" name="alert_id" value="<?php echo $alert_id; ?>">
                        
                        <table class="form-table">
                            <tr>
                                <td><input type="text" name="sku" placeholder="SKU*" required></td>
                                <td><input type="text" name="name" placeholder="Nombre*" required></td>
                                <td><input type="number" name="quantity" placeholder="Cantidad*" required min="1"></td>
                                <td><input type="number" name="price" placeholder="Precio USD" step="0.01"></td>
                                <td>
                                    <select name="quality">
                                        <option value="">Calidad</option>
                                        <?php 
                                        $quality_options = get_option('fc_quality_options', array('A+', 'A', 'B', 'C'));
                                        foreach ($quality_options as $quality): 
                                        ?>
                                            <option value="<?php echo esc_attr($quality); ?>">
                                                <?php echo esc_html($quality); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><button type="submit" class="button button-primary">Agregar</button></td>
                            </tr>
                        </table>
                    </form>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($manual_products)): ?>
                <table class="products-table">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Producto</th>
                            <th>Cantidad</th>
                            <th>Precio USD</th>
                            <th>Calidad</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($manual_products as $product): ?>
                            <tr>
                                <td><?php echo esc_html($product->sku); ?></td>
                                <td><?php echo esc_html($product->name); ?></td>
                                <td><?php echo $product->quantity; ?></td>
                                <td><?php echo $product->price ? '$' . number_format($product->price, 2) : '-'; ?></td>
                                <td><?php echo esc_html($product->quality); ?></td>
                                <td>
                                    <?php if ($alert->status != 'ordered'): ?>
                                        <form method="post" style="display: inline;">
                                            <input type="hidden" name="fc_action" value="delete_manual_product">
                                            <input type="hidden" name="product_id" value="<?php echo $product->id; ?>">
                                            <input type="hidden" name="alert_id" value="<?php echo $alert_id; ?>">
                                            <button type="submit" class="button button-small">Eliminar</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No se han agregado productos manuales.</p>
            <?php endif; ?>
        </div>
        
        <!-- Acciones -->
        <div class="manage-section">
            <h3>Acciones del Pedido</h3>
            
            <?php if ($alert->status == 'ready'): ?>
                <p>  
                            <!-- NUEVO: Campo para batch size -->
                <label style="margin-left: 20px;">
                    Procesar de a: 
                    <input type="number" id="batch-size-<?php echo $alert_id; ?>" 
                           value="50" min="10" max="500" step="10" 
                           style="width: 80px;" />
                    productos
                </label>
                    <button type="button" id="btn-export-ajax" data-alert-id="<?php echo $alert_id; ?>" 
                            class="button button-primary">
                         Generar y Enviar por Email
                    </button>
                    <!-- Barra de progreso (oculta inicialmente) -->
                    <div id="export-progress-container" style="display:none; position:fixed; top:32px; left:0; right:0; 
                         background:#fff; border-bottom:2px solid #0073aa; padding:20px; box-shadow:0 2px 5px rgba(0,0,0,0.2); z-index:9999;">
                        <div style="max-width:800px; margin:0 auto;">
                            <h3 style="margin:0 0 10px 0;"> Generando pedido de compras...</h3>
                            <div style="background:#f0f0f0; height:30px; border-radius:15px; overflow:hidden; position:relative;">
                                <div id="export-progress-bar" style="background:#0073aa; height:100%; width:0%; transition:width 0.3s;"></div>
                                <div style="position:absolute; top:0; left:0; right:0; text-align:center; line-height:30px; font-weight:bold;">
                                    <span id="export-progress-text">0%</span>
                                </div>
                            </div>
                            <p id="export-status" style="margin:10px 0 0 0;">Iniciando proceso...</p>
                            <button type="button" id="btn-cancel-export" class="button" style="margin-top:10px; display:none;">Cancelar</button>
                        </div>
                    </div>
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="fc_action" value="mark_as_ordered">
                        <input type="hidden" name="alert_id" value="<?php echo $alert_id; ?>">
                        <button type="submit" class="button button-secondary" 
                                onclick="return confirm('Confirmas que el pedido fue realizado?')">
                             Marcar como Ordenado
                        </button>
                    </form>
                    
                    <a href="?page=fc-weight-alerts&action=force&id=<?php echo $alert_id; ?>" 
                       class="button"> Forzar Envío</a>
                </p>
            <?php elseif ($alert->status == 'ordered'): ?>
                <div style="background: #f0f8ff; padding: 15px; border-left: 4px solid #2196F3; margin: 15px 0;">
                    <p><strong> Pedido Ordenado</strong></p>
                    <p>Este pedido fue marcado como ordenado el 
                    <?php echo date('d/m/Y', strtotime($alert->last_order_date)); ?>.</p>
                    <p>La alerta se finalizar automticamente cuando importes el Excel del proveedor con los productos recibidos.</p>
                    
                    <p style="margin-top: 15px;">
                        <a href="?page=fc-import-orders&alert_id=<?php echo $alert_id; ?>" class="button button-primary">
                             Ir a Importar Órdenes
                        </a>
                        <span class="description" style="margin-left: 10px;">
                            Importa el Excel cuando llegue la mercadera
                        </span>
                    </p>
                </div>
            <?php elseif ($alert->status == 'completed'): ?>
                <div style="background: #d4edda; padding: 15px; border-left: 4px solid #28a745; margin: 15px 0;">
                    <p><strong> Pedido Finalizado</strong></p>
                    <p>Este pedido fue completado. Ciclo cerrado.</p>
                    
                    <p style="margin-top: 15px;">
                        <a href="?page=fc-weight-alerts&action=export&id=<?php echo $alert_id; ?>&download=1" 
                           class="button"> Descargar Excel Histórico</a>
                        
                        <a href="?page=fc-weight-alerts&action=duplicate&id=<?php echo $alert_id; ?>" 
                           class="button button-primary"> Duplicar para Nuevo Ciclo</a>
                    </p>
                </div>
            <?php endif; ?>
            
            <p style="margin-top: 20px;">
                <a href="?page=fc-weight-alerts" class="button"> Volver a la lista</a>
            </p>
            <script>
jQuery(document).ready(function($) {
    let exportInProgress = false;
    let cancelled = false;
    let currentBatch = 0;
    let totalProducts = 0;
    let totalWeight = 0;
    let tempFile = '';
    let alertId = 0;
    let startTime = 0;
    
    // Click en exportar
    $('#btn-export-ajax').on('click', function() {
        if (exportInProgress) {
            alert('Ya hay una exportacin en progreso');
            return;
        }
        
        const $btn = $(this);
        alertId = $btn.data('alert-id');
        
        // NUEVO: Obtener batch size
        const batchSize = $('#batch-size-' + alertId).val() || 50;
        
        // Mostrar barra de progreso
        $('#export-progress-container').slideDown();
        $btn.prop('disabled', true);
        exportInProgress = true;
        startTime = Date.now();
        
        // Iniciar exportación con batch size
        startExport(batchSize);
    });
    
    // Funcin para iniciar
    function startExport(batchSize) {
        $('#export-status').text('Preparando exportación...');
        
        savedBatchSize = batchSize;
        
        $.post(ajaxurl, {
            action: 'fc_ajax_start_export',
            alert_id: alertId,
            batch_size: batchSize
        })
        .done(function(response) {
            if (response.success) {
                totalProducts = response.data.total;
                tempFile = response.data.temp_file;
                currentBatch = 0;
                
                $('#export-status').text('Procesando ' + totalProducts + ' productos...');
                $('#btn-cancel-export').show();
                
                // Procesar primer lote
                processBatch(0);
            } else {
                showError(response.data || 'Error al iniciar');
            }
        })
        .fail(function() {
            showError('Error de conexin');
        });
    }
    
    // Procesar lote
    function processBatch(offset) {
        if (!exportInProgress) return;
        
        $.post(ajaxurl, {
            action: 'fc_ajax_process_batch',
            alert_id: alertId,
            offset: offset,
            temp_file: tempFile
        })
        .done(function(response) {
            if (response.success) {
                currentBatch += response.data.processed;
                totalWeight += response.data.batch_value;  // <-- ESTO ESTÁ BIEN
                
                // Actualizar progreso
                const progress = Math.min(100, Math.round((currentBatch / totalProducts) * 100));
                $('#export-progress-bar').css('width', progress + '%');
                $('#export-progress-text').text(progress + '%');
                
                // Calcular tiempo estimado
                const elapsed = (Date.now() - startTime) / 1000;
                const rate = currentBatch / elapsed;
                const remaining = (totalProducts - currentBatch) / rate;
                const eta = remaining > 60 ? Math.round(remaining / 60) + ' min' : Math.round(remaining) + ' seg';
                
                // SOLO UNA VEZ - Actualizar el status con el peso
                $('#export-status').text(
                    'Procesados: ' + currentBatch + ' de ' + totalProducts + ' productos. ' +
                    'Peso: ' + totalWeight.toFixed(2) + ' kg. ' +
                    'Tiempo restante: ' + eta
                );
                
                // Continuar o finalizar
                console.log('Export batch response:', response.data);
                if (response.data.has_more && !cancelled) {
                    console.log('Continuando con siguiente batch...');
                    setTimeout(() => processBatch(response.data.next_offset), 10);
                } else {
                    console.log('Finalizando - has_more:', response.data.has_more);
                    finishExport();
                }
            } else {
                showError(response.data || 'Error al procesar lote');
            }
        })
        .fail(function() {
            if (exportInProgress) {
                // Reintentar
                setTimeout(() => processBatch(offset), 2000);
            }
        });
    }
    
    // Finalizar exportacin
    function finishExport() {
        $('#export-status').text('Finalizando y enviando por email...');
        $('#btn-cancel-export').hide();
        
        // Calcular peso total acumulado
        let totalWeight = 0;
        // Aquí necesitamos acumular el peso de cada batch
        
        $.post(ajaxurl, {
            action: 'fc_ajax_finish_export',
            alert_id: alertId,
            temp_file: tempFile,
            total_weight: totalWeight  // <-- AGREGAR ESTA LÍNEA
        }).done(function(response) {
            if (response.success) {
                $('#export-progress-bar').css('width', '100%');
                $('#export-progress-text').text('100%');
                $('#export-status').html(
                    '<strong style="color:green;">✅ ' + response.data.message + '</strong>'
                );
                
                setTimeout(() => {
                    $('#export-progress-container').slideUp();
                    resetExport();
                }, 5000);
            } else {
                showError(response.data || 'Error al finalizar');
            }
        })
        .fail(function() {
            showError('Error al enviar email');
        });
    }
    
    // Mostrar error
    function showError(message) {
        $('#export-status').html(
            '<strong style="color:red;"> Error: ' + message + '</strong>' +
            ' <button type="button" onclick="location.reload();" class="button">Recargar pgina</button>'
        );
        $('#btn-cancel-export').hide();
    }
    
    // Botn cancelar (debe existir más abajo)
    $('#btn-cancel-export').on('click', function() {
        if (confirm('¿Cancelar la exportacin?')) {
            cancelled = true;  // <-- Asegurarse que esté
            exportInProgress = false;
            $('#export-progress-container').slideUp();
            resetExport();
        }
    });
    
    // Reset
    function resetExport() {
        exportInProgress = false;
        $('#btn-export-ajax').prop('disabled', false);
        $('#export-progress-bar').css('width', '0%');
        $('#export-progress-text').text('0%');
        currentBatch = 0;
    }
});
</script>
        </div>
        <?php
    }
    
    // NUEVO: Iniciar actualizacin en background
    private function start_background_update() {
        if (!isset($_POST['alert_id'])) {
            return;
        }
        
        $alert_id = intval($_POST['alert_id']);
        
        // Programar la actualizacin para ejecutarse en 10 segundos
        wp_schedule_single_event(time() + 10, 'fc_background_update_alert', array($alert_id));
        
        // Marcar como procesando
        update_option('fc_alert_processing_' . $alert_id, array(
            'status' => 'processing',
            'started' => current_time('mysql')
        ));
        
        wp_redirect(add_query_arg(array(
            'page' => 'fc-weight-alerts',
            'message' => 'processing'
        ), admin_url('admin.php')));
        exit;
    }
    
    // Obtener productos de la alerta
    public function get_alert_products($alert) {
        require_once FC_PLUGIN_PATH . 'includes/class-fc-processor.php';
        $processor = new FC_Processor($alert, 800);
        
        $processor->preload_data();
        $processor->save_cache_to_transient();
        
        $results = $processor->process_batch(0);
        
        return array(
            'products' => $results['products'],
            'total_value' => $results['total_weight']
        );
    }
    
    // Agregar producto manual
    private function add_manual_product() {
        global $wpdb;
        
        $data = array(
            'alert_id' => intval($_POST['alert_id']),
            'sku' => sanitize_text_field($_POST['sku']),
            'brand' => '',
            'name' => sanitize_text_field($_POST['name']),
            'quantity' => intval($_POST['quantity']),
            'price' => !empty($_POST['price']) ? floatval($_POST['price']) : null,
            'quality' => sanitize_text_field($_POST['quality'])
        );
        
        $wpdb->insert($this->table_manual_products, $data);
        
        wp_redirect(add_query_arg(array(
            'page' => 'fc-weight-alerts',
            'action' => 'manage',
            'id' => $data['alert_id']
        ), admin_url('admin.php')));
        exit;
    }
    
    // Eliminar producto manual
    private function delete_manual_product() {
        global $wpdb;
        
        $product_id = intval($_POST['product_id']);
        $alert_id = intval($_POST['alert_id']);
        
        $wpdb->delete($this->table_manual_products, array('id' => $product_id));
        
        wp_redirect(add_query_arg(array(
            'page' => 'fc-weight-alerts',
            'action' => 'manage',
            'id' => $alert_id
        ), admin_url('admin.php')));
        exit;
    }
    
    // Marcar como ordenado
    private function mark_as_ordered() {
        global $wpdb;
        
        $alert_id = intval($_POST['alert_id']);
        
        $wpdb->update(
            $this->table_alerts,
            array(
                'status' => 'ordered',
                'last_order_date' => current_time('mysql')
            ),
            array('id' => $alert_id)
        );
        
        // Registrar en historial
        $wpdb->insert($this->table_history, array(
            'alert_id' => $alert_id,
            'event_type' => 'marked_ordered',
            'details' => 'Pedido marcado como ordenado'
        ));
        
        wp_redirect(add_query_arg(array(
            'page' => 'fc-weight-alerts',
            'action' => 'manage',
            'id' => $alert_id
        ), admin_url('admin.php')));
        exit;
    }

    // Obtener etiqueta de evento
    private function get_event_label($event_type) {
        $labels = array(
            'value_update' => 'Actualizacin',
            'limit_reached' => 'Lmite Alcanzado',
            'marked_ordered' => 'Pedido Realizado',
            'reactivated' => 'Reactivada',
            'completed' => 'Finalizada',
            'created' => 'Creada',
            'manual_product_added' => 'Producto Manual',
            'forced_export' => 'Envo Forzado'
        );
        return isset($labels[$event_type]) ? $labels[$event_type] : $event_type;
    }
    // Manejar forzar envo
    private function handle_force_export() {
        global $wpdb;
        
        $alert_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        
        if (!$alert_id) {
            wp_redirect(admin_url('admin.php?page=fc-weight-alerts'));
            exit;
        }
        
        // Cambiar estado temporalmente a 'ready' para permitir la exportacin
        $wpdb->update(
            $this->table_alerts,
            array('status' => 'ready'),
            array('id' => $alert_id)
        );
        
        // Registrar en historial
        $alert = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_alerts} WHERE id = %d",
            $alert_id
        ));
        
        $wpdb->insert($this->table_history, array(
            'alert_id' => $alert_id,
            'event_type' => 'forced_export',
            'value_before' => $alert->current_value,
            'value_after' => $alert->current_value,
            'details' => 'Envío forzado sin alcanzar el límite'
        ));
        
        // Redirigir a la pgina de gestin
        wp_redirect(add_query_arg(array(
            'page' => 'fc-weight-alerts',
            'action' => 'manage',
            'id' => $alert_id,
            'forced' => '1'
        ), admin_url('admin.php')));
        exit;
    }
    // Manejar duplicacin de alerta
    private function handle_duplicate() {
        global $wpdb;
        
        $alert_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        
        if (!$alert_id) {
            wp_redirect(admin_url('admin.php?page=fc-weight-alerts'));
            exit;
        }
        
        // Obtener alerta original
        $alert = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_alerts} WHERE id = %d",
            $alert_id
        ));
        
        if (!$alert) {
            wp_redirect(admin_url('admin.php?page=fc-weight-alerts'));
            exit;
        }
        
        // Crear nueva alerta
        $new_data = array(
            'name' => $alert->name . ' (Copia)',
            'email' => $alert->email,
            'type' => $alert->type,
            'categories' => $alert->categories,
            'tags' => $alert->tags,
            'limit_value' => $alert->limit_value,
            'analysis_days' => $alert->analysis_days,
            'purchase_months' => $alert->purchase_months,
            'status' => 'active',
            'current_value' => 0,
            'cycles_completed' => 0
        );
        
        $wpdb->insert($this->table_alerts, $new_data);
        $new_id = $wpdb->insert_id;
        
        // Registrar en historial
        $wpdb->insert($this->table_history, array(
            'alert_id' => $new_id,
            'event_type' => 'created',
            'details' => 'Duplicada desde alerta #' . $alert_id
        ));
        
        // Redirigir a editar la nueva alerta
        wp_redirect(add_query_arg(array(
            'page' => 'fc-weight-alerts',
            'action' => 'edit',
            'id' => $new_id,
            'message' => 'duplicated'
        ), admin_url('admin.php')));
        exit;
    }
}