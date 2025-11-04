<?php
/**
 * Template para el formulario de filtros
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Obtener categorías para el selector
$categories = get_terms(array(
    'taxonomy' => 'product_cat',
    'hide_empty' => false,
    'orderby' => 'name',
    'order' => 'ASC'
));

$selected_count = count($this->filters['categorias']);
?>

<div style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccc;">
    <form method="get" action="">
    <input type="hidden" name="page" value="fc-projection">
        
        <table class="form-table">
            <tr>
                <th>Categorías:</th>
                <td>
                    <div class="fc-category-selector">
                        <button type="button" class="button" id="toggle-categories">
                            <span class="dashicons dashicons-category"></span>
                            <?php 
                            if ($selected_count > 0) {
                                echo $selected_count . ' categorías seleccionadas';
                            } else {
                                echo 'Seleccionar categorías';
                            }
                            ?>
                            <span class="dashicons dashicons-arrow-down-alt2"></span>
                        </button>
                        
                        <div id="category-dropdown" style="display: none; position: absolute; z-index: 999; background: white; border: 1px solid #ccc; border-radius: 4px; box-shadow: 0 2px 5px rgba(0,0,0,0.2); margin-top: 5px; width: 300px;">
                            <div style="padding: 10px; border-bottom: 1px solid #eee;">
                                <label style="font-weight: bold; display: block; margin-bottom: 5px;">
                                    <input type="checkbox" id="select-all-cats"> Seleccionar todas
                                </label>
                                <input type="text" id="search-cats" placeholder="Buscar categorías..." style="width: 100%; padding: 5px;">
                            </div>
                            
                            <div style="max-height: 250px; overflow-y: auto; padding: 10px;">
                                <?php foreach ($categories as $cat): ?>
                                    <?php
                                    $nivel = count(get_ancestors($cat->term_id, 'product_cat'));
                                    $padding = $nivel * 20;
                                    ?>
                                    <label class="cat-item" style="display: block; padding: 3px 0; margin-left: <?php echo $padding; ?>px; cursor: pointer;">
                                        <input type="checkbox" name="categorias[]" value="<?php echo $cat->term_id; ?>" 
                                               <?php checked(in_array($cat->term_id, $this->filters['categorias'])); ?>>
                                        <span class="cat-name"><?php echo esc_html($cat->name); ?></span>
                                        <span style="color: #999; font-size: 12px;">(<?php echo $cat->count; ?>)</span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            
                            <div style="padding: 10px; border-top: 1px solid #eee; text-align: right;">
                                <button type="button" class="button button-small" id="close-categories">Cerrar</button>
                            </div>
                        </div>
                    </div>
                </td>
                <th>Buscar:</th>
                <td>
                    <input type="text" name="buscar" value="<?php echo esc_attr($this->filters['buscar']); ?>" placeholder="SKU o nombre...">
                </td>
            </tr>
            <tr>
                <th>Período de análisis:</th>
                <td>
                    <select name="periodo" id="periodo_select">
                        <option value="30" <?php selected($this->filters['periodo'], 30); ?>>Últimos 30 días</option>
                        <option value="60" <?php selected($this->filters['periodo'], 60); ?>>Últimos 60 días</option>
                        <option value="90" <?php selected($this->filters['periodo'], 90); ?>>Últimos 90 días</option>
                        <option value="180" <?php selected($this->filters['periodo'], 180); ?>>Últimos 6 meses</option>
                        <option value="custom" <?php echo (!empty($this->filters['fecha_desde']) || !empty($this->filters['fecha_hasta'])) ? 'selected' : ''; ?>>Personalizado</option>
                    </select>
                </td>
                <th>Proyectar para:</th>
                <td>
                    <input type="number" name="meses_proyeccion" value="<?php echo $this->filters['meses_proyeccion']; ?>" min="1" max="12" style="width: 60px;"> meses
                </td>
            </tr>
                <tr>
                    <th>Filtrar por stock:</th>
                    <td>
                        <label>
                            <input type="checkbox" name="solo_sin_stock" value="1" <?php checked(!empty($this->filters['solo_sin_stock'])); ?>>
                            Mostrar solo productos sin stock (0)
                        </label>
                        <br>
                        <label>
                            <input type="checkbox" name="solo_stock_critico" value="1" <?php checked(!empty($this->filters['solo_stock_critico'])); ?>>
                            Mostrar solo stock crítico
                        </label>
                        <br>
                        <label>
                            <input type="checkbox" name="solo_stock_bajo" value="1" <?php checked(!empty($this->filters['solo_stock_bajo'])); ?>>
                            Mostrar solo stock bajo
                        </label>
                    </td>
                    <th></th>
                    <td></td>
                </tr>
            <tr id="fechas_custom" style="<?php echo (empty($this->filters['fecha_desde']) && empty($this->filters['fecha_hasta'])) ? 'display:none;' : ''; ?>">
                <th>Fechas personalizadas:</th>
                <td colspan="3">
                    Desde: <input type="date" name="fecha_desde" value="<?php echo esc_attr($this->filters['fecha_desde']); ?>">
                    Hasta: <input type="date" name="fecha_hasta" value="<?php echo esc_attr($this->filters['fecha_hasta']); ?>">
                </td>
            </tr>
            <tr>
                <th>Mostrar promedio:</th>
                <td colspan="3">
                    <select name="tipo_promedio">
                        <option value="diario" <?php selected($this->filters['tipo_promedio'], 'diario'); ?>>Diario</option>
                        <option value="semanal" <?php selected($this->filters['tipo_promedio'], 'semanal'); ?>>Semanal</option>
                        <option value="mensual" <?php selected($this->filters['tipo_promedio'], 'mensual'); ?>>Mensual</option>
                    </select>
                </td>
                <tr>
                    <th>Tags:</th>
                    <td>
                        <?php
                        $product_tags = get_terms(array(
                            'taxonomy' => 'product_tag',
                            'hide_empty' => false
                        ));
                        ?>
                        <select name="tags[]" multiple style="width: 300px; height: 100px;">
                            <?php foreach ($product_tags as $tag): ?>
                                <option value="<?php echo $tag->term_id; ?>" 
                                    <?php echo in_array($tag->term_id, $this->filters['tags'] ?? []) ? 'selected' : ''; ?>>
                                    <?php echo esc_html($tag->name); ?> (<?php echo $tag->count; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Mantén Ctrl para seleccionar múltiples tags</p>
                    </td>
                    <th>Proveedor:</th>
                    <td>
                        <input type="text" name="proveedor" value="<?php echo esc_attr($this->filters['proveedor'] ?? ''); ?>" 
                               placeholder="Nombre del proveedor...">
                    </td>
                </tr>
            </tr>
        </table>
        
        <p class="submit">
            <button type="submit" class="button button-primary">Aplicar Filtros</button>
            <a href="?page=fc-projection" class="button">Limpiar</a>
        </p>
    </form>
</div>

<style>
.fc-category-selector {
    position: relative;
    display: inline-block;
}
.fc-category-selector .button {
    display: flex;
    align-items: center;
    gap: 5px;
}
.cat-item:hover {
    background-color: #f0f0f0;
}
.cat-item input[type="checkbox"] {
    margin-right: 5px;
}
#category-dropdown {
    min-width: 300px;
}
</style>

<script>
jQuery(document).ready(function($) {
    // Perodo personalizado
    $('#periodo_select').change(function() {
        if ($(this).val() === 'custom') {
            $('#fechas_custom').show();
        } else {
            $('#fechas_custom').hide();
            $('input[name="fecha_desde"]').val('');
            $('input[name="fecha_hasta"]').val('');
        }
    });
    
    // Toggle categorías
    $('#toggle-categories').click(function(e) {
        e.preventDefault();
        $('#category-dropdown').toggle();
    });
    
    // Cerrar categorías
    $('#close-categories').click(function() {
        $('#category-dropdown').hide();
        // Actualizar texto del botón
        var count = $('input[name="categorias[]"]:checked').length;
        var buttonText = count > 0 ? count + ' categorías seleccionadas' : 'Seleccionar categorías';
        $('#toggle-categories').html('<span class="dashicons dashicons-category"></span> ' + buttonText + ' <span class="dashicons dashicons-arrow-down-alt2"></span>');
    });
    
    // Cerrar al hacer clic fuera
    $(document).click(function(e) {
        if (!$(e.target).closest('.fc-category-selector').length) {
            $('#category-dropdown').hide();
        }
    });
    
    // Seleccionar todas
    $('#select-all-cats').change(function() {
        $('input[name="categorias[]"]').prop('checked', $(this).is(':checked'));
    });
    
    // Buscar categorías
    $('#search-cats').on('keyup', function() {
        var value = $(this).val().toLowerCase();
        $('.cat-item').each(function() {
            var text = $(this).find('.cat-name').text().toLowerCase();
            $(this).toggle(text.indexOf(value) > -1);
        });
    });
    
    // Actualizar contador cuando se selecciona/deselecciona
    $('input[name="categorias[]"]').change(function() {
        if (!$(this).is(':checked')) {
            $('#select-all-cats').prop('checked', false);
        }
    });
});
</script>