<?php
/**
 * Clase para manejar exportación de alertas de peso
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

class FC_Weight_Export {
    
    private $table_alerts;
    private $table_manual_products;
    
    public function __construct() {
        global $wpdb;
        $this->table_alerts = $wpdb->prefix . 'fc_weight_alerts';
        $this->table_manual_products = $wpdb->prefix . 'fc_alert_manual_products';
        
        // Manejar exportación
        add_action('admin_init', array($this, 'handle_export'));
        
        // NUEVO: Hook para proceso en background
        add_action('fc_process_export_background', array($this, 'process_background_export'), 10, 2);
        
        // AGREGAR ESTAS 3 LÍNEAS NUEVAS:
        add_action('wp_ajax_fc_ajax_start_export', array($this, 'ajax_start_export'));
        add_action('wp_ajax_fc_ajax_process_batch', array($this, 'ajax_process_batch'));
        add_action('wp_ajax_fc_ajax_finish_export', array($this, 'ajax_finish_export'));
    }
    
    // Manejar solicitud de exportación
    public function handle_export() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'fc-weight-alerts') {
            return;
        }
        
        if (!isset($_GET['action']) || $_GET['action'] !== 'export') {
            return;
        }
        
        if (!isset($_GET['id'])) {
            return;
        }
        
        $alert_id = intval($_GET['id']);
        $download = isset($_GET['download']);
        $send_email = isset($_GET['email']);
        
        // Si es email, ejecutar en background
        if ($send_email) {
            $this->schedule_background_export($alert_id);
            return;
        }
        
        // Si es descarga, procesar directamente
        $this->process_export($alert_id, $download);
    }
    
    private function schedule_background_export($alert_id) {
        // NUEVO: Ejecutar directamente sin programar
        $this->process_background_export($alert_id, true);
        
        exit;
    }
    
    // Procesar exportación
    private function process_export($alert_id, $download_only = false) {
        // Quitar TODOS los límites
        @ini_set('max_execution_time', 0);
        @ini_set('memory_limit', '-1');
        @set_time_limit(0);
        
        global $wpdb;
        
        // Obtener alerta
        $alert = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_alerts} WHERE id = %d",
            $alert_id
        ));
        
        if (!$alert) {
            wp_die('Alerta no encontrada');
        }
        
        // NUEVO: Mtodo ULTRA RÁPIDO
        $this->export_direct_to_browser($alert);
    }
    
    public function ajax_start_export() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Sin permisos');
        }
        
        $alert_id = intval($_POST['alert_id']);
        $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 50;
        global $wpdb;
        
        // Obtener alerta
        $alert = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_alerts} WHERE id = %d",
            $alert_id
        ));
        
        if (!$alert) {
            wp_send_json_error('Alerta no encontrada');
        }
        
        // NUEVO: Usar el processor para pre-cargar y contar
        require_once FC_PLUGIN_PATH . 'includes/class-fc-processor.php';
        $processor = new FC_Processor($alert, $batch_size);
        $processor->preload_data();
        $processor->save_cache_to_transient();
        
        // Contar total de productos usando el processor
        $total_products = $processor->count_products();
        
        // Crear archivo temporal
        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . '/fc-exports';
        if (!file_exists($temp_dir)) {
            wp_mkdir_p($temp_dir);
        }
        
        $temp_file = $temp_dir . '/temp_' . $alert_id . '_' . time() . '.csv';
        
        // Inicializar archivo con headers
        $handle = fopen($temp_file, 'w');
        fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($handle, ['SKU', 'Producto', 'QTY', 'Price USD', 'Quality', 'Weight'], ';');
        fclose($handle);
        
        // Guardar batch size en transient para usarlo después
        set_transient('fc_batch_size_' . $alert_id, $batch_size, 3600);
        
        wp_send_json_success(array(
            'total' => $total_products,
            'temp_file' => basename($temp_file),
            'alert_name' => $alert->name,
            'batch_size' => $batch_size
        ));
    }
    
    public function ajax_process_batch() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Sin permisos');
        }
        
        $alert_id = intval($_POST['alert_id']);
        $offset = intval($_POST['offset']);
        $temp_file = sanitize_file_name($_POST['temp_file']);
        
        // Obtener batch size del transient
        $batch_size = get_transient('fc_batch_size_' . $alert_id);
        if (!$batch_size) {
            $batch_size = 50;
        }
        
        global $wpdb;
        
        // Obtener alerta
        $alert = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_alerts} WHERE id = %d",
            $alert_id
        ));
        
        if (!$alert) {
            wp_send_json_error('Alerta no encontrada');
        }
        
        // NUEVO: Usar el processor
        require_once FC_PLUGIN_PATH . 'includes/class-fc-processor.php';
        $processor = new FC_Processor($alert, $batch_size);
        $processor->load_cache_from_transient();
        
        // Procesar lote
        $results = $processor->process_batch($offset);
        
        // Escribir al CSV
        $processed = $this->write_batch_to_csv($results['products'], $temp_file);

        wp_send_json_success(array(
            'processed' => $results['processed'],
            'next_offset' => $offset + $batch_size,  // <-- Usar batch_size fijo
            'has_more' => $results['has_more'],
            'batch_value' => $results['total_weight'] 
        ));
    }
    
    /**
     * Escribir productos al archivo CSV
     */
    private function write_batch_to_csv($products, $temp_file) {
        $upload_dir = wp_upload_dir();
        $file_path = $upload_dir['basedir'] . '/fc-exports/' . $temp_file;
        
        $handle = fopen($file_path, 'a');
        if (!$handle) {
            return 0;
        }
        
        $processed = 0;
    
        foreach ($products as $product) {
            fputcsv($handle, [
                $product['sku'],
                $product['name'],
                $product['to_order'],
                str_replace('.', ',', $product['price']),
                $product['quality'],
                str_replace('.', ',', $product['weight'])
            ], ';');
            
            $processed++;
        }
        
        fclose($handle);
        
        return $processed;
    }
    
    // AJAX: Finalizar exportacin
    public function ajax_finish_export() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Sin permisos');
        }
        
        $alert_id = intval($_POST['alert_id']);
        $temp_file = sanitize_file_name($_POST['temp_file']);
        
        global $wpdb;
        
        // Obtener alerta
        $alert = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_alerts} WHERE id = %d",
            $alert_id
        ));
        
        if (!$alert) {
            wp_send_json_error('Alerta no encontrada');
        }
        
        // Ruta del archivo temporal
        $upload_dir = wp_upload_dir();
        $temp_path = $upload_dir['basedir'] . '/fc-exports/' . $temp_file;
        
        if (!file_exists($temp_path)) {
            wp_send_json_error('Archivo temporal no encontrado');
        }
        
        // Abrir para agregar productos manuales y totales
        $handle = fopen($temp_path, 'a');
        if (!$handle) {
            wp_send_json_error('No se pudo abrir el archivo');
        }
        
        // Calcular totales leyendo el archivo
        $total_items = 0;
        $total_qty = 0;
        $total_weight = 0;  // <-- AGREGAR ESTA VARIABLE

        
        if (($read_handle = fopen($temp_path, 'r')) !== FALSE) {
            $first_line = true;
            while (($data = fgetcsv($read_handle, 0, ';')) !== FALSE) {
                if ($first_line) {
                    $first_line = false; // Saltar header
                    continue;
                }
                if (isset($data[2]) && is_numeric($data[2])) {
                    $total_items++;
                    $total_qty += intval($data[2]);
                    // Peso: columna 5 es peso unitario, columna 2 es cantidad
                    if (isset($data[5]) && isset($data[2])) {
                        $peso_unitario = floatval(str_replace(',', '.', $data[5]));
                        $cantidad = intval($data[2]);
                        $peso_producto = $peso_unitario * $cantidad;
                        $total_weight += $peso_producto;
                    }   
                }
            }
            fclose($read_handle);
        }
        
        // Agregar productos manuales
        $manual = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_manual_products} WHERE alert_id = %d",
            $alert_id
        ));
        
        if ($manual) {
            fputcsv($handle, ['', '', '', '', ''], ';');
            fputcsv($handle, ['PRODUCTOS MANUALES', '', '', '', '']);
            
            foreach ($manual as $m) {
                fputcsv($handle, [
                $m->sku,
                $m->name,
                $m->quantity,
                str_replace('.', ',', $m->price ?: '0'),  // Cambiar punto por coma
                $m->quality ?: 'Sin definir',
                '0'  // Peso 0 para productos manuales
            ], ';');
                $total_items++;
                $total_qty += $m->quantity;
            }
        }
        
        // Agregar totales
        fputcsv($handle, ['', '', '', '', '']);
        fputcsv($handle, ['', 'TOTAL ITEMS:', $total_items, '', '']);
        fputcsv($handle, ['', 'TOTAL UNIDADES:', $total_qty, '', '']);
        
        fclose($handle);
        
        // Renombrar archivo final
        $final_name = 'pedido_' . sanitize_file_name($alert->name) . '_' . date('Y-m-d_His') . '.csv';
        $final_path = dirname($temp_path) . '/' . $final_name;
        
        if (!rename($temp_path, $final_path)) {
            wp_send_json_error('Error al renombrar archivo');
        }
        
        // Enviar por email - CORREGIDO
        try {
            $to = $alert->email;
            $subject = sprintf(
                '[%s] Pedido de compras listo - %s',
                get_bloginfo('name'),
                $alert->name
            );
            
            $message = "Hola,\n\n";
            $message .= "El pedido de compras para la alerta '{$alert->name}' est listo.\n\n";
            $message .= "Tipo de envo: " . ucfirst($alert->type) . "\n";
            $message .= "Configuracin: Análisis de {$alert->analysis_days} das para {$alert->purchase_months} meses\n\n";
            $message .= "Total de productos: {$total_items}\n";
            $message .= "Total de unidades: {$total_qty}\n\n";
            $message .= "Se adjunta el archivo CSV con el pedido.\n\n";
            $message .= "Saludos,\n";
            $message .= get_bloginfo('name');
            
            $headers = array('Content-Type: text/plain; charset=UTF-8');
            $attachments = array($final_path);
            
            $sent = wp_mail($to, $subject, $message, $headers, $attachments);
            
            // Limpiar cache del processor
            $transient_key = 'fc_processor_cache_' . $alert_id;
            delete_transient($transient_key);
            delete_transient('fc_processed_skus_' . $alert_id);

            
            // Programar limpieza
            wp_schedule_single_event(time() + 86400, 'fc_cleanup_export_file', array($final_path));
            
            wp_send_json_success(array(
                'message' => 'Exportacin completada y enviada por email a ' . $to
            ));
            
        } catch (Exception $e) {
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }
}

// Inicializar
new FC_Weight_Export();

// Registrar funcin de limpieza
add_action('fc_cleanup_export_file', function($file_path) {
    if (file_exists($file_path)) {
        unlink($file_path);
    }
});