<?php
require_once __DIR__ . '/includes/session.php';
startAppSession();
include 'db.php';
require_once __DIR__ . '/includes/order_email.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: cart.php");
    exit;
}

$userId = (int) $_SESSION['user_id'];
$source = $_POST['source'] ?? 'cart';
$paymentMethod = $_POST['payment_method'] ?? 'cod';
$allowedPaymentMethods = ['cod', 'store_pickup'];

if (!in_array($paymentMethod, $allowedPaymentMethods, true)) {
    $paymentMethod = 'cod';
}

function isTangubDeliveryCity($city)
{
    $city = strtolower(trim((string) $city));
    return $city === 'tangub' || $city === 'tangub city';
}

function generateOrderNumber($conn)
{
    $prefix = 'JJK-' . date('ymd') . '-';

    for ($attempt = 0; $attempt < 20; $attempt++) {
        $suffix = strtoupper(substr(bin2hex(random_bytes(2)), 0, 3));
        $orderNumber = $prefix . $suffix;

        $stmt = $conn->prepare("SELECT id FROM orders WHERE order_number = ? LIMIT 1");
        $stmt->bind_param("s", $orderNumber);
        $stmt->execute();

        if ($stmt->get_result()->num_rows === 0) {
            return $orderNumber;
        }
    }

    throw new Exception('Unable to generate a unique order number');
}

function calculateOrderDeliveryFee($subtotal, $city, $paymentMethod)
{
    if ($paymentMethod === 'store_pickup') {
        return 0;
    }

    return isTangubDeliveryCity($city) && $subtotal >= 200 ? 0 : 50;
}

function resolveOrderCustomerName($user, $fallback = '')
{
    $profileName = trim(implode(' ', array_filter([
        $user['first_name'] ?? '',
        $user['last_name'] ?? ''
    ])));

    if ($profileName !== '') {
        return $profileName;
    }

    return trim((string) $fallback);
}

function createOrderTables($conn)
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_number VARCHAR(40) NOT NULL UNIQUE,
            user_id INT NOT NULL,
            address_id INT NULL,
            payment_method VARCHAR(30) NOT NULL,
            fulfillment_method VARCHAR(30) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            customer_name VARCHAR(255) NULL,
            address_line VARCHAR(255) NULL,
            city VARCHAR(100) NULL,
            phone VARCHAR(50) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (user_id),
            INDEX (address_id)
        )
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS order_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            product_id INT NOT NULL,
            variant_id INT NOT NULL,
            product_title VARCHAR(255) NOT NULL,
            option1_value VARCHAR(100) NULL,
            option2_value VARCHAR(100) NULL,
            option3_value VARCHAR(100) NULL,
            sku VARCHAR(100) NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            quantity INT NOT NULL DEFAULT 1,
            subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            item_status VARCHAR(30) NOT NULL DEFAULT 'active',
            canceled_at DATETIME NULL,
            cancel_reason VARCHAR(255) NULL,
            image_path VARCHAR(255) NULL,
            INDEX (order_id),
            INDEX (product_id),
            INDEX (variant_id)
        )
    ");
}

function fetchCheckoutItems($conn, $userId, $source)
{
    if ($source === 'direct') {
        $productId = (int) ($_POST['product_id'] ?? 0);
        $variantId = (int) ($_POST['variant_id'] ?? 0);
        $quantity = max(1, (int) ($_POST['quantity'] ?? 1));

        $stmt = $conn->prepare("
            SELECT
                products.id AS product_id,
                products.title,
                product_variants.id AS variant_id,
                product_variants.option1_value,
                product_variants.option2_value,
                product_variants.option3_value,
                product_variants.sku,
                product_variants.price,
                product_variants.inventory,
                (
                    SELECT image_path
                    FROM product_images
                    WHERE product_id = products.id
                    ORDER BY is_main DESC, sort_order ASC
                    LIMIT 1
                ) AS image_path
            FROM products
            INNER JOIN product_variants ON product_variants.product_id = products.id
            WHERE products.status = 'active'
            AND products.id = ?
            AND product_variants.id = ?
            LIMIT 1
        ");
        $stmt->bind_param("ii", $productId, $variantId);
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();

        if (!$item) {
            return [];
        }

        $item['quantity'] = min($quantity, max(0, (int) $item['inventory']));
        return $item['quantity'] > 0 ? [$item] : [];
    }

    $stmt = $conn->prepare("
        SELECT
            cart.quantity,
            products.id AS product_id,
            products.title,
            product_variants.id AS variant_id,
            product_variants.option1_value,
            product_variants.option2_value,
            product_variants.option3_value,
            product_variants.sku,
            product_variants.price,
            product_variants.inventory,
            (
                SELECT image_path
                FROM product_images
                WHERE product_id = products.id
                ORDER BY is_main DESC, sort_order ASC
                LIMIT 1
            ) AS image_path
        FROM cart
        INNER JOIN products ON cart.product_id = products.id
        INNER JOIN product_variants ON cart.variant_id = product_variants.id
        WHERE cart.user_id = ?
        AND products.status = 'active'
        ORDER BY cart.id DESC
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    $items = [];
    while ($row = $result->fetch_assoc()) {
        $row['quantity'] = min(max(1, (int) $row['quantity']), max(0, (int) $row['inventory']));
        if ($row['quantity'] > 0) {
            $items[] = $row;
        }
    }

    return $items;
}

createOrderTables($conn);

$items = fetchCheckoutItems($conn, $userId, $source);

if (empty($items)) {
    header("Location: checkout.php?source=" . urlencode($source));
    exit;
}

$address = null;
$addressId = null;

if ($paymentMethod === 'cod') {
    $addressId = (int) ($_POST['address_id'] ?? 0);
    $addressStmt = $conn->prepare("
        SELECT *
        FROM addresses
        WHERE id = ?
        AND user_id = ?
        LIMIT 1
    ");
    $addressStmt->bind_param("ii", $addressId, $userId);
    $addressStmt->execute();
    $address = $addressStmt->get_result()->fetch_assoc();

    if (!$address || !isTangubDeliveryCity($address['city'])) {
        header("Location: checkout.php?source=" . urlencode($source));
        exit;
    }
}

$subtotal = 0;
foreach ($items as $index => $item) {
    $items[$index]['price'] = (float) $item['price'];
    $items[$index]['quantity'] = max(1, (int) $item['quantity']);
    $items[$index]['subtotal'] = $items[$index]['price'] * $items[$index]['quantity'];
    $subtotal += $items[$index]['subtotal'];
}

$deliveryFee = calculateOrderDeliveryFee($subtotal, $address['city'] ?? '', $paymentMethod);
$total = $subtotal + $deliveryFee;
$orderNumber = generateOrderNumber($conn);
$fulfillmentMethod = $paymentMethod === 'store_pickup' ? 'store_pickup' : 'delivery';

$userStmt = $conn->prepare("
    SELECT first_name, last_name, email
    FROM users
    WHERE id = ?
    LIMIT 1
");
$userStmt->bind_param("i", $userId);
$userStmt->execute();
$orderUser = $userStmt->get_result()->fetch_assoc() ?: [];

$customerName = $address['full_name'] ?? resolveOrderCustomerName($orderUser, $_SESSION['user_name'] ?? '');
$addressLine = $address['address_line'] ?? null;
$city = $address['city'] ?? null;
$phone = $address['phone'] ?? null;

$conn->begin_transaction();

try {
    foreach ($items as $item) {
        $stockStmt = $conn->prepare("
            SELECT inventory
            FROM product_variants
            WHERE id = ?
            FOR UPDATE
        ");
        $stockStmt->bind_param("i", $item['variant_id']);
        $stockStmt->execute();
        $stockRow = $stockStmt->get_result()->fetch_assoc();

        if (!$stockRow || (int) $stockRow['inventory'] < (int) $item['quantity']) {
            throw new Exception('Insufficient stock');
        }
    }

    $orderStmt = $conn->prepare("
        INSERT INTO orders (
            order_number,
            user_id,
            address_id,
            payment_method,
            fulfillment_method,
            subtotal,
            delivery_fee,
            total,
            customer_name,
            address_line,
            city,
            phone
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $orderStmt->bind_param(
        "siissdddssss",
        $orderNumber,
        $userId,
        $addressId,
        $paymentMethod,
        $fulfillmentMethod,
        $subtotal,
        $deliveryFee,
        $total,
        $customerName,
        $addressLine,
        $city,
        $phone
    );
    $orderStmt->execute();
    $orderId = (int) $conn->insert_id;

    $itemStmt = $conn->prepare("
        INSERT INTO order_items (
            order_id,
            product_id,
            variant_id,
            product_title,
            option1_value,
            option2_value,
            option3_value,
            sku,
            price,
            quantity,
            subtotal,
            image_path
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $inventoryStmt = $conn->prepare("
        UPDATE product_variants
        SET inventory = inventory - ?
        WHERE id = ?
    ");

    foreach ($items as $item) {
        $itemStmt->bind_param(
            "iiisssssdiis",
            $orderId,
            $item['product_id'],
            $item['variant_id'],
            $item['title'],
            $item['option1_value'],
            $item['option2_value'],
            $item['option3_value'],
            $item['sku'],
            $item['price'],
            $item['quantity'],
            $item['subtotal'],
            $item['image_path']
        );
        $itemStmt->execute();

        $inventoryStmt->bind_param("ii", $item['quantity'], $item['variant_id']);
        $inventoryStmt->execute();
    }

    if ($source !== 'direct') {
        $clearCart = $conn->prepare("DELETE FROM cart WHERE user_id = ?");
        $clearCart->bind_param("i", $userId);
        $clearCart->execute();
    }

    $conn->commit();

    sendCustomerOrderDetailsEmail($conn, $orderId, 'created');

    header("Location: order_success.php?id=" . $orderId);
    exit;
} catch (Throwable $error) {
    $conn->rollback();
    header("Location: checkout.php?source=" . urlencode($source));
    exit;
}
