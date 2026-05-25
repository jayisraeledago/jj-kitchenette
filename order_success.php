<?php
require_once __DIR__ . '/includes/session.php';
startAppSession();
include 'db.php';
require_once __DIR__ . '/includes/order_cancel.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userId = (int) $_SESSION['user_id'];
$orderId = (int) ($_GET['id'] ?? 0);
$message = $_GET['message'] ?? '';
$messageType = $_GET['message_type'] ?? '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['form_action'] ?? '') === 'cancel_order') {
    $cancelOrderId = (int) ($_POST['order_id'] ?? 0);
    $result = cancelCustomerOrder($conn, $cancelOrderId, $userId);

    header("Location: /order_success.php?id=" . $cancelOrderId . "&message=" . urlencode($result['message']) . "&message_type=" . ($result['success'] ? 'success' : 'error'));
    exit;
}

$orderStmt = $conn->prepare("
    SELECT *
    FROM orders
    WHERE id = ?
    AND user_id = ?
    LIMIT 1
");
$orderStmt->bind_param("ii", $orderId, $userId);
$orderStmt->execute();
$order = $orderStmt->get_result()->fetch_assoc();

if (!$order) {
    header("Location: menu.php");
    exit;
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
$itemsResult = $itemStmt->get_result();
$items = [];

while ($item = $itemsResult->fetch_assoc()) {
    $items[] = $item;
}

function orderPaymentLabel($method)
{
    return $method === 'store_pickup' ? 'Store Pick Up' : 'Cash on Delivery';
}

function orderStatusLabel($status)
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

$isPickup = $order['fulfillment_method'] !== 'delivery';
$statusLabel = orderStatusLabel($order['status']);
$orderStepKeys = ['pending', 'preparing', 'shipped'];
$currentStepIndex = array_search($order['status'], $orderStepKeys, true);
if ($currentStepIndex === false) {
    $currentStepIndex = $order['status'] === 'delivered' ? 2 : 0;
}
$orderSteps = [
    [
        'key' => 'pending',
        'icon' => 'fa-check',
        'title' => 'Order Confirmed',
        'copy' => "We've received your order."
    ],
    [
        'key' => 'preparing',
        'icon' => 'fa-bag-shopping',
        'title' => 'Preparing Your Order',
        'copy' => "We'll prepare it with care."
    ],
    [
        'key' => 'shipped',
        'icon' => $isPickup ? 'fa-store' : 'fa-truck',
        'title' => $isPickup ? 'Ready for Pickup' : 'Out for Delivery',
        'copy' => "We'll notify you when it's ready."
    ]
];
$deliverySavings = !$isPickup && (float) $order['delivery_fee'] <= 0 ? 50 : 0;

$pageTitle = "Order Confirmation | J&J's Kitchenette";
$pageCSS = "checkout.css";
include('store/includes/header.php');
?>

<main class="order-success-page">
    <nav class="order-success-breadcrumbs" aria-label="Breadcrumb">
        <a href="/">
            <i class="fas fa-home"></i>
            Home
        </a>
        <i class="fas fa-chevron-right" aria-hidden="true"></i>
        <a href="/account/orders.php">My Orders</a>
        <i class="fas fa-chevron-right" aria-hidden="true"></i>
        <span><?php echo htmlspecialchars($order['order_number']); ?></span>
    </nav>

    <div class="order-success-container">
        <section class="order-success-card">
            <?php if ($message !== '') { ?>
                <div class="order-success-message <?= $messageType === 'success' ? 'order-success-message--success' : 'order-success-message--error' ?>">
                    <i class="fas <?= $messageType === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php } ?>

            <div class="order-success-hero">
                <div class="order-success-icon">
                    <i class="fas fa-check"></i>
                </div>

                <div>
                    <p class="order-success-eyebrow">Order placed</p>
                    <h1>Thank you for your order!</h1>
                    <p class="order-success-copy">
                        Your order has been successfully placed.<br>
                        We'll notify you once it's <?php echo $isPickup ? 'ready for pickup' : 'on the way'; ?>.
                    </p>
                </div>
            </div>

            <div class="order-success-details">
                <div>
                    <i class="fas fa-receipt"></i>
                    <span>Order Number</span>
                    <strong><?php echo htmlspecialchars($order['order_number']); ?></strong>
                </div>
                <div>
                    <i class="fas fa-wallet"></i>
                    <span>Payment Method</span>
                    <strong><?php echo orderPaymentLabel($order['payment_method']); ?></strong>
                </div>
                <div>
                    <i class="fas fa-clock"></i>
                    <span>Order Status</span>
                    <strong class="order-status-pill"><?php echo htmlspecialchars($statusLabel); ?></strong>
                </div>
            </div>

            <?php if ($order['fulfillment_method'] === 'delivery') { ?>
                <div class="order-success-address">
                    <div class="order-success-fulfillment-art">
                        <i class="fas fa-truck"></i>
                    </div>
                    <div>
                        <h2>Delivery Details</h2>
                        <p>
                            <strong><?php echo htmlspecialchars($order['customer_name']); ?></strong><br>
                            <?php echo htmlspecialchars($order['address_line']); ?><br>
                            <?php echo htmlspecialchars($order['city']); ?><br>
                            <?php echo htmlspecialchars($order['phone']); ?>
                        </p>
                    </div>
                </div>
            <?php } else { ?>
                <div class="order-success-address">
                    <div class="order-success-fulfillment-art">
                        <i class="fas fa-store"></i>
                    </div>
                    <div>
                        <h2>Store Pick Up</h2>
                        <p>Your order is marked for pickup.<br>No delivery fee was added.</p>
                    </div>
                </div>
            <?php } ?>

            <div class="order-next">
                <h2><i class="fas fa-clipboard-check"></i> What's Next?</h2>

                <div class="order-steps">
                    <?php foreach ($orderSteps as $index => $step) {
                        $stepState = $index < $currentStepIndex ? 'is-complete' : ($index === $currentStepIndex ? 'is-active' : '');
                    ?>
                        <div class="<?= htmlspecialchars($stepState) ?>">
                            <i class="fas <?= htmlspecialchars($stepState === 'is-complete' ? 'fa-check' : $step['icon']) ?>"></i>
                            <strong><?= htmlspecialchars($step['title']) ?></strong>
                            <span><?= htmlspecialchars($step['copy']) ?></span>
                        </div>
                    <?php } ?>
                </div>
            </div>

            <div class="order-success-note">
                <i class="fas fa-leaf"></i>
                <span>You will receive an email or SMS once your order is <?php echo $isPickup ? 'ready for pickup' : 'on the way'; ?>.</span>
            </div>
        </section>

        <aside class="order-success-summary">
            <div class="order-success-summary-title">
                <i class="fas fa-bag-shopping"></i>
                <h2>Order Summary</h2>
            </div>

            <?php foreach ($items as $item) {
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
                <div class="order-success-item <?= $isCanceledItem ? 'order-success-item--canceled' : '' ?>">
                    <img src="/<?php echo htmlspecialchars($imagePath); ?>" alt="<?php echo htmlspecialchars($item['product_title']); ?>">
                    <div>
                        <h3>
                            <?php echo htmlspecialchars($item['product_title']); ?>
                            <?php if ($isCanceledItem) { ?>
                                <em class="order-success-item__badge">Canceled</em>
                            <?php } ?>
                        </h3>
                        <?php if (!empty($options)) { ?>
                            <p><?php echo htmlspecialchars(implode(' / ', $options)); ?></p>
                        <?php } ?>
                        <span>SKU: <?php echo htmlspecialchars($item['display_sku'] ?? '-'); ?></span>
                        <span>Qty: <?php echo (int) $item['quantity']; ?></span>
                        <?php if ($isCanceledItem && !empty($item['cancel_reason'])) { ?>
                            <span>Reason: <?php echo htmlspecialchars($item['cancel_reason']); ?></span>
                        <?php } ?>
                    </div>
                    <strong>
                        <?php echo $isCanceledItem ? 'Removed' : '&#8369;' . number_format((float) $item['subtotal'], 2); ?>
                    </strong>
                </div>
            <?php } ?>

            <div class="order-success-totals">
                <div>
                    <span>Subtotal</span>
                    <strong>&#8369;<?php echo number_format((float) $order['subtotal'], 2); ?></strong>
                </div>
                <div>
                    <span>Delivery</span>
                    <strong><?php echo (float) $order['delivery_fee'] > 0 ? '&#8369;' . number_format((float) $order['delivery_fee'], 2) : 'Free'; ?></strong>
                </div>
                <div class="order-success-total">
                    <span>Total</span>
                    <strong>&#8369;<?php echo number_format((float) $order['total'], 2); ?></strong>
                </div>
            </div>

            <?php if ($deliverySavings > 0) { ?>
                <div class="order-savings-note">
                    <i class="fas fa-leaf"></i>
                    <span>You saved &#8369;<?php echo number_format($deliverySavings, 2); ?> with free delivery!</span>
                </div>
            <?php } ?>

            <div class="order-thanks-note">
                <i class="far fa-heart"></i>
                <span>
                    <strong>Thank you for choosing J&J Kitchenette!</strong>
                    We appreciate your support.
                </span>
            </div>

            <div class="order-success-actions">
                <a href="/menu.php">
                    <i class="fas fa-utensils"></i>
                    Order Again
                </a>
                <a href="/account/profile.php">
                    <i class="fas fa-user"></i>
                    My Account
                </a>
            </div>

            <?php if ($order['status'] === 'pending') { ?>
                <form method="POST" class="order-success-cancel-form js-cancel-order-form">
                    <input type="hidden" name="form_action" value="cancel_order">
                    <input type="hidden" name="order_id" value="<?php echo (int) $order['id']; ?>">
                    <button type="submit">
                        <i class="fas fa-ban"></i>
                        Cancel Order
                    </button>
                </form>
            <?php } ?>
        </aside>
    </div>
</main>

<div class="customer-cancel-order-modal" id="cancelOrderModal" aria-hidden="true">
    <div class="customer-cancel-order-modal__panel">
        <button type="button" class="customer-cancel-order-modal__close" id="closeCancelOrderModal" aria-label="Close cancel order confirmation">
            <i class="fas fa-times"></i>
        </button>

        <div class="customer-cancel-order-modal__icon">
            <i class="fas fa-ban"></i>
        </div>

        <h2>Cancel this order?</h2>
        <p>This can only be done while the order is pending. Your items will be released back to inventory and you will receive an email update.</p>

        <div class="customer-cancel-order-modal__actions">
            <button type="button" id="keepOrderButton">Keep Order</button>
            <button type="button" id="confirmCancelOrderButton">
                <i class="fas fa-ban"></i>
                Cancel Order
            </button>
        </div>
    </div>
</div>

<script>
    const cancelOrderModal = document.getElementById('cancelOrderModal');
    const closeCancelOrderModal = document.getElementById('closeCancelOrderModal');
    const keepOrderButton = document.getElementById('keepOrderButton');
    const confirmCancelOrderButton = document.getElementById('confirmCancelOrderButton');
    let pendingCancelOrderForm = null;

    function openCancelOrderModal(form) {
        pendingCancelOrderForm = form;
        cancelOrderModal?.classList.add('is-open');
        cancelOrderModal?.setAttribute('aria-hidden', 'false');
    }

    function closeCancelOrderConfirmModal() {
        pendingCancelOrderForm = null;
        cancelOrderModal?.classList.remove('is-open');
        cancelOrderModal?.setAttribute('aria-hidden', 'true');
    }

    document.querySelectorAll('.js-cancel-order-form').forEach(form => {
        form.addEventListener('submit', event => {
            event.preventDefault();
            openCancelOrderModal(form);
        });
    });

    confirmCancelOrderButton?.addEventListener('click', () => {
        const form = pendingCancelOrderForm;
        closeCancelOrderConfirmModal();
        form?.submit();
    });

    keepOrderButton?.addEventListener('click', closeCancelOrderConfirmModal);
    closeCancelOrderModal?.addEventListener('click', closeCancelOrderConfirmModal);
    cancelOrderModal?.addEventListener('click', event => {
        if (event.target === cancelOrderModal) {
            closeCancelOrderConfirmModal();
        }
    });
</script>

<?php include('store/includes/footer.php'); ?>
