<?php
require_once __DIR__ . '/includes/session.php';
startAppSession();
include 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

$pageTitle = "Your Cart | J&J's Kitchenette";
$pageCSS = "cart.css";

// GET CART ITEMS
$stmt = $conn->prepare("
    SELECT
        cart.id AS cart_id,
        cart.quantity,
        products.id AS product_id,
        products.title,
        product_variants.option1_value,
        product_variants.option2_value,
        product_variants.option3_value,
        product_variants.price,
        (
            SELECT image_path
            FROM product_images
            WHERE product_id = products.id
            ORDER BY is_main DESC, sort_order ASC
            LIMIT 1
        ) AS image_path
    FROM cart
    LEFT JOIN products ON cart.product_id = products.id
    LEFT JOIN product_variants ON cart.variant_id = product_variants.id
    WHERE cart.user_id = ?
    ORDER BY cart.id DESC
");

$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$cartItems = [];
$total = 0;

while ($item = $result->fetch_assoc()) {
    $item['price'] = (float) $item['price'];
    $item['quantity'] = max(1, (int) $item['quantity']);
    $item['subtotal'] = $item['price'] * $item['quantity'];
    $item['image_path'] = !empty($item['image_path']) ? $item['image_path'] : 'uploads/default.png';

    $cartItems[] = $item;
    $total += $item['subtotal'];
}

$addressStmt = $conn->prepare("SELECT city FROM addresses WHERE user_id = ? ORDER BY is_default DESC, id DESC LIMIT 1");
$addressStmt->bind_param("i", $user_id);
$addressStmt->execute();
$selectedAddress = $addressStmt->get_result()->fetch_assoc();

$deliveryFee = 50;
if ($selectedAddress) {
    $city = strtolower(trim((string) $selectedAddress['city']));
    if (($city === 'tangub' || $city === 'tangub city') && $total >= 200) {
        $deliveryFee = 0;
    }
}

$grandTotal = $total + $deliveryFee;
$freeDeliveryTarget = 200;
$freeDeliveryRemaining = max(0, $freeDeliveryTarget - $total);
$freeDeliveryProgress = $freeDeliveryTarget > 0 ? min(100, ($total / $freeDeliveryTarget) * 100) : 100;

$cartProductIds = array_unique(array_map(function ($item) {
    return (int) $item['product_id'];
}, $cartItems));

$recommendations = [];
$recommendSql = "
    SELECT
        p.handle,
        p.title,
        c.name AS category_name,
        COALESCE(MIN(v.price), 0) AS min_price,
        (
            SELECT pi.image_path
            FROM product_images pi
            WHERE pi.product_id = p.id
            ORDER BY pi.is_main DESC, pi.sort_order ASC
            LIMIT 1
        ) AS image_path
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    LEFT JOIN product_variants v ON v.product_id = p.id
    WHERE p.status = 'active'
";

if (!empty($cartProductIds)) {
    $placeholders = implode(',', array_fill(0, count($cartProductIds), '?'));
    $recommendSql .= " AND p.id NOT IN ({$placeholders})";
}

$recommendSql .= "
    GROUP BY p.id, p.handle, p.title, c.name
    ORDER BY p.id DESC
    LIMIT 4
";

$recommendStmt = $conn->prepare($recommendSql);
if (!empty($cartProductIds)) {
    $types = str_repeat('i', count($cartProductIds));
    $recommendStmt->bind_param($types, ...$cartProductIds);
}
$recommendStmt->execute();
$recommendResult = $recommendStmt->get_result();

while ($product = $recommendResult->fetch_assoc()) {
    $recommendations[] = $product;
}

include('store/includes/header.php');
?>

<main class="cart-page">
    <div class="cart-container">
        <section class="cart-hero">
            <h1>Your Cart</h1>
            <p>Review your items and proceed to checkout.</p>
        </section>

        <?php if (!empty($cartItems)) { ?>
            <div class="cart-note">
                <i class="fas fa-leaf"></i>
                <span>Freshly made. Carefully packed. Delivered to you.</span>
            </div>

            <div class="cart-layout">
                <section class="cart-panel">
                    <div class="cart-table-head" aria-hidden="true">
                        <span>Item</span>
                        <span>Price</span>
                        <span>Quantity</span>
                        <span>Total</span>
                    </div>

                    <?php foreach ($cartItems as $item) { ?>
                        <?php
                        $options = array_filter([
                            $item['option1_value'],
                            $item['option2_value'],
                            $item['option3_value']
                        ], function ($value) {
                            return $value !== null && $value !== '' && strtolower($value) !== 'default';
                        });
                        ?>

                        <article class="cart-item">
                            <img
                                src="/<?php echo htmlspecialchars($item['image_path']); ?>"
                                alt="<?php echo htmlspecialchars($item['title']); ?>"
                                class="cart-image">

                            <div class="cart-info">
                                <h3><?php echo htmlspecialchars($item['title']); ?></h3>

                                <?php if (!empty($options)) { ?>
                                    <p><?php echo htmlspecialchars(implode(' / ', $options)); ?></p>
                                <?php } ?>

                                <strong>&#8369;<?php echo number_format($item['price'], 2); ?></strong>
                                <span class="cart-tag"><i class="fas fa-leaf"></i> Fresh & Delicious</span>
                            </div>

                            <div class="cart-price">
                                &#8369;<?php echo number_format($item['price'], 2); ?>
                            </div>

                            <div class="quantity-box">
                                <a
                                    href="update_cart.php?action=decrease&id=<?php echo (int) $item['cart_id']; ?>"
                                    <?php if ((int) $item['quantity'] === 1) { ?>
                                        class="js-remove-confirm"
                                        data-item-title="<?php echo htmlspecialchars($item['title']); ?>"
                                    <?php } ?>
                                    aria-label="Decrease quantity">
                                    <i class="fas fa-minus"></i>
                                </a>

                                <span><?php echo (int) $item['quantity']; ?></span>

                                <a href="update_cart.php?action=increase&id=<?php echo (int) $item['cart_id']; ?>" aria-label="Increase quantity">
                                    <i class="fas fa-plus"></i>
                                </a>
                            </div>

                            <div class="cart-right">
                                <h4>&#8369;<?php echo number_format($item['subtotal'], 2); ?></h4>

                                <a
                                    class="remove-btn"
                                    href="remove_cart_item.php?id=<?php echo (int) $item['cart_id']; ?>"
                                    aria-label="Remove <?php echo htmlspecialchars($item['title']); ?>">
                                    <i class="fas fa-trash-can"></i>
                                </a>
                            </div>
                        </article>
                    <?php } ?>

                    <a href="/menu.php" class="continue-shopping">
                        <i class="fas fa-arrow-left"></i>
                        Continue Shopping
                    </a>
                </section>

                <aside class="cart-summary">
                    <div class="cart-summary-title">
                        <i class="fas fa-bag-shopping"></i>
                        <h2>Order Summary</h2>
                    </div>

                    <div class="summary-row">
                        <span>Subtotal</span>
                        <strong>&#8369;<?php echo number_format($total, 2); ?></strong>
                    </div>

                    <div class="summary-row">
                        <span>Delivery Fee</span>
                        <strong><?php echo $deliveryFee > 0 ? '&#8369;' . number_format($deliveryFee, 2) : 'Free'; ?></strong>
                    </div>

                    <div class="summary-total">
                        <span>Total</span>
                        <strong>&#8369;<?php echo number_format($grandTotal, 2); ?></strong>
                    </div>

                    <div class="free-delivery">
                        <p>
                            <i class="fas fa-leaf"></i>
                            <?php if ($freeDeliveryRemaining > 0) { ?>
                                You're &#8369;<?php echo number_format($freeDeliveryRemaining, 2); ?> away from FREE delivery.
                            <?php } else { ?>
                                You qualify for FREE delivery.
                            <?php } ?>
                        </p>

                        <div class="free-delivery-bar">
                            <span style="width: <?php echo (int) $freeDeliveryProgress; ?>%;"></span>
                        </div>
                    </div>

                    <a href="checkout.php?source=cart" class="btn-checkout">
                        <i class="fas fa-lock"></i>
                        Proceed to Checkout
                    </a>
                </aside>
            </div>

            <?php if (!empty($recommendations)) { ?>
                <section class="cart-recommendations">
                    <h2><i class="fas fa-heart"></i> You may also like</h2>

                    <div class="recommendation-grid">
                        <?php foreach ($recommendations as $product) {
                            $recommendImage = !empty($product['image_path']) ? $product['image_path'] : 'uploads/default.png';
                        ?>
                            <article class="recommendation-card">
                                <img src="/<?php echo htmlspecialchars($recommendImage); ?>" alt="<?php echo htmlspecialchars($product['title']); ?>">

                                <div>
                                    <h3><?php echo htmlspecialchars($product['title']); ?></h3>
                                    <p><?php echo htmlspecialchars($product['category_name'] ?? 'Menu'); ?></p>
                                    <strong>&#8369;<?php echo number_format((float) $product['min_price'], 2); ?></strong>
                                    <a href="/product.php?handle=<?php echo urlencode($product['handle']); ?>">
                                        <i class="fas fa-shopping-cart"></i>
                                        View Item
                                    </a>
                                </div>
                            </article>
                        <?php } ?>
                    </div>
                </section>
            <?php } ?>
        <?php } else { ?>
            <div class="empty-cart">
                <i class="fas fa-shopping-cart"></i>
                <h2>Your cart is empty</h2>
                <p>Browse the menu and add your favorites.</p>
                <a href="/menu.php">View Menu</a>
            </div>
        <?php } ?>
    </div>
</main>

<div class="cart-modal" id="removeCartModal" aria-hidden="true">
    <div class="cart-modal__backdrop" data-close-modal></div>

    <div class="cart-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="removeCartTitle">
        <h2 id="removeCartTitle">Remove item?</h2>

        <p>
            Remove <span id="removeCartItemName">this item</span> from your cart?
        </p>

        <div class="cart-modal__actions">
            <button type="button" class="cart-modal__cancel" data-close-modal>
                Cancel
            </button>

            <a href="#" class="cart-modal__confirm" id="confirmRemoveCartItem">
                Remove
            </a>
        </div>
    </div>
</div>

<script>
    const removeCartModal = document.getElementById("removeCartModal");
    const confirmRemoveCartItem = document.getElementById("confirmRemoveCartItem");
    const removeCartItemName = document.getElementById("removeCartItemName");

    document.querySelectorAll(".js-remove-confirm").forEach(link => {
        link.addEventListener("click", event => {
            event.preventDefault();

            confirmRemoveCartItem.href = link.href;
            removeCartItemName.innerText = link.dataset.itemTitle || "this item";
            removeCartModal.classList.add("is-open");
            removeCartModal.setAttribute("aria-hidden", "false");
        });
    });

    document.querySelectorAll("[data-close-modal]").forEach(element => {
        element.addEventListener("click", () => {
            removeCartModal.classList.remove("is-open");
            removeCartModal.setAttribute("aria-hidden", "true");
        });
    });

    document.addEventListener("keydown", event => {
        if (event.key === "Escape") {
            removeCartModal.classList.remove("is-open");
            removeCartModal.setAttribute("aria-hidden", "true");
        }
    });
</script>

<?php include('store/includes/footer.php'); ?>
