<?php

require_once __DIR__ . '/mailer.php';

function orderEmailMoney($value): string
{
    return '&#8369;' . number_format((float) $value, 2);
}

function orderEmailPlainMoney($value): string
{
    return 'PHP ' . number_format((float) $value, 2);
}

function orderEmailPaymentLabel($method): string
{
    return $method === 'store_pickup' ? 'Store Pick Up' : 'Cash on Delivery';
}

function orderEmailFulfillmentLabel($method): string
{
    return $method === 'store_pickup' ? 'Store Pickup' : 'Delivery';
}

function orderEmailStatusLabel($status, $method): string
{
    if ($method === 'store_pickup' && $status === 'shipped') {
        return 'Ready to Pick Up';
    }

    if ($method === 'store_pickup' && $status === 'delivered') {
        return 'Picked Up';
    }

    if ($method !== 'store_pickup' && $status === 'delivered') {
        return 'Delivered';
    }

    $labels = [
        'pending' => 'Pending',
        'preparing' => 'Preparing',
        'shipped' => 'Shipped',
        'delivered' => 'Delivered / Picked Up',
        'canceled' => 'Canceled'
    ];

    return $labels[$status] ?? ucfirst((string) $status);
}

function orderEmailSubjectForStatus(array $order, string $context): string
{
    $orderNumber = (string) ($order['order_number'] ?? '');
    $status = (string) ($order['status'] ?? '');
    $method = (string) ($order['fulfillment_method'] ?? '');

    if ($context !== 'updated') {
        return "J&J's Kitchenette order confirmation - {$orderNumber}";
    }

    if ($status === 'preparing') {
        return "We are preparing your order #{$orderNumber}";
    }

    if ($status === 'shipped') {
        return $method === 'store_pickup'
            ? "Your order #{$orderNumber} is ready for pickup"
            : "Your order #{$orderNumber} is out for delivery";
    }

    if ($status === 'delivered') {
        return $method === 'store_pickup'
            ? "Your order #{$orderNumber} has been picked up"
            : "Your order #{$orderNumber} has been delivered";
    }

    if ($status === 'canceled') {
        return "Your order #{$orderNumber} has been canceled";
    }

    return "J&J's Kitchenette order update - {$orderNumber}";
}

function fetchOrderEmailPayload(mysqli $conn, int $orderId): ?array
{
    $orderStmt = $conn->prepare("
        SELECT
            o.*,
            u.email,
            COALESCE(
                NULLIF(NULLIF(o.customer_name, ''), 'Customer'),
                NULLIF(CONCAT_WS(' ', NULLIF(u.first_name, ''), NULLIF(u.last_name, '')), ''),
                'Customer'
            ) AS display_customer_name
        FROM orders o
        LEFT JOIN users u ON u.id = o.user_id
        WHERE o.id = ?
        LIMIT 1
    ");
    $orderStmt->bind_param("i", $orderId);
    $orderStmt->execute();
    $order = $orderStmt->get_result()->fetch_assoc();

    if (!$order || empty($order['email'])) {
        return null;
    }

    $itemStmt = $conn->prepare("
        SELECT
            oi.*,
            COALESCE(NULLIF(oi.sku, ''), pv.sku, '-') AS display_sku
        FROM order_items oi
        LEFT JOIN product_variants pv ON pv.id = oi.variant_id
        WHERE oi.order_id = ?
        ORDER BY oi.id ASC
    ");
    $itemStmt->bind_param("i", $orderId);
    $itemStmt->execute();
    $result = $itemStmt->get_result();

    $items = [];
    while ($item = $result->fetch_assoc()) {
        $items[] = $item;
    }

    return [
        'order' => $order,
        'items' => $items
    ];
}

function sendCustomerOrderDetailsEmail(mysqli $conn, int $orderId, string $context = 'created'): bool
{
    $payload = fetchOrderEmailPayload($conn, $orderId);
    if (!$payload) {
        return false;
    }

    $order = $payload['order'];
    $items = $payload['items'];
    $toEmail = trim((string) $order['email']);
    $customerName = trim((string) ($order['display_customer_name'] ?? $order['customer_name'] ?? 'Customer'));
    $safeName = htmlspecialchars($customerName !== '' ? $customerName : 'Customer', ENT_QUOTES, 'UTF-8');
    $safeOrderNumber = htmlspecialchars((string) $order['order_number'], ENT_QUOTES, 'UTF-8');
    $fulfillmentLabel = orderEmailFulfillmentLabel($order['fulfillment_method']);
    $statusLabel = orderEmailStatusLabel($order['status'], $order['fulfillment_method']);
    $subjectLead = $context === 'updated' ? 'Order update' : 'Order confirmation';
    $subject = orderEmailSubjectForStatus($order, $context);
    $intro = $context === 'updated'
        ? 'Your order has been updated. Please review the current items and total below.'
        : 'We received your order. Here are the details for your records.';

    $logoPath = __DIR__ . '/../assets/images/kitchenette-logo.svg';
    $embeddedImages = file_exists($logoPath) ? ['kitchenetteLogo' => $logoPath] : [];
    $logoHtml = file_exists($logoPath)
        ? '<img src="cid:kitchenetteLogo" width="160" alt="J&J\'s Kitchenette" style="display:block;border:0;max-width:160px;height:auto;margin:0 auto;">'
        : '<div style="font-size:28px;font-weight:800;color:#125827;text-align:center;">J&amp;J&apos;s Kitchenette</div>';

    $activeRows = '';
    $canceledRows = '';
    $plainItems = [];
    $plainCanceled = [];

    foreach ($items as $item) {
        $isCanceled = ($item['item_status'] ?? 'active') === 'canceled';
        $options = array_filter([
            $item['option1_value'] ?? '',
            $item['option2_value'] ?? '',
            $item['option3_value'] ?? ''
        ], function ($value) {
            return $value !== null && $value !== '' && strtolower((string) $value) !== 'default';
        });
        $optionText = !empty($options) ? ' (' . implode(' / ', $options) . ')' : '';
        $safeTitle = htmlspecialchars((string) $item['product_title'], ENT_QUOTES, 'UTF-8');
        $safeOptions = htmlspecialchars($optionText, ENT_QUOTES, 'UTF-8');
        $safeSku = htmlspecialchars((string) ($item['display_sku'] ?? '-'), ENT_QUOTES, 'UTF-8');
        $quantity = (int) $item['quantity'];
        $price = orderEmailMoney($item['price']);
        $lineTotal = $isCanceled ? 'Removed' : orderEmailMoney($item['subtotal']);
        $reason = trim((string) ($item['cancel_reason'] ?? ''));
        $safeReason = htmlspecialchars($reason !== '' ? $reason : 'Item unavailable', ENT_QUOTES, 'UTF-8');

        $row = "
            <tr>
                <td style=\"padding:12px 0;border-bottom:1px solid #e7eee4;\">
                    <strong style=\"color:#1f2937;\">{$safeTitle}</strong>
                    <span style=\"display:block;color:#64748b;font-size:13px;margin-top:3px;\">{$safeOptions}</span>
                    <span style=\"display:block;color:#64748b;font-size:13px;margin-top:3px;\">SKU: {$safeSku}</span>
                </td>
                <td align=\"center\" style=\"padding:12px 10px;border-bottom:1px solid #e7eee4;color:#1f2937;\">{$quantity}</td>
                <td align=\"right\" style=\"padding:12px 10px;border-bottom:1px solid #e7eee4;color:#1f2937;\">{$price}</td>
                <td align=\"right\" style=\"padding:12px 0;border-bottom:1px solid #e7eee4;font-weight:800;color:#1f2937;\">{$lineTotal}</td>
            </tr>";

        if ($isCanceled) {
            $canceledRows .= "
                <tr>
                    <td style=\"padding:12px 0;border-bottom:1px solid #f3cccc;\">
                        <strong style=\"color:#991b1b;\">{$safeTitle}</strong>
                        <span style=\"display:block;color:#b45309;font-size:13px;margin-top:3px;\">{$safeOptions}</span>
                        <span style=\"display:block;color:#991b1b;font-size:13px;margin-top:3px;\">SKU: {$safeSku}</span>
                        <span style=\"display:block;color:#991b1b;font-size:13px;margin-top:3px;\">Reason: {$safeReason}</span>
                    </td>
                    <td align=\"center\" style=\"padding:12px 10px;border-bottom:1px solid #f3cccc;color:#991b1b;\">{$quantity}</td>
                    <td align=\"right\" style=\"padding:12px 10px;border-bottom:1px solid #f3cccc;color:#991b1b;\">{$price}</td>
                    <td align=\"right\" style=\"padding:12px 0;border-bottom:1px solid #f3cccc;font-weight:800;color:#991b1b;\">Removed</td>
                </tr>";
            $plainCanceled[] = "- {$item['product_title']}{$optionText} SKU {$item['display_sku']} x{$quantity}: removed ({$reason})";
        } else {
            $activeRows .= $row;
            $plainItems[] = "- {$item['product_title']}{$optionText} SKU {$item['display_sku']} x{$quantity} @ " . orderEmailPlainMoney($item['price']) . " = " . orderEmailPlainMoney($item['subtotal']);
        }
    }

    if ($activeRows === '') {
        $activeRows = '<tr><td colspan="4" style="padding:12px 0;color:#64748b;">No active items.</td></tr>';
    }

    $canceledSection = '';
    if ($canceledRows !== '') {
        $canceledSection = "
            <h2 style=\"margin:28px 0 10px;font-size:18px;color:#991b1b;\">Canceled Items</h2>
            <table role=\"presentation\" width=\"100%\" cellspacing=\"0\" cellpadding=\"0\" style=\"border-collapse:collapse;background:#fff7f7;border:1px solid #fecaca;border-radius:10px;overflow:hidden;\">
                <tr>
                    <th align=\"left\" style=\"padding:10px 0;color:#991b1b;font-size:12px;text-transform:uppercase;\">Item</th>
                    <th align=\"center\" style=\"padding:10px;color:#991b1b;font-size:12px;text-transform:uppercase;\">Qty</th>
                    <th align=\"right\" style=\"padding:10px;color:#991b1b;font-size:12px;text-transform:uppercase;\">Price</th>
                    <th align=\"right\" style=\"padding:10px 0;color:#991b1b;font-size:12px;text-transform:uppercase;\">Status</th>
                </tr>
                {$canceledRows}
            </table>";
    }

    $shippingDetails = $order['fulfillment_method'] === 'delivery'
        ? '<strong>' . htmlspecialchars((string) $order['customer_name'], ENT_QUOTES, 'UTF-8') . '</strong><br>' .
            htmlspecialchars((string) $order['address_line'], ENT_QUOTES, 'UTF-8') . '<br>' .
            htmlspecialchars((string) $order['city'], ENT_QUOTES, 'UTF-8') . '<br>' .
            htmlspecialchars((string) $order['phone'], ENT_QUOTES, 'UTF-8')
        : 'Store pickup selected. No delivery address is required.';

    $plainShipping = $order['fulfillment_method'] === 'delivery'
        ? trim(($order['customer_name'] ?? '') . "\n" . ($order['address_line'] ?? '') . "\n" . ($order['city'] ?? '') . "\n" . ($order['phone'] ?? ''))
        : 'Store pickup selected. No delivery address is required.';

    $safeIntro = htmlspecialchars($intro, ENT_QUOTES, 'UTF-8');
    $safeFulfillment = htmlspecialchars($fulfillmentLabel, ENT_QUOTES, 'UTF-8');
    $safeStatus = htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8');
    $safePayment = htmlspecialchars(orderEmailPaymentLabel($order['payment_method']), ENT_QUOTES, 'UTF-8');
    $createdAt = !empty($order['created_at']) ? date('M d, Y h:i A', strtotime($order['created_at'])) : '';
    $safeCreatedAt = htmlspecialchars($createdAt, ENT_QUOTES, 'UTF-8');

    $html = <<<HTML
<!doctype html>
<html>
<body style="margin:0;padding:0;background:#f5f8f3;font-family:Arial,Helvetica,sans-serif;color:#172018;">
    <div class="email-wrap" style="padding:24px 14px;">
        <div class="email-container" style="max-width:660px;margin:0 auto;background:#fbfdf9;border:1px solid #e1eadf;border-radius:12px;box-shadow:0 12px 28px rgba(18,88,39,0.08);overflow:hidden;">
            <div class="email-logo" style="padding:22px 20px 8px;text-align:center;">{$logoHtml}</div>
            <div class="email-card" style="margin:0 18px 22px;background:#ffffff;border-radius:12px;box-shadow:0 10px 24px rgba(17,24,39,0.06);padding:22px;">
                <p style="margin:0 0 8px;color:#125827;font-size:13px;font-weight:800;text-transform:uppercase;">{$subjectLead}</p>
                <h1 class="email-title" style="margin:0 0 8px;font-size:25px;line-height:1.15;color:#1b1f1d;font-weight:800;">Order {$safeOrderNumber}</h1>
                <p class="email-copy" style="margin:0 0 18px;font-size:14px;line-height:1.5;color:#475569;">Hello <strong style="color:#125827;">{$safeName}</strong>, {$safeIntro}</p>

                <table class="email-stat" role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;background:#f8fbf7;border:1px solid #dfeadf;border-radius:10px;margin:0 0 20px;">
                    <tr>
                        <td style="padding:14px 16px;"><span style="display:block;color:#64748b;font-size:12px;">Status</span><strong style="color:#125827;">{$safeStatus}</strong></td>
                        <td style="padding:14px 16px;"><span style="display:block;color:#64748b;font-size:12px;">Method</span><strong>{$safeFulfillment}</strong></td>
                        <td style="padding:14px 16px;"><span style="display:block;color:#64748b;font-size:12px;">Payment</span><strong>{$safePayment}</strong></td>
                    </tr>
                    <tr>
                        <td colspan="3" style="padding:0 16px 14px;"><span style="display:block;color:#64748b;font-size:12px;">Placed</span><strong>{$safeCreatedAt}</strong></td>
                    </tr>
                </table>

                <h2 style="margin:0 0 8px;font-size:17px;color:#1f2937;">Ordered Items</h2>
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">
                    <tr>
                        <th align="left" style="padding:10px 0;color:#64748b;font-size:12px;text-transform:uppercase;">Item</th>
                        <th align="center" style="padding:10px;color:#64748b;font-size:12px;text-transform:uppercase;">Qty</th>
                        <th align="right" style="padding:10px;color:#64748b;font-size:12px;text-transform:uppercase;">Price</th>
                        <th align="right" style="padding:10px 0;color:#64748b;font-size:12px;text-transform:uppercase;">Total</th>
                    </tr>
                    {$activeRows}
                </table>

                {$canceledSection}

                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin-top:20px;background:#f8fbf7;border-radius:10px;">
                    <tr><td style="padding:14px 16px;color:#64748b;">Subtotal</td><td align="right" style="padding:14px 16px;font-weight:800;">__ORDER_SUBTOTAL__</td></tr>
                    <tr><td style="padding:0 16px 14px;color:#64748b;">Delivery</td><td align="right" style="padding:0 16px 14px;font-weight:800;">__ORDER_DELIVERY__</td></tr>
                    <tr><td style="border-top:1px solid #dfeadf;padding:14px 16px;color:#125827;font-size:18px;font-weight:900;">Total</td><td align="right" style="border-top:1px solid #dfeadf;padding:14px 16px;color:#125827;font-size:18px;font-weight:900;">__ORDER_TOTAL__</td></tr>
                </table>

                <h2 style="margin:22px 0 8px;font-size:17px;color:#1f2937;">Shipping Details</h2>
                <div class="email-copy" style="background:#f8fbf7;border:1px solid #dfeadf;border-radius:10px;padding:14px;font-size:14px;line-height:1.5;color:#1f2937;">{$shippingDetails}</div>
            </div>
            <div class="email-footer" style="background:#eef6ee;padding:14px 20px;text-align:center;color:#5f6f64;font-size:12px;">
                &copy; 2026 J&amp;J&apos;s Kitchenette. This is an automated email. Please do not reply.
            </div>
        </div>
    </div>
</body>
</html>
HTML;

    $thisSubtotal = orderEmailMoney($order['subtotal']);
    $thisDelivery = (float) $order['delivery_fee'] > 0 ? orderEmailMoney($order['delivery_fee']) : 'Free';
    $thisTotal = orderEmailMoney($order['total']);
    $html = str_replace(
        ['__ORDER_SUBTOTAL__', '__ORDER_DELIVERY__', '__ORDER_TOTAL__'],
        [$thisSubtotal, $thisDelivery, $thisTotal],
        $html
    );

    $plain = "Order {$order['order_number']}\n"
        . "{$intro}\n\n"
        . "Status: {$statusLabel}\n"
        . "Fulfillment: {$fulfillmentLabel}\n"
        . "Payment: " . orderEmailPaymentLabel($order['payment_method']) . "\n\n"
        . "Ordered items:\n" . implode("\n", $plainItems) . "\n\n";

    if (!empty($plainCanceled)) {
        $plain .= "Canceled items:\n" . implode("\n", $plainCanceled) . "\n\n";
    }

    $plain .= "Subtotal: " . orderEmailPlainMoney($order['subtotal']) . "\n"
        . "Delivery: " . ((float) $order['delivery_fee'] > 0 ? orderEmailPlainMoney($order['delivery_fee']) : 'Free') . "\n"
        . "Total: " . orderEmailPlainMoney($order['total']) . "\n\n"
        . "Shipping details:\n{$plainShipping}";

    return sendAppMail($toEmail, $customerName, $subject, $html, $plain, $embeddedImages);
}
