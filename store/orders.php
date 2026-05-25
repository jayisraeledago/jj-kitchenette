<?php
include __DIR__ . '/../db.php';
include __DIR__ . '/includes/admin-auth.php';
require_once __DIR__ . '/../includes/order_email.php';
requireAdminPermission($conn, ['orders']);

$statusOptions = ['pending', 'preparing', 'shipped', 'delivered', 'canceled'];
$statusLabels = [
    'pending' => 'Pending',
    'preparing' => 'Preparing',
    'shipped' => 'Shipped',
    'delivered' => 'Delivered / Picked Up',
    'canceled' => 'Canceled'
];
$methodLabels = [
    'delivery' => 'Delivery',
    'store_pickup' => 'Store Pick Up'
];

function orderStatusLabel($status, $statusLabels)
{
    return $statusLabels[$status] ?? ucfirst($status);
}

function orderStatusOptionsForFulfillment($method)
{
    return ['pending', 'preparing', 'shipped', 'delivered'];
}

function orderStatusLabelForFulfillment($status, $statusLabels, $method)
{
    if ($method === 'store_pickup' && $status === 'shipped') {
        return 'Ready to Pick Up';
    }

    if ($method === 'store_pickup' && $status === 'delivered') {
        return 'Picked Up';
    }

    if ($method !== 'store_pickup' && $status === 'delivered') {
        return 'Delivered';
    }

    return orderStatusLabel($status, $statusLabels);
}

function orderPaymentLabel($method)
{
    return $method === 'store_pickup' ? 'Store Pick Up' : 'Cash on Delivery';
}

function orderFulfillmentLabel($method)
{
    return $method === 'store_pickup' ? 'Pickup' : 'Delivery';
}

function orderInitials($name)
{
    $name = trim((string) $name);
    if ($name === '') {
        return 'CU';
    }

    $parts = preg_split('/\s+/', $name);
    $first = strtoupper(substr($parts[0] ?? 'C', 0, 1));
    $last = strtoupper(substr($parts[count($parts) - 1] ?? 'U', 0, 1));

    return $first . $last;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id'], $_POST['status'])) {
    $orderId = (int) $_POST['order_id'];
    $status = $_POST['status'];

    $allowedStatusOptions = $statusOptions;
    if ($orderId > 0) {
        $methodStmt = $conn->prepare("SELECT fulfillment_method FROM orders WHERE id = ? LIMIT 1");
        $methodStmt->bind_param("i", $orderId);
        $methodStmt->execute();
        $methodRow = $methodStmt->get_result()->fetch_assoc();
        if ($methodRow) {
            $allowedStatusOptions = orderStatusOptionsForFulfillment($methodRow['fulfillment_method']);
        }
    }

    if ($orderId > 0 && in_array($status, $allowedStatusOptions, true)) {
        $updateStmt = $conn->prepare("
            UPDATE orders
            SET status = ?
            WHERE id = ?
        ");
        $updateStmt->bind_param("si", $status, $orderId);
        $updateStmt->execute();

        sendCustomerOrderDetailsEmail($conn, $orderId, 'updated');
    }

    $redirectQuery = $_POST['redirect_query'] ?? '';
    header("Location: orders.php" . ($redirectQuery !== '' ? '?' . $redirectQuery : ''));
    exit;
}

$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$methodFilter = $_GET['method'] ?? '';
$allowedLimits = [10, 25, 50, 100];
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;

if (!in_array($statusFilter, $statusOptions, true)) {
    $statusFilter = '';
}

if (!array_key_exists($methodFilter, $methodLabels)) {
    $methodFilter = '';
}

if (!in_array($limit, $allowedLimits, true)) {
    $limit = 10;
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$conditions = [];
$types = '';
$params = [];

if ($search !== '') {
    $conditions[] = "(
        o.order_number LIKE ?
        OR o.customer_name LIKE ?
        OR CONCAT_WS(' ', NULLIF(u.first_name, ''), NULLIF(u.last_name, '')) LIKE ?
        OR o.phone LIKE ?
        OR u.email LIKE ?
    )";
    $searchTerm = "%{$search}%";
    $types .= 'sssss';
    array_push($params, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
}

if ($statusFilter !== '') {
    $conditions[] = "o.status = ?";
    $types .= 's';
    $params[] = $statusFilter;
}

if ($methodFilter !== '') {
    $conditions[] = "o.fulfillment_method = ?";
    $types .= 's';
    $params[] = $methodFilter;
}

$whereSql = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

$countSql = "
    SELECT COUNT(*) AS total
    FROM orders o
    LEFT JOIN users u ON u.id = o.user_id
    {$whereSql}
";
$countStmt = $conn->prepare($countSql);
if (!empty($params)) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$totalRows = (int) $countStmt->get_result()->fetch_assoc()['total'];
$totalPages = max(1, (int) ceil($totalRows / $limit));
$fromRow = $totalRows > 0 ? $offset + 1 : 0;
$toRow = min($offset + $limit, $totalRows);

$summaryCounts = [];
$summaryTotalStmt = $conn->prepare("SELECT COUNT(*) AS total FROM orders");
$summaryTotalStmt->execute();
$summaryCounts['all'] = (int) $summaryTotalStmt->get_result()->fetch_assoc()['total'];

foreach ($statusOptions as $status) {
    $summaryStmt = $conn->prepare("SELECT COUNT(*) AS total FROM orders WHERE status = ?");
    $summaryStmt->bind_param("s", $status);
    $summaryStmt->execute();
    $summaryCounts[$status] = (int) $summaryStmt->get_result()->fetch_assoc()['total'];
}

$orderSql = "
    SELECT
        o.*,
        u.email,
        COALESCE(
            NULLIF(NULLIF(o.customer_name, ''), 'Customer'),
            NULLIF(CONCAT_WS(' ', NULLIF(u.first_name, ''), NULLIF(u.last_name, '')), ''),
            'Customer'
        ) AS display_customer_name,
        (
            SELECT COUNT(*)
            FROM order_items oi
            WHERE oi.order_id = o.id
        ) AS item_count,
        (
            SELECT GROUP_CONCAT(CONCAT(oi.product_title, ' x', oi.quantity) ORDER BY oi.id SEPARATOR ', ')
            FROM order_items oi
            WHERE oi.order_id = o.id
        ) AS item_summary
    FROM orders o
    LEFT JOIN users u ON u.id = o.user_id
    {$whereSql}
    ORDER BY o.created_at DESC, o.id DESC
    LIMIT ? OFFSET ?
";

$orderTypes = $types . 'ii';
$orderParams = $params;
$orderParams[] = $limit;
$orderParams[] = $offset;

$orderStmt = $conn->prepare($orderSql);
$orderStmt->bind_param($orderTypes, ...$orderParams);
$orderStmt->execute();
$orders = $orderStmt->get_result();

$queryParams = [];
if ($search !== '') {
    $queryParams['search'] = $search;
}
if ($statusFilter !== '') {
    $queryParams['status'] = $statusFilter;
}
if ($methodFilter !== '') {
    $queryParams['method'] = $methodFilter;
}
$queryParams['limit'] = $limit;
$baseQuery = http_build_query($queryParams);
$redirectQuery = http_build_query(array_merge($queryParams, ['page' => $page]));

$summaryCards = [
    ['key' => 'all', 'label' => 'Total Orders', 'hint' => 'All time orders', 'icon' => 'fa-bag-shopping'],
    ['key' => 'pending', 'label' => 'Pending', 'hint' => 'Awaiting action', 'icon' => 'fa-clock'],
    ['key' => 'preparing', 'label' => 'Preparing', 'hint' => 'Being prepared', 'icon' => 'fa-box'],
    ['key' => 'shipped', 'label' => 'Shipped', 'hint' => 'On the way', 'icon' => 'fa-truck'],
    ['key' => 'delivered', 'label' => 'Delivered', 'hint' => 'Completed', 'icon' => 'fa-circle-check'],
    ['key' => 'canceled', 'label' => 'Canceled', 'hint' => 'Canceled by customer', 'icon' => 'fa-ban']
];
?>

<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orders Admin | J&J's Kitchenette</title>
    <link rel="icon" type="image/png" href="/jj_kitchenette/assets/images/favicon.png">
    <link rel="shortcut icon" type="image/png" href="/jj_kitchenette/assets/images/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
</head>

<body>
    <div class="app">
        <?php include 'includes/sidebar.php'; ?>

        <div class="main">
            <div class="page-container">
                <div class="page-top orders-page-top">
                    <div class="page-heading">
                        <h1>Orders</h1>
                        <p>Manage and track all customer orders.</p>
                    </div>

                    <div class="page-actions orders-page-actions">
                        <form method="GET" class="search-bar orders-search-bar">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <input
                                type="text"
                                name="search"
                                placeholder="Search orders by ID, customer..."
                                value="<?= htmlspecialchars($search) ?>">
                            <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
                            <input type="hidden" name="method" value="<?= htmlspecialchars($methodFilter) ?>">
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
                                        <?php foreach ($statusOptions as $status) { ?>
                                            <option value="<?= htmlspecialchars($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>>
                                                <?= htmlspecialchars(orderStatusLabel($status, $statusLabels)) ?>
                                            </option>
                                        <?php } ?>
                                    </select>
                                </label>

                                <label>
                                    <span>Method</span>
                                    <select name="method">
                                        <option value="">All methods</option>
                                        <?php foreach ($methodLabels as $method => $methodLabel) { ?>
                                            <option value="<?= htmlspecialchars($method) ?>" <?= $methodFilter === $method ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($methodLabel) ?>
                                            </option>
                                        <?php } ?>
                                    </select>
                                </label>

                                <div class="orders-filter-actions">
                                    <a href="orders.php?limit=<?= $limit ?>">Clear</a>
                                    <button type="submit">Apply</button>
                                </div>
                            </form>
                        </details>
                    </div>
                </div>

                <div class="orders-summary-row">
                    <?php foreach ($summaryCards as $card) { ?>
                        <div class="orders-summary-card orders-summary-card--<?= htmlspecialchars($card['key']) ?>">
                            <span class="orders-summary-icon">
                                <i class="fa-solid <?= htmlspecialchars($card['icon']) ?>"></i>
                            </span>
                            <div>
                                <span><?= htmlspecialchars($card['label']) ?></span>
                                <strong><?= number_format($summaryCounts[$card['key']] ?? 0) ?></strong>
                                <small><?= htmlspecialchars($card['hint']) ?></small>
                            </div>
                        </div>
                    <?php } ?>
                </div>

                <div class="orders-table-card">
                    <table class="orders-table">
                        <thead>
                            <tr>
                                <th>Order</th>
                                <th>Customer</th>
                                <th>Items</th>
                                <th>Payment & Method</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if ($orders->num_rows === 0) { ?>
                                <tr>
                                    <td colspan="7" class="orders-empty">No orders found.</td>
                                </tr>
                            <?php } ?>

                            <?php while ($order = $orders->fetch_assoc()) { ?>
                                <?php
                                $detailUrl = 'order-detail.php?id=' . (int) $order['id'] . '&return=' . urlencode($redirectQuery);
                                $customerName = $order['display_customer_name'] ?? 'Customer';
                                ?>
                                <tr>
                                    <td>
                                        <a href="<?= htmlspecialchars($detailUrl) ?>" class="order-number-link">
                                            <?= htmlspecialchars($order['order_number']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <div class="order-customer">
                                            <span class="order-customer__avatar"><?= htmlspecialchars(orderInitials($customerName)) ?></span>
                                            <div>
                                                <strong><?= htmlspecialchars($customerName) ?></strong>
                                                <?php if (!empty($order['email'])) { ?>
                                                    <span class="orders-muted"><?= htmlspecialchars($order['email']) ?></span>
                                                <?php } ?>
                                                <?php if (!empty($order['phone'])) { ?>
                                                    <span class="orders-muted"><?= htmlspecialchars($order['phone']) ?></span>
                                                <?php } ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <strong><?= (int) $order['item_count'] ?> item<?= (int) $order['item_count'] === 1 ? '' : 's' ?></strong>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars(orderPaymentLabel($order['payment_method'])) ?></strong>
                                        <span class="orders-muted"><?= htmlspecialchars(orderFulfillmentLabel($order['fulfillment_method'])) ?></span>
                                    </td>
                                    <td>
                                        <strong>&#8369;<?= number_format((float) $order['total'], 2) ?></strong>
                                        <span class="orders-muted">Delivery: <?= (float) $order['delivery_fee'] > 0 ? '&#8369;' . number_format((float) $order['delivery_fee'], 2) : 'Free' ?></span>
                                    </td>
                                    <td>
                                        <span class="order-status order-status--<?= htmlspecialchars($order['status']) ?>">
                                            <?= htmlspecialchars(orderStatusLabelForFulfillment($order['status'], $statusLabels, $order['fulfillment_method'])) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span><?= date('M d, Y', strtotime($order['created_at'])) ?></span>
                                        <span class="orders-muted"><?= date('h:i A', strtotime($order['created_at'])) ?></span>
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>

                    <div class="orders-table-footer">
                        <p>Showing <?= $fromRow ?> to <?= $toRow ?> of <?= $totalRows ?> orders</p>

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

                        <form method="GET" class="products-per-page orders-per-page">
                            <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                            <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
                            <input type="hidden" name="method" value="<?= htmlspecialchars($methodFilter) ?>">
                            <select name="limit" aria-label="Orders per page" onchange="this.form.submit()">
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
