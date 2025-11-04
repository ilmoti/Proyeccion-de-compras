<?php
/**
 * Sistema de notificaciones para stock muerto
 */

if (!defined('ABSPATH')) {
    exit;
}

class FC_Dead_Stock_Notifications {
    
    public function __construct() {
        // Hook para envío semanal
        add_action('fc_weekly_dead_stock_report', array($this, 'send_weekly_report'));
        
        // Programar si no existe
        if (!wp_next_scheduled('fc_weekly_dead_stock_report')) {
            wp_schedule_event(time(), 'weekly', 'fc_weekly_dead_stock_report');
        }
    }
    
    public function send_weekly_report() {
        global $wpdb;
        
        // Obtener emails configurados
        $table_emails = $wpdb->prefix . 'fc_dead_stock_emails';
        $emails = $wpdb->get_col("SELECT email FROM $table_emails WHERE active = 1");
        
        if (empty($emails)) {
            return;
        }
        
        // Generar reporte
        $report_html = $this->generate_report_html();
        
        // Enviar email
        $subject = 'Reporte Semanal - Stock Sin Rotación ' . date('d/m/Y');
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        foreach ($emails as $email) {
            wp_mail($email, $subject, $report_html, $headers);
        }
    }
    
    private function generate_report_html() {
        global $wpdb;
        $table = $wpdb->prefix . 'fc_stock_analysis_cache';
        
        // Obtener estadísticas
        $stats = $wpdb->get_row("
            SELECT 
                COUNT(CASE WHEN days_without_sale >= 30 THEN 1 END) as sin_venta_30,
                COUNT(CASE WHEN days_without_sale >= 90 THEN 1 END) as sin_venta_90,
                SUM(immobilized_value) as valor_total
            FROM $table
            WHERE current_stock > 0
        ");
        
        // Top 10 productos críticos
        $critical_products = $wpdb->get_results("
            SELECT * FROM $table
            WHERE current_stock > 0 AND risk_score >= 50
            ORDER BY risk_score DESC, immobilized_value DESC
            LIMIT 10
        ");
        
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; }
                table { border-collapse: collapse; width: 100%; margin: 20px 0; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                th { background-color: #f2f2f2; }
                .summary { background: #f9f9f9; padding: 20px; margin: 20px 0; }
                .critical { color: #d32f2f; font-weight: bold; }
                .warning { color: #f57c00; }
            </style>
        </head>
        <body>
            <h2>Reporte Semanal - Stock Sin Rotación</h2>
            
            <div class="summary">
                <h3>Resumen</h3>
                <ul>
                    <li>Productos sin venta +30 días: <strong><?php echo $stats->sin_venta_30; ?></strong></li>
                    <li>Productos sin venta +90 días: <strong class="critical"><?php echo $stats->sin_venta_90; ?></strong></li>
                    <li>Valor total inmovilizado: <strong>$<?php echo number_format($stats->valor_total, 0); ?></strong></li>
                </ul>
            </div>
            
            <h3>Top 10 Productos Críticos</h3>
            <table>
                <thead>
                    <tr>
                        <th>Producto</th>
                        <th>SKU</th>
                        <th>Días sin venta</th>
                        <th>Stock</th>
                        <th>Valor</th>
                        <th>Risk Score</th>
                        <th>Desc. Sugerido</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($critical_products as $product): ?>
                        <tr>
                            <td><?php echo esc_html($product->product_name); ?></td>
                            <td><?php echo esc_html($product->sku); ?></td>
                            <td class="<?php echo $product->days_without_sale >= 90 ? 'critical' : 'warning'; ?>">
                                <?php echo $product->days_without_sale; ?> días
                            </td>
                            <td><?php echo $product->current_stock; ?></td>
                            <td>$<?php echo number_format($product->immobilized_value, 0); ?></td>
                            <td><?php echo $product->risk_score; ?>/100</td>
                            <td><?php echo $product->suggested_discount; ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <p>Para ver el reporte completo y tomar acciones, ingrese a: 
               <a href="<?php echo admin_url('admin.php?page=fc-dead-stock'); ?>">Panel de Stock Sin Rotación</a>
            </p>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
    
    // Página de configuración de emails
    public static function render_email_config() {
        global $wpdb;
        $table = $wpdb->prefix . 'fc_dead_stock_emails';
        
        // Procesar formulario
        if (isset($_POST['save_emails'])) {
            // Eliminar todos
            $wpdb->query("DELETE FROM $table");
            
            // Insertar nuevos
            if (isset($_POST['emails']) && is_array($_POST['emails'])) {
                foreach ($_POST['emails'] as $email) {
                    if (is_email($email)) {
                        $wpdb->insert($table, array('email' => sanitize_email($email)));
                    }
                }
            }
            echo '<div class="notice notice-success"><p>Emails guardados.</p></div>';
        }
        
        // Obtener emails actuales
        $emails = $wpdb->get_col("SELECT email FROM $table WHERE active = 1");
        ?>
        <div class="wrap">
            <h1>Configurar Notificaciones - Stock Sin Rotación</h1>
            
            <form method="post">
                <h3>Emails para recibir el reporte semanal:</h3>
                <div id="email-list">
                    <?php foreach ($emails as $email): ?>
                        <p>
                            <input type="email" name="emails[]" value="<?php echo esc_attr($email); ?>" />
                            <button type="button" onclick="this.parentElement.remove()">Eliminar</button>
                        </p>
                    <?php endforeach; ?>
                    <?php if (empty($emails)): ?>
                        <p><input type="email" name="emails[]" placeholder="email@ejemplo.com" /></p>
                    <?php endif; ?>
                </div>
                
                <p>
                    <button type="button" onclick="addEmailField()">+ Agregar otro email</button>
                </p>
                
                <p class="submit">
                    <button type="submit" name="save_emails" class="button button-primary">Guardar</button>
                    <a href="?page=fc-dead-stock" class="button">Cancelar</a>
                </p>
            </form>
            
            <script>
            function addEmailField() {
                var div = document.getElementById('email-list');
                var p = document.createElement('p');
                p.innerHTML = '<input type="email" name="emails[]" placeholder="email@ejemplo.com" /> ' +
                             '<button type="button" onclick="this.parentElement.remove()">Eliminar</button>';
                div.appendChild(p);
            }
            </script>
        </div>
        <?php
    }
}