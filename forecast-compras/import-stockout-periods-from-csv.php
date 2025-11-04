<?php
/**
 * Importar períodos de stockout desde CSVs de movimientos
 *
 * Lee los archivos:
 * - Listado de movimientos.csv (movimientos de stock)
 * - Productosycodigos.csv (mapeo nombre -> _alg_ean)
 *
 * Calcula períodos donde stock = 0 y los inserta en la DB
 */

require_once 'wp-load.php';
global $wpdb;

set_time_limit(600);
ini_set('max_execution_time', 600);
ini_set('memory_limit', '512M');

header('Content-Type: text/plain; charset=utf-8');

echo "========== IMPORTAR PERÍODOS DE STOCKOUT DESDE CSV ==========\n\n";

// Buscar archivos en la carpeta del plugin
$file_movimientos = __DIR__ . '/Listado de movimientos.csv';
$file_productos = __DIR__ . '/Productosycodigos.csv';

// Si no existen, buscar en la raíz de WordPress
if (!file_exists($file_movimientos)) {
    $file_movimientos = ABSPATH . 'Listado de movimientos.csv';
}
if (!file_exists($file_productos)) {
    $file_productos = ABSPATH . 'Productosycodigos.csv';
}

if (!file_exists($file_movimientos)) {
    die("❌ No se encuentra el archivo: {$file_movimientos}\n");
}

if (!file_exists($file_productos)) {
    die("❌ No se encuentra el archivo: {$file_productos}\n");
}

// 1. LEER MAPEO DE PRODUCTOS -> CÓDIGOS
echo "1. Leyendo mapeo de productos...\n";
$productos_map = array();

if (($handle = fopen($file_productos, 'r')) !== false) {
    $is_first = true;
    while (($data = fgetcsv($handle, 1000, ';')) !== false) {
        if ($is_first) {
            $is_first = false;
            continue; // Skip header
        }

        if (count($data) >= 2) {
            $nombre = trim($data[0]);
            $codigo = trim($data[1]);

            if (!empty($nombre) && !empty($codigo)) {
                $productos_map[$nombre] = $codigo;
            }
        }
    }
    fclose($handle);
}

echo "   ✅ " . count($productos_map) . " productos con código mapeados\n\n";

// 2. LEER MOVIMIENTOS Y CALCULAR PERÍODOS
echo "2. Procesando movimientos...\n\n";

$table_stockouts = $wpdb->prefix . 'fc_stockout_periods';

// IMPORTANTE: Borrar todos los períodos existentes para empezar de cero
echo "   ⚠️ Eliminando períodos existentes...\n";
$wpdb->query("TRUNCATE TABLE $table_stockouts");
echo "   ✅ Tabla limpiada\n\n";

$current_product = null;
$current_product_name = null;
$current_sku = null;
$current_product_id = null;
$stockout_start = null;
$movimientos = array();

$periodos_creados = 0;
$productos_procesados = 0;
$productos_sin_codigo = 0;
$productos_sin_id = 0;

if (($handle = fopen($file_movimientos, 'r')) !== false) {
    $is_first = true;

    while (($data = fgetcsv($handle, 10000, ';')) !== false) {
        if ($is_first) {
            $is_first = false;
            continue; // Skip header
        }

        $nombre = trim($data[0]);
        $fecha = isset($data[1]) ? trim($data[1]) : '';
        $ingreso = isset($data[2]) ? str_replace(',', '.', trim($data[2])) : '0';
        $egreso = isset($data[3]) ? str_replace(',', '.', trim($data[3])) : '0';
        $stock = isset($data[6]) ? str_replace(',', '.', trim($data[6])) : '0';

        // Si cambia el producto, procesar el anterior
        if ($nombre !== $current_product_name && $current_product_name !== null) {
            // Procesar movimientos del producto anterior
            procesarProducto($current_product_name, $current_sku, $current_product_id, $movimientos, $table_stockouts, $wpdb, $periodos_creados);
            $productos_procesados++;
            $movimientos = array();
        }

        // Si es una nueva línea de producto (sin fecha = línea de cabecera con stock inicial)
        if (empty($fecha)) {
            $current_product_name = $nombre;

            // Buscar código del producto
            if (isset($productos_map[$nombre])) {
                $current_sku = $productos_map[$nombre];

                // Buscar product_id en WordPress
                $current_product_id = $wpdb->get_var($wpdb->prepare("
                    SELECT post_id
                    FROM {$wpdb->postmeta}
                    WHERE meta_key = '_alg_ean'
                    AND meta_value = %s
                    LIMIT 1
                ", $current_sku));

                if (!$current_product_id) {
                    $productos_sin_id++;
                    echo "   ⚠️ Producto sin ID en WP: {$nombre} (SKU: {$current_sku})\n";
                }
            } else {
                $current_sku = null;
                $current_product_id = null;
                $productos_sin_codigo++;
            }

            // Agregar stock inicial
            $movimientos[] = array(
                'fecha' => null,
                'stock' => floatval($stock)
            );
        } else {
            // Movimiento con fecha
            $movimientos[] = array(
                'fecha' => $fecha,
                'stock' => floatval($stock)
            );
        }

        $current_product_name = $nombre;
    }

    // Procesar el último producto
    if ($current_product_name !== null) {
        procesarProducto($current_product_name, $current_sku, $current_product_id, $movimientos, $table_stockouts, $wpdb, $periodos_creados);
        $productos_procesados++;
    }

    fclose($handle);
}

echo "\n\n========== ✅ COMPLETADO ==========\n";
echo "Productos procesados: {$productos_procesados}\n";
echo "Períodos de stockout creados: {$periodos_creados}\n";
echo "Productos sin código _alg_ean: {$productos_sin_codigo}\n";
echo "Productos sin ID en WordPress: {$productos_sin_id}\n";
echo "\n⚠️ PRÓXIMO PASO:\n";
echo "Ve a WordPress → Forecast Dashboard → Configuración\n";
echo "Click en 'Actualizar Métricas'\n";

/**
 * Procesar movimientos de un producto y crear períodos de stockout
 */
function procesarProducto($nombre, $sku, $product_id, $movimientos, $table_stockouts, $wpdb, &$periodos_creados) {
    if (empty($sku) || empty($product_id)) {
        return; // Skip productos sin código o sin ID
    }

    $stockout_start = null;
    $last_fecha = null;

    foreach ($movimientos as $mov) {
        $fecha = $mov['fecha'];
        $stock = $mov['stock'];

        // Si el stock llega a 0 o menos
        if ($stock <= 0) {
            // Si no estamos en un período de stockout, iniciarlo
            if ($stockout_start === null && $fecha !== null) {
                $stockout_start = $fecha;
            }
        } else {
            // Si hay stock y estábamos en período de stockout, cerrarlo
            if ($stockout_start !== null && $fecha !== null) {
                // Calcular días
                $dias = calcularDias($stockout_start, $fecha);

                // Insertar período
                $wpdb->insert(
                    $table_stockouts,
                    array(
                        'product_id' => $product_id,
                        'sku' => $sku,
                        'start_date' => $stockout_start,
                        'end_date' => $fecha,
                        'days_out' => $dias
                    ),
                    array('%d', '%s', '%s', '%s', '%d')
                );

                $periodos_creados++;
                $stockout_start = null;
            }
        }

        if ($fecha !== null) {
            $last_fecha = $fecha;
        }
    }

    // Si el producto termina sin stock, crear período abierto
    if ($stockout_start !== null && $last_fecha !== null) {
        $dias = calcularDias($stockout_start, date('Y-m-d'));

        $wpdb->insert(
            $table_stockouts,
            array(
                'product_id' => $product_id,
                'sku' => $sku,
                'start_date' => $stockout_start,
                'end_date' => null,
                'days_out' => $dias
            ),
            array('%d', '%s', '%s', '%s', '%d')
        );

        $periodos_creados++;
    }
}

/**
 * Calcular días entre dos fechas
 */
function calcularDias($fecha_inicio, $fecha_fin) {
    $d1 = new DateTime($fecha_inicio);
    $d2 = new DateTime($fecha_fin);
    return $d1->diff($d2)->days;
}
