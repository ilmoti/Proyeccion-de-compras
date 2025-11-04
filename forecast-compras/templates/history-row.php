<?php
/**
 * Template para una fila del histórico
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}
?>
<tr>
    <td><?php echo $order->id; ?></td>
    <td><?php echo esc_html($order->sku); ?></td>
    <td><?php echo esc_html($order->product_name); ?></td>
    <td style="text-align: center;"><?php echo esc_html($order->quantity); ?></td>
    <td style="text-align: right;">$<?php echo number_format($order->purchase_price, 2); ?></td>
    <td><?php echo esc_html($order->quality); ?></td>
    <td><?php echo date('d/m/Y', strtotime($order->arrival_date)); ?></td>
    <td>
        <?php if ($order->status == 'pending'): ?>
            <span class="dashicons dashicons-clock" style="color: orange;"></span> Pendiente
        <?php else: ?>
            <span class="dashicons dashicons-yes" style="color: green;"></span> Recibida
            <?php if ($order->received_date): ?>
                <br><small><?php echo date('d/m/Y', strtotime($order->received_date)); ?></small>
            <?php endif; ?>
        <?php endif; ?>
    </td>
    <td>
        <?php if ($order->status == 'pending'): ?>
            <form method="post" style="display: inline;">
                <?php wp_nonce_field('fc_mark_received_' . $order->id, 'fc_nonce'); ?>
                <input type="hidden" name="fc_action" value="mark_received">
                <input type="hidden" name="order_id" value="<?php echo $order->id; ?>">
                <button type="submit" class="button button-small">Marcar Recibida</button>
            </form>
        <?php endif; ?>
    </td>
</tr>