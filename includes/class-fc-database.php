<?php
/**
 * Clase para manejar la base de datos
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

class FC_Database {
    
    // Crear tablas al activar el plugin
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        // Tabla para calidades de productos
        $table_qualities = $wpdb->prefix . 'fc_product_qualities';
        $sql1 = "CREATE TABLE IF NOT EXISTS $table_qualities (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sku VARCHAR(100) NOT NULL,
            quality VARCHAR(50) NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY sku_unique (sku)
        ) $charset_collate;";
        
        // Tabla para órdenes
        $table_orders = $wpdb->prefix . 'fc_orders_history';
        $sql2 = "CREATE TABLE IF NOT EXISTS $table_orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_name VARCHAR(255) NOT NULL, -- NUEVO CAMPO
            sku VARCHAR(100) NOT NULL,
            product_name VARCHAR(255),
            quantity INT NOT NULL,
            purchase_price DECIMAL(10,2),
            quality VARCHAR(50),
            arrival_date DATE,
            status VARCHAR(20) DEFAULT 'pending',
            received_date DATE NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sku (sku),
            INDEX idx_status (status),
            INDEX idx_arrival (arrival_date),
            INDEX idx_order_name (order_name) -- NUEVO NDICE
        ) $charset_collate;";
        
        // Tabla para historial de ventas (para análisis)
        $table_sales = $wpdb->prefix . 'fc_sales_history';
        $sql3 = "CREATE TABLE IF NOT EXISTS $table_sales (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sku VARCHAR(100) NOT NULL,
            product_id INT NOT NULL,
            quantity INT NOT NULL,
            sale_date DATE NOT NULL,
            order_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sku_date (sku, sale_date),
            INDEX idx_product (product_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql1);
        dbDelta($sql2);
        dbDelta($sql3);
        
        // Tabla para períodos sin stock
        $table_stockouts = $wpdb->prefix . 'fc_stockout_periods';
        $sql4 = "CREATE TABLE IF NOT EXISTS $table_stockouts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            sku VARCHAR(100) NOT NULL,
            start_date DATETIME NOT NULL,
            end_date DATETIME NULL,
            days_out INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_product (product_id),
            INDEX idx_dates (start_date, end_date)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql1);
        dbDelta($sql2);
        dbDelta($sql3);
        dbDelta($sql4);
        
        // Tabla para caché de métricas
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}fc_product_metrics (
            product_id BIGINT(20) PRIMARY KEY,
            stock_actual INT DEFAULT 0,
            ventas_30_dias INT DEFAULT 0,
            promedio_diario DECIMAL(10,2) DEFAULT 0,
            meses_stock DECIMAL(10,2) DEFAULT 0,
            ultima_actualizacion DATETIME,
            INDEX idx_meses_stock (meses_stock)
        ) $charset_collate;";
        $wpdb->query($sql);
        
        // NUEVO: Tabla para alertas de peso/CBM
        $table_weight_alerts = $wpdb->prefix . 'fc_weight_alerts';
        $sql5 = "CREATE TABLE IF NOT EXISTS $table_weight_alerts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            type VARCHAR(20) NOT NULL COMMENT 'aereo o maritimo',
            categories TEXT COMMENT 'IDs separados por comas',
            tags TEXT COMMENT 'Tags separados por comas',
            limit_value DECIMAL(10,2) NOT NULL,
            current_value DECIMAL(10,2) DEFAULT 0,
            analysis_days INT NOT NULL DEFAULT 30,
            purchase_months INT NOT NULL DEFAULT 3,
            status VARCHAR(20) DEFAULT 'active',
            last_check DATETIME NULL,
            last_notification DATETIME NULL,
            last_order_date DATETIME NULL,
            cycles_completed INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_type (type)
        ) $charset_collate;";
        
        // NUEVO: Tabla para productos manuales
        $table_manual_products = $wpdb->prefix . 'fc_alert_manual_products';
        $sql6 = "CREATE TABLE IF NOT EXISTS $table_manual_products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            alert_id INT NOT NULL,
            sku VARCHAR(100) NOT NULL,
            brand VARCHAR(100),
            name VARCHAR(255) NOT NULL,
            quantity INT NOT NULL,
            price DECIMAL(10,2),
            quality VARCHAR(50),
            added_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_alert (alert_id),
            FOREIGN KEY (alert_id) REFERENCES $table_weight_alerts(id) ON DELETE CASCADE
        ) $charset_collate;";
        
        // NUEVO: Tabla para historial de alertas
        $table_alert_history = $wpdb->prefix . 'fc_alert_history';
        $sql7 = "CREATE TABLE IF NOT EXISTS $table_alert_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            alert_id INT NOT NULL,
            event_type VARCHAR(50) NOT NULL,
            value_before DECIMAL(10,2),
            value_after DECIMAL(10,2),
            event_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            details TEXT,
            INDEX idx_alert_date (alert_id, event_date),
            FOREIGN KEY (alert_id) REFERENCES $table_weight_alerts(id) ON DELETE CASCADE
        ) $charset_collate;";
        
        dbDelta($sql5);
        dbDelta($sql6);
        dbDelta($sql7);
        
        // Tabla para análisis de stock muerto
        $table_dead_stock = $wpdb->prefix . 'fc_stock_analysis_cache';
        $sql_dead_stock = "CREATE TABLE IF NOT EXISTS $table_dead_stock (
            product_id BIGINT(20) PRIMARY KEY,
            product_name VARCHAR(255),
            sku VARCHAR(100),
            last_sale_date DATE NULL,
            days_without_sale INT DEFAULT 0,
            current_stock INT DEFAULT 0,
            stock_months DECIMAL(10,2) DEFAULT 0,
            sales_trend_30d DECIMAL(5,2) DEFAULT 0,
            immobilized_value DECIMAL(10,2) DEFAULT 0,
            avg_purchase_price DECIMAL(10,2) DEFAULT 0,
            last_purchase_date DATE NULL,
            risk_score INT DEFAULT 0,
            suggested_discount INT DEFAULT 0,
            marked_for_liquidation TINYINT(1) DEFAULT 0,
            notes TEXT,
            last_update DATETIME,
            INDEX idx_days_without_sale (days_without_sale),
            INDEX idx_risk_score (risk_score),
            INDEX idx_stock_months (stock_months)
        ) $charset_collate;";
        
        // Tabla para configuración de emails
        $table_email_config = $wpdb->prefix . 'fc_dead_stock_emails';
        $sql_email_config = "CREATE TABLE IF NOT EXISTS $table_email_config (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL,
            active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) $charset_collate;";
        
        dbDelta($sql_dead_stock);
        dbDelta($sql_email_config);
        
    }
}