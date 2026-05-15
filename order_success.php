<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userId = (int) $_SESSION['user_id'];
$orderId = (int) ($_GET['id'] ?? 0);

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
    SELECT *
    FROM order_items
    WHERE order_id = ?
    ORDER BY id ASC
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
        'delivered' => 'Delivered / Picked Up'
    ];

    return $labels[$status] ?? ucfirst($status);
}

$isPickup = $order['fulfillment_method'] !== 'delivery';
$statusLabel = orderStatusLabel($order['status']);
$deliverySavings = (float) $order['delivery_fee'] <= 0 ? 50 : 0;

$pageTitle = "Order Confirmation | J&J's Kitchenette";
$pageCSS = "checkout.css";
include('store/includes/header.php');
?>

<main class="order-success-page">
    <div class="order-success-container">
        <section class="order-success-card">
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
                    <div class="is-active">
                        <i class="fas fa-check"></i>
                        <strong>Order Confirmed</strong>
                        <span>We've received your order.</span>
                    </div>
                    <div>
                        <i class="fas fa-bag-shopping"></i>
                        <strong>Preparing Your Order</strong>
                        <span>We'll prepare it with care.</span>
                    </div>
                    <div>
                        <i class="fas fa-store"></i>
                        <strong><?php echo $isPickup ? 'Ready for Pickup' : 'Out for Delivery'; ?></strong>
                        <span>We'll notify you when it's ready.</span>
                    </div>
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
                $options = array_filter([
                    $item['option1_value'],
                    $item['option2_value'],
                    $item['option3_value']
                ], function ($value) {
                    return $value !== null && $value !== '' && strtolower($value) !== 'default';
                });
                $imagePath = !empty($item['image_path']) ? $item['image_path'] : 'uploads/default.png';
            ?>
                <div class="order-success-item">
                    <img src="/jj_kitchenette/<?php echo htmlspecialchars($imagePath); ?>" alt="<?php echo htmlspecialchars($item['product_title']); ?>">
                    <div>
                        <h3><?php echo htmlspecialchars($item['product_title']); ?></h3>
                        <?php if (!empty($options)) { ?>
                            <p><?php echo htmlspecialchars(implode(' / ', $options)); ?></p>
                        <?php } ?>
                        <span>Qty: <?php echo (int) $item['quantity']; ?></span>
                    </div>
                    <strong>&#8369;<?php echo number_format((float) $item['subtotal'], 2); ?></strong>
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
                <a href="/jj_kitchenette/menu.php">
                    <i class="fas fa-utensils"></i>
                    Order Again
                </a>
                <a href="/jj_kitchenette/account/profile.php">
                    <i class="fas fa-user"></i>
                    My Account
                </a>
            </div>
        </aside>
    </div>
</main>

<?php include('store/includes/footer.php'); ?>
