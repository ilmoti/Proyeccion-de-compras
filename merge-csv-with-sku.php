<?php
/**
 * Agregar columna SKU al CSV de movimientos
 * Une los dos archivos CSV para tener el código en cada línea
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(300);

header('Content-Type: text/plain; charset=utf-8');

echo "========== AGREGAR SKU AL CSV DE MOVIMIENTOS ==========\n\n";

$file_movimientos = __DIR__ . '/Listado de movimientos.csv';
$file_productos = __DIR__ . '/Productosycodigos.csv';
$file_output = __DIR__ . '/Movimientos-con-SKU.csv';

if (!file_exists($file_movimientos)) {
    die("❌ No se encuentra: {$file_movimientos}\n");
}

if (!file_exists($file_productos)) {
    die("❌ No se encuentra: {$file_productos}\n");
}

// 1. Leer mapeo de productos -> códigos
echo "1. Leyendo mapeo de productos y códigos...\n";
$productos_map = array();

if (($handle = fopen($file_productos, 'r')) !== false) {
    $is_first = true;
    while (($data = fgetcsv($handle, 1000, ';')) !== false) {
        if ($is_first) {
            $is_first = false;
            continue;
        }

        if (count($data) >= 2) {
            $nombre = trim($data[0]);
            $codigo = trim($data[1]);

            if (!empty($nombre)) {
                $productos_map[$nombre] = $codigo;
            }
        }
    }
    fclose($handle);
}

echo "   ✅ " . count($productos_map) . " productos mapeados\n\n";

// 2. Procesar CSV de movimientos y agregar SKU
echo "2. Procesando movimientos y agregando SKU...\n";

$output_handle = fopen($file_output, 'w');

// Escribir header
fputcsv($output_handle, ['Producto', 'SKU', 'Fecha', 'Ingreso', 'Egreso', 'Precio Venta', 'Monto Venta', 'Stock'], ';');

$lineas_procesadas = 0;
$con_sku = 0;
$sin_sku = 0;

if (($handle = fopen($file_movimientos, 'r')) !== false) {
    $is_first = true;

    while (($data = fgetcsv($handle, 10000, ';')) !== false) {
        if ($is_first) {
            $is_first = false;
            continue; // Skip header
        }

        $nombre = trim($data[0]);
        $fecha = isset($data[1]) ? trim($data[1]) : '';
        $ingreso = isset($data[2]) ? trim($data[2]) : '';
        $egreso = isset($data[3]) ? trim($data[3]) : '';
        $precio = isset($data[4]) ? trim($data[4]) : '';
        $monto = isset($data[5]) ? trim($data[5]) : '';
        $stock = isset($data[6]) ? trim($data[6]) : '';

        // Buscar SKU
        $sku = isset($productos_map[$nombre]) ? $productos_map[$nombre] : '';

        if (!empty($sku)) {
            $con_sku++;
        } else {
            $sin_sku++;
        }

        // Escribir línea con SKU
        fputcsv($output_handle, [
            $nombre,
            $sku,
            $fecha,
            $ingreso,
            $egreso,
            $precio,
            $monto,
            $stock
        ], ';');

        $lineas_procesadas++;

        if ($lineas_procesadas % 1000 == 0) {
            echo "   Procesadas {$lineas_procesadas} líneas...\n";
        }
    }

    fclose($handle);
}

fclose($output_handle);

echo "\n========== ✅ COMPLETADO ==========\n";
echo "Líneas procesadas: {$lineas_procesadas}\n";
echo "Con SKU: {$con_sku}\n";
echo "Sin SKU: {$sin_sku}\n";
echo "\nArchivo generado: {$file_output}\n";
echo "\nAhora reemplazá 'Listado de movimientos.csv' con este nuevo archivo.\n";
