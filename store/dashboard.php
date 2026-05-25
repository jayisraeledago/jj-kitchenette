<?php
include __DIR__ . '/../db.php';
include __DIR__ . '/includes/admin-auth.php';
requireAdminPermission($conn);

$statusLabels = [
    'pending' => 'Pending',
    'preparing' => 'Preparing',
    'shipped' => 'Shipped',
    'delivered' => 'Delivered / Picked Up',
    'canceled' => 'Canceled'
];

function dashboardStatusLabel($status, $statusLabels)
{
    return $statusLabels[$status] ?? ucfirst((string) $status);
}

function dashboardPaymentLabel($method)
{
    return $method === 'store_pickup' ? 'Store Pick Up' : 'Cash on Delivery';
}

function dashboardCustomerName($order)
{
    $name = trim((string) ($order['customer_name'] ?? ''));
    if ($name !== '' && strtolower($name) !== 'customer') {
        return $name;
    }

    $profileName = trim(implode(' ', array_filter([
        trim((string) ($order['first_name'] ?? '')),
        trim((string) ($order['last_name'] ?? ''))
    ])));

    return $profileName !== '' ? $profileName : 'Customer';
}

function dashboardMoney($amount)
{
    return '&#8369;' . number_format((float) $amount, 2);
}

function dashboardImagePath($path)
{
    $path = trim((string) $path);
    return $path !== '' ? '../' . $path : '../uploads/default.png';
}

function dashboardValidDate($date)
{
    $dateObject = DateTime::createFromFormat('Y-m-d', (string) $date);
    return $dateObject && $dateObject->format('Y-m-d') === $date;
}

$defaultRangeStart = date('Y-m-d', strtotime('monday this week'));
$defaultRangeEnd = date('Y-m-d', strtotime($defaultRangeStart . ' +6 days'));
$legacyDate = $_GET['date'] ?? '';

if (!empty($_GET['start_date']) || !empty($_GET['end_date'])) {
    $reportStart = dashboardValidDate($_GET['start_date'] ?? '') ? $_GET['start_date'] : $defaultRangeStart;
    $reportEnd = dashboardValidDate($_GET['end_date'] ?? '') ? $_GET['end_date'] : $defaultRangeEnd;
} elseif (dashboardValidDate($legacyDate)) {
    $reportStart = date('Y-m-d', strtotime('monday this week', strtotime($legacyDate)));
    $reportEnd = date('Y-m-d', strtotime($reportStart . ' +6 days'));
} else {
    $reportStart = $defaultRangeStart;
    $reportEnd = $defaultRangeEnd;
}

if (strtotime($reportStart) > strtotime($reportEnd)) {
    [$reportStart, $reportEnd] = [$reportEnd, $reportStart];
}

$reportDays = max(1, ((new DateTime($reportStart))->diff(new DateTime($reportEnd))->days) + 1);
$previousReportStart = date('Y-m-d', strtotime($reportStart . " -$reportDays days"));
$previousReportEnd = date('Y-m-d', strtotime($reportStart . ' -1 day'));
$reportStartSql = $conn->real_escape_string($reportStart);
$reportEndSql = $conn->real_escape_string($reportEnd);
$previousReportStartSql = $conn->real_escape_string($previousReportStart);
$previousReportEndSql = $conn->real_escape_string($previousReportEnd);
$dashboardDate = $reportEnd;
$dashboardDateSql = $conn->real_escape_string($dashboardDate);
$dashboardDateLabel = date('M d', strtotime($reportStart)) . ' - ' . date('M d, Y', strtotime($reportEnd));
$isDefaultWeeklyReport = $reportStart === $defaultRangeStart && $reportEnd === $defaultRangeEnd;

$statsStmt = $conn->prepare("
    SELECT
        (SELECT COUNT(*) FROM orders) AS total_orders,
        (SELECT COUNT(*) FROM orders WHERE DATE(created_at) = DATE('$dashboardDateSql')) AS today_orders,
        (SELECT COUNT(*) FROM orders WHERE DATE(created_at) = DATE('$dashboardDateSql') - INTERVAL 1 DAY) AS yesterday_orders,
        (SELECT COALESCE(SUM(total), 0) FROM orders) AS total_revenue,
        (SELECT COALESCE(SUM(total), 0) FROM orders WHERE DATE(created_at) = DATE('$dashboardDateSql')) AS today_revenue,
        (SELECT COALESCE(SUM(total), 0) FROM orders WHERE DATE(created_at) = DATE('$dashboardDateSql') - INTERVAL 1 DAY) AS yesterday_revenue,
        (SELECT COALESCE(SUM(total), 0) FROM orders WHERE DATE(created_at) BETWEEN DATE('$reportStartSql') AND DATE('$reportEndSql')) AS week_revenue,
        (SELECT COUNT(*) FROM orders WHERE DATE(created_at) BETWEEN DATE('$reportStartSql') AND DATE('$reportEndSql')) AS week_orders,
        (SELECT COALESCE(SUM(total), 0) FROM orders WHERE DATE(created_at) BETWEEN DATE('$previousReportStartSql') AND DATE('$previousReportEndSql')) AS previous_week_revenue,
        (SELECT COUNT(*) FROM orders WHERE DATE(created_at) BETWEEN DATE('$previousReportStartSql') AND DATE('$previousReportEndSql')) AS previous_week_orders,
        (SELECT COALESCE(SUM(total), 0) FROM orders WHERE YEAR(created_at) = YEAR(DATE('$dashboardDateSql')) AND MONTH(created_at) = MONTH(DATE('$dashboardDateSql'))) AS month_revenue,
        (SELECT COUNT(*) FROM orders WHERE YEAR(created_at) = YEAR(DATE('$dashboardDateSql')) AND MONTH(created_at) = MONTH(DATE('$dashboardDateSql'))) AS month_orders,
        (SELECT COALESCE(SUM(total), 0) FROM orders WHERE YEAR(created_at) = YEAR(DATE('$dashboardDateSql'))) AS year_revenue,
        (SELECT COUNT(*) FROM orders WHERE YEAR(created_at) = YEAR(DATE('$dashboardDateSql'))) AS year_orders,
        (SELECT COUNT(*) FROM products) AS total_products,
        (SELECT COUNT(*) FROM products WHERE status = 'active') AS active_products,
        (
            SELECT COUNT(*)
            FROM users u
            INNER JOIN roles r ON r.id = u.role_id
            WHERE r.role_name = 'customer'
        ) AS total_customers,
        (
            SELECT COUNT(*)
            FROM product_variants
            WHERE COALESCE(inventory, 0) <= 5
        ) AS low_stock_variants
");
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc();

$statusStmt = $conn->prepare("
    SELECT status, COUNT(*) AS total
    FROM orders
    WHERE DATE(created_at) BETWEEN DATE('$reportStartSql') AND DATE('$reportEndSql')
    GROUP BY status
");
$statusStmt->execute();
$statusRows = $statusStmt->get_result();
$statusCounts = ['pending' => 0, 'preparing' => 0, 'shipped' => 0, 'delivered' => 0];
while ($row = $statusRows->fetch_assoc()) {
    $statusCounts[$row['status']] = (int) $row['total'];
}
$totalStatusOrders = array_sum($statusCounts);

$salesMap = [];
$salesStmt = $conn->prepare("
    SELECT DATE(created_at) AS sale_date, COALESCE(SUM(total), 0) AS revenue
    FROM orders
    WHERE DATE(created_at) BETWEEN DATE('$reportStartSql') AND DATE('$reportEndSql')
    GROUP BY DATE(created_at)
");
$salesStmt->execute();
$salesRows = $salesStmt->get_result();
while ($row = $salesRows->fetch_assoc()) {
    $salesMap[$row['sale_date']] = (float) $row['revenue'];
}

$salesSeries = [];
for ($i = 0; $i < $reportDays; $i++) {
    $dateKey = date('Y-m-d', strtotime($reportStart . " +$i days"));
    $salesSeries[] = [
        'label' => date('M j', strtotime($dateKey)),
        'value' => $salesMap[$dateKey] ?? 0
    ];
}

$maxSales = max(array_column($salesSeries, 'value'));
$chartWidth = 720;
$chartHeight = 190;
$chartTop = 12;
$chartBottom = 168;
$chartPoints = [];
foreach ($salesSeries as $index => $point) {
    $x = 18 + ($index * (($chartWidth - 36) / max(1, count($salesSeries) - 1)));
    $y = $maxSales > 0
        ? $chartBottom - (($point['value'] / $maxSales) * ($chartBottom - $chartTop))
        : $chartBottom;
    $chartPoints[] = round($x, 2) . ',' . round($y, 2);
}
$chartPolyline = implode(' ', $chartPoints);
$chartArea = '18,' . $chartBottom . ' ' . $chartPolyline . ' ' . ($chartWidth - 18) . ',' . $chartBottom;

$recentOrdersStmt = $conn->prepare("
    SELECT
        o.id,
        o.order_number,
        o.customer_name,
        o.payment_method,
        o.status,
        o.total,
        o.created_at,
        u.first_name,
        u.last_name,
        u.email
    FROM orders o
    LEFT JOIN users u ON u.id = o.user_id
    WHERE DATE(o.created_at) BETWEEN DATE('$reportStartSql') AND DATE('$reportEndSql')
    ORDER BY o.created_at DESC, o.id DESC
    LIMIT 6
");
$recentOrdersStmt->execute();
$recentOrders = $recentOrdersStmt->get_result();

$lowStockStmt = $conn->prepare("
    SELECT
        pv.id,
        pv.product_id,
        pv.sku,
        COALESCE(pv.inventory, 0) AS inventory,
        pv.option1_value,
        pv.option2_value,
        pv.option3_value,
        p.title,
        (
            SELECT image_path
            FROM product_images
            WHERE product_id = p.id AND is_main = 1
            LIMIT 1
        ) AS main_image
    FROM product_variants pv
    INNER JOIN products p ON p.id = pv.product_id
    WHERE COALESCE(pv.inventory, 0) <= 5
    ORDER BY COALESCE(pv.inventory, 0) ASC, p.title ASC
    LIMIT 6
");
$lowStockStmt->execute();
$lowStockItems = $lowStockStmt->get_result();

$topProductsStmt = $conn->prepare("
    SELECT
        oi.product_id,
        oi.product_title,
        SUM(oi.quantity) AS units_sold,
        SUM(oi.subtotal) AS revenue,
        (
            SELECT image_path
            FROM product_images
            WHERE product_id = oi.product_id AND is_main = 1
            LIMIT 1
        ) AS main_image
    FROM order_items oi
    INNER JOIN orders o ON o.id = oi.order_id
    WHERE DATE(o.created_at) BETWEEN DATE('$reportStartSql') AND DATE('$reportEndSql')
    GROUP BY oi.product_id, oi.product_title
    ORDER BY units_sold DESC, revenue DESC
    LIMIT 5
");
$topProductsStmt->execute();
$topProducts = $topProductsStmt->get_result();

$revenueDelta = (float) ($stats['previous_week_revenue'] ?? 0) > 0
    ? (((float) ($stats['week_revenue'] ?? 0) - (float) $stats['previous_week_revenue']) / (float) $stats['previous_week_revenue']) * 100
    : 0;
$ordersDelta = (int) ($stats['previous_week_orders'] ?? 0) > 0
    ? ((((int) ($stats['week_orders'] ?? 0)) - (int) $stats['previous_week_orders']) / (int) $stats['previous_week_orders']) * 100
    : 0;

$cards = [
    ['label' => 'Report Revenue', 'value' => dashboardMoney($stats['week_revenue'] ?? 0), 'hint' => number_format(abs($revenueDelta), 2) . '% vs previous range', 'icon' => 'fa-wallet', 'class' => 'revenue', 'trend' => $revenueDelta >= 0 ? 'up' : 'down'],
    ['label' => 'Report Orders', 'value' => number_format((int) ($stats['week_orders'] ?? 0)), 'hint' => number_format(abs($ordersDelta), 1) . '% vs previous range', 'icon' => 'fa-bag-shopping', 'class' => 'orders', 'trend' => $ordersDelta >= 0 ? 'up' : 'down'],
    ['label' => 'Total Customers', 'value' => number_format((int) ($stats['total_customers'] ?? 0)), 'hint' => '0% vs yesterday', 'icon' => 'fa-users', 'class' => 'customers', 'trend' => 'flat'],
    ['label' => 'Total Products', 'value' => number_format((int) ($stats['total_products'] ?? 0)), 'hint' => number_format((int) ($stats['active_products'] ?? 0)) . ' active', 'icon' => 'fa-cube', 'class' => 'products', 'trend' => 'flat'],
    ['label' => 'Low Stock Items', 'value' => number_format((int) ($stats['low_stock_variants'] ?? 0)), 'hint' => 'Variants at 5 or less', 'icon' => 'fa-triangle-exclamation', 'class' => 'stock', 'trend' => 'alert']
];

$periodCards = [
    ['label' => 'Selected Day', 'revenue' => $stats['today_revenue'] ?? 0, 'orders' => $stats['today_orders'] ?? 0],
    ['label' => 'Selected Range', 'revenue' => $stats['week_revenue'] ?? 0, 'orders' => $stats['week_orders'] ?? 0],
    ['label' => 'This Month', 'revenue' => $stats['month_revenue'] ?? 0, 'orders' => $stats['month_orders'] ?? 0],
    ['label' => 'This Year', 'revenue' => $stats['year_revenue'] ?? 0, 'orders' => $stats['year_orders'] ?? 0]
];
?>

<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Admin | J&J's Kitchenette</title>
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
                <div class="page-top dashboard-page-top">
                    <div class="page-heading">
                        <h1>Welcome back, Admin!</h1>
                        <p><?= $isDefaultWeeklyReport ? "Here's your weekly store report." : "Here's your custom store report." ?></p>
                    </div>

                    <form method="GET" class="dashboard-date-form" id="dashboardDateForm">
                        <button class="dashboard-date-chip" type="button" id="dashboardDateButton">
                            <i class="fa-regular fa-calendar-days"></i>
                            <span><?= htmlspecialchars($dashboardDateLabel) ?></span>
                            <i class="fa-solid fa-chevron-down"></i>
                        </button>
                        <div class="dashboard-date-panel" id="dashboardDatePanel" hidden>
                            <label>
                                <span>Start date</span>
                                <input
                                    type="date"
                                    name="start_date"
                                    value="<?= htmlspecialchars($reportStart) ?>">
                            </label>
                            <label>
                                <span>End date</span>
                                <input
                                    type="date"
                                    name="end_date"
                                    value="<?= htmlspecialchars($reportEnd) ?>">
                            </label>
                            <div class="dashboard-date-actions">
                                <a href="dashboard.php">Reset weekly</a>
                                <button type="submit">Apply</button>
                            </div>
                        </div>
                    </form>
                </div>

                <div class="dashboard-summary-row">
                    <?php foreach ($cards as $card) { ?>
                        <div class="dashboard-summary-card dashboard-summary-card--<?= htmlspecialchars($card['class']) ?>">
                            <span class="dashboard-summary-icon">
                                <i class="fa-solid <?= htmlspecialchars($card['icon']) ?>"></i>
                            </span>
                            <div>
                                <span><?= htmlspecialchars($card['label']) ?></span>
                                <strong><?= $card['value'] ?></strong>
                                <small class="dashboard-trend dashboard-trend--<?= htmlspecialchars($card['trend']) ?>">
                                    <?php if ($card['trend'] === 'up') { ?><i class="fa-solid fa-arrow-up"></i><?php } ?>
                                    <?php if ($card['trend'] === 'down') { ?><i class="fa-solid fa-arrow-down"></i><?php } ?>
                                    <?= $card['hint'] ?>
                                </small>
                            </div>
                        </div>
                    <?php } ?>
                </div>

                <div class="dashboard-grid">
                    <div class="dashboard-column dashboard-column--main">
                    <section class="dashboard-card dashboard-card--sales">
                        <div class="dashboard-card__header">
                            <div>
                                <h2>Sales Overview</h2>
                            </div>
                        </div>

                        <div class="dashboard-chart">
                            <div class="dashboard-chart__axis">
                                <?php
                                $steps = [1, .75, .5, .25, 0];
                                foreach ($steps as $step) {
                                    $axisValue = $maxSales > 0 ? $maxSales * $step : 0;
                                    echo '<span>' . dashboardMoney($axisValue) . '</span>';
                                }
                                ?>
                            </div>
                            <div class="dashboard-chart__plot">
                                <svg viewBox="0 0 <?= $chartWidth ?> <?= $chartHeight ?>" role="img" aria-label="Sales for the selected date range">
                                    <polygon points="<?= htmlspecialchars($chartArea) ?>" class="dashboard-chart__area" fill="rgba(18, 88, 39, 0.09)"></polygon>
                                    <polyline points="<?= htmlspecialchars($chartPolyline) ?>" class="dashboard-chart__line" fill="none" stroke="#125827" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"></polyline>
                                    <?php foreach ($chartPoints as $point) { ?>
                                        <?php [$cx, $cy] = explode(',', $point); ?>
                                        <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="4" fill="#125827" stroke="#fff" stroke-width="2"></circle>
                                    <?php } ?>
                                </svg>
                                <div class="dashboard-chart__labels" style="grid-template-columns: repeat(<?= count($salesSeries) ?>, minmax(0, 1fr));">
                                    <?php foreach ($salesSeries as $point) { ?>
                                        <span><?= htmlspecialchars($point['label']) ?></span>
                                    <?php } ?>
                                </div>
                            </div>
                        </div>

                        <div class="dashboard-period-row">
                            <?php foreach ($periodCards as $period) { ?>
                                <div class="dashboard-period-card">
                                    <span><?= htmlspecialchars($period['label']) ?></span>
                                    <strong><?= dashboardMoney($period['revenue']) ?></strong>
                                    <small><?= number_format((int) $period['orders']) ?> orders</small>
                                </div>
                            <?php } ?>
                        </div>
                    </section>

                    <section class="dashboard-card dashboard-card--orders">
                        <div class="dashboard-card__header">
                            <h2>Recent Orders</h2>
                            <a href="orders.php">View all orders</a>
                        </div>

                        <div class="dashboard-table-wrap">
                            <table class="dashboard-table">
                                <thead>
                                    <tr>
                                        <th>Order</th>
                                        <th>Customer</th>
                                        <th>Payment</th>
                                        <th>Total</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($recentOrders->num_rows === 0) { ?>
                                        <tr>
                                            <td colspan="6" class="dashboard-empty">No orders yet.</td>
                                        </tr>
                                    <?php } ?>

                                    <?php while ($order = $recentOrders->fetch_assoc()) { ?>
                                        <tr>
                                            <td>
                                                <a href="order-detail.php?id=<?= (int) $order['id'] ?>" class="dashboard-link">
                                                    <?= htmlspecialchars($order['order_number']) ?>
                                                </a>
                                            </td>
                                            <td>
                                                <strong><?= htmlspecialchars(dashboardCustomerName($order)) ?></strong>
                                                <?php if (!empty($order['email'])) { ?>
                                                    <span><?= htmlspecialchars($order['email']) ?></span>
                                                <?php } ?>
                                            </td>
                                            <td><?= htmlspecialchars(dashboardPaymentLabel($order['payment_method'])) ?></td>
                                            <td><strong><?= dashboardMoney($order['total']) ?></strong></td>
                                            <td>
                                                <span class="order-status order-status--<?= htmlspecialchars($order['status']) ?>">
                                                    <?= htmlspecialchars(dashboardStatusLabel($order['status'], $statusLabels)) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span><?= date('M d, Y', strtotime($order['created_at'])) ?></span>
                                                <small><?= date('h:i A', strtotime($order['created_at'])) ?></small>
                                            </td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="dashboard-table-action">
                            <a href="orders.php"><i class="fa-solid fa-list-check"></i> View all orders</a>
                        </div>
                    </section>

                    <section class="dashboard-banner">
                        <span><i class="fa-solid fa-chart-simple"></i></span>
                        <div>
                            <strong>Keep growing your business!</strong>
                            <p>You're doing great! Check your low stock items and update products to keep your store running smoothly.</p>
                        </div>
                        <a href="orders.php"><i class="fa-solid fa-chart-simple"></i> View Reports</a>
                    </section>
                    </div>

                    <div class="dashboard-column dashboard-column--side">
                    <section class="dashboard-card dashboard-card--status">
                        <div class="dashboard-card__header">
                            <h2>Order Status</h2>
                            <a href="orders.php">View all</a>
                        </div>

                        <div class="dashboard-status-panel">
                            <div class="dashboard-donut" style="--pending: <?= $totalStatusOrders > 0 ? (($statusCounts['pending'] / $totalStatusOrders) * 100) : 0 ?>%; --preparing: <?= $totalStatusOrders > 0 ? (($statusCounts['preparing'] / $totalStatusOrders) * 100) : 0 ?>%; --shipped: <?= $totalStatusOrders > 0 ? (($statusCounts['shipped'] / $totalStatusOrders) * 100) : 0 ?>%;">
                                <span><strong><?= number_format($totalStatusOrders) ?></strong> Total Orders</span>
                            </div>

                            <div class="dashboard-status-list">
                                <?php foreach ($statusCounts as $status => $count) { ?>
                                    <a href="orders.php?status=<?= htmlspecialchars($status) ?>&limit=10" class="dashboard-status-item dashboard-status-item--<?= htmlspecialchars($status) ?>">
                                        <span><?= htmlspecialchars(dashboardStatusLabel($status, $statusLabels)) ?></span>
                                        <strong><?= number_format((int) $count) ?></strong>
                                    </a>
                                <?php } ?>
                            </div>
                        </div>
                    </section>

                    <section class="dashboard-card dashboard-card--low-stock">
                        <div class="dashboard-card__header">
                            <h2>Low Stock Items</h2>
                            <a href="products.php">View all</a>
                        </div>

                        <div class="dashboard-list">
                            <?php if ($lowStockItems->num_rows === 0) { ?>
                                <div class="dashboard-empty">No low-stock variants.</div>
                            <?php } ?>

                            <?php while ($item = $lowStockItems->fetch_assoc()) { ?>
                                <?php
                                $options = array_filter([
                                    $item['option1_value'],
                                    $item['option2_value'],
                                    $item['option3_value']
                                ], function ($value) {
                                    return $value !== null && $value !== '' && strtolower($value) !== 'default';
                                });
                                ?>
                                <a href="edit-product.php?id=<?= (int) $item['product_id'] ?>" class="dashboard-list-item">
                                    <img src="<?= htmlspecialchars(dashboardImagePath($item['main_image'] ?? '')) ?>" alt="">
                                    <span>
                                        <strong><?= htmlspecialchars($item['title']) ?></strong>
                                        <small><?= htmlspecialchars(!empty($options) ? implode(' / ', $options) : ($item['sku'] ?: 'Default variant')) ?></small>
                                    </span>
                                    <em><?= number_format((int) $item['inventory']) ?> left</em>
                                </a>
                            <?php } ?>
                        </div>
                    </section>

                    <section class="dashboard-card dashboard-card--top-products">
                        <div class="dashboard-card__header">
                            <h2>Top Selling Products</h2>
                            <a href="products.php">View all</a>
                        </div>

                        <div class="dashboard-list">
                            <?php if ($topProducts->num_rows === 0) { ?>
                                <div class="dashboard-empty">No product sales yet.</div>
                            <?php } ?>

                            <?php $rank = 1; ?>
                            <?php while ($product = $topProducts->fetch_assoc()) { ?>
                                <a href="products.php?search=<?= urlencode((string) $product['product_title']) ?>" class="dashboard-list-item">
                                    <b><?= $rank ?></b>
                                    <img src="<?= htmlspecialchars(dashboardImagePath($product['main_image'] ?? '')) ?>" alt="">
                                    <span>
                                        <strong><?= htmlspecialchars($product['product_title']) ?></strong>
                                        <small><?= number_format((int) $product['units_sold']) ?> units sold</small>
                                    </span>
                                    <em><?= dashboardMoney($product['revenue']) ?></em>
                                </a>
                                <?php $rank++; ?>
                            <?php } ?>
                        </div>
                    </section>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const dashboardDateButton = document.getElementById('dashboardDateButton');
        const dashboardDatePanel = document.getElementById('dashboardDatePanel');
        const dashboardDateForm = document.getElementById('dashboardDateForm');

        if (dashboardDateButton && dashboardDatePanel && dashboardDateForm) {
            dashboardDateButton.addEventListener('click', event => {
                event.stopPropagation();
                dashboardDatePanel.hidden = !dashboardDatePanel.hidden;
            });

            dashboardDatePanel.addEventListener('click', event => {
                event.stopPropagation();
            });

            document.addEventListener('click', () => {
                dashboardDatePanel.hidden = true;
            });
        }
    </script>
</body>

</html>
