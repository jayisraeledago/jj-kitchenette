<?php
include __DIR__ . '/../db.php';
include __DIR__ . '/includes/admin-auth.php';
requireAdminOnly();

function reportMoney($amount)
{
    return '&#8369;' . number_format((float) $amount, 2);
}

function reportValidDate($date)
{
    $dateObject = DateTime::createFromFormat('Y-m-d', (string) $date);
    return $dateObject && $dateObject->format('Y-m-d') === $date;
}

function reportPaymentLabel($method)
{
    return $method === 'store_pickup' ? 'Store Pick Up' : 'Cash on Delivery';
}

function reportImagePath($path)
{
    $path = trim((string) $path);
    return $path !== '' ? '../' . $path : '../uploads/default.png';
}

$defaultStart = date('Y-m-d', strtotime('monday this week'));
$defaultEnd = date('Y-m-d', strtotime($defaultStart . ' +6 days'));
$startDate = reportValidDate($_GET['start_date'] ?? '') ? $_GET['start_date'] : $defaultStart;
$endDate = reportValidDate($_GET['end_date'] ?? '') ? $_GET['end_date'] : $defaultEnd;

if (strtotime($startDate) > strtotime($endDate)) {
    [$startDate, $endDate] = [$endDate, $startDate];
}

$rangeDays = max(1, ((new DateTime($startDate))->diff(new DateTime($endDate))->days) + 1);
$previousStart = date('Y-m-d', strtotime($startDate . " -$rangeDays days"));
$previousEnd = date('Y-m-d', strtotime($startDate . ' -1 day'));

$startSql = $conn->real_escape_string($startDate);
$endSql = $conn->real_escape_string($endDate);
$previousStartSql = $conn->real_escape_string($previousStart);
$previousEndSql = $conn->real_escape_string($previousEnd);
$rangeLabel = date('M d', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate));

$summaryStmt = $conn->prepare("
    SELECT
        COUNT(*) AS total_orders,
        COALESCE(SUM(total), 0) AS total_revenue,
        COALESCE(AVG(total), 0) AS average_order,
        COUNT(DISTINCT user_id) AS active_customers,
        SUM(status = 'pending') AS pending_orders,
        SUM(status = 'preparing') AS preparing_orders,
        SUM(status = 'shipped') AS shipped_orders,
        SUM(status = 'delivered') AS delivered_orders
    FROM orders
    WHERE DATE(created_at) BETWEEN DATE('$startSql') AND DATE('$endSql')
");
$summaryStmt->execute();
$summary = $summaryStmt->get_result()->fetch_assoc();

$previousStmt = $conn->prepare("
    SELECT
        COUNT(*) AS total_orders,
        COALESCE(SUM(total), 0) AS total_revenue
    FROM orders
    WHERE DATE(created_at) BETWEEN DATE('$previousStartSql') AND DATE('$previousEndSql')
");
$previousStmt->execute();
$previous = $previousStmt->get_result()->fetch_assoc();

$revenueChange = (float) ($previous['total_revenue'] ?? 0) > 0
    ? (((float) ($summary['total_revenue'] ?? 0) - (float) $previous['total_revenue']) / (float) $previous['total_revenue']) * 100
    : 0;
$ordersChange = (int) ($previous['total_orders'] ?? 0) > 0
    ? ((((int) ($summary['total_orders'] ?? 0)) - (int) $previous['total_orders']) / (int) $previous['total_orders']) * 100
    : 0;

$salesMap = [];
$salesStmt = $conn->prepare("
    SELECT DATE(created_at) AS sale_date, COUNT(*) AS orders, COALESCE(SUM(total), 0) AS revenue
    FROM orders
    WHERE DATE(created_at) BETWEEN DATE('$startSql') AND DATE('$endSql')
    GROUP BY DATE(created_at)
");
$salesStmt->execute();
$salesRows = $salesStmt->get_result();
while ($row = $salesRows->fetch_assoc()) {
    $salesMap[$row['sale_date']] = [
        'orders' => (int) $row['orders'],
        'revenue' => (float) $row['revenue']
    ];
}

$salesSeries = [];
for ($i = 0; $i < $rangeDays; $i++) {
    $dateKey = date('Y-m-d', strtotime($startDate . " +$i days"));
    $salesSeries[] = [
        'label' => date('M j', strtotime($dateKey)),
        'orders' => $salesMap[$dateKey]['orders'] ?? 0,
        'revenue' => $salesMap[$dateKey]['revenue'] ?? 0
    ];
}

$maxRevenue = max(array_column($salesSeries, 'revenue'));
$chartWidth = 720;
$chartHeight = 190;
$chartTop = 12;
$chartBottom = 168;
$chartMinWidth = max(720, count($salesSeries) * 70);
$chartPoints = [];
foreach ($salesSeries as $index => $point) {
    $x = 18 + ($index * (($chartWidth - 36) / max(1, count($salesSeries) - 1)));
    $y = $maxRevenue > 0
        ? $chartBottom - (($point['revenue'] / $maxRevenue) * ($chartBottom - $chartTop))
        : $chartBottom;
    $chartPoints[] = round($x, 2) . ',' . round($y, 2);
}
$chartPolyline = implode(' ', $chartPoints);
$chartArea = '18,' . $chartBottom . ' ' . $chartPolyline . ' ' . ($chartWidth - 18) . ',' . $chartBottom;

$statusCounts = [
    'Pending' => (int) ($summary['pending_orders'] ?? 0),
    'Preparing' => (int) ($summary['preparing_orders'] ?? 0),
    'Shipped' => (int) ($summary['shipped_orders'] ?? 0),
    'Delivered / Picked Up' => (int) ($summary['delivered_orders'] ?? 0)
];

$paymentStmt = $conn->prepare("
    SELECT payment_method, COUNT(*) AS orders, COALESCE(SUM(total), 0) AS revenue
    FROM orders
    WHERE DATE(created_at) BETWEEN DATE('$startSql') AND DATE('$endSql')
    GROUP BY payment_method
    ORDER BY revenue DESC
");
$paymentStmt->execute();
$payments = $paymentStmt->get_result();

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
    WHERE DATE(o.created_at) BETWEEN DATE('$startSql') AND DATE('$endSql')
    GROUP BY oi.product_id, oi.product_title
    ORDER BY revenue DESC, units_sold DESC
    LIMIT 8
");
$topProductsStmt->execute();
$topProducts = $topProductsStmt->get_result();

$summaryCards = [
    ['label' => 'Revenue', 'value' => reportMoney($summary['total_revenue'] ?? 0), 'hint' => number_format(abs($revenueChange), 2) . '% vs previous range', 'icon' => 'fa-wallet', 'class' => 'revenue', 'trend' => $revenueChange >= 0 ? 'up' : 'down'],
    ['label' => 'Orders', 'value' => number_format((int) ($summary['total_orders'] ?? 0)), 'hint' => number_format(abs($ordersChange), 1) . '% vs previous range', 'icon' => 'fa-bag-shopping', 'class' => 'orders', 'trend' => $ordersChange >= 0 ? 'up' : 'down'],
    ['label' => 'Average Order', 'value' => reportMoney($summary['average_order'] ?? 0), 'hint' => 'Per order value', 'icon' => 'fa-receipt', 'class' => 'average', 'trend' => 'flat'],
    ['label' => 'Active Customers', 'value' => number_format((int) ($summary['active_customers'] ?? 0)), 'hint' => 'Customers in range', 'icon' => 'fa-users', 'class' => 'customers', 'trend' => 'flat']
];
?>

<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports Admin | J&J's Kitchenette</title>
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
                <div class="page-top reports-page-top">
                    <div class="page-heading">
                        <h1>Reports</h1>
                        <p>Review sales, orders, payments, and product performance.</p>
                    </div>

                    <form method="GET" class="reports-range-form">
                        <label>
                            <span>Start date</span>
                            <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>">
                        </label>
                        <label>
                            <span>End date</span>
                            <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>">
                        </label>
                        <button type="submit"><i class="fa-solid fa-filter"></i> Apply</button>
                        <a href="reports.php">This week</a>
                    </form>
                </div>

                <div class="reports-range-chip">
                    <i class="fa-regular fa-calendar-days"></i>
                    <span><?= htmlspecialchars($rangeLabel) ?></span>
                </div>

                <div class="reports-summary-row">
                    <?php foreach ($summaryCards as $card) { ?>
                        <div class="reports-summary-card reports-summary-card--<?= htmlspecialchars($card['class']) ?>">
                            <span class="reports-summary-icon">
                                <i class="fa-solid <?= htmlspecialchars($card['icon']) ?>"></i>
                            </span>
                            <div>
                                <span><?= htmlspecialchars($card['label']) ?></span>
                                <strong><?= $card['value'] ?></strong>
                                <small class="dashboard-trend dashboard-trend--<?= htmlspecialchars($card['trend']) ?>">
                                    <?php if ($card['trend'] === 'up') { ?><i class="fa-solid fa-arrow-up"></i><?php } ?>
                                    <?php if ($card['trend'] === 'down') { ?><i class="fa-solid fa-arrow-down"></i><?php } ?>
                                    <?= htmlspecialchars($card['hint']) ?>
                                </small>
                            </div>
                        </div>
                    <?php } ?>
                </div>

                <div class="reports-grid">
                    <section class="reports-card reports-card--wide">
                        <div class="reports-card__header">
                            <h2>Sales Trend</h2>
                            <span><?= htmlspecialchars($rangeLabel) ?></span>
                        </div>

                        <div class="reports-chart">
                            <div class="reports-chart__axis">
                                <?php foreach ([1, .75, .5, .25, 0] as $step) { ?>
                                    <span><?= reportMoney($maxRevenue > 0 ? $maxRevenue * $step : 0) ?></span>
                                <?php } ?>
                            </div>
                            <div class="reports-chart__plot">
                                <svg viewBox="0 0 <?= $chartWidth ?> <?= $chartHeight ?>" role="img" aria-label="Revenue by day" style="min-width: <?= $chartMinWidth ?>px;">
                                    <polygon points="<?= htmlspecialchars($chartArea) ?>" fill="rgba(18, 88, 39, 0.09)"></polygon>
                                    <polyline points="<?= htmlspecialchars($chartPolyline) ?>" fill="none" stroke="#125827" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"></polyline>
                                    <?php foreach ($chartPoints as $point) { ?>
                                        <?php [$cx, $cy] = explode(',', $point); ?>
                                        <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="4" fill="#125827" stroke="#fff" stroke-width="2"></circle>
                                    <?php } ?>
                                </svg>
                                <div class="reports-chart__labels" style="grid-template-columns: repeat(<?= count($salesSeries) ?>, minmax(42px, 1fr)); min-width: <?= $chartMinWidth ?>px;">
                                    <?php foreach ($salesSeries as $point) { ?>
                                        <span><?= htmlspecialchars($point['label']) ?></span>
                                    <?php } ?>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="reports-card">
                        <div class="reports-card__header">
                            <h2>Order Status</h2>
                        </div>

                        <div class="reports-breakdown">
                            <?php foreach ($statusCounts as $label => $count) { ?>
                                <div class="reports-breakdown-item">
                                    <span><?= htmlspecialchars($label) ?></span>
                                    <strong><?= number_format($count) ?></strong>
                                </div>
                            <?php } ?>
                        </div>
                    </section>

                    <section class="reports-card">
                        <div class="reports-card__header">
                            <h2>Payment Methods</h2>
                        </div>

                        <div class="reports-breakdown">
                            <?php if ($payments->num_rows === 0) { ?>
                                <div class="dashboard-empty">No payment data for this range.</div>
                            <?php } ?>

                            <?php while ($payment = $payments->fetch_assoc()) { ?>
                                <div class="reports-breakdown-item">
                                    <span>
                                        <?= htmlspecialchars(reportPaymentLabel($payment['payment_method'])) ?>
                                        <small><?= number_format((int) $payment['orders']) ?> orders</small>
                                    </span>
                                    <strong><?= reportMoney($payment['revenue']) ?></strong>
                                </div>
                            <?php } ?>
                        </div>
                    </section>

                    <section class="reports-card reports-card--wide">
                        <div class="reports-card__header">
                            <h2>Top Selling Products</h2>
                            <a href="products.php">View products</a>
                        </div>

                        <div class="reports-products">
                            <?php if ($topProducts->num_rows === 0) { ?>
                                <div class="dashboard-empty">No product sales for this range.</div>
                            <?php } ?>

                            <?php $rank = 1; ?>
                            <?php while ($product = $topProducts->fetch_assoc()) { ?>
                                <a class="reports-product-row" href="products.php?search=<?= urlencode((string) $product['product_title']) ?>">
                                    <b><?= $rank ?></b>
                                    <img src="<?= htmlspecialchars(reportImagePath($product['main_image'] ?? '')) ?>" alt="">
                                    <span>
                                        <strong><?= htmlspecialchars($product['product_title']) ?></strong>
                                        <small><?= number_format((int) $product['units_sold']) ?> units sold</small>
                                    </span>
                                    <em><?= reportMoney($product['revenue']) ?></em>
                                </a>
                                <?php $rank++; ?>
                            <?php } ?>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </div>
</body>

</html>
