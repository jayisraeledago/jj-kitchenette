<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$cart_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$action = $_GET['action'] ?? '';

if ($action === "increase") {

    $stmt = $conn->prepare("
        UPDATE cart
        SET quantity = quantity + 1
        WHERE id = ?
        AND user_id = ?
    ");

    $stmt->bind_param("ii", $cart_id, $user_id);
    $stmt->execute();

}

if ($action === "decrease") {

    // CHECK CURRENT QTY
    $check = $conn->prepare("
        SELECT quantity
        FROM cart
        WHERE id = ?
        AND user_id = ?
    ");

    $check->bind_param("ii", $cart_id, $user_id);
    $check->execute();

    $result = $check->get_result();
    $item = $result->fetch_assoc();

    if ($item && $item['quantity'] > 1) {

        $stmt = $conn->prepare("
            UPDATE cart
            SET quantity = quantity - 1
            WHERE id = ?
            AND user_id = ?
        ");

        $stmt->bind_param("ii", $cart_id, $user_id);
        $stmt->execute();

    } elseif ($item) {

        $stmt = $conn->prepare("
            DELETE FROM cart
            WHERE id = ?
            AND user_id = ?
        ");

        $stmt->bind_param("ii", $cart_id, $user_id);
        $stmt->execute();

    }

}

header("Location: cart.php");
