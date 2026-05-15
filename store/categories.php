<?php
include __DIR__ . '/../db.php';
include __DIR__ . '/includes/admin-auth.php';
requireAdminPermission($conn, ['categories']);

$allowedLimits = [10, 25, 50, 100];
$message = trim($_GET['message'] ?? '');
$error = trim($_GET['error'] ?? '');

function redirectCategories($params = [])
{
    $query = http_build_query(array_filter($params, function ($value) {
        return $value !== null && $value !== '';
    }));

    header("Location: categories.php" . ($query !== '' ? '?' . $query : ''));
    exit;
}

function categoryNameExists($conn, $name, $ignoreId = 0)
{
    $stmt = $conn->prepare("
        SELECT id
        FROM categories
        WHERE LOWER(name) = LOWER(?)
        AND id <> ?
        LIMIT 1
    ");
    $stmt->bind_param("si", $name, $ignoreId);
    $stmt->execute();

    return $stmt->get_result()->num_rows > 0;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';
    $redirectQuery = $_POST['redirect_query'] ?? '';
    parse_str($redirectQuery, $redirectParams);

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');

        if ($name === '') {
            $redirectParams['error'] = 'Category name is required.';
            redirectCategories($redirectParams);
        }

        if (categoryNameExists($conn, $name)) {
            $redirectParams['error'] = 'Category already exists.';
            redirectCategories($redirectParams);
        }

        $stmt = $conn->prepare("INSERT INTO categories (name) VALUES (?)");
        $stmt->bind_param("s", $name);
        $stmt->execute();

        $redirectParams['message'] = 'Category added.';
        redirectCategories($redirectParams);
    }

    if ($action === 'update') {
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');

        if ($categoryId <= 0 || $name === '') {
            $redirectParams['error'] = 'Category name is required.';
            redirectCategories($redirectParams);
        }

        if (categoryNameExists($conn, $name, $categoryId)) {
            $redirectParams['error'] = 'Category already exists.';
            redirectCategories($redirectParams);
        }

        $stmt = $conn->prepare("UPDATE categories SET name = ? WHERE id = ?");
        $stmt->bind_param("si", $name, $categoryId);
        $stmt->execute();

        $redirectParams['message'] = 'Category updated.';
        redirectCategories($redirectParams);
    }

    if ($action === 'delete') {
        $categoryId = (int) ($_POST['category_id'] ?? 0);

        $countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM products WHERE category_id = ?");
        $countStmt->bind_param("i", $categoryId);
        $countStmt->execute();
        $productCount = (int) $countStmt->get_result()->fetch_assoc()['total'];

        if ($categoryId <= 0 || $productCount > 0) {
            $redirectParams['error'] = 'Only empty categories can be deleted.';
            redirectCategories($redirectParams);
        }

        $stmt = $conn->prepare("DELETE FROM categories WHERE id = ?");
        $stmt->bind_param("i", $categoryId);
        $stmt->execute();

        $redirectParams['message'] = 'Category deleted.';
        redirectCategories($redirectParams);
    }
}

$search = trim($_GET['search'] ?? '');
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;

if (!in_array($limit, $allowedLimits, true)) {
    $limit = 10;
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$conditions = [];
$types = '';
$params = [];

if ($search !== '') {
    $conditions[] = "c.name LIKE ?";
    $searchTerm = "%{$search}%";
    $types .= 's';
    $params[] = $searchTerm;
}

$whereSql = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

$countSql = "
    SELECT COUNT(*) AS total
    FROM categories c
    {$whereSql}
";
$countStmt = $conn->prepare($countSql);
if ($types !== '') {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$totalRows = (int) $countStmt->get_result()->fetch_assoc()['total'];
$totalPages = max(1, (int) ceil($totalRows / $limit));
$fromRow = $totalRows > 0 ? $offset + 1 : 0;
$toRow = min($offset + $limit, $totalRows);

$summaryStmt = $conn->prepare("
    SELECT
        COUNT(*) AS total_categories,
        COALESCE(SUM(product_counts.product_count), 0) AS total_products,
        SUM(COALESCE(product_counts.product_count, 0) = 0) AS empty_categories,
        COALESCE(MAX(product_counts.product_count), 0) AS largest_category_count
    FROM categories c
    LEFT JOIN (
        SELECT category_id, COUNT(*) AS product_count
        FROM products
        GROUP BY category_id
    ) product_counts ON product_counts.category_id = c.id
");
$summaryStmt->execute();
$summary = $summaryStmt->get_result()->fetch_assoc();

$categoriesSql = "
    SELECT
        c.id,
        c.name,
        COALESCE(COUNT(p.id), 0) AS product_count,
        COALESCE(SUM(p.status = 'active'), 0) AS active_count,
        COALESCE(SUM(p.status = 'draft'), 0) AS draft_count,
        MAX(p.created_at) AS latest_product_at
    FROM categories c
    LEFT JOIN products p ON p.category_id = c.id
    {$whereSql}
    GROUP BY c.id, c.name
    ORDER BY c.name ASC
    LIMIT ? OFFSET ?
";

$categoryTypes = $types . 'ii';
$categoryParams = $params;
$categoryParams[] = $limit;
$categoryParams[] = $offset;

$categoriesStmt = $conn->prepare($categoriesSql);
$categoriesStmt->bind_param($categoryTypes, ...$categoryParams);
$categoriesStmt->execute();
$categories = $categoriesStmt->get_result();

$queryParams = [];
if ($search !== '') {
    $queryParams['search'] = $search;
}
$queryParams['limit'] = $limit;
$redirectQuery = http_build_query(array_merge($queryParams, ['page' => $page]));

$summaryCards = [
    ['label' => 'Total Categories', 'value' => (int) ($summary['total_categories'] ?? 0), 'hint' => 'Menu groups', 'icon' => 'fa-tags', 'class' => 'all'],
    ['label' => 'Products Assigned', 'value' => (int) ($summary['total_products'] ?? 0), 'hint' => 'Across categories', 'icon' => 'fa-box', 'class' => 'products'],
    ['label' => 'Empty Categories', 'value' => (int) ($summary['empty_categories'] ?? 0), 'hint' => 'No products yet', 'icon' => 'fa-folder-open', 'class' => 'empty'],
    ['label' => 'Largest Category', 'value' => (int) ($summary['largest_category_count'] ?? 0), 'hint' => 'Products in one group', 'icon' => 'fa-chart-simple', 'class' => 'largest']
];
?>

<!DOCTYPE html>
<html>

<head>
    <title>Categories Admin | J&J's Kitchenette</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>

<body>
    <div class="app">
        <?php include 'includes/sidebar.php'; ?>

        <div class="main">
            <div class="page-container">
                <div class="page-top categories-page-top">
                    <div class="page-heading">
                        <h1>Categories</h1>
                        <p>Organize menu items into clear customer-friendly groups.</p>
                    </div>

                    <div class="page-actions categories-page-actions">
                        <form method="GET" class="search-bar categories-search-bar">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <input
                                type="text"
                                name="search"
                                placeholder="Search categories..."
                                value="<?= htmlspecialchars($search) ?>">
                            <input type="hidden" name="limit" value="<?= $limit ?>">
                            <button type="submit" class="sr-only">Search</button>
                        </form>
                    </div>
                </div>

                <?php if ($message !== '') { ?>
                    <div class="admin-alert admin-alert--success"><?= htmlspecialchars($message) ?></div>
                <?php } ?>

                <?php if ($error !== '') { ?>
                    <div class="admin-alert admin-alert--error"><?= htmlspecialchars($error) ?></div>
                <?php } ?>

                <div class="categories-summary-row">
                    <?php foreach ($summaryCards as $card) { ?>
                        <div class="categories-summary-card categories-summary-card--<?= htmlspecialchars($card['class']) ?>">
                            <span class="categories-summary-icon">
                                <i class="fa-solid <?= htmlspecialchars($card['icon']) ?>"></i>
                            </span>
                            <div>
                                <span><?= htmlspecialchars($card['label']) ?></span>
                                <strong><?= number_format($card['value']) ?></strong>
                                <small><?= htmlspecialchars($card['hint']) ?></small>
                            </div>
                        </div>
                    <?php } ?>
                </div>

                <div class="categories-layout">
                    <section class="categories-table-card">
                        <table class="categories-table">
                            <thead>
                                <tr>
                                    <th>Category</th>
                                    <th>Products</th>
                                    <th>Active</th>
                                    <th>Draft</th>
                                    <th>Latest Product</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($categories->num_rows === 0) { ?>
                                    <tr>
                                        <td colspan="6" class="categories-empty">No categories found.</td>
                                    </tr>
                                <?php } ?>

                                <?php while ($category = $categories->fetch_assoc()) { ?>
                                    <?php $productsUrl = 'products.php?category=' . (int) $category['id']; ?>
                                    <tr>
                                        <td>
                                            <div class="category-cell">
                                                <span class="category-icon">
                                                    <i class="fa-solid fa-tag"></i>
                                                </span>
                                                <form method="POST" class="category-edit-form">
                                                    <input type="hidden" name="action" value="update">
                                                    <input type="hidden" name="category_id" value="<?= (int) $category['id'] ?>">
                                                    <input type="hidden" name="redirect_query" value="<?= htmlspecialchars($redirectQuery) ?>">
                                                    <input type="text" name="name" value="<?= htmlspecialchars($category['name']) ?>" aria-label="Category name">
                                                    <button type="submit" title="Save category" aria-label="Save category">
                                                        <i class="fa-solid fa-check"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                        <td><strong><?= number_format((int) $category['product_count']) ?></strong></td>
                                        <td><span class="category-count-pill"><?= number_format((int) $category['active_count']) ?></span></td>
                                        <td><span class="category-count-pill category-count-pill--draft"><?= number_format((int) $category['draft_count']) ?></span></td>
                                        <td>
                                            <?= !empty($category['latest_product_at']) ? date('M d, Y', strtotime($category['latest_product_at'])) : '<span class="categories-muted">No products</span>' ?>
                                        </td>
                                        <td>
                                            <div class="category-actions">
                                                <a href="<?= htmlspecialchars($productsUrl) ?>" class="view-btn" title="View products" aria-label="View products">
                                                    <i class="fa-regular fa-eye"></i>
                                                </a>

                                                <form method="POST" class="category-delete-form">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="category_id" value="<?= (int) $category['id'] ?>">
                                                    <input type="hidden" name="redirect_query" value="<?= htmlspecialchars($redirectQuery) ?>">
                                                    <button
                                                        type="submit"
                                                        class="view-btn"
                                                        title="<?= (int) $category['product_count'] > 0 ? 'Only empty categories can be deleted' : 'Delete category' ?>"
                                                        aria-label="Delete category"
                                                        <?= (int) $category['product_count'] > 0 ? 'disabled' : '' ?>>
                                                        <i class="fa-regular fa-trash-can"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>

                        <div class="categories-table-footer">
                            <p>Showing <?= $fromRow ?> to <?= $toRow ?> of <?= $totalRows ?> categories</p>

                            <div class="pagination">
                                <?php if ($page > 1) {
                                    $prevQuery = http_build_query(array_merge($queryParams, ['page' => $page - 1]));
                                ?>
                                    <a href="?<?= htmlspecialchars($prevQuery) ?>" aria-label="Previous page">
                                        <i class="fa-solid fa-chevron-left"></i>
                                    </a>
                                <?php } ?>

                                <?php for ($i = 1; $i <= $totalPages; $i++) {
                                    $pageQuery = http_build_query(array_merge($queryParams, ['page' => $i]));
                                ?>
                                    <a href="?<?= htmlspecialchars($pageQuery) ?>" class="<?= $i === $page ? 'active' : '' ?>">
                                        <?= $i ?>
                                    </a>
                                <?php } ?>

                                <?php if ($page < $totalPages) {
                                    $nextQuery = http_build_query(array_merge($queryParams, ['page' => $page + 1]));
                                ?>
                                    <a href="?<?= htmlspecialchars($nextQuery) ?>" aria-label="Next page">
                                        <i class="fa-solid fa-chevron-right"></i>
                                    </a>
                                <?php } ?>
                            </div>

                            <form method="GET" class="products-per-page categories-per-page">
                                <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                                <select name="limit" aria-label="Categories per page" onchange="this.form.submit()">
                                    <?php foreach ($allowedLimits as $allowedLimit) { ?>
                                        <option value="<?= $allowedLimit ?>" <?= $limit === $allowedLimit ? 'selected' : '' ?>>
                                            <?= $allowedLimit ?> / page
                                        </option>
                                    <?php } ?>
                                </select>
                                <i class="fa-solid fa-chevron-down"></i>
                            </form>
                        </div>
                    </section>

                    <aside class="category-create-card">
                        <div>
                            <span class="category-create-icon">
                                <i class="fa-solid fa-plus"></i>
                            </span>
                            <h2>Add Category</h2>
                            <p>Create a new menu group for products.</p>
                        </div>

                        <form method="POST">
                            <input type="hidden" name="action" value="create">
                            <input type="hidden" name="redirect_query" value="<?= htmlspecialchars($redirectQuery) ?>">
                            <label>
                                <span>Name</span>
                                <input type="text" name="name" placeholder="e.g. Rice Meals" required>
                            </label>
                            <button type="submit">
                                <i class="fa-solid fa-plus"></i>
                                Add category
                            </button>
                        </form>
                    </aside>
                </div>
            </div>
        </div>
    </div>
</body>

</html>
