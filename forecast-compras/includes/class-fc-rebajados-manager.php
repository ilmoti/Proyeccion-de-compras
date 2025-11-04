<?php
/**
 * Gestor de productos rebajados
 * Detecta rebajas individuales y mantiene etiquetas
 */

if (!defined('ABSPATH')) {
    exit;
}

class FC_Rebajados_Manager {
    
    private $tag_name = 'Rebajado';
    private $min_discount_percent = 3;
    private $mass_change_threshold = 100;
    private $time_window = 600; // 10 minutos en segundos
    
    public function __construct() {
        // Hook para detectar cambios de precio
        add_action('fc_price_change_detected', array($this, 'handle_price_change'), 10, 4);
        
        // Hook cuando el stock llega a 0
        add_action('woocommerce_product_set_stock', array($this, 'check_stock_level'));
        add_action('woocommerce_variation_set_stock', array($this, 'check_stock_level'));
        
        // Hook para cuando se actualiza el stock (aumenta)
        add_action('woocommerce_product_set_stock', array($this, 'check_stock_increase'), 10, 1);
        add_action('woocommerce_variation_set_stock', array($this, 'check_stock_increase'), 10, 1);
        
        // Programar evento diario para revisar etiquetas expiradas
        add_action('fc_check_expired_rebajados', array($this, 'remove_expired_tags'));
        if (!wp_next_scheduled('fc_check_expired_rebajados')) {
            wp_schedule_event(time(), 'daily', 'fc_check_expired_rebajados');
        }
        
        // Hook cuando se actualiza el stock (para cuando vuelve a tener)
        add_action('woocommerce_product_set_stock_status', array($this, 'check_stock_status'), 10, 3);
        
        // Crear tabla para tracking si no existe
        $this->maybe_create_tracking_table();
    }
    
    /**
     * Crear tabla para rastrear cambios masivos
     */
    private function maybe_create_tracking_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'fc_price_changes_tracking';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            $charset_collate = $wpdb->get_charset_collate();
            
            $sql = "CREATE TABLE $table_name (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                product_id bigint(20) NOT NULL,
                change_type varchar(20) NOT NULL,
                change_percent decimal(10,2) NOT NULL,
                change_time datetime NOT NULL,
                PRIMARY KEY (id),
                KEY product_id (product_id),
                KEY change_time (change_time)
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
    }
    
    /**
     * Manejar cambio de precio detectado
     */
    public function handle_price_change($product_id, $old_price, $new_price, $change_percent) {
        
        // INICIO DEBUG
        error_log("REBAJADOS: Handler llamado - ID: $product_id, Old: $old_price, New: $new_price, Change: $change_percent%");
        // FIN DEBUG
        
        // Registrar el cambio
        $this->record_price_change($product_id, $change_percent);
        
        // Verificar si es un cambio masivo
        if ($this->is_mass_price_change()) {
            // Es cambio masivo, no procesar rebajas individuales
            return;
        }
        
        // Si es una rebaja individual de al menos 3%
        if ($change_percent <= -$this->min_discount_percent) {
            $this->apply_discount_tag($product_id, abs($change_percent));
        }
    }
    
    /**
     * Registrar cambio de precio en la tabla de tracking
     */
    private function record_price_change($product_id, $change_percent) {
        global $wpdb;
        
        $change_type = $change_percent < 0 ? 'decrease' : 'increase';
        
        $wpdb->insert(
            $wpdb->prefix . 'fc_price_changes_tracking',
            array(
                'product_id' => $product_id,
                'change_type' => $change_type,
                'change_percent' => $change_percent,
                'change_time' => current_time('mysql')
            )
        );
    }
    
    /**
     * Verificar si es un cambio masivo de precios
     */
    private function is_mass_price_change() {
        global $wpdb;
        
        $time_limit = date('Y-m-d H:i:s', time() - $this->time_window);
        
        // Contar cambios en los últimos 10 minutos
        $count = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT product_id) 
            FROM {$wpdb->prefix}fc_price_changes_tracking
            WHERE change_time > %s
        ", $time_limit));
        
        return $count > $this->mass_change_threshold;
    }
    
    /**
     * Aplicar etiqueta de rebajado
     */
    private function apply_discount_tag($product_id, $discount_percent) {
        // Obtener o crear la etiqueta
        $tag = get_term_by('name', $this->tag_name, 'product_tag');
        if (!$tag) {
            $tag_data = wp_insert_term($this->tag_name, 'product_tag');
            $tag_id = $tag_data['term_id'];
        } else {
            $tag_id = $tag->term_id;
        }
        
        // Asignar etiqueta al producto
        wp_set_post_terms($product_id, array($tag_id), 'product_tag', true);
        
        // Guardar metadatos de la rebaja
        update_post_meta($product_id, '_rebaja_porcentaje', $discount_percent);
        update_post_meta($product_id, '_rebaja_fecha', current_time('mysql'));
        
        // Guardar el precio de lista sin rebaja para cálculos futuros
        $current_price = get_post_meta($product_id, '_price', true);
        $precio_sin_rebaja = $current_price / (1 - ($discount_percent / 100));
        update_post_meta($product_id, '_rebaja_precio_lista_original', $precio_sin_rebaja);
    }
    
    /**
     * Verificar nivel de stock
     */
    public function check_stock_level($product) {
        if ($product->get_stock_quantity() <= 0) {
            $this->remove_discount_tag($product->get_id());
        }
    }
    
    /**
     * Verificar si se agregó stock a un producto rebajado
     */
    public function check_stock_increase($product) {
        $product_id = $product->get_id();
        
        // Solo procesar si tiene etiqueta de rebajado
        if (!$this->has_discount_tag($product_id)) {
            return;
        }
        
        // Obtener stock anterior guardado
        $stock_anterior = get_post_meta($product_id, '_ultimo_stock_registrado', true);
        $stock_actual = $product->get_stock_quantity();
        
        // Si no hay registro anterior, guardarlo y salir
        if ($stock_anterior === '') {
            update_post_meta($product_id, '_ultimo_stock_registrado', $stock_actual);
            return;
        }
        
        // Si el stock aumentó, marcar fecha de ingreso
        if ($stock_actual > $stock_anterior) {
            update_post_meta($product_id, '_rebaja_fecha_ingreso_stock', current_time('mysql'));
            
            // Log para debugging
            error_log("REBAJADOS: Stock aumentado - Producto ID: $product_id, Anterior: $stock_anterior, Actual: $stock_actual");
        }
        
        // Actualizar stock registrado
        update_post_meta($product_id, '_ultimo_stock_registrado', $stock_actual);
    }
    
    /**
     * Verificar cuando cambia el estado del stock
     */
    public function check_stock_status($product_id, $stock_status, $product) {
        // Si vuelve a tener stock, quitar etiqueta
        if ($stock_status === 'instock' && $this->has_discount_tag($product_id)) {
            // Verificar si realmente viene de sin stock
            $old_stock = get_post_meta($product_id, '_stock', true);
            if ($old_stock <= 0) {
                $this->remove_discount_tag($product_id);
            }
        }
    }
    
    /**
     * Verificar si producto tiene etiqueta de rebajado
     */
    private function has_discount_tag($product_id) {
        $tag = get_term_by('name', $this->tag_name, 'product_tag');
        if (!$tag) return false;
        
        return has_term($tag->term_id, 'product_tag', $product_id);
    }
    
    /**
     * Quitar etiqueta de rebajado
     */
    private function remove_discount_tag($product_id) {
        $tag = get_term_by('name', $this->tag_name, 'product_tag');
        if ($tag) {
            wp_remove_object_terms($product_id, $tag->term_id, 'product_tag');
        }
        
        // Limpiar metadatos
        delete_post_meta($product_id, '_rebaja_porcentaje');
        delete_post_meta($product_id, '_rebaja_fecha');
        delete_post_meta($product_id, '_rebaja_precio_lista_original');
        // INICIO MODIFICACIÓN - Limpiar nuevos metadatos
        delete_post_meta($product_id, '_rebaja_fecha_ingreso_stock');
        delete_post_meta($product_id, '_ultimo_stock_registrado');
        // FIN MODIFICACIÓN
    }
    
    /**
     * Eliminar etiquetas de productos que agregaron stock hace más de 30 días
     */
    public function remove_expired_tags() {
        // Obtener todos los productos con etiqueta rebajado
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => -1,
            'product_tag' => $this->tag_name,
            'meta_key' => '_rebaja_fecha_ingreso_stock',
            'meta_compare' => 'EXISTS'
        );
        
        $products = get_posts($args);
        
        foreach ($products as $product_post) {
            $fecha_ingreso = get_post_meta($product_post->ID, '_rebaja_fecha_ingreso_stock', true);
            
            if ($fecha_ingreso) {
                $dias_transcurridos = (time() - strtotime($fecha_ingreso)) / (60 * 60 * 24);
                
                // Si han pasado 30 días o más desde el ingreso de stock
                if ($dias_transcurridos >= 30) {
                    $this->remove_discount_tag($product_post->ID);
                    
                    // Log para debugging
                    error_log("REBAJADOS: Etiqueta expirada removida - Producto ID: {$product_post->ID}, Días: $dias_transcurridos");
                }
            }
        }
    }
    
     /**
     * Obtener precios antes/ahora para mostrar
     */
    public function get_display_prices($product_id) {
        // Verificar si tiene rebaja
        $rebaja_porcentaje = get_post_meta($product_id, '_rebaja_porcentaje', true);
        if (!$rebaja_porcentaje) {
            return false;
        }
        
        // Precio actual de lista
        $precio_actual = get_post_meta($product_id, '_price', true);
        
        // Calcular precio "antes" (sin rebaja)
        $precio_antes = $precio_actual / (1 - ($rebaja_porcentaje / 100));
        
        // Aplicar descuentos por rol si el usuario está logueado
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            $descuento_rol = $this->get_role_discount($user);
            
            if ($descuento_rol > 0) {
                $precio_antes = $precio_antes * (1 - ($descuento_rol / 100));
                $precio_actual = $precio_actual * (1 - ($descuento_rol / 100));
            }
        }
        
        return array(
            'antes' => $precio_antes,
            'ahora' => $precio_actual,
            'porcentaje' => $rebaja_porcentaje
        );
    }
    
    /**
     * Obtener descuento por rol de usuario
     */
    private function get_role_discount($user) {
        // Verificar roles y devolver descuento
        if (in_array('distri30', $user->roles)) {
            return 30;
        } elseif (in_array('distri20', $user->roles)) {
            return 20;
        } elseif (in_array('distri10', $user->roles)) {
            return 10;
        }
        return 0;
    }
    
    /**
     * Shortcode para mostrar productos rebajados
     */
    public function rebajados_shortcode($atts) {
        $atts = shortcode_atts(array(
            'limit' => 12,
            'columns' => 4
        ), $atts);
        
        // Obtener productos con etiqueta "Rebajado"
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => $atts['limit'],
            'product_tag' => $this->tag_name
        );
        
        $products = new WP_Query($args);
        
        ob_start();
        
        if ($products->have_posts()) {
            woocommerce_product_loop_start();
            
            while ($products->have_posts()) {
                $products->the_post();
                wc_get_template_part('content', 'product');
            }
            
            woocommerce_product_loop_end();
        }
        
        wp_reset_postdata();
        
        return ob_get_clean();
    }
    /**
     * Renderizar página de administración
     */
    public function render_admin_page() {
        global $wpdb;
        
        // Obtener productos con etiqueta rebajado
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => -1,
            'product_tag' => $this->tag_name,
            'meta_key' => '_rebaja_fecha',
            'orderby' => 'meta_value',
            'order' => 'DESC'
        );
        
        $products = get_posts($args);
        ?>
        <div class="wrap">
            <h1>Productos Rebajados</h1>
            
            <div class="notice notice-info">
                <p>Total de productos rebajados: <strong><?php echo count($products); ?></strong></p>
            </div>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Producto</th>
                        <th>SKU</th>
                        <th>% Rebaja</th>
                        <th>Precio Antes</th>
                        <th>Precio Ahora</th>
                        <th>Fecha Rebaja</th>
                        <th>Stock</th>
                        <th>Expira en</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $product_post): 
                        $product = wc_get_product($product_post->ID);
                        $rebaja_info = $this->get_display_prices($product_post->ID);
                        $rebaja_fecha = get_post_meta($product_post->ID, '_rebaja_fecha', true);
                        $fecha_ingreso_stock = get_post_meta($product_post->ID, '_rebaja_fecha_ingreso_stock', true);
                        $dias_restantes = '';
                        if ($fecha_ingreso_stock) {
                            $dias_transcurridos = (time() - strtotime($fecha_ingreso_stock)) / (60 * 60 * 24);
                            $dias_restantes = 30 - $dias_transcurridos;
                            if ($dias_restantes > 0) {
                                $dias_restantes = round($dias_restantes) . ' días';
                            } else {
                                $dias_restantes = '<span style="color: red;">Expirado</span>';
                            }
                        } else {
                            $dias_restantes = 'Sin ingreso';
                        }
                        
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($product->get_name()); ?></strong>
                            </td>
                            <td><?php echo esc_html($product->get_sku()); ?></td>
                            <td>
                                <span style="color: #d9534f; font-weight: bold;">
                                    -<?php echo round($rebaja_info['porcentaje']); ?>%
                                </span>
                            </td>
                            <td><?php echo wc_price($rebaja_info['antes']); ?></td>
                            <td><?php echo wc_price($rebaja_info['ahora']); ?></td>
                            <td><?php echo date('d/m/Y', strtotime($rebaja_fecha)); ?></td>
                            <td>
                                <?php 
                                $stock = $product->get_stock_quantity();
                                if ($stock <= 0) {
                                    echo '<span style="color: red;">Sin stock</span>';
                                } else {
                                    echo $stock;
                                }
                                ?>
                            </td>
                            <td><?php echo $dias_restantes; ?></td>
                            <td>
                                <a href="<?php echo get_edit_post_link($product_post->ID); ?>" 
                                   class="button button-small">Editar</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
} // FIN DE LA CLASE - Esta llave ya existe, no la dupliques


// Inicializar
new FC_Rebajados_Manager();

