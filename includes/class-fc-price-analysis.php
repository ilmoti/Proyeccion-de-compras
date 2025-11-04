<?php
/**
 * Clase para análisis de precios pre-compra
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

class FC_Price_Analysis {
    
    private $analysis_data = null;
    
    public function __construct() {
        // Manejar la subida del archivo
        if (isset($_POST['analyze_order']) && isset($_FILES['analysis_file'])) {
            $this->process_analysis();
        }
        
        // Manejar exportación
        if (isset($_POST['export_analysis'])) {
            $this->export_analysis();
        }
    }
    
    // Renderizar página principal
    public function render_page() {
        ?>
        <div class="wrap">
            <h1>📊 Análisis Pre-Compra de Órdenes</h1>
            <p>Sube un archivo Excel para comparar precios con las últimas órdenes registradas.</p>
            
            <?php if (!isset($_POST['analyze_order'])): ?>
                <!-- Formulario de subida -->
                <div class="card" style="max-width: 600px; padding: 20px;">
                    <form method="post" enctype="multipart/form-data">
                        <table class="form-table">
                            <tr>
                                <th scope="row">Archivo Excel</th>
                                <td>
                                    <input type="file" name="analysis_file" accept=".xlsx,.xls,.csv" required>
                                    <p class="description">Formato: SKU | Marca | Producto | QTY | Price USD | Quality</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Nombre del Análisis</th>
                                <td>
                                    <input type="text" name="analysis_name" required 
                                           placeholder="Ej: Cotización Samsung Nov 2024" 
                                           style="width: 300px;">
                                </td>
                            </tr>
                        </table>
                        
                        <p class="submit">
                            <button type="submit" name="analyze_order" class="button button-primary">
                                 Analizar Orden
                            </button>
                        </p>
                    </form>
                </div>
            <?php else: ?>
                <!-- Mostrar resultados del análisis -->
                <?php $this->render_analysis_results(); ?>
            <?php endif; ?>
        </div>
        
        <style>
        .analysis-table { margin-top: 20px; }
        .analysis-table th { background: #f0f0f1; font-weight: bold; }
        .price-up { background-color: #ffebee !important; color: #c62828; }
        .price-down { background-color: #e8f5e9 !important; color: #2e7d32; }
        .price-same { background-color: #f5f5f5 !important; }
        .price-alert { background-color: #fff3cd !important; color: #856404; }
        .quality-changed { background-color: #e3f2fd !important; }
        .analysis-summary {
            background: white;
            padding: 20px;
            border: 1px solid #ddd;
            margin: 20px 0;
            border-radius: 5px;
        }
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 15px;
        }
        .summary-card {
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            text-align: center;
        }
        .summary-card h3 { margin: 0 0 10px 0; font-size: 14px; color: #666; }
        .summary-card .value { font-size: 24px; font-weight: bold; }
        .trend-arrow { font-size: 18px; margin-left: 5px; }
        </style>
        <?php
    }
    
    // Procesar el análisis
    private function process_analysis() {
        if (!isset($_FILES['analysis_file']) || $_FILES['analysis_file']['error'] !== UPLOAD_ERR_OK) {
            echo '<div class="notice notice-error"><p>Error al subir el archivo.</p></div>';
            return;
        }
        
        $file = $_FILES['analysis_file']['tmp_name'];
        $file_ext = strtolower(pathinfo($_FILES['analysis_file']['name'], PATHINFO_EXTENSION));
        
        if (in_array($file_ext, ['xlsx', 'xls'])) {
            $simplexlsx_path = FC_PLUGIN_PATH . 'includes/SimpleXLSX.php';
            if (file_exists($simplexlsx_path)) {
                require_once $simplexlsx_path;
                
                if ($xlsx = \Shuchkin\SimpleXLSX::parse($file)) {
                    $this->analyze_excel_data($xlsx);
                }
            }
        }
    }
    
    // Analizar datos del Excel
    private function analyze_excel_data($xlsx) {
        global $wpdb;
        $table_orders = $wpdb->prefix . 'fc_orders_history';
        
        $this->analysis_data = array(
            'name' => sanitize_text_field($_POST['analysis_name']),
            'date' => current_time('mysql'),
            'items' => array(),
            'summary' => array(
                'total_skus' => 0,
                'total_quantity' => 0,
                'total_value' => 0,
                'previous_value' => 0,
                'price_increases' => 0,
                'price_decreases' => 0,
                'quality_changes' => 0,
                'new_skus' => 0
            )
        );
        
        $first_row = true;
        
        foreach ($xlsx->rows() as $row) {
            if ($first_row) {
                $first_row = false;
                continue;
            }
            
            if (count($row) >= 6) {
                $sku = trim($row[0]);
                if (empty($sku)) continue;
                
                $current_data = array(
                    'sku' => $sku,
                    'brand' => trim($row[1]),
                    'product' => trim($row[2]),
                    'quantity' => intval($row[3]),
                    'price' => floatval(str_replace(',', '.', trim($row[4]))),
                    'quality' => trim($row[5])
                );
                
                // Obtener las últimas 2 órdenes de este SKU
                $history = $wpdb->get_results($wpdb->prepare("
                    SELECT * FROM $table_orders 
                    WHERE sku = %s 
                    ORDER BY arrival_date DESC 
                    LIMIT 2
                ", $sku));
                
                // Analizar comparación
                $comparison = $this->compare_with_history($current_data, $history);
                
                // Agregar al análisis
                $this->analysis_data['items'][] = $comparison;
                
                // Actualizar resumen
                $this->update_summary($comparison);
            }
        }
    }
    
    // Comparar con historial
    private function compare_with_history($current, $history) {
        $result = array(
            'sku' => $current['sku'],
            'brand' => $current['brand'],
            'product' => $current['product'],
            'quantity' => $current['quantity'],
            'current_price' => $current['price'],
            'current_quality' => $current['quality'],
            'total_value' => $current['quantity'] * $current['price'],
            'status' => 'new',
            'price_trend' => 'new',
            'alerts' => array()
        );
        
        if (count($history) > 0) {
            $last_order = $history[0];
            $result['status'] = 'existing';
            $result['last_price'] = floatval($last_order->purchase_price);
            $result['last_quality'] = $last_order->quality;
            $result['last_date'] = $last_order->arrival_date;
            $result['last_order'] = $last_order->order_name;
            
            // Calcular variación de precio
            if ($result['last_price'] > 0) {
                $price_diff = $current['price'] - $result['last_price'];
                $price_percent = ($price_diff / $result['last_price']) * 100;
                
                $result['price_diff'] = $price_diff;
                $result['price_percent'] = $price_percent;
                
                // Determinar tendencia
                if ($price_percent > 2) {
                    $result['price_trend'] = 'up';
                    if ($price_percent > 10) {
                        $result['alerts'][] = 'Aumento significativo de precio';
                    }
                } elseif ($price_percent < -2) {
                    $result['price_trend'] = 'down';
                } else {
                    $result['price_trend'] = 'stable';
                }
            }
            
            // Verificar cambio de calidad (sin distinguir mayúsculas/minúsculas)
                if (strcasecmp($current['quality'], $last_order->quality) !== 0) {
                    $result['quality_changed'] = true;
                    $result['alerts'][] = 'Cambio de calidad';
                }
            
            // Si hay segunda orden, calcular tendencia
            if (count($history) > 1) {
                $second_order = $history[1];
                $result['second_price'] = floatval($second_order->purchase_price);
                $result['second_date'] = $second_order->arrival_date;
                
                // Determinar tendencia de largo plazo
                if ($current['price'] > $result['last_price'] && 
                    $result['last_price'] > $result['second_price']) {
                    $result['long_trend'] = 'increasing';
                    $result['alerts'][] = 'Tendencia alcista sostenida';
                }
            }
        }
        
        return $result;
    }
    
    // Actualizar resumen
    private function update_summary(&$item) {
        $this->analysis_data['summary']['total_skus']++;
        $this->analysis_data['summary']['total_quantity'] += $item['quantity'];
        $this->analysis_data['summary']['total_value'] += $item['total_value'];
        
        if ($item['status'] === 'new') {
            $this->analysis_data['summary']['new_skus']++;
        } else {
            // Valor anterior estimado
            $this->analysis_data['summary']['previous_value'] += 
                ($item['quantity'] * $item['last_price']);
            
            if ($item['price_trend'] === 'up') {
                $this->analysis_data['summary']['price_increases']++;
            } elseif ($item['price_trend'] === 'down') {
                $this->analysis_data['summary']['price_decreases']++;
            }
            
            if (isset($item['quality_changed']) && $item['quality_changed']) {
                $this->analysis_data['summary']['quality_changes']++;
            }
        }
    }
    
    // Renderizar resultados del análisis
    private function render_analysis_results() {
        if (!$this->analysis_data) {
            echo '<div class="notice notice-error"><p>No hay datos de análisis.</p></div>';
            return;
        }
        
        $summary = $this->analysis_data['summary'];
        $value_diff = $summary['total_value'] - $summary['previous_value'];
        $value_percent = $summary['previous_value'] > 0 
            ? ($value_diff / $summary['previous_value']) * 100 
            : 0;
        ?>
        
        <h2>Resultados del Análisis: <?php echo esc_html($this->analysis_data['name']); ?></h2>
        
        <!-- Resumen ejecutivo -->
        <div class="analysis-summary">
            <h3>📊 Resumen Ejecutivo</h3>
            <div class="summary-grid">
                <div class="summary-card">
                    <h3>Total SKUs</h3>
                    <div class="value"><?php echo $summary['total_skus']; ?></div>
                    <?php if ($summary['new_skus'] > 0): ?>
                        <small><?php echo $summary['new_skus']; ?> nuevos</small>
                    <?php endif; ?>
                </div>
                
                <div class="summary-card">
                    <h3>Valor Total</h3>
                    <div class="value">$<?php echo number_format($summary['total_value'], 2); ?></div>
                    <?php if ($value_percent != 0): ?>
                        <small>
                            <?php if ($value_percent > 0): ?>
                                <span style="color: #d32f2f;">
                                    +<?php echo number_format($value_percent, 1); ?>%
                                </span>
                            <?php else: ?>
                                <span style="color: #388e3c;">
                                    <?php echo number_format($value_percent, 1); ?>%
                                </span>
                            <?php endif; ?>
                        </small>
                    <?php endif; ?>
                </div>
                
                <div class="summary-card">
                    <h3>Cambios de Precio</h3>
                    <div class="value">
                        <span style="color: #d32f2f;">↑ <?php echo $summary['price_increases']; ?></span>
                        <span style="color: #388e3c;"> <?php echo $summary['price_decreases']; ?></span>
                    </div>
                </div>
                
                <div class="summary-card">
                    <h3>Alertas</h3>
                    <div class="value">
                        <?php 
                        $total_alerts = 0;
                        foreach ($this->analysis_data['items'] as $item) {
                            $total_alerts += count($item['alerts']);
                        }
                        echo $total_alerts;
                        ?>
                    </div>
                    <?php if ($summary['quality_changes'] > 0): ?>
                        <small><?php echo $summary['quality_changes']; ?> cambios de calidad</small>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Tabla de análisis detallado -->
        <table class="wp-list-table widefat fixed striped analysis-table">
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>Producto</th>
                    <th>Cant.</th>
                    <th>Precio Nuevo</th>
                    <th>Precio Anterior</th>
                    <th>Variación</th>
                    <th>Calidad</th>
                    <th>Alertas</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($this->analysis_data['items'] as $item): 
                    $row_class = '';
                    if ($item['price_trend'] === 'up') $row_class = 'price-up';
                    elseif ($item['price_trend'] === 'down') $row_class = 'price-down';
                    elseif ($item['price_trend'] === 'stable') $row_class = 'price-same';
                    
                    if (!empty($item['alerts'])) $row_class .= ' price-alert';
                ?>
                    <tr class="<?php echo $row_class; ?>">
                        <td><strong><?php echo esc_html($item['sku']); ?></strong></td>
                        <td>
                            <?php echo esc_html($item['brand'] . ' ' . $item['product']); ?>
                            <?php if ($item['status'] === 'new'): ?>
                                <span style="color: #1976d2; font-size: 11px;">NUEVO</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: center;"><?php echo $item['quantity']; ?></td>
                        <td style="text-align: right;">
                            $<?php echo number_format($item['current_price'], 2); ?>
                        </td>
                        <td style="text-align: right;">
                            <?php if (isset($item['last_price'])): ?>
                                $<?php echo number_format($item['last_price'], 2); ?>
                                <br><small style="color: #666;">
                                    <?php echo date('d/m/y', strtotime($item['last_date'])); ?>
                                </small>
                                <?php if (isset($item['last_order']) && !empty($item['last_order'])): ?>
                                    <br><small style="color: #999; font-style: italic;">
                                        <?php echo esc_html($item['last_order']); ?>
                                    </small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="color: #999;">-</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: center;">
                            <?php if (isset($item['price_diff'])): ?>
                                <?php if ($item['price_trend'] === 'up'): ?>
                                    <span style="color: #d32f2f; font-weight: bold;">
                                        ↑ +$<?php echo number_format(abs($item['price_diff']), 2); ?>
                                        <br>(+<?php echo number_format($item['price_percent'], 1); ?>%)
                                    </span>
                                <?php elseif ($item['price_trend'] === 'down'): ?>
                                    <span style="color: #388e3c; font-weight: bold;">
                                        ↓ -$<?php echo number_format(abs($item['price_diff']), 2); ?>
                                        <br>(<?php echo number_format($item['price_percent'], 1); ?>%)
                                    </span>
                                <?php else: ?>
                                    <span style="color: #666;">= Sin cambio</span>
                                <?php endif; ?>
                                
                                <?php if (isset($item['long_trend']) && $item['long_trend'] === 'increasing'): ?>
                                    <br><small style="color: #ff5722;">📈 Tendencia alcista</small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="color: #1976d2;">Nuevo SKU</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: center;">
                            <?php echo esc_html($item['current_quality']); ?>
                            <?php if (isset($item['quality_changed']) && $item['quality_changed']): ?>
                                <br><small style="color: #ff5722;">
                                    Antes: <?php echo esc_html($item['last_quality']); ?>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($item['alerts'])): ?>
                                <?php foreach ($item['alerts'] as $alert): ?>
                                    <span style="color: #d32f2f; font-size: 12px;">
                                        ⚠️ <?php echo esc_html($alert); ?>
                                    </span><br>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <!-- Acciones -->
        <div style="margin-top: 20px;">
            <button type="button" onclick="exportToCSV()" class="button button-primary">
                📥 Exportar Análisis
            </button>
            
            <a href="?page=fc-price-analysis" class="button">
                🗑️ Nuevo Análisis
            </a>
            
            <a href="?page=fc-weight-alerts" class="button" style="float: right;">
                ➡️ Ir a Alertas de Pedidos
            </a>
        </div>
        
        <script>
        function exportToCSV() {
            var data = <?php echo json_encode($this->analysis_data); ?>;
            
            // Función para formatear números con coma decimal
            function formatNumber(num) {
                if (num === null || num === undefined) return '';
                return num.toFixed(2).replace('.', ',');
            }
            
            // Encabezados con punto y coma
            var csv = 'ANÁLISIS DE PRECIOS - ' + data.name + '\n';
            csv += 'Fecha;' + new Date(data.date).toLocaleDateString('es-AR') + '\n';
            csv += '\n';
            csv += 'RESUMEN\n';
            csv += 'Total SKUs;' + data.summary.total_skus + '\n';
            csv += 'Valor Total;$' + formatNumber(data.summary.total_value) + '\n';
            csv += 'Aumentos de Precio;' + data.summary.price_increases + '\n';
            csv += 'Reducciones de Precio;' + data.summary.price_decreases + '\n';
            csv += 'Cambios de Calidad;' + data.summary.quality_changes + '\n';
            csv += 'SKUs Nuevos;' + data.summary.new_skus + '\n';
            csv += '\n';
            csv += 'DETALLE\n';
            
            // Encabezados de la tabla
            csv += 'SKU;Marca;Producto;Cantidad;Precio Nuevo;Precio Anterior;Diferencia $;Diferencia %;Tendencia;Calidad Nueva;Calidad Anterior;Alertas\n';
            
            // Datos
            data.items.forEach(function(item) {
                var row = [];
                
                // SKU
                row.push('"' + item.sku + '"');
                
                // Marca
                row.push('"' + item.brand + '"');
                
                // Producto
                row.push('"' + item.product + '"');
                
                // Cantidad
                row.push(item.quantity);
                
                // Precio Nuevo
                row.push('$' + formatNumber(item.current_price));
                
                // Precio Anterior (con orden)
                if (item.last_price) {
                    var precioAnterior = '$' + formatNumber(item.last_price);
                    if (item.last_order) {
                        precioAnterior += ' (' + item.last_order + ')';
                    }
                    row.push(precioAnterior);
                } else {
                    row.push('N/A');
                }
                
                // Diferencia $
                if (item.price_diff !== undefined) {
                    var sign = item.price_diff >= 0 ? '+' : '';
                    row.push(sign + '$' + formatNumber(Math.abs(item.price_diff)));
                } else {
                    row.push('N/A');
                }
                
                // Diferencia %
                if (item.price_percent !== undefined) {
                    var sign = item.price_percent >= 0 ? '+' : '';
                    row.push(sign + item.price_percent.toFixed(1).replace('.', ',') + '%');
                } else {
                    row.push('N/A');
                }
                
                // Tendencia
                var tendencia = '';
                if (item.price_trend === 'up') tendencia = '↑ SUBE';
                else if (item.price_trend === 'down') tendencia = '↓ BAJA';
                else if (item.price_trend === 'stable') tendencia = '= IGUAL';
                else if (item.price_trend === 'new') tendencia = '★ NUEVO';
                row.push(tendencia);
                
                // Calidad Nueva
                row.push(item.current_quality);
                
                // Calidad Anterior
                row.push(item.last_quality || 'N/A');
                
                // Alertas
                row.push('"' + item.alerts.join(' | ') + '"');
                
                csv += row.join(';') + '\n';
            });
            
            // Agregar totales al final
            csv += '\n';
            csv += 'TOTALES\n';
            csv += ';; ;' + data.summary.total_quantity + ';$' + formatNumber(data.summary.total_value) + '\n';
            
            // Crear y descargar el archivo
            var blob = new Blob(["\ufeff" + csv], { type: 'text/csv;charset=utf-8;' });
            var link = document.createElement("a");
            var url = URL.createObjectURL(blob);
            link.setAttribute("href", url);
            
            // Nombre del archivo con fecha
            var fecha = new Date().toISOString().split('T')[0];
            var filename = "analisis_" + data.name.replace(/\s+/g, '_') + "_" + fecha + ".csv";
            link.setAttribute("download", filename);
            
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
        </script>
        
        <?php
    }
    
    // Exportar análisis
    /*private function export_analysis() {
        if (!isset($_POST['analysis_data'])) return;
        
        $data = json_decode(stripslashes($_POST['analysis_data']), true);
        if (!$data) return;
        
        // Crear archivo Excel con el análisis
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="analisis_' . 
                sanitize_title($data['name']) . '_' . date('Y-m-d') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        $output = fopen('php://output', 'w');
        
        // Encabezados
        fputcsv($output, array(
            'SKU', 
            'Producto', 
            'Cantidad', 
            'Precio Nuevo', 
            'Precio Anterior', 
            'Diferencia $', 
            'Diferencia %', 
            'Calidad Nueva',
            'Calidad Anterior',
            'Alertas'
        ));
        
        // Datos
        foreach ($data['items'] as $item) {
            fputcsv($output, array(
                $item['sku'],
                $item['brand'] . ' ' . $item['product'],
                $item['quantity'],
                $item['current_price'],
                isset($item['last_price']) ? $item['last_price'] : '',
                isset($item['price_diff']) ? $item['price_diff'] : '',
                isset($item['price_percent']) ? $item['price_percent'] . '%' : '',
                $item['current_quality'],
                isset($item['last_quality']) ? $item['last_quality'] : '',
                implode(' | ', $item['alerts'])
            ));
        }
        
        fclose($output);
        exit;
    }*/
}