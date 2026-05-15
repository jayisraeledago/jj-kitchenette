<?php
session_start();
include __DIR__ . '/../db.php';

if (isset($_SESSION['user_id']) && in_array($_SESSION['role_name'] ?? '', ['admin', 'staff'], true)) {
    header("Location: /jj_kitchenette/store/dashboard.php");
    exit;
}

$message = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $conn->prepare("
        SELECT u.*, r.role_name
        FROM users u
        LEFT JOIN roles r ON r.id = u.role_id
        WHERE u.email = ?
        LIMIT 1
    ");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $message = "Invalid email or password.";
    } else {
        $user = $result->fetch_assoc();

        if (!password_verify($password, $user['password'])) {
            $message = "Invalid email or password.";
        } elseif (!in_array($user['role_name'], ['admin', 'staff'], true)) {
            $message = "This login is for staff and admin users only.";
        } elseif (($user['status'] ?? '') !== 'active') {
            $message = "Account is disabled.";
        } else {
            session_regenerate_id(true);

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role_id'] = $user['role_id'];
            $_SESSION['role_name'] = $user['role_name'];
            $_SESSION['user_name'] = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: $user['email'];
            $_SESSION['user_email'] = $user['email'];

            header("Location: /jj_kitchenette/store/dashboard.php");
            exit;
        }
    }
}
?>

<!DOCTYPE html>
<html>

<head>
    <title>Admin Login | J&J's Kitchenette</title>
    <link rel="icon" type="image/png" href="/jj_kitchenette/assets/images/favicon.png">
    <link rel="shortcut icon" type="image/png" href="/jj_kitchenette/assets/images/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>

<body class="admin-login-page">
    <main class="admin-login-shell">
        <section class="admin-login-card">
            <img src="/jj_kitchenette/assets/images/kitchenette-logo.svg" alt="J&J's Kitchenette">
            <h1>Staff & Admin Login</h1>
            <p>Sign in to manage products, orders, inventory, and store settings.</p>

            <?php if ($message !== ''): ?>
                <div class="admin-login-message">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="admin-login-form">
                <label>
                    Email Address
                    <span>
                        <i class="fa-regular fa-envelope"></i>
                        <input type="email" name="email" placeholder="admin@example.com" required>
                    </span>
                </label>

                <label>
                    Password
                    <span>
                        <i class="fa-solid fa-lock"></i>
                        <input type="password" name="password" placeholder="Enter your password" required>
                    </span>
                </label>

                <button type="submit">
                    <i class="fa-solid fa-right-to-bracket"></i>
                    Login to Admin
                </button>
            </form>

            <a href="/jj_kitchenette/login.php">Customer login</a>
        </section>
    </main>
</body>

</html>
