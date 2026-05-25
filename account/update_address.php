<?php
require_once __DIR__ . '/../includes/session.php';
startAppSession();
include '../db.php';

$userId = $_SESSION['user_id'];

$addressId = $_POST['address_id'];

$isDefault = isset($_POST['is_default']) ? 1 : 0;

// IF NEW DEFAULT, RESET OTHERS
if ($isDefault == 1) {

    $resetStmt = $conn->prepare("
        UPDATE addresses
        SET is_default = 0
        WHERE user_id = ?
    ");

    $resetStmt->bind_param("i", $userId);
    $resetStmt->execute();
}

// UPDATE ADDRESS
$stmt = $conn->prepare("
    UPDATE addresses
    SET
        full_name = ?,
        address_line = ?,
        city = ?,
        phone = ?,
        is_default = ?
    WHERE id = ?
    AND user_id = ?
");

$stmt->bind_param(
    "ssssiii",
    $_POST['full_name'],
    $_POST['address_line'],
    $_POST['city'],
    $_POST['phone'],
    $isDefault,
    $addressId,
    $userId
);

$stmt->execute();

header("Location: profile.php");
exit;