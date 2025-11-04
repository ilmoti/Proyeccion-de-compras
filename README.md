# Forecast de Compras WooCommerce

Plugin de WordPress para proyección de compras y gestión de inventario para tiendas WooCommerce.

## 🎯 Características Principales

- **Proyección de Ventas Ajustada**: Calcula ventas considerando períodos sin stock
- **Gestión de Órdenes**: Importa y gestiona órdenes de compra desde Excel
- **Alertas de Peso/CBM**: Sistema de alertas para pedidos aéreos y marítimos
- **Análisis de Stock Muerto**: Identifica productos sin rotación
- **Dashboard Consolidado**: Vista unificada de todas las métricas
- **Múltiplos de Pedido**: Configuración por categoría

## 📦 Instalación

### Via WP Pusher (Recomendado)

1. Instala [WP Pusher](https://wppusher.com/) en tu WordPress
2. Ve a **WP Pusher → Install Plugin**
3. Configura:
   - **Repository**: `ilmoti/Proyeccion-de-compras`
   - **Branch**: `main`
   - **Subdirectory**: `forecast-compras`
4. Click en **Install Plugin**

### Via FTP/Manual

1. Descarga el repositorio
2. Sube la carpeta `forecast-compras` a `/wp-content/plugins/`
3. Activa el plugin desde WordPress Admin → Plugins

## ⚙️ Requisitos

- WordPress 5.0+
- WooCommerce 3.0+
- PHP 7.4+

## 📚 Documentación

Ver [CLAUDE.md](forecast-compras/CLAUDE.md) para documentación técnica detallada.

## 🔧 Configuración Inicial

1. Activa el plugin
2. Ve a **Forecast Dashboard → Configuración**
3. Configura múltiplos de pedido por categoría
4. Click en **Actualizar Métricas** para calcular datos iniciales

## 🚀 Uso

### Proyección de Compras
1. **Forecast Dashboard → Proyección Detallada**
2. Filtra por categorías, período, etc.
3. Revisa las cantidades sugeridas
4. Exporta a Excel

### Alertas de Peso
1. **Forecast Dashboard → Alertas Pedidos**
2. Crea nueva alerta con límite de kg o CBM
3. El sistema monitorea automáticamente
4. Genera Excel cuando alcanza el límite

### Importar Órdenes
1. **Forecast Dashboard → Importar Órdenes**
2. Sube archivo Excel con formato:
   - SKU | Producto | Cantidad | Precio | Calidad | Fecha
3. El sistema actualiza stock en camino

## 🐛 Corrección de Problemas

Si encuentras períodos sin stock incorrectos:

1. Descarga `fix-all-open-periods.php` del repositorio
2. Súbelo a la raíz de WordPress
3. Ejecuta: `https://tu-sitio.com/fix-all-open-periods.php`
4. Borra el archivo después

## 📝 Changelog

### v5.1 (2025-11-04)
- ✅ Corrección del algoritmo de cálculo de días sin stock
- ✅ Mejora en la precisión de proyecciones
- ✅ Scripts de corrección para períodos incorrectos

## 🤝 Contribuir

1. Fork el proyecto
2. Crea tu feature branch (`git checkout -b feature/AmazingFeature`)
3. Commit cambios (`git commit -m 'Add AmazingFeature'`)
4. Push al branch (`git push origin feature/AmazingFeature`)
5. Abre un Pull Request

## 📄 Licencia

Propietario: WiFix Development

## 🆘 Soporte

Para problemas o preguntas, abre un [Issue](https://github.com/ilmoti/Proyeccion-de-compras/issues)
