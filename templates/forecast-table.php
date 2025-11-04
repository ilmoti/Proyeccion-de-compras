<?php
/**
 * Template para la tabla de proyección
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

// Paginacin
$per_page = 50;
$paged = $this->filters['paged'];

// NUEVO: Aumentar límite de tiempo
set_time_limit(300); // 5 minutos
ini_set('memory_limit', '256M'); // Más memoria

// Construir consulta
$args = array(
    'post_type' => array('product', 'product_variation'), // CAMBIO: Agregar product_variation
    'posts_per_page' => $per_page,
    'paged' => $paged,
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

// Construir meta_query correctamente
    $meta_queries = array();
    
    // Obtener configuracin de límites (valores por defecto)
    $limite_critico = get_option('fc_stock_critico', 30); // 30 días por defecto
    $limite_bajo = get_option('fc_stock_bajo', 60); // 60 días por defecto
    
    // NUEVO: Filtro para solo sin stock
    if (!empty($this->filters['solo_sin_stock'])) {
        $meta_queries[] = array(
            'key' => '_stock',
            'value' => '0',
            'compare' => '=',
            'type' => 'NUMERIC'
        );
    }
    
    // NUEVO: Filtro para stock crítico (basado en días de stock)
    if (!empty($this->filters['solo_stock_critico'])) {
        // Por ahora usar un valor fijo, luego lo calcularemos dinámicamente
        $meta_queries[] = array(
            'key' => '_stock',
            'value' => array(1, 10), // Ajustar según productos
            'compare' => 'BETWEEN',
            'type' => 'NUMERIC'
        );
    }
    
    // NUEVO: Filtro para stock bajo
    if (!empty($this->filters['solo_stock_bajo'])) {
        $meta_queries[] = array(
            'key' => '_stock',
            'value' => array(11, 30), // Ajustar según productos
            'compare' => 'BETWEEN',
            'type' => 'NUMERIC'
        );
    }
    
    // Filtro por estado de stock (existente)
    if (!empty($this->filters['stock_status'])) {
        if ($this->filters['stock_status'] == 'out') {
            // Solo productos sin stock
            $meta_queries[] = array(
                'key' => '_stock',
                'value' => '0',
                'compare' => '=',
                'type' => 'NUMERIC'
            );
        } elseif ($this->filters['stock_status'] == 'critical') {
            // Solo productos con stock crítico
            $meta_queries[] = array(
                'key' => '_stock',
                'value' => array(1, 20),
                'compare' => 'BETWEEN',
                'type' => 'NUMERIC'
            );
        }
    }
    
    // Filtro por búsqueda
    if (!empty($this->filters['buscar'])) {
        // Primero intentar buscar por título
        $args['s'] = $this->filters['buscar'];
    }
    
    // Aplicar filtros de stock si existen
    if (!empty($meta_queries) && count($meta_queries) > 0) {  // CAMBIO: > 0 en lugar de > 1
        $args['meta_query'] = $meta_queries;
    }
    
    // Si hay filtros de stock crítico o bajo
    if (!empty($this->filters['solo_stock_critico']) || !empty($this->filters['solo_stock_bajo'])) {
        global $wpdb;
        
        $dias_critico = get_option('fc_stock_critico_dias', 30) / 30;
        $dias_bajo = get_option('fc_stock_bajo_dias', 60) / 30;
        
        $where = "WHERE 1=1";
        
        if (!empty($this->filters['solo_stock_critico'])) {
            $where .= $wpdb->prepare(" AND meses_stock < %f", $dias_critico);
        } elseif (!empty($this->filters['solo_stock_bajo'])) {
            $where .= $wpdb->prepare(" AND meses_stock < %f", $dias_bajo);
        }
        
        $valid_ids = $wpdb->get_col("
            SELECT product_id 
            FROM {$wpdb->prefix}fc_product_metrics 
            $where
        ");
        
        if (!empty($valid_ids)) {
            $args['post__in'] = $valid_ids;
        } else {
            $args['post__in'] = array(0);
        }
    }
    
    // Ejecutar primera búsqueda
    $products = new WP_Query($args);
    
    // Si buscó por título y no encontró nada, buscar por SKU/EAN
    if (!empty($this->filters['buscar']) && $products->found_posts == 0) {
        // Quitar búsqueda por ttulo
        unset($args['s']);
        
        // Buscar por SKU/EAN
        $search_query = array(
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
        
        // Combinar con filtros de stock si existen
        if (!empty($meta_queries) && count($meta_queries) > 0) {  // CAMBIO: > 0 en lugar de > 1
            $args['meta_query'] = array(
                'relation' => 'AND',
                $meta_queries,
                $search_query
            );
        } else {
            $args['meta_query'] = $search_query;
        }
        
        // Buscar de nuevo
        $products = new WP_Query($args);
    }
?>

<h2>Proyeccin de Inventario</h2>

<div class="fc-table-info">
    <p>
        <?php 
        $desde = (($paged - 1) * $per_page) + 1;
        $hasta = min($paged * $per_page, $products->found_posts);
        ?>
        Mostrando <?php echo $desde; ?>-<?php echo $hasta; ?> de <?php echo $products->found_posts; ?> productos
        <?php if (!empty($this->filters['categorias'])): ?>
            | Categorías filtradas: <?php echo count($this->filters['categorias']); ?>
        <?php endif; ?>
    </p>
</div>

<table class="wp-list-table widefat fixed striped fc-forecast-table">
    <thead>
        <tr>
            <th style="width: 40px;">
                <input type="checkbox" id="select-all-products">
            </th>
            <th style="width: 100px;">SKU</th>
            <th>Producto</th>
            <th style="width: 80px;">Stock</th>
            <th style="width: 100px;">
                Ventas
                <?php 
                if (!empty($this->filters['fecha_desde']) && !empty($this->filters['fecha_hasta'])) {
                    echo '<br><small>' . date('d/m', strtotime($this->filters['fecha_desde'])) . ' - ' . date('d/m', strtotime($this->filters['fecha_hasta'])) . '</small>';
                } else {
                    echo '<br><small>(' . $this->filters['periodo'] . ' días)</small>';
                }
                ?>
            </th>
            <th style="width: 80px;">Días<br>Sin Stock</th>
            <th style="width: 100px;">Ventas<br>Perdidas</th>
            <th style="width: 80px;">Promedio<br><?php echo ucfirst($this->filters['tipo_promedio']); ?></th>
            <th style="width: 80px;">Meses<br>Stock</th>
            <th style="width: 80px;">En<br>Camino</th>
            <th style="width: 100px;">Comprar<br>(<?php echo $this->filters['meses_proyeccion']; ?> meses)</th>
            <th style="width: 80px;">Estado</th>
            <th style="width: 100px;">Acciones</th>
        </tr>
    </thead>
    <tbody>
        <tbody>
        <?php 
        if ($products->have_posts()) {
            while ($products->have_posts()) {
                $products->the_post();
                $product_id = get_the_ID();
                $product = wc_get_product($product_id);
                
                // Si es un producto variable, mostrar sus variaciones
                if ($product && $product->is_type('variable')) {
                    $variations = $product->get_children();
                    foreach ($variations as $variation_id) {
                        // Cambiar temporalmente el ID para procesar la variacin
                        $GLOBALS['fc_current_variation_id'] = $variation_id;
                        $GLOBALS['fc_parent_product'] = $product;
                        include FC_PLUGIN_PATH . 'templates/forecast-row.php';
                    }
                } else {
                    // Producto simple
                    $GLOBALS['fc_current_variation_id'] = null;
                    $GLOBALS['fc_parent_product'] = null;
                    include FC_PLUGIN_PATH . 'templates/forecast-row.php';
                }
            }
            wp_reset_postdata();
        } else {
            ?>
            <tr>
                <td colspan="13" style="text-align: center;">
                    No se encontraron productos con los filtros seleccionados.
                </td>
            </tr>
            <?php
        }
        ?>
    </tbody>
</table>
<?php

// Paginación
if ($products->max_num_pages > 1) {
    echo '<div class="tablenav bottom"><div class="tablenav-pages">';
    
    $base_url = admin_url('admin.php?page=fc-projection');
    $query_args = $this->filters;
    unset($query_args['paged']);
    
    // Limpiar argumentos vacíos
    foreach ($query_args as $key => $value) {
        if (empty($value) && $value !== '0') {
            unset($query_args[$key]);
        }
    }
    
    $base_url = add_query_arg($query_args, $base_url);
    
    echo paginate_links(array(
        'base' => $base_url . '%_%',
        'format' => '&paged=%#%',
        'current' => $paged,
        'total' => $products->max_num_pages,
        'prev_text' => '&laquo;',
        'next_text' => '&raquo;'
    ));
    
    echo '</div></div>';
}
?>

<!-- Modal para historial -->
<div id="fc-history-modal" style="display: none;">
    <div class="fc-modal-overlay"></div>
    <div class="fc-modal-content">
        <h3>Historial del Producto</h3>
        <div class="fc-modal-body">
            <div id="fc-history-loading">Cargando...</div>
            <div id="fc-history-data"></div>
        </div>
        <button type="button" class="button fc-modal-close">Cerrar</button>
    </div>
</div>

<style>
.fc-forecast-table th {
    text-align: center;
    font-size: 12px;
}
.fc-table-info {
    margin-bottom: 10px;
    font-style: italic;
}
.fc-modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.6);
    z-index: 999998;
}
.fc-modal-content {
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: white;
    padding: 20px;
    border-radius: 5px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.3);
    z-index: 999999;
    max-width: 600px;
    width: 90%;
    max-height: 80vh;
    overflow-y: auto;
}
/* Estilos mejorados para el modal */
.fc-history-container {
    padding: 10px;
}

.fc-chart-container {
    margin-bottom: 30px;
    padding: 20px;
    background: #f9f9f9;
    border-radius: 8px;
}

.fc-history-table {
    margin-top: 20px;
}

.fc-history-table td {
    padding: 12px 8px;
}

.fc-center {
    text-align: center;
}

.fc-price {
    font-weight: bold;
    font-size: 14px;
}

/* Variaciones de precio */
.fc-price-up {
    color: #d32f2f;
    font-weight: bold;
}

.fc-price-down {
    color: #388e3c;
    font-weight: bold;
}

.fc-price-same {
    color: #666;
}

.fc-row-up {
    background-color: #ffebee !important;
}

.fc-row-down {
    background-color: #e8f5e9 !important;
}

/* Badges de estado */
.fc-badge {
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: bold;
}

.fc-badge-recibido {
    background: #4caf50;
    color: white;
}

/* Grid de estadsticas */
.fc-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 15px;
    margin-top: 20px;
}

.fc-stat-card {
    background: #f5f5f5;
    padding: 15px;
    border-radius: 8px;
    text-align: center;
}

.fc-stat-card h4 {
    margin: 0 0 10px 0;
    font-size: 14px;
    color: #666;
}

.fc-stat-value {
    font-size: 24px;
    font-weight: bold;
    margin: 0;
    color: #333;
}

.fc-stat-detail {
    font-size: 12px;
    color: #666;
    margin: 5px 0 0 0;
}

/* Alertas */
.fc-alert {
    padding: 12px;
    border-radius: 4px;
    margin-top: 15px;
}

.fc-alert-warning {
    background: #fff3cd;
    border: 1px solid #ffeaa7;
    color: #856404;
}
</style>

<script>
jQuery(document).ready(function($) {
    // Nonce para AJAX
    var fc_nonce = '<?php echo wp_create_nonce("fc_ajax_nonce"); ?>';
    
    // Seleccionar todos
    $('#select-all-products').on('change', function() {
        $('.fc-product-checkbox').prop('checked', $(this).is(':checked'));
    });
    
    // Modal de historial
    $('.fc-view-history').on('click', function(e) {
        e.preventDefault();
        var sku = $(this).data('sku');
        
        $('#fc-history-modal').show();
        $('#fc-history-loading').show();
        $('#fc-history-data').hide();
        
        $.post(ajaxurl, {
            action: 'fc_get_product_history',
            sku: sku,
            nonce: fc_nonce
        }, function(response) {
            $('#fc-history-loading').hide();
            if (response.success) {
                // Calcular variaciones de precio
                var precioAnterior = null;
                var maxPrecio = 0;
                var minPrecio = 999999;
                
                // Preparar datos para el gráfico
                var fechas = [];
                var precios = [];
                var cantidades = [];
                
                // Crear HTML mejorado
                var html = '<div class="fc-history-container">';
                
                // Gráfico
                html += '<div class="fc-chart-container">';
                html += '<canvas id="fc-price-chart" height="200"></canvas>';
                html += '</div>';
                
                // Tabla mejorada
                html += '<table class="widefat fc-history-table">';
                html += '<thead><tr>';
                html += '<th>Fecha</th>';
                html += '<th>Orden</th>';
                html += '<th>Cantidad</th>';
                html += '<th>Precio</th>';
                html += '<th>Variación</th>';
                html += '<th>Estado</th>';
                html += '</tr></thead>';
                html += '<tbody>';
                
                $.each(response.data, function(i, order) {
                    var precio = parseFloat(order.precio.replace('$', ''));
                    var variacion = '';
                    var claseVariacion = '';
                    
                    // Calcular variación
                    if (precioAnterior !== null) {
                        var diff = precio - precioAnterior;
                        var porcentaje = (diff / precioAnterior * 100).toFixed(1);
                        
                        if (diff > 0) {
                            variacion = '<span class="fc-price-up">↑ +$' + diff.toFixed(2) + ' (' + porcentaje + '%)</span>';
                            claseVariacion = 'fc-row-up';
                        } else if (diff < 0) {
                            variacion = '<span class="fc-price-down"> $' + diff.toFixed(2) + ' (' + porcentaje + '%)</span>';
                            claseVariacion = 'fc-row-down';
                        } else {
                            variacion = '<span class="fc-price-same">= Sin cambio</span>';
                        }
                    }
                    
                    // Actualizar máximos y mínimos
                    maxPrecio = Math.max(maxPrecio, precio);
                    minPrecio = Math.min(minPrecio, precio);
                    
                    // Agregar datos para el gráfico
                    fechas.push(order.fecha);
                    precios.push(precio);
                    cantidades.push(parseInt(order.cantidad));
                    
                    // Formatear fecha
                    var fecha = new Date(order.fecha.split('/').reverse().join('-'));
                    var fechaFormateada = fecha.toLocaleDateString('es-AR', { day: 'numeric', month: 'short', year: '2-digit' });
                    
                    html += '<tr class="' + claseVariacion + '">';
                    html += '<td>' + fechaFormateada + '</td>';
                    html += '<td>' + (order.order_name || 'Sin nombre') + '</td>';
                    html += '<td class="fc-center">' + order.cantidad + ' un.</td>';
                    html += '<td class="fc-price">$' + precio.toFixed(2) + '</td>';
                    html += '<td>' + (variacion || '-') + '</td>';
                    html += '<td><span class="fc-badge fc-badge-' + order.estado.toLowerCase() + '">' + order.estado + '</span></td>';
                    html += '</tr>';
                    
                    precioAnterior = precio;
                });
                
                html += '</tbody></table>';
                
                // Estadísticas mejoradas
                if (response.stats) {
                    var promedio = parseFloat(response.stats.precio_promedio.replace('$', ''));
                    var rangoPrecios = maxPrecio - minPrecio;
                    var variacionTotal = ((maxPrecio - minPrecio) / minPrecio * 100).toFixed(1);
                    
                    html += '<div class="fc-stats-grid">';
                    html += '<div class="fc-stat-card">';
                    html += '<h4>📊 Precio Promedio</h4>';
                    html += '<p class="fc-stat-value">$' + promedio.toFixed(2) + '</p>';
                    html += '</div>';
                    
                    html += '<div class="fc-stat-card">';
                    html += '<h4>📈 Rango de Precios</h4>';
                    html += '<p class="fc-stat-value">$' + minPrecio.toFixed(2) + ' - $' + maxPrecio.toFixed(2) + '</p>';
                    html += '<p class="fc-stat-detail">Variación: ' + variacionTotal + '%</p>';
                    html += '</div>';
                    
                    html += '<div class="fc-stat-card">';
                    html += '<h4>📦 Total Comprado</h4>';
                    html += '<p class="fc-stat-value">' + response.stats.total_ordenado + ' un.</p>';
                    html += '<p class="fc-stat-detail">en ' + response.stats.ordenes_totales + ' órdenes</p>';
                    html += '</div>';
                    
                    // Alerta de variación significativa
                    if (variacionTotal > 20) {
                        html += '<div class="fc-alert fc-alert-warning">';
                        html += '⚠️ <strong>Alerta:</strong> Los precios han variado más del 20% en el perodo analizado.';
                        html += '</div>';
                    }
                    
                    html += '</div>';
                }
                
                html += '</div>';
                
                $('#fc-history-data').html(html).show();
                
                // Crear el gráfico después de insertar el HTML
                setTimeout(function() {
                    var canvas = document.getElementById('fc-price-chart');
                    if (!canvas) return;
                    
                    var ctx = canvas.getContext('2d');
                    
                    // Destruir gráfico anterior si existe
                    if (window.fcPriceChart) {
                        window.fcPriceChart.destroy();
                    }
                    
                    // Crear nuevo gráfico
                    window.fcPriceChart = new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: fechas.slice().reverse(),
                            datasets: [{
                                label: 'Precio ($)',
                                data: precios.slice().reverse(),
                                borderColor: 'rgb(75, 192, 192)',
                                backgroundColor: 'rgba(75, 192, 192, 0.1)',
                                tension: 0.1,
                                yAxisID: 'y',
                                pointRadius: 4,
                                pointHoverRadius: 6
                            }, {
                                label: 'Cantidad (un.)',
                                data: cantidades.slice().reverse(),
                                borderColor: 'rgb(255, 99, 132)',
                                backgroundColor: 'rgba(255, 99, 132, 0.1)',
                                tension: 0.1,
                                yAxisID: 'y1',
                                pointRadius: 4,
                                pointHoverRadius: 6
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            interaction: {
                                mode: 'index',
                                intersect: false,
                            },
                            plugins: {
                                title: {
                                    display: true,
                                    text: 'Evolución de Precio y Cantidades',
                                    font: {
                                        size: 16
                                    }
                                },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            let label = context.dataset.label || '';
                                            if (label) {
                                                label += ': ';
                                            }
                                            if (context.dataset.label === 'Precio ($)') {
                                                label += '$' + context.parsed.y.toFixed(2);
                                            } else {
                                                label += context.parsed.y + ' un.';
                                            }
                                            return label;
                                        }
                                    }
                                }
                            },
                            scales: {
                                y: {
                                    type: 'linear',
                                    display: true,
                                    position: 'left',
                                    title: {
                                        display: true,
                                        text: 'Precio ($)'
                                    },
                                    ticks: {
                                        callback: function(value) {
                                            return '$' + value.toFixed(2);
                                        }
                                    }
                                },
                                y1: {
                                    type: 'linear',
                                    display: true,
                                    position: 'right',
                                    title: {
                                        display: true,
                                        text: 'Cantidad'
                                    },
                                    grid: {
                                        drawOnChartArea: false,
                                    },
                                    ticks: {
                                        callback: function(value) {
                                            return value + ' un.';
                                        }
                                    }
                                }
                            }
                        }
                    });
                }, 200);
                
            } else {
                $('#fc-history-data').html('<p>' + response.message + '</p>').show();
            }
        });
    });
    
    // Cerrar modal
    $('.fc-modal-close, .fc-modal-overlay').on('click', function() {
        $('#fc-history-modal').hide();
    });
});
</script>