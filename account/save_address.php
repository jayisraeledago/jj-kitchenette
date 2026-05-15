<?php
session_start();
include '../db.php';

$userId = $_SESSION['user_id'];

$fullName = $_POST['full_name'];
$addressLine = $_POST['address_line'];
$city = $_POST['city'];
$phone = $_POST['phone'];

$isDefault = isset($_POST['is_default']) ? 1 : 0;

// CHECK IF USER HAS ADDRESS
$checkStmt = $conn->prepare("
    SELECT COUNT(*) as total
    FROM addresses
    WHERE user_id = ?
");

$checkStmt->bind_param("i", $userId);
$checkStmt->execute();

$count = $checkStmt->get_result()->fetch_assoc()['total'];

// FIRST ADDRESS = AUTO DEFAULT
if ($count == 0) {
    $isDefault = 1;
}

// IF NEW DEFAULT, REMOVE OLD DEFAULT
if ($isDefault == 1) {

    $resetStmt = $conn->prepare("
        UPDATE addresses
        SET is_default = 0
        WHERE user_id = ?
    ");

    $resetStmt->bind_param("i", $userId);
    $resetStmt->execute();
}

// INSERT ADDRESS
$stmt = $conn->prepare("
    INSERT INTO addresses
    (user_id, full_name, address_line, city, phone, is_default)
    VALUES (?, ?, ?, ?, ?, ?)
");

$stmt->bind_param(
    "issssi",
    $userId,
    $fullName,
    $addressLine,
    $city,
    $phone,
    $isDefault
);

$stmt->execute();

header("Location: profile.php");
exit;