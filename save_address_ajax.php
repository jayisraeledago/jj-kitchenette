<?php
session_start();
include 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'User not logged in'
    ]);
    exit;
}

$user_id = $_SESSION['user_id'];

$full_name = trim($_POST['full_name'] ?? '');
$address_line = trim($_POST['address_line'] ?? '');
$city = trim($_POST['city'] ?? '');
$phone = trim($_POST['phone'] ?? '');

$is_default = isset($_POST['is_default']) ? 1 : 0;

if (
    empty($full_name) ||
    empty($address_line) ||
    empty($city) ||
    empty($phone)
) {
    echo json_encode([
        'success' => false,
        'message' => 'All fields are required'
    ]);
    exit;
}

// FIRST ADDRESS = AUTO DEFAULT
$check = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM addresses
    WHERE user_id = ?
");

$check->bind_param("i", $user_id);
$check->execute();
$address_count = (int) $check->get_result()->fetch_assoc()['total'];

if ($address_count === 0) {
    $is_default = 1;
}

// REMOVE OLD DEFAULT ADDRESS
if ($is_default) {

    $reset = $conn->prepare("
        UPDATE addresses
        SET is_default = 0
        WHERE user_id = ?
    ");

    $reset->bind_param("i", $user_id);
    $reset->execute();
}

// INSERT ADDRESS
$stmt = $conn->prepare("
    INSERT INTO addresses (
        user_id,
        full_name,
        address_line,
        city,
        phone,
        is_default
    )
    VALUES (?, ?, ?, ?, ?, ?)
");

if (!$stmt) {
    echo json_encode([
        'success' => false,
        'message' => $conn->error
    ]);
    exit;
}

$stmt->bind_param(
    "issssi",
    $user_id,
    $full_name,
    $address_line,
    $city,
    $phone,
    $is_default
);

if ($stmt->execute()) {
    $address_id = $conn->insert_id;

    echo json_encode([
        'success' => true,
        'address' => [
            'id' => $address_id,
            'full_name' => $full_name,
            'address_line' => $address_line,
            'city' => $city,
            'phone' => $phone,
            'is_default' => $is_default
        ]
    ]);

} else {

    echo json_encode([
        'success' => false,
        'message' => $stmt->error
    ]);
}
