<?php
require_once __DIR__ . '/../includes/session.php';
startAppSession();
include '../db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

$userId = $_SESSION['user_id'];
$addressId = isset($_POST['address_id']) ? (int) $_POST['address_id'] : 0;

if ($addressId > 0) {
    $stmt = $conn->prepare("
        DELETE FROM addresses
        WHERE id = ?
        AND user_id = ?
        AND is_default = 0
    ");

    $stmt->bind_param("ii", $addressId, $userId);
    $stmt->execute();
}

header("Location: profile.php");
exit;
