# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Forecast de Compras WooCommerce** is a WordPress plugin for purchase forecasting and sales projection for WooCommerce stores. It calculates optimal order quantities based on historical sales data, tracks stockouts, manages purchase orders, and provides alerts based on weight/volume constraints for shipping.

**Key Capabilities:**
- Sales forecasting with stockout-adjusted calculations
- Purchase order management with Excel import/export
- Weight/CBM-based purchase alerts (air/maritime shipping)
- Dead stock analysis and product liquidation tracking
- Automatic stock monitoring and metrics calculation

## Architecture

### Core Structure

The plugin follows a **class-based modular architecture** with:
- **Main entry point:** [forecast-compras.php](forecast-compras.php) - Plugin initialization, hooks, and menu registration
- **Class modules:** `/includes/class-*.php` - Feature-specific functionality
- **Templates:** `/templates/*.php` - Reusable view components
- **Utility functions:** [includes/fc-functions.php](includes/fc-functions.php) - Shared helper functions

### Database Tables

Created by [class-fc-database.php](includes/class-fc-database.php):

- `wp_fc_product_qualities` - Product quality ratings (A+, A, B, C)
- `wp_fc_orders_history` - Purchase orders with status tracking
- `wp_fc_stockout_periods` - Automatic stockout period tracking
- `wp_fc_weight_alerts` - Weight/CBM-based purchase alerts
- `wp_fc_alert_manual_products` - Manual products added to alerts
- `wp_fc_stock_analysis_cache` - Dead stock analysis cache
- `wp_fc_product_metrics` - Cached metrics (stock, sales velocity)

### Key Architectural Patterns

**Stockout-Adjusted Sales Calculation:**
The system tracks periods when products are out of stock and adjusts sales projections accordingly. When calculating average daily sales, it divides total sales by days WITH stock available, not total calendar days. This prevents underestimating demand.

**Alert Lifecycle States:**
- `active` - Monitoring products, accumulating weight/CBM
- `ready` - Reached limit, ready to export order
- `ordered` - Order placed, waiting for import
- `finalized` - Order imported and received

**Monitor Classes:**
Background monitoring classes extend the plugin functionality:
- [FC_Stock_Monitor](includes/class-fc-stock-monitor.php) - Detects stockouts via WooCommerce stock change hooks
- [FC_Weight_Monitor](includes/class-fc-weight-monitor.php) - Daily cron job checking alert thresholds
- [FC_Dead_Stock_Notifications](includes/class-fc-dead-stock-notifications.php) - Identifies slow-moving inventory

## Key Technical Patterns

### Meta Keys Used
- `_alg_ean` - Primary SKU (used for matching with Excel imports)
- `_sku` - Alternative SKU
- `_stock` - WooCommerce stock quantity
- `_weight` - Product weight in kg
- `_length`, `_width`, `_height` - Dimensions in cm

### Sales Data Query Pattern
Sales are calculated from WooCommerce order items with statuses `wc-completed`, `wc-processing`, or `wc-shipped`. The query joins order items with meta data to match product IDs and variations, then sums quantities.

See [fc_get_product_sales()](includes/fc-functions.php) and [fc_get_adjusted_sales()](includes/fc-functions.php) for implementation.

### Category Multiples Configuration
Products can have category-specific order multiples (e.g., order in multiples of 6). Configuration stored in WordPress option `fc_category_multiples` with structure:
```php
[category_id => ['multiple' => 6, 'min_exact' => 12]]
```
If quantity needed ≤ `min_exact`, order exact amount; otherwise round up to multiple.

## Development Workflow

### Common Commands

**Activate plugin in WordPress:**
Navigate to WordPress admin → Plugins → Activate "Forecast de Compras WooCommerce"

**Check database tables:**
Run SQL via phpMyAdmin or WordPress CLI:
```sql
SHOW TABLES LIKE 'wp_fc_%';
```

**Test cron jobs:**
```php
// In wp-admin or via WP-CLI
do_action('fc_daily_metrics_update');
do_action('fc_check_weight_alerts');
```

### Excel Import Format

Orders are imported via [FC_Import](includes/class-fc-import-temp.php) with expected columns:
- SKU (matched against `_alg_ean` or `_sku`)
- Product Name
- Quantity
- Price
- Quality
- Arrival Date (optional)

Library used: [SimpleXLSX.php](includes/SimpleXLSX.php) for reading, [SimpleXLSXGen.php](includes/SimpleXLSXGen.php) for writing.

### AJAX Handler Pattern

All AJAX requests are handled by [FC_Ajax_Handler](includes/class-fc-ajax-handler.php) which registers actions like:
- `fc_get_product_history` - Fetch sales history
- `fc_update_quality` - Update product quality
- `fc_mark_received` - Mark order as received
- `fc_delete_order_item` - Delete order line

## Important Business Logic

### Stockout Adjustment Algorithm

Located in [fc_get_adjusted_sales()](includes/fc-functions.php):
1. Get total sales in period (e.g., 60 days)
2. Query `fc_stockout_periods` for days without stock in same period
3. Calculate: `avg_daily_sales = total_sales / (period_days - stockout_days)`
4. Estimate lost sales: `lost_sales ≈ avg_daily_sales × stockout_days`

This ensures purchase quantities reflect true demand, not artificially low sales during stockouts.

### Weight/CBM Alert Monitoring

[FC_Weight_Monitor](includes/class-fc-weight-monitor.php) runs daily via WordPress cron:
1. Find all `active` alerts
2. For each alert, query products matching categories/tags (excluding excluded tags)
3. Calculate quantity needed based on `analysis_days` and `purchase_months`
4. Sum total weight (kg) or CBM (L×W×H/1000000)
5. If current_value ≥ limit_value, send email and change status to `ready`

### Dead Stock Risk Scoring

[FC_Dead_Stock_Calculator](includes/class-fc-dead-stock-calculator.php) calculates risk scores (0-100) based on:
- Days without sales (higher = more risk)
- Current stock level vs. sales velocity
- Inventory holding costs
- Price trend analysis

Products with high risk scores are flagged for liquidation with suggested discount percentages.

## Working with the Codebase

### Adding a New Admin Page

1. Add menu item in [forecast-compras.php](forecast-compras.php) `fc_setup_admin_menu()`
2. Create render function (e.g., `fc_render_my_page()`)
3. Create class file in `/includes/class-fc-my-feature.php`
4. Create template in `/templates/my-template.php` if needed

### Extending Database Schema

1. Add table creation SQL in [FC_Database::create_tables()](includes/class-fc-database.php)
2. Use `dbDelta()` for schema updates (it handles existing tables)
3. Run migration manually or trigger via plugin activation hook

### Adding New Filters to Forecast Page

The forecast system uses a filter array pattern. Modify:
1. [FC_Forecast::get_filters()](includes/class-fc-forecast.php) to capture new GET parameters
2. [templates/filters-form.php](templates/filters-form.php) to add UI controls
3. [templates/forecast-table.php](templates/forecast-table.php) to apply filters in product query

## Dependencies

- **WordPress 5.0+**
- **WooCommerce 3.0+** (required - plugin checks for activation)
- **PHP 7.4+**
- **SimpleXLSX** (bundled) - Excel file handling
- **Chart.js** (CDN) - Data visualization

## Plugin Constants

Defined in [forecast-compras.php](forecast-compras.php):
- `FC_PLUGIN_PATH` - Absolute path to plugin directory
- `FC_PLUGIN_URL` - URL to plugin directory
- `FC_PLUGIN_VERSION` - Current version (for cache busting)

## Known Limitations

- SKU matching requires exact match on `_alg_ean` or `_sku` meta fields
- Sales calculations only include orders with specific statuses (completed/processing/shipped)
- Stockout tracking requires the monitor class to be running; historical stockouts before activation are not tracked
- Excel imports are processed synchronously (may timeout on very large files)

## Future Development Areas

See [Pendientes.txt](Pendientes.txt) for planned features:
- Enhanced dashboard with unified metrics
- Automatic reorder point calculations with lead times
- Supplier management and price comparison
- Advanced filtering by tags and suppliers
- Product variation SKU handling improvements
