<?php
include __DIR__ . '/../db.php';
include __DIR__ . '/includes/admin-auth.php';
requireAdminOnly();

function customerDetailInitials($firstName, $lastName, $email)
{
    $firstName = trim((string) $firstName);
    $lastName = trim((string) $lastName);

    if ($firstName !== '' || $lastName !== '') {
        return strtoupper(substr($firstName !== '' ? $firstName : $lastName, 0, 1) . substr($lastName !== '' ? $lastName : $firstName, 0, 1));
    }

    return strtoupper(substr((string) $email, 0, 2));
}

function customerDetailName($firstName, $lastName)
{
    $name = trim(implode(' ', array_filter([trim((string) $firstName), trim((string) $lastName)])));
    return $name !== '' ? $name : 'Customer';
}

function customerDetailStatusLabel($status)
{
    return $status !== '' ? ucfirst((string) $status) : 'Active';
}

function customerDetailPaymentLabel($method)
{
    return $method === 'store_pickup' ? 'Store Pick Up' : 'Cash on Delivery';
}

$customerId = (int) ($_GET['id'] ?? 0);
$returnQuery = $_GET['return'] ?? $_POST['return_query'] ?? '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['customer_id'], $_POST['status'])) {
    $postedCustomerId = (int) $_POST['customer_id'];
    $status = $_POST['status'];

    if ($postedCustomerId > 0 && in_array($status, ['active', 'disabled'], true)) {
        $updateStmt = $conn->prepare("
            UPDATE users u
            INNER JOIN roles r ON r.id = u.role_id
            SET u.status = ?
            WHERE u.id = ?
            AND r.role_name = 'customer'
        ");
        $updateStmt->bind_param("si", $status, $postedCustomerId);
        $updateStmt->execute();
    }

    $redirect = 'customer-detail.php?id=' . $postedCustomerId;
    if ($returnQuery !== '') {
        $redirect .= '&return=' . urlencode($returnQuery);
    }

    header("Location: " . $redirect);
    exit;
}

$customerStmt = $conn->prepare("
    SELECT
        u.id,
        u.first_name,
        u.last_name,
        u.email,
        u.status,
        u.created_at,
        COALESCE(order_totals.order_count, 0) AS order_count,
        COALESCE(order_totals.total_spent, 0) AS total_spent,
        COALESCE(order_totals.average_order, 0) AS average_order,
        order_totals.last_order_at
    FROM users u
    INNER JOIN roles r ON r.id = u.role_id
    LEFT JOIN (
        SELECT
            user_id,
            COUNT(*) AS order_count,
            SUM(total) AS total_spent,
            AVG(total) AS average_order,
            MAX(created_at) AS last_order_at
        FROM orders
        GROUP BY user_id
    ) order_totals ON order_totals.user_id = u.id
    WHERE u.id = ?
    AND r.role_name = 'customer'
    LIMIT 1
");
$customerStmt->bind_param("i", $customerId);
$customerStmt->execute();
$customer = $customerStmt->get_result()->fetch_assoc();

if (!$customer) {
    header("Location: customers.php");
    exit;
}

$addressStmt = $conn->prepare("
    SELECT *
    FROM addresses
    WHERE user_id = ?
    ORDER BY is_default DESC, id DESC
");
$addressStmt->bind_param("i", $customerId);
$addressStmt->execute();
$addressRows = [];
$addressResult = $addressStmt->get_result();
while ($address = $addressResult->fetch_assoc()) {
    $addressRows[] = $address;
}
$defaultAddress = $addressRows[0] ?? null;

$ordersStmt = $conn->prepare("
    SELECT
        id,
        order_number,
        status,
        payment_method,
        fulfillment_method,
        total,
        created_at,
        (
            SELECT COUNT(*)
            FROM order_items oi
            WHERE oi.order_id = orders.id
        ) AS item_count
    FROM orders
    WHERE user_id = ?
    ORDER BY created_at DESC, id DESC
    LIMIT 12
");
$ordersStmt->bind_param("i", $customerId);
$ordersStmt->execute();
$orders = $ordersStmt->get_result();

$customerName = customerDetailName($customer['first_name'], $customer['last_name']);
$initials = customerDetailInitials($customer['first_name'], $customer['last_name'], $customer['email']);
$backUrl = 'customers.php' . ($returnQuery !== '' ? '?' . $returnQuery : '');
$customerCode = 'CUS-' . str_pad((string) $customer['id'], 4, '0', STR_PAD_LEFT);
$nextStatus = $customer['status'] === 'active' ? 'disabled' : 'active';
$nextStatusLabel = $nextStatus === 'disabled' ? 'Disable Account' : 'Enable Account';
$phone = $defaultAddress['phone'] ?? 'No phone saved';
?>

<!DOCTYPE html>
<html>

<head>
    <title><?= htmlspecialchars($customerName) ?> | Customers Admin</title>
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
                <div class="customer-detail-top customer-detail-top--refined">
                    <a href="<?= htmlspecialchars($backUrl) ?>" class="order-detail-back">
                        <i class="fa-solid fa-chevron-left"></i>
                        Back to customers
                    </a>

                    <div class="customer-profile-hero">
                        <div class="customer-profile-identity">
                            <span class="customer-detail-avatar"><?= htmlspecialchars($initials) ?></span>
                            <div>
                                <span class="customer-status customer-status--<?= htmlspecialchars($customer['status']) ?>">
                                    <?= htmlspecialchars(customerDetailStatusLabel($customer['status'])) ?> Customer
                                </span>
                                <h1><?= htmlspecialchars($customerName) ?></h1>
                                <p><i class="fa-regular fa-envelope"></i><?= htmlspecialchars($customer['email']) ?></p>
                                <p><i class="fa-solid fa-phone"></i><?= htmlspecialchars($phone) ?></p>
                            </div>
                        </div>

                        <div class="customer-profile-meta">
                            <div>
                                <span>Customer ID</span>
                                <strong><?= htmlspecialchars($customerCode) ?></strong>
                            </div>
                            <div>
                                <span>Joined</span>
                                <strong><?= !empty($customer['created_at']) ? date('M d, Y', strtotime($customer['created_at'])) : '-' ?></strong>
                            </div>
                            <div>
                                <span>Last Order</span>
                                <strong><?= !empty($customer['last_order_at']) ? date('M d, Y', strtotime($customer['last_order_at'])) : 'No orders' ?></strong>
                                <?php if (!empty($customer['last_order_at'])) { ?>
                                    <small><?= date('h:i A', strtotime($customer['last_order_at'])) ?></small>
                                <?php } ?>
                            </div>
                        </div>

                        <details class="customer-action-menu">
                            <summary>
                                Actions
                                <i class="fa-solid fa-chevron-down"></i>
                            </summary>
                            <form method="POST">
                                <input type="hidden" name="customer_id" value="<?= (int) $customer['id'] ?>">
                                <input type="hidden" name="status" value="<?= htmlspecialchars($nextStatus) ?>">
                                <input type="hidden" name="return_query" value="<?= htmlspecialchars($returnQuery) ?>">
                                <button type="submit" class="<?= $nextStatus === 'disabled' ? 'is-danger' : '' ?>">
                                    <i class="fa-solid <?= $nextStatus === 'disabled' ? 'fa-user-slash' : 'fa-user-check' ?>"></i>
                                    <?= htmlspecialchars($nextStatusLabel) ?>
                                </button>
                            </form>
                        </details>
                    </div>
                </div>

                <div class="customer-detail-summary customer-detail-summary--cards">
                    <div>
                        <span class="customer-detail-summary-icon"><i class="fa-solid fa-bag-shopping"></i></span>
                        <span>Total Orders</span>
                        <strong><?= number_format((int) $customer['order_count']) ?></strong>
                        <small>All time</small>
                    </div>
                    <div>
                        <span class="customer-detail-summary-icon customer-detail-summary-icon--spent"><i class="fa-solid fa-peso-sign"></i></span>
                        <span>Total Spent</span>
                        <strong>&#8369;<?= number_format((float) $customer['total_spent'], 2) ?></strong>
                        <small>All time</small>
                    </div>
                    <div>
                        <span class="customer-detail-summary-icon customer-detail-summary-icon--average"><i class="fa-solid fa-chart-simple"></i></span>
                        <span>Average Order</span>
                        <strong>&#8369;<?= number_format((float) $customer['average_order'], 2) ?></strong>
                        <small>Per order</small>
                    </div>
                    <div>
                        <span class="customer-detail-summary-icon customer-detail-summary-icon--address"><i class="fa-solid fa-location-dot"></i></span>
                        <span>Saved Addresses</span>
                        <strong><?= number_format(count($addressRows)) ?></strong>
                        <small>On profile</small>
                    </div>
                </div>

                <div class="customer-detail-layout customer-detail-layout--refined">
                    <section class="customer-detail-main">
                        <div class="customer-detail-card">
                            <div class="customer-detail-card__header">
                                <h2><i class="fa-solid fa-clock-rotate-left"></i> Order History</h2>
                                <a href="orders.php?search=<?= urlencode((string) $customer['email']) ?>">View all orders</a>
                            </div>

                            <div class="customer-order-table-wrap">
                                <table class="customer-order-table">
                                    <thead>
                                        <tr>
                                            <th>Order ID</th>
                                            <th>Items</th>
                                            <th>Payment Method</th>
                                            <th>Total</th>
                                            <th>Status</th>
                                            <th>Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($orders->num_rows === 0) { ?>
                                            <tr>
                                                <td colspan="6" class="dashboard-empty">No orders yet.</td>
                                            </tr>
                                        <?php } ?>

                                        <?php while ($order = $orders->fetch_assoc()) { ?>
                                            <tr>
                                                <td>
                                                    <a href="order-detail.php?id=<?= (int) $order['id'] ?>"><?= htmlspecialchars($order['order_number']) ?></a>
                                                </td>
                                                <td>
                                                    <strong><?= (int) $order['item_count'] ?> item<?= (int) $order['item_count'] === 1 ? '' : 's' ?></strong>
                                                    <small><?= htmlspecialchars($order['fulfillment_method'] === 'store_pickup' ? 'Store Pick Up' : 'Delivery') ?></small>
                                                </td>
                                                <td><?= htmlspecialchars(customerDetailPaymentLabel($order['payment_method'])) ?></td>
                                                <td><strong>&#8369;<?= number_format((float) $order['total'], 2) ?></strong></td>
                                                <td>
                                                    <span class="order-status order-status--<?= htmlspecialchars($order['status']) ?>">
                                                        <?= htmlspecialchars(customerDetailStatusLabel($order['status'])) ?>
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
                        </div>
                    </section>

                    <aside class="customer-detail-side">
                        <div class="customer-detail-card">
                            <div class="customer-detail-card__header">
                                <h2><i class="fa-solid fa-location-dot"></i> Saved Addresses</h2>
                            </div>

                            <div class="customer-address-list">
                                <?php if (empty($addressRows)) { ?>
                                    <div class="dashboard-empty">No saved addresses.</div>
                                <?php } ?>

                                <?php foreach ($addressRows as $address) { ?>
                                    <div class="customer-address-item">
                                        <div>
                                            <strong><?= htmlspecialchars($address['full_name']) ?></strong>
                                            <?php if ((int) $address['is_default'] === 1) { ?>
                                                <span>Default</span>
                                            <?php } ?>
                                        </div>
                                        <small><?= htmlspecialchars($address['address_line']) ?></small>
                                        <small><?= htmlspecialchars($address['city']) ?></small>
                                        <small><?= htmlspecialchars($address['phone']) ?></small>
                                    </div>
                                <?php } ?>
                            </div>
                        </div>
                    </aside>
                </div>
            </div>
        </div>
    </div>
</body>

</html>
