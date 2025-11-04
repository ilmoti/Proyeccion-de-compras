🔧 ARCHIVOS PRINCIPALES Y SUS FUNCIONES:
1. forecast-compras.php (Principal)

Función: Archivo principal del plugin
Qué hace:

Inicializa el plugin
Crea los menús del admin
Carga los demás archivos cuando se necesitan
Define las constantes (rutas, versión)



2. class-fc-database.php

Función: Maneja la base de datos
Qué hace:

Crea las tablas al activar el plugin
Tablas: fc_product_qualities, fc_orders_history, fc_sales_history, fc_stockout_periods



3. class-fc-forecast.php

Función: Página principal de proyección
Qué hace:

Renderiza la tabla de proyección de ventas
Maneja los filtros (categorías, búsqueda, período)
Calcula cuánto comprar de cada producto



4. fc-functions.php

Función: Funciones auxiliares
Contiene:

fc_get_product_sales(): Obtiene ventas de un producto
fc_get_product_sales_by_dates(): Ventas por fechas específicas
fc_get_adjusted_sales(): NUEVA - Calcula ventas ajustadas por días sin stock



5. class-fc-import-temp.php

Función: Importar órdenes de compra
Qué hace:

Importa Excel con órdenes de proveedores
Muestra histórico de órdenes
Permite marcar órdenes como recibidas
Eliminar órdenes pendientes



6. class-fc-stock-monitor.php (NUEVO)

Función: Monitor automático de stock
Qué hace:

Detecta cuando un producto llega a stock 0
Registra períodos sin stock
Cierra períodos cuando llega mercadería
Verificación diaria de productos



7. class-fc-export.php

Función: Exportar pedidos
Qué hace:

Genera Excel con el pedido de compras
Formato: SKU | Marca | Producto | QTY | Price USD | Quality



8. class-fc-ajax-handler.php

Función: Maneja peticiones AJAX
Qué hace:

Ver historial de un producto
Actualizar calidad
Marcar órdenes recibidas
Eliminar items



9. class-fc-charts.php

Función: Gráficos y estadísticas
Qué hace:

Muestra gráficos de tendencias
Estadísticas de ventas
Top productos



📋 TEMPLATES (Plantillas):

filters-form.php: Formulario de filtros
forecast-table.php: Tabla principal de proyección
forecast-row.php: Fila individual de la tabla