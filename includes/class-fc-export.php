<?php
/**
 * Clase para manejar exportaciones
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

class FC_Export {
    
    private $filters;
    
    public function __construct() {
        add_action('admin_init', array($this, 'handle_export'));
    }
    
    // Manejar exportación
    public function handle_export() {
        if (!isset($_POST['action']) || $_POST['action'] !== 'fc_export_forecast') {
            return;
        }
        
        // Verificar permisos
        if (!current_user_can('manage_options')) {
            wp_die('No tienes permisos para realizar esta acción');
        }
        
        // Obtener filtros
        $this->filters = array(
            'categorias' => isset($_POST['categorias']) ? array_map('intval', (array)$_POST['categorias']) : array(),
            'buscar' => isset($_POST['buscar']) ? sanitize_text_field($_POST['buscar']) : '',
            'periodo' => isset($_POST['periodo']) ? intval($_POST['periodo']) : 30,
            'meses_proyeccion' => isset($_POST['meses_proyeccion']) ? intval($_POST['meses_proyeccion']) : 3,
            'fecha_desde' => isset($_POST['fecha_desde']) ? sanitize_text_field($_POST['fecha_desde']) : '',
            'fecha_hasta' => isset($_POST['fecha_hasta']) ? sanitize_text_field($_POST['fecha_hasta']) : '',
            'tipo_promedio' => isset($_POST['tipo_promedio']) ? sanitize_text_field($_POST['tipo_promedio']) : 'diario'
        );
        
        // Generar Excel
        $this->generate_excel();
    }
    
    // Generar Excel
    private function generate_excel() {
        // Cargar SimpleXLSXGen
        require_once FC_PLUGIN_PATH . 'includes/SimpleXLSXGen.php';
        
        // Obtener datos
        $data = $this->get_export_data();
        
        // Generar y descargar Excel
        $xlsx = \Shuchkin\SimpleXLSXGen::fromArray($data);
        $xlsx->setDefaultFont('Arial');
        $xlsx->setDefaultFontSize(11);
        
        // Aplicar estilos a la primera fila
        $xlsx->mergeCells('A1:F1');
        
        $xlsx->downloadAs('pedido_compras_' . date('Y-m-d') . '.xlsx');
        exit;
    }
    
    // Obtener datos para exportar
    private function get_export_data() {
        global $wpdb;
        
        $data = array();
        
        // Título
        $data[] = ['PEDIDO DE COMPRAS - ' . date('d/m/Y')];
        $data[] = []; // Fila vacía
        
        // Headers con formato
        $data[] = [
            '<b>SKU</b>',
            '<b>Marca</b>',
            '<b>Producto</b>',
            '<b>QTY</b>',
            '<b>Price USD</b>',
            '<b>Quality</b>'
        ];
        
        // Obtener productos
        $products = $this->get_products_to_order();
        
        $total_items = 0;
        $total_quantity = 0;
        
        foreach ($products as $product) {
            $data[] = [
                $product['sku'],
                $product['marca'],
                $product['producto'],
                $product['cantidad'],
                '', // Precio vacío para que complete el proveedor
                $product['calidad']
            ];
            
            $total_items++;
            $total_quantity += $product['cantidad'];
        }
        
        // Resumen
        $data[] = []; // Fila vacía
        $data[] = ['', '', '<b>TOTAL ITEMS:</b>', '<b>' . $total_items . '</b>'];
        $data[] = ['', '', '<b>TOTAL UNIDADES:</b>', '<b>' . $total_quantity . '</b>'];
        
        // Información adicional
        $data[] = [];
        $data[] = ['<b>INFORMACIÓN DEL PEDIDO</b>'];
        $data[] = ['Fecha:', date('d/m/Y H:i')];
        $data[] = ['Proyección para:', $this->filters['meses_proyeccion'] . ' meses'];
        
        if (!empty($this->filters['fecha_desde']) && !empty($this->filters['fecha_hasta'])) {
            $data[] = ['Período analizado:', $this->filters['fecha_desde'] . ' a ' . $this->filters['fecha_hasta']];
        } else {
            $data[] = ['Período analizado:', 'Últimos ' . $this->filters['periodo'] . ' días'];
        }
        
        return $data;
    }
    
    // Obtener productos a ordenar
    private function get_products_to_order() {
        global $wpdb;
        
        $products_to_order = array();
        
        // Construir consulta
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => -1,
            'post_status' => 'publish'
        );
        
        // Filtro por categorías
        if (!empty($this->filters['categorias'])) {
            $args['tax_query'] = array(
                array(
                    'taxonomy' => 'product_cat',
                    'field' => 'term_id',
                    'terms' => $this->filters['categorias'],
                    'include_children' => true
                )
            );
        }
        
        // Filtro por búsqueda
        if (!empty($this->filters['buscar'])) {
            $args['meta_query'] = array(
                'relation' => 'OR',
                array(
                    'key' => '_alg_ean',
                    'value' => $this->filters['buscar'],
                    'compare' => 'LIKE'
                ),
                array(
                    'key' => '_sku',
                    'value' => $this->filters['buscar'],
                    'compare' => 'LIKE'
                )
            );
        }
        
        $products = new WP_Query($args);
        
        if ($products->have_posts()) {
            while ($products->have_posts()) {
                $products->the_post();
                $product_id = get_the_ID();
                $product = wc_get_product($product_id);
                
                // SKU
                $sku = get_post_meta($product_id, '_alg_ean', true);
                if (empty($sku)) {
                    $sku = $product->get_sku();
                }
                
                // Stock actual
                $stock_actual = $product->get_stock_quantity() ?: 0;
                
                // Calcular ventas
                if (!empty($this->filters['fecha_desde']) && !empty($this->filters['fecha_hasta'])) {
                    $dias_reales = (strtotime($this->filters['fecha_hasta']) - strtotime($this->filters['fecha_desde'])) / 86400 + 1;
                    $ventas_periodo = fc_get_product_sales_by_dates($product_id, $sku, $this->filters['fecha_desde'], $this->filters['fecha_hasta']);
                } else {
                    $dias_reales = $this->filters['periodo'];
                    $ventas_periodo = fc_get_product_sales($product_id, $sku, $this->filters['periodo']);
                }
                
                $promedio_diario = $dias_reales > 0 ? $ventas_periodo / $dias_reales : 0;
                
                // Stock en camino
                $table_orders = $wpdb->prefix . 'fc_orders_history';
                $stock_camino = $wpdb->get_var($wpdb->prepare(
                    "SELECT SUM(quantity) FROM $table_orders WHERE sku = %s AND status = 'pending'",
                    $sku
                )) ?: 0;
                
                // Calcular cuánto comprar
                $necesario = ($promedio_diario * 30 * $this->filters['meses_proyeccion']) - $stock_actual - $stock_camino;
                $comprar = max(0, ceil($necesario));
                
                // Solo incluir si hay que comprar
                if ($comprar > 0) {
                    // Obtener calidad
                    $table_qualities = $wpdb->prefix . 'fc_product_qualities';
                    $calidad = $wpdb->get_var($wpdb->prepare(
                        "SELECT quality FROM $table_qualities WHERE sku = %s",
                        $sku
                    ));
                    
                    // Separar marca y producto
                    $titulo = get_the_title();
                    $partes = explode(' ', $titulo, 2);
                    $marca = isset($partes[0]) ? $partes[0] : '';
                    $producto = isset($partes[1]) ? $partes[1] : $titulo;
                    
                    $products_to_order[] = array(
                        'sku' => $sku,
                        'marca' => $marca,
                        'producto' => $producto,
                        'cantidad' => $comprar,
                        'calidad' => $calidad ?: 'Sin definir'
                    );
                }
            }
            wp_reset_postdata();
        }
        
        // Ordenar por SKU
        usort($products_to_order, function($a, $b) {
            return strcmp($a['sku'], $b['sku']);
        });
        
        return $products_to_order;
    }
}

// Inicializar la clase
new FC_Export();