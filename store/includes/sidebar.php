<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentAdminPage = basename($_SERVER['PHP_SELF']);
$currentRoleName = $_SESSION['role_name'] ?? '';
$currentAdminName = $_SESSION['user_name'] ?? 'Admin User';
$currentAdminEmail = $_SESSION['user_email'] ?? 'admin@jjkitchenette.com';
$staffPermissions = [
    'inventory' => false,
    'products' => false,
    'categories' => false,
    'orders' => false
];

if (isset($_SESSION['user_id'], $conn)) {
    $currentUserStmt = $conn->prepare("
        SELECT first_name, last_name, email
        FROM users
        WHERE id = ?
        LIMIT 1
    ");
    $currentUserStmt->bind_param("i", $_SESSION['user_id']);
    $currentUserStmt->execute();
    $currentUser = $currentUserStmt->get_result()->fetch_assoc();

    if ($currentUser) {
        $currentAdminName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));
        $currentAdminEmail = $currentUser['email'] ?? $currentAdminEmail;

        if ($currentAdminName === '') {
            $currentAdminName = $currentAdminEmail;
        }

        $_SESSION['user_name'] = $currentAdminName;
        $_SESSION['user_email'] = $currentAdminEmail;
    }
}

if ($currentRoleName === 'staff' && isset($_SESSION['user_id'], $conn)) {
    $conn->query("
        CREATE TABLE IF NOT EXISTS staff_permissions (
            user_id INT PRIMARY KEY,
            can_manage_inventory TINYINT(1) NOT NULL DEFAULT 0,
            can_manage_products TINYINT(1) NOT NULL DEFAULT 0,
            can_manage_categories TINYINT(1) NOT NULL DEFAULT 0,
            can_manage_orders TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");

    $permissionStmt = $conn->prepare("
        SELECT
            can_manage_inventory,
            can_manage_products,
            can_manage_categories,
            can_manage_orders
        FROM staff_permissions
        WHERE user_id = ?
        LIMIT 1
    ");
    $permissionStmt->bind_param("i", $_SESSION['user_id']);
    $permissionStmt->execute();
    $permissionRow = $permissionStmt->get_result()->fetch_assoc() ?: [];

    $staffPermissions = [
        'inventory' => (int) ($permissionRow['can_manage_inventory'] ?? 0) === 1,
        'products' => (int) ($permissionRow['can_manage_products'] ?? 0) === 1,
        'categories' => (int) ($permissionRow['can_manage_categories'] ?? 0) === 1,
        'orders' => (int) ($permissionRow['can_manage_orders'] ?? 0) === 1
    ];
}

$adminNavItems = [
    ['label' => 'Dashboard', 'icon' => 'fa-house', 'href' => '/jj_kitchenette/store/dashboard.php', 'pages' => ['dashboard.php'], 'permission' => null],
    ['label' => 'Products', 'icon' => 'fa-box', 'href' => '/jj_kitchenette/store/products.php', 'pages' => ['products.php', 'add-product.php', 'edit-product.php'], 'permission' => 'products'],
    ['label' => 'Orders', 'icon' => 'fa-bag-shopping', 'href' => '/jj_kitchenette/store/orders.php', 'pages' => ['orders.php'], 'permission' => 'orders'],
    ['label' => 'Customers', 'icon' => 'fa-users', 'href' => '/jj_kitchenette/store/customers.php', 'pages' => ['customers.php', 'customer-detail.php'], 'admin_only' => true],
    ['label' => 'Categories', 'icon' => 'fa-tag', 'href' => '/jj_kitchenette/store/categories.php', 'pages' => ['categories.php'], 'permission' => 'categories'],
    ['label' => 'Reports', 'icon' => 'fa-chart-simple', 'href' => '/jj_kitchenette/store/reports.php', 'pages' => ['reports.php'], 'admin_only' => true],
    ['label' => 'Settings', 'icon' => 'fa-gear', 'href' => '/jj_kitchenette/store/settings.php', 'pages' => ['settings.php', 'staff-users.php'], 'admin_only' => true],
];
?>

<aside class="sidebar admin-sidebar">
    <a class="admin-sidebar__brand" href="/jj_kitchenette/store/dashboard.php" aria-label="J&J's Kitchenette admin">
        <img src="/jj_kitchenette/assets/images/kitchenette-logo.svg" alt="J&J's Kitchenette">
    </a>

    <nav class="admin-sidebar__nav" aria-label="Admin navigation">
        <?php foreach ($adminNavItems as $item): ?>
            <?php
            if ($currentRoleName === 'staff') {
                if (!empty($item['admin_only'])) {
                    continue;
                }

                $permission = $item['permission'] ?? null;
                if (
                    $permission === 'products'
                    && empty($staffPermissions['products'])
                    && empty($staffPermissions['inventory'])
                ) {
                    continue;
                }

                if ($permission !== null && $permission !== 'products' && empty($staffPermissions[$permission])) {
                    continue;
                }
            }
            ?>
            <?php $isActive = in_array($currentAdminPage, $item['pages'], true); ?>
            <a
                href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>"
                class="admin-sidebar__link <?= $isActive ? 'active' : '' ?>">
                <i class="fa-solid <?= htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
                <span><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="admin-sidebar__footer">
        <details class="admin-user-menu">
            <summary class="admin-user-card">
                <span class="admin-user-card__avatar">
                    <i class="fa-solid fa-user"></i>
                </span>
                <span>
                    <strong><?= htmlspecialchars($currentAdminName, ENT_QUOTES, 'UTF-8') ?></strong>
                    <small><?= htmlspecialchars($currentAdminEmail, ENT_QUOTES, 'UTF-8') ?></small>
                </span>
                <i class="fa-solid fa-chevron-down"></i>
            </summary>

            <div class="admin-user-menu__panel">
                <a href="/jj_kitchenette/store/change-password.php">
                    <i class="fa-solid fa-key"></i>
                    <span>Change Password</span>
                </a>
            </div>
        </details>

        <a class="admin-sidebar__link admin-sidebar__logout" href="/jj_kitchenette/store/logout.php">
            <i class="fa-solid fa-right-from-bracket"></i>
            <span>Logout</span>
        </a>
    </div>
</aside>
