<?php
session_start();
include '../db.php';

// CHECK LOGIN
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userId = $_SESSION['user_id'];

// GET USER
$stmt = $conn->prepare("
    SELECT first_name, last_name, email
    FROM users
    WHERE id = ?
");

$stmt->bind_param("i", $userId);
$stmt->execute();

$user = $stmt->get_result()->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Profile | J&J's Kitchenette</title>
    <link rel="icon" type="image/png" href="/jj_kitchenette/assets/images/favicon.png">
    <link rel="shortcut icon" type="image/png" href="/jj_kitchenette/assets/images/favicon.png">

    <link rel="stylesheet" href="../assets/css/profile.css">
</head>
<body>

<div class="account-page">

    <h1>Edit Profile</h1>

    <div class="profile-card">

        <form action="update_profile.php" method="POST">

            <div class="form-group">
                <label>First Name</label>

                <input
                    type="text"
                    name="first_name"
                    value="<?= htmlspecialchars($user['first_name']) ?>"
                    required
                >
            </div>

            <div class="form-group">
                <label>Last Name</label>

                <input
                    type="text"
                    name="last_name"
                    value="<?= htmlspecialchars($user['last_name']) ?>"
                    required
                >
            </div>

            <div class="form-group">
                <label>Email</label>

                <input
                    type="email"
                    name="email"
                    value="<?= htmlspecialchars($user['email']) ?>"
                    required
                >
            </div>

            <button type="submit" class="save-btn">
                Save Changes
            </button>

        </form>

    </div>

</div>

</body>
</html>
