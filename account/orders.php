<?php
session_start();
include '../db.php';
require_once __DIR__ . '/../includes/order_cancel.php';

// CHECK LOGIN
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userId = (int) $_SESSION['user_id'];
$statusFilter = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');
$statusOptions = ['delivered', 'shipped', 'preparing', 'pending', 'canceled'];
$message = $_GET['message'] ?? '';
$messageType = $_GET['message_type'] ?? '';

if (!in_array($statusFilter, $statusOptions, true)) {
    $statusFilter = '';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['form_action'] ?? '') === 'cancel_order') {
    $cancelOrderId = (int) ($_POST['order_id'] ?? 0);
    $result = cancelCustomerOrder($conn, $cancelOrderId, $userId);
    $redirect = '/jj_kitchenette/account/orders.php?message=' . urlencode($result['message']) . '&message_type=' . ($result['success'] ? 'success' : 'error');

    if ($statusFilter !== '') {
        $redirect .= '&status=' . urlencode($statusFilter);
    }

    if ($search !== '') {
        $redirect .= '&search=' . urlencode($search);
    }

    header("Location: " . $redirect);
    exit;
}

// GET USER
$stmt = $conn->prepare("
    SELECT *
    FROM users
    WHERE id = ?
");

$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

$conditions = ["o.user_id = ?"];
$types = "i";
$params = [$userId];

if ($statusFilter !== '') {
    $conditions[] = "o.status = ?";
    $types .= "s";
    $params[] = $statusFilter;
}

if ($search !== '') {
    $conditions[] = "(
        o.order_number LIKE ?
        OR EXISTS (
            SELECT 1
            FROM order_items oi
            WHERE oi.order_id = o.id
            AND oi.product_title LIKE ?
        )
    )";
    $searchTerm = "%{$search}%";
    $types .= "ss";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$whereSql = implode(" AND ", $conditions);

$orderStmt = $conn->prepare("
    SELECT
        o.*,
        (
            SELECT COUNT(*)
            FROM order_items oi
            WHERE oi.order_id = o.id
        ) AS item_count
    FROM orders o
    WHERE {$whereSql}
    ORDER BY o.created_at DESC, o.id DESC
");
$orderStmt->bind_param($types, ...$params);
$orderStmt->execute();
$orders = $orderStmt->get_result();

$pageTitle = "My Orders | J&J's Kitchenette";
$pageCSS = "profile.css";

function profileOrderStatusLabel($status)
{
    $labels = [
        'pending' => 'Pending',
        'preparing' => 'Preparing',
        'shipped' => 'Shipped',
        'delivered' => 'Delivered / Picked Up',
        'canceled' => 'Canceled'
    ];

    return $labels[$status] ?? ucfirst($status);
}

function profilePaymentLabel($method)
{
    return $method === 'store_pickup' ? 'Store Pick Up' : 'Cash on Delivery';
}

function profileFulfillmentLabel($method)
{
    return $method === 'store_pickup' ? 'Pickup' : 'Delivery';
}

function profileOrderStatusIcon($status)
{
    $icons = [
        'pending' => 'fa-clock',
        'preparing' => 'fa-kitchen-set',
        'shipped' => 'fa-truck',
        'delivered' => 'fa-bag-shopping',
        'canceled' => 'fa-ban'
    ];

    return $icons[$status] ?? 'fa-bag-shopping';
}

include('../store/includes/header.php');
?>

    <!-- CONTENT -->
    <div class="account-page orders-page">

        <div class="profile-page-top orders-page-top">
            <div>
                <h1>My Orders <i class="fas fa-leaf"></i></h1>
                <p>Track and manage all your orders in one place.</p>
            </div>

            <a href="/jj_kitchenette/account/profile.php" class="profile-orders-link">
                <i class="fas fa-user"></i>
                Profile
            </a>
        </div>

        <div class="orders-dashboard">
            <?php if ($message !== ''): ?>
                <div class="orders-page-message <?= $messageType === 'success' ? 'orders-page-message--success' : 'orders-page-message--error' ?>">
                    <i class="fas <?= $messageType === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <div class="orders-controls">
                <nav class="orders-tabs" aria-label="Order status filters">
                    <a href="/jj_kitchenette/account/orders.php<?php echo $search !== '' ? '?search=' . urlencode($search) : ''; ?>" class="<?php echo $statusFilter === '' ? 'active' : ''; ?>">
                        All Orders
                    </a>

                    <?php foreach ($statusOptions as $status) {
                        $query = ['status' => $status];
                        if ($search !== '') {
                            $query['search'] = $search;
                        }
                    ?>
                        <a href="/jj_kitchenette/account/orders.php?<?php echo htmlspecialchars(http_build_query($query)); ?>" class="<?php echo $statusFilter === $status ? 'active' : ''; ?>">
                            <?php echo htmlspecialchars(profileOrderStatusLabel($status)); ?>
                        </a>
                    <?php } ?>
                </nav>

                <form class="orders-search" method="GET" action="/jj_kitchenette/account/orders.php">
                    <?php if ($statusFilter !== '') { ?>
                        <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>">
                    <?php } ?>

                    <input
                        type="search"
                        name="search"
                        placeholder="Search by order ID or item..."
                        value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" aria-label="Search orders">
                        <i class="fas fa-search"></i>
                    </button>
                </form>
            </div>

            <?php if ($orders->num_rows > 0): ?>

                <div class="profile-orders">

                    <?php while ($order = $orders->fetch_assoc()): ?>
                        <?php
                        $itemsStmt = $conn->prepare("
                            SELECT
                                oi.*,
                                COALESCE(NULLIF(oi.sku, ''), pv.sku, '-') AS display_sku
                            FROM order_items oi
                            LEFT JOIN product_variants pv ON pv.id = oi.variant_id
                            WHERE oi.order_id = ?
                            ORDER BY oi.id ASC
                        ");
                        $itemsStmt->bind_param("i", $order['id']);
                        $itemsStmt->execute();
                        $orderItems = $itemsStmt->get_result();
                        $items = [];
                        while ($item = $orderItems->fetch_assoc()) {
                            $items[] = $item;
                        }
                        ?>

                        <article
                            class="profile-order order-history-card"
                            role="link"
                            tabindex="0"
                            data-order-url="/jj_kitchenette/order_success.php?id=<?= (int) $order['id'] ?>">
                            <div class="order-history-icon profile-order-status--<?= htmlspecialchars($order['status']) ?>">
                                <i class="fas <?= htmlspecialchars(profileOrderStatusIcon($order['status'])) ?>"></i>
                            </div>

                            <div class="order-history-main">
                                <div class="profile-order-header">
                                    <div>
                                        <span>Order ID</span>
                                        <strong><?= htmlspecialchars($order['order_number']) ?></strong>
                                        <small><i class="far fa-calendar-days"></i> <?= date('M d, Y h:i A', strtotime($order['created_at'])) ?></small>
                                    </div>

                                    <span class="profile-order-status profile-order-status--<?= htmlspecialchars($order['status']) ?>">
                                        <i class="fas fa-check"></i>
                                        <?= htmlspecialchars(profileOrderStatusLabel($order['status'])) ?>
                                    </span>
                                </div>

                                <div class="order-history-body">
                                    <div class="profile-order-meta">
                                        <div>
                                            <i class="fas fa-credit-card"></i>
                                            <span>Payment</span>
                                            <strong><?= htmlspecialchars(profilePaymentLabel($order['payment_method'])) ?></strong>
                                        </div>

                                        <div>
                                            <i class="fas fa-truck"></i>
                                            <span>Method</span>
                                            <strong><?= htmlspecialchars(profileFulfillmentLabel($order['fulfillment_method'])) ?></strong>
                                        </div>

                                        <div>
                                            <i class="fas fa-peso-sign"></i>
                                            <span>Total</span>
                                            <strong>&#8369;<?= number_format((float) $order['total'], 2) ?></strong>
                                        </div>
                                    </div>

                                    <div class="profile-order-items">
                                        <?php foreach ($items as $item): ?>
                                            <?php
                                            $isCanceledItem = ($item['item_status'] ?? 'active') === 'canceled';
                                            $options = array_filter([
                                                $item['option1_value'],
                                                $item['option2_value'],
                                                $item['option3_value']
                                            ], function ($value) {
                                                return $value !== null && $value !== '' && strtolower($value) !== 'default';
                                            });
                                            $imagePath = !empty($item['image_path']) ? $item['image_path'] : 'uploads/default.png';
                                            ?>

                                            <div class="profile-order-item <?= $isCanceledItem ? 'profile-order-item--canceled' : '' ?>">
                                                <div class="order-item-image-wrap">
                                                    <img src="/jj_kitchenette/<?= htmlspecialchars($imagePath) ?>" alt="<?= htmlspecialchars($item['product_title']) ?>">
                                                    <span><?= (int) $item['quantity'] ?></span>
                                                </div>

                                                <div>
                                                    <strong>
                                                        <?= htmlspecialchars($item['product_title']) ?>
                                                        <?php if ($isCanceledItem): ?>
                                                            <em class="profile-order-item__badge">Canceled</em>
                                                        <?php endif; ?>
                                                    </strong>
                                                    <?php if (!empty($options)): ?>
                                                        <span><?= htmlspecialchars(implode(' / ', $options)) ?></span>
                                                    <?php endif; ?>
                                                    <span>SKU: <?= htmlspecialchars($item['display_sku'] ?? '-') ?></span>
                                                    <span>Qty: <?= (int) $item['quantity'] ?></span>
                                                    <?php if ($isCanceledItem && !empty($item['cancel_reason'])): ?>
                                                        <span>Reason: <?= htmlspecialchars($item['cancel_reason']) ?></span>
                                                    <?php endif; ?>
                                                </div>

                                                <strong>
                                                    <?= $isCanceledItem ? 'Removed' : '&#8369;' . number_format((float) $item['subtotal'], 2) ?>
                                                </strong>
                                            </div>

                                        <?php endforeach; ?>

                                        <div class="profile-order-totals">
                                            <span>Subtotal: <strong>&#8369;<?= number_format((float) $order['subtotal'], 2) ?></strong></span>
                                            <span>Delivery Fee: <strong><?= (float) $order['delivery_fee'] > 0 ? '&#8369;' . number_format((float) $order['delivery_fee'], 2) : 'Free' ?></strong></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </article>

                    <?php endwhile; ?>

                </div>

            <?php else: ?>

                <div class="profile-empty-orders">
                    <p>No orders found.</p>
                    <a href="/jj_kitchenette/menu.php">Browse Menu</a>
                </div>

            <?php endif; ?>

        </div>

    </div>

    <script>
        document.querySelectorAll('.order-history-card[data-order-url]').forEach(card => {
            card.addEventListener('click', () => {
                window.location.href = card.dataset.orderUrl;
            });

            card.addEventListener('keydown', event => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    window.location.href = card.dataset.orderUrl;
                }
            });
        });
    </script>

</body>

</html>
