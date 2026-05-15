<?php
session_start();
include 'db.php';

$pageTitle = "Home | J&J's Kitchenette";
$pageCSS = "home.css";

function homeExcerpt($text, $limit = 105)
{
    $text = trim(strip_tags((string) $text));

    if ($text === '') {
        return 'Freshly prepared comfort food from J&J Kitchenette.';
    }

    if (strlen($text) <= $limit) {
        return $text;
    }

    return rtrim(substr($text, 0, $limit - 3)) . '...';
}

$featuredSql = "
    SELECT
        p.id,
        p.handle,
        p.title,
        p.body,
        c.name AS category_name,
        COALESCE(MIN(v.price), 0) AS min_price,
        COALESCE(SUM(v.inventory), 0) AS total_stock,
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
    GROUP BY p.id, p.handle, p.title, p.body, c.name
    ORDER BY total_stock DESC, p.id DESC
    LIMIT 6
";

$featuredResult = $conn->query($featuredSql);
$featuredProducts = [];

while ($row = $featuredResult->fetch_assoc()) {
    $featuredProducts[] = $row;
}

$heroProduct = $featuredProducts[0] ?? null;
$heroImage = !empty($heroProduct['image_path'])
    ? $heroProduct['image_path']
    : 'uploads/default.png';

$categoryStmt = $conn->prepare("
    SELECT
        c.id,
        c.name,
        COUNT(DISTINCT p.id) AS product_count,
        (
            SELECT pi.image_path
            FROM products cp
            INNER JOIN product_images pi ON pi.product_id = cp.id
            WHERE cp.category_id = c.id
            AND cp.status = 'active'
            ORDER BY pi.is_main DESC, pi.sort_order ASC
            LIMIT 1
        ) AS image_path
    FROM categories c
    INNER JOIN products p ON p.category_id = c.id
    WHERE p.status = 'active'
    GROUP BY c.id, c.name
    ORDER BY c.name ASC
    LIMIT 4
");
$categoryStmt->execute();
$categoryResult = $categoryStmt->get_result();

$categories = [];
while ($category = $categoryResult->fetch_assoc()) {
    $categories[] = $category;
}

$statsResult = $conn->query("
    SELECT
        COUNT(DISTINCT p.id) AS product_count,
        COUNT(DISTINCT p.category_id) AS category_count
    FROM products p
    WHERE p.status = 'active'
");
$stats = $statsResult->fetch_assoc() ?: ['product_count' => 0, 'category_count' => 0];

include('store/includes/header.php');
?>

<main class="home-page">
    <section
        class="home-hero"
        style="background-image: url('/jj_kitchenette/<?php echo htmlspecialchars($heroImage); ?>');">
        <div class="home-hero__overlay"></div>

        <div class="home-container home-hero__content">
            <p class="home-eyebrow">J&J Kitchenette</p>
            <h1>Delicious meals made fresh for everyday cravings.</h1>
            <p>
                Browse the menu, choose your favorites, and order comfort food from J&J Kitchenette in just a few clicks.
            </p>

            <div class="home-actions">
                <a href="/jj_kitchenette/menu.php" class="home-btn home-btn--primary">
                    View Menu
                </a>
                <a href="/jj_kitchenette/cart.php" class="home-btn home-btn--secondary">
                    <i class="fas fa-shopping-cart"></i>
                    Cart
                </a>
            </div>
        </div>
    </section>

    <section class="home-strip">
        <div class="home-container home-strip__grid">
            <div>
                <i class="fas fa-utensils"></i>
                <span>
                    <strong><?php echo (int) $stats['product_count']; ?>+</strong>
                    <small>Available items</small>
                </span>
            </div>
            <div>
                <i class="fas fa-layer-group"></i>
                <span>
                    <strong><?php echo (int) $stats['category_count']; ?></strong>
                    <small>Menu categories</small>
                </span>
            </div>
            <div>
                <i class="fas fa-leaf"></i>
                <span>
                    <strong>Fresh</strong>
                    <small>Prepared daily</small>
                </span>
            </div>
        </div>
    </section>

    <?php if (!empty($categories)) { ?>
        <section class="home-section">
            <div class="home-container">
                <div class="home-section__header">
                    <div>
                        <p class="home-eyebrow">Browse by category</p>
                        <h2>Find your next order faster</h2>
                    </div>
                    <a href="/jj_kitchenette/menu.php">All categories</a>
                </div>

                <div class="home-category-grid">
                    <?php foreach ($categories as $category) {
                        $categoryImage = !empty($category['image_path']) ? $category['image_path'] : 'uploads/default.png';
                    ?>
                        <a
                            class="home-category"
                            href="/jj_kitchenette/menu.php?category=<?php echo (int) $category['id']; ?>">
                            <img
                                src="/jj_kitchenette/<?php echo htmlspecialchars($categoryImage); ?>"
                                alt="<?php echo htmlspecialchars($category['name']); ?>">
                            <span><?php echo htmlspecialchars($category['name']); ?></span>
                            <small><?php echo (int) $category['product_count']; ?> item<?php echo (int) $category['product_count'] === 1 ? '' : 's'; ?></small>
                        </a>
                    <?php } ?>
                </div>
            </div>
        </section>
    <?php } ?>

    <section class="home-section home-section--featured">
        <div class="home-container">
            <div class="home-section__header">
                <div>
                    <p class="home-eyebrow">Customer-ready picks</p>
                    <h2>Featured menu items</h2>
                </div>
                <a href="/jj_kitchenette/menu.php">View full menu</a>
            </div>

            <?php if (empty($featuredProducts)) { ?>
                <div class="home-empty">
                    <h3>No active products yet</h3>
                    <p>Add active products in the store admin to show them here.</p>
                </div>
            <?php } else { ?>
                <div class="home-featured-grid">
                    <?php foreach ($featuredProducts as $product) {
                        $productImage = !empty($product['image_path']) ? $product['image_path'] : 'uploads/default.png';
                    ?>
                        <article class="home-product">
                            <a class="home-product__image" href="/jj_kitchenette/product.php?handle=<?php echo urlencode($product['handle']); ?>">
                                <img
                                    src="/jj_kitchenette/<?php echo htmlspecialchars($productImage); ?>"
                                    alt="<?php echo htmlspecialchars($product['title']); ?>">
                            </a>

                            <div class="home-product__body">
                                <span><?php echo htmlspecialchars($product['category_name'] ?? 'Menu'); ?></span>
                                <h3>
                                    <a href="/jj_kitchenette/product.php?handle=<?php echo urlencode($product['handle']); ?>">
                                        <?php echo htmlspecialchars($product['title']); ?>
                                    </a>
                                </h3>
                                <p><?php echo htmlspecialchars(homeExcerpt($product['body'])); ?></p>

                                <div class="home-product__footer">
                                    <strong>&#8369;<?php echo number_format((float) $product['min_price'], 2); ?></strong>
                                    <a href="/jj_kitchenette/product.php?handle=<?php echo urlencode($product['handle']); ?>">
                                        Order
                                    </a>
                                </div>
                            </div>
                        </article>
                    <?php } ?>
                </div>
            <?php } ?>
        </div>
    </section>

    <section class="home-cta">
        <div class="home-container home-cta__inner">
            <div>
                <p class="home-eyebrow">Ready to eat?</p>
                <h2>Start with the menu and build your order.</h2>
            </div>
            <a href="/jj_kitchenette/menu.php" class="home-btn home-btn--primary">Browse Menu</a>
        </div>
    </section>
</main>

<?php include('store/includes/footer.php'); ?>
