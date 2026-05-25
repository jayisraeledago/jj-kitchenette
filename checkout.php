<?php
require_once __DIR__ . '/includes/session.php';
startAppSession();
include 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$source = $_POST['source'] ?? $_GET['source'] ?? 'cart';
$items = [];
$total = 0;

$userStmt = $conn->prepare("
    SELECT first_name, last_name
    FROM users
    WHERE id = ?
    LIMIT 1
");
$userStmt->bind_param("i", $user_id);
$userStmt->execute();
$checkoutUser = $userStmt->get_result()->fetch_assoc() ?: [];
$checkoutFullName = trim(implode(' ', array_filter([
    $checkoutUser['first_name'] ?? '',
    $checkoutUser['last_name'] ?? ''
])));

// For direct checkout, we need to keep track of these to pass to the final order script
$direct_product_id = $_POST['product_id'] ?? null;
$direct_variant_id = $_POST['variant_id'] ?? null;
$direct_qty = $_POST['quantity'] ?? null;

function getCheckoutOptions($item)
{
    return array_filter([
        $item['option1_value'] ?? null,
        $item['option2_value'] ?? null,
        $item['option3_value'] ?? null
    ], function ($value) {
        return $value !== null && $value !== '' && strtolower($value) !== 'default';
    });
}

function getDeliveryFee($subtotal, $city, $paymentMethod)
{
    if ($paymentMethod === 'store_pickup') {
        return 0;
    }

    $city = strtolower(trim((string) $city));

    return ($city === 'tangub' || $city === 'tangub city') && $subtotal >= 200 ? 0 : 50;
}

if ($source === 'direct') {
    $product_id = (int) $direct_product_id;
    $variant_id = (int) $direct_variant_id;
    $quantity = max(1, (int) $direct_qty);

    $stmt = $conn->prepare("
            SELECT 
                products.id AS product_id, 
                products.title, 
                product_variants.id AS variant_id,
                product_variants.option1_value, 
                product_variants.option2_value, 
                product_variants.option3_value,
                product_variants.price, 
                product_variants.inventory,
                (
                    SELECT image_path 
                    FROM product_images 
                    WHERE product_id = products.id 
                    ORDER BY is_main DESC, sort_order ASC 
                    LIMIT 1
                ) AS image_path
            FROM products
            INNER JOIN product_variants ON product_variants.product_id = products.id
            WHERE products.status = 'active' 
            AND products.id = ? 
            AND product_variants.id = ?
            LIMIT 1
        ");

    $stmt->bind_param("ii", $product_id, $variant_id);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc();

    if ($item) {
        $item['quantity'] = min($quantity, max(1, (int) $item['inventory']));
        $items[] = $item;
    }
} else {
    $source = 'cart';
    $stmt = $conn->prepare("
            SELECT 
                cart.quantity, 
                products.id AS product_id, 
                products.title, 
                product_variants.id AS variant_id,
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
            INNER JOIN products ON cart.product_id = products.id
            INNER JOIN product_variants ON cart.variant_id = product_variants.id
            WHERE cart.user_id = ?
            ORDER BY cart.id DESC
        ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }
}

foreach ($items as $index => $item) {
    $items[$index]['price'] = (float) $item['price'];
    $items[$index]['quantity'] = max(1, (int) $item['quantity']);
    $items[$index]['subtotal'] = $items[$index]['price'] * $items[$index]['quantity'];

    $total += $items[$index]['subtotal'];
}

$addressStmt = $conn->prepare("SELECT * FROM addresses WHERE user_id = ? ORDER BY is_default DESC, id DESC");
$addressStmt->bind_param("i", $user_id);
$addressStmt->execute();
$addressResult = $addressStmt->get_result();
$addresses = [];
while ($row = $addressResult->fetch_assoc()) {
    $addresses[] = $row;
}
$selectedAddress = $addresses[0] ?? null;
$paymentMethod = 'cod';
$deliveryFee = getDeliveryFee($total, $selectedAddress['city'] ?? '', $paymentMethod);
$grandTotal = $total + $deliveryFee;
$itemCount = array_sum(array_map(function ($item) {
    return (int) $item['quantity'];
}, $items));
$freeDeliveryTarget = 200;
$freeDeliveryRemaining = max(0, $freeDeliveryTarget - $total);
$freeDeliveryProgress = $freeDeliveryTarget > 0 ? min(100, ($total / $freeDeliveryTarget) * 100) : 100;

$pageTitle = "Checkout | J&J's Kitchenette";
$pageCSS = "checkout.css";
include('store/includes/header.php');
?>

<main class="checkout-page">
    <div class="checkout-container">
        <section class="checkout-hero">
            <h1>Checkout <i class="fas fa-leaf"></i></h1>
            <p>Review your order, choose a payment method, and place your order.</p>
        </section>

        <?php if (empty($items)) { ?>
            <div class="checkout-empty">
                <i class="fas fa-bag-shopping"></i>
                <h2>No items to checkout.</h2>
                <a href="/menu.php">Browse Menu</a>
            </div>
        <?php } else { ?>
            <div class="checkout-secure-note">
                <i class="fas fa-check"></i>
                <span>Your order is secure and your information is protected.</span>
            </div>

            <form action="process_order.php" method="POST" id="checkoutForm">
                <input type="hidden" name="source" value="<?php echo htmlspecialchars($source); ?>">
                <input type="hidden" name="delivery_fee" id="deliveryFeeInput" value="<?php echo number_format($deliveryFee, 2, '.', ''); ?>">
                <input type="hidden" name="order_total" id="orderTotalInput" value="<?php echo number_format($grandTotal, 2, '.', ''); ?>">
                <?php if ($source === 'direct') { ?>
                    <input type="hidden" name="product_id" value="<?php echo (int) $direct_product_id; ?>">
                    <input type="hidden" name="variant_id" value="<?php echo (int) $direct_variant_id; ?>">
                    <input type="hidden" name="quantity" value="<?php echo (int) ($items[0]['quantity'] ?? 1); ?>">
                <?php } ?>

                <div class="checkout-layout">
                    <section class="checkout-section">
                        <div class="checkout-section-header">
                            <div>
                                <i class="fas fa-location-dot"></i>
                                <h2>Delivery Details</h2>
                            </div>
                            <?php if (!empty($addresses)) { ?>
                                <button type="button" class="edit-address-btn" onclick="toggleAddressList()">
                                    <i class="fas fa-pen"></i>
                                    Change
                                </button>
                            <?php } ?>
                        </div>

                        <?php if ($selectedAddress) { ?>
                            <div class="checkout-address-wrap">
                                <div class="checkout-address-art">
                                    <i class="fas fa-house-chimney"></i>
                                </div>

                                <div class="checkout-address" id="currentAddressDisplay">
                                    <strong><?php echo htmlspecialchars($selectedAddress['full_name']); ?></strong>
                                    <span><?php echo htmlspecialchars($selectedAddress['address_line']); ?></span>
                                    <span><?php echo htmlspecialchars($selectedAddress['city']); ?></span>
                                    <span><i class="fas fa-phone"></i> <?php echo htmlspecialchars($selectedAddress['phone']); ?></span>
                                </div>
                            </div>

                            <div class="address-list" id="addressList" style="display:none;">
                                <?php foreach ($addresses as $address) { ?>
                                    <label class="address-option" data-city="<?php echo htmlspecialchars($address['city']); ?>">
                                        <input type="radio" name="address_id" value="<?php echo $address['id']; ?>" <?php echo $address['is_default'] ? 'checked' : ''; ?> required>
                                        <div>
                                            <strong><?php echo htmlspecialchars($address['full_name']); ?></strong>
                                            <p><?php echo htmlspecialchars($address['address_line']); ?>,
                                                <?php echo htmlspecialchars($address['city']); ?>
                                            </p>
                                            <p><?php echo htmlspecialchars($address['phone']); ?></p>
                                        </div>
                                    </label>
                                <?php } ?>
                                <button type="button" class="add-new-address" onclick="openAddressModal()">
                                    + Add New Address
                                </button>
                            </div>
                        <?php } else { ?>
                            <p class="checkout-muted">No saved address yet.</p>
                            <button type="button" class="add-new-address" onclick="openAddressModal()">
                                + Add Address
                            </button>
                        <?php } ?>
                    </section>

                    <section class="checkout-section payment-section">
                        <div class="checkout-section-header">
                            <div>
                                <i class="fas fa-credit-card"></i>
                                <h2>Payment Method</h2>
                            </div>
                        </div>

                        <div class="payment-options">
                            <label class="payment-option">
                                <input type="radio" name="payment_method" value="cod" checked>
                                <span class="payment-icon"><i class="fas fa-wallet"></i></span>
                                <div>
                                    <strong>Cash on Delivery</strong>
                                    <p>Pay when your order arrives.</p>
                                </div>
                            </label>

                            <label class="payment-option">
                                <input type="radio" name="payment_method" value="store_pickup">
                                <span class="payment-icon"><i class="fas fa-store"></i></span>
                                <div>
                                    <strong>Store Pick Up</strong>
                                    <p>Pick up your order from the store. No delivery fee.</p>
                                </div>
                            </label>
                        </div>

                        <p class="checkout-note" id="deliveryRuleNote">
                            Cash on Delivery is available in Tangub or Tangub City. Free delivery applies when the order reaches &#8369;200.00.
                        </p>
                        <p class="checkout-warning" id="checkoutWarning" hidden></p>
                    </section>

                    <section class="checkout-section">
                        <div class="checkout-section-header">
                            <div>
                                <i class="fas fa-bag-shopping"></i>
                                <h2>Items</h2>
                            </div>
                        </div>
                        <?php foreach ($items as $item) {
                            $imagePath = !empty($item['image_path']) ? $item['image_path'] : 'uploads/default.png';
                            $options = getCheckoutOptions($item);
                            ?>
                            <div class="checkout-item">
                                <img src="/<?php echo htmlspecialchars($imagePath); ?>" alt="Product">
                                <div class="checkout-item__info">
                                    <h3><?php echo htmlspecialchars($item['title']); ?></h3>
                                    <?php if (!empty($options)) { ?>
                                        <p><?php echo htmlspecialchars(implode(' / ', $options)); ?></p>
                                    <?php } ?>
                                    <span>Qty: <?php echo (int) $item['quantity']; ?></span>
                                </div>
                                <strong>&#8369;<?php echo number_format($item['subtotal'], 2); ?></strong>
                            </div>
                        <?php } ?>

                        <div class="checkout-items-total">
                            <span><?php echo count($items); ?> item<?php echo count($items) === 1 ? '' : 's'; ?></span>
                            <strong>Total Items (<?php echo (int) $itemCount; ?>)</strong>
                        </div>
                    </section>

                    <aside class="checkout-summary">
                        <div class="checkout-summary-title">
                            <i class="fas fa-bag-shopping"></i>
                            <h2>Order Summary</h2>
                        </div>

                        <div class="summary-row">
                            <span>Subtotal</span>
                            <strong>&#8369;<span id="summarySubtotal"><?php echo number_format($total, 2); ?></span></strong>
                        </div>
                        <div class="summary-row">
                            <span>Delivery Fee</span>
                            <strong id="summaryDelivery">
                                <?php echo $deliveryFee > 0 ? '&#8369;' . number_format($deliveryFee, 2) : 'Free'; ?>
                            </strong>
                        </div>
                        <div class="summary-total">
                            <span>Total</span>
                            <strong>&#8369;<span id="summaryTotal"><?php echo number_format($grandTotal, 2); ?></span></strong>
                        </div>

                        <div class="checkout-free-delivery">
                            <p>
                                <i class="fas fa-leaf"></i>
                                <span id="freeDeliveryText">
                                    <?php if ($freeDeliveryRemaining > 0) { ?>
                                        You're &#8369;<?php echo number_format($freeDeliveryRemaining, 2); ?> away from FREE delivery.
                                    <?php } else { ?>
                                        You qualify for FREE delivery.
                                    <?php } ?>
                                </span>
                            </p>
                            <div class="checkout-progress">
                                <span id="freeDeliveryProgress" style="width: <?php echo (int) $freeDeliveryProgress; ?>%;"></span>
                            </div>
                        </div>

                        <button class="place-order-btn" id="placeOrderBtn" type="submit" <?php echo !$selectedAddress ? 'disabled' : ''; ?>>
                            <i class="fas fa-lock"></i>
                            Place Order
                        </button>

                        <div class="checkout-benefits">
                            <div>
                                <i class="fas fa-shield-halved"></i>
                                <span>
                                    <strong>Secure Checkout</strong>
                                    Your information is safe with us.
                                </span>
                            </div>
                            <div>
                                <i class="fas fa-truck"></i>
                                <span>
                                    <strong>Fast Delivery</strong>
                                    We deliver your food fresh & on time.
                                </span>
                            </div>
                        </div>
                    </aside>
                </div>
            </form>

            <div class="checkout-confirm-modal" id="checkoutConfirmModal" aria-hidden="true">
                <div class="checkout-confirm-modal__panel" role="dialog" aria-modal="true" aria-labelledby="checkoutConfirmTitle">
                    <button type="button" class="checkout-confirm-modal__close" id="checkoutConfirmClose" aria-label="Close confirmation">
                        <i class="fas fa-times"></i>
                    </button>
                    <span class="checkout-confirm-modal__icon" id="checkoutConfirmIcon">
                        <i class="fas fa-wallet"></i>
                    </span>
                    <h2 id="checkoutConfirmTitle">Confirm order method</h2>
                    <p id="checkoutConfirmMessage">
                        Please confirm your order method before placing your order.
                    </p>
                    <div class="checkout-confirm-modal__details">
                        <span>Selected method</span>
                        <strong id="checkoutConfirmMethod">Cash on Delivery</strong>
                    </div>
                    <div class="checkout-confirm-modal__actions">
                        <button type="button" id="checkoutConfirmCancel">Review order</button>
                        <button type="button" id="checkoutConfirmSubmit">
                            <i class="fas fa-check"></i>
                            Place order
                        </button>
                    </div>
                </div>
            </div>
        <?php } ?>
    </div>
</main>

<script>
    const checkoutSubtotal = <?php echo json_encode((float) $total); ?>;

    function formatMoney(amount) {
        return Number(amount).toLocaleString('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function getSelectedPaymentMethod() {
        return document.querySelector('input[name="payment_method"]:checked')?.value || 'cod';
    }

    function getPaymentMethodLabel(method) {
        return method === 'store_pickup' ? 'Store Pick Up' : 'Cash on Delivery';
    }

    function getSelectedAddressRadio() {
        return document.querySelector('input[name="address_id"]:checked');
    }

    function getSelectedCity() {
        return getSelectedAddressRadio()?.closest('.address-option')?.dataset.city || '';
    }

    function normalizeCity(city) {
        return city.trim().toLowerCase();
    }

    function isTangubCity(city) {
        const normalizedCity = normalizeCity(city);
        return normalizedCity === 'tangub' || normalizedCity === 'tangub city';
    }

    function calculateDeliveryFee() {
        const paymentMethod = getSelectedPaymentMethod();

        if (paymentMethod === 'store_pickup') {
            return 0;
        }

        return isTangubCity(getSelectedCity()) && checkoutSubtotal >= 200 ? 0 : 50;
    }

    function updateCheckoutSummary() {
        const paymentMethod = getSelectedPaymentMethod();
        const deliveryFee = calculateDeliveryFee();
        const orderTotal = checkoutSubtotal + deliveryFee;
        const deliveryDisplay = document.getElementById('summaryDelivery');
        const totalDisplay = document.getElementById('summaryTotal');
        const deliveryInput = document.getElementById('deliveryFeeInput');
        const totalInput = document.getElementById('orderTotalInput');
        const placeOrderBtn = document.getElementById('placeOrderBtn');
        const checkoutWarning = document.getElementById('checkoutWarning');
        const freeDeliveryText = document.getElementById('freeDeliveryText');
        const freeDeliveryProgress = document.getElementById('freeDeliveryProgress');
        const addressInputs = document.querySelectorAll('input[name="address_id"]');
        const needsAddress = paymentMethod === 'cod';
        const selectedCity = getSelectedCity();
        const isCodOutsideDeliveryArea = paymentMethod === 'cod' && selectedCity !== '' && !isTangubCity(selectedCity);
        const remainingForFreeDelivery = Math.max(0, 200 - checkoutSubtotal);

        addressInputs.forEach(input => {
            input.required = needsAddress;
        });

        if (deliveryDisplay) {
            deliveryDisplay.innerHTML = deliveryFee > 0 ? `&#8369;${formatMoney(deliveryFee)}` : 'Free';
        }

        if (totalDisplay) {
            totalDisplay.innerText = formatMoney(orderTotal);
        }

        if (deliveryInput) {
            deliveryInput.value = deliveryFee.toFixed(2);
        }

        if (totalInput) {
            totalInput.value = orderTotal.toFixed(2);
        }

        if (freeDeliveryText) {
            freeDeliveryText.innerHTML = remainingForFreeDelivery > 0
                ? `You're &#8369;${formatMoney(remainingForFreeDelivery)} away from FREE delivery.`
                : 'You qualify for FREE delivery.';
        }

        if (freeDeliveryProgress) {
            freeDeliveryProgress.style.width = `${Math.min(100, (checkoutSubtotal / 200) * 100)}%`;
        }

        if (placeOrderBtn) {
            placeOrderBtn.disabled = (needsAddress && !getSelectedAddressRadio()) || isCodOutsideDeliveryArea;
        }

        if (checkoutWarning) {
            if (isCodOutsideDeliveryArea) {
                checkoutWarning.hidden = false;
                checkoutWarning.innerText = 'Cash on Delivery is only available in Tangub or Tangub City. Please choose Store Pick Up to continue.';
            } else {
                checkoutWarning.hidden = true;
                checkoutWarning.innerText = '';
            }
        }
    }

    function toggleAddressList() {
        const list = document.getElementById('addressList');

        if (list.style.display === 'none') {
            list.style.display = 'block';
        } else {
            list.style.display = 'none';
        }
    }

    document.addEventListener('change', function (event) {
        if (event.target.matches('input[name="address_id"]')) {
            const addressCard = event.target.closest('.address-option');
            const currentAddress = document.getElementById('currentAddressDisplay');
            const name = addressCard.querySelector('strong').innerText;
            const paragraphs = addressCard.querySelectorAll('p');
            const addressLine = paragraphs[0].innerText;
            const phone = paragraphs[1].innerText;

            if (currentAddress) {
                currentAddress.innerHTML = `
                    <strong>${name}</strong>
                    <span>${addressLine}</span>
                    <span>${phone}</span>
                `;
            }

            const addressList = document.getElementById('addressList');
            if (addressList) {
                addressList.style.display = 'none';
            }

            updateCheckoutSummary();
        }

        if (event.target.matches('input[name="payment_method"]')) {
            updateCheckoutSummary();
        }
    });

    const checkoutForm = document.getElementById('checkoutForm');
    const checkoutConfirmModal = document.getElementById('checkoutConfirmModal');
    const checkoutConfirmTitle = document.getElementById('checkoutConfirmTitle');
    const checkoutConfirmMessage = document.getElementById('checkoutConfirmMessage');
    const checkoutConfirmMethod = document.getElementById('checkoutConfirmMethod');
    const checkoutConfirmIcon = document.getElementById('checkoutConfirmIcon');
    const checkoutConfirmSubmit = document.getElementById('checkoutConfirmSubmit');
    const checkoutConfirmCancel = document.getElementById('checkoutConfirmCancel');
    const checkoutConfirmClose = document.getElementById('checkoutConfirmClose');
    let checkoutConfirmApproved = false;

    function openCheckoutConfirmModal() {
        const method = getSelectedPaymentMethod();
        const methodLabel = getPaymentMethodLabel(method);

        if (checkoutConfirmMethod) {
            checkoutConfirmMethod.innerText = methodLabel;
        }

        if (checkoutConfirmIcon) {
            checkoutConfirmIcon.innerHTML = method === 'store_pickup'
                ? '<i class="fas fa-store"></i>'
                : '<i class="fas fa-truck"></i>';
        }

        if (checkoutConfirmTitle) {
            checkoutConfirmTitle.innerText = method === 'store_pickup'
                ? 'Confirm store pickup'
                : 'Confirm cash on delivery';
        }

        if (checkoutConfirmMessage) {
            checkoutConfirmMessage.innerText = method === 'store_pickup'
                ? "You selected Store Pick Up. Your order will not be delivered, and you will pick it up from J&J's Kitchenette when it is ready."
                : 'You selected Cash on Delivery. Your order will be prepared for delivery, and payment will be collected when it arrives.';
        }

        checkoutConfirmModal?.classList.add('is-open');
        checkoutConfirmModal?.setAttribute('aria-hidden', 'false');
    }

    function closeCheckoutConfirmModal() {
        checkoutConfirmModal?.classList.remove('is-open');
        checkoutConfirmModal?.setAttribute('aria-hidden', 'true');
    }

    function handleCheckoutOrderAttempt(event) {
        if (checkoutConfirmApproved) {
            return;
        }

        event.preventDefault();
        updateCheckoutSummary();

        const placeOrderBtn = document.getElementById('placeOrderBtn');
        if (placeOrderBtn?.disabled) {
            return;
        }

        if (checkoutForm && typeof checkoutForm.reportValidity === 'function' && !checkoutForm.reportValidity()) {
            return;
        }

        openCheckoutConfirmModal();
    }

    checkoutForm?.addEventListener('submit', handleCheckoutOrderAttempt);
    document.getElementById('placeOrderBtn')?.addEventListener('click', handleCheckoutOrderAttempt);

    checkoutConfirmSubmit?.addEventListener('click', function () {
        if (!checkoutForm) {
            return;
        }

        checkoutConfirmApproved = true;
        closeCheckoutConfirmModal();
        if (typeof checkoutForm.requestSubmit === 'function') {
            checkoutForm.requestSubmit();
            return;
        }

        checkoutForm.submit();
    });

    checkoutConfirmCancel?.addEventListener('click', closeCheckoutConfirmModal);
    checkoutConfirmClose?.addEventListener('click', closeCheckoutConfirmModal);
    checkoutConfirmModal?.addEventListener('click', function (event) {
        if (event.target === checkoutConfirmModal) {
            closeCheckoutConfirmModal();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && checkoutConfirmModal?.classList.contains('is-open')) {
            closeCheckoutConfirmModal();
        }
    });

    updateCheckoutSummary();
</script>

<div class="address-modal" id="addressModal">

    <div class="address-modal-content">

        <div class="address-modal-header">
            <h2>Add Address</h2>

            <button type="button" class="close-modal-btn" onclick="closeAddressModal()">
                &times;
            </button>
        </div>

        <form id="addressForm">

            <div class="form-group">
                <label>Recipient Name</label>

                <input type="text" name="full_name" value="<?php echo htmlspecialchars($checkoutFullName); ?>" required>
            </div>

            <div class="form-group">
                <label>Address</label>

                <input type="text" name="address_line" required>
            </div>

            <div class="form-group">
                <label>City</label>

                <input type="text" name="city" required>
            </div>

            <div class="form-group">
                <label>Phone</label>

                <input type="text" name="phone" required>
            </div>

            <label class="default-check">
                <input type="checkbox" name="is_default" value="1">

                This is my default address
            </label>

            <button type="submit" class="save-address-btn">
                Save Address
            </button>

        </form>

    </div>

</div>

<script>
    const checkoutDefaultFullName = <?php echo json_encode($checkoutFullName); ?>;

    function openAddressModal() {
        const nameInput = document.querySelector('#addressForm [name="full_name"]');
        if (nameInput && nameInput.value.trim() === '') {
            nameInput.value = checkoutDefaultFullName;
        }

        document.getElementById('addressModal').style.display = 'flex';
    }

    function closeAddressModal() {
        document.getElementById('addressModal').style.display = 'none';
    }

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.innerText = value;
        return div.innerHTML;
    }

    function renderCurrentAddress(address) {
        let currentAddress = document.getElementById('currentAddressDisplay');

        if (!currentAddress) {
            const deliverySection = document.querySelector('.checkout-section');
            const muted = deliverySection.querySelector('.checkout-muted');
            const addButton = deliverySection.querySelector('.add-new-address');

            if (muted) {
                muted.remove();
            }

            if (addButton) {
                addButton.remove();
            }

            currentAddress = document.createElement('div');
            currentAddress.className = 'checkout-address';
            currentAddress.id = 'currentAddressDisplay';
            deliverySection.appendChild(currentAddress);
        }

        currentAddress.innerHTML = `
            <strong>${escapeHtml(address.full_name)}</strong>
            <span>${escapeHtml(address.address_line)}</span>
            <span>${escapeHtml(address.city)}</span>
            <span>${escapeHtml(address.phone)}</span>
        `;
    }

    function addAddressToList(address) {
        let addressList = document.getElementById('addressList');

        if (!addressList) {
            const deliverySection = document.querySelector('.checkout-section');
            addressList = document.createElement('div');
            addressList.className = 'address-list';
            addressList.id = 'addressList';
            addressList.style.display = 'none';
            deliverySection.appendChild(addressList);
        }

        addressList.insertAdjacentHTML('afterbegin', `
            <label class="address-option" data-city="${escapeHtml(address.city)}">
                <input type="radio" name="address_id" value="${address.id}" checked required>
                <div>
                    <strong>${escapeHtml(address.full_name)}</strong>
                    <p>${escapeHtml(address.address_line)}, ${escapeHtml(address.city)}</p>
                    <p>${escapeHtml(address.phone)}</p>
                </div>
            </label>
        `);
    }

    document.getElementById('addressForm').addEventListener('submit', async function (e) {
        e.preventDefault();

        const form = this;
        const formData = new FormData(form);

        const response = await fetch('save_address_ajax.php', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            renderCurrentAddress(data.address);
            addAddressToList(data.address);

            const addressList = document.getElementById('addressList');
            if (addressList) {
                addressList.style.display = 'none';
            }

            updateCheckoutSummary();

            form.reset();
            const nameInput = form.querySelector('[name="full_name"]');
            if (nameInput) {
                nameInput.value = checkoutDefaultFullName;
            }
            closeAddressModal();
        } else {
            alert(data.message || 'Failed to save address');
        }
    });
</script>

<?php include('store/includes/footer.php'); ?>
