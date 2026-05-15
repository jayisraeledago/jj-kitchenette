<?php
include __DIR__ . '/../db.php';
include __DIR__ . '/includes/admin-auth.php';
requireAdminOnly();

$statusOptions = ['active', 'disabled'];
$allowedLimits = [10, 25, 50, 100];

function customerInitials($firstName, $lastName, $email)
{
    $firstName = trim((string) $firstName);
    $lastName = trim((string) $lastName);

    if ($firstName !== '' || $lastName !== '') {
        return strtoupper(substr($firstName !== '' ? $firstName : $lastName, 0, 1) . substr($lastName !== '' ? $lastName : $firstName, 0, 1));
    }

    return strtoupper(substr((string) $email, 0, 2));
}

function customerFullName($firstName, $lastName)
{
    $name = trim(implode(' ', array_filter([trim((string) $firstName), trim((string) $lastName)])));
    return $name !== '' ? $name : 'Customer';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['customer_id'], $_POST['status'])) {
    $customerId = (int) $_POST['customer_id'];
    $status = $_POST['status'];

    if ($customerId > 0 && in_array($status, $statusOptions, true)) {
        $updateStmt = $conn->prepare("
            UPDATE users u
            INNER JOIN roles r ON r.id = u.role_id
            SET u.status = ?
            WHERE u.id = ?
            AND r.role_name = 'customer'
        ");
        $updateStmt->bind_param("si", $status, $customerId);
        $updateStmt->execute();
    }

    $redirectQuery = $_POST['redirect_query'] ?? '';
    header("Location: customers.php" . ($redirectQuery !== '' ? '?' . $redirectQuery : ''));
    exit;
}

$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;

if (!in_array($statusFilter, $statusOptions, true)) {
    $statusFilter = '';
}

if (!in_array($limit, $allowedLimits, true)) {
    $limit = 10;
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$conditions = ["r.role_name = 'customer'"];
$types = '';
$params = [];

if ($search !== '') {
    $conditions[] = "(
        u.email LIKE ?
        OR u.first_name LIKE ?
        OR u.last_name LIKE ?
        OR CONCAT_WS(' ', NULLIF(u.first_name, ''), NULLIF(u.last_name, '')) LIKE ?
    )";
    $searchTerm = "%{$search}%";
    $types .= 'ssss';
    array_push($params, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
}

if ($statusFilter !== '') {
    $conditions[] = "u.status = ?";
    $types .= 's';
    $params[] = $statusFilter;
}

$whereSql = 'WHERE ' . implode(' AND ', $conditions);

$countSql = "
    SELECT COUNT(*) AS total
    FROM users u
    INNER JOIN roles r ON r.id = u.role_id
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
        COUNT(*) AS total_customers,
        SUM(u.status = 'active') AS active_customers,
        SUM(u.status = 'disabled') AS disabled_customers,
        COALESCE(SUM(order_totals.order_count), 0) AS total_orders,
        COALESCE(SUM(order_totals.total_spent), 0) AS total_revenue
    FROM users u
    INNER JOIN roles r ON r.id = u.role_id
    LEFT JOIN (
        SELECT user_id, COUNT(*) AS order_count, SUM(total) AS total_spent
        FROM orders
        GROUP BY user_id
    ) order_totals ON order_totals.user_id = u.id
    WHERE r.role_name = 'customer'
");
$summaryStmt->execute();
$summary = $summaryStmt->get_result()->fetch_assoc();

$customersSql = "
    SELECT
        u.id,
        u.first_name,
        u.last_name,
        u.email,
        u.status,
        u.created_at,
        COALESCE(order_totals.order_count, 0) AS order_count,
        COALESCE(order_totals.total_spent, 0) AS total_spent,
        order_totals.last_order_at
    FROM users u
    INNER JOIN roles r ON r.id = u.role_id
    LEFT JOIN (
        SELECT
            user_id,
            COUNT(*) AS order_count,
            SUM(total) AS total_spent,
            MAX(created_at) AS last_order_at
        FROM orders
        GROUP BY user_id
    ) order_totals ON order_totals.user_id = u.id
    {$whereSql}
    ORDER BY u.created_at DESC, u.id DESC
    LIMIT ? OFFSET ?
";

$customerTypes = $types . 'ii';
$customerParams = $params;
$customerParams[] = $limit;
$customerParams[] = $offset;

$customersStmt = $conn->prepare($customersSql);
$customersStmt->bind_param($customerTypes, ...$customerParams);
$customersStmt->execute();
$customers = $customersStmt->get_result();

$queryParams = [];
if ($search !== '') {
    $queryParams['search'] = $search;
}
if ($statusFilter !== '') {
    $queryParams['status'] = $statusFilter;
}
$queryParams['limit'] = $limit;
$redirectQuery = http_build_query(array_merge($queryParams, ['page' => $page]));

$summaryCards = [
    ['label' => 'Total Customers', 'value' => (int) ($summary['total_customers'] ?? 0), 'hint' => 'Registered accounts', 'icon' => 'fa-users', 'class' => 'all'],
    ['label' => 'Active', 'value' => (int) ($summary['active_customers'] ?? 0), 'hint' => 'Can place orders', 'icon' => 'fa-user-check', 'class' => 'active'],
    ['label' => 'Disabled', 'value' => (int) ($summary['disabled_customers'] ?? 0), 'hint' => 'Access restricted', 'icon' => 'fa-user-slash', 'class' => 'disabled'],
    ['label' => 'Customer Orders', 'value' => (int) ($summary['total_orders'] ?? 0), 'hint' => 'All customer orders', 'icon' => 'fa-bag-shopping', 'class' => 'orders'],
    ['label' => 'Revenue', 'value' => '&#8369;' . number_format((float) ($summary['total_revenue'] ?? 0), 2), 'hint' => 'From customers', 'icon' => 'fa-peso-sign', 'class' => 'revenue']
];
?>

<!DOCTYPE html>
<html>

<head>
    <title>Customers Admin | J&J's Kitchenette</title>
    <link rel="icon" type="image/png" href="/jj_kitchenette/assets/images/favicon.png">
    <link rel="shortcut icon" type="image/png" href="/jj_kitchenette/assets/images/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>

<body>
    <div class="app">
        <?php include 'includes/sidebar.php'; ?>

        <div class="main">
            <div class="page-container">
                <div class="page-top customers-page-top">
                    <div class="page-heading">
                        <h1>Customers</h1>
                        <p>View customer accounts, order history, and account status.</p>
                    </div>

                    <div class="page-actions customers-page-actions">
                        <form method="GET" class="search-bar customers-search-bar">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <input
                                type="text"
                                name="search"
                                placeholder="Search customers..."
                                value="<?= htmlspecialchars($search) ?>">
                            <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
                            <input type="hidden" name="limit" value="<?= $limit ?>">
                            <button type="submit" class="sr-only">Search</button>
                        </form>

                        <details class="orders-filter-menu">
                            <summary class="btn btn-filter">
                                <i class="fa-solid fa-filter"></i>
                                Filter
                                <i class="fa-solid fa-chevron-down"></i>
                            </summary>

                            <form method="GET" class="orders-filter-panel">
                                <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                                <input type="hidden" name="limit" value="<?= $limit ?>">

                                <label>
                                    <span>Status</span>
                                    <select name="status">
                                        <option value="">All statuses</option>
                                        <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                                        <option value="disabled" <?= $statusFilter === 'disabled' ? 'selected' : '' ?>>Disabled</option>
                                    </select>
                                </label>

                                <div class="orders-filter-actions">
                                    <a href="customers.php?limit=<?= $limit ?>">Clear</a>
                                    <button type="submit">Apply</button>
                                </div>
                            </form>
                        </details>
                    </div>
                </div>

                <div class="customers-summary-row">
                    <?php foreach ($summaryCards as $card) { ?>
                        <div class="customers-summary-card customers-summary-card--<?= htmlspecialchars($card['class']) ?>">
                            <span class="customers-summary-icon">
                                <i class="fa-solid <?= htmlspecialchars($card['icon']) ?>"></i>
                            </span>
                            <div>
                                <span><?= htmlspecialchars($card['label']) ?></span>
                                <strong><?= is_string($card['value']) ? $card['value'] : number_format($card['value']) ?></strong>
                                <small><?= htmlspecialchars($card['hint']) ?></small>
                            </div>
                        </div>
                    <?php } ?>
                </div>

                <div class="customers-table-card">
                    <table class="customers-table">
                        <thead>
                            <tr>
                                <th>Customer</th>
                                <th>Status</th>
                                <th>Orders</th>
                                <th>Total Spent</th>
                                <th>Last Order</th>
                                <th>Joined</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($customers->num_rows === 0) { ?>
                                <tr>
                                    <td colspan="7" class="customers-empty">No customers found.</td>
                                </tr>
                            <?php } ?>

                            <?php while ($customer = $customers->fetch_assoc()) { ?>
                                <?php
                                $name = customerFullName($customer['first_name'], $customer['last_name']);
                                $initials = customerInitials($customer['first_name'], $customer['last_name'], $customer['email']);
                                $nextStatus = $customer['status'] === 'active' ? 'disabled' : 'active';
                                $customerUrl = 'customer-detail.php?id=' . (int) $customer['id'] . '&return=' . urlencode($redirectQuery);
                                ?>
                                <tr>
                                    <td>
                                        <div class="customer-cell">
                                            <span class="customer-avatar"><?= htmlspecialchars($initials) ?></span>
                                            <div>
                                                <a href="<?= htmlspecialchars($customerUrl) ?>" class="customer-name-link">
                                                    <?= htmlspecialchars($name) ?>
                                                </a>
                                                <span><?= htmlspecialchars($customer['email'] ?? '') ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="customer-status customer-status--<?= htmlspecialchars($customer['status']) ?>">
                                            <?= htmlspecialchars(ucfirst($customer['status'])) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong><?= number_format((int) $customer['order_count']) ?></strong>
                                    </td>
                                    <td>
                                        <strong>&#8369;<?= number_format((float) $customer['total_spent'], 2) ?></strong>
                                    </td>
                                    <td>
                                        <?= !empty($customer['last_order_at']) ? date('M d, Y', strtotime($customer['last_order_at'])) : '<span class="customers-muted">No orders</span>' ?>
                                    </td>
                                    <td>
                                        <span><?= date('M d, Y', strtotime($customer['created_at'])) ?></span>
                                    </td>
                                    <td>
                                        <div class="customer-actions">
                                            <form method="POST" class="customer-status-form">
                                                <input type="hidden" name="customer_id" value="<?= (int) $customer['id'] ?>">
                                                <input type="hidden" name="status" value="<?= htmlspecialchars($nextStatus) ?>">
                                                <input type="hidden" name="redirect_query" value="<?= htmlspecialchars($redirectQuery) ?>">
                                                <button type="submit" class="view-btn" aria-label="<?= $nextStatus === 'active' ? 'Enable customer' : 'Disable customer' ?>" title="<?= $nextStatus === 'active' ? 'Enable customer' : 'Disable customer' ?>">
                                                    <i class="fa-solid <?= $nextStatus === 'active' ? 'fa-user-check' : 'fa-user-slash' ?>"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>

                    <div class="customers-table-footer">
                        <p>Showing <?= $fromRow ?> to <?= $toRow ?> of <?= $totalRows ?> customers</p>

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

                        <form method="GET" class="products-per-page customers-per-page">
                            <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                            <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
                            <select name="limit" aria-label="Customers per page" onchange="this.form.submit()">
                                <?php foreach ($allowedLimits as $allowedLimit) { ?>
                                    <option value="<?= $allowedLimit ?>" <?= $limit === $allowedLimit ? 'selected' : '' ?>>
                                        <?= $allowedLimit ?> / page
                                    </option>
                                <?php } ?>
                            </select>
                            <i class="fa-solid fa-chevron-down"></i>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>

</html>
