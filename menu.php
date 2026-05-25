<?php
session_start();
include 'db.php';

$pageTitle = "Menu | J&J's Kitchenette";
$pageCSS = "menu.css";

$search = trim($_GET['search'] ?? '');
$categoryId = isset($_GET['category']) ? (int) $_GET['category'] : 0;

$categoryStmt = $conn->prepare("
    SELECT DISTINCT c.id, c.name
    FROM categories c
    INNER JOIN products p ON p.category_id = c.id
    WHERE p.status = 'active'
    ORDER BY c.name ASC
");
$categoryStmt->execute();
$categoryResult = $categoryStmt->get_result();

$categories = [];
while ($category = $categoryResult->fetch_assoc()) {
    $categories[] = $category;
}

$conditions = ["p.status = 'active'"];
$types = "";
$params = [];

if ($search !== '') {
    $conditions[] = "(
        p.title LIKE ?
        OR p.body LIKE ?
        OR c.name LIKE ?
        OR EXISTS (
            SELECT 1
            FROM product_variants sv
            WHERE sv.product_id = p.id
              AND sv.sku LIKE ?
        )
    )";
    $searchTerm = "%{$search}%";
    $types .= "ssss";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if ($categoryId > 0) {
    $conditions[] = "p.category_id = ?";
    $types .= "i";
    $params[] = $categoryId;
}

$whereSql = implode(" AND ", $conditions);

$menuSql = "
    SELECT
        p.id,
        p.handle,
        p.title,
        p.body,
        c.name AS category_name,
        COALESCE(MIN(v.price), 0) AS min_price,
        COUNT(DISTINCT v.id) AS variant_count,
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
    WHERE {$whereSql}
    GROUP BY p.id, p.handle, p.title, p.body, c.name
    ORDER BY p.title ASC
";

$menuStmt = $conn->prepare($menuSql);
if (!empty($params)) {
    $menuStmt->bind_param($types, ...$params);
}
$menuStmt->execute();
$menuResult = $menuStmt->get_result();

$products = [];
while ($product = $menuResult->fetch_assoc()) {
    $products[] = $product;
}

function menuExcerpt($text, $limit = 120)
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

function menuCategoryIcon($name)
{
    $name = strtolower((string) $name);

    if (str_contains($name, 'dessert') || str_contains($name, 'halo') || str_contains($name, 'cake')) {
        return 'fa-ice-cream';
    }

    if (str_contains($name, 'grill')) {
        return 'fa-drumstick-bite';
    }

    if (str_contains($name, 'pastry') || str_contains($name, 'bread')) {
        return 'fa-wheat-awn';
    }

    if (str_contains($name, 'snack')) {
        return 'fa-cookie-bite';
    }

    if (str_contains($name, 'soup')) {
        return 'fa-bowl-food';
    }

    return 'fa-bowl-rice';
}

$heroImage = !empty($products[0]['image_path']) ? $products[0]['image_path'] : 'uploads/default.png';

include('store/includes/header.php');
?>

<main class="menu-page">
    <section class="menu-hero">
        <img
            class="menu-hero__image"
            src="/jj_kitchenette/<?php echo htmlspecialchars($heroImage); ?>"
            alt=""
            aria-hidden="true">
        <div class="menu-container menu-hero__inner">
            <div>
                <p class="menu-eyebrow">Fresh from the kitchen</p>
                <h1>Our Menu <i class="fas fa-leaf"></i></h1>
                <p class="menu-intro">
                    Browse our available meals and choose your favorite for pickup or delivery.
                </p>
            </div>

            <form class="menu-search" method="GET" action="menu.php">
                <label for="menuSearch">Search menu</label>
                <div class="menu-search__row">
                    <i class="fas fa-search"></i>
                    <input
                        type="search"
                        id="menuSearch"
                        name="search"
                        placeholder="Search meals..."
                        value="<?php echo htmlspecialchars($search); ?>">

                    <?php if ($categoryId > 0) { ?>
                        <input type="hidden" name="category" value="<?php echo $categoryId; ?>">
                    <?php } ?>

                    <button type="submit">
                        Search
                    </button>
                </div>
            </form>
        </div>
    </section>

    <section class="menu-container" id="menuResults" aria-live="polite">
        <div class="menu-toolbar">
            <div class="category-tabs" aria-label="Menu categories">
                <a
                    href="menu.php<?php echo $search !== '' ? '?search=' . urlencode($search) : ''; ?>"
                    class="<?php echo $categoryId === 0 ? 'active' : ''; ?>">
                    <i class="fas fa-grip"></i>
                    All
                </a>

                <?php foreach ($categories as $category) {
                    $categoryUrl = 'menu.php?category=' . (int) $category['id'];
                    if ($search !== '') {
                        $categoryUrl .= '&search=' . urlencode($search);
                    }
                ?>
                    <a
                        href="<?php echo htmlspecialchars($categoryUrl); ?>"
                        class="<?php echo $categoryId === (int) $category['id'] ? 'active' : ''; ?>">
                        <i class="fas <?php echo menuCategoryIcon($category['name']); ?>"></i>
                        <?php echo htmlspecialchars($category['name']); ?>
                    </a>
                <?php } ?>
            </div>

            <p class="menu-count">
                <?php echo count($products); ?> item<?php echo count($products) === 1 ? '' : 's'; ?>
            </p>
        </div>

        <?php if (empty($products)) { ?>
            <div class="menu-empty">
                <h2>No menu items found</h2>
                <p>Try another search or category.</p>
                <a href="menu.php">View all menu items</a>
            </div>
        <?php } else { ?>
            <div class="menu-grid">
                <?php foreach ($products as $product) {
                    $imagePath = !empty($product['image_path']) ? $product['image_path'] : 'uploads/default.png';
                    $inStock = (int) $product['total_stock'] > 0;
                ?>
                    <article class="menu-card">
                        <a class="menu-card__image" href="product.php?handle=<?php echo urlencode($product['handle']); ?>">
                            <img
                                src="/jj_kitchenette/<?php echo htmlspecialchars($imagePath); ?>"
                                alt="<?php echo htmlspecialchars($product['title']); ?>">
                        </a>
                        <div class="menu-card__body">
                            <div class="menu-card__meta">
                                <span><?php echo htmlspecialchars($product['category_name'] ?? 'Menu'); ?></span>
                                <span class="<?php echo $inStock ? 'is-available' : 'is-sold-out'; ?>">
                                    <?php echo $inStock ? 'Available' : 'Sold out'; ?>
                                </span>
                            </div>

                            <h2>
                                <a href="product.php?handle=<?php echo urlencode($product['handle']); ?>">
                                    <?php echo htmlspecialchars($product['title']); ?>
                                </a>
                            </h2>

                            <p><?php echo htmlspecialchars(menuExcerpt($product['body'])); ?></p>

                            <div class="menu-card__footer">
                                <strong>
                                    <?php echo (int) $product['variant_count'] > 1 ? 'From ' : ''; ?>&#8369;<?php echo number_format((float) $product['min_price'], 2); ?>
                                </strong>

                                <a class="menu-card__button" href="product.php?handle=<?php echo urlencode($product['handle']); ?>">
                                    View
                                </a>
                            </div>
                        </div>
                    </article>
                <?php } ?>
            </div>
        <?php } ?>
    </section>
</main>

<script>
    const menuSearchForm = document.querySelector(".menu-search");
    const menuSearchInput = document.getElementById("menuSearch");
    const menuResults = document.getElementById("menuResults");
    let menuSearchTimer;
    let menuSearchController;

    function getCurrentCategory() {
        const params = new URLSearchParams(window.location.search);
        return params.get("category") || "";
    }

    function buildMenuUrl(searchValue) {
        const params = new URLSearchParams();
        const category = getCurrentCategory();

        if (searchValue.trim() !== "") {
            params.set("search", searchValue.trim());
        }

        if (category !== "") {
            params.set("category", category);
        }

        const query = params.toString();
        return `menu.php${query ? `?${query}` : ""}`;
    }

    function updateMenuResults(searchValue) {
        const url = buildMenuUrl(searchValue);

        if (menuSearchController) {
            menuSearchController.abort();
        }

        menuSearchController = new AbortController();
        menuResults.classList.add("is-loading");

        fetch(url, {
            signal: menuSearchController.signal
        })
            .then(response => response.text())
            .then(html => {
                const doc = new DOMParser().parseFromString(html, "text/html");
                const nextResults = doc.getElementById("menuResults");

                if (nextResults) {
                    menuResults.innerHTML = nextResults.innerHTML;
                    history.replaceState(null, "", url);
                }
            })
            .catch(error => {
                if (error.name !== "AbortError") {
                    console.error("Menu search failed", error);
                }
            })
            .finally(() => {
                menuResults.classList.remove("is-loading");
            });
    }

    menuSearchInput.addEventListener("input", () => {
        clearTimeout(menuSearchTimer);
        menuSearchTimer = setTimeout(() => {
            updateMenuResults(menuSearchInput.value);
        }, 250);
    });

    menuSearchForm.addEventListener("submit", event => {
        event.preventDefault();
        clearTimeout(menuSearchTimer);
        updateMenuResults(menuSearchInput.value);
    });
</script>

<?php include('store/includes/footer.php'); ?>
