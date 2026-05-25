<?php
require_once __DIR__ . '/includes/session.php';
startAppSession();
include 'db.php';

if (!isset($_SESSION['user_id'])) {

    echo "login_required";
    exit;

}

$user_id = $_SESSION['user_id'];
$product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
$variant_id = isset($_POST['variant_id']) ? (int) $_POST['variant_id'] : 0;
$quantity = isset($_POST['quantity']) ? max(1, (int) $_POST['quantity']) : 1;

if ($product_id <= 0 || $variant_id <= 0) {
    echo "invalid_variant";
    exit;
}

// Make sure the selected variant belongs to this product.
$variant = $conn->prepare("
    SELECT id, inventory
    FROM product_variants
    WHERE id = ?
    AND product_id = ?
");

$variant->bind_param("ii", $variant_id, $product_id);
$variant->execute();

$variant_result = $variant->get_result();

$variant_row = $variant_result->fetch_assoc();

if (!$variant_row) {
    echo "invalid_variant";
    exit;
}

if ((int) $variant_row['inventory'] < $quantity) {
    echo "out_of_stock";
    exit;
}

// CHECK IF PRODUCT ALREADY EXISTS
$check = $conn->prepare("
    SELECT quantity FROM cart
    WHERE user_id = ?
    AND product_id = ?
    AND variant_id = ?
");

$check->bind_param("iii", $user_id, $product_id, $variant_id);
$check->execute();

$result = $check->get_result();

// IF EXISTS, UPDATE QUANTITY
$cart_row = $result->fetch_assoc();

if ($cart_row) {

    $new_quantity = (int) $cart_row['quantity'] + $quantity;

    if ((int) $variant_row['inventory'] < $new_quantity) {
        echo "out_of_stock";
        exit;
    }

    $update = $conn->prepare("
        UPDATE cart
        SET quantity = quantity + ?
        WHERE user_id = ?
        AND product_id = ?
        AND variant_id = ?
    ");

    $update->bind_param("iiii", $quantity, $user_id, $product_id, $variant_id);
    $update->execute();

} else {

    // INSERT NEW ITEM
    $insert = $conn->prepare("
        INSERT INTO cart (user_id, product_id, variant_id, quantity)
        VALUES (?, ?, ?, ?)
    ");

    $insert->bind_param("iiii", $user_id, $product_id, $variant_id, $quantity);
    $insert->execute();

}

echo "success";
?>
