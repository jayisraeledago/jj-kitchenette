<?php
session_start();
include '../db.php';

// CHECK LOGIN
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userId = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $firstName = trim($_POST['first_name']);
    $lastName = trim($_POST['last_name']);
    $email = trim($_POST['email']);

    // UPDATE USER
    $stmt = $conn->prepare("
        UPDATE users
        SET first_name = ?, last_name = ?, email = ?
        WHERE id = ?
    ");

    $stmt->bind_param(
        "sssi",
        $firstName,
        $lastName,
        $email,
        $userId
    );

    $stmt->execute();

    // OPTIONAL SESSION UPDATE
    $_SESSION['user_name'] = $firstName . ' ' . $lastName;

    // REDIRECT BACK
    header("Location: profile.php?updated=1");
    exit;
}