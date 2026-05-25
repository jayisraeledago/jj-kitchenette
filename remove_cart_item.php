<?php
require_once __DIR__ . '/includes/session.php';
startAppSession();
include 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$cart_id = $_GET['id'];

$stmt = $conn->prepare("
    DELETE FROM cart
    WHERE id = ?
");

$stmt->bind_param("i", $cart_id);
$stmt->execute();

header("Location: cart.php");