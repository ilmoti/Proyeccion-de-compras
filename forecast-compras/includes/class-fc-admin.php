<?php
/**
 * Clase para manejar el admin del plugin
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

class FC_Admin {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
    }
    
    // Agregar menús
    public function add_admin_menu() {
        // Menú principal
        add_menu_page(
            'Proyección Ventas',
            'Proyección Ventas',
            'manage_options',
            'forecast-compras',
            array($this, 'render_main_page'),
            'dashicons-chart-line',
            30
        );
        
        // Submenú para importar
        add_submenu_page(
            'forecast-compras',
            'Importar Órdenes',
            'Importar Órdenes',
            'manage_options',
            'fc-import-orders',
            array($this, 'render_import_page')
        );
        
        // Submenú para histórico
        add_submenu_page(
            'forecast-compras',
            'Histórico de Órdenes',
            'Histórico',
            'manage_options',
            'fc-orders-history',
            array($this, 'render_history_page')
        );
    }
    
    // Cargar scripts y estilos
    public function enqueue_scripts($hook) {
        // Solo en nuestras páginas
        if (strpos($hook, 'forecast-compras') === false && strpos($hook, 'fc-') === false) {
            return;
        }
        
        // Chart.js para gráficos
        wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '3.9.1', true);
        
        // Nuestros archivos
        wp_enqueue_style('fc-admin', FC_PLUGIN_URL . 'assets/css/admin.css', array(), '1.0');
        wp_enqueue_script('fc-admin', FC_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), '1.0', true);
    }
    
    // Página principal
    public function render_main_page() {
        require_once FC_PLUGIN_PATH . 'includes/class-fc-forecast.php';
        $forecast = new FC_Forecast();
        $forecast->render_page();
    }
    
    // Página de importación
    public function render_import_page() {
        require_once FC_PLUGIN_PATH . 'includes/class-fc-import.php';
        $import = new FC_Import();
        $import->render_page();
    }
    
    // Página de histórico
    public function render_history_page() {
        require_once FC_PLUGIN_PATH . 'includes/class-fc-import.php';
        $import = new FC_Import();
        $import->render_history_page();
    }
}