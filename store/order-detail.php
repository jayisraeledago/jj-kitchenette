<?php
include __DIR__ . '/../db.php';
include __DIR__ . '/includes/admin-auth.php';
require_once __DIR__ . '/../includes/order_email.php';
requireAdminPermission($conn, ['orders']);

$statusOptions = ['pending', 'preparing', 'shipped', 'delivered', 'canceled'];
$statusLabels = [
    'pending' => 'Pending',
    'preparing' => 'Preparing',
    'shipped' => 'Shipped',
    'delivered' => 'Delivered / Picked Up',
    'canceled' => 'Canceled'
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

function ensureOrderItemCancellationColumns($conn)
{
    $columns = [
        'sku' => "ALTER TABLE order_items ADD COLUMN sku VARCHAR(100) NULL AFTER option3_value",
        'item_status' => "ALTER TABLE order_items ADD COLUMN item_status VARCHAR(30) NOT NULL DEFAULT 'active' AFTER subtotal",
        'canceled_at' => "ALTER TABLE order_items ADD COLUMN canceled_at DATETIME NULL AFTER item_status",
        'cancel_reason' => "ALTER TABLE order_items ADD COLUMN cancel_reason VARCHAR(255) NULL AFTER canceled_at"
    ];

    foreach ($columns as $column => $sql) {
        $check = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'order_items'
            AND COLUMN_NAME = ?
        ");
        $check->bind_param("s", $column);
        $check->execute();

        if ((int) $check->get_result()->fetch_assoc()['total'] === 0) {
            $conn->query($sql);
        }
    }
}

function ensureOrderNotesTable($conn)
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS order_notes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            user_id INT NULL,
            note TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_order_notes_order (order_id),
            INDEX idx_order_notes_user (user_id)
        )
    ");
}

ensureOrderItemCancellationColumns($conn);
ensureOrderNotesTable($conn);

$orderId = (int) ($_GET['id'] ?? $_POST['order_id'] ?? 0);
$returnQuery = $_GET['return'] ?? $_POST['return_query'] ?? '';
$notice = $_GET['notice'] ?? '';
$error = $_GET['error'] ?? '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['form_action'] ?? '') === 'add_note') {
    $noteText = trim((string) ($_POST['note_text'] ?? ''));
    $adminUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    $redirect = "order-detail.php?id=" . $orderId;
    if ($returnQuery !== '') {
        $redirect .= "&return=" . urlencode($returnQuery);
    }

    if ($orderId <= 0 || $noteText === '') {
        header("Location: " . $redirect . "&error=" . urlencode('Please enter a note before posting.'));
        exit;
    }

    $noteStmt = $conn->prepare("
        INSERT INTO order_notes (order_id, user_id, note)
        VALUES (?, ?, ?)
    ");
    $noteStmt->bind_param("iis", $orderId, $adminUserId, $noteText);
    $noteStmt->execute();

    header("Location: " . $redirect . "&notice=" . urlencode('Note posted.'));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['form_action'] ?? '') === 'cancel_item') {
    $itemId = (int) ($_POST['item_id'] ?? 0);
    $allowedCancelReasons = [
        'Item unavailable',
        'Ingredient unavailable',
        'Variant unavailable',
        'Quality issue',
        'Customer requested item removal',
        'Duplicate item added by mistake'
    ];
    $reason = trim((string) ($_POST['cancel_reason'] ?? ''));
    $redirect = "order-detail.php?id=" . $orderId;
    if ($returnQuery !== '') {
        $redirect .= "&return=" . urlencode($returnQuery);
    }

    if ($orderId <= 0 || $itemId <= 0 || !in_array($reason, $allowedCancelReasons, true)) {
        header("Location: " . $redirect . "&error=" . urlencode('Unable to cancel that item.'));
        exit;
    }

    try {
        $conn->begin_transaction();

        $orderLock = $conn->prepare("
            SELECT id, status, delivery_fee
            FROM orders
            WHERE id = ?
            FOR UPDATE
        ");
        $orderLock->bind_param("i", $orderId);
        $orderLock->execute();
        $lockedOrder = $orderLock->get_result()->fetch_assoc();

        $itemLock = $conn->prepare("
            SELECT id, variant_id, quantity, subtotal, item_status
            FROM order_items
            WHERE id = ?
            AND order_id = ?
            FOR UPDATE
        ");
        $itemLock->bind_param("ii", $itemId, $orderId);
        $itemLock->execute();
        $lockedItem = $itemLock->get_result()->fetch_assoc();

        if (!$lockedOrder || !$lockedItem) {
            throw new Exception('Unable to cancel that item.');
        }

        if (!in_array($lockedOrder['status'], ['pending', 'preparing'], true)) {
            throw new Exception('Items can only be canceled while an order is pending or preparing.');
        }

        if (($lockedItem['item_status'] ?? 'active') === 'canceled') {
            throw new Exception('That item is already canceled.');
        }

        $activeCountStmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM order_items
            WHERE order_id = ?
            AND COALESCE(item_status, 'active') <> 'canceled'
        ");
        $activeCountStmt->bind_param("i", $orderId);
        $activeCountStmt->execute();
        $activeCount = (int) $activeCountStmt->get_result()->fetch_assoc()['total'];

        if ($activeCount <= 1) {
            throw new Exception('At least one active item must remain in the order.');
        }

        $cancelStmt = $conn->prepare("
            UPDATE order_items
            SET item_status = 'canceled',
                canceled_at = NOW(),
                cancel_reason = ?
            WHERE id = ?
            AND order_id = ?
        ");
        $cancelStmt->bind_param("sii", $reason, $itemId, $orderId);
        $cancelStmt->execute();

        $restockReasons = [
            'Customer requested item removal',
            'Duplicate item added by mistake'
        ];

        if (in_array($reason, $restockReasons, true) && (int) ($lockedItem['variant_id'] ?? 0) > 0) {
            $restoreQuantity = max(1, (int) ($lockedItem['quantity'] ?? 1));
            $variantId = (int) $lockedItem['variant_id'];
            $restockStmt = $conn->prepare("
                UPDATE product_variants
                SET inventory = inventory + ?
                WHERE id = ?
            ");
            $restockStmt->bind_param("ii", $restoreQuantity, $variantId);
            $restockStmt->execute();
        }

        $subtotalStmt = $conn->prepare("
            SELECT COALESCE(SUM(subtotal), 0) AS active_subtotal
            FROM order_items
            WHERE order_id = ?
            AND COALESCE(item_status, 'active') <> 'canceled'
        ");
        $subtotalStmt->bind_param("i", $orderId);
        $subtotalStmt->execute();
        $activeSubtotal = (float) $subtotalStmt->get_result()->fetch_assoc()['active_subtotal'];
        $newTotal = $activeSubtotal + (float) $lockedOrder['delivery_fee'];

        $orderUpdate = $conn->prepare("
            UPDATE orders
            SET subtotal = ?,
                total = ?
            WHERE id = ?
        ");
        $orderUpdate->bind_param("ddi", $activeSubtotal, $newTotal, $orderId);
        $orderUpdate->execute();

        $conn->commit();
        header("Location: " . $redirect . "&notice=" . urlencode('Item canceled and order total updated.'));
        exit;
    } catch (Throwable $exception) {
        $conn->rollback();
        header("Location: " . $redirect . "&error=" . urlencode($exception->getMessage()));
        exit;
    }
}

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

        sendCustomerOrderDetailsEmail($conn, $orderId, 'updated');
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
$itemsResult = $itemStmt->get_result();
$items = [];
$activeItemCount = 0;
$canceledItemCount = 0;

while ($item = $itemsResult->fetch_assoc()) {
    $itemStatus = $item['item_status'] ?? 'active';
    if ($itemStatus === 'canceled') {
        $canceledItemCount++;
    } else {
        $activeItemCount++;
    }
    $items[] = $item;
}

$notesStmt = $conn->prepare("
    SELECT
        n.*,
        COALESCE(
            NULLIF(CONCAT_WS(' ', NULLIF(u.first_name, ''), NULLIF(u.last_name, '')), ''),
            u.email,
            'Admin'
        ) AS author_name
    FROM order_notes n
    LEFT JOIN users u ON u.id = n.user_id
    WHERE n.order_id = ?
    ORDER BY n.created_at DESC, n.id DESC
");
$notesStmt->bind_param("i", $orderId);
$notesStmt->execute();
$notesResult = $notesStmt->get_result();
$orderNotes = [];

while ($note = $notesResult->fetch_assoc()) {
    $orderNotes[] = $note;
}

$backUrl = "orders.php" . ($returnQuery !== '' ? "?" . $returnQuery : "");
$itemCount = count($items);
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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($order['order_number']) ?> | Orders Admin</title>
    <link rel="icon" type="image/png" href="/assets/images/favicon.png">
    <link rel="shortcut icon" type="image/png" href="/assets/images/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
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
                        <?php if ($notice !== '') { ?>
                            <div class="order-detail-alert order-detail-alert--success">
                                <i class="fa-solid fa-circle-check"></i>
                                <?= htmlspecialchars($notice) ?>
                            </div>
                        <?php } ?>

                        <?php if ($error !== '') { ?>
                            <div class="order-detail-alert order-detail-alert--error">
                                <i class="fa-solid fa-circle-exclamation"></i>
                                <?= htmlspecialchars($error) ?>
                            </div>
                        <?php } ?>

                        <div class="order-detail-card">
                            <div class="order-detail-card__header">
                                <h2>Order Items</h2>
                                <span><?= $itemCount ?> item<?= $itemCount === 1 ? '' : 's' ?> • Total &#8369;<?= number_format((float) $order['total'], 2) ?></span>
                            </div>

                            <div class="order-detail-items">
                                <?php foreach ($items as $item) { ?>
                                    <?php
                                    $isCanceledItem = ($item['item_status'] ?? 'active') === 'canceled';
                                    $options = array_filter([
                                        $item['option1_value'],
                                        $item['option2_value'],
                                        $item['option3_value']
                                    ], function ($value) {
                                        return $value !== null && $value !== '' && strtolower($value) !== 'default';
                                    });
                                    $imagePath = !empty($item['image_path']) ? '../' . $item['image_path'] : '../uploads/default.png';
                                    $canCancelItem = !$isCanceledItem && $activeItemCount > 1 && in_array($order['status'], ['pending', 'preparing'], true);
                                    ?>
                                    <div class="order-detail-item <?= $isCanceledItem ? 'order-detail-item--canceled' : '' ?>">
                                        <img src="<?= htmlspecialchars($imagePath) ?>" alt="<?= htmlspecialchars($item['product_title']) ?>">
                                        <div>
                                            <strong>
                                                <?= htmlspecialchars($item['product_title']) ?>
                                                <?php if ($isCanceledItem) { ?>
                                                    <em class="order-detail-item__badge">Canceled</em>
                                                <?php } ?>
                                            </strong>
                                            <?php if (!empty($options)) { ?>
                                                <span><?= htmlspecialchars(implode(' / ', $options)) ?></span>
                                            <?php } ?>
                                            <span>SKU: <?= htmlspecialchars($item['display_sku'] ?? '-') ?></span>
                                            <?php if ($isCanceledItem && !empty($item['cancel_reason'])) { ?>
                                                <span>Reason: <?= htmlspecialchars($item['cancel_reason']) ?></span>
                                            <?php } ?>
                                        </div>
                                        <div class="order-detail-item__quantity">
                                            <span>Quantity</span>
                                            <strong><?= (int) $item['quantity'] ?></strong>
                                            <small>x &#8369;<?= number_format((float) $item['price'], 2) ?></small>
                                        </div>
                                        <div class="order-detail-item__total">
                                            <span>Total</span>
                                            <strong>
                                                <?= $isCanceledItem ? 'Removed' : '&#8369;' . number_format((float) $item['subtotal'], 2) ?>
                                            </strong>
                                            <?php if ($canCancelItem) { ?>
                                                <form method="POST" class="order-detail-cancel-item-form">
                                                    <input type="hidden" name="form_action" value="cancel_item">
                                                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                                    <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                                    <input type="hidden" name="return_query" value="<?= htmlspecialchars($returnQuery) ?>">
                                                    <input type="hidden" name="cancel_reason" value="">
                                                    <button type="submit">
                                                        <i class="fa-regular fa-circle-xmark"></i>
                                                        Cancel item
                                                    </button>
                                                </form>
                                            <?php } ?>
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

                            <form method="POST" class="order-detail-status-form" id="orderStatusForm" data-current-status="<?= htmlspecialchars($order['status']) ?>">
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

                        <div class="order-detail-card order-notes-card">
                            <div class="order-detail-card__header">
                                <h2><i class="fa-regular fa-note-sticky"></i> Order Notes</h2>
                            </div>

                            <form method="POST" class="order-note-form">
                                <input type="hidden" name="form_action" value="add_note">
                                <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                <input type="hidden" name="return_query" value="<?= htmlspecialchars($returnQuery) ?>">
                                <label for="orderNoteText">Add note</label>
                                <textarea id="orderNoteText" name="note_text" rows="4" placeholder="Write an internal note..." required></textarea>
                                <button type="submit">
                                    <i class="fa-regular fa-paper-plane"></i>
                                    Post Note
                                </button>
                            </form>

                            <div class="order-note-list">
                                <?php if (empty($orderNotes)) { ?>
                                    <p class="order-note-empty">No notes posted yet.</p>
                                <?php } ?>

                                <?php foreach ($orderNotes as $note) { ?>
                                    <article class="order-note-item">
                                        <p><?= nl2br(htmlspecialchars($note['note'])) ?></p>
                                        <small>
                                            <strong><?= htmlspecialchars($note['author_name']) ?></strong>
                                            <span><?= date('M d, Y h:i A', strtotime($note['created_at'])) ?></span>
                                        </small>
                                    </article>
                                <?php } ?>
                            </div>
                        </div>
                    </aside>
                </div>
            </div>
        </div>
    </div>

    <div class="order-availability-modal" id="availabilityConfirmModal" aria-hidden="true">
        <div class="order-availability-modal__panel">
            <button type="button" class="order-availability-modal__close" id="closeAvailabilityModal" aria-label="Close availability confirmation">
                <i class="fa-solid fa-xmark"></i>
            </button>

            <div class="order-availability-modal__icon">
                <i class="fa-solid fa-clipboard-check"></i>
            </div>

            <h2>Confirm Item Availability</h2>
            <p>Before setting this order to Preparing, please confirm that all ordered items are available or unavailable items have been canceled.</p>

            <div class="order-availability-modal__actions">
                <button type="button" id="cancelAvailabilityConfirm">Review items</button>
                <button type="button" id="confirmAvailabilityStatus">
                    <i class="fa-solid fa-check"></i>
                    Yes, items are checked
                </button>
            </div>
        </div>
    </div>

    <div class="order-availability-modal order-cancel-item-modal" id="cancelItemConfirmModal" aria-hidden="true">
        <div class="order-availability-modal__panel">
            <button type="button" class="order-availability-modal__close" id="closeCancelItemModal" aria-label="Close cancel item confirmation">
                <i class="fa-solid fa-xmark"></i>
            </button>

            <div class="order-availability-modal__icon order-availability-modal__icon--danger">
                <i class="fa-regular fa-circle-xmark"></i>
            </div>

            <h2>Cancel Unavailable Item?</h2>
            <p>This item will be marked as canceled and removed from the customer&apos;s active total. No email will be sent for this item change.</p>

            <label class="order-cancel-reason-field">
                <span>Cancellation reason</span>
                <select id="cancelItemReason" required>
                    <option value="">Select a reason</option>
                    <option value="Item unavailable">Item unavailable</option>
                    <option value="Ingredient unavailable">Ingredient unavailable</option>
                    <option value="Variant unavailable">Variant unavailable</option>
                    <option value="Quality issue">Quality issue</option>
                    <option value="Customer requested item removal">Customer requested item removal</option>
                    <option value="Duplicate item added by mistake">Duplicate item added by mistake</option>
                </select>
                <small id="cancelItemReasonError"></small>
            </label>

            <div class="order-availability-modal__actions">
                <button type="button" id="keepCancelItem">Keep item</button>
                <button type="button" id="confirmCancelItem" class="order-availability-modal__danger-action">
                    <i class="fa-regular fa-circle-xmark"></i>
                    Cancel item
                </button>
            </div>
        </div>
    </div>

    <script>
        const orderStatusForm = document.getElementById('orderStatusForm');
        const availabilityModal = document.getElementById('availabilityConfirmModal');
        const confirmAvailabilityStatus = document.getElementById('confirmAvailabilityStatus');
        const cancelAvailabilityConfirm = document.getElementById('cancelAvailabilityConfirm');
        const closeAvailabilityModal = document.getElementById('closeAvailabilityModal');
        const cancelItemModal = document.getElementById('cancelItemConfirmModal');
        const confirmCancelItem = document.getElementById('confirmCancelItem');
        const keepCancelItem = document.getElementById('keepCancelItem');
        const closeCancelItemModal = document.getElementById('closeCancelItemModal');
        const cancelItemReason = document.getElementById('cancelItemReason');
        const cancelItemReasonError = document.getElementById('cancelItemReasonError');
        let availabilityConfirmed = false;
        let pendingCancelItemForm = null;

        function openAvailabilityModal() {
            if (!availabilityModal) return;
            availabilityModal.classList.add('is-open');
            availabilityModal.setAttribute('aria-hidden', 'false');
        }

        function closeAvailabilityConfirmModal() {
            if (!availabilityModal) return;
            availabilityModal.classList.remove('is-open');
            availabilityModal.setAttribute('aria-hidden', 'true');
        }

        function openCancelItemModal(form) {
            pendingCancelItemForm = form;
            if (cancelItemReason) {
                cancelItemReason.value = '';
            }
            if (cancelItemReasonError) {
                cancelItemReasonError.textContent = '';
            }
            if (!cancelItemModal) return;
            cancelItemModal.classList.add('is-open');
            cancelItemModal.setAttribute('aria-hidden', 'false');
        }

        function closeCancelItemConfirmModal() {
            pendingCancelItemForm = null;
            if (!cancelItemModal) return;
            cancelItemModal.classList.remove('is-open');
            cancelItemModal.setAttribute('aria-hidden', 'true');
        }

        orderStatusForm?.addEventListener('submit', event => {
            const currentStatus = orderStatusForm.dataset.currentStatus;
            const selectedStatus = orderStatusForm.status.value;

            if (currentStatus === 'pending' && selectedStatus === 'preparing' && !availabilityConfirmed) {
                event.preventDefault();
                openAvailabilityModal();
            }
        });

        confirmAvailabilityStatus?.addEventListener('click', () => {
            availabilityConfirmed = true;
            closeAvailabilityConfirmModal();
            orderStatusForm?.requestSubmit();
        });

        cancelAvailabilityConfirm?.addEventListener('click', closeAvailabilityConfirmModal);
        closeAvailabilityModal?.addEventListener('click', closeAvailabilityConfirmModal);

        availabilityModal?.addEventListener('click', event => {
            if (event.target === availabilityModal) {
                closeAvailabilityConfirmModal();
            }
        });

        document.querySelectorAll('.order-detail-cancel-item-form').forEach(form => {
            form.addEventListener('submit', event => {
                event.preventDefault();
                openCancelItemModal(form);
            });
        });

        confirmCancelItem?.addEventListener('click', () => {
            const form = pendingCancelItemForm;
            const reason = cancelItemReason?.value || '';

            if (!reason) {
                if (cancelItemReasonError) {
                    cancelItemReasonError.textContent = 'Please select a cancellation reason.';
                }
                cancelItemReason?.focus();
                return;
            }

            const reasonInput = form?.querySelector('[name="cancel_reason"]');
            if (reasonInput) {
                reasonInput.value = reason;
            }

            closeCancelItemConfirmModal();
            form?.submit();
        });

        cancelItemReason?.addEventListener('change', () => {
            if (cancelItemReasonError) {
                cancelItemReasonError.textContent = '';
            }
        });

        keepCancelItem?.addEventListener('click', closeCancelItemConfirmModal);
        closeCancelItemModal?.addEventListener('click', closeCancelItemConfirmModal);

        cancelItemModal?.addEventListener('click', event => {
            if (event.target === cancelItemModal) {
                closeCancelItemConfirmModal();
            }
        });
    </script>
</body>

</html>
