<?php
require_once __DIR__ . '/includes/session.php';
startAppSession();
require_once __DIR__ . '/includes/images.php';
include 'db.php';

$handle = trim($_GET['handle'] ?? '');

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function productExcerpt($text, $limit = 170)
{
    $text = trim(strip_tags((string) $text));

    if ($text === '') {
        return 'Freshly prepared and ready to order from J&J Kitchenette.';
    }

    if (strlen($text) <= $limit) {
        return $text;
    }

    return rtrim(substr($text, 0, $limit - 3)) . '...';
}

if ($handle === '') {
    http_response_code(404);
    $pageTitle = "Product Not Found | J&J's Kitchenette";
    $pageCSS = 'product.css';
    include('store/includes/header.php');
    ?>
    <main class="product-page">
        <section class="product-empty">
            <h1>Product not found</h1>
            <p>Please choose an item from the menu.</p>
            <a href="menu.php">View menu</a>
        </section>
    </main>
    <?php
    include('store/includes/footer.php');
    exit;
}

$productStmt = $conn->prepare("
    SELECT
        p.id,
        p.handle,
        p.title,
        p.body,
        p.category_id,
        c.name AS category_name
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    WHERE p.status = 'active'
    AND p.handle = ?
    LIMIT 1
");
$productStmt->bind_param("s", $handle);
$productStmt->execute();
$product = $productStmt->get_result()->fetch_assoc();

if (!$product) {
    http_response_code(404);
    $pageTitle = "Product Not Found | J&J's Kitchenette";
    $pageCSS = 'product.css';
    include('store/includes/header.php');
    ?>
    <main class="product-page">
        <section class="product-empty">
            <h1>Product not found</h1>
            <p>This item may be unavailable or no longer active.</p>
            <a href="menu.php">Back to menu</a>
        </section>
    </main>
    <?php
    include('store/includes/footer.php');
    exit;
}

$productId = (int) $product['id'];

$imageStmt = $conn->prepare("
    SELECT image_path
    FROM product_images
    WHERE product_id = ?
    ORDER BY is_main DESC, sort_order ASC, id ASC
");
$imageStmt->bind_param("i", $productId);
$imageStmt->execute();
$imageResult = $imageStmt->get_result();

$images = [];
while ($row = $imageResult->fetch_assoc()) {
    $images[] = $row;
}

if (empty($images)) {
    $images[] = ['image_path' => 'uploads/default.png'];
}

$optionStmt = $conn->prepare("
    SELECT id, option_name
    FROM product_options
    WHERE product_id = ?
    ORDER BY id ASC
");
$optionStmt->bind_param("i", $productId);
$optionStmt->execute();
$optionResult = $optionStmt->get_result();

$options = [];
while ($option = $optionResult->fetch_assoc()) {
    $valueStmt = $conn->prepare("
        SELECT value
        FROM product_option_values
        WHERE option_id = ?
        ORDER BY id ASC
    ");
    $optionId = (int) $option['id'];
    $valueStmt->bind_param("i", $optionId);
    $valueStmt->execute();
    $valueResult = $valueStmt->get_result();

    $values = [];
    while ($value = $valueResult->fetch_assoc()) {
        $values[] = $value['value'];
    }

    if (!empty($values)) {
        $options[] = [
            'name' => $option['option_name'],
            'values' => $values
        ];
    }
}

$variantStmt = $conn->prepare("
    SELECT id, option1_value, option2_value, option3_value, price, inventory, sku
    FROM product_variants
    WHERE product_id = ?
    ORDER BY id ASC
");
$variantStmt->bind_param("i", $productId);
$variantStmt->execute();
$variantResult = $variantStmt->get_result();

$variants = [];
$minPrice = null;
$totalInventory = 0;

while ($variant = $variantResult->fetch_assoc()) {
    $variant['id'] = (int) $variant['id'];
    $variant['price'] = (float) $variant['price'];
    $variant['inventory'] = (int) $variant['inventory'];
    $variants[] = $variant;
    $totalInventory += $variant['inventory'];

    if ($minPrice === null || $variant['price'] < $minPrice) {
        $minPrice = $variant['price'];
    }
}

$firstImage = appImageUrl($images[0]['image_path'] ?? '');

$relatedProducts = [];
$relatedProductIds = [$productId];
$categoryId = (int) ($product['category_id'] ?? 0);

if ($categoryId > 0) {
    $relatedStmt = $conn->prepare("
        SELECT
            p.id,
            p.handle,
            p.title,
            p.body,
            c.name AS category_name,
            MIN(v.price) AS min_price,
            COUNT(v.id) AS variant_count,
            COALESCE(SUM(v.inventory), 0) AS total_stock,
            (
                SELECT pi.image_path
                FROM product_images pi
                WHERE pi.product_id = p.id
                ORDER BY pi.is_main DESC, pi.sort_order ASC, pi.id ASC
                LIMIT 1
            ) AS image_path
        FROM products p
        INNER JOIN product_variants v ON v.product_id = p.id
        LEFT JOIN categories c ON c.id = p.category_id
        WHERE p.status = 'active'
        AND p.category_id = ?
        AND p.id <> ?
        GROUP BY p.id, p.handle, p.title, p.body, c.name
        ORDER BY RAND()
        LIMIT 4
    ");
    $relatedStmt->bind_param("ii", $categoryId, $productId);
    $relatedStmt->execute();
    $relatedResult = $relatedStmt->get_result();

    while ($related = $relatedResult->fetch_assoc()) {
        $relatedProducts[] = $related;
        $relatedProductIds[] = (int) $related['id'];
    }
}

if (count($relatedProducts) < 4) {
    $remainingRelated = 4 - count($relatedProducts);
    $excludedIds = implode(',', array_map('intval', $relatedProductIds));
    $fallbackRelatedStmt = $conn->prepare("
        SELECT
            p.id,
            p.handle,
            p.title,
            p.body,
            c.name AS category_name,
            MIN(v.price) AS min_price,
            COUNT(v.id) AS variant_count,
            COALESCE(SUM(v.inventory), 0) AS total_stock,
            (
                SELECT pi.image_path
                FROM product_images pi
                WHERE pi.product_id = p.id
                ORDER BY pi.is_main DESC, pi.sort_order ASC, pi.id ASC
                LIMIT 1
            ) AS image_path
        FROM products p
        INNER JOIN product_variants v ON v.product_id = p.id
        LEFT JOIN categories c ON c.id = p.category_id
        WHERE p.status = 'active'
        AND p.id NOT IN ($excludedIds)
        GROUP BY p.id, p.handle, p.title, p.body, c.name
        ORDER BY RAND()
        LIMIT ?
    ");
    $fallbackRelatedStmt->bind_param("i", $remainingRelated);
    $fallbackRelatedStmt->execute();
    $fallbackRelatedResult = $fallbackRelatedStmt->get_result();

    while ($related = $fallbackRelatedResult->fetch_assoc()) {
        $relatedProducts[] = $related;
    }
}

$pageTitle = $product['title'] . " | J&J's Kitchenette";
$pageCSS = 'product.css';
include('store/includes/header.php');
?>

<script>
    const productData = <?php echo json_encode([
        'id' => $productId,
        'title' => $product['title'],
        'image' => '/' . $firstImage
    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
</script>

<main class="product-page">
    <nav class="product-breadcrumbs" aria-label="Breadcrumb">
        <a href="/">
            <i class="fas fa-home"></i>
            Home
        </a>
        <i class="fas fa-chevron-right" aria-hidden="true"></i>
        <a href="/menu.php">Menu</a>
        <?php if (!empty($product['category_name'])) { ?>
            <i class="fas fa-chevron-right" aria-hidden="true"></i>
            <a href="/menu.php?category=<?php echo (int) $product['category_id']; ?>">
                <?php echo e($product['category_name']); ?>
            </a>
        <?php } ?>
        <i class="fas fa-chevron-right" aria-hidden="true"></i>
        <span><?php echo e($product['title']); ?></span>
    </nav>

    <div class="product-container">
        <section class="product-gallery" aria-label="<?php echo e($product['title']); ?> images">
            <div class="main-image">
                <img
                    id="mainImage"
                    src="<?php echo e($firstImage); ?>"
                    alt="<?php echo e($product['title']); ?>">
            </div>

            <?php if (count($images) > 1) { ?>
                <div class="thumbnail-list" aria-label="Product image thumbnails">
                    <?php foreach ($images as $index => $img) { ?>
                        <button
                            type="button"
                            class="thumb<?php echo $index === 0 ? ' active' : ''; ?>"
                            onclick="changeImage(this)"
                            aria-label="Show image <?php echo $index + 1; ?>">
                            <img
                                src="<?php echo e(appImageUrl($img['image_path'] ?? '')); ?>"
                                alt="">
                        </button>
                    <?php } ?>
                </div>
            <?php } ?>
        </section>

        <section class="product-details">
            <div class="product-meta">
                <a href="menu.php" class="product-back">
                    <i class="fas fa-arrow-left"></i>
                    Menu
                </a>
                <span class="<?php echo $totalInventory > 0 ? 'is-available' : 'is-sold-out'; ?>">
                    <?php echo $totalInventory > 0 ? 'Available' : 'Sold out'; ?>
                </span>
            </div>

            <?php if (!empty($product['category_name'])) { ?>
                <p class="product-category"><?php echo e($product['category_name']); ?></p>
            <?php } ?>

            <h1><?php echo e($product['title']); ?></h1>
            <p class="description"><?php echo e(productExcerpt($product['body'], 260)); ?></p>

            <?php if (!empty($options)) { ?>
                <div class="product-options">
                    <?php foreach ($options as $index => $option) {
                        $optionNumber = $index + 1;
                    ?>
                        <label class="product-option" for="option<?php echo $optionNumber; ?>">
                            <span><?php echo e($option['name']); ?></span>
                            <select class="variant-option" id="option<?php echo $optionNumber; ?>">
                                <?php foreach ($option['values'] as $value) { ?>
                                    <option value="<?php echo e($value); ?>"><?php echo e($value); ?></option>
                                <?php } ?>
                            </select>
                        </label>
                    <?php } ?>
                </div>
            <?php } ?>

            <div class="product-purchase">
                <div>
                    <span class="price-label">Price</span>
                    <strong class="price">&#8369;<span id="price"><?php echo number_format((float) ($minPrice ?? 0), 2); ?></span></strong>
                </div>

                <label class="quantity-control quantity-control--mobile" for="quantityMobile">
                    <span>Quantity</span>
                    <div class="quantity-stepper">
                        <button type="button" onclick="changeQuantity(-1)" aria-label="Decrease quantity">
                            <i class="fas fa-minus"></i>
                        </button>
                        <input type="number" id="quantityMobile" min="1" value="1" inputmode="numeric">
                        <button type="button" onclick="changeQuantity(1)" aria-label="Increase quantity">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                </label>
            </div>

            <label class="quantity-control quantity-control--desktop" for="quantity">
                <span>Quantity</span>
                <div class="quantity-stepper">
                    <button type="button" onclick="changeQuantity(-1)" aria-label="Decrease quantity">
                        <i class="fas fa-minus"></i>
                    </button>
                    <input type="number" id="quantity" min="1" value="1" inputmode="numeric">
                    <button type="button" onclick="changeQuantity(1)" aria-label="Increase quantity">
                        <i class="fas fa-plus"></i>
                    </button>
                </div>
            </label>

            <div class="product-actions">
                <button class="btn-cart" id="addToCartButton" type="button" onclick="addToCart()">
                    <i class="fas fa-shopping-cart"></i>
                    Add to Cart
                </button>

                <button class="btn-order" id="orderNowButton" type="button" onclick="orderNow()">
                    Order Now
                </button>
            </div>
        </section>
    </div>

    <?php if (!empty($relatedProducts)) { ?>
        <section class="related-products" aria-labelledby="relatedProductsTitle">
            <div class="related-products__header">
                <div>
                    <p>You may also like</p>
                    <h2 id="relatedProductsTitle">More from our kitchen</h2>
                </div>
                <a href="menu.php">View full menu</a>
            </div>

            <div class="related-products__grid">
                <?php foreach ($relatedProducts as $related) {
                    $relatedImage = appImageUrl($related['image_path'] ?? '');
                    $relatedInStock = (int) ($related['total_stock'] ?? 0) > 0;
                ?>
                    <article class="related-product-card">
                        <a class="related-product-card__image" href="product.php?handle=<?php echo urlencode($related['handle']); ?>">
                            <img
                                src="<?php echo e($relatedImage); ?>"
                                alt="<?php echo e($related['title']); ?>">
                        </a>

                        <div class="related-product-card__body">
                            <div class="related-product-card__meta">
                                <span><?php echo e($related['category_name'] ?? 'Menu'); ?></span>
                                <span class="<?php echo $relatedInStock ? 'is-available' : 'is-sold-out'; ?>">
                                    <?php echo $relatedInStock ? 'Available' : 'Sold out'; ?>
                                </span>
                            </div>

                            <h3>
                                <a href="product.php?handle=<?php echo urlencode($related['handle']); ?>">
                                    <?php echo e($related['title']); ?>
                                </a>
                            </h3>

                            <p><?php echo e(productExcerpt($related['body'], 92)); ?></p>

                            <div class="related-product-card__footer">
                                <strong>
                                    <?php echo (int) $related['variant_count'] > 1 ? 'From ' : ''; ?>&#8369;<?php echo number_format((float) $related['min_price'], 2); ?>
                                </strong>
                                <a href="product.php?handle=<?php echo urlencode($related['handle']); ?>">View</a>
                            </div>
                        </div>
                    </article>
                <?php } ?>
            </div>
        </section>
    <?php } ?>
</main>

<div id="toast" role="status" aria-live="polite"></div>

<script>
    const variants = <?php echo json_encode($variants, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    const optionSelects = Array.from(document.querySelectorAll(".variant-option"));
    const priceElement = document.getElementById("price");
    const quantityInput = document.getElementById("quantity");
    const quantityMobileInput = document.getElementById("quantityMobile");
    const addToCartButton = document.getElementById("addToCartButton");
    const orderNowButton = document.getElementById("orderNowButton");

    function activeQuantityInput() {
        return window.matchMedia("(max-width: 560px)").matches && quantityMobileInput
            ? quantityMobileInput
            : quantityInput;
    }

    function syncQuantityInputs(quantity) {
        quantityInput.value = quantity;

        if (quantityMobileInput) {
            quantityMobileInput.value = quantity;
        }
    }

    function currency(value) {
        return Number(value || 0).toLocaleString("en-PH", {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function getSelectedVariant() {
        const selectedValues = optionSelects.map(select => select.value);

        return variants.find(variant => {
            return selectedValues.every((value, index) => {
                const key = `option${index + 1}_value`;
                return !value || variant[key] === value;
            });
        }) || null;
    }

    function getQuantity(maxStock) {
        const stockLimit = Math.max(0, Number(maxStock || 0));
        const input = activeQuantityInput();
        let quantity = parseInt(input.value, 10);

        if (!Number.isFinite(quantity) || quantity < 1) {
            quantity = 1;
        }

        if (stockLimit > 0) {
            quantity = Math.min(quantity, stockLimit);
        }

        syncQuantityInputs(quantity);
        quantityInput.max = stockLimit > 0 ? stockLimit : 1;
        if (quantityMobileInput) {
            quantityMobileInput.max = stockLimit > 0 ? stockLimit : 1;
        }

        return quantity;
    }

    function updatePurchaseState() {
        const matched = getSelectedVariant();
        const hasStock = matched && Number(matched.inventory) > 0;

        priceElement.innerText = matched ? currency(matched.price) : "0.00";

        quantityInput.disabled = !hasStock;
        if (quantityMobileInput) {
            quantityMobileInput.disabled = !hasStock;
        }
        addToCartButton.disabled = !hasStock;
        orderNowButton.disabled = !hasStock;
        addToCartButton.innerHTML = hasStock
            ? '<i class="fas fa-shopping-cart"></i> Add to Cart'
            : '<i class="fas fa-circle-xmark"></i> Sold Out';

        getQuantity(matched ? matched.inventory : 0);
    }

    function changeQuantity(amount) {
        const matched = getSelectedVariant();
        const input = activeQuantityInput();
        const current = parseInt(input.value, 10) || 1;
        syncQuantityInputs(current + amount);
        getQuantity(matched ? matched.inventory : 0);
    }

    function changeImage(button) {
        const image = button.querySelector("img");
        document.getElementById("mainImage").src = image.src;

        document.querySelectorAll(".thumb").forEach(thumb => {
            thumb.classList.remove("active");
        });

        button.classList.add("active");
    }

    function addToCart() {
        const selectedVariant = getSelectedVariant();

        if (!selectedVariant || Number(selectedVariant.inventory) <= 0) {
            showToast("This variation is sold out.");
            return;
        }

        const quantity = getQuantity(selectedVariant.inventory);
        addToCartButton.disabled = true;

        fetch("add_to_cart.php", {
            method: "POST",
            headers: {
                "Content-Type": "application/x-www-form-urlencoded"
            },
            body: new URLSearchParams({
                product_id: productData.id,
                variant_id: selectedVariant.id,
                quantity
            })
        })
            .then(response => response.text())
            .then(data => {
                data = data.trim();

                if (data === "login_required") {
                    window.location.href = "login.php";
                    return;
                }

                if (data === "success") {
                    showToast(quantity > 1 ? `${quantity} items added to cart.` : "Added to cart.");
                    return;
                }

                if (data === "out_of_stock") {
                    showToast("Not enough stock for that quantity.");
                    return;
                }

                showToast("Something went wrong.");
            })
            .catch(() => {
                showToast("Unable to add item right now.");
            })
            .finally(updatePurchaseState);
    }

    function orderNow() {
        const selectedVariant = getSelectedVariant();

        if (!selectedVariant || Number(selectedVariant.inventory) <= 0) {
            showToast("This variation is sold out.");
            return;
        }

        const form = document.createElement("form");
        form.method = "POST";
        form.action = "checkout.php";

        const fields = {
            source: "direct",
            product_id: productData.id,
            variant_id: selectedVariant.id,
            quantity: getQuantity(selectedVariant.inventory)
        };

        Object.entries(fields).forEach(([name, value]) => {
            const input = document.createElement("input");
            input.type = "hidden";
            input.name = name;
            input.value = value;
            form.appendChild(input);
        });

        document.body.appendChild(form);
        form.submit();
    }

    function showToast(message) {
        const toast = document.getElementById("toast");
        toast.innerText = message;
        toast.classList.add("show");

        setTimeout(() => {
            toast.classList.remove("show");
        }, 2500);
    }

    optionSelects.forEach(select => {
        select.addEventListener("change", updatePurchaseState);
    });

    quantityInput.addEventListener("change", () => {
        const matched = getSelectedVariant();
        getQuantity(matched ? matched.inventory : 0);
    });

    if (quantityMobileInput) {
        quantityMobileInput.addEventListener("change", () => {
            const matched = getSelectedVariant();
            getQuantity(matched ? matched.inventory : 0);
        });
    }

    updatePurchaseState();
</script>

<?php include('store/includes/footer.php'); ?>
