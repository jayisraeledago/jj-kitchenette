<?php
include __DIR__ . '/../db.php';
include __DIR__ . '/includes/admin-auth.php';
requireAdminPermission($conn, ['orders']);

$statusOptions = ['pending', 'preparing', 'shipped', 'delivered'];
$statusLabels = [
    'pending' => 'Pending',
    'preparing' => 'Preparing',
    'shipped' => 'Shipped',
    'delivered' => 'Delivered / Picked Up'
];

function adminOrderStatusLabel($status, $statusLabels)
{
    return $statusLabels[$status] ?? ucfirst($status);
}

function adminOrderStatusOptionsForFulfillment($method)
{
    return ['pending', 'preparing', 'shipped', 'delivered'];
}

function adminOrderStatusLabelForFulfillment($status, $statusLabels, $method)
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

    return adminOrderStatusLabel($status, $statusLabels);
}

function adminOrderPaymentLabel($method)
{
    return $method === 'store_pickup' ? 'Store Pick Up' : 'Cash on Delivery';
}

function adminOrderFulfillmentLabel($method)
{
    return $method === 'store_pickup' ? 'Pickup' : 'Delivery';
}

function adminOrderInitials($name)
{
    $name = trim((string) $name);
    if ($name === '') {
        return 'CU';
    }

    $parts = preg_split('/\s+/', $name);
    return strtoupper(substr($parts[0] ?? 'C', 0, 1) . substr($parts[count($parts) - 1] ?? 'U', 0, 1));
}

$orderId = (int) ($_GET['id'] ?? $_POST['order_id'] ?? 0);
$returnQuery = $_GET['return'] ?? $_POST['return_query'] ?? '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['order_id'], $_POST['status'])) {
    $status = $_POST['status'];

    $allowedStatusOptions = $statusOptions;
    if ($orderId > 0) {
        $methodStmt = $conn->prepare("SELECT fulfillment_method FROM orders WHERE id = ? LIMIT 1");
        $methodStmt->bind_param("i", $orderId);
        $methodStmt->execute();
        $methodRow = $methodStmt->get_result()->fetch_assoc();
        if ($methodRow) {
            $allowedStatusOptions = adminOrderStatusOptionsForFulfillment($methodRow['fulfillment_method']);
        }
    }

    if ($orderId > 0 && in_array($status, $allowedStatusOptions, true)) {
        $updateStmt = $conn->prepare("
            UPDATE orders
            SET status = ?
            WHERE id = ?
        ");
        $updateStmt->bind_param("si", $status, $orderId);
        $updateStmt->execute();
    }

    $redirect = "order-detail.php?id=" . $orderId;
    if ($returnQuery !== '') {
        $redirect .= "&return=" . urlencode($returnQuery);
    }

    header("Location: " . $redirect);
    exit;
}

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

if (!$order) {
    header("Location: orders.php");
    exit;
}

$customerName = $order['display_customer_name'] ?? 'Customer';

$itemStmt = $conn->prepare("
    SELECT *
    FROM order_items
    WHERE order_id = ?
    ORDER BY id ASC
");
$itemStmt->bind_param("i", $orderId);
$itemStmt->execute();
$items = $itemStmt->get_result();

$backUrl = "orders.php" . ($returnQuery !== '' ? "?" . $returnQuery : "");
$itemCount = (int) $items->num_rows;
$orderStatusOptions = adminOrderStatusOptionsForFulfillment($order['fulfillment_method']);
$timelineSteps = $order['fulfillment_method'] === 'store_pickup'
    ? [
        ['key' => 'pending', 'label' => 'Order Placed'],
        ['key' => 'preparing', 'label' => 'Preparing'],
        ['key' => 'shipped', 'label' => 'Ready to Pick Up'],
        ['key' => 'delivered', 'label' => 'Picked Up']
    ]
    : [
        ['key' => 'pending', 'label' => 'Order Placed'],
        ['key' => 'preparing', 'label' => 'Preparing'],
        ['key' => 'shipped', 'label' => 'Shipped'],
        ['key' => 'delivered', 'label' => 'Delivered']
    ];
$statusIndex = array_search($order['status'], array_column($timelineSteps, 'key'), true);
if ($statusIndex === false) {
    $statusIndex = 0;
}
?>

<!DOCTYPE html>
<html>

<head>
    <title><?= htmlspecialchars($order['order_number']) ?> | Orders Admin</title>
    <link rel="icon" type="image/png" href="/jj_kitchenette/assets/images/favicon.png">
    <link rel="shortcut icon" type="image/png" href="/jj_kitchenette/assets/images/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>

<body>
    <div class="app">
        <?php include 'includes/sidebar.php'; ?>

        <div class="main">
            <div class="page-container">
                <div class="order-detail-top order-detail-top--refined">
                    <a href="<?= htmlspecialchars($backUrl) ?>" class="order-detail-back">
                        <i class="fa-solid fa-chevron-left"></i>
                        Back to orders
                    </a>

                    <div class="order-detail-heading order-detail-heading--refined">
                        <div>
                            <span>Order</span>
                            <h1><?= htmlspecialchars($order['order_number']) ?></h1>
                            <p>
                                <i class="fa-regular fa-calendar-days"></i>
                                <?= date('M d, Y', strtotime($order['created_at'])) ?> at <?= date('h:i A', strtotime($order['created_at'])) ?>
                                <span></span>
                                Order placed
                            </p>
                        </div>

                    </div>
                </div>

                <div class="order-detail-layout">
                    <section class="order-detail-main">
                        <div class="order-detail-card">
                            <div class="order-detail-card__header">
                                <h2>Order Items</h2>
                                <span><?= $itemCount ?> item<?= $itemCount === 1 ? '' : 's' ?> • Total &#8369;<?= number_format((float) $order['total'], 2) ?></span>
                            </div>

                            <div class="order-detail-items">
                                <?php while ($item = $items->fetch_assoc()) { ?>
                                    <?php
                                    $options = array_filter([
                                        $item['option1_value'],
                                        $item['option2_value'],
                                        $item['option3_value']
                                    ], function ($value) {
                                        return $value !== null && $value !== '' && strtolower($value) !== 'default';
                                    });
                                    $imagePath = !empty($item['image_path']) ? '../' . $item['image_path'] : '../uploads/default.png';
                                    ?>
                                    <div class="order-detail-item">
                                        <img src="<?= htmlspecialchars($imagePath) ?>" alt="<?= htmlspecialchars($item['product_title']) ?>">
                                        <div>
                                            <strong><?= htmlspecialchars($item['product_title']) ?></strong>
                                            <?php if (!empty($options)) { ?>
                                                <span><?= htmlspecialchars(implode(' / ', $options)) ?></span>
                                            <?php } ?>
                                            <span>SKU: <?= htmlspecialchars($item['sku'] ?? '-') ?></span>
                                        </div>
                                        <div class="order-detail-item__quantity">
                                            <span>Quantity</span>
                                            <strong><?= (int) $item['quantity'] ?></strong>
                                            <small>x &#8369;<?= number_format((float) $item['price'], 2) ?></small>
                                        </div>
                                        <div class="order-detail-item__total">
                                            <span>Total</span>
                                            <strong>&#8369;<?= number_format((float) $item['subtotal'], 2) ?></strong>
                                        </div>
                                    </div>
                                <?php } ?>
                                <div class="order-detail-items-total">
                                    <span>Order Total</span>
                                    <strong>&#8369;<?= number_format((float) $order['total'], 2) ?></strong>
                                </div>
                            </div>
                        </div>

                        <div class="order-detail-card">
                            <div class="order-detail-card__header">
                                <h2>Customer & Fulfillment</h2>
                            </div>

                            <div class="order-detail-info-grid">
                                <div>
                                    <span class="order-customer__avatar"><?= htmlspecialchars(adminOrderInitials($customerName)) ?></span>
                                    <div>
                                        <strong><?= htmlspecialchars($customerName) ?></strong>
                                        <?php if (!empty($order['email'])) { ?>
                                            <span><?= htmlspecialchars($order['email']) ?></span>
                                        <?php } ?>
                                        <?php if (!empty($order['phone'])) { ?>
                                            <span><?= htmlspecialchars($order['phone']) ?></span>
                                        <?php } ?>
                                    </div>
                                </div>

                                <div>
                                    <i class="fa-solid <?= $order['fulfillment_method'] === 'store_pickup' ? 'fa-store' : 'fa-truck' ?>"></i>
                                    <div>
                                        <strong><?= htmlspecialchars(adminOrderFulfillmentLabel($order['fulfillment_method'])) ?></strong>
                                        <?php if ($order['fulfillment_method'] === 'delivery') { ?>
                                            <span><?= htmlspecialchars($order['address_line'] ?? '') ?></span>
                                            <span><?= htmlspecialchars($order['city'] ?? '') ?></span>
                                        <?php } else { ?>
                                            <span>Customer will pick up at the store.</span>
                                        <?php } ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="order-detail-card">
                            <div class="order-detail-card__header">
                                <h2>Order Timeline</h2>
                            </div>

                            <div class="order-detail-timeline">
                                <?php foreach ($timelineSteps as $index => $step) { ?>
                                    <?php
                                    if ($order['status'] === 'delivered' && $index <= $statusIndex) {
                                        $state = 'completed';
                                    } else {
                                        $state = $index < $statusIndex ? 'completed' : ($index === $statusIndex ? 'current' : 'upcoming');
                                    }
                                    ?>
                                    <div class="order-detail-timeline__item order-detail-timeline__item--<?= htmlspecialchars($state) ?>">
                                        <span class="order-detail-timeline__dot">
                                            <?php if ($state === 'completed') { ?>
                                                <i class="fa-solid fa-check"></i>
                                            <?php } ?>
                                        </span>
                                        <div>
                                            <strong><?= htmlspecialchars($step['label']) ?></strong>
                                            <small><?= $index <= $statusIndex ? date('M d, Y h:i A', strtotime($order['created_at'])) : '-' ?></small>
                                        </div>
                                        <em><?= htmlspecialchars(ucfirst($state)) ?></em>
                                    </div>
                                <?php } ?>
                            </div>
                        </div>
                    </section>

                    <aside class="order-detail-side">
                        <div class="order-detail-card">
                            <div class="order-detail-card__header">
                                <h2>Update Status</h2>
                            </div>

                            <form method="POST" class="order-detail-status-form">
                                <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                <input type="hidden" name="return_query" value="<?= htmlspecialchars($returnQuery) ?>">
                                <div class="order-detail-current-status">
                                    <span>Current Status</span>
                                    <span class="order-status order-status--<?= htmlspecialchars($order['status']) ?>">
                                        <?= htmlspecialchars(adminOrderStatusLabelForFulfillment($order['status'], $statusLabels, $order['fulfillment_method'])) ?>
                                    </span>
                                </div>
                                <label>
                                    <span>Change Status</span>
                                <select name="status">
                                    <?php foreach ($orderStatusOptions as $status) { ?>
                                        <option value="<?= htmlspecialchars($status) ?>" <?= $order['status'] === $status ? 'selected' : '' ?>>
                                            <?= htmlspecialchars(adminOrderStatusLabelForFulfillment($status, $statusLabels, $order['fulfillment_method'])) ?>
                                        </option>
                                    <?php } ?>
                                </select>
                                </label>
                                <button type="submit">Update Status <i class="fa-regular fa-paper-plane"></i></button>
                                <p class="order-detail-note">
                                    <i class="fa-solid fa-circle-info"></i>
                                    The customer will be notified automatically when the status is updated.
                                </p>
                            </form>
                        </div>

                        <div class="order-detail-card">
                            <div class="order-detail-card__header">
                                <h2><i class="fa-regular fa-credit-card"></i> Payment Summary</h2>
                            </div>

                            <div class="order-detail-payment">
                                <div>
                                    <span>Method</span>
                                    <strong><?= htmlspecialchars(adminOrderPaymentLabel($order['payment_method'])) ?></strong>
                                </div>
                                <div>
                                    <span>Subtotal</span>
                                    <strong>&#8369;<?= number_format((float) $order['subtotal'], 2) ?></strong>
                                </div>
                                <div>
                                    <span>Delivery</span>
                                    <strong><?= (float) $order['delivery_fee'] > 0 ? '&#8369;' . number_format((float) $order['delivery_fee'], 2) : 'Free' ?></strong>
                                </div>
                                <div class="order-detail-payment__total">
                                    <span>Total</span>
                                    <strong>&#8369;<?= number_format((float) $order['total'], 2) ?></strong>
                                </div>
                            </div>
                        </div>
                    </aside>
                </div>
            </div>
        </div>
    </div>
</body>

</html>
